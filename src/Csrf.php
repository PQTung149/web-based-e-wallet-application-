<?php

declare(strict_types=1);

namespace App;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        return (string)$_SESSION['_csrf'];
    }

    public static function validate(?string $token): bool
    {
        if ($token === null) {
            return false;
        }
        return hash_equals((string)($_SESSION['_csrf'] ?? ''), $token);
    }
}
