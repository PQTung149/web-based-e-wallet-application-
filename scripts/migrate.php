<?php

declare(strict_types=1);

$configPath = __DIR__ . '/../config/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config/config.php\n");
    exit(1);
}
$config = require $configPath;

$dbCfg = $config['db'] ?? [];
$host = (string)($dbCfg['host'] ?? '127.0.0.1');
$port = (int)($dbCfg['port'] ?? 3306);
$name = (string)($dbCfg['name'] ?? '');
$charset = (string)($dbCfg['charset'] ?? 'utf8mb4');
$user = (string)($dbCfg['user'] ?? '');
$pass = (string)($dbCfg['pass'] ?? '');

$dsnBase = "mysql:host={$host};port={$port};charset={$charset}";
$dsnDb = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

try {
    $pdo = new PDO($dsnBase, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed\n");
    fwrite(STDERR, "Host: {$host}  Port: {$port}\n");
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

if ($name === '') {
    fwrite(STDERR, "Missing database name in config (db.name)\n");
    exit(1);
}

try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to create database '{$name}'\n");
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

try {
    $pdo = new PDO($dsnDb, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed (after creating DB)\n");
    fwrite(STDERR, "Database: {$name}\n");
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

$schemaPath = __DIR__ . '/../database/schema.sql';
$schemaSql = file_get_contents($schemaPath);
if ($schemaSql === false) {
    fwrite(STDERR, "Unable to read schema.sql\n");
    exit(1);
}

$pdo->exec($schemaSql);

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
        fwrite(STDOUT, "Admin created: {$adminEmail} / {$adminPassword}\n");
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Failed to seed admin\n");
        exit(1);
    }
} else {
    fwrite(STDOUT, "Admin already exists\n");
}

fwrite(STDOUT, "Migration complete\n");
