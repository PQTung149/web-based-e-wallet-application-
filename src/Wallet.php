<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Wallet
{
    public static function getBalance(int $userId): int
    {
        $pdo = App::db()->pdo();
        $stmt = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['balance'] ?? 0);
    }

    public static function lockBalance(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $stmt = $pdo->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, ?)');
            $stmt->execute([$userId, 0]);
            return 0;
        }
        return (int)$row['balance'];
    }

    public static function setBalance(PDO $pdo, int $userId, int $newBalance): void
    {
        $stmt = $pdo->prepare('UPDATE wallets SET balance = ? WHERE user_id = ?');
        $stmt->execute([$newBalance, $userId]);
    }
}
