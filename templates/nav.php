<?php

declare(strict_types=1);

use App\View;

$isLoggedIn = !empty($currentUser);
$isAdmin = $isLoggedIn && ($currentUser['role'] ?? '') === 'admin';
$isUser = $isLoggedIn && ($currentUser['role'] ?? '') === 'user';

?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="/">E-Wallet</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if ($isUser): ?>
                    <li class="nav-item"><a class="nav-link" href="/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="/profile.php">Profile</a></li>
                    <li class="nav-item"><a class="nav-link" href="/deposit.php">Deposit</a></li>
                    <li class="nav-item"><a class="nav-link" href="/withdraw.php">Withdraw</a></li>
                    <li class="nav-item"><a class="nav-link" href="/transfer.php">Transfer</a></li>
                    <li class="nav-item"><a class="nav-link" href="/buy_phone_card.php">Phone Card</a></li>
                    <li class="nav-item"><a class="nav-link" href="/transactions.php">Transactions</a></li>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <li class="nav-item"><a class="nav-link" href="/admin/dashboard.php">Admin</a></li>
                    <li class="nav-item"><a class="nav-link" href="/admin/accounts.php">Accounts</a></li>
                    <li class="nav-item"><a class="nav-link" href="/admin/approvals.php">Approvals</a></li>
                    <li class="nav-item"><a class="nav-link" href="/admin/locked_accounts.php">Locked</a></li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ms-auto">
                <?php if ($isLoggedIn): ?>
                    <li class="nav-item">
                        <span class="navbar-text text-light me-3">
                            <?= View::e((string)($currentUser['full_name'] ?? '')) ?>
                        </span>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="/logout.php">Logout</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="/login.php">Login</a></li>
                    <li class="nav-item"><a class="nav-link" href="/register.php">Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
