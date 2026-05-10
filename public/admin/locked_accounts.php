<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Csrf;
use App\Flash;
use App\Http;
use App\View;

$pdo = App::db()->pdo();

if (Http::isPost()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Flash::set('error', 'Invalid request');
        Http::redirect('/admin/locked_accounts.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare(
            "UPDATE users
             SET indefinite_lock_at = NULL,
                 temp_lock_until = NULL,
                 consecutive_failed = 0,
                 abnormal_login_count = 0,
                 abnormal_login_at = NULL
             WHERE id = ? AND role='user'"
        );
        $stmt->execute([$id]);
        Flash::set('success', 'Account unlocked');
    }
    Http::redirect('/admin/locked_accounts.php');
}

$stmt = $pdo->query(
    "SELECT id, full_name, email, phone, indefinite_lock_at
     FROM users
     WHERE role='user' AND indefinite_lock_at IS NOT NULL
     ORDER BY indefinite_lock_at DESC"
);
$rows = $stmt->fetchAll();

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Locked accounts';

ob_start();
?>
<h1 class="h3 mb-3">Locked accounts</h1>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>Indefinitely locked accounts</div>
        <div class="text-muted small"><?= View::e((string)count($rows)) ?> accounts</div>
    </div>
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Locked at</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="text-muted">No locked accounts found.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= View::e((string)$r['full_name']) ?></td>
                    <td><?= View::e((string)$r['email']) ?></td>
                    <td><?= View::e((string)$r['phone']) ?></td>
                    <td><?= View::e((string)$r['indefinite_lock_at']) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/admin/account.php?id=<?= View::e((string)$r['id']) ?>">Details</a>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
                            <input type="hidden" name="id" value="<?= View::e((string)$r['id']) ?>">
                            <button class="btn btn-sm btn-primary" type="submit" onclick="return confirm('Unlock this account?')">Unlock</button>
                        </form>
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
