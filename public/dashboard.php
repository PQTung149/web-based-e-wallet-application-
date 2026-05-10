<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\Auth;
use App\Flash;
use App\Util;
use App\View;
use App\Wallet;

Auth::requireLogin();

if (($currentUser['role'] ?? '') === 'admin') {
    \App\Http::redirect('/admin/dashboard.php');
}

$balance = Wallet::getBalance((int)$currentUser['id']);
$status = (string)($currentUser['status'] ?? '');

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Dashboard';

ob_start();
?>
<h1 class="h3 mb-3">Dashboard</h1>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card card-body">
            <div class="mb-2"><strong>Full name:</strong> <?= View::e((string)$currentUser['full_name']) ?></div>
            <div class="mb-2"><strong>Email:</strong> <?= View::e((string)$currentUser['email']) ?></div>
            <div class="mb-2"><strong>Phone:</strong> <?= View::e((string)$currentUser['phone']) ?></div>
            <div class="mb-2"><strong>Status:</strong> <?= View::e($status) ?></div>
            <div><strong>Balance:</strong> <?= View::e(Util::money($balance)) ?></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card card-body">
            <?php if ($status !== 'verified'): ?>
                <div class="alert alert-warning mb-0">
                    This feature is only available for verified accounts.
                    You can still view Profile and change password while waiting for verification.
                </div>
            <?php else: ?>
                <div class="alert alert-success mb-0">
                    Your account is verified. You can use wallet features from the menu.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
