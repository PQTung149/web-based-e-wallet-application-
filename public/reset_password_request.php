<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Csrf;
use App\Flash;
use App\Http;
use App\Otp;
use App\View;

$errors = [];

if (Http::isPost()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid request';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }
        if ($phone === '' || !preg_match('/^\d{8,15}$/', $phone)) {
            $errors[] = 'Valid phone number is required';
        }

        if (!$errors) {
            $pdo = App::db()->pdo();
            $stmt = $pdo->prepare("SELECT id, status, full_name FROM users WHERE role='user' AND email = ? AND phone = ? LIMIT 1");
            $stmt->execute([$email, $phone]);
            $u = $stmt->fetch();

            if (!$u) {
                $errors[] = 'No matching account found';
            } elseif (($u['status'] ?? '') === 'disabled') {
                $errors[] = 'This account has been disabled, please contact the hotline 18001008';
            } else {
                $otp = Otp::create((int)$u['id'], 'reset_password', [], 60);
                $subject = 'OTP for password reset';
                $body = "Hello {$u['full_name']},\n\nYour OTP code is: {$otp['code']}\nThis code expires in 1 minute.\n\nIf you did not request this, ignore this email.";
                App::mailer()->send($email, $subject, $body);
                Flash::set('success', 'OTP generated. Please open storage/mail_outbox.log to view the OTP and enter it within 1 minute.');
                Http::redirect('/reset_password_confirm.php');
            }
        }
    }
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Reset password';

ob_start();
?>
<h1 class="h3 mb-3">Reset password</h1>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= View::e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-5">
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
            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Send OTP</button>
                <a class="btn btn-outline-secondary" href="/login.php">Back to login</a>
            </div>
        </form>
        <div class="mt-3">
            <a href="/reset_password_confirm.php">Already have OTP?</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
