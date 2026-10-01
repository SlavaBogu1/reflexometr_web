<?php

declare(strict_types=1);

namespace Reflexometr\Repositories;

use PDO;

final class RTestRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_tests WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_tests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * CR-TEST-25 (Sprint 11): no longer accepts category_id — tag assignment is a separate step
     * via TagRepository::setTagsForTest(), driven by the caller's tag_ids (see
     * ImportService::importNewTest()). category_id itself is now inert legacy data (never written
     * by new code — see schema.mysql.sql's migration comment for why the column is kept, not
     * dropped, this sprint).
     */
    public function create(string $slug, string $name, ?string $description): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO r_tests (slug, name, description) VALUES (?, ?, ?)'
        );
        $stmt->execute([$slug, $name, $description]);
        return (int) $this->db->lastInsertId();
    }

    public function updateMeta(int $id, ?string $name, ?string $description): void
    {
        $fields = [];
        $args = [];
        if ($name !== null) {
            $fields[] = 'name = ?';
            $args[] = $name;
        }
        if ($description !== null) {
            $fields[] = 'description = ?';
            $args[] = $description;
        }
        if ($fields === []) {
            return;
        }
        $args[] = $id;
        $stmt = $this->db->prepare('UPDATE r_tests SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($args);
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM r_tests ORDER BY id ASC');
        return $stmt->fetchAll();
    }

    /**
     * CR-UI-18 (Sprint 15): permanently deletes an r-test row and, via ON DELETE CASCADE, all its
     * r_test_versions rows and run_token rows (FK cascade). Caller MUST first verify there are no
     * results referencing this r-test (countForRTest() = 0) — this method never checks that itself,
     * trusting the controller to enforce the business rule.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM r_tests WHERE id = ?');
        $stmt->execute([$id]);
    }
}
