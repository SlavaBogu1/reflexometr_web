<?php

declare(strict_types=1);

namespace Reflexometr\Repositories;

use PDO;

final class UserRepository
{
    public const DOMINANT_HAND_VALUES = ['left', 'right', 'none-recorded'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([mb_strtolower($email)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $email, string $passwordHash, bool $isAdmin): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (email, password_hash, is_admin, dominant_hand, preferred_locale)
             VALUES (?, ?, ?, ?, NULL)'
        );
        $stmt->execute([mb_strtolower($email), $passwordHash, $isAdmin ? 1 : 0, 'none-recorded']);
        return (int) $this->db->lastInsertId();
    }

    public function updateDominantHand(int $userId, string $value): void
    {
        $stmt = $this->db->prepare('UPDATE users SET dominant_hand = ? WHERE id = ?');
        $stmt->execute([$value, $userId]);
    }

    public function updatePreferredLocale(int $userId, string $locale): void
    {
        $stmt = $this->db->prepare('UPDATE users SET preferred_locale = ? WHERE id = ?');
        $stmt->execute([$locale, $userId]);
    }
}
