<?php

declare(strict_types=1);

namespace Reflexometr\Services;

use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Repositories\ResultRepository;

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

        $all = $this->results->allMetricsForScope($rTestId, $rTestVersionId);
        $total = count($all);
        $othersCount = $total - 1;

        // Percentile = share of *other* participants this result is faster than (lower ms =
        // faster) — self excluded from the denominator so "faster than 100%" is meaningful with
        // as few as one other participant. Ties neither help nor hurt (not counted as "beaten").
        $beatenCount = count(array_filter($all, static fn (float $v): bool => $v > $ownValue));
        $percentile = $othersCount > 0 ? round(($beatenCount / $othersCount) * 100, 1) : null;

        sort($all);
        $rank = array_search($ownValue, $all, true);
        $rank = $rank === false ? null : $rank + 1;

        return [
            'r_test_id' => $rTestId,
            'r_test_version_id' => $rTestVersionId,
            'your_value_ms' => $ownValue,
            'percentile' => $percentile,
            'rank' => $rank,
            'total_participants' => $total,
        ];
    }
}
