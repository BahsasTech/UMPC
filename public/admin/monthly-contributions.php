<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_login(['admin']);

if (get('template') === '1') {
    csv_output('monthly-contributions-template.csv', ['Membership no', 'Amount'], [['UMP-000001', '5000.00']]);
}

$period = date('Y-m');
$token = '';
$name = '';
$headers = [];
$map = ['membership_no' => '', 'amount' => ''];
$preview = null;
$report = null;
$batch = null;
$items = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    $period = (string) post('period', date('Y-m'));

    if (!monthly_contribution_period_valid($period)) {
        flash('danger', 'Please choose a valid contribution month.');
        redirect('/admin/monthly-contributions.php');
    }

    try {
        if ($action === 'upload') {
            if (!isset($_FILES['file'])) {
                throw new RuntimeException('Choose a CSV file to upload.');
            }
            $token = import_save_upload($_FILES['file']);
            $name = (string) ($_FILES['file']['name'] ?? '');
            flash('success', 'File uploaded. Review the columns, then post the contributions.');
            redirect('/admin/monthly-contributions.php?token=' . urlencode($token) . '&name=' . urlencode($name) . '&period=' . urlencode($period));
        }

        if (in_array($action, ['preview', 'import'], true)) {
            $token = (string) post('token', '');
            $name = (string) post('name', '');
            $parsed = import_parse_csv(import_token_path($token));
            $headers = $parsed['headers'];
            $postedMap = $_POST['map'] ?? [];
            $map = [
                'membership_no' => (string) ($postedMap['membership_no'] ?? ''),
                'amount' => (string) ($postedMap['amount'] ?? ''),
            ];
            if (!monthly_contribution_mapping_valid($map, $headers)) {
                throw new RuntimeException('Select a valid CSV column for Membership no and Amount.');
            }
            $rows = monthly_contribution_map_rows($map, $parsed['rows']);

            if ($action === 'import') {
                $report = monthly_contribution_import($rows, $period, $name, (int) ($user['id'] ?? 0));
                import_delete_token($token);
                $_SESSION['monthly_contribution_report'] = $report;
                flash('success', 'Monthly contributions posted: ' . $report['posted'] . ' posted, ' . $report['skipped'] . ' skipped, ' . $report['failed'] . ' failed.');
                redirect('/admin/monthly-contributions.php?done=1&batch=' . $report['batch_id']);
            }

            $preview = monthly_contribution_validate_rows($rows, $period);
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
} elseif (get('token') !== null) {
    $token = (string) get('token', '');
    $name = (string) get('name', '');
    $period = (string) get('period', date('Y-m'));
    try {
        $parsed = import_parse_csv(import_token_path($token));
        $headers = $parsed['headers'];
        $map = monthly_contribution_guess_mapping($headers);
        $preview = monthly_contribution_validate_rows(monthly_contribution_map_rows($map, $parsed['rows']), $period);
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
        $token = '';
    }
} elseif (get('done') === '1') {
    $report = $_SESSION['monthly_contribution_report'] ?? null;
    unset($_SESSION['monthly_contribution_report']);
}

if (get('batch') !== null) {
    $batch = get_monthly_contribution_batch((int) get('batch', 0));
    if ($batch !== null) {
        $items = list_monthly_contribution_items((int) $batch['id']);
    }
}

$batches = list_monthly_contribution_batches();
$columnOptions = '<option value="">Select column…</option>';
foreach ($headers as $index => $header) {
    $columnOptions .= '<option value="' . (int) $index . '" ' . ((string) ($map['membership_no'] ?? '') === (string) $index ? 'selected' : '') . '>' . e((string) $header) . '</option>';
}
$amountOptions = '<option value="">Select column…</option>';
foreach ($headers as $index => $header) {
    $amountOptions .= '<option value="' . (int) $index . '" ' . ((string) ($map['amount'] ?? '') === (string) $index ? 'selected' : '') . '>' . e((string) $header) . '</option>';
}

render_header('Monthly Contribution Upload', $user, [
    'actions' => '<a class="btn btn-outline-secondary btn-sm" href="/admin/payments.php"><i class="bi bi-arrow-left"></i> Payments</a>',
]);
?>

<?php if ($report !== null): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3">Upload complete</h5>
        <div class="row g-3">
            <div class="col-md-3"><span class="text-muted small">Batch</span><div class="fw-semibold"><?= e($report['batch_no'] ?? '') ?></div></div>
            <div class="col-md-3"><span class="text-muted small">Rows</span><div class="fw-semibold"><?= (int) $report['total'] ?></div></div>
            <div class="col-md-2"><span class="text-muted small">Posted</span><div class="fw-semibold text-success"><?= (int) $report['posted'] ?></div></div>
            <div class="col-md-2"><span class="text-muted small">Skipped</span><div class="fw-semibold text-warning"><?= (int) $report['skipped'] ?></div></div>
            <div class="col-md-2"><span class="text-muted small">Failed</span><div class="fw-semibold text-danger"><?= (int) $report['failed'] ?></div></div>
        </div>
        <?php if (($report['errors'] ?? []) !== []): ?>
            <div class="alert alert-warning mt-3 mb-0">
                <div class="fw-semibold mb-1">Rows needing attention</div>
                <ul class="mb-0 small">
                    <?php foreach (array_slice($report['errors'], 0, 20) as $error): ?>
                        <li>Row <?= (int) $error['row'] ?>: <?= e($error['message']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($token !== ''): ?>
    <div class="card p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h5 class="mb-1">Review upload</h5>
                <div class="text-muted small"><?= e($name) ?> · Period <?= e($period) ?></div>
            </div>
            <a class="btn btn-outline-secondary btn-sm" href="/admin/monthly-contributions.php">Cancel</a>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="name" value="<?= e($name) ?>">
            <input type="hidden" name="period" value="<?= e($period) ?>">
            <div class="row g-3 mb-3">
                <div class="col-md-4"><label class="form-label">Contribution month</label><input type="month" class="form-control" value="<?= e($period) ?>" disabled></div>
                <div class="col-md-4"><label class="form-label">Membership no column</label><select class="form-select" name="map[membership_no]"><?= $columnOptions ?></select></div>
                <div class="col-md-4"><label class="form-label">Amount column</label><select class="form-select" name="map[amount]"><?= $amountOptions ?></select></div>
            </div>
            <?php if ($preview !== null): ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Row</th><th>Membership no</th><th>Member</th><th class="text-end">Amount</th><th>Status</th><th>Remark</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($preview['rows'], 0, 30) as $row): ?>
                            <tr>
                                <td><?= (int) $row['_line'] ?></td>
                                <td><?= e($row['membership_no']) ?></td>
                                <td><?= e($row['member']['name'] ?? '—') ?></td>
                                <td class="text-end"><?= money((float) $row['amount']) ?></td>
                                <td><?= e(ucfirst($row['status'])) ?></td>
                                <td class="small text-muted"><?= e($row['error']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted mb-3">
                    <?= (int) $preview['imported'] ?> ready · <?= (int) $preview['skipped'] ?> skipped · <?= (int) $preview['failed'] ?> failed
                    <?php if (count($preview['rows']) > 30): ?>(showing first 30 rows)<?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-outline-primary" name="action" value="preview"><i class="bi bi-eye"></i> Refresh preview</button>
                <button class="btn btn-success" name="action" value="import" data-confirm="Post these monthly contributions now?"><i class="bi bi-check2-circle"></i> Post contributions</button>
            </div>
        </form>
    </div>
<?php else: ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-5">
            <div class="card p-4 h-100">
                <h5 class="mb-3">Upload monthly contributions</h5>
                <p class="text-muted small">CSV needs two columns: <strong>Membership no</strong> and <strong>Amount</strong>. Each member can be posted once per month.</p>
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
                    <div class="mb-3"><label class="form-label">Contribution month</label><input type="month" class="form-control" name="period" value="<?= e($period) ?>" required></div>
                    <div class="mb-3"><label class="form-label">CSV file</label><input type="file" class="form-control" name="file" accept=".csv,.txt" required></div>
                    <button class="btn btn-primary w-100"><i class="bi bi-upload"></i> Upload for review</button>
                </form>
                <hr>
                <a class="btn btn-outline-secondary btn-sm" href="/admin/monthly-contributions.php?template=1"><i class="bi bi-download"></i> Download template</a>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card p-4 h-100">
                <h5 class="mb-3">How it works</h5>
                <ol class="small mb-0">
                    <li>Choose the month the contributions belong to.</li>
                    <li>Upload a CSV with membership numbers and amounts.</li>
                    <li>Confirm the detected columns and review the preview.</li>
                    <li>Post the batch. Successful rows create receipts, update savings balances, and post ledger entries.</li>
                </ol>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($batch !== null): ?>
    <div class="card p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h5 class="mb-1">Batch <?= e($batch['batch_no']) ?></h5>
                <div class="text-muted small">Period <?= e($batch['period']) ?> · <?= e($batch['filename'] ?? '') ?></div>
            </div>
            <a class="btn btn-outline-secondary btn-sm" href="/admin/monthly-contributions.php">Close</a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Membership no</th><th class="text-end">Amount</th><th>Receipt</th><th>Posted</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['membership_no']) ?></td>
                        <td class="text-end"><?= money((float) $item['amount']) ?></td>
                        <td><?= e($item['receipt_no']) ?></td>
                        <td><?= e(fmt_date($item['created_at'], 'Y-m-d H:i')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card p-4">
    <h5 class="mb-3">Previous uploads</h5>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Batch</th><th>Period</th><th>File</th><th class="text-end">Posted</th><th class="text-end">Skipped</th><th class="text-end">Failed</th><th>Uploaded by</th><th></th></tr></thead>
            <tbody>
            <?php if ($batches === []): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No monthly contribution uploads yet.</td></tr>
            <?php else: foreach ($batches as $row): ?>
                <tr>
                    <td><?= e($row['batch_no']) ?></td>
                    <td><?= e($row['period']) ?></td>
                    <td><?= e($row['filename'] ?? '') ?></td>
                    <td class="text-end"><?= (int) $row['posted'] ?></td>
                    <td class="text-end"><?= (int) $row['skipped'] ?></td>
                    <td class="text-end"><?= (int) $row['failed'] ?></td>
                    <td><?= e($row['uploaded_by_name'] ?? '') ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/monthly-contributions.php?batch=<?= (int) $row['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
