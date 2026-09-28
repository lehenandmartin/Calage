<?php
declare(strict_types=1);

namespace Calage\Repo;

use Calage\Html\PreviewText;
use PDO;

/**
 * Versions of a newsletter: at most one draft (editable, no number)
 * and published versions (frozen, numbered 1, 2, 3…).
 * A published version is never modified or deleted by these methods.
 */
final class VersionRepo
{
    public function __construct(private PDO $db)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM versions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function draft(int $newsletterId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM versions WHERE newsletter_id = ? AND status = \'draft\'');
        $stmt->execute([$newsletterId]);
        return $stmt->fetch() ?: null;
    }

    public function latestPublished(int $newsletterId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM versions WHERE newsletter_id = ? AND status = \'published\' ORDER BY number DESC LIMIT 1'
        );
        $stmt->execute([$newsletterId]);
        return $stmt->fetch() ?: null;
    }

    /** Published version number $number, or null (drafts have no number: never returned). */
    public function publishedByNumber(int $newsletterId, int $number): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM versions WHERE newsletter_id = ? AND status = \'published\' AND number = ?'
        );
        $stmt->execute([$newsletterId, $number]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array> published versions, newest first (without the HTML) */
    public function publishedList(int $newsletterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, number, subject, preheader, published_at FROM versions
             WHERE newsletter_id = ? AND status = \'published\' ORDER BY number DESC'
        );
        $stmt->execute([$newsletterId]);
        return $stmt->fetchAll();
    }

    /** Working version: the draft if there is one, otherwise the latest published one. */
    public function current(int $newsletterId): ?array
    {
        return $this->draft($newsletterId) ?? $this->latestPublished($newsletterId);
    }

    /** @return list<array> the draft first, then published versions, newest first (without the HTML) */
    public function forNewsletter(int $newsletterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, number, status, subject, preheader, created_at, updated_at, published_at, mjml IS NOT NULL AS is_mjml
             FROM versions WHERE newsletter_id = ?
             ORDER BY status = \'draft\' DESC, number DESC'
        );
        $stmt->execute([$newsletterId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, array{hash: string, ext: string}> path as written → hosted image */
    public function images(int $versionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT vi.path, i.hash, i.ext FROM version_images vi JOIN images i ON i.hash = vi.hash WHERE vi.version_id = ?'
        );
        $stmt->execute([$versionId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['path']] = ['hash' => $row['hash'], 'ext' => $row['ext']];
        }
        return $map;
    }

    /**
     * Preheader as the user set it: '' when it is generated from the content (see PreviewText).
     * Pass this value on when copying a version, so that an automatic preheader stays automatic.
     */
    public static function userPreheader(array $version): string
    {
        return (int) ($version['preheader_auto'] ?? 0) === 1 ? '' : (string) $version['preheader'];
    }

    /**
     * An empty preheader is generated from the HTML, like an email client does, and marked as automatic.
     * @return array{0: string, 1: int} [preheader, preheader_auto]
     */
    private static function preheader(string $preheader, string $html): array
    {
        return $preheader === '' ? [PreviewText::fromHtml($html), 1] : [$preheader, 0];
    }

    /**
     * @param string $preheader '' = generated from the content
     * @param array<string, string> $images path as written in the HTML → image hash
     * @param ?string $mjml MJML source that $html was compiled from (null for an HTML newsletter)
     */
    public function createDraft(int $newsletterId, string $html, string $subject, string $preheader, array $images, ?string $mjml = null): int
    {
        [$preheader, $auto] = self::preheader($preheader, $html);
        $this->db->prepare(
            'INSERT INTO versions (newsletter_id, status, html, subject, preheader, preheader_auto, mjml) VALUES (?, \'draft\', ?, ?, ?, ?, ?)'
        )->execute([$newsletterId, $html, $subject, $preheader, $auto, $mjml]);
        $versionId = (int) $this->db->lastInsertId();

        $insert = $this->db->prepare('INSERT INTO version_images (version_id, path, hash) VALUES (?, ?, ?)');
        foreach ($images as $path => $hash) {
            $insert->execute([$versionId, (string) $path, $hash]);
        }
        $this->touchNewsletter($newsletterId);
        return $versionId;
    }

    /**
     * Replaces the draft, if any, with a new one (new file uploaded, restore).
     * @param array<string, string> $images
     */
    public function replaceDraft(int $newsletterId, string $html, string $subject, string $preheader, array $images, ?string $mjml = null): int
    {
        return $this->transaction(function () use ($newsletterId, $html, $subject, $preheader, $images, $mjml): int {
            $this->db->prepare('DELETE FROM versions WHERE newsletter_id = ? AND status = \'draft\'')->execute([$newsletterId]);
            return $this->createDraft($newsletterId, $html, $subject, $preheader, $images, $mjml);
        });
    }

    /**
     * The existing draft, or a new draft copied from the latest published version
     * (HTML, subject, preheader and images). Null when the newsletter has no version.
     */
    public function ensureDraft(int $newsletterId): ?int
    {
        return $this->transaction(function () use ($newsletterId): ?int {
            $draft = $this->draft($newsletterId);
            if ($draft !== null) {
                return (int) $draft['id'];
            }
            $base = $this->latestPublished($newsletterId);
            if ($base === null) {
                return null;
            }
            return $this->createDraft($newsletterId, $base['html'], $base['subject'], self::userPreheader($base), $this->hashes((int) $base['id']), $base['mjml']);
        });
    }

    /**
     * @param string $preheader '' = generated from the content
     * @param ?string $mjml new MJML source (null: HTML newsletter)
     */
    public function updateDraft(int $versionId, string $html, string $subject, string $preheader, ?string $mjml = null): void
    {
        [$preheader, $auto] = self::preheader($preheader, $html);
        $stmt = $this->db->prepare(
            'UPDATE versions SET html = ?, subject = ?, preheader = ?, preheader_auto = ?, mjml = ?, updated_at = strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\')
             WHERE id = ? AND status = \'draft\''
        );
        $stmt->execute([$html, $subject, $preheader, $auto, $mjml, $versionId]);
        if ($stmt->rowCount() !== 1) {
            throw new \LogicException("Version $versionId is not a draft.");
        }
        $this->touchNewsletter((int) $this->find($versionId)['newsletter_id']);
    }

    /** Sets the subject of a draft only (subject entered right before publishing). */
    public function setDraftSubject(int $versionId, string $subject): void
    {
        $this->db->prepare('UPDATE versions SET subject = ? WHERE id = ? AND status = \'draft\'')->execute([$subject, $versionId]);
    }

    /** Freezes the draft with the next number. Returns that number, or null when there is no draft. */
    public function publish(int $newsletterId): ?int
    {
        return $this->transaction(function () use ($newsletterId): ?int {
            $draft = $this->draft($newsletterId);
            if ($draft === null) {
                return null;
            }
            $stmt = $this->db->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM versions WHERE newsletter_id = ?');
            $stmt->execute([$newsletterId]);
            $number = (int) $stmt->fetchColumn();

            $this->db->prepare(
                'UPDATE versions SET status = \'published\', number = ?,
                        published_at = strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'),
                        updated_at = strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\')
                 WHERE id = ?'
            )->execute([$number, $draft['id']]);
            $this->touchNewsletter($newsletterId);
            return $number;
        });
    }

    public function discardDraft(int $newsletterId): void
    {
        $this->db->prepare('DELETE FROM versions WHERE newsletter_id = ? AND status = \'draft\'')->execute([$newsletterId]);
        $this->touchNewsletter($newsletterId);
    }

    /** Restore: an exact copy of a published version, as a draft (replaces the draft, if any). */
    public function restoreAsDraft(int $versionId): int
    {
        $version = $this->find($versionId);
        if ($version === null || $version['status'] !== 'published') {
            throw new \LogicException("Version $versionId is not a published version.");
        }
        return $this->replaceDraft(
            (int) $version['newsletter_id'],
            $version['html'],
            $version['subject'],
            self::userPreheader($version),
            $this->hashes($versionId),
            $version['mjml']
        );
    }

    /** @return array<string, string> path as written → hash */
    public function hashes(int $versionId): array
    {
        return array_map(fn(array $image): string => $image['hash'], $this->images($versionId));
    }

    private function touchNewsletter(int $newsletterId): void
    {
        $this->db->prepare('UPDATE newsletters SET updated_at = strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\') WHERE id = ?')
            ->execute([$newsletterId]);
    }

    /** Transaction, or a plain call when the caller already opened a transaction. */
    private function transaction(callable $fn): mixed
    {
        if ($this->db->inTransaction()) {
            return $fn();
        }
        $this->db->beginTransaction();
        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
