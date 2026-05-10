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
$result = null;

if (Http::isPost() && ($currentUser['status'] ?? '') === 'verified') {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid request';
    } else {
        $carrier = (string)($_POST['carrier'] ?? '');
        $denom = (int)($_POST['denomination'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 0);

        $carriers = [
            'viettel' => ['label' => 'Viettel', 'prefix' => '11111'],
            'mobifone' => ['label' => 'Mobifone', 'prefix' => '22222'],
            'vinaphone' => ['label' => 'Vinaphone', 'prefix' => '33333'],
        ];
        $denoms = [10000, 20000, 50000, 100000];

        if (!isset($carriers[$carrier])) {
            $errors[] = 'Invalid carrier';
        }
        if (!in_array($denom, $denoms, true)) {
            $errors[] = 'Invalid denomination';
        }
        if ($qty < 1 || $qty > 5) {
            $errors[] = 'You can buy up to 5 cards per transaction';
        }

        if (!$errors) {
            $fee = 0;
            $total = $denom * $qty;

            $db = App::db();
            $pdo = $db->pdo();
            $db->begin();
            try {
                $userId = (int)$currentUser['id'];
                $bal = Wallet::lockBalance($pdo, $userId);
                if ($bal < $total + $fee) {
                    $db->rollBack();
                    $errors[] = 'Insufficient balance';
                } else {
                    Wallet::setBalance($pdo, $userId, $bal - $total - $fee);

                    $prefix = $carriers[$carrier]['prefix'];
                    $codes = [];
                    for ($i = 0; $i < $qty; $i++) {
                        $codes[] = $prefix . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                    }

                    $txCode = Util::txCode();
                    $stmt = $pdo->prepare(
                        "INSERT INTO transactions (code, type, user_id, amount, fee, status, note, meta_json)
                         VALUES (?, 'phone_card', ?, ?, ?, 'success', NULL, ?)"
                    );
                    $stmt->execute([
                        $txCode,
                        $userId,
                        $total,
                        $fee,
                        json_encode([
                            'carrier' => $carrier,
                            'carrier_label' => $carriers[$carrier]['label'],
                            'denomination' => $denom,
                            'quantity' => $qty,
                            'codes' => $codes,
                        ], JSON_UNESCAPED_UNICODE),
                    ]);

                    $db->commit();
                    $result = [
                        'tx_code' => $txCode,
                        'carrier_label' => $carriers[$carrier]['label'],
                        'denomination' => $denom,
                        'quantity' => $qty,
                        'fee' => $fee,
                        'total' => $total,
                        'codes' => $codes,
                    ];
                }
            } catch (Throwable $e) {
                $db->rollBack();
                $errors[] = 'Purchase failed';
            }
        }
    }
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Buy phone card';

ob_start();
?>
<h1 class="h3 mb-3">Buy phone card</h1>

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

    <?php if ($result): ?>
        <div class="card card-body mb-3">
            <h2 class="h5">Purchase result</h2>
            <div><strong>Transaction:</strong> <?= View::e((string)$result['tx_code']) ?></div>
            <div><strong>Carrier:</strong> <?= View::e((string)$result['carrier_label']) ?></div>
            <div><strong>Denomination:</strong> <?= View::e(Util::money((int)$result['denomination'])) ?></div>
            <div><strong>Quantity:</strong> <?= View::e((string)$result['quantity']) ?></div>
            <div><strong>Fee:</strong> <?= View::e(Util::money((int)$result['fee'])) ?></div>
            <div><strong>Total:</strong> <?= View::e(Util::money((int)$result['total'])) ?></div>
            <div class="mt-3">
                <div class="fw-semibold mb-2">Card codes</div>
                <ul class="mb-0">
                    <?php foreach ($result['codes'] as $c): ?>
                        <li><?= View::e((string)$c) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-6">
            <form method="post" class="card card-body">
                <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Carrier</label>
                        <select class="form-select" name="carrier" required>
                            <option value="viettel" <?= (($_POST['carrier'] ?? '') === 'viettel') ? 'selected' : '' ?>>Viettel</option>
                            <option value="mobifone" <?= (($_POST['carrier'] ?? '') === 'mobifone') ? 'selected' : '' ?>>Mobifone</option>
                            <option value="vinaphone" <?= (($_POST['carrier'] ?? '') === 'vinaphone') ? 'selected' : '' ?>>Vinaphone</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Denomination</label>
                        <select class="form-select" name="denomination" required>
                            <option value="10000" <?= (($_POST['denomination'] ?? '') === '10000') ? 'selected' : '' ?>>10,000 VND</option>
                            <option value="20000" <?= (($_POST['denomination'] ?? '') === '20000') ? 'selected' : '' ?>>20,000 VND</option>
                            <option value="50000" <?= (($_POST['denomination'] ?? '') === '50000') ? 'selected' : '' ?>>50,000 VND</option>
                            <option value="100000" <?= (($_POST['denomination'] ?? '') === '100000') ? 'selected' : '' ?>>100,000 VND</option>
                        </select>
                        <div class="form-text">Fee: 0 VND</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Quantity (max 5)</label>
                        <input class="form-control" name="quantity" type="number" min="1" max="5" required value="<?= View::e($_POST['quantity'] ?? '1') ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Buy</button>
                        <a class="btn btn-outline-secondary" href="/dashboard.php">Back</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
