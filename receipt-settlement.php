<?php
/**
 * SETTLEMENT RECEIPT — twin of the VB MakeTransactionRecieptForm:
 * Patient No/Name, Transaction ID, Bill Amount, Amount Settled, New Balance (+ context).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = intval(get('id'));
function scol($t, $c) { try { db()->query("SELECT $c FROM $t LIMIT 1"); return true; } catch (Exception $e) { return false; } }
$ext = scol('members', 'mtn_no');
$has = scol('withdrawals', 'auth_method');
$st = db()->prepare('SELECT w.*, m.member_no, m.full_names, m.address, m.phone_no' . ($ext ? ', m.airtel_no, m.mtn_no' : '') . ' FROM withdrawals w JOIN members m ON m.id=w.member_id WHERE w.id = ?');
$st->execute(array($id)); $w = $st->fetch();
if (!$w) { echo '<p style="font:bold 16px Arial;padding:30px">Settlement not found. <a href="withdrawals.php">Back</a></p>'; exit; }
$g = function ($k) use ($w) { return isset($w[$k]) ? $w[$k] : ''; };
?><!doctype html>
<html><head><meta charset="utf-8"><title>Settlement Receipt #<?php echo $id; ?> — MedSavu</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body style="background:#EAF3FA;margin:0">
<div class="noprint" style="text-align:right;padding:12px 18px;font-family:Arial">
  <button class="btn primary" onclick="window.print()">Print / Save PDF</button>
  <a class="btn plain" href="bills.php?a=settle">&laquo; Settlement desk</a>
</div>
<div style="max-width:640px;margin:10px auto 30px;background:#fff;border:1px solid #cfe0ee;box-shadow:0 2px 8px rgba(18,71,111,.12)">
  <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 22px;background:linear-gradient(90deg,#EAF4FC,#F7FBFE);border-bottom:3px solid #1B6BB3">
    <div>
      <div style="font:bold 21px Georgia;color:#12476F">KISUBI <span style="color:#1B6BB3">HOSPITAL</span></div>
      <div style="font:bold 12px Arial;letter-spacing:3px;color:#1B6BB3">HEALTH SAVINGS SCHEME</div>
      <div style="font:10px Arial;color:#5b7c99;margin-top:2px">Medical Saving Scheme &middot; Settlement Voucher</div>
    </div>
    <div style="text-align:right">
      <div style="font:bold 16px Georgia;color:#12476F">BILL SETTLEMENT RECEIPT</div>
      <div style="font:bold 13px 'Courier New';background:#12476F;color:#ffd76e;display:inline-block;padding:2px 10px;border-radius:3px;margin-top:4px">TXN #<?php echo $w['id']; ?></div>
      <div style="font:11px Arial;color:#5b7c99;margin-top:4px"><?php echo e($w['withdraw_date']); ?> &middot; Status: <b style="color:<?php echo $w['status'] === 'Paid' ? '#20603a' : '#a0651a'; ?>"><?php echo e($w['status']); ?></b></div>
    </div>
  </div>
  <div style="padding:18px 22px">
    <table style="width:100%;font:13px Georgia;border-collapse:collapse">
      <tr><td style="color:#5b7c99;padding:3px 0;width:190px">Member</td><td><b><?php echo e($w['full_names']); ?></b> (<?php echo e($w['member_no']); ?>)</td></tr>
      <tr><td style="color:#5b7c99">Patient No</td><td><b><?php echo e($g('patient_no')); ?></b></td></tr>
      <tr><td style="color:#5b7c99">Patient Name</td><td><b><?php echo e($g('patient_name')); ?></b></td></tr>
      <tr><td style="color:#5b7c99">Hospital Invoice</td><td><?php echo e($g('invoice_no')); ?><?php echo intval($g('hospital_invoice_id')) > 0 ? ' &middot; HIS ID ' . e($g('hospital_invoice_id')) : ''; ?></td></tr>
      <tr><td style="color:#5b7c99">Visit / Days</td><td><?php echo e($g('visit_type')); ?> &middot; <?php echo e($g('hospital_days') !== '' ? $g('hospital_days') : '0'); ?> day(s)</td></tr>
    </table>
    <table style="width:100%;margin:16px 0;border-collapse:collapse;font:bold 14px Georgia">
      <tr style="background:#E9F2FA;color:#12476F">
        <td style="padding:9px;border:1px solid #cfe0ee">BILL AMOUNT (UGX)</td>
        <td style="padding:9px;border:1px solid #cfe0ee;text-align:right"><?php echo num($g('bill_amount') !== '' ? $g('bill_amount') : $w['amount']); ?></td></tr>
      <?php if ($g('discount_percent') !== '' && (float)$g('discount_percent') > 0): ?>
      <tr><td style="padding:9px;border:1px solid #cfe0ee;color:#a0651a">DISCOUNT GIVEN (<?php echo e($g('discount_percent')); ?>%)</td>
        <td style="padding:9px;border:1px solid #cfe0ee;text-align:right;color:#a0651a">-<?php echo num((float)$g('bill_amount') * (float)$g('discount_percent') / 100); ?></td></tr>
      <?php endif; ?>
      <tr><td style="padding:9px;border:1px solid #cfe0ee;color:#20603a">AMOUNT SETTLED FROM SAVINGS</td>
        <td style="padding:9px;border:1px solid #cfe0ee;text-align:right;color:#20603a"><?php echo num($w['amount']); ?></td></tr>
      <tr style="background:#F0FBF2"><td style="padding:9px;border:1px solid #bfe3c6">NEW BALANCE (UGX)</td>
        <td style="padding:9px;border:1px solid #bfe3c6;text-align:right"><?php echo num($g('new_balance')); ?></td></tr>
    </table>
    <?php if ($has): ?>
    <table style="width:100%;font:12.5px Georgia;border-collapse:collapse">
      <tr><td style="color:#5b7c99;width:190px">Authorized by</td><td><b><?php echo e($g('auth_method')); ?></b><?php echo e($g('auth_reference')) !== '' ? ' &middot; ref ' . e($g('auth_reference')) : ''; ?><?php echo e($g('auth_at')) !== '' ? ' &middot; ' . e($g('auth_at')) : ''; ?></td></tr>
      <tr><td style="color:#5b7c99">Settled by</td><td><?php echo e($w['processed_by']); ?></td></tr>
    </table>
    <?php endif; ?>
  </div>
  <div style="text-align:center;padding:13px;border-top:2px solid #1B6BB3;background:#F7FBFE">
    <div style="font:bold 14px Georgia;color:#12476F">Paid from member savings on this day as recorded in the scheme ledger.</div>
    <div style="font:10.5px Arial;color:#5b7c99;margin-top:3px">Kisubi, Kampala &middot; www.kisubihospital.co.ug &middot; Voucher generated <?php echo date('d M Y H:i'); ?></div>
  </div>
</div>
<style>@media print{.noprint{display:none}body{background:#fff}}</style>
</body></html>
