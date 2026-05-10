<?php

declare(strict_types=1);

$debug = isset($_GET['debug']) && (string)($_GET['debug'] ?? '') === '1';
if ($debug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

register_shutdown_function(static function () use ($debug): void {
    $e = error_get_last();
    if (!$e) {
        return;
    }
    $line = json_encode([
        'at' => date('c'),
        'type' => $e['type'] ?? null,
        'message' => $e['message'] ?? null,
        'file' => $e['file'] ?? null,
        'line' => $e['line'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/install_error.log', $line . PHP_EOL, FILE_APPEND);
    if ($debug) {
        http_response_code(500);
        echo "Fatal error:\n" . ($e['message'] ?? 'unknown');
    }
});

$configPath = __DIR__ . '/../config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Missing config/config.php';
    exit;
}
$config = require $configPath;
$token = (string)($config['app']['install_token'] ?? '');

if ($token === '' || $token === 'CHANGE_ME') {
    http_response_code(403);
    echo 'Install token is not configured.';
    exit;
}

$provided = (string)($_GET['token'] ?? '');
if (!hash_equals($token, $provided)) {
    http_response_code(403);
    echo 'Invalid token.';
    exit;
}

$dbCfg = $config['db'] ?? [];
$host = (string)($dbCfg['host'] ?? '127.0.0.1');
$port = (int)($dbCfg['port'] ?? 3306);
$name = (string)($dbCfg['name'] ?? '');
$charset = (string)($dbCfg['charset'] ?? 'utf8mb4');
$user = (string)($dbCfg['user'] ?? '');
$pass = (string)($dbCfg['pass'] ?? '');

if ($name === '') {
    http_response_code(500);
    echo 'Missing database name in config (db.name).';
    exit;
}

$dsnBase = "mysql:host={$host};port={$port};charset={$charset}";
$dsnDb = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

try {
    $pdo = new PDO($dsnBase, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection failed: ' . $e->getMessage();
    exit;
}

try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to create database: ' . $e->getMessage();
    exit;
}

try {
    $pdo = new PDO($dsnDb, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection failed (after create): ' . $e->getMessage();
    exit;
}

$schemaPath = __DIR__ . '/../database/schema.sql';
$schemaSql = file_get_contents($schemaPath);
if ($schemaSql === false) {
    http_response_code(500);
    echo 'Unable to read schema.sql';
    exit;
}

try {
    $pdo->exec($schemaSql);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to apply schema: ' . $e->getMessage();
    exit;
}

$adminCfg = $config['admin'] ?? [];
$adminEmail = (string)($adminCfg['email'] ?? 'admin@ewallet.local');
$adminPhone = (string)($adminCfg['phone'] ?? '0000000000');
$adminPassword = (string)($adminCfg['password'] ?? 'admin123');

$stmt = $pdo->prepare('SELECT id FROM users WHERE role = ? LIMIT 1');
$stmt->execute(['admin']);
$admin = $stmt->fetch();

if (!$admin) {
    $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (role,email,phone,full_name,dob,address,password_hash,status,must_change_password)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            'admin',
            $adminEmail,
            $adminPhone,
            'Administrator',
            null,
            null,
            $hash,
            'verified',
            0,
        ]);
        $adminId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, ?)');
        $stmt->execute([$adminId, 0]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo 'Failed to seed admin: ' . $e->getMessage();
        exit;
    }
}

echo "Install complete.\n";
echo "Admin email: {$adminEmail}\n";
echo "Admin password: {$adminPassword}\n";
echo "\nImportant: remove public/install.php or change install_token after installing.\n";
