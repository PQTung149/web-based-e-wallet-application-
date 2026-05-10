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

if (($currentUser['role'] ?? '') !== 'user') {
    \App\Http::redirect('/');
}

$errors = [];

if (Http::isPost() && ($currentUser['status'] ?? '') === 'verified') {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid request';
    } else {
        $cardNumber = trim((string)($_POST['card_number'] ?? ''));
        $exp = trim((string)($_POST['expiration'] ?? ''));
        $cvv = trim((string)($_POST['cvv'] ?? ''));
        $amountRaw = trim((string)($_POST['amount'] ?? ''));
        $amount = (int)str_replace([',', ' '], '', $amountRaw);

        if (!preg_match('/^\d{6}$/', $cardNumber)) {
            $errors[] = 'Card number must be 6 digits';
        }
        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $exp)) {
            $errors[] = 'Expiration must be in format dd/mm/yyyy';
        }
        if (!preg_match('/^\d{3}$/', $cvv)) {
            $errors[] = 'CVV must be 3 digits';
        }
        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than 0';
        }

        $cards = [
            '111111' => ['exp' => '10/10/2022', 'cvv' => '411', 'max' => null, 'mode' => 'ok'],
            '222222' => ['exp' => '11/11/2022', 'cvv' => '443', 'max' => 1000000, 'mode' => 'ok'],
            '333333' => ['exp' => '12/12/2022', 'cvv' => '577', 'max' => null, 'mode' => 'out_of_money'],
        ];

        if (!$errors && !isset($cards[$cardNumber])) {
            $errors[] = 'this card is not supported';
        }

        if (!$errors) {
            $card = $cards[$cardNumber];
            if ($exp !== $card['exp']) {
                $errors[] = 'Invalid expiration date';
            }
            if ($cvv !== $card['cvv']) {
                $errors[] = 'Invalid CVV code';
            }
            if (!$errors && $card['mode'] === 'out_of_money') {
                $errors[] = 'card is out of money';
            }
            if (!$errors && $card['max'] !== null && $amount > (int)$card['max']) {
                $errors[] = 'This card can only be loaded up to 1,000,000 VND/time';
            }
        }

        if (!$errors) {
            $db = App::db();
            $pdo = $db->pdo();
            $db->begin();
            try {
                $userId = (int)$currentUser['id'];
                $bal = Wallet::lockBalance($pdo, $userId);
                $newBal = $bal + $amount;
                Wallet::setBalance($pdo, $userId, $newBal);

                $stmt = $pdo->prepare(
                    "INSERT INTO transactions (code, type, user_id, amount, fee, status, note, meta_json)
                     VALUES (?, 'deposit', ?, ?, 0, 'success', NULL, ?)"
                );
                $stmt->execute([
                    Util::txCode(),
                    $userId,
                    $amount,
                    json_encode(['card_number' => $cardNumber, 'expiration' => $exp], JSON_UNESCAPED_UNICODE),
                ]);

                $db->commit();
                Flash::set('success', 'Deposit successful');
                Http::redirect('/dashboard.php');
            } catch (Throwable $e) {
                $db->rollBack();
                $errors[] = 'Deposit failed';
            }
        }
    }
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Deposit';

ob_start();
?>
<h1 class="h3 mb-3">Deposit</h1>

<?php if (($currentUser['status'] ?? '') !== 'verified'): ?>
    <div class="alert alert-warning">This feature is only available for verified accounts.</div>
<?php else: ?>
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
        <div class="col-lg-6">
            <form method="post" class="card card-body">
                <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Card number (6 digits)</label>
                        <input class="form-control" name="card_number" required value="<?= View::e($_POST['card_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Expiration (dd/mm/yyyy)</label>
                        <input class="form-control" name="expiration" required placeholder="10/10/2022" value="<?= View::e($_POST['expiration'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">CVV (3 digits)</label>
                        <input class="form-control" name="cvv" required value="<?= View::e($_POST['cvv'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Amount (VND)</label>
                        <input class="form-control" name="amount" required inputmode="numeric" autocomplete="off" data-money="vnd" value="<?= View::e($_POST['amount'] ?? '') ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Deposit</button>
                        <a class="btn btn-outline-secondary" href="/dashboard.php">Back</a>
                    </div>
                </div>
            </form>
            <div class="small text-muted mt-3">
                Supported top-up cards: 111111, 222222, 333333 (simulation).
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
