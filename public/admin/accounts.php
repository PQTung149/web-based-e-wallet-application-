<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Flash;
use App\View;

$view = (string)($_GET['view'] ?? 'pending');
$allowedViews = ['pending', 'verified', 'disabled', 'waiting_updates'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'pending';
}

$map = [
    'pending' => ['label' => 'Waiting activation', 'status' => 'pending_verification', 'order' => 'created_at DESC'],
    'verified' => ['label' => 'Activated', 'status' => 'verified', 'order' => 'created_at DESC'],
    'disabled' => ['label' => 'Disabled', 'status' => 'disabled', 'order' => 'created_at DESC'],
    'waiting_updates' => ['label' => 'Waiting updates', 'status' => 'waiting_updates', 'order' => 'updated_at DESC'],
];

$pdo = App::db()->pdo();
$cfg = $map[$view];
$stmt = $pdo->prepare("SELECT id, email, phone, full_name, status, created_at, updated_at FROM users WHERE role='user' AND status = ? ORDER BY {$cfg['order']}");
$stmt->execute([$cfg['status']]);
$rows = $stmt->fetchAll();

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Accounts';

ob_start();
?>
<h1 class="h3 mb-3">Account management</h1>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $view === 'pending' ? 'active' : '' ?>" href="/admin/accounts.php?view=pending">Waiting activation</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'verified' ? 'active' : '' ?>" href="/admin/accounts.php?view=verified">Activated</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'disabled' ? 'active' : '' ?>" href="/admin/accounts.php?view=disabled">Disabled</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'waiting_updates' ? 'active' : '' ?>" href="/admin/accounts.php?view=waiting_updates">Waiting updates</a></li>
</ul>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div><?= View::e($cfg['label']) ?></div>
        <div class="text-muted small"><?= View::e((string)count($rows)) ?> accounts</div>
    </div>
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-muted">No accounts found.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= View::e((string)$r['full_name']) ?></td>
                    <td><?= View::e((string)$r['email']) ?></td>
                    <td><?= View::e((string)$r['phone']) ?></td>
                    <td><?= View::e((string)$r['status']) ?></td>
                    <td><?= View::e((string)$r['created_at']) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/admin/account.php?id=<?= View::e((string)$r['id']) ?>">Details</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../templates/layout.php';
