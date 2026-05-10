<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Csrf;
use App\Flash;
use App\Http;
use App\Util;
use App\View;
use App\Wallet;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$pdo = App::db()->pdo();
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role='user' LIMIT 1");
$stmt->execute([$id]);
$u = $stmt->fetch();
if (!$u) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

if (Http::isPost()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Flash::set('error', 'Invalid request');
        Http::redirect('/admin/account.php?id=' . $id);
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'verify' && ($u['status'] ?? '') === 'pending_verification') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'verified' WHERE id = ?");
        $stmt->execute([$id]);
        Flash::set('success', 'Account verified');
        Http::redirect('/admin/accounts.php?view=pending');
    }

    if ($action === 'cancel' && ($u['status'] ?? '') === 'pending_verification') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = ?");
        $stmt->execute([$id]);
        Flash::set('success', 'Account disabled');
        Http::redirect('/admin/accounts.php?view=pending');
    }

    if ($action === 'request_updates' && ($u['status'] ?? '') === 'pending_verification') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'waiting_updates' WHERE id = ?");
        $stmt->execute([$id]);
        Flash::set('success', 'Requested additional information');
        Http::redirect('/admin/accounts.php?view=pending');
    }

    if ($action === 'unlock' && !empty($u['indefinite_lock_at'])) {
        $stmt = $pdo->prepare(
            'UPDATE users
             SET indefinite_lock_at = NULL,
                 temp_lock_until = NULL,
                 consecutive_failed = 0,
                 abnormal_login_count = 0,
                 abnormal_login_at = NULL
             WHERE id = ?'
        );
        $stmt->execute([$id]);
        Flash::set('success', 'Account unlocked');
        Http::redirect('/admin/locked_accounts.php');
    }

    if ($action === 'delete') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND role = ?');
            $stmt->execute([$id, 'user']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Flash::set('error', 'Delete failed');
            Http::redirect('/admin/account.php?id=' . $id);
        }

        $idsDir = dirname(__DIR__) . '/uploads/ids/' . $id;
        if (is_dir($idsDir)) {
            Util::deleteDir($idsDir);
        }

        Flash::set('success', 'Account deleted');
        Http::redirect('/admin/accounts.php?view=pending');
    }

    Flash::set('error', 'Action not available');
    Http::redirect('/admin/account.php?id=' . $id);
}

$balance = Wallet::getBalance((int)$u['id']);

$monthStart = date('Y-m-01 00:00:00');
$stmt = $pdo->prepare(
    'SELECT id, code, type, amount, fee, status, created_at
     FROM transactions
     WHERE user_id = ? AND created_at >= ?
     ORDER BY created_at DESC
     LIMIT 20'
);
$stmt->execute([(int)$u['id'], $monthStart]);
$txs = $stmt->fetchAll();

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Account details';

ob_start();
?>
<h1 class="h3 mb-3">Account details</h1>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card card-body">
            <div class="mb-2"><strong>Name:</strong> <?= View::e((string)$u['full_name']) ?></div>
            <div class="mb-2"><strong>Email:</strong> <?= View::e((string)$u['email']) ?></div>
            <div class="mb-2"><strong>Phone:</strong> <?= View::e((string)$u['phone']) ?></div>
            <div class="mb-2"><strong>DOB:</strong> <?= View::e((string)($u['dob'] ?? '')) ?></div>
            <div class="mb-2"><strong>Address:</strong> <?= View::e((string)($u['address'] ?? '')) ?></div>
            <div class="mb-2"><strong>Status:</strong> <?= View::e((string)$u['status']) ?></div>
            <div class="mb-2"><strong>Balance:</strong> <?= View::e(Util::money($balance)) ?></div>
            <div class="mb-2"><strong>Created:</strong> <?= View::e((string)$u['created_at']) ?></div>
            <?php if (!empty($u['indefinite_lock_at'])): ?>
                <div class="alert alert-warning mt-2 mb-0">
                    Indefinitely locked at: <?= View::e((string)$u['indefinite_lock_at']) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card card-body">
            <div class="row g-3">
                <div class="col-6">
                    <div class="small text-muted mb-2">ID front</div>
                    <?php if (!empty($u['id_front_path'])): ?>
                        <img class="img-fluid rounded border" src="<?= View::e((string)$u['id_front_path']) ?>" alt="ID front">
                    <?php else: ?>
                        <div class="text-muted">Not uploaded</div>
                    <?php endif; ?>
                </div>
                <div class="col-6">
                    <div class="small text-muted mb-2">ID back</div>
                    <?php if (!empty($u['id_back_path'])): ?>
                        <img class="img-fluid rounded border" src="<?= View::e((string)$u['id_back_path']) ?>" alt="ID back">
                    <?php else: ?>
                        <div class="text-muted">Not uploaded</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (($u['status'] ?? '') === 'pending_verification'): ?>
    <div class="card card-body mt-3">
        <h2 class="h5">Activation actions</h2>
        <form method="post" class="d-flex flex-wrap gap-2">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <button class="btn btn-success" name="action" value="verify" onclick="return confirm('Verify this account?')">Verify</button>
            <button class="btn btn-danger" name="action" value="cancel" onclick="return confirm('Disable this account?')">Cancel</button>
            <button class="btn btn-warning" name="action" value="request_updates" onclick="return confirm('Request additional information (ID re-upload)?')">Request additional information</button>
        </form>
    </div>
<?php endif; ?>

<?php if (!empty($u['indefinite_lock_at'])): ?>
    <div class="card card-body mt-3">
        <h2 class="h5">Unlock</h2>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <button class="btn btn-primary" name="action" value="unlock" onclick="return confirm('Unlock this account?')">Unlock</button>
        </form>
    </div>
<?php endif; ?>

<div class="card card-body mt-3">
    <h2 class="h5">Danger zone</h2>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
        <button class="btn btn-outline-danger" name="action" value="delete" onclick="return confirm('Delete this account permanently? This cannot be undone.')">Delete account permanently</button>
    </form>
</div>

<div class="card mt-3">
    <div class="card-header">Transactions (current month)</div>
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead>
            <tr>
                <th>Code</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Fee</th>
                <th>Status</th>
                <th>Time</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$txs): ?>
                <tr><td colspan="6" class="text-muted">No transactions this month.</td></tr>
            <?php endif; ?>
            <?php foreach ($txs as $t): ?>
                <tr>
                    <td><?= View::e((string)$t['code']) ?></td>
                    <td><?= View::e((string)$t['type']) ?></td>
                    <td><?= View::e(Util::money((int)$t['amount'])) ?></td>
                    <td><?= View::e(Util::money((int)$t['fee'])) ?></td>
                    <td><?= View::e((string)$t['status']) ?></td>
                    <td><?= View::e((string)$t['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../templates/layout.php';
