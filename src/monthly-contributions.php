<?php
declare(strict_types=1);

function monthly_contribution_period_valid(string $period): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period);
}

function monthly_contribution_guess_mapping(array $headers): array
{
    $normalized = array_map('import_normalize_header', $headers);
    $aliases = import_aliases();
    $map = [];
    foreach (['membership_no', 'amount'] as $field) {
        $candidates = array_merge([$field], $aliases[$field] ?? []);
        $found = '';
        foreach ($candidates as $candidate) {
            $index = array_search($candidate, $normalized, true);
            if ($index !== false) {
                $found = (string) $index;
                break;
            }
        }
        if ($found === '') {
            foreach ($normalized as $index => $header) {
                foreach ($candidates as $candidate) {
                    if ($header !== '' && strpos($header, $candidate) !== false) {
                        $found = (string) $index;
                        break 2;
                    }
                }
            }
        }
        $map[$field] = $found;
    }
    return $map;
}

function monthly_contribution_mapping_valid(array $map, array $headers): bool
{
    foreach (['membership_no', 'amount'] as $field) {
        $index = (string) ($map[$field] ?? '');
        if ($index === '' || !ctype_digit($index) || !array_key_exists((int) $index, $headers)) {
            return false;
        }
    }
    return true;
}

function monthly_contribution_map_rows(array $map, array $rawRows): array
{
    $rows = [];
    $numberIndex = $map['membership_no'] ?? '';
    $amountIndex = $map['amount'] ?? '';
    foreach ($rawRows as $i => $raw) {
        $rows[] = [
            '_line' => $i + 2,
            'membership_no' => $numberIndex !== '' ? trim((string) ($raw[(int) $numberIndex] ?? '')) : '',
            'amount' => $amountIndex !== '' ? trim((string) ($raw[(int) $amountIndex] ?? '')) : '',
        ];
    }
    return $rows;
}

function monthly_contribution_already_posted(int $memberId, string $period): bool
{
    return fetch_value(
        'SELECT 1 FROM monthly_contribution_items WHERE member_id = ? AND period = ? LIMIT 1',
        [$memberId, $period]
    ) !== false;
}

function monthly_contribution_validate_rows(array $rows, string $period): array
{
    $report = [
        'rows' => [],
        'total' => count($rows),
        'imported' => 0,
        'posted' => 0,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
    ];
    $seen = [];
    foreach ($rows as $i => $row) {
        $line = (int) ($row['_line'] ?? ($i + 2));
        $membershipNo = trim((string) ($row['membership_no'] ?? ''));
        $amount = to_amount($row['amount'] ?? 0);
        $status = 'posted';
        $error = '';
        $member = null;

        if ($membershipNo === '') {
            $status = 'failed';
            $error = 'Membership no is required.';
        } elseif ($amount <= 0) {
            $status = 'failed';
            $error = 'Amount must be greater than zero.';
        } elseif (isset($seen[$membershipNo])) {
            $status = 'skipped';
            $error = 'Duplicate membership no in this file.';
        } else {
            $seen[$membershipNo] = true;
            $member = find_member_by_membership_no($membershipNo);
            if ($member === null) {
                $status = 'failed';
                $error = 'No member found for this membership no.';
            } elseif (monthly_contribution_already_posted((int) $member['id'], $period)) {
                $status = 'skipped';
                $error = 'Monthly contribution already posted for this period.';
            }
        }

        if ($status === 'posted') {
            $report['imported']++;
            $report['posted']++;
        } elseif ($status === 'skipped') {
            $report['skipped']++;
        } else {
            $report['failed']++;
        }
        if ($error !== '') {
            $report['errors'][] = ['row' => $line, 'message' => $error];
        }
        $report['rows'][] = [
            '_line' => $line,
            'membership_no' => $membershipNo,
            'amount' => $amount,
            'status' => $status,
            'error' => $error,
            'member' => $member === null ? null : [
                'id' => (int) $member['id'],
                'name' => trim((string) ($member['first_name'] ?? '') . ' ' . (string) ($member['last_name'] ?? '')),
            ],
        ];
    }
    return $report;
}

