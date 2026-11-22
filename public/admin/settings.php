<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();
require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    try {
        if ($action === 'general') {
            $name = trim((string) post('org_name', ''));
            $short = trim((string) post('short_name', ''));
            $currency = trim((string) post('currency_symbol', ''));
            $timezone = (string) post('timezone', 'Africa/Lagos');
            $maxMultiple = (float) post('loan_max_multiple', 3);
            $rate = (float) post('loan_interest_rate', 5);
            $guarantors = (int) post('loan_min_guarantors', 2);

            if ($name === '' || $short === '' || $currency === '') {
                throw new RuntimeException('Organisation name, short name and currency symbol are required.');
            }
            if (strlen($name) > 190 || strlen($short) > 60 || strlen($currency) > 10) {
                throw new RuntimeException('One or more text values are too long.');
            }
            if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new RuntimeException('Please choose a valid timezone.');
            }
            if ($maxMultiple <= 0 || $maxMultiple > 100 || $rate < 0 || $rate > 100 || $guarantors < 0 || $guarantors > 20) {
                throw new RuntimeException('Loan policy values are outside the allowed range.');
            }

            foreach ([
                'app.name'           => $name,
                'app.short_name'     => $short,
                'app.currency'       => $currency,
                'app.timezone'       => $timezone,
                'loan_max_multiple'  => (string) $maxMultiple,
                'loan_interest_rate' => (string) $rate,
                'loan_min_guarantors'=> (string) $guarantors,
            ] as $key => $value) {
                setting_set($key, $value);
            }
            flash('success', 'System settings saved.');
        } elseif ($action === 'templates') {
            foreach (notification_templates() as $event => $template) {
                notification_template_save($event, [
                    'email_subject' => (string) post('template_' . $event . '_subject', $template['email_subject']),
                    'email_body'    => (string) post('template_' . $event . '_body', $template['email_body']),
                    'sms'           => (string) post('template_' . $event . '_sms', $template['sms']),
                ]);
            }
            flash('success', 'Notification templates saved.');
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/settings.php');
}

$loan = loan_settings();
$timezones = DateTimeZone::listIdentifiers();
$templates = notification_templates();

render_header('System Settings', $user);
?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card p-4 h-100">
            <h5 class="mb-3"><i class="bi bi-building"></i> Organisation &amp; display</h5>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="general">
                <div class="mb-3">
                    <label class="form-label">Organisation name</label>
                    <input class="form-control" name="org_name" value="<?= e(app_name()) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Short name</label>
                    <input class="form-control" name="short_name" value="<?= e(app_short_name()) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Currency symbol</label>
                    <input class="form-control" name="currency_symbol" value="<?= e(app_currency()) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Timezone</label>
                    <select class="form-select" name="timezone">
                        <?php foreach ($timezones as $timezone): ?>
                            <option value="<?= e($timezone) ?>" <?= app_timezone() === $timezone ? 'selected' : '' ?>><?= e($timezone) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <hr>
                <h6 class="mb-3"><i class="bi bi-cash-coin"></i> Loan policy</h6>
                <div class="mb-3">
                    <label class="form-label">Maximum savings multiple</label>
                    <input type="number" step="0.1" min="0.1" max="100" class="form-control" name="loan_max_multiple" value="<?= e((string) $loan['max_multiple']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Default interest rate (%)</label>
                    <input type="number" step="0.1" min="0" max="100" class="form-control" name="loan_interest_rate" value="<?= e((string) $loan['default_rate']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Minimum guarantors</label>
                    <input type="number" min="0" max="20" class="form-control" name="loan_min_guarantors" value="<?= e((string) $loan['min_guarantors']) ?>">
                </div>
                <button class="btn btn-primary"><i class="bi bi-save"></i> Save system settings</button>
            </form>
            <div class="alert alert-info small mt-3 mb-0">
                Infrastructure values (database, Supabase, session and cron secrets) stay in environment variables and are not editable here.
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="bi bi-chat-left-text"></i> Notification templates</h5>
                <span class="text-muted small">Leave a field blank to keep the built-in wording.</span>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="templates">
                <?php foreach ($templates as $event => $template): ?>
                    <details class="border rounded p-3 mb-2">
                        <summary class="fw-semibold"><?= e(label($event)) ?></summary>
                        <div class="mt-3">
                            <div class="mb-2">
                                <label class="form-label">Email subject</label>
                                <input class="form-control" name="template_<?= e($event) ?>_subject" value="<?= e($template['email_subject']) ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Email body</label>
                                <textarea class="form-control" rows="4" name="template_<?= e($event) ?>_body"><?= e($template['email_body']) ?></textarea>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">SMS</label>
                                <textarea class="form-control" rows="2" name="template_<?= e($event) ?>_sms"><?= e($template['sms']) ?></textarea>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
                <button class="btn btn-primary mt-2"><i class="bi bi-save"></i> Save templates</button>
            </form>
        </div>
    </div>
</div>
