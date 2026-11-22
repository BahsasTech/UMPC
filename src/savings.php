<?php
declare(strict_types=1);

/**
 * Savings management: contributions, withdrawals and statements.
 */

/** Fetch a member's savings account of a given type. */
function get_savings_account(int $memberId, string $type): ?array
{
    create_savings_accounts($memberId);
    return fetch_one('SELECT * FROM savings_accounts WHERE member_id = ? AND type = ?', [$memberId, $type]);
}

/**
 * Post a savings contribution (credit) and record the matching payment.
 */
function record_savings_contribution(int $memberId, string $type, float $amount, array $meta = []): array
{
    if ($amount <= 0) {
        throw new RuntimeException('Contribution amount must be greater than zero.');
    }
    if (!array_key_exists($type, SAVINGS_PRODUCTS)) {
        throw new RuntimeException('Unknown savings product.');
    }

    $account = get_savings_account($memberId, $type);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('UPDATE savings_accounts SET balance = balance + ? WHERE id = ?', [$amount, $account['id']]);
        $newBalance = (float) fetch_value('SELECT balance FROM savings_accounts WHERE id = ?', [$account['id']]);

        $receipt = next_reference('payments', 'receipt_no', 'RCP-' . date('ymd') . '-', 4);
        $paymentId = db_insert('payments', [
            'receipt_no'         => $receipt,
            'member_id'          => $memberId,
            'savings_account_id' => (int) $account['id'],
            'type'               => $type === 'share_capital' ? 'share_capital'
                                    : ($type === 'monthly' ? 'savings_contribution' : 'special_savings'),
            'amount'             => $amount,
            'method'             => $meta['method'] ?? 'cash',
            'reference'          => $meta['reference'] ?? null,
            'status'             => 'confirmed',
            'narration'          => $meta['narration'] ?? ('Contribution to ' . SAVINGS_PRODUCTS[$type]),
            'paid_at'            => $meta['paid_at'] ?? date('Y-m-d H:i:s'),
            'recorded_by'        => $meta['recorded_by'] ?? ($_SESSION['user_id'] ?? null),
        ]);

        $pdo->commit();
        audit('savings_contribution', 'savings_accounts', (int) $account['id'], money($amount) . ' to ' . $type);
        post_savings_entry((string) ($meta['method'] ?? 'cash'), $type, $amount, $meta['reference'] ?? null, $paymentId);
        return ['receipt_no' => $receipt, 'payment_id' => $paymentId, 'balance' => $newBalance];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Post a savings withdrawal (debit). */
function record_savings_withdrawal(int $memberId, string $type, float $amount, array $meta = []): array
{
    if ($amount <= 0) {
        throw new RuntimeException('Withdrawal amount must be greater than zero.');
    }
    $account = get_savings_account($memberId, $type);
    if ((float) $account['balance'] < $amount) {
        throw new RuntimeException('Insufficient balance in the ' . SAVINGS_PRODUCTS[$type] . ' account.');
    }

    q('UPDATE savings_accounts SET balance = balance - ? WHERE id = ?', [$amount, $account['id']]);
    $newBalance = (float) fetch_value('SELECT balance FROM savings_accounts WHERE id = ?', [$account['id']]);

    audit('savings_withdrawal', 'savings_accounts', (int) $account['id'], money($amount) . ' from ' . $type);
    post_savings_withdrawal_entry((string) ($meta['method'] ?? 'cash'), $type, $amount, (int) $account['id']);
    return ['balance' => $newBalance];
}

/** Update a savings target. */
function set_savings_target(int $memberId, string $type, float $target): void
{
    $account = get_savings_account($memberId, $type);
    db_update('savings_accounts', (int) $account['id'], ['target_amount' => $target]);
}

function submit_savings_contribution_request(int $memberId, string $type, float $amount, array $meta, int $requestedBy): int
{
    if ($amount <= 0) {
        throw new RuntimeException('Contribution amount must be greater than zero.');
    }
    if (!array_key_exists($type, SAVINGS_PRODUCTS)) {
        throw new RuntimeException('Unknown savings product.');
    }
    $account = get_savings_account($memberId, $type);
    $id = db_insert('savings_requests', [
        'member_id'         => $memberId,
        'savings_account_id'=> (int) $account['id'],
        'type'              => 'contribution',
        'amount'            => $amount,
        'method'            => (string) ($meta['method'] ?? 'bank_transfer'),
        'reference'         => (string) ($meta['reference'] ?? '') ?: null,
        'narration'         => (string) ($meta['narration'] ?? '') ?: null,
        'requested_by'      => $requestedBy,
    ]);
    audit('savings_contribution_submitted', 'savings_requests', $id, money($amount) . ' to ' . $type);
    safe_notify_member($memberId, 'contribution_submitted', ['amount' => money($amount)]);
    notify_assigned_reviewer($memberId, 'Contribution awaiting verification', money($amount) . ' was submitted for ' . SAVINGS_PRODUCTS[$type] . '.');
    return $id;
}

function submit_savings_target_request(int $memberId, string $type, float $target, string $reason, int $requestedBy): int
{
    if ($target < 0) {
        throw new RuntimeException('Savings target cannot be negative.');
    }
    if (!array_key_exists($type, SAVINGS_PRODUCTS)) {
        throw new RuntimeException('Unknown savings product.');
    }
    $account = get_savings_account($memberId, $type);
    $id = db_insert('savings_requests', [
        'member_id'         => $memberId,
        'savings_account_id'=> (int) $account['id'],
        'type'              => 'target',
        'requested_target'  => $target,
        'reason'            => substr(trim($reason), 0, 255) ?: null,
        'requested_by'      => $requestedBy,
    ]);
    audit('savings_target_requested', 'savings_requests', $id, 'Target ' . money($target) . ' for ' . $type);
    safe_notify_member($memberId, 'target_requested', [
        'product' => SAVINGS_PRODUCTS[$type],
        'target'  => $target > 0 ? money($target) : 'cleared',
    ]);
    notify_assigned_reviewer($memberId, 'Savings target request', 'A ' . ($target > 0 ? 'target change' : 'target reset') . ' was requested for ' . SAVINGS_PRODUCTS[$type] . '.');
    return $id;
}

function assigned_reviewer_name(int $memberId): string
{
    $row = fetch_one('SELECT u.full_name FROM members m LEFT JOIN users u ON u.id = m.assigned_user_id WHERE m.id = ?', [$memberId]);
    return (string) ($row['full_name'] ?? '') ?: 'Any staff member';
}

function get_savings_request(int $id): ?array
{
    return fetch_one(
        'SELECT r.*, m.membership_no, m.first_name, m.last_name, m.assigned_user_id,
                s.type AS product, u.full_name AS requested_by_name, ru.full_name AS reviewed_by_name
         FROM savings_requests r
         JOIN members m ON m.id = r.member_id
         JOIN savings_accounts s ON s.id = r.savings_account_id
         JOIN users u ON u.id = r.requested_by
         LEFT JOIN users ru ON ru.id = r.reviewed_by
         WHERE r.id = ?',
        [$id]
    );
}

function list_savings_requests(array $filters = []): array
{
    $sql = 'SELECT r.*, m.membership_no, m.first_name, m.last_name, m.assigned_user_id,
                   s.type AS product, u.full_name AS requested_by_name, ru.full_name AS reviewed_by_name
            FROM savings_requests r
            JOIN members m ON m.id = r.member_id
            JOIN savings_accounts s ON s.id = r.savings_account_id
            JOIN users u ON u.id = r.requested_by
            LEFT JOIN users ru ON ru.id = r.reviewed_by
            WHERE 1 = 1';
    $params = [];
    if (!empty($filters['status'])) {
        $sql .= ' AND r.status = ?';
        $params[] = (string) $filters['status'];
    }
    if (!empty($filters['type'])) {
        $sql .= ' AND r.type = ?';
        $params[] = (string) $filters['type'];
    }
    if (!empty($filters['member_id'])) {
        $sql .= ' AND r.member_id = ?';
        $params[] = (int) $filters['member_id'];
    }
    if (!empty($filters['assigned_user_id'])) {
        $sql .= ' AND (m.assigned_user_id = ? OR m.assigned_user_id IS NULL)';
        $params[] = (int) $filters['assigned_user_id'];
    }
    $sql .= ' ORDER BY CASE r.status WHEN "pending" THEN 0 WHEN "approved" THEN 1 ELSE 2 END, r.requested_at DESC, r.id DESC LIMIT 300';
    return fetch_all($sql, $params);
}

function can_review_savings_request(array $request, array $user): bool
{
    $role = (string) ($user['role'] ?? '');
    if ($role === 'admin') {
        return true;
    }
    if ($role !== 'staff') {
        return false;
    }
    $assigned = (int) ($request['assigned_user_id'] ?? 0);
    return $assigned === 0 || $assigned === (int) ($user['id'] ?? 0);
}

function verify_savings_contribution(int $id, int $reviewerId, string $remarks = ''): array
{
    $request = get_savings_request($id);
    if (!$request || $request['type'] !== 'contribution') {
        throw new RuntimeException('Contribution request not found.');
    }
    if ($request['status'] !== 'pending') {
        throw new RuntimeException('This request has already been reviewed.');
    }

    $claimed = q(
        'UPDATE savings_requests SET status = "approved", reviewed_by = ?, reviewed_at = ?, review_remarks = ? WHERE id = ? AND status = "pending"',
        [$reviewerId, date('Y-m-d H:i:s'), substr(trim($remarks), 0, 255) ?: null, $id]
    )->rowCount();
    if ($claimed !== 1) {
        throw new RuntimeException('This request has already been reviewed.');
    }

    try {
        $result = record_savings_contribution((int) $request['member_id'], (string) $request['product'], (float) $request['amount'], [
            'method'      => (string) ($request['method'] ?? 'cash'),
            'reference'   => $request['reference'] ?? null,
            'narration'   => $request['narration'] ?? null,
            'recorded_by' => $reviewerId,
        ]);
        q('UPDATE savings_requests SET receipt_no = ?, payment_id = ? WHERE id = ?', [$result['receipt_no'], $result['payment_id'], $id]);
        audit('savings_contribution_verified', 'savings_requests', $id, 'Approved receipt ' . $result['receipt_no']);
        safe_notify_member((int) $request['member_id'], 'contribution_approved', [
            'amount'     => money((float) $request['amount']),
            'receipt_no' => $result['receipt_no'],
        ]);
        return $result;
    } catch (Throwable $e) {
        q('UPDATE savings_requests SET status = "pending", reviewed_by = NULL, reviewed_at = NULL, review_remarks = NULL WHERE id = ? AND status = "approved"', [$id]);
        throw $e;
    }
}

function approve_savings_target_request(int $id, int $reviewerId, string $remarks = ''): void
{
    $request = get_savings_request($id);
    if (!$request || $request['type'] !== 'target') {
        throw new RuntimeException('Target request not found.');
    }
    if ($request['status'] !== 'pending') {
        throw new RuntimeException('This request has already been reviewed.');
    }

    $claimed = q(
        'UPDATE savings_requests SET status = "approved", reviewed_by = ?, reviewed_at = ?, review_remarks = ? WHERE id = ? AND status = "pending"',
        [$reviewerId, date('Y-m-d H:i:s'), substr(trim($remarks), 0, 255) ?: null, $id]
    )->rowCount();
    if ($claimed !== 1) {
        throw new RuntimeException('This request has already been reviewed.');
    }

    try {
        $target = (float) $request['requested_target'];
        set_savings_target((int) $request['member_id'], (string) $request['product'], $target);
        audit('savings_target_approved', 'savings_requests', $id, 'Target set to ' . money($target));
        safe_notify_member((int) $request['member_id'], 'target_approved', [
            'product' => SAVINGS_PRODUCTS[$request['product']] ?? $request['product'],
            'target'  => $target > 0 ? money($target) : 'cleared',
        ]);
    } catch (Throwable $e) {
        q('UPDATE savings_requests SET status = "pending", reviewed_by = NULL, reviewed_at = NULL, review_remarks = NULL WHERE id = ? AND status = "approved"', [$id]);
        throw $e;
    }
}

function reject_savings_request(int $id, int $reviewerId, string $remarks = ''): void
{
    $request = get_savings_request($id);
    if (!$request) {
        throw new RuntimeException('Request not found.');
    }
    if ($request['status'] !== 'pending') {
        throw new RuntimeException('This request has already been reviewed.');
    }

    $updated = q(
        'UPDATE savings_requests SET status = "rejected", reviewed_by = ?, reviewed_at = ?, review_remarks = ? WHERE id = ? AND status = "pending"',
        [$reviewerId, date('Y-m-d H:i:s'), substr(trim($remarks), 0, 255) ?: null, $id]
    )->rowCount();
    if ($updated !== 1) {
        throw new RuntimeException('This request has already been reviewed.');
    }

    audit('savings_request_rejected', 'savings_requests', $id, 'Rejected ' . $request['type'] . ' request');
    safe_notify_member((int) $request['member_id'], $request['type'] === 'contribution' ? 'contribution_rejected' : 'target_rejected', [
        'product' => SAVINGS_PRODUCTS[$request['product']] ?? $request['product'],
        'amount'  => money((float) ($request['amount'] ?? 0)),
        'remarks' => $remarks,
    ]);
}

/** Transaction history for a member's savings, optionally for one product. */
function savings_statement(int $memberId, ?string $type = null, int $limit = 200): array
{
    $sql = 'SELECT p.*, s.type AS product
            FROM payments p
            LEFT JOIN savings_accounts s ON s.id = p.savings_account_id
            WHERE p.member_id = ? AND p.savings_account_id IS NOT NULL AND p.status = "confirmed"';
    $params = [$memberId];
    if ($type !== null) {
        $sql .= ' AND s.type = ?';
        $params[] = $type;
    }
    $sql .= ' ORDER BY p.paid_at DESC, p.id DESC LIMIT ' . (int) $limit;
    return fetch_all($sql, $params);
}
