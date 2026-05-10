<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Flash;
use App\View;

$pdo = App::db()->pdo();

$counts = [
    'pending' => 0,
    'verified' => 0,
    'disabled' => 0,
    'waiting_updates' => 0,
    'locked' => 0,
    'pending_approvals' => 0,
];

$stmt = $pdo->query("SELECT status, COUNT(*) AS c FROM users WHERE role='user' GROUP BY status");
foreach ($stmt->fetchAll() as $row) {
    $counts[(string)$row['status']] = (int)$row['c'];
}

$stmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role='user' AND indefinite_lock_at IS NOT NULL");
$counts['locked'] = (int)($stmt->fetch()['c'] ?? 0);

$stmt = $pdo->query("SELECT COUNT(*) AS c FROM transactions WHERE status='pending_admin'");
$counts['pending_approvals'] = (int)($stmt->fetch()['c'] ?? 0);

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Admin';

ob_start();
?>
<h1 class="h3 mb-3">Admin dashboard</h1>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card card-body">
            <div class="text-muted">Accounts waiting activation</div>
            <div class="display-6"><?= View::e((string)($counts['pending_verification'] ?? 0)) ?></div>
            <a class="btn btn-sm btn-outline-primary mt-2" href="/admin/accounts.php?view=pending">View</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-body">
            <div class="text-muted">Activated accounts</div>
            <div class="display-6"><?= View::e((string)($counts['verified'] ?? 0)) ?></div>
            <a class="btn btn-sm btn-outline-primary mt-2" href="/admin/accounts.php?view=verified">View</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-body">
            <div class="text-muted">Disabled accounts</div>
            <div class="display-6"><?= View::e((string)($counts['disabled'] ?? 0)) ?></div>
            <a class="btn btn-sm btn-outline-primary mt-2" href="/admin/accounts.php?view=disabled">View</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-body">
            <div class="text-muted">Waiting for updates</div>
            <div class="display-6"><?= View::e((string)($counts['waiting_updates'] ?? 0)) ?></div>
            <a class="btn btn-sm btn-outline-primary mt-2" href="/admin/accounts.php?view=waiting_updates">View</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-body">
            <div class="text-muted">Indefinitely locked</div>
            <div class="display-6"><?= View::e((string)($counts['locked'] ?? 0)) ?></div>
            <a class="btn btn-sm btn-outline-primary mt-2" href="/admin/locked_accounts.php">View</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-body">
            <div class="text-muted">Pending approvals</div>
            <div class="display-6"><?= View::e((string)($counts['pending_approvals'] ?? 0)) ?></div>
            <a class="btn btn-sm btn-outline-primary mt-2" href="/admin/approvals.php">View</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../templates/layout.php';
