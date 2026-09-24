<?php

declare(strict_types=1);

namespace Reflexometr\Services;

use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Repositories\ResultRepository;
use Reflexometr\Support\Stats;

/**
 * CR-STATS-01 (every query scoped to one exact (r_test_id, r_test_version_id) pair — never
 * r_test_id alone) and CR-STATS-02 (anonymized aggregate comparison, D12 — never another user's
 * identity or raw value).
 */
final class StatsService
{
    private ResultRepository $results;

    public function __construct()
    {
        $this->results = new ResultRepository(Database::connection());
    }

    /**
     * @param int|null $limit Optional page size; null (default) returns the caller's **complete**
     *   history for this scope (CR-STATS-04 — no more silent, undocumented caps).
     * @return array<string,mixed>
     */
    public function history(int $userId, int $rTestId, int $rTestVersionId, ?int $limit = null, int $offset = 0): array
    {
        $rows = $this->results->historyForUser($userId, $rTestId, $rTestVersionId, $limit, $offset);
        $total = $this->results->countForUser($userId, $rTestId, $rTestVersionId);
        $entries = array_map(static function (array $row): array {
            return [
                'result_id' => (int) $row['id'],
                'created_at' => $row['created_at'],
                'primary_metric_ms' => (float) $row['primary_metric_ms'],
                'summary' => json_decode((string) $row['summary_json'], true),
                // CR-STATS-07: the entry stays present (never filtered server-side) so the client
                // can render it struck-through with an un-exclude option, not have it silently
                // vanish from the trend view.
                'excluded' => (bool) $row['excluded_from_own_stats'],
            ];
        }, $rows);

        return [
            'r_test_id' => $rTestId,
            'r_test_version_id' => $rTestVersionId,
            'total' => $total,
            'entries' => $entries,
        ];
    }

    /**
     * Anonymized aggregate comparison for one specific result, scoped to that result's exact
     * (r_test_id, r_test_version_id). Never returns another user's identity or raw value (D12) —
     * only this user's own value plus an aggregate percentile/rank/count.
     *
     * CR-AUTH-02/D19: the comparison **pool** (percentile/rank/total_participants, and CR-STATS-08's
     * peer_sd_ms_median below) only includes `approval_status = 'approved'` results — see
     * ResultRepository::allApprovedMetricsForScope, one query backing both aggregates. The
     * requesting user's **own**
     * `your_value_ms`/`your_sd_ms`/`your_cv` are read directly from their own result row and are
     * completely unaffected by their own result's approval status.
     *
     * CR-STATS-08: adds `your_sd_ms`/`your_cv` (this result's own values) plus `peer_sd_ms_median`
     * — the median sd_ms across the (approved) peer pool, a real server-computed aggregate (not a
     * client-side interpolation trick, since a single scalar can't reconstruct a distribution's
     * spread). Null whenever there is no approved peer data yet (own result excluded from the
     * "peer" pool, matching percentile/rank's existing self-exclusion convention).
     * @return array<string,mixed>
     */
    public function comparisonForResult(int $userId, array $result): array
    {
        if ((int) $result['user_id'] !== $userId) {
            // IDOR guard: a user may only compare their own result (D3 privacy rule).
            throw new ApiException(ErrorCode::NOT_FOUND, 404);
        }

        $rTestId = (int) $result['r_test_id'];
        $rTestVersionId = (int) $result['r_test_version_id'];
        $ownValue = (float) $result['primary_metric_ms'];
        $ownSdMs = $result['sd_ms'] !== null ? (float) $result['sd_ms'] : null;
        $ownCv = $result['cv'] !== null ? (float) $result['cv'] : null;
        $ownIsApproved = ($result['approval_status'] ?? 'approved') === 'approved';

        // Single query backs both the percentile/rank pool and the peer-sd_ms pool — one shared
        // approval_status='approved' filter (ResultRepository::allApprovedMetricsForScope), not a
        // duplicated predicate per aggregate.
        $scopeRows = $this->results->allApprovedMetricsForScope($rTestId, $rTestVersionId);
        // Self-exclusion: if this own result is itself approved, it is included in $scopeRows —
        // remove exactly the one row matching this result's own value so percentile/rank/
        // total_participants and the peer sd_ms pool continue to mean "this result vs. the rest
        // of the approved field," identical to pre-CR-AUTH-02 semantics. If this own result is NOT
        // approved, it was never in $scopeRows to begin with.
        if ($ownIsApproved) {
            foreach ($scopeRows as $idx => $row) {
                if ($row['primary_metric_ms'] === $ownValue) {
                    array_splice($scopeRows, $idx, 1);
                    break;
                }
            }
        }
        $all = array_map(static fn ($row) => $row['primary_metric_ms'], $scopeRows);
        $peerSdValues = array_values(array_filter(
            array_map(static fn ($row) => $row['sd_ms'], $scopeRows),
            static fn ($v) => $v !== null
        ));
        $othersCount = count($all);
        $total = $othersCount + 1; // self always counts toward total_participants (pre-existing semantics)

        // Percentile = share of *other* (approved) participants this result is faster than (lower
        // ms = faster) — self excluded from the denominator so "faster than 100%" is meaningful
        // with as few as one other participant. Ties neither help nor hurt (not counted as "beaten").
        $beatenCount = count(array_filter($all, static fn (float $v): bool => $v > $ownValue));
        $percentile = $othersCount > 0 ? round(($beatenCount / $othersCount) * 100, 1) : null;

        $rankPool = $all;
        $rankPool[] = $ownValue;
        sort($rankPool);
        $rank = array_search($ownValue, $rankPool, true);
        $rank = $rank === false ? null : $rank + 1;

        $peerSdMsMedian = Stats::median($peerSdValues);

        return [
            'r_test_id' => $rTestId,
            'r_test_version_id' => $rTestVersionId,
            'your_value_ms' => $ownValue,
            'your_sd_ms' => $ownSdMs,
            'your_cv' => $ownCv,
            'percentile' => $percentile,
            'rank' => $rank,
            'total_participants' => $total,
            'peer_sd_ms_median' => $peerSdMsMedian,
        ];
    }

    /**
     * CR-STATS-07: toggles a user's own result's excluded_from_own_stats flag. IDOR-guarded
     * identically to comparisonForResult above — NOT_FOUND for another user's result, same code
     * whether the result doesn't exist at all or simply isn't theirs (D3). Purely a per-owner
     * display concern: never touches approval_status or the separate, admin-driven peer-comparison
     * pool (D19) — GET /results/{id}/comparison is completely unaffected by this flag.
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function setExcludedFromOwnStats(int $userId, array $result, bool $excluded): array
    {
        if ((int) $result['user_id'] !== $userId) {
            // IDOR guard: a user may only exclude/include their own result (D3 privacy rule) —
            // same NOT_FOUND-for-both-cases pattern as comparisonForResult.
            throw new ApiException(ErrorCode::NOT_FOUND, 404);
        }

        $this->results->updateExcludedFromOwnStats((int) $result['id'], $excluded);

        return [
            'result_id' => (int) $result['id'],
            'excluded' => $excluded,
        ];
    }

    /** @param array<int,float> $values */
}
