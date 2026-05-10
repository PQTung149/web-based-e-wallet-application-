<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
Auth::enforceMustChangePassword((string)$path);

$currentUser = Auth::user();
