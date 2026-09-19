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
use Reflexometr\Support\Validation;

/**
 * CR-AUTH-02 (D19): admin-only results-approval queue. Mirrors AdminRTestController's existing
 * pattern (is_admin-gated, ADMIN_REQUIRED 403 for non-admins). Never exposes the submitting user's
 * identity (no email/user_id in the list payload) beyond existing admin-access norms.
 */
final class AdminResultController
{
    private const DEFAULT_LIMIT = 50;

    public static function listPending(Request $request): array
    {
        (new AuthService())->requireAdmin($request);

        $status = (string) $request->query('status', 'pending');
        if (!in_array($status, ResultRepository::APPROVAL_STATUS_VALUES, true)) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => ['status']]);
        }

        $limit = Validation::optionalPositiveIntQuery($request, 'limit') ?? self::DEFAULT_LIMIT;
        $offset = Validation::nonNegativeIntQuery($request, 'offset');

        $repo = new ResultRepository(Database::connection());
        $rows = $repo->findByApprovalStatus($status, $limit, $offset);
        $total = $repo->countByApprovalStatus($status);

        $entries = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'r_test_id' => (int) $row['r_test_id'],
                'r_test_slug' => $row['r_test_slug'],
                'r_test_version_id' => (int) $row['r_test_version_id'],
                'r_test_version' => (int) $row['r_test_version'],
                'primary_metric_ms' => (float) $row['primary_metric_ms'],
                'submitted_at' => $row['created_at'],
            ];
        }, $rows);

        return Response::json(['status' => $status, 'total' => $total, 'entries' => $entries]);
    }

    public static function updateStatus(Request $request): array
    {
        (new AuthService())->requireAdmin($request);

        $id = (int) $request->param('id');
        $status = Validation::requireEnum($request->all(), 'approval_status', ['approved', 'rejected']);

        $repo = new ResultRepository(Database::connection());
        $result = $repo->findById($id);
        if ($result === null) {
            throw new ApiException(ErrorCode::NOT_FOUND, 404);
        }

        $repo->updateApprovalStatus($id, $status);

        return Response::json(['id' => $id, 'approval_status' => $status]);
    }
}
