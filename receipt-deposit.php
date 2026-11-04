<?php
/**
 * DEPOSIT RECEIPT — twin of the VB DepositRecieptForm (Crystal receipt):
 * one receipt card for a deposit transaction, print-to-PDF ready.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

function rcol($tbl, $col) { try { db()->query("SELECT $col FROM $tbl LIMIT 1"); return true; } catch (Exception $e) { return false; } }

$id = intval(get('id'));
$ext = rcol('members', 'mtn_no');
$sel = 'SELECT d.*, m.member_no, m.full_names, m.email_address, m.address, m.phone_no'
     . ($ext ? ', m.mtn_no, m.airtel_no' : '') . ' FROM deposits d JOIN members m ON m.id = d.member_id WHERE d.id = ?';
$st = db()->prepare($sel); $st->execute(array($id)); $d = $st->fetch();
if (!$d) { echo '<p style="font:bold 16px Arial;padding:30px">Receipt not found.</p>'; exit; }

$memberId = intval($d['member_id']); $date = $d['deposit_date'];
// balance just before this entry (same ledger rules as the register)
$st = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM deposits WHERE member_id=? AND (deposit_date<? OR (deposit_date=? AND id<?))');
$st->execute(array($memberId, $date, $date, $id)); $dep = (float)$st->fetchColumn();
$st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE member_id=? AND status='Paid' AND withdraw_date<=?");
$st->execute(array($memberId, $date)); $wd = (float)$st->fetchColumn();
$st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM medical_bills WHERE member_id=? AND status='Paid' AND bill_date<=?");
$st->execute(array($memberId, $date)); $bl = (float)$st->fetchColumn();
$prevBalance = max(0.0, $dep - $wd - $bl);
$newBalance = $prevBalance + (float)$d['amount'];
?><!doctype html>
<html><head><meta charset="utf-8"><title>Deposit Receipt #<?php echo $id; ?> — MedSavu</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body style="background:#EAF3FA;margin:0">
<div class="noprint" style="text-align:right;padding:12px 18px;font-family:Arial">
  <button class="btn primary" onclick="window.print()">Print / Save PDF</button>
  <a class="btn plain" href="deposits.php">&laquo; Back to register</a>
</div>
<div style="max-width:640px;margin:10px auto 30px;background:#fff;border:1px solid #cfe0ee;box-shadow:0 2px 8px rgba(18,71,111,.12)">
  <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 22px;background:linear-gradient(90deg,#EAF4FC,#F7FBFE);border-bottom:3px solid #1B6BB3">
    <div>
      <div style="font:bold 21px Georgia;color:#12476F">KISUBI <span style="color:#1B6BB3">HOSPITAL</span></div>
      <div style="font:bold 12px Arial;letter-spacing:3px;color:#1B6BB3">HEALTH SAVINGS SCHEME</div>
      <div style="font:10px Arial;color:#5b7c99;margin-top:2px">Registration No: <?php echo e(setting('reg_no')); ?> &middot; SACCO No: <?php echo e(setting('sacco_no')); ?></div>
    </div>
    <div style="text-align:right">
      <div style="font:bold 16px Georgia;color:#12476F">DEPOSIT RECEIPT</div>
      <div style="font:bold 13px 'Courier New';background:#12476F;color:#ffd76e;display:inline-block;padding:2px 10px;border-radius:3px;margin-top:4px">TXN #<?php echo $d['id']; ?></div>
      <div style="font:11px Arial;color:#5b7c99;margin-top:4px"><?php echo e($date); ?> <?php echo e(substr((string)$d['created_at'], 11, 8)); ?></div>
    </div>
  </div>
  <div style="padding:18px 22px">
    <table style="width:100%;font:13px Georgia;border-collapse:collapse">
      <tr><td style="color:#5b7c99;padding:3px 0;width:180px">Member</td><td><b><?php echo e($d['full_names']); ?></b></td></tr>
      <tr><td style="color:#5b7c99">Member No.</td><td><b><?php echo e($d['member_no']); ?></b></td></tr>
      <tr><td style="color:#5b7c99">Telephone</td><td><?php echo $ext ? 'Airtel ' . e($d['airtel_no']) . ' &middot; MTN ' . e($d['mtn_no']) : e($d['phone_no']); ?></td></tr>
      <tr><td style="color:#5b7c99">Residence</td><td><?php echo e($d['address']); ?></td></tr>
      <?php if (!empty($d['email_address'])): ?><tr><td style="color:#5b7c99">Email</td><td><?php echo e($d['email_address']); ?></td></tr><?php endif; ?>
    </table>
    <table style="width:100%;margin:16px 0;border-collapse:collapse;font:bold 14px Georgia">
      <tr style="background:#E9F2FA;color:#12476F">
        <td style="padding:9px;border:1px solid #cfe0ee">PREVIOUS BALANCE (UGX)</td>
        <td style="padding:9px;border:1px solid #cfe0ee;text-align:right"><?php echo num($prevBalance); ?></td></tr>
      <tr><td style="padding:9px;border:1px solid #cfe0ee;color:#20603a">DEPOSIT RECEIVED — <?php echo strtoupper(e($d['payment_method'])); ?></td>
        <td style="padding:9px;border:1px solid #cfe0ee;text-align:right;color:#20603a"><?php echo num($d['amount']); ?></td></tr>
      <tr style="background:#F0FBF2"><td style="padding:9px;border:1px solid #bfe3c6">NEW TOTAL BALANCE (UGX)</td>
        <td style="padding:9px;border:1px solid #bfe3c6;text-align:right"><?php echo num($newBalance); ?></td></tr>
    </table>
    <table style="width:100%;font:12.5px Georgia;border-collapse:collapse">
      <tr><td style="color:#5b7c99;width:180px">Reference</td><td><?php echo e($d['reference']); ?></td></tr>
      <tr><td style="color:#5b7c99">Received by</td><td><?php echo e($d['entered_by']); ?></td></tr>
      <?php if ((string)$d['note'] !== ''): ?><tr><td style="color:#5b7c99">Note</td><td><?php echo e($d['note']); ?></td></tr><?php endif; ?>
    </table>
  </div>
  <div style="text-align:center;padding:13px;border-top:2px solid #1B6BB3;background:#F7FBFE">
    <div style="font:bold 14px Georgia;color:#12476F">Thank you for Depositing with Kisubi Hospital Medical Saving Scheme.</div>
    <div style="font:10.5px Arial;color:#5b7c99;margin-top:3px">Kisubi, Kampala &middot; www.kisubihospital.co.ug &middot; Receipt generated <?php echo date('d M Y H:i'); ?></div>
  </div>
</div>
<style>@media print{.noprint{display:none}body{background:#fff}}</style>
</body></html>
