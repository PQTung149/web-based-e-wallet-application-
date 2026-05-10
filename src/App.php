<?php

declare(strict_types=1);

namespace App;

final class App
{
    private static array $config = [];
    private static ?Database $db = null;
    private static ?Mailer $mailer = null;

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function config(): array
    {
        return self::$config;
    }

    public static function db(): Database
    {
        if (self::$db === null) {
            self::$db = new Database(self::$config['db']);
        }
        return self::$db;
    }

    public static function mailer(): Mailer
    {
        if (self::$mailer === null) {
            $mailCfg = self::$config['mail'] ?? [];
            self::$mailer = new Mailer(
                (string)($mailCfg['from_email'] ?? 'no-reply@example.com'),
                (string)($mailCfg['from_name'] ?? 'E-Wallet'),
            );
        }
        return self::$mailer;
    }
}
