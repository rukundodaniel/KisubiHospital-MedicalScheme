<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$table = get('table');
$from = get('from', '1900-01-01'); $to = get('to', '2099-12-31');

$queries = array(
    'deposits' => array('csv',
        "SELECT d.deposit_date, m.member_no, m.full_names, d.amount, d.payment_method, d.reference, d.entered_by
         FROM deposits d JOIN members m ON m.id=d.member_id WHERE d.deposit_date BETWEEN ? AND ? ORDER BY d.deposit_date",
        array($from, $to)),
    'withdrawals' => array('csv',
        "SELECT w.withdraw_date, m.member_no, m.full_names, w.amount, w.purpose, w.status, w.paid_date
         FROM withdrawals w JOIN members m ON m.id=w.member_id ORDER BY w.withdraw_date",
        array()),
    'bills' => array('csv',
        "SELECT b.bill_date, m.member_no, m.full_names, b.provider, b.description, b.amount, b.status
         FROM medical_bills b JOIN members m ON m.id=b.member_id ORDER BY b.bill_date",
        array()),
    'members' => array('csv',
        "SELECT member_no, full_names, national_id, gender, phone_no, address, next_of_kin, registration_date, status FROM members ORDER BY member_no",
        array()),
    'momo' => array('csv',
        "SELECT t.created_at, m.member_no, m.full_names, t.phone, t.external_id, t.reference_id, t.amount, t.currency, t.status, t.momo_status, t.reason FROM momo_transactions t JOIN members m ON m.id=t.member_id ORDER BY t.id DESC",
        array()),
    'finance' => array('finance', array(), array()),
);
if (!isset($queries[$table])) die('Unknown export.');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="medsavu_' . preg_replace('/\W/', '', $table) . '_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');

if ($table === 'finance') {
    fputcsv($out, array('MedSavu Financial Summary', $from, 'to', $to));
    fputcsv($out, array(''));
    fputcsv($out, array('Item', 'UGX'));
    fputcsv($out, array('Income (deposits)', sum_between('deposits', 'deposit_date', $from, $to)));
    $wd = sum_between('withdrawals', 'withdraw_date', $from, $to, "status='Paid'");
    $bi = sum_between('medical_bills', 'bill_date', $from, $to, "status='Paid'");
    fputcsv($out, array('Withdrawals paid', $wd));
    fputcsv($out, array('Medical bills paid', $bi));
    fputcsv($out, array('Total expenditure', $wd + $bi));
    fputcsv($out, array('Net movement', sum_between('deposits','deposit_date',$from,$to) - $wd - $bi));
    fclose($out); exit;
}

list(, $sql, $args) = $queries[$table];
$st = db()->prepare($sql); $st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if ($rows) { fputcsv($out, array_keys($rows[0])); foreach ($rows as $r) { fputcsv($out, array_values($r)); } }
fclose($out); exit;
