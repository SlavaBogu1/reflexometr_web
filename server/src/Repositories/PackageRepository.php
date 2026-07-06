<?php

declare(strict_types=1);

namespace Reflexometr\Repositories;

use PDO;

final class PackageRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        return $this->db->query('SELECT * FROM r_test_packages ORDER BY name ASC')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_test_packages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $name, ?string $description): int
    {
        $stmt = $this->db->prepare('INSERT INTO r_test_packages (name, description) VALUES (?, ?)');
        $stmt->execute([$name, $description]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, ?string $name, ?string $description): void
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
        $stmt = $this->db->prepare('UPDATE r_test_packages SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($args);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM r_test_packages WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function addTest(int $packageId, int $rTestId): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'INSERT IGNORE INTO r_test_package_items (package_id, r_test_id) VALUES (?, ?)'
            : 'INSERT OR IGNORE INTO r_test_package_items (package_id, r_test_id) VALUES (?, ?)';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$packageId, $rTestId]);
    }

    public function removeTest(int $packageId, int $rTestId): void
    {
        $stmt = $this->db->prepare('DELETE FROM r_test_package_items WHERE package_id = ? AND r_test_id = ?');
        $stmt->execute([$packageId, $rTestId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listTestsForPackage(int $packageId): array
    {
        $stmt = $this->db->prepare(
            'SELECT rt.* FROM r_tests rt
             INNER JOIN r_test_package_items i ON i.r_test_id = rt.id
             WHERE i.package_id = ? ORDER BY rt.id ASC'
        );
        $stmt->execute([$packageId]);
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function listPackagesForTest(int $rTestId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.* FROM r_test_packages p
             INNER JOIN r_test_package_items i ON i.package_id = p.id
             WHERE i.r_test_id = ? ORDER BY p.id ASC'
        );
        $stmt->execute([$rTestId]);
        return $stmt->fetchAll();
    }
}
