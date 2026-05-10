<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\Auth;
use App\Csrf;
use App\Flash;
use App\Http;
use App\View;

if ($currentUser) {
    Http::redirect('/');
}

$errors = [];
$lockSeconds = null;

if (Http::isPost()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid request';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $errors[] = 'Username and password are required';
        } else {
            $res = Auth::loginByIdentifier($identifier, $password);
            if (!$res['ok']) {
                $err = (string)$res['error'];
                $errors[] = $err;
                if ($lockSeconds === null && preg_match('/try again in (\d+) seconds/', $err, $m)) {
                    $lockSeconds = (int)$m[1];
                }
            } else {
                $u = $res['user'];
                if (($u['role'] ?? '') === 'admin') {
                    Http::redirect('/admin/dashboard.php');
                }
                Http::redirect('/dashboard.php');
            }
        }
    }
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Login';

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-lg-5">
        <h1 class="h3 mb-3">Login</h1>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?= View::e($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($lockSeconds !== null && $lockSeconds > 0): ?>
            <div class="alert alert-warning" data-countdown-seconds="<?= View::e((string)$lockSeconds) ?>">
                Please wait <span data-countdown-value><?= View::e((string)$lockSeconds) ?></span> seconds before trying again.
            </div>
        <?php endif; ?>

        <form method="post" class="card card-body">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <div class="mb-3">
                <label class="form-label">Email or phone</label>
                <input class="form-control" name="identifier" required value="<?= View::e($_POST['identifier'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input class="form-control" name="password" type="password" required>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Login</button>
                <a class="btn btn-outline-secondary" href="/register.php">Register</a>
            </div>
            <div class="mt-3">
                <a href="/reset_password_request.php">Forgot password?</a>
            </div>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
