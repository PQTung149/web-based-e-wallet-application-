<?php

declare(strict_types=1);

namespace App;

final class Flash
{
    public static function set(string $key, string $message): void
    {
        $_SESSION['_flash'][$key] = $message;
    }

    public static function get(string $key): ?string
    {
        $msg = $_SESSION['_flash'][$key] ?? null;
        if ($msg !== null) {
            unset($_SESSION['_flash'][$key]);
        }
        return $msg;
    }
}
