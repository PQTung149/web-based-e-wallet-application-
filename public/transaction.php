<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

use App\App;
use App\Auth;
use App\Flash;
use App\Http;
use App\Util;
use App\View;

Auth::requireLogin();

if (($currentUser['role'] ?? '') !== 'user') {
    Http::redirect('/');
}

if (($currentUser['status'] ?? '') !== 'verified') {
    Flash::set('error', 'This feature is only available for verified accounts.');
    Http::redirect('/dashboard.php');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$pdo = App::db()->pdo();
$stmt = $pdo->prepare(
    'SELECT t.*, u2.full_name AS related_name, u2.phone AS related_phone
     FROM transactions t
     LEFT JOIN users u2 ON u2.id = t.related_user_id
     WHERE t.id = ? AND t.user_id = ?
     LIMIT 1'
);
$stmt->execute([$id, (int)$currentUser['id']]);
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

$fee = (int)$tx['fee'];
if ($fee === 0 && isset($meta['fee'])) {
    $fee = (int)$meta['fee'];
}

$flashSuccess = Flash::get('success');
$flashError = Flash::get('error');
$title = 'Transaction';

ob_start();
?>
<h1 class="h3 mb-3">Transaction details</h1>

<div class="card card-body">
    <div class="row g-2">
        <div class="col-md-6"><strong>Code:</strong> <?= View::e((string)$tx['code']) ?></div>
        <div class="col-md-6"><strong>Type:</strong> <?= View::e((string)$tx['type']) ?></div>
        <div class="col-md-6"><strong>Status:</strong> <?= View::e((string)$tx['status']) ?></div>
        <div class="col-md-6"><strong>Time:</strong> <?= View::e((string)$tx['created_at']) ?></div>
        <div class="col-md-6"><strong>Amount:</strong> <?= View::e(Util::money((int)$tx['amount'])) ?></div>
        <div class="col-md-6"><strong>Fee:</strong> <?= View::e(Util::money($fee)) ?></div>
        <?php if (!empty($tx['related_user_id'])): ?>
            <div class="col-12">
                <strong>Related account:</strong>
                <?= View::e((string)($tx['related_name'] ?? '')) ?> (<?= View::e((string)($tx['related_phone'] ?? '')) ?>)
            </div>
        <?php endif; ?>
        <?php if (!empty($tx['note'])): ?>
            <div class="col-12"><strong>Note:</strong> <?= View::e((string)$tx['note']) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if (($tx['type'] ?? '') === 'phone_card' && !empty($meta['codes']) && is_array($meta['codes'])): ?>
    <div class="card card-body mt-3">
        <h2 class="h5">Phone card codes</h2>
        <ul class="mb-0">
            <?php foreach ($meta['codes'] as $c): ?>
                <li><?= View::e((string)$c) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($meta)): ?>
    <div class="card card-body mt-3">
        <h2 class="h5">Details</h2>
        <pre class="mb-0"><?= View::e(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
<?php endif; ?>

<div class="mt-3">
    <a class="btn btn-outline-secondary" href="/transactions.php">Back</a>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
