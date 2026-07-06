<?php

declare(strict_types=1);

namespace Reflexometr\Repositories;

use PDO;

final class CategoryRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        return $this->db->query('SELECT * FROM r_test_categories ORDER BY name ASC')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_test_categories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_test_categories WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $name): int
    {
        $stmt = $this->db->prepare('INSERT INTO r_test_categories (name) VALUES (?)');
        $stmt->execute([$name]);
        return (int) $this->db->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $stmt = $this->db->prepare('UPDATE r_test_categories SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM r_test_categories WHERE id = ?');
        $stmt->execute([$id]);
    }
}
