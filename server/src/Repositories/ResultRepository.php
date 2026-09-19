<?php

declare(strict_types=1);

namespace Reflexometr\Repositories;

use PDO;

final class ResultRepository
{
    /** CR-AUTH-02 (D19): valid `approval_status` values — mirrors UserRepository's *_VALUES pattern. */
    public const APPROVAL_STATUS_VALUES = ['pending', 'approved', 'rejected'];

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
        ?float $sdMs,
        ?float $cv,
        int $clientStartedAtMs,
        int $serverReceivedAtMs,
    ): int {
        // approval_status intentionally omitted from the INSERT column list — the schema default
        // ('pending') is the single source of truth for a newly-submitted result's starting state
        // (CR-AUTH-02/D19), never re-specified here.
        $stmt = $this->db->prepare(
            'INSERT INTO results
                (user_id, r_test_id, r_test_version_id, run_token_id, dominant_hand_at_submission,
                 trial_count, trials_json, summary_json, primary_metric_ms, sd_ms, cv,
                 client_started_at_ms, server_received_at_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId, $rTestId, $rTestVersionId, $runTokenId, $dominantHandAtSubmission,
            $trialCount, $trialsJson, $summaryJson, $primaryMetricMs, $sdMs, $cv,
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
     * All primary_metric_ms/sd_ms pairs for a given (r_test_id, r_test_version_id) scope, across
     * every user — used only to compute anonymized aggregates (percentile/rank, CR-STATS-08's
     * peer_sd_ms_median), never returned as a per-user list to any endpoint (D12). CR-AUTH-02/D19:
     * the comparison **pool** is restricted to `approval_status = 'approved'` results only — a
     * pending/rejected result never affects *other* users' aggregates (the requesting user's own
     * value is read directly off their own result row, unaffected by this filter — see
     * StatsService). One query backs both aggregates so the approval filter exists in exactly one
     * place, not duplicated per metric.
     * @return array<int,array{primary_metric_ms:float,sd_ms:?float}>
     */
    public function allApprovedMetricsForScope(int $rTestId, int $rTestVersionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT primary_metric_ms, sd_ms FROM results
             WHERE r_test_id = ? AND r_test_version_id = ? AND approval_status = 'approved'"
        );
        $stmt->execute([$rTestId, $rTestVersionId]);
        return array_map(
            static fn ($row) => [
                'primary_metric_ms' => (float) $row['primary_metric_ms'],
                'sd_ms' => $row['sd_ms'] !== null ? (float) $row['sd_ms'] : null,
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * CR-AUTH-02: paginated list of results awaiting admin review. Never exposes the submitting
     * user's identity beyond existing admin-access norms (no email/user_id in the returned shape —
     * see AdminResultController).
     * @return array<int,array<string,mixed>>
     */
    public function findByApprovalStatus(string $status, int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.r_test_id, r.r_test_version_id, r.primary_metric_ms, r.created_at,
                    rt.slug AS r_test_slug, rtv.version AS r_test_version
             FROM results r
             JOIN r_tests rt ON rt.id = r.r_test_id
             JOIN r_test_versions rtv ON rtv.id = r.r_test_version_id
             WHERE r.approval_status = ?
             ORDER BY r.created_at ASC, r.id ASC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $status, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countByApprovalStatus(string $status): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM results WHERE approval_status = ?');
        $stmt->execute([$status]);
        return (int) $stmt->fetchColumn();
    }

    /** CR-AUTH-02: admin transition — never deletes the row (D19). */
    public function updateApprovalStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE results SET approval_status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }
}
