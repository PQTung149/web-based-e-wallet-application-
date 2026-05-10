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
        $note = trim((string)($_POST['note'] ?? ''));
        $amountRaw = trim((string)($_POST['amount'] ?? ''));
        $amount = (int)str_replace([',', ' '], '', $amountRaw);

        if ($cardNumber !== '111111') {
            $errors[] = 'This card is not supported for withdrawal';
        } elseif ($exp !== '10/10/2022' || $cvv !== '411') {
            $errors[] = 'Invalid card information';
        }

        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than 0';
        } elseif ($amount % 50000 !== 0) {
            $errors[] = 'Withdrawal amount must be a multiple of 50,000 VND';
        }

        if (!$errors) {
            $pdo = App::db()->pdo();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS c
                 FROM transactions
                 WHERE user_id = ? AND type = 'withdraw' AND DATE(created_at) = CURDATE() AND status IN ('success','pending_admin')"
            );
            $stmt->execute([(int)$currentUser['id']]);
            $countToday = (int)($stmt->fetch()['c'] ?? 0);
            if ($countToday >= 2) {
                $errors[] = 'Only 2 withdrawals can be made per day';
            }
        }

        if (!$errors) {
            $fee = intdiv($amount * 5, 100);
            $total = $amount + $fee;
            $needsAdmin = $amount > 5000000;

            $db = App::db();
            $pdo = $db->pdo();
            $db->begin();
            try {
                $userId = (int)$currentUser['id'];

                if ($needsAdmin) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO transactions (code, type, user_id, amount, fee, status, note, meta_json)
                         VALUES (?, 'withdraw', ?, ?, ?, 'pending_admin', ?, ?)"
                    );
                    $stmt->execute([
                        Util::txCode(),
                        $userId,
                        $amount,
                        $fee,
                        $note !== '' ? $note : null,
                        json_encode(['card_number' => $cardNumber, 'expiration' => $exp, 'cvv' => $cvv], JSON_UNESCAPED_UNICODE),
                    ]);
                    $db->commit();
                    Flash::set('success', 'Withdrawal created and is pending admin approval');
                    Http::redirect('/transactions.php');
                }

                $bal = Wallet::lockBalance($pdo, $userId);
                if ($bal < $total) {
                    $db->rollBack();
                    $errors[] = 'Insufficient balance';
                } else {
                    Wallet::setBalance($pdo, $userId, $bal - $total);
                    $stmt = $pdo->prepare(
                        "INSERT INTO transactions (code, type, user_id, amount, fee, status, note, meta_json)
                         VALUES (?, 'withdraw', ?, ?, ?, 'success', ?, ?)"
                    );
                    $stmt->execute([
                        Util::txCode(),
                        $userId,
                        $amount,
                        $fee,
                        $note !== '' ? $note : null,
                        json_encode(['card_number' => $cardNumber, 'expiration' => $exp, 'cvv' => $cvv], JSON_UNESCAPED_UNICODE),
                    ]);
                    $db->commit();
                    Flash::set('success', 'Withdrawal successful');
                    Http::redirect('/dashboard.php');
                }
            } catch (Throwable $e) {
                $db->rollBack();
                $errors[] = 'Withdrawal failed';
            }
        }
    }
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Withdraw';

ob_start();
?>
<h1 class="h3 mb-3">Withdraw</h1>

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
        <div class="col-lg-7">
            <form method="post" class="card card-body">
                <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Card number</label>
                        <input class="form-control" name="card_number" required value="<?= View::e($_POST['card_number'] ?? '111111') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Expiration</label>
                        <input class="form-control" name="expiration" required value="<?= View::e($_POST['expiration'] ?? '10/10/2022') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">CVV</label>
                        <input class="form-control" name="cvv" required value="<?= View::e($_POST['cvv'] ?? '411') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Amount (multiple of 50,000)</label>
                        <input class="form-control" name="amount" required inputmode="numeric" autocomplete="off" data-money="vnd" value="<?= View::e($_POST['amount'] ?? '') ?>">
                        <div class="form-text">Fee: 5%</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Note</label>
                        <input class="form-control" name="note" value="<?= View::e($_POST['note'] ?? '') ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Withdraw</button>
                        <a class="btn btn-outline-secondary" href="/dashboard.php">Back</a>
                    </div>
                </div>
            </form>
            <div class="small text-muted mt-3">
                Withdrawals over 5,000,000 VND will be pending admin approval. Maximum 2 withdrawals per day.
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
