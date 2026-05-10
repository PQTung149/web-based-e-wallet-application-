<?php

declare(strict_types=1);

require __DIR__ . '/../_init.php';

use App\Auth;

Auth::requireAdmin();
