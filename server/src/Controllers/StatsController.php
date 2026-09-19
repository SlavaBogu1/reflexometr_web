<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Auth\AuthService;
use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Repositories\ResultRepository;
use Reflexometr\Repositories\RTestRepository;
use Reflexometr\Repositories\RTestVersionRepository;
use Reflexometr\Services\StatsService;
use Reflexometr\Support\Validation;

/** CR-STATS-01 (version-scoped aggregation) + CR-STATS-02 (anonymized comparison, D12). */
final class StatsController
{
    /**
     * Personal-history endpoint — trend view, scoped to one exact (r_test_id, r_test_version_id).
     * Defaults to the caller's **complete** history for this scope (CR-STATS-04); optional
     * `limit`/`offset` query params opt into a page instead.
     */
    public static function history(Request $request): array
    {
        $user = (new AuthService())->requireUser($request);
        $slug = (string) $request->param('slug');
        $versionNumber = (int) $request->param('version');
        $limit = Validation::optionalPositiveIntQuery($request, 'limit');
        $offset = Validation::nonNegativeIntQuery($request, 'offset');

        $db = Database::connection();
        $rTest = (new RTestRepository($db))->findBySlug($slug);
        if ($rTest === null) {
            throw new ApiException(ErrorCode::RTEST_NOT_FOUND, 404);
        }
        $version = (new RTestVersionRepository($db))->findByTestAndVersion((int) $rTest['id'], $versionNumber);
        if ($version === null) {
            throw new ApiException(ErrorCode::RTEST_VERSION_NOT_FOUND, 404);
        }

        $history = (new StatsService())->history((int) $user['id'], (int) $rTest['id'], (int) $version['id'], $limit, $offset);
        return Response::json($history);
    }

    /**
     * Anonymized aggregate comparison for one specific completed run (typically called right
     * after CR-TEST-02's submit response, using its result_id). Never another user's identity or
     * raw value (D12).
     */
    public static function comparison(Request $request): array
    {
        $user = (new AuthService())->requireUser($request);
        $resultId = (int) $request->param('id');

        $result = (new ResultRepository(Database::connection()))->findById($resultId);
        if ($result === null) {
            throw new ApiException(ErrorCode::NOT_FOUND, 404);
        }

        $comparison = (new StatsService())->comparisonForResult((int) $user['id'], $result);
        return Response::json($comparison);
    }

}
