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

    public function create(string $slug, string $name, ?string $description, ?int $categoryId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO r_tests (slug, name, description, category_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$slug, $name, $description, $categoryId]);
        return (int) $this->db->lastInsertId();
    }

    public function updateMeta(int $id, ?string $name, ?string $description, ?int $categoryId, bool $categoryProvided): void
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
        if ($categoryProvided) {
            $fields[] = 'category_id = ?';
            $args[] = $categoryId;
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
        $stmt = $this->db->query(
            'SELECT rt.*, c.name AS category_name
             FROM r_tests rt
             LEFT JOIN r_test_categories c ON c.id = rt.category_id
             ORDER BY rt.id ASC'
        );
        return $stmt->fetchAll();
    }
}
