<?php

declare(strict_types=1);

namespace Reflexometr\Repositories;

use PDO;

/**
 * CR-TEST-25 (Sprint 11): replaces CategoryRepository — r_test_tags is the renamed
 * r_test_categories table (same catalog semantics, admin-managed, not a fixed enum), plus the new
 * r_test_tag_links many-to-many join table (an r-test can carry any number of tags, including
 * zero; a tag can apply to any number of r-tests).
 */
final class TagRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        return $this->db->query('SELECT * FROM r_test_tags ORDER BY name ASC')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_test_tags WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM r_test_tags WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $name): int
    {
        $stmt = $this->db->prepare('INSERT INTO r_test_tags (name) VALUES (?)');
        $stmt->execute([$name]);
        return (int) $this->db->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $stmt = $this->db->prepare('UPDATE r_test_tags SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    public function delete(int $id): void
    {
        // ON DELETE CASCADE on r_test_tag_links.tag_id cleans up any links automatically — deleting
        // a tag never deletes the r-tests that carried it, only the association (same semantics
        // the old category FK's ON DELETE SET NULL had: removing the taxonomy entry never removes
        // the content it classified).
        $stmt = $this->db->prepare('DELETE FROM r_test_tags WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return array<int,int> Tag ids currently linked to this r-test. */
    public function tagIdsForTest(int $rTestId): array
    {
        $stmt = $this->db->prepare('SELECT tag_id FROM r_test_tag_links WHERE r_test_id = ? ORDER BY tag_id ASC');
        $stmt->execute([$rTestId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,array<int,array<string,mixed>>> Map of r_test_id => [{id, name}, ...], for every id in $rTestIds. */
    public function tagsForTests(array $rTestIds): array
    {
        $out = array_fill_keys($rTestIds, []);
        if ($rTestIds === []) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($rTestIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT l.r_test_id, t.id, t.name
             FROM r_test_tag_links l
             INNER JOIN r_test_tags t ON t.id = l.tag_id
             WHERE l.r_test_id IN ({$placeholders})
             ORDER BY t.name ASC"
        );
        $stmt->execute($rTestIds);
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['r_test_id']][] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> {id, name} pairs for a single r-test, name-sorted. */
    public function tagsForTest(int $rTestId): array
    {
        return $this->tagsForTests([$rTestId])[$rTestId];
    }

    /** @return array<int,int> r_test ids currently carrying this tag. */
    public function testIdsForTag(int $tagId): array
    {
        $stmt = $this->db->prepare('SELECT r_test_id FROM r_test_tag_links WHERE tag_id = ?');
        $stmt->execute([$tagId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Replaces the full tag set for an r-test with exactly $tagIds (empty array = no tags).
     * Not merely additive — this is the "PATCH replaces the full set" semantics the contract
     * describes for tag_ids. Runs as one transaction so a partial failure never leaves the link
     * table in a half-updated state.
     * @param array<int,int> $tagIds
     */
    public function setTagsForTest(int $rTestId, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('DELETE FROM r_test_tag_links WHERE r_test_id = ?');
            $stmt->execute([$rTestId]);

            if ($tagIds !== []) {
                $insert = $this->db->prepare('INSERT INTO r_test_tag_links (r_test_id, tag_id) VALUES (?, ?)');
                foreach ($tagIds as $tagId) {
                    $insert->execute([$rTestId, $tagId]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<int,int> Given candidate ids, returns only the ones that exist in r_test_tags. */
    public function filterExistingIds(array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if ($tagIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $stmt = $this->db->prepare("SELECT id FROM r_test_tags WHERE id IN ({$placeholders})");
        $stmt->execute($tagIds);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
