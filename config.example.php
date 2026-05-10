<?php

return [
    'app' => [
        'base_url' => 'http://localhost',
        'session_name' => 'ewallet_session',
        'install_token' => 'CHANGE_ME',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'ewallet',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'from_email' => 'no-reply@example.com',
        'from_name' => 'E-Wallet',
    ],
    'admin' => [
        'email' => 'admin@ewallet.local',
        'phone' => '0000000000',
        'password' => 'admin123',
    ],
];
