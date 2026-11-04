<?php
/**
 * PORTAL: MY BILL CLEARINGS — every time the scheme settled a hospital invoice from
 * the member's savings (settlement desk rows + paid payouts), with running context.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_auth.php';
$m = portal_require();

$hasSettle = false;
$st = db()->prepare('SELECT * FROM withdrawals WHERE member_id=? AND status=\'Paid\' ORDER BY id DESC');
$st->execute(array($m['id'])); $rows = $st->fetchAll();
try { db()->query('SELECT auth_method, patient_name, invoice_no, new_balance, discount_percent, visit_type FROM withdrawals LIMIT 1'); $hasSettle = true; } catch (Exception $e) { $hasSettle = false; }

$totSettled = 0.0; $settleN = 0; $payoutN = 0; $totPayout = 0.0;
foreach ($rows as $r) {
    $isS = $hasSettle && trim((string)$r['auth_method']) !== '';
    if ($isS) { $totSettled += (float)$r['amount']; $settleN++; } else { $totPayout += (float)$r['amount']; $payoutN++; }
}

$p_title = 'My Bill Clearings';
include __DIR__ . '/includes/portal_header.php';
?>
<div class="page-head noprint"><div><h1 style="margin:0;font:bold 21px Georgia,serif;color:#12476f">My Bill Clearings</h1>
<span style="font:12px Arial;color:#5b7c99">Every payment the scheme made from your savings to settle a hospital invoice</span></div>
<a class="btn plain" href="#" onclick="window.print();return false">🖨 Print</a></div>

<div class="grid grid-3" style="margin:12px 0">
  <div class="kpi"><div class="k-label">Bills cleared</div><div class="k-value"><?php echo $settleN; ?></div><div class="k-sub">via the settlement desk</div></div>
  <div class="kpi red"><div class="k-label">Total settled from savings</div><div class="k-value"><?php echo num($totSettled); ?></div><div class="k-sub">UGX &middot; hospital invoices</div></div>
  <div class="kpi gold"><div class="k-label">Other payouts</div><div class="k-value"><?php echo num($totPayout); ?></div><div class="k-sub"><?php echo $payoutN; ?> approved withdrawal(s)</div></div>
</div>

<div class="twrap"><table class="data">
<tr><th>Date</th><th>Patient</th><th>Invoice</th><th>Visit</th><th class="num">Bill (UGX)</th><th class="num">Settled (UGX)</th><?php if ($hasSettle): ?><th class="num">Balance after</th><?php endif; ?><th>Authorized</th><th>By</th></tr>
<?php if (!$rows): ?><tr><td colspan="<?php echo $hasSettle?9:8; ?>" style="color:#5b7c99">No bill clearings yet — when the desk settles an invoice for you it appears here instantly.</td></tr><?php endif; ?>
<?php foreach ($rows as $r):
    $isS = $hasSettle && trim((string)$r['auth_method']) !== ''; ?>
<tr<?php echo $isS ? ' style="background:#F7FBFE"' : ''; ?>>
<td style="white-space:nowrap"><?php echo e(date('d M Y', strtotime($r['withdraw_date']))); ?></td>
<td><?php echo $isS ? '<b>' . e($r['patient_name'] !== null && $r['patient_name'] !== '' ? $r['patient_name'] : '—') . '</b>' : '<span style="color:#5b7c99">member payout</span>'; ?></td>
<td style="font-size:11.5px"><?php echo $isS && trim((string)$r['invoice_no']) !== '' ? e($r['invoice_no']) : '—'; ?></td>
<td><?php echo $isS && trim((string)$r['visit_type']) !== '' ? e($r['visit_type']) . ((int)$r['hospital_days'] > 0 ? ' &middot; ' . (int)$r['hospital_days'] . 'd' : '') : '—'; ?></td>
<td class="num"><?php echo $isS && (float)$r['bill_amount'] > 0 ? num($r['bill_amount']) . ($hasSettle && (float)$r['discount_percent'] > 0 ? ' <span style="font-size:10px;color:#a0651a">(-' . num((float)$r['bill_amount'] * (float)$r['discount_percent'] / 100) . ' disc.)</span>' : '') : '—'; ?></td>
<td class="num"><b style="color:<?php echo $isS ? '#a03028' : '#8a6410'; ?>"><?php echo ugx($r['amount']); ?></b></td>
<?php if ($hasSettle): ?><td class="num"><?php echo $r['new_balance'] !== null && $r['new_balance'] !== '' ? '<b>' . num($r['new_balance']) . '</b>' : '—'; ?></td><?php endif; ?>
<td><?php if ($isS): ?><span class="badge blue"><?php echo e($r['auth_method']); ?></span><?php else: ?><span class="badge amber">PAYOUT</span><?php endif; ?></td>
<td style="font-size:11.5px;color:#5b7c99"><?php echo e($r['processed_by']); ?></td></tr>
<?php endforeach; ?>
</table></div>
<p style="color:#5b7c99;font-size:12px;margin-top:10px">Your current available balance is <b><?php echo ugx(member_balance($m['id'])); ?></b>. Settlement vouchers are printed at the cashier desk.
&nbsp;<a href="portal.php">Back to my account &raquo;</a></p>
<?php include __DIR__ . '/includes/portal_footer.php'; ?>
