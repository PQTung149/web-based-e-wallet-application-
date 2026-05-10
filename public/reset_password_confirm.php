<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Csrf;
use App\Flash;
use App\Http;
use App\Otp;
use App\View;

if (!empty($currentUser)) {
    Http::redirect('/');
}

$errors = [];

if (Http::isPost()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid request';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $otpCode = trim((string)($_POST['otp'] ?? ''));
        $p1 = (string)($_POST['new_password'] ?? '');
        $p2 = (string)($_POST['confirm_password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }
        if ($phone === '' || !preg_match('/^\d{8,15}$/', $phone)) {
            $errors[] = 'Valid phone number is required';
        }
        if (!preg_match('/^\d{6}$/', $otpCode)) {
            $errors[] = 'OTP must be 6 digits';
        }
        if (strlen($p1) < 6) {
            $errors[] = 'Password must be at least 6 characters';
        }
        if ($p1 !== $p2) {
            $errors[] = 'Passwords do not match';
        }

        if (!$errors) {
            $pdo = App::db()->pdo();
            $stmt = $pdo->prepare("SELECT id, status FROM users WHERE role='user' AND email = ? AND phone = ? LIMIT 1");
            $stmt->execute([$email, $phone]);
            $u = $stmt->fetch();
            if (!$u) {
                $errors[] = 'No matching account found';
            } elseif (($u['status'] ?? '') === 'disabled') {
                $errors[] = 'This account has been disabled, please contact the hotline 18001008';
            } else {
                $otp = Otp::consume((int)$u['id'], 'reset_password', $otpCode);
                if (!$otp) {
                    $errors[] = 'Invalid or expired OTP';
                } else {
                    $hash = password_hash($p1, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
                    $stmt->execute([$hash, (int)$u['id']]);
                    Flash::set('success', 'Password changed. Please log in again.');
                    Http::redirect('/login.php');
                }
            }
        }
    }
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Enter OTP';

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-lg-5">
        <h1 class="h3 mb-3">Reset password (OTP)</h1>

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
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="email" required value="<?= View::e($_POST['email'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Phone</label>
                <input class="form-control" name="phone" required value="<?= View::e($_POST['phone'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">OTP (6 digits)</label>
                <input class="form-control" name="otp" required value="<?= View::e($_POST['otp'] ?? '') ?>">
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
                <button class="btn btn-primary" type="submit">Change password</button>
                <a class="btn btn-outline-secondary" href="/reset_password_request.php">Back</a>
            </div>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';

