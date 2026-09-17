<?php

declare(strict_types=1);

namespace Reflexometr\Repositories;

use PDO;

final class ResultRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(
        int $userId,
        int $rTestId,
        int $rTestVersionId,
        int $runTokenId,
        ?string $dominantHandAtSubmission,
        int $trialCount,
        string $trialsJson,
        string $summaryJson,
        float $primaryMetricMs,
        int $clientStartedAtMs,
        int $serverReceivedAtMs,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO results
                (user_id, r_test_id, r_test_version_id, run_token_id, dominant_hand_at_submission,
                 trial_count, trials_json, summary_json, primary_metric_ms,
                 client_started_at_ms, server_received_at_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId, $rTestId, $rTestVersionId, $runTokenId, $dominantHandAtSubmission,
            $trialCount, $trialsJson, $summaryJson, $primaryMetricMs,
            $clientStartedAtMs, $serverReceivedAtMs,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM results WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Personal history — always scoped to one exact (r_test_id, r_test_version_id) pair
     * (CR-STATS-01: never r_test_id alone). This is a single-user, single-scope query (never
     * admin-wide or cross-user) so the default is **no cap** — every matching row is returned
     * unless the caller explicitly opts into a page via $limit/$offset (CR-STATS-04).
     * @return array<int,array<string,mixed>>
     */
    public function historyForUser(int $userId, int $rTestId, int $rTestVersionId, ?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT * FROM results
                WHERE user_id = ? AND r_test_id = ? AND r_test_version_id = ?
                ORDER BY created_at DESC, id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $rTestId, PDO::PARAM_INT);
        $stmt->bindValue(3, $rTestVersionId, PDO::PARAM_INT);
        if ($limit !== null) {
            $stmt->bindValue(4, $limit, PDO::PARAM_INT);
            $stmt->bindValue(5, $offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Total count of a user's results for one exact (r_test_id, r_test_version_id) pair —
     * lets a paginating caller know when it has reached the end (CR-STATS-04).
     */
    public function countForUser(int $userId, int $rTestId, int $rTestVersionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM results WHERE user_id = ? AND r_test_id = ? AND r_test_version_id = ?'
        );
        $stmt->execute([$userId, $rTestId, $rTestVersionId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * All primary metric values for a given (r_test_id, r_test_version_id) scope, across every
     * user — used only to compute an anonymized aggregate (percentile/rank), never returned
     * as a per-user list to any endpoint (D12).
     * @return array<int,float>
     */
    public function allMetricsForScope(int $rTestId, int $rTestVersionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT primary_metric_ms FROM results WHERE r_test_id = ? AND r_test_version_id = ?'
        );
        $stmt->execute([$rTestId, $rTestVersionId]);
        return array_map(static fn ($v) => (float) $v, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
