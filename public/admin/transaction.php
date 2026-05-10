<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Csrf;
use App\Flash;
use App\Http;
use App\Util;
use App\View;
use App\Wallet;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$db = App::db();
$pdo = $db->pdo();

$stmt = $pdo->prepare(
    'SELECT t.*, u.full_name, u.email, u.phone
     FROM transactions t
     JOIN users u ON u.id = t.user_id
     WHERE t.id = ?
     LIMIT 1'
);
$stmt->execute([$id]);
$tx = $stmt->fetch();
if (!$tx) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$meta = [];
if (!empty($tx['meta_json'])) {
    $decoded = json_decode((string)$tx['meta_json'], true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
}

if (Http::isPost()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Flash::set('error', 'Invalid request');
        Http::redirect('/admin/transaction.php?id=' . $id);
    }

    $action = (string)($_POST['action'] ?? '');

    if (($tx['status'] ?? '') !== 'pending_admin') {
        Flash::set('error', 'This transaction is not pending admin approval');
        Http::redirect('/admin/transaction.php?id=' . $id);
    }

    if ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE transactions SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$id]);
        Flash::set('success', 'Transaction rejected');
        Http::redirect('/admin/approvals.php');
    }

    if ($action === 'approve') {
        $type = (string)$tx['type'];
        $amount = (int)$tx['amount'];
        $fee = (int)$tx['fee'];
        $userId = (int)$tx['user_id'];
        $relatedUserId = $tx['related_user_id'] !== null ? (int)$tx['related_user_id'] : null;

        $db->begin();
        try {
            $stmt = $pdo->prepare("SELECT status FROM transactions WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $locked = $stmt->fetch();
            if (!$locked || ($locked['status'] ?? '') !== 'pending_admin') {
                throw new RuntimeException('Not pending');
            }

            if ($type === 'withdraw') {
                $bal = Wallet::lockBalance($pdo, $userId);
                $total = $amount + $fee;
                if ($bal < $total) {
                    $stmt = $pdo->prepare("UPDATE transactions SET status = 'rejected' WHERE id = ?");
                    $stmt->execute([$id]);
                    $db->commit();
                    Flash::set('error', 'Rejected: insufficient balance');
                    Http::redirect('/admin/approvals.php');
                }
                Wallet::setBalance($pdo, $userId, $bal - $total);
                $stmt = $pdo->prepare("UPDATE transactions SET status = 'success' WHERE id = ?");
                $stmt->execute([$id]);
                $db->commit();
                Flash::set('success', 'Withdrawal approved');
                Http::redirect('/admin/approvals.php');
            }

            if ($type === 'transfer_out') {
                if (!$relatedUserId) {
                    throw new RuntimeException('Missing recipient');
                }
                $feePayer = (string)($meta['fee_payer'] ?? 'sender');
                if ($feePayer !== 'sender' && $feePayer !== 'receiver') {
                    $feePayer = 'sender';
                }

                $senderDebit = $amount + ($feePayer === 'sender' ? $fee : 0);
                $receiverCredit = $amount - ($feePayer === 'receiver' ? $fee : 0);
                if ($receiverCredit < 0) {
                    throw new RuntimeException('Invalid receiver credit');
                }

                $a = min($userId, $relatedUserId);
                $b = max($userId, $relatedUserId);
                $balA = Wallet::lockBalance($pdo, $a);
                $balB = Wallet::lockBalance($pdo, $b);

                $senderBal = ($userId === $a) ? $balA : $balB;
                $receiverBal = ($relatedUserId === $a) ? $balA : $balB;

                if ($senderBal < $senderDebit) {
                    $stmt = $pdo->prepare("UPDATE transactions SET status = 'rejected' WHERE id = ?");
                    $stmt->execute([$id]);
                    $db->commit();
                    Flash::set('error', 'Rejected: insufficient balance');
                    Http::redirect('/admin/approvals.php');
                }

                $senderBal -= $senderDebit;
                $receiverBal += $receiverCredit;

                if ($userId === $a) {
                    $balA = $senderBal;
                } else {
                    $balB = $senderBal;
                }
                if ($relatedUserId === $a) {
                    $balA = $receiverBal;
                } else {
                    $balB = $receiverBal;
                }

                Wallet::setBalance($pdo, $a, $balA);
                Wallet::setBalance($pdo, $b, $balB);

                $stmt = $pdo->prepare("UPDATE transactions SET status = 'success' WHERE id = ?");
                $stmt->execute([$id]);

                $stmt = $pdo->prepare(
                    "INSERT INTO transactions (code, type, user_id, related_user_id, amount, fee, status, note, meta_json)
                     VALUES (?, 'transfer_in', ?, ?, ?, 0, 'success', ?, ?)"
                );
                $metaIn = $meta;
                $metaIn['original_amount'] = $amount;
                $metaIn['fee'] = $fee;
                $metaIn['fee_payer'] = $feePayer;
                $stmt->execute([
                    (string)$tx['code'],
                    $relatedUserId,
                    $userId,
                    $receiverCredit,
                    (string)($tx['note'] ?? ''),
                    json_encode($metaIn, JSON_UNESCAPED_UNICODE),
                ]);

                $db->commit();

                $stmt = $pdo->prepare('SELECT email, full_name FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$relatedUserId]);
                $rcpt = $stmt->fetch();
                if ($rcpt && !empty($rcpt['email'])) {
                    $subject = 'You received money';
                    $body = "You received " . Util::money($receiverCredit) . " from " . (string)($tx['full_name'] ?? '') . ".\nYour balance is now " . Util::money(Wallet::getBalance($relatedUserId)) . ".";
                    App::mailer()->send((string)$rcpt['email'], $subject, $body);
                }

                Flash::set('success', 'Transfer approved');
                Http::redirect('/admin/approvals.php');
            }

            $db->rollBack();
            Flash::set('error', 'Unsupported pending transaction type');
            Http::redirect('/admin/transaction.php?id=' . $id);
        } catch (Throwable $e) {
            $db->rollBack();
            Flash::set('error', 'Approval failed');
            Http::redirect('/admin/transaction.php?id=' . $id);
        }
    }

    Flash::set('error', 'Invalid action');
    Http::redirect('/admin/transaction.php?id=' . $id);
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Transaction details';

ob_start();
?>
<h1 class="h3 mb-3">Transaction details</h1>

<div class="card card-body">
    <div class="row g-2">
        <div class="col-md-6"><strong>Code:</strong> <?= View::e((string)$tx['code']) ?></div>
        <div class="col-md-6"><strong>Type:</strong> <?= View::e((string)$tx['type']) ?></div>
        <div class="col-md-6"><strong>User:</strong> <?= View::e((string)$tx['full_name']) ?> (<?= View::e((string)$tx['phone']) ?>)</div>
        <div class="col-md-6"><strong>Status:</strong> <?= View::e((string)$tx['status']) ?></div>
        <div class="col-md-6"><strong>Amount:</strong> <?= View::e(Util::money((int)$tx['amount'])) ?></div>
        <div class="col-md-6"><strong>Fee:</strong> <?= View::e(Util::money((int)$tx['fee'])) ?></div>
        <div class="col-12"><strong>Note:</strong> <?= View::e((string)($tx['note'] ?? '')) ?></div>
        <div class="col-12"><strong>Created:</strong> <?= View::e((string)$tx['created_at']) ?></div>
    </div>
</div>

<?php if (($tx['status'] ?? '') === 'pending_admin'): ?>
    <div class="card card-body mt-3">
        <h2 class="h5">Admin decision</h2>
        <form method="post" class="d-flex gap-2">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <button class="btn btn-success" name="action" value="approve" onclick="return confirm('Approve this transaction?')">Approve</button>
            <button class="btn btn-danger" name="action" value="reject" onclick="return confirm('Reject this transaction?')">Reject</button>
        </form>
    </div>
<?php endif; ?>

<?php if (!empty($meta)): ?>
    <div class="card card-body mt-3">
        <h2 class="h5">Meta</h2>
        <pre class="mb-0"><?= View::e(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../templates/layout.php';
