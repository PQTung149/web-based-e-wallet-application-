<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Auth
{
    public static function user(): ?array
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return null;
        }
        $pdo = App::db()->pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$userId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            self::logout();
            return null;
        }
        return $u;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function loginByIdentifier(string $identifier, string $password): array
    {
        $pdo = App::db()->pdo();
        $stmt = $pdo->prepare(
            'SELECT *,
                    (temp_lock_until IS NOT NULL AND temp_lock_until > NOW()) AS is_temp_locked,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), temp_lock_until)) AS temp_lock_seconds
             FROM users
             WHERE email = ? OR phone = ?
             LIMIT 1'
        );
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['ok' => false, 'error' => 'Invalid credentials'];
        }

        if ($user['role'] !== 'admin') {
            if ($user['status'] === 'disabled') {
                return ['ok' => false, 'error' => 'This account has been disabled, please contact the hotline 18001008'];
            }
            if (!empty($user['indefinite_lock_at'])) {
                return ['ok' => false, 'error' => 'Account has been locked due to entering the wrong password many times, please contact the administrator for support'];
            }
            if ((int)($user['is_temp_locked'] ?? 0) === 1) {
                $sec = (int)($user['temp_lock_seconds'] ?? 0);
                if ($sec < 1) {
                    $sec = 1;
                }
                return ['ok' => false, 'error' => "Account is currently locked, please try again in {$sec} seconds"];
            }
        }

        if (!password_verify($password, (string)$user['password_hash'])) {
            if ($user['role'] !== 'admin') {
                $lockMessage = self::recordFailedLogin((int)$user['id'], (int)$user['consecutive_failed'], (int)$user['abnormal_login_count']);
                if ($lockMessage !== null) {
                    return ['ok' => false, 'error' => $lockMessage];
                }
            }
            return ['ok' => false, 'error' => 'Invalid credentials'];
        }

        if ($user['role'] !== 'admin') {
            self::resetLoginSecurity((int)$user['id']);
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['role'] = (string)$user['role'];

        return ['ok' => true, 'user' => $user];
    }

    private static function recordFailedLogin(int $userId, int $consecutiveFailed, int $abnormalLoginCount): ?string
    {
        $pdo = App::db()->pdo();
        $consecutiveFailed++;

        if ($consecutiveFailed >= 3) {
            if ($abnormalLoginCount >= 1) {
                $stmt = $pdo->prepare(
                    'UPDATE users
                     SET consecutive_failed = 0,
                         temp_lock_until = NULL,
                         abnormal_login_count = ?,
                         abnormal_login_at = NOW(),
                         indefinite_lock_at = NOW()
                     WHERE id = ?'
                );
                $stmt->execute([$abnormalLoginCount + 1, $userId]);
                return 'Account has been locked due to entering the wrong password many times, please contact the administrator for support';
            }

            $stmt = $pdo->prepare(
                'UPDATE users
                 SET consecutive_failed = 0,
                     temp_lock_until = DATE_ADD(NOW(), INTERVAL 60 SECOND),
                     abnormal_login_count = ?,
                     abnormal_login_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([$abnormalLoginCount + 1, $userId]);
            return 'Account is currently locked, please try again in 60 seconds';
        }

        $stmt = $pdo->prepare('UPDATE users SET consecutive_failed = ? WHERE id = ?');
        $stmt->execute([$consecutiveFailed, $userId]);
        return null;
    }

    private static function resetLoginSecurity(int $userId): void
    {
        $pdo = App::db()->pdo();
        $stmt = $pdo->prepare(
            'UPDATE users
             SET consecutive_failed = 0,
                 temp_lock_until = NULL,
                 abnormal_login_count = 0,
                 abnormal_login_at = NULL
             WHERE id = ?'
        );
        $stmt->execute([$userId]);
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['role']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            Http::redirect('/login.php');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    public static function enforceMustChangePassword(string $currentPath): void
    {
        $u = self::user();
        if (!$u || $u['role'] === 'admin') {
            return;
        }
        if ((int)$u['must_change_password'] !== 1) {
            return;
        }
        $allowed = ['/first_change_password.php', '/logout.php'];
        if (!in_array($currentPath, $allowed, true)) {
            Http::redirect('/first_change_password.php');
        }
    }

    public static function requireVerified(): bool
    {
        $u = self::user();
        return $u && $u['role'] !== 'admin' && $u['status'] === 'verified';
    }
}
