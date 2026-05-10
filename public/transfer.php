<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Auth;
use App\Csrf;
use App\Flash;
use App\Http;
use App\Otp;
use App\Util;
use App\View;
use App\Wallet;

Auth::requireLogin();

if (($currentUser['role'] ?? '') !== 'user') {
    \App\Http::redirect('/');
}

$errors = [];
$tx = null;
$recipient = null;
$meta = [];

if (($currentUser['status'] ?? '') === 'verified') {
    $txId = (int)($_GET['tx'] ?? 0);
    if ($txId > 0) {
        $pdo = App::db()->pdo();
        $stmt = $pdo->prepare(
            "SELECT t.*, u2.full_name AS recipient_name, u2.email AS recipient_email, u2.phone AS recipient_phone
             FROM transactions t
             LEFT JOIN users u2 ON u2.id = t.related_user_id
             WHERE t.id = ? AND t.user_id = ? AND t.type = 'transfer_out'
             LIMIT 1"
        );
        $stmt->execute([$txId, (int)$currentUser['id']]);
        $tx = $stmt->fetch();
        if ($tx && !empty($tx['meta_json'])) {
            $decoded = json_decode((string)$tx['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
    }
}

if (Http::isPost() && ($currentUser['status'] ?? '') === 'verified') {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid request';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'request_otp') {
            $toPhone = trim((string)($_POST['to_phone'] ?? ''));
            $amountRaw = trim((string)($_POST['amount'] ?? ''));
            $amount = (int)str_replace([',', ' '], '', $amountRaw);
            $note = trim((string)($_POST['note'] ?? ''));
            $feePayer = (string)($_POST['fee_payer'] ?? 'sender');
            if ($feePayer !== 'sender' && $feePayer !== 'receiver') {
                $feePayer = 'sender';
            }

            if ($toPhone === '' || !preg_match('/^\d{8,15}$/', $toPhone)) {
                $errors[] = 'Valid recipient phone is required';
            }
            if ($amount <= 0) {
                $errors[] = 'Amount must be greater than 0';
            }
            if ($toPhone === (string)$currentUser['phone']) {
                $errors[] = 'Cannot transfer to your own account';
            }

            $pdo = App::db()->pdo();
            if (!$errors) {
                $stmt = $pdo->prepare("SELECT id, full_name, email, status FROM users WHERE role='user' AND phone = ? LIMIT 1");
                $stmt->execute([$toPhone]);
                $recipient = $stmt->fetch();
                if (!$recipient) {
                    $errors[] = 'Recipient not found';
                } elseif (($recipient['status'] ?? '') === 'disabled') {
                    $errors[] = 'Recipient account is disabled';
                }
            }

            if (!$errors) {
                $fee = intdiv($amount * 5, 100);
                $senderDebit = $amount + ($feePayer === 'sender' ? $fee : 0);
                $receiverCredit = $amount - ($feePayer === 'receiver' ? $fee : 0);
                if ($receiverCredit < 0) {
                    $errors[] = 'Invalid transfer amount';
                } else {
                    $bal = Wallet::getBalance((int)$currentUser['id']);
                    if ($bal < $senderDebit) {
                        $errors[] = 'Insufficient balance';
                    }
                }
            }

            if (!$errors) {
                $db = App::db();
                $pdo = $db->pdo();
                $db->begin();
                try {
                    $txCode = Util::txCode();
                    $stmt = $pdo->prepare(
                        "INSERT INTO transactions (code, type, user_id, related_user_id, amount, fee, status, note, meta_json)
                         VALUES (?, 'transfer_out', ?, ?, ?, ?, 'pending_otp', ?, ?)"
                    );
                    $stmt->execute([
                        $txCode,
                        (int)$currentUser['id'],
                        (int)$recipient['id'],
                        $amount,
                        $fee,
                        $note !== '' ? $note : null,
                        json_encode([
                            'fee_payer' => $feePayer,
                            'receiver_credit' => $receiverCredit,
                            'sender_debit' => $senderDebit,
                            'to_phone' => $toPhone,
                            'to_name' => (string)$recipient['full_name'],
                        ], JSON_UNESCAPED_UNICODE),
                    ]);
                    $newTxId = (int)$pdo->lastInsertId();

                    $otp = Otp::create((int)$currentUser['id'], 'transfer', ['tx_id' => $newTxId], 60);
                    $subject = 'OTP for money transfer';
                    $body = "Your OTP code is: {$otp['code']}\nThis code expires in 1 minute.";
                    App::mailer()->send((string)$currentUser['email'], $subject, $body);

                    $db->commit();
                    Flash::set('success', 'OTP generated. Please open storage/mail_outbox.log to view the OTP and enter it within 1 minute.');
                    Http::redirect('/transfer.php?tx=' . $newTxId);
                } catch (Throwable $e) {
                    $db->rollBack();
                    $errors[] = 'Failed to create transfer';
                }
            }
        }

        if ($action === 'confirm_otp') {
            $txId = (int)($_POST['tx_id'] ?? 0);
            $otpCode = trim((string)($_POST['otp'] ?? ''));

            if ($txId <= 0) {
                $errors[] = 'Invalid transfer';
            }
            if (!preg_match('/^\d{6}$/', $otpCode)) {
                $errors[] = 'OTP must be 6 digits';
            }

            if (!$errors) {
                $db = App::db();
                $pdo = $db->pdo();
                $stmt = $pdo->prepare(
                    "SELECT t.*, u2.full_name AS recipient_name, u2.email AS recipient_email
                     FROM transactions t
                     JOIN users u2 ON u2.id = t.related_user_id
                     WHERE t.id = ? AND t.user_id = ? AND t.type='transfer_out'
                     LIMIT 1"
                );
                $stmt->execute([$txId, (int)$currentUser['id']]);
                $tx = $stmt->fetch();
                if (!$tx || ($tx['status'] ?? '') !== 'pending_otp') {
                    $errors[] = 'This transfer is not waiting for OTP';
                }
            }

            if (!$errors) {
                $otpRow = Otp::consume((int)$currentUser['id'], 'transfer', $otpCode);
                if (!$otpRow) {
                    $errors[] = 'Invalid or expired OTP';
                } else {
                    $otpMeta = [];
                    if (!empty($otpRow['meta_json'])) {
                        $decoded = json_decode((string)$otpRow['meta_json'], true);
                        if (is_array($decoded)) {
                            $otpMeta = $decoded;
                        }
                    }
                    if ((int)($otpMeta['tx_id'] ?? 0) !== (int)$txId) {
                        $errors[] = 'Invalid OTP';
                    }
                }
            }

            if (!$errors) {
                $amount = (int)$tx['amount'];
                $fee = (int)$tx['fee'];
                $feePayer = 'sender';
                $meta = [];
                if (!empty($tx['meta_json'])) {
                    $decoded = json_decode((string)$tx['meta_json'], true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }
                if (($meta['fee_payer'] ?? '') === 'receiver') {
                    $feePayer = 'receiver';
                }

                $senderDebit = $amount + ($feePayer === 'sender' ? $fee : 0);
                $receiverCredit = $amount - ($feePayer === 'receiver' ? $fee : 0);
                $needsAdmin = $amount > 5000000;

                $db = App::db();
                $pdo = $db->pdo();
                $db->begin();
                try {
                    $stmt = $pdo->prepare("SELECT status FROM transactions WHERE id = ? FOR UPDATE");
                    $stmt->execute([$txId]);
                    $locked = $stmt->fetch();
                    if (!$locked || ($locked['status'] ?? '') !== 'pending_otp') {
                        throw new RuntimeException('Not pending');
                    }

                    if ($needsAdmin) {
                        $stmt = $pdo->prepare("UPDATE transactions SET status='pending_admin' WHERE id = ?");
                        $stmt->execute([$txId]);
                        $db->commit();
                        Flash::set('success', 'Transfer confirmed by OTP and is pending admin approval');
                        Http::redirect('/transactions.php');
                    }

                    $senderId = (int)$currentUser['id'];
                    $receiverId = (int)$tx['related_user_id'];

                    $a = min($senderId, $receiverId);
                    $b = max($senderId, $receiverId);
                    $balA = Wallet::lockBalance($pdo, $a);
                    $balB = Wallet::lockBalance($pdo, $b);

                    $senderBal = ($senderId === $a) ? $balA : $balB;
                    $receiverBal = ($receiverId === $a) ? $balA : $balB;

                    if ($senderBal < $senderDebit) {
                        $stmt = $pdo->prepare("UPDATE transactions SET status='rejected' WHERE id = ?");
                        $stmt->execute([$txId]);
                        $db->commit();
                        Flash::set('error', 'Transfer failed: insufficient balance');
                        Http::redirect('/transactions.php');
                    }

                    $senderBal -= $senderDebit;
                    $receiverBal += $receiverCredit;

                    if ($senderId === $a) {
                        $balA = $senderBal;
                    } else {
                        $balB = $senderBal;
                    }
                    if ($receiverId === $a) {
                        $balA = $receiverBal;
                    } else {
                        $balB = $receiverBal;
                    }

                    Wallet::setBalance($pdo, $a, $balA);
                    Wallet::setBalance($pdo, $b, $balB);

                    $stmt = $pdo->prepare("UPDATE transactions SET status='success' WHERE id = ?");
                    $stmt->execute([$txId]);

                    $metaIn = $meta;
                    $metaIn['original_amount'] = $amount;
                    $metaIn['fee'] = $fee;
                    $metaIn['fee_payer'] = $feePayer;

                    $stmt = $pdo->prepare(
                        "INSERT INTO transactions (code, type, user_id, related_user_id, amount, fee, status, note, meta_json)
                         VALUES (?, 'transfer_in', ?, ?, ?, 0, 'success', ?, ?)"
                    );
                    $stmt->execute([
                        (string)$tx['code'],
                        $receiverId,
                        $senderId,
                        $receiverCredit,
                        (string)($tx['note'] ?? ''),
                        json_encode($metaIn, JSON_UNESCAPED_UNICODE),
                    ]);

                    $db->commit();

                    $subject = 'You received money';
                    $body = "You received " . Util::money($receiverCredit) . " from " . (string)$currentUser['full_name'] . ".\nYour balance is now " . Util::money(Wallet::getBalance($receiverId)) . ".";
                    App::mailer()->send((string)$tx['recipient_email'], $subject, $body);

                    Flash::set('success', 'Transfer successful');
                    Http::redirect('/transactions.php');
                } catch (Throwable $e) {
                    $db->rollBack();
                    $errors[] = 'Transfer failed';
                }
            }
        }
    }
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Transfer';

ob_start();
?>
<h1 class="h3 mb-3">Transfer</h1>

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

    <?php if ($tx && ($tx['status'] ?? '') === 'pending_otp'): ?>
        <div class="card card-body mb-3">
            <h2 class="h5">Confirm transfer</h2>
            <div class="mb-2"><strong>To:</strong> <?= View::e((string)($tx['recipient_name'] ?? '')) ?></div>
            <div class="mb-2"><strong>Amount:</strong> <?= View::e(Util::money((int)$tx['amount'])) ?></div>
            <div class="mb-2"><strong>Fee:</strong> <?= View::e(Util::money((int)$tx['fee'])) ?></div>
            <div class="mb-2"><strong>Fee payer:</strong> <?= View::e((string)($meta['fee_payer'] ?? 'sender')) ?></div>
            <div class="text-muted small">OTP was sent to your email. Enter it within 1 minute.</div>
        </div>

        <form method="post" class="card card-body">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="confirm_otp">
            <input type="hidden" name="tx_id" value="<?= View::e((string)$tx['id']) ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">OTP (6 digits)</label>
                    <input class="form-control" name="otp" required>
                </div>
                <div class="col-md-6 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Confirm</button>
                    <a class="btn btn-outline-secondary" href="/transfer.php">New transfer</a>
                </div>
            </div>
        </form>
    <?php else: ?>
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <form method="post" class="card card-body">
                    <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="request_otp">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Recipient phone</label>
                            <input class="form-control" name="to_phone" required value="<?= View::e($_POST['to_phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount (VND)</label>
                            <input class="form-control" name="amount" required inputmode="numeric" autocomplete="off" data-money="vnd" value="<?= View::e($_POST['amount'] ?? '') ?>">
                            <div class="form-text">Fee: 5%</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fee payer</label>
                            <select class="form-select" name="fee_payer" required>
                                <option value="sender" <?= (($_POST['fee_payer'] ?? '') !== 'receiver') ? 'selected' : '' ?>>Sender pays fee</option>
                                <option value="receiver" <?= (($_POST['fee_payer'] ?? '') === 'receiver') ? 'selected' : '' ?>>Receiver pays fee</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Note</label>
                            <input class="form-control" name="note" value="<?= View::e($_POST['note'] ?? '') ?>">
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary" type="submit">Send OTP</button>
                            <a class="btn btn-outline-secondary" href="/dashboard.php">Back</a>
                        </div>
                    </div>
                </form>
                <div class="small text-muted mt-3">
                    Transfers over 5,000,000 VND will require admin approval after OTP verification.
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
