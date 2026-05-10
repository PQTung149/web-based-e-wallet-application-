<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Auth;
use App\Csrf;
use App\Flash;
use App\Http;
use App\View;

Auth::requireLogin();

if (($currentUser['role'] ?? '') !== 'user') {
    Http::redirect('/');
}

$errors = [];

if (Http::isPost()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid request';
    } else {
        $old = (string)($_POST['old_password'] ?? '');
        $p1 = (string)($_POST['new_password'] ?? '');
        $p2 = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($old, (string)$currentUser['password_hash'])) {
            $errors[] = 'Old password is incorrect';
        }
        if (strlen($p1) < 6) {
            $errors[] = 'Password must be at least 6 characters';
        }
        if ($p1 !== $p2) {
            $errors[] = 'Passwords do not match';
        }

        if (!$errors) {
            $hash = password_hash($p1, PASSWORD_DEFAULT);
            $pdo = App::db()->pdo();
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hash, (int)$currentUser['id']]);
            Flash::set('success', 'Password updated');
            Http::redirect('/profile.php');
        }
    }
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Change password';

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-lg-5">
        <h1 class="h3 mb-3">Change password</h1>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?= View::e($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="card card-body">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <div class="mb-3">
                <label class="form-label">Old password</label>
                <input class="form-control" name="old_password" type="password" required>
            </div>
            <div class="mb-3">
                <label class="form-label">New password</label>
                <input class="form-control" name="new_password" type="password" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm new password</label>
                <input class="form-control" name="confirm_password" type="password" required>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Update password</button>
                <a class="btn btn-outline-secondary" href="/profile.php">Back</a>
            </div>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
