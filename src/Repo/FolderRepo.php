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

    /** Deletes an empty folder. Returns false when it still contains newsletters. */
    public function deleteIfEmpty(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM newsletters WHERE folder_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }
        $this->db->prepare('DELETE FROM folders WHERE id = ?')->execute([$id]);
        return true;
    }
}