function monthly_contribution_next_batch_no(): string
{
    do {
        $batchNo = 'MC-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    } while (fetch_value('SELECT 1 FROM monthly_contribution_batches WHERE batch_no = ?', [$batchNo]) !== false);
    return $batchNo;
}

function monthly_contribution_import(array $rows, string $period, string $filename, int $uploadedBy): array
{
    if (!monthly_contribution_period_valid($period)) {
        throw new RuntimeException('Please choose a valid contribution month.');
    }

    $validation = monthly_contribution_validate_rows($rows, $period);
    $batchNo = monthly_contribution_next_batch_no();
    $batchId = db_insert('monthly_contribution_batches', [
        'batch_no' => $batchNo,
        'period' => $period,
        'filename' => $filename,
        'total_rows' => $validation['total'],
        'posted' => 0,
        'skipped' => $validation['skipped'],
        'failed' => $validation['failed'],
        'uploaded_by' => $uploadedBy > 0 ? $uploadedBy : null,
    ]);

    $posted = 0;
    $failed = $validation['failed'];
    $errors = $validation['errors'];
    $paidAt = $period . '-01 00:00:00';

    foreach ($validation['rows'] as $row) {
        if ($row['status'] !== 'posted') {
            continue;
        }
        try {
            if (monthly_contribution_already_posted((int) $row['member']['id'], $period)) {
                throw new RuntimeException('Monthly contribution already posted for this period.');
            }
            $result = record_savings_contribution((int) $row['member']['id'], 'monthly', (float) $row['amount'], [
                'method' => 'cash',
                'reference' => $batchNo,
                'narration' => 'Monthly contribution ' . $period,
                'paid_at' => $paidAt,
                'recorded_by' => $uploadedBy > 0 ? $uploadedBy : null,
            ]);
            db_insert('monthly_contribution_items', [
                'batch_id' => $batchId,
                'member_id' => (int) $row['member']['id'],
                'membership_no' => $row['membership_no'],
                'period' => $period,
                'amount' => (float) $row['amount'],
                'status' => 'posted',
                'receipt_no' => $result['receipt_no'],
                'payment_id' => (int) $result['payment_id'],
            ]);
            $posted++;
            safe_notify_member((int) $row['member']['id'], 'savings_contribution', [
                'amount' => money((float) $row['amount']),
                'receipt_no' => $result['receipt_no'],
            ]);
        } catch (Throwable $e) {
            $failed++;
            $errors[] = ['row' => (int) $row['_line'], 'message' => $e->getMessage()];
        }
    }

    db_update('monthly_contribution_batches', $batchId, [
        'posted' => $posted,
        'skipped' => $validation['skipped'],
        'failed' => $failed,
    ]);

    return [
        'total' => $validation['total'],
        'imported' => $posted,
        'posted' => $posted,
        'skipped' => $validation['skipped'],
        'failed' => $failed,
        'errors' => $errors,
        'batch_no' => $batchNo,
        'batch_id' => $batchId,
    ];
}

function list_monthly_contribution_batches(int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    return fetch_all(
        'SELECT b.*, u.full_name AS uploaded_by_name
         FROM monthly_contribution_batches b
         LEFT JOIN users u ON u.id = b.uploaded_by
         ORDER BY b.id DESC LIMIT ' . $limit
    );
}

function get_monthly_contribution_batch(int $batchId): ?array
{
    return fetch_one(
        'SELECT b.*, u.full_name AS uploaded_by_name
         FROM monthly_contribution_batches b
         LEFT JOIN users u ON u.id = b.uploaded_by
         WHERE b.id = ?',
        [$batchId]
    );
}

function list_monthly_contribution_items(int $batchId): array
{
    return fetch_all(
        'SELECT * FROM monthly_contribution_items WHERE batch_id = ? ORDER BY id',
        [$batchId]
    );
}
