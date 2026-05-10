<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Otp
{
    public static function generate6Digits(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function create(int $userId, string $purpose, array $meta = [], int $ttlSeconds = 60): array
    {
        $pdo = App::db()->pdo();
        $code = self::generate6Digits();
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        $stmt = $pdo->prepare(
            'INSERT INTO otps (user_id, purpose, code, expires_at, meta_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $purpose,
            $code,
            $expiresAt,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);

        return [
            'code' => $code,
            'expires_at' => $expiresAt,
        ];
    }

    public static function consume(int $userId, string $purpose, string $code): ?array
    {
        $pdo = App::db()->pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM otps
             WHERE user_id = ? AND purpose = ? AND code = ? AND consumed_at IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$userId, $purpose, $code]);
        $otp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$otp) {
            return null;
        }
        if (strtotime((string)$otp['expires_at']) < time()) {
            return null;
        }

        $stmt = $pdo->prepare('UPDATE otps SET consumed_at = NOW() WHERE id = ?');
        $stmt->execute([(int)$otp['id']]);

        return $otp;
    }
}

