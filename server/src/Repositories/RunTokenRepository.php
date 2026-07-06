<?php

declare(strict_types=1);

namespace Reflexometr\Repositories;

use PDO;

final class RunTokenRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(
        string $token,
        int $userId,
        int $rTestId,
        int $rTestVersionId,
        string $seriesMode,
        ?string $seriesId,
        string $scheduleJson,
        int $issuedAtMs,
        int $expiresAtMs,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO run_tokens
                (token, user_id, r_test_id, r_test_version_id, series_mode, series_id,
                 schedule_json, issued_at_ms, expires_at_ms, used)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([
            $token, $userId, $rTestId, $rTestVersionId, $seriesMode, $seriesId,
            $scheduleJson, $issuedAtMs, $expiresAtMs,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM run_tokens WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Atomically mark a token used — only if it is not already used. Returns true iff this call
     * was the one that consumed it (guards against replay under concurrent requests, D9).
     */
    public function markUsedIfUnused(int $tokenId, int $usedAtMs): bool
    {
        $stmt = $this->db->prepare('UPDATE run_tokens SET used = 1, used_at_ms = ? WHERE id = ? AND used = 0');
        $stmt->execute([$usedAtMs, $tokenId]);
        return $stmt->rowCount() === 1;
    }
}
