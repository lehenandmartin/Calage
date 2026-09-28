<?php
declare(strict_types=1);

namespace Calage\Repo;

use PDO;

final class FolderRepo
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array> folders in alphabetical order, with their number of newsletters */
    public function all(): array
    {
        return $this->db->query(
            'SELECT f.*, (SELECT COUNT(*) FROM newsletters n WHERE n.folder_id = f.id) AS newsletter_count
             FROM folders f ORDER BY f.name COLLATE NOCASE, f.id'
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM folders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $name): int
    {
        $this->db->prepare('INSERT INTO folders (name) VALUES (?)')->execute([$name]);
        return (int) $this->db->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $this->db->prepare('UPDATE folders SET name = ?, updated_at = strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\') WHERE id = ?')
            ->execute([$name, $id]);
    }

    /**
     * Deletes a folder. Its newsletters are either moved out of it (they end up without a folder) or,
     * with $withNewsletters, deleted along with it (versions and share links; hosted images are never deleted).
     * Returns the number of newsletters that were in the folder.
     */
    public function delete(int $id, bool $withNewsletters): int
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id FROM newsletters WHERE folder_id = ?');
            $stmt->execute([$id]);
            $newsletterIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($withNewsletters) {
                $newsletters = new NewsletterRepo($this->db);
                foreach ($newsletterIds as $newsletterId) {
                    $newsletters->delete($newsletterId);
                }
            } else {
                $this->db->prepare('UPDATE newsletters SET folder_id = NULL WHERE folder_id = ?')->execute([$id]);
            }
            $this->db->prepare('DELETE FROM folders WHERE id = ?')->execute([$id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return count($newsletterIds);
    }
}
