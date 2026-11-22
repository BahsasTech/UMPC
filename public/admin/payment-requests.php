<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) post('action', ''), ['approve', 'reject'], true)) {
    require_csrf();
    try {
        $id = (int) post('id', 0);
        $remarks = (string) post('remarks', '');
        if (post('action') === 'approve') {
            $result = approve_payment_request($id, (int) $user['id'], $remarks);
            flash('success', 'Payment approved and posted. Receipt ' . ($result['receipt_no'] ?? '—') . '.');
        } else {
            reject_payment_request($id, (int) $user['id'], $remarks);
            flash('success', 'Payment rejected.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/payment-requests.php');
}

$filters = [
    'status' => (string) get('status', 'pending'),
    'type'   => (string) get('type', ''),
    'search' => trim((string) get('search', '')),
];
if (!in_array($filters['status'], ['pending', 'approved', 'rejected', ''], true)) {
    $filters['status'] = 'pending';
}
$requests = list_payment_requests($filters);
$pendingCount = count(list_payment_requests(['status' => 'pending', 'limit' => 500]));

render_header('Payment Approvals', $user, [
    'actions' => '<a class="btn btn-outline-primary btn-sm" href="/admin/transactions.php"><i class="bi bi-credit-card"></i> Online payments</a>',
]);
?>
<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Member payment verification queue</h5>
        <span class="badge text-bg-warning"><?= $pendingCount ?> pending</span>
    </div>
    <form method="get" class="row g-2 align-items-end mb-3">
        <div class="col-md-4">
            <label class="form-label small mb-0">Status</label>
            <select class="form-select form-select-sm" name="status">
                <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', '' => 'All'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-0">Type</label>
            <select class="form-select form-select-sm" name="type">
                <option value="">All types</option>
                <?php foreach (PAYMENT_REQUEST_TYPES as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0">Search</label>
            <input class="form-control form-control-sm" name="search" value="<?= e($filters['search']) ?>" placeholder="Member / reference">
        </div>
        <div class="col-md-1 d-grid">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel"></i></button>
        </div>
    </form>
    <div class="alert alert-info small">Member payments only affect balances, statements and dashboards after approval here. Counter payments recorded by staff are posted immediately.</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr><th>Submitted</th><th>Member</th><th>Type</th><th>Details</th><th class="text-end">Amount</th><th>Status</th><th style="width: 300px;">Review</th></tr>
            </thead>
            <tbody>
            <?php if ($requests === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No payment requests found.</td></tr>
            <?php else: foreach ($requests as $r): ?>
                <tr>
                    <td class="small text-muted"><?= e(fmt_date($r['requested_at'])) ?><br><?= e($r['requested_by_name']) ?></td>
                    <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?><br><span class="text-muted small"><?= e($r['membership_no']) ?></span></td>
                    <td><?= e(PAYMENT_REQUEST_TYPES[$r['type']] ?? label($r['type'])) ?></td>
                    <td class="small">
                        <?= e($r['loan_no'] ?? ($r['product'] ? label($r['product']) : '—')) ?><br>
                        <span class="text-muted"><?= e(label($r['method'])) ?> · <?= e($r['reference'] ?: ($r['transaction_reference'] ?: '—')) ?></span>
                        <?php if ($r['status'] !== 'pending'): ?>
                            <br><span class="text-muted">Receipt: <?= e($r['receipt_no'] ?: '—') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= money($r['amount']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                            <form method="post" class="d-flex gap-1">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <input class="form-control form-control-sm" name="remarks" maxlength="255" placeholder="Remarks">
                                <button class="btn btn-sm btn-success" name="action" value="approve"><i class="bi bi-check-lg"></i> Approve</button>
                                <button class="btn btn-sm btn-outline-danger" name="action" value="reject" data-confirm="Reject this payment?">Reject</button>
                            </form>
                        <?php else: ?>
                            <span class="small text-muted"><?= e($r['reviewed_by_name'] ?? '—') ?><?= $r['review_remarks'] ? ' · ' . e($r['review_remarks']) : '' ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
