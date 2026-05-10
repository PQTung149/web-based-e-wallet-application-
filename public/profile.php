<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Auth;
use App\Csrf;
use App\Flash;
use App\Http;
use App\Util;
use App\View;
use App\Wallet;

Auth::requireLogin();

if (($currentUser['role'] ?? '') === 'admin') {
    Http::redirect('/admin/dashboard.php');
}

$errors = [];

if (Http::isPost()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid request';
    } elseif (($currentUser['status'] ?? '') !== 'waiting_updates') {
        $errors[] = 'This action is not available';
    } else {
        $front = $_FILES['id_front'] ?? null;
        $back = $_FILES['id_back'] ?? null;
        if (!$front || ($front['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'ID front photo is required';
        }
        if (!$back || ($back['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'ID back photo is required';
        }

        if (!$errors) {
            $userId = (int)$currentUser['id'];
            $uploadDir = __DIR__ . '/uploads/ids/' . $userId;
            if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
                $errors[] = 'Upload directory error';
            } else {
                $frontExt = strtolower(pathinfo((string)$front['name'], PATHINFO_EXTENSION));
                $backExt = strtolower(pathinfo((string)$back['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png'];
                if (!in_array($frontExt, $allowed, true) || !in_array($backExt, $allowed, true)) {
                    $errors[] = 'Only JPG/PNG images are allowed';
                } else {
                    $frontRel = '/uploads/ids/' . $userId . '/front.' . $frontExt;
                    $backRel = '/uploads/ids/' . $userId . '/back.' . $backExt;
                    $frontAbs = __DIR__ . $frontRel;
                    $backAbs = __DIR__ . $backRel;

                    if (!move_uploaded_file((string)$front['tmp_name'], $frontAbs)) {
                        $errors[] = 'Failed to save ID front photo';
                    }
                    if (!move_uploaded_file((string)$back['tmp_name'], $backAbs)) {
                        $errors[] = 'Failed to save ID back photo';
                    }

                    if (!$errors) {
                        $pdo = App::db()->pdo();
                        $stmt = $pdo->prepare(
                            'UPDATE users
                             SET id_front_path = ?, id_back_path = ?, status = ?
                             WHERE id = ?'
                        );
                        $stmt->execute([$frontRel, $backRel, 'pending_verification', $userId]);
                        Flash::set('success', 'ID photos uploaded. Please wait for admin verification.');
                        Http::redirect('/profile.php');
                    }
                }
            }
        }
    }
}

$currentUser = Auth::user();
$balance = Wallet::getBalance((int)$currentUser['id']);

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Profile';

ob_start();
?>
<h1 class="h3 mb-3">Profile</h1>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= View::e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card card-body">
            <div class="mb-2"><strong>Full name:</strong> <?= View::e((string)$currentUser['full_name']) ?></div>
            <div class="mb-2"><strong>Email:</strong> <?= View::e((string)$currentUser['email']) ?></div>
            <div class="mb-2"><strong>Phone:</strong> <?= View::e((string)$currentUser['phone']) ?></div>
            <div class="mb-2"><strong>Status:</strong> <?= View::e((string)$currentUser['status']) ?></div>
            <div class="mb-2"><strong>Balance:</strong> <?= View::e(Util::money($balance)) ?></div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-primary" href="/change_password.php">Change password</a>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-body">
            <div class="row g-3">
                <div class="col-6">
                    <div class="small text-muted mb-2">ID front</div>
                    <?php if (!empty($currentUser['id_front_path'])): ?>
                        <img class="img-fluid rounded border" src="<?= View::e((string)$currentUser['id_front_path']) ?>" alt="ID front">
                    <?php else: ?>
                        <div class="text-muted">Not uploaded</div>
                    <?php endif; ?>
                </div>
                <div class="col-6">
                    <div class="small text-muted mb-2">ID back</div>
                    <?php if (!empty($currentUser['id_back_path'])): ?>
                        <img class="img-fluid rounded border" src="<?= View::e((string)$currentUser['id_back_path']) ?>" alt="ID back">
                    <?php else: ?>
                        <div class="text-muted">Not uploaded</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (($currentUser['status'] ?? '') === 'waiting_updates'): ?>
    <div class="card card-body mt-3">
        <h2 class="h5">Additional information required</h2>
        <div class="alert alert-warning">
            Admin requested that you re-upload your ID card photos.
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">ID front photo</label>
                    <input class="form-control" type="file" name="id_front" accept=".jpg,.jpeg,.png" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ID back photo</label>
                    <input class="form-control" type="file" name="id_back" accept=".jpg,.jpeg,.png" required>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Upload</button>
                </div>
            </div>
        </form>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
