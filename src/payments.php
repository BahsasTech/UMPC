<?php
declare(strict_types=1);

/**
 * Payments and repayments: a unified view over all money received.
 */

const PAYMENT_REQUEST_TYPES = [
    'savings'        => 'Savings contribution',
    'loan_repayment' => 'Loan repayment',
    'order'          => 'Store order',
    'other'          => 'Other payment',
];

/** Record a payment, routing it to savings or loans as appropriate. */
function record_payment(int $memberId, string $type, float $amount, array $meta = []): array
{
    switch ($type) {
        case 'savings_contribution':
            return record_savings_contribution($memberId, 'monthly', $amount, $meta);
        case 'share_capital':
            return record_savings_contribution($memberId, 'share_capital', $amount, $meta);
        case 'special_savings':
            $product = $meta['product'] ?? 'special_land';
            return record_savings_contribution($memberId, $product, $amount, $meta);
        case 'loan_repayment':
            $loanId = (int) ($meta['loan_id'] ?? 0);
            if ($loanId <= 0) {
                throw new RuntimeException('Select the loan this repayment applies to.');
            }
            return record_loan_repayment($loanId, $amount, $meta);
        case 'fee':
        case 'other':
            $receipt = next_reference('payments', 'receipt_no', 'RCP-' . date('ymd') . '-', 4);
            $id = db_insert('payments', [
                'receipt_no'  => $receipt,
                'member_id'   => $memberId,
                'type'        => $type,
                'amount'      => $amount,
                'method'      => $meta['method'] ?? 'cash',
                'reference'   => $meta['reference'] ?? null,
                'status'      => 'confirmed',
                'narration'   => $meta['narration'] ?? label($type),
                'paid_at'      => $meta['paid_at'] ?? date('Y-m-d H:i:s'),
                'recorded_by' => $meta['recorded_by'] ?? ($_SESSION['user_id'] ?? null),
            ]);
            post_other_income_entry((string) ($meta['method'] ?? 'cash'), $amount, $meta['reference'] ?? null, $id);
            return ['receipt_no' => $receipt, 'payment_id' => $id];
        default:
            throw new RuntimeException('Unsupported payment type.');
    }
}

/** List payments for the admin console. */
function list_payments(array $filters = []): array
{
    $sql = 'SELECT p.*, m.membership_no, m.first_name, m.last_name,
                   s.type AS product, l.loan_no
            FROM payments p
            JOIN members m ON m.id = p.member_id
            LEFT JOIN savings_accounts s ON s.id = p.savings_account_id
            LEFT JOIN loans l ON l.id = p.loan_id
            WHERE 1 = 1';
    $params = [];
    if (!empty($filters['type'])) {
        $sql .= ' AND p.type = ?';
        $params[] = $filters['type'];
    }
    if (!empty($filters['method'])) {
        $sql .= ' AND p.method = ?';
        $params[] = $filters['method'];
    }
    if (!empty($filters['search'])) {
        $like = '%' . $filters['search'] . '%';
        $sql .= ' AND (p.receipt_no LIKE ? OR m.membership_no LIKE ? OR p.reference LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (!empty($filters['from'])) {
        $sql .= ' AND p.paid_at >= ?';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $sql .= ' AND p.paid_at <= ?';
        $params[] = $filters['to'] . ' 23:59:59';
    }
    $sql .= ' ORDER BY p.paid_at DESC, p.id DESC LIMIT 500';
    return fetch_all($sql, $params);
}

/** All payment history for a single member. */
function member_payment_history(int $memberId, int $limit = 200): array
{
    return fetch_all(
        'SELECT p.*, s.type AS product, l.loan_no
         FROM payments p
         LEFT JOIN savings_accounts s ON s.id = p.savings_account_id
         LEFT JOIN loans l ON l.id = p.loan_id
         WHERE p.member_id = ? AND p.status = "confirmed"
         ORDER BY p.paid_at DESC, p.id DESC
         LIMIT ' . (int) $limit,
        [$memberId]
    );
}

/** A single payment row. */
function get_payment(int $id): ?array
{
    return fetch_one(
        'SELECT p.*, m.membership_no, m.first_name, m.last_name, u.email,
                s.type AS product, l.loan_no
         FROM payments p
         JOIN members m ON m.id = p.member_id
         JOIN users u ON u.id = m.user_id
         LEFT JOIN savings_accounts s ON s.id = p.savings_account_id
         LEFT JOIN loans l ON l.id = p.loan_id
         WHERE p.id = ?',
        [$id]
    );
}

/** Missed instalments across the portfolio. */
function missed_payments(): array
{
    refresh_missed_installments();
    return fetch_all(
        'SELECT ls.*, l.loan_no, l.id AS loan_id, m.membership_no, m.first_name, m.last_name
         FROM loan_schedule ls
         JOIN loans l ON l.id = ls.loan_id
         JOIN members m ON m.id = l.member_id
         WHERE ls.status = "missed"
         ORDER BY ls.due_date ASC'
    );
}

