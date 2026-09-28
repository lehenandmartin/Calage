<?php
declare(strict_types=1);

namespace Calage\Repo;

use Calage\Html\PathResolver;
use PDO;

final class NewsletterRepo
{
    public function __construct(private PDO $db)
    {
    }

    public function create(string $name, ?int $folderId): int
    {
        $this->db->prepare('INSERT INTO newsletters (folder_id, name, token) VALUES (?, ?, ?)')
            ->execute([$folderId, $name, self::newToken()]);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM newsletters WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM newsletters WHERE token = ?');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Newsletters, most recently modified first, with their folder, their state and the subject /
     * preheader of their current version (the draft, otherwise the latest published one).
     * $folder: null = all, 0 = no folder, otherwise the folder id.
     * $search: filter on the name, subject and preheader, ignoring accents and case.
     * @return list<array>
     */
    public function all(?int $folder = null, string $search = ''): array
    {
        $where = match (true) {
            $folder === null => '',
            $folder === 0 => 'WHERE n.folder_id IS NULL',
            default => 'WHERE n.folder_id = :folder',
        };
        $stmt = $this->db->prepare(
            "SELECT n.*, f.name AS folder_name, cv.subject, cv.preheader, cv.updated_at AS version_updated_at,
                    (SELECT MAX(number) FROM versions v WHERE v.newsletter_id = n.id) AS last_number,
                    EXISTS (SELECT 1 FROM versions v WHERE v.newsletter_id = n.id AND v.status = 'draft') AS has_draft
             FROM newsletters n
             LEFT JOIN folders f ON f.id = n.folder_id
             LEFT JOIN versions cv ON cv.id = (
                 SELECT v.id FROM versions v WHERE v.newsletter_id = n.id
                 ORDER BY v.status = 'draft' DESC, v.number DESC LIMIT 1
             )
             $where ORDER BY n.updated_at DESC, n.id DESC"
        );
        $stmt->execute($folder !== null && $folder !== 0 ? ['folder' => $folder] : []);
        $rows = $stmt->fetchAll();

        $terms = preg_split('/\s+/', PathResolver::fold(trim($search)), -1, PREG_SPLIT_NO_EMPTY);
        if ($terms === []) {
            return $rows;
        }
        // Each word must appear somewhere: "summer dupont" finds "Summer 2026" in the Maison Dupont folder.
        return array_values(array_filter($rows, function (array $row) use ($terms): bool {
            $haystack = PathResolver::fold(implode(' ', [$row['name'], $row['folder_name'] ?? '', $row['subject'] ?? '', $row['preheader'] ?? '']));
            foreach ($terms as $term) {
                if (!str_contains($haystack, $term)) {
                    return false;
                }
            }
            return true;
        }));
    }

    public function countWithoutFolder(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM newsletters WHERE folder_id IS NULL')->fetchColumn();
    }

    public function rename(int $id, string $name): void
    {
        $this->db->prepare('UPDATE newsletters SET name = ?, updated_at = strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\') WHERE id = ?')
            ->execute([$name, $id]);
    }

    public function move(int $id, ?int $folderId): void
    {
        $this->db->prepare('UPDATE newsletters SET folder_id = ?, updated_at = strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\') WHERE id = ?')
            ->execute([$folderId, $id]);
    }

    /**
     * Deletes the newsletter, its versions and its share link (cascade on versions and version_images).
     * Hosted images are never deleted: emails already sent point to them.
     */
    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM newsletters WHERE id = ?')->execute([$id]);
    }

    /**
     * Copy: a new newsletter (new share link, same folder) whose draft takes the latest published
     * version (or, failing that, the draft): HTML, subject, preheader, images. History not copied.
     */
    public function duplicate(int $id, string $name): ?int
    {
        $source = $this->find($id);
        $versions = new VersionRepo($this->db);
        $base = $source === null ? null : ($versions->latestPublished($id) ?? $versions->draft($id));
        if ($base === null) {
            return null;
        }
        $this->db->beginTransaction();
        try {
            $copyId = $this->create($name, $source['folder_id'] === null ? null : (int) $source['folder_id']);
            $versions->createDraft($copyId, $base['html'], $base['subject'], VersionRepo::userPreheader($base), $versions->hashes((int) $base['id']), $base['mjml']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $copyId;
    }

    /** Share link token: 128 random bits, never derived from the id. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
