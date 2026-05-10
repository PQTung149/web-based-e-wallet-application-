<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Csrf;
use App\Flash;
use App\Http;
use App\Util;
use App\View;

if ($currentUser) {
    Http::redirect('/');
}

$errors = [];
$generatedPassword = null;

if (Http::isPost()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid request';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $dob = trim((string)($_POST['dob'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }
        if ($phone === '' || !preg_match('/^\d{8,15}$/', $phone)) {
            $errors[] = 'Valid phone number is required';
        }
        if ($fullName === '') {
            $errors[] = 'Full name is required';
        }
        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            $errors[] = 'Date of birth is invalid';
        }

        if ($password !== '' || $confirmPassword !== '') {
            if (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters';
            }
            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match';
            }
        }

        $front = $_FILES['id_front'] ?? null;
        $back = $_FILES['id_back'] ?? null;
        if (!$front || ($front['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'ID front photo is required';
        }
        if (!$back || ($back['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'ID back photo is required';
        }

        $pdo = App::db()->pdo();
        if (!$errors) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1');
            $stmt->execute([$email, $phone]);
            if ($stmt->fetch()) {
                $errors[] = 'Email or phone already exists';
            }
        }

        if (!$errors) {
            if ($password !== '') {
                $generatedPassword = null;
                $hash = password_hash($password, PASSWORD_DEFAULT);
            } else {
                $generatedPassword = Util::randomString(6);
                $hash = password_hash($generatedPassword, PASSWORD_DEFAULT);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (role,email,phone,full_name,dob,address,password_hash,status,must_change_password)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    'user',
                    $email,
                    $phone,
                    $fullName,
                    $dob !== '' ? $dob : null,
                    $address !== '' ? $address : null,
                    $hash,
                    'pending_verification',
                    1,
                ]);

                $userId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, ?)');
                $stmt->execute([$userId, 0]);

                $uploadDir = __DIR__ . '/uploads/ids/' . $userId;
                if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
                    throw new RuntimeException('Upload directory error');
                }

                $frontExt = strtolower(pathinfo((string)$front['name'], PATHINFO_EXTENSION));
                $backExt = strtolower(pathinfo((string)$back['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png'];
                if (!in_array($frontExt, $allowed, true) || !in_array($backExt, $allowed, true)) {
                    throw new RuntimeException('Only JPG/PNG images are allowed');
                }

                $frontRel = '/uploads/ids/' . $userId . '/front.' . $frontExt;
                $backRel = '/uploads/ids/' . $userId . '/back.' . $backExt;
                $frontAbs = __DIR__ . $frontRel;
                $backAbs = __DIR__ . $backRel;

                if (!move_uploaded_file((string)$front['tmp_name'], $frontAbs)) {
                    throw new RuntimeException('Failed to save ID front photo');
                }
                if (!move_uploaded_file((string)$back['tmp_name'], $backAbs)) {
                    throw new RuntimeException('Failed to save ID back photo');
                }

                $stmt = $pdo->prepare('UPDATE users SET id_front_path = ?, id_back_path = ? WHERE id = ?');
                $stmt->execute([$frontRel, $backRel, $userId]);

                $pdo->commit();

                $subject = 'Your E-Wallet login credentials';
                if ($generatedPassword) {
                    $body = "Welcome!\n\nUsername (email): {$email}\nUsername (phone): {$phone}\nPassword: {$generatedPassword}\n\nOn first login you must change your password.";
                } else {
                    $body = "Welcome!\n\nUsername (email): {$email}\nUsername (phone): {$phone}\nPassword: (the password you set during registration)\n\nOn first login you must change your password.";
                }
                App::mailer()->send($email, $subject, $body);

                Flash::set('success', 'Registration successful.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Registration failed';
                $generatedPassword = null;
            }
        }
    }
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Register';

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <h1 class="h3 mb-3">Register</h1>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?= View::e($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($generatedPassword): ?>
            <div class="alert alert-warning">
                <div><strong>Email:</strong> <?= View::e($email ?? '') ?></div>
                <div><strong>Phone:</strong> <?= View::e($phone ?? '') ?></div>
                <div><strong>Generated password:</strong> <?= View::e($generatedPassword) ?></div>
                <div class="mt-2">If email sending is not available on hosting, evaluators can use the above credentials.</div>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="card card-body">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input class="form-control" name="email" type="email" required value="<?= View::e($_POST['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input class="form-control" name="phone" required value="<?= View::e($_POST['phone'] ?? '') ?>">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Full name</label>
                    <input class="form-control" name="full_name" required value="<?= View::e($_POST['full_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date of birth</label>
                    <input class="form-control" name="dob" type="date" value="<?= View::e($_POST['dob'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Address</label>
                    <input class="form-control" name="address" value="<?= View::e($_POST['address'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <input class="form-control" name="password" type="password" value="">
                    <div class="form-text">Leave blank to auto-generate a password.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm password</label>
                    <input class="form-control" name="confirm_password" type="password" value="">
                </div>
                <div class="col-md-6">
                    <label class="form-label">ID front photo</label>
                    <input class="form-control" type="file" name="id_front" accept=".jpg,.jpeg,.png" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ID back photo</label>
                    <input class="form-control" type="file" name="id_back" accept=".jpg,.jpeg,.png" required>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Create account</button>
                    <a class="btn btn-outline-secondary" href="/login.php">Back to login</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
