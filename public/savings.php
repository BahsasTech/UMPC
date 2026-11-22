<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login(['member']);
$member = member_for_user((int) $user['id']);
$memberId = (int) $member['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    try {
        if ($action === 'contribute') {
            $type = (string) post('type', '');
            $amount = to_amount(post('amount', 0));
            submit_savings_contribution_request($memberId, $type, $amount, [
                'method'    => (string) post('method', 'bank_transfer'),
                'reference' => (string) post('reference', ''),
                'narration' => 'Member contribution to ' . (SAVINGS_PRODUCTS[$type] ?? $type),
            ], (int) $user['id']);
            flash('success', 'Contribution submitted for verification.');
        } elseif ($action === 'target') {
            $target = to_amount(post('target', 0));
            submit_savings_target_request($memberId, (string) post('type', ''), $target, (string) post('reason', ''), (int) $user['id']);
            flash('success', $target > 0 ? 'Target change request submitted.' : 'Target reset request submitted.');
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/savings.php');
}

$summary = savings_summary($memberId);
$statement = savings_statement($memberId, null, 50);
$requests = list_savings_requests(['member_id' => $memberId]);
$reviewer = assigned_reviewer_name($memberId);

render_header('My Savings', $user);
?>
<div class="row g-3 mb-4">
    <?php foreach (SAVINGS_PRODUCTS as $type => $name): $acc = $summary['accounts'][$type] ?? null;
        $balance = (float) ($acc['balance'] ?? 0);
        $target = (float) ($acc['target_amount'] ?? 0);
        $pct = $target > 0 ? min(100, round($balance / $target * 100)) : 0; ?>
        <div class="col-md-6 col-xl-3">
            <div class="card h-100 p-3">
                <div class="text-muted small"><?= e($name) ?></div>
                <div class="h5"><?= money($balance) ?></div>
                <?php if ($target > 0): ?>
                    <div class="progress" style="height:6px"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div>
                    <div class="small text-muted mt-1"><?= $pct ?>% of <?= money($target) ?></div>
                <?php else: ?>
                    <div class="small text-muted">No target set</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Make a contribution</h5>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="contribute">
                <div class="mb-3">
                    <label class="form-label">Savings product</label>
                    <select class="form-select" name="type" required>
                        <?php foreach (SAVINGS_PRODUCTS as $type => $name): ?>
                            <option value="<?= $type ?>"><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" min="1" class="form-control" name="amount" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Payment method</label>
                    <select class="form-select" name="method">
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="paystack">Paystack</option>
                        <option value="remita">Remita</option>
                        <option value="cash">Cash</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Reference / teller no.</label>
                    <input class="form-control" name="reference">
                </div>
                <button class="btn btn-primary w-100"><i class="bi bi-send"></i> Submit for verification</button>
            </form>
        </div>

        <div class="card p-4">
            <h5 class="mb-3">Savings target request</h5>
            <div class="alert alert-info small">Requests are reviewed by <strong><?= e($reviewer) ?></strong>.</div>
            <form method="post" class="mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="target">
                <div class="mb-3">
                    <label class="form-label">Savings product</label>
                    <select class="form-select" name="type" required>
                        <?php foreach (SAVINGS_PRODUCTS as $type => $name): ?>
                            <option value="<?= $type ?>"><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">New target amount</label>
                    <input type="number" step="0.01" min="1" class="form-control" name="target" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Reason</label>
                    <input class="form-control" name="reason" maxlength="255" placeholder="Optional">
                </div>
                <button class="btn btn-outline-primary w-100"><i class="bi bi-bullseye"></i> Request target change</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="target">
                <input type="hidden" name="target" value="0">
                <input type="hidden" name="reason" value="Member requested target reset">
                <div class="mb-2">
                    <label class="form-label">Product to reset</label>
                    <select class="form-select" name="type" required>
                        <?php foreach (SAVINGS_PRODUCTS as $type => $name): ?>
                            <option value="<?= $type ?>"><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-outline-danger w-100"><i class="bi bi-arrow-counterclockwise"></i> Request target reset</button>
            </form>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">My requests</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Request</th><th>Details</th><th>Status</th><th>Remarks</th></tr></thead>
                    <tbody>
                    <?php if ($requests === []): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No requests yet.</td></tr>
                    <?php else: foreach ($requests as $r): ?>
                        <tr>
                            <td><?= e(fmt_date($r['requested_at'])) ?></td>
                            <td><?= $r['type'] === 'contribution' ? 'Contribution' : 'Target' ?><br><span class="text-muted small"><?= e(label((string) $r['product'])) ?></span></td>
                            <td><?= $r['type'] === 'contribution' ? money((float) $r['amount']) : ($r['requested_target'] > 0 ? money((float) $r['requested_target']) : 'Reset to none') ?></td>
                            <td><?= status_badge((string) $r['status']) ?></td>
                            <td class="small text-muted"><?= e($r['review_remarks'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card p-4">
            <h5 class="mb-3">Savings statement</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Receipt</th><th>Product</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    <?php if ($statement === []): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No contributions yet.</td></tr>
                    <?php else: foreach ($statement as $row): ?>
                        <tr>
                            <td><?= e(fmt_date($row['paid_at'])) ?></td>
                            <td><?= e($row['receipt_no']) ?></td>
                            <td><?= e(label((string) $row['product'])) ?></td>
                            <td><?= e(label($row['method'])) ?></td>
                            <td class="text-end"><?= money($row['amount']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
