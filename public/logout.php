<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\Auth;
use App\Http;

Auth::logout();
Http::redirect('/login.php');