/** Queue a member payment for staff approval. */
function submit_payment_request(array $data): int
{
    $type = (string) ($data['type'] ?? '');
    if (!isset(PAYMENT_REQUEST_TYPES[$type])) {
        throw new RuntimeException('Unsupported payment type.');
    }
    $amount = to_amount($data['amount'] ?? 0);
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }
    $memberId = (int) ($data['member_id'] ?? 0);
    if ($memberId <= 0) {
        throw new RuntimeException('A member is required for this payment.');
    }
    $requestedBy = (int) ($data['requested_by'] ?? ($_SESSION['user_id'] ?? 0));
    if ($requestedBy <= 0) {
        throw new RuntimeException('The member account could not be identified.');
    }
    $transactionReference = trim((string) ($data['transaction_reference'] ?? '')) ?: null;
    if ($transactionReference !== null) {
        $existing = fetch_one('SELECT id FROM payment_requests WHERE transaction_reference = ?', [$transactionReference]);
        if ($existing) {
            return (int) $existing['id'];
        }
    }

    return db_insert('payment_requests', [
        'member_id'            => $memberId,
        'type'                 => $type,
        'related_id'           => !empty($data['related_id']) ? (int) $data['related_id'] : null,
        'amount'               => $amount,
        'method'               => (string) ($data['method'] ?? 'cash'),
        'reference'            => trim((string) ($data['reference'] ?? '')) ?: null,
        'narration'            => trim((string) ($data['narration'] ?? '')) ?: null,
        'payload'              => !empty($data['payload']) ? json_encode($data['payload']) : null,
        'status'               => 'pending',
        'transaction_reference'=> $transactionReference,
        'requested_by'         => $requestedBy,
    ]);
}

