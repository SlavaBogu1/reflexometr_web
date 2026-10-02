<?php

declare(strict_types=1);

namespace Reflexometr\Repositories;

use PDO;

/**
 * `description` here is the imported opaque textual/JSON payload (D11) — this repository never
 * interprets it; only Services\ScheduleCompiler reads its structure, server-side, at run-start.
 */
final class RTestVersionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Insert a new version and mark it the r-test's current/active one. Prior versions' rows
     * (description, version number, created_at) are never modified — only their `is_active`
     * pointer flips off, which is a "what's current" pointer, not the versioned content itself
     * (CR-TEST-01 acceptance 3: prior versions and their results stay unchanged).
     */
    public function createAsActive(int $rTestId, int $version, string $description): int
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE r_test_versions SET is_active = 0 WHERE r_test_id = ?');
            $stmt->execute([$rTestId]);

            $stmt = $this->db->prepare(
                'INSERT INTO r_test_versions (r_test_id, version, description, is_active) VALUES (?, ?, ?, 1)'
            );
            $stmt->execute([$rTestId, $version, $description]);
            $id = (int) $this->db->lastInsertId();

            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function nextVersionNumber(int $rTestId): int
    {
        $stmt = $this->db->prepare('SELECT MAX(version) AS max_version FROM r_test_versions WHERE r_test_id = ?');
        $stmt->execute([$rTestId]);
        $row = $stmt->fetch();
        return ((int) ($row['max_version'] ?? 0)) + 1;
    }

    /** @return array<string,mixed>|null */
    public function findByTestAndVersion(int $rTestId, int $version): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_test_versions WHERE r_test_id = ? AND version = ?');
        $stmt->execute([$rTestId, $version]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_test_versions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function listForTest(int $rTestId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_test_versions WHERE r_test_id = ? ORDER BY version ASC');
        $stmt->execute([$rTestId]);
        return $stmt->fetchAll();
    }

    /**
     * CR-UI-18 (Sprint 15): finds a version by its numeric version number, only if it is
     * visible (is_visible = 1). Returns null for both "not found" and "found but hidden" —
     * callers that need to distinguish (e.g. admin endpoints) should use findByTestAndVersion()
     * and inspect is_visible themselves.
     * @return array<string,mixed>|null
     */
    public function findVisibleByTestAndVersion(int $rTestId, int $version): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_test_versions WHERE r_test_id = ? AND version = ? AND is_visible = 1');
        $stmt->execute([$rTestId, $version]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * CR-UI-18 (Sprint 15) + CR-UI-31 (Sprint 18): the effective "current version" for a test's
     * public surface (listing, detail page, default run-start). Prefers the active version if
     * it's still visible; if the active version has been archived (is_visible = 0) but an older
     * version of the same test is still visible, falls back to the highest-numbered visible
     * version rather than treating the test as having no visible version at all. Returns null
     * only when every version of the test is archived.
     * @return array<string,mixed>|null
     */
    public function findEffectiveVersionForTest(int $rTestId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_test_versions WHERE r_test_id = ? AND is_active = 1 AND is_visible = 1 LIMIT 1');
        $stmt->execute([$rTestId]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }
        $stmt = $this->db->prepare('SELECT * FROM r_test_versions WHERE r_test_id = ? AND is_visible = 1 ORDER BY version DESC LIMIT 1');
        $stmt->execute([$rTestId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * CR-UI-18 (Sprint 15): sets is_visible for a specific version row by its id.
     */
    public function updateVisibility(int $versionId, bool $isVisible): void
    {
        $stmt = $this->db->prepare('UPDATE r_test_versions SET is_visible = ? WHERE id = ?');
        $stmt->execute([$isVisible ? 1 : 0, $versionId]);
    }
}
