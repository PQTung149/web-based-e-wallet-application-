<?php

$base = __DIR__;
$local = $base . '/config.local.php';
$hosting = $base . '/config.hosting.php';
$example = $base . '/config.example.php';

if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
    if (is_file($local)) {
        return require $local;
    }
}

if (is_file($hosting)) {
    return require $hosting;
}

if (is_file($local)) {
    return require $local;
}

return require $example;
