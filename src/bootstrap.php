<?php

declare(strict_types=1);

$configPath = __DIR__ . '/../config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Missing config/config.php. Copy config/config.example.php to config/config.php and adjust settings.';
    exit;
}

$config = require $configPath;

date_default_timezone_set('Asia/Ho_Chi_Minh');

session_name($config['app']['session_name'] ?? 'ewallet_session');
session_start();

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    $path = __DIR__ . DIRECTORY_SEPARATOR . $relativePath;
    if (is_file($path)) {
        require $path;
    }
});

App\App::init($config);
