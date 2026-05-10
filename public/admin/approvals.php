<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Flash;
use App\Util;
use App\View;

$pdo = App::db()->pdo();
$stmt = $pdo->query(
    "SELECT t.id, t.code, t.type, t.amount, t.fee, t.status, t.created_at, u.full_name
     FROM transactions t
     JOIN users u ON u.id = t.user_id
     WHERE t.status = 'pending_admin'
     ORDER BY t.created_at DESC"
);
$rows = $stmt->fetchAll();

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Approvals';

ob_start();
?>
<h1 class="h3 mb-3">Pending approvals</h1>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>Withdrawals / Transfers over 5,000,000</div>
        <div class="text-muted small"><?= View::e((string)count($rows)) ?> transactions</div>
    </div>
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead>
            <tr>
                <th>Code</th>
                <th>User</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Fee</th>
                <th>Status</th>
                <th>Time</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-muted">No pending approvals.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= View::e((string)$r['code']) ?></td>
                    <td><?= View::e((string)$r['full_name']) ?></td>
                    <td><?= View::e((string)$r['type']) ?></td>
                    <td><?= View::e(Util::money((int)$r['amount'])) ?></td>
                    <td><?= View::e(Util::money((int)$r['fee'])) ?></td>
                    <td><?= View::e((string)$r['status']) ?></td>
                    <td><?= View::e((string)$r['created_at']) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/admin/transaction.php?id=<?= View::e((string)$r['id']) ?>">Details</a>
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
