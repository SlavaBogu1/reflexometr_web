<?php

declare(strict_types=1);

namespace Reflexometr\Repositories;

use PDO;
use Reflexometr\Config;

final class SessionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(int $userId, string $token): void
    {
        $lifetime = Config::getInt('SESSION_LIFETIME_SECONDS', 1209600);

        if (Config::get('DB_DRIVER', 'sqlite') === 'mysql') {
            $stmt = $this->db->prepare(
                'INSERT INTO sessions (user_id, token, expires_at, last_used_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())'
            );
            $stmt->execute([$userId, $token, $lifetime]);
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO sessions (user_id, token, expires_at, last_used_at)
             VALUES (?, ?, datetime('now', ?), datetime('now'))"
        );
        $stmt->execute([$userId, $token, "+{$lifetime} seconds"]);
    }

    /** @return array<string,mixed>|null */
    public function findValidByToken(string $token): ?array
    {
        $now = Config::get('DB_DRIVER', 'sqlite') === 'mysql' ? 'NOW()' : "datetime('now')";
        $stmt = $this->db->prepare(
            "SELECT * FROM sessions WHERE token = ? AND expires_at > {$now}"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function touch(int $sessionId): void
    {
        $lifetime = Config::getInt('SESSION_LIFETIME_SECONDS', 1209600);
        if (Config::get('DB_DRIVER', 'sqlite') === 'mysql') {
            $stmt = $this->db->prepare(
                'UPDATE sessions SET last_used_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?'
            );
            $stmt->execute([$lifetime, $sessionId]);
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE sessions SET last_used_at = datetime('now'), expires_at = datetime('now', ?) WHERE id = ?"
        );
        $stmt->execute(["+{$lifetime} seconds", $sessionId]);
    }

    public function delete(string $token): void
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE token = ?');
        $stmt->execute([$token]);
    }
}