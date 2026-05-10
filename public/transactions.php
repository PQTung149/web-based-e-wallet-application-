<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Auth;
use App\Flash;
use App\Util;
use App\View;

Auth::requireLogin();

if (($currentUser['role'] ?? '') !== 'user') {
    \App\Http::redirect('/');
}

$rows = [];
if (($currentUser['status'] ?? '') === 'verified') {
    $pdo = App::db()->pdo();
    $stmt = $pdo->prepare(
        'SELECT id, code, type, amount, fee, status, meta_json, created_at
         FROM transactions
         WHERE user_id = ?
         ORDER BY created_at DESC'
    );
    $stmt->execute([(int)$currentUser['id']]);
    $rows = $stmt->fetchAll();
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Transactions';

ob_start();
?>
<h1 class="h3 mb-3">Transaction history</h1>

<?php if (($currentUser['status'] ?? '') !== 'verified'): ?>
    <div class="alert alert-warning">This feature is only available for verified accounts.</div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>All transactions</div>
            <div class="text-muted small"><?= View::e((string)count($rows)) ?> transactions</div>
        </div>
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead>
                <tr>
                    <th>Time</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-muted">No transactions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $fee = (int)$r['fee'];
                    if ($fee === 0 && !empty($r['meta_json'])) {
                        $decoded = json_decode((string)$r['meta_json'], true);
                        if (is_array($decoded) && isset($decoded['fee'])) {
                            $fee = (int)$decoded['fee'];
                        }
                    }
                    ?>
                    <tr>
                        <td><?= View::e((string)$r['created_at']) ?></td>
                        <td><?= View::e((string)$r['type']) ?></td>
                        <td><?= View::e(Util::money((int)$r['amount'])) ?></td>
                        <td><?= View::e(Util::money($fee)) ?></td>
                        <td><?= View::e((string)$r['status']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/transaction.php?id=<?= View::e((string)$r['id']) ?>">Details</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
