<?php

declare(strict_types=1);

namespace Reflexometr\Services;

use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Repositories\RTestRepository;
use Reflexometr\Repositories\RTestVersionRepository;
use Reflexometr\Repositories\TagRepository;

/**
 * CR-TEST-01: admin-only import/export of r-test descriptions. The admin imports a specialist-
 * authored, already-validated description (D11) — this service stores/versions it; it never
 * authors or edits description content itself, only r_tests metadata (name/description/category).
 */
final class ImportService
{
    private RTestRepository $rTests;
    private RTestVersionRepository $versions;
    private TagRepository $tags;

    public function __construct()
    {
        $db = Database::connection();
        $this->rTests = new RTestRepository($db);
        $this->versions = new RTestVersionRepository($db);
        $this->tags = new TagRepository($db);
    }

    /**
     * @param array<int,int>|null $tagIds CR-TEST-25: replaces the old single $categoryId — a new
     *     r-test can be created with any number of tags (including none/omitted). Unknown ids are
     *     silently dropped (filterExistingIds), never a hard failure on import — matches the old
     *     category_id behavior of not validating existence beyond the FK itself.
     * @return array<string,mixed> The created r_test row + its v1 version row.
     */
    public function importNewTest(
        string $slug,
        string $name,
        ?string $metaDescription,
        ?array $tagIds,
        string $rawDescription,
    ): array {
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => ['slug']]);
        }
        if ($this->rTests->findBySlug($slug) !== null) {
            throw new ApiException(ErrorCode::RTEST_SLUG_TAKEN, 409);
        }

        $this->assertValidDescription($rawDescription);

        $rTestId = $this->rTests->create($slug, $name, $metaDescription);
        $versionId = $this->versions->createAsActive($rTestId, 1, $rawDescription);

        if ($tagIds !== null && $tagIds !== []) {
            $this->tags->setTagsForTest($rTestId, $this->tags->filterExistingIds($tagIds));
        }

        return [
            'r_test' => $this->rTests->findById($rTestId),
            'version' => $this->versions->findById($versionId),
        ];
    }

    /** @return array<string,mixed> The newly created version row. */
    public function importNewVersion(string $slug, string $rawDescription): array
    {
        $rTest = $this->rTests->findBySlug($slug);
        if ($rTest === null) {
            throw new ApiException(ErrorCode::RTEST_NOT_FOUND, 404);
        }

        $this->assertValidDescription($rawDescription);

        $nextVersion = $this->versions->nextVersionNumber((int) $rTest['id']);
        $versionId = $this->versions->createAsActive((int) $rTest['id'], $nextVersion, $rawDescription);

        return ['version' => $this->versions->findById($versionId)];
    }

    /** Raw description text for export — admin-only caller enforced by the controller. */
    public function exportVersion(string $slug, int $versionNumber): string
    {
        $rTest = $this->rTests->findBySlug($slug);
        if ($rTest === null) {
            throw new ApiException(ErrorCode::RTEST_NOT_FOUND, 404);
        }
        $version = $this->versions->findByTestAndVersion((int) $rTest['id'], $versionNumber);
        if ($version === null) {
            throw new ApiException(ErrorCode::RTEST_VERSION_NOT_FOUND, 404);
        }
        return (string) $version['description'];
    }

    private function assertValidDescription(string $rawDescription): void
    {
        $decoded = json_decode($rawDescription, true);
        if (!is_array($decoded)) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => ['description']]);
        }
        // Throws VALIDATION_ERROR itself, with the specific malformed sub-fields, if invalid.
        ScheduleCompiler::validateDescription($decoded);
    }
}
