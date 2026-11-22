<?php
declare(strict_types=1);

/**
 * Shared reminder/missed-payment job used by bin/send-reminders.php and the
 * Vercel Cron HTTP endpoint (public/cron.php).
 */

function reminders_already_notified(string $event, int $memberId, string $needle, bool $force): bool
{
    if ($force) {
        return false;
    }
    $today = date('Y-m-d');
    $count = (int) fetch_value(
        'SELECT COUNT(*) FROM notifications
         WHERE event = ? AND member_id = ? AND body LIKE ?
           AND created_at >= ? AND created_at < ?',
        [$event, $memberId, '%' . $needle . '%', $today, date('Y-m-d', strtotime('+1 day'))]
    );
    return $count > 0;
}

/** @return array{sent:int,skipped:int,lines:array<int,string>} */
function run_reminders(int $days = 3, bool $doReminders = true, bool $doMissed = true, bool $force = false): array
{
    $sent = 0;
    $skipped = 0;
    $lines = [];
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime('+' . max(0, $days) . ' days'));

    if ($doReminders) {
        $rows = fetch_all(
            'SELECT ls.installment, ls.amount_due, ls.due_date, l.loan_no, l.member_id
             FROM loan_schedule ls
             JOIN loans l ON l.id = ls.loan_id
             WHERE l.status IN (\'disbursed\',\'defaulted\')
               AND ls.status IN (\'pending\',\'partial\')
               AND ls.due_date BETWEEN ? AND ?
             ORDER BY ls.due_date ASC',
            [$today, $until]
        );
        $lines[] = '• ' . count($rows) . ' upcoming installment(s) within ' . $days . ' day(s).';
        foreach ($rows as $r) {
            $needle = $r['loan_no'] . '|' . $r['installment'];
            if (reminders_already_notified('repayment_reminder', (int) $r['member_id'], $r['loan_no'], $force)) {
                $skipped++;
                continue;
            }
            notify_member((int) $r['member_id'], 'repayment_reminder', [
                'loan_no'     => $r['loan_no'],
                'installment' => $r['installment'],
                'amount_due'  => money($r['amount_due']),
                'due_date'    => fmt_date($r['due_date']),
                'marker'      => $needle,
            ]);
            $sent++;
        }
    }

    if ($doMissed) {
        refresh_missed_installments();
        $rows = fetch_all(
            'SELECT ls.installment, ls.amount_due, ls.due_date, l.loan_no, l.member_id
             FROM loan_schedule ls
             JOIN loans l ON l.id = ls.loan_id
             WHERE ls.status = \'missed\'
             ORDER BY ls.due_date ASC'
        );
        $lines[] = '• ' . count($rows) . ' missed installment(s).';
        foreach ($rows as $r) {
            if (reminders_already_notified('missed_payment', (int) $r['member_id'], $r['loan_no'], $force)) {
                $skipped++;
                continue;
            }
            notify_member((int) $r['member_id'], 'missed_payment', [
                'loan_no'     => $r['loan_no'],
                'installment' => $r['installment'],
                'amount_due'  => money($r['amount_due']),
                'due_date'    => fmt_date($r['due_date']),
            ]);
            $sent++;
        }
    }

    return ['sent' => $sent, 'skipped' => $skipped, 'lines' => $lines];
}
