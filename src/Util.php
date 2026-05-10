<?php

declare(strict_types=1);

namespace App;

final class Util
{
    public static function randomString(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    public static function money(int $vnd): string
    {
        return number_format($vnd, 0, '.', ',') . ' VND';
    }

    public static function txCode(): string
    {
        return strtoupper(bin2hex(random_bytes(5)));
    }

    public static function deleteDir(string $dir): bool
    {
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        $items = scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        return @rmdir($dir);
    }
}
