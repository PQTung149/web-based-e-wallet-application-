<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\Http;

if ($currentUser) {
    if (($currentUser['role'] ?? '') === 'admin') {
        Http::redirect('/admin/dashboard.php');
    }
    Http::redirect('/dashboard.php');
}

Http::redirect('/login.php');