/** Payment requests awaiting or completed with staff review. */
function list_payment_requests(array $filters = []): array
{
    $sql = 'SELECT pr.*, m.membership_no, m.first_name, m.last_name,
                   u.full_name AS requested_by_name, ru.full_name AS reviewed_by_name,
                   l.loan_no, s.type AS product
            FROM payment_requests pr
            JOIN members m ON m.id = pr.member_id
            JOIN users u ON u.id = pr.requested_by
            LEFT JOIN users ru ON ru.id = pr.reviewed_by
            LEFT JOIN loans l ON pr.type = \'loan_repayment\' AND l.id = pr.related_id
            LEFT JOIN savings_accounts s ON pr.type = \'savings\' AND s.id = pr.related_id
            WHERE 1 = 1';
    $params = [];
    if (!empty($filters['status'])) {
        $sql .= ' AND pr.status = ?';
        $params[] = (string) $filters['status'];
    }
    if (!empty($filters['type'])) {
        $sql .= ' AND pr.type = ?';
        $params[] = (string) $filters['type'];
    }
    if (!empty($filters['member_id'])) {
        $sql .= ' AND pr.member_id = ?';
        $params[] = (int) $filters['member_id'];
    }
    if (!empty($filters['id'])) {
        $sql .= ' AND pr.id = ?';
        $params[] = (int) $filters['id'];
    }
    if (!empty($filters['search'])) {
        $like = '%' . $filters['search'] . '%';
        $sql .= ' AND (m.membership_no LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR pr.reference LIKE ? OR pr.transaction_reference LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY CASE pr.status WHEN \'pending\' THEN 0 WHEN \'approved\' THEN 1 ELSE 2 END, pr.requested_at DESC, pr.id DESC LIMIT '
        . max(1, min(500, (int) ($filters['limit'] ?? 300)));
    return fetch_all($sql, $params);
}

/** Pending/confirmed requests belonging to one member. */
function member_payment_requests(int $memberId, int $limit = 100): array
{
    return list_payment_requests(['member_id' => $memberId, 'limit' => $limit]);
}

function get_payment_request(int $id): ?array
{
    $rows = list_payment_requests(['id' => $id]);
    return $rows[0] ?? null;
}

/** Apply an approved member payment to the ledger/domain exactly once. */
function approve_payment_request(int $id, int $reviewerId, string $remarks = ''): array
{
    $request = get_payment_request($id);
    if (!$request) {
        throw new RuntimeException('Payment request not found.');
    }
    if ($request['status'] !== 'pending') {
        throw new RuntimeException('This payment has already been reviewed.');
    }

    $claimed = q(
        'UPDATE payment_requests SET status = \'approved\', reviewed_by = ?, reviewed_at = ?, review_remarks = ? WHERE id = ? AND status = \'pending\'',
        [$reviewerId, date('Y-m-d H:i:s'), substr(trim($remarks), 0, 255) ?: null, $id]
    )->rowCount();
    if ($claimed !== 1) {
        throw new RuntimeException('This payment has already been reviewed.');
    }

    $memberId = (int) $request['member_id'];
    $amount = (float) $request['amount'];
    $payload = json_decode((string) ($request['payload'] ?? ''), true);
    $payload = is_array($payload) ? $payload : [];
    $meta = [
        'method'      => (string) $request['method'],
        'reference'   => $request['reference'] ?? null,
        'narration'   => $request['narration'] ?? null,
        'recorded_by' => $reviewerId,
    ];

    try {
        $receipt = null;
        $paymentId = null;
        switch ($request['type']) {
            case 'savings':
                $product = (string) ($payload['product'] ?? 'monthly');
                if (!array_key_exists($product, SAVINGS_PRODUCTS)) {
                    throw new RuntimeException('Unknown savings product for this payment.');
                }
                $meta['narration'] = $meta['narration'] ?: ('Contribution to ' . SAVINGS_PRODUCTS[$product]);
                $result = record_savings_contribution($memberId, $product, $amount, $meta);
                $receipt = $result['receipt_no'];
                $paymentId = (int) $result['payment_id'];
                safe_notify_member($memberId, 'savings_contribution', [
                    'amount'     => money($amount),
                    'receipt_no' => $receipt,
                ]);
                break;

            case 'loan_repayment':
                $result = record_loan_repayment((int) $request['related_id'], $amount, $meta);
                $receipt = $result['receipt_no'];
                $paymentId = (int) $result['payment_id'];
                safe_notify_member($memberId, 'repayment_received', [
                    'loan_no'     => (string) ($request['loan_no'] ?? ''),
                    'amount'      => money($amount),
                    'outstanding' => money((float) ($result['outstanding'] ?? 0)),
                    'receipt_no'  => $receipt,
                ]);
                break;

            case 'order':
                mark_sale_paid((int) $request['related_id'], (string) $request['method'], $request['reference'] ?? null);
                $sale = get_sale((int) $request['related_id']);
                $receipt = $sale['sale_no'] ?? null;
                break;

            default:
                $result = record_payment($memberId, 'other', $amount, $meta);
                $receipt = $result['receipt_no'] ?? null;
                $paymentId = !empty($result['payment_id']) ? (int) $result['payment_id'] : null;
                break;
        }

        q('UPDATE payment_requests SET receipt_no = ?, payment_id = ? WHERE id = ?', [$receipt, $paymentId, $id]);
        if (!empty($request['transaction_reference'])) {
            q('UPDATE transactions SET payment_id = ? WHERE reference = ?', [$paymentId, $request['transaction_reference']]);
        }
        audit('payment_request_approved', 'payment_requests', $id, PAYMENT_REQUEST_TYPES[$request['type']] . ' ' . money($amount));
        safe_notify_member($memberId, 'payment_approved', [
            'amount'   => money($amount),
            'purpose'  => PAYMENT_REQUEST_TYPES[$request['type']],
            'receipt_no' => $receipt ?? '—',
        ]);
        return ['receipt_no' => $receipt, 'payment_id' => $paymentId];
    } catch (Throwable $e) {
        q('UPDATE payment_requests SET status = \'pending\', reviewed_by = NULL, reviewed_at = NULL, review_remarks = NULL WHERE id = ? AND status = \'approved\'', [$id]);
        throw $e;
    }
}

/** Reject a queued member payment. */
function reject_payment_request(int $id, int $reviewerId, string $remarks = ''): void
{
    $request = get_payment_request($id);
    if (!$request) {
        throw new RuntimeException('Payment request not found.');
    }
    if ($request['status'] !== 'pending') {
        throw new RuntimeException('This payment has already been reviewed.');
    }
    $updated = q(
        'UPDATE payment_requests SET status = \'rejected\', reviewed_by = ?, reviewed_at = ?, review_remarks = ? WHERE id = ? AND status = \'pending\'',
        [$reviewerId, date('Y-m-d H:i:s'), substr(trim($remarks), 0, 255) ?: null, $id]
    )->rowCount();
    if ($updated !== 1) {
        throw new RuntimeException('This payment has already been reviewed.');
    }
    audit('payment_request_rejected', 'payment_requests', $id, PAYMENT_REQUEST_TYPES[$request['type']] . ' ' . money((float) $request['amount']));
    safe_notify_member((int) $request['member_id'], 'payment_rejected', [
        'amount'  => money((float) $request['amount']),
        'purpose' => PAYMENT_REQUEST_TYPES[$request['type']],
        'remarks' => $remarks,
    ]);
}

/** Confirmed income totals for a period. */
function payment_totals(?string $from = null, ?string $to = null): array
{
    $sql = 'SELECT type, COALESCE(SUM(amount),0) AS total, COUNT(*) AS count
            FROM payments WHERE status = "confirmed"';
    $params = [];
    if ($from) {
        $sql .= ' AND paid_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to) {
        $sql .= ' AND paid_at <= ?';
        $params[] = $to . ' 23:59:59';
    }
    $sql .= ' GROUP BY type';
    $rows = fetch_all($sql, $params);
    $out = ['grand_total' => 0.0, 'by_type' => []];
    foreach ($rows as $row) {
        $out['by_type'][$row['type']] = ['total' => (float) $row['total'], 'count' => (int) $row['count']];
        $out['grand_total'] += (float) $row['total'];
    }
    return $out;
}
