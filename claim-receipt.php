<?php
/** Printable claim / disbursement receipt (v3.9). */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_login();
$id = intval(get('id'));
$st = db()->prepare('SELECT c.*, u.full_name owner_name FROM facility_claims c LEFT JOIN users u ON u.username=c.paid_by WHERE c.id=?');
$st->execute(array($id)); $c = $st->fetch();
if (!$c) { echo 'Claim not found.'; exit; }
$locked = fac_scope_fid();
if ($locked > 0 && intval($c['facility_id']) !== $locked) { echo 'This claim belongs to another facility.'; exit; }
$items = json_decode((string)$c['items_json'], true); if (!is_array($items)) $items = array();
$f = fac_of(intval($c['facility_id']));
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title><?php echo e($c['claim_no']); ?></title>
<style>
 body{font:13px "Segoe UI",Arial;color:#123;margin:24px;background:#fff}
 h1{font:bold 17px Georgia;margin:0 0 2px} .sub{color:#5b7c99;font-size:11.5px}
 table{width:100%;border-collapse:collapse;margin:14px 0} th,td{border:1px solid #cfd9e2;padding:6px 8px;text-align:left;font-size:12px}
 th{background:#E3EFFA} .num{text-align:right}
 .stamp{display:inline-block;border:3px solid #20603a;color:#20603a;font:bold 15px Georgia;padding:6px 18px;border-radius:6px;transform:rotate(-4deg)}
 .stamp.no{border-color:#8c2424;color:#8c2424} .stamp.wait{border-color:#8a6410;color:#8a6410}
 .grid{display:flex;gap:26px;flex-wrap:wrap;font-size:12px} .grid b{display:block;font-size:10px;color:#7a94aa;text-transform:uppercase;letter-spacing:.6px}
 @media print{.noprint{display:none}}
</style></head><body>
<div class="noprint" style="margin-bottom:14px"><button onclick="window.print()" style="padding:6px 14px;cursor:pointer">&#128424; Print</button>
 <button onclick="history.back()" style="padding:6px 14px;cursor:pointer">&larr; Back</button></div>
<div class="grid" style="justify-content:space-between;align-items:flex-start">
 <div><h1>&#9878;&#65039; KISUBI HOSPITAL MEDICAL SAVING SCHEME</h1>
 <div class="sub">Facility Claims &amp; Disbursements — official receipt &middot; Tel 0392-177-008 &middot; Kisubi, Wakiso</div></div>
 <?php if ($c['status'] === 'Paid'): ?><div class="stamp">CLEARED<br><span style="font:11px Arial"><?php echo e(date('d M Y', strtotime($c['paid_at']))); ?></span></div>
 <?php elseif ($c['status'] === 'Rejected'): ?><div class="stamp no">NOT CLEARED</div>
 <?php elseif ($c['status'] === 'Approved'): ?><div class="stamp wait">APPROVED — AWAITING PAYMENT</div>
 <?php else: ?><div class="stamp wait">PENDING OWNER REVIEW</div><?php endif; ?>
</div>
<div class="grid" style="margin-top:14px">
 <div><b>Claim no</b><?php echo e($c['claim_no']); ?></div>
 <div><b>From facility</b><?php echo $f ? $f['ftype'] === 'Hospital' ? '&#127973; ' : '⚕️ ' : ''; ?><?php echo e($c['facility_name']); ?></div>
 <div><b>Status</b><?php echo e($c['status']); ?></div>
 <div><b>Patient</b><?php echo e($c['patient_name'] !== '' ? $c['patient_name'] : '—'); ?></div>
 <div><b>Visit date</b><?php echo $c['visit_date'] ? e(date('d M Y', strtotime($c['visit_date']))) : '—'; ?></div>
 <div><b>Service period</b><?php echo e($c['period_label'] !== '' ? $c['period_label'] : '—'); ?></div>
</div>
<table><tr><th>Line</th><th>Category</th><th class="num">Unit price</th><th class="num">Qty</th><th class="num">Amount (UGX)</th></tr>
<?php $tot = 0.0; foreach ($items as $r): $v = round(((float)$r['price']) * ((float)$r['qty']), 2); $tot += $v; ?>
<tr><td><?php echo e($r['name']); ?></td><td><?php echo e($r['cat']); ?></td><td class="num"><?php echo number_format((float)$r['price'], 0); ?></td><td class="num"><?php echo rtrim(rtrim(number_format((float)$r['qty'], 2), '0'), '.'); ?></td><td class="num"><b><?php echo number_format($v, 0); ?></b></td></tr>
<?php endforeach; ?>
<tr><th colspan="4" class="num">Billed total</th><th class="num"><?php echo number_format($tot, 0); ?></th></tr>
<?php if ($c['amount_approved'] !== null): ?><tr><th colspan="4" class="num">Approved by owner</th><th class="num"><?php echo number_format((float)$c['amount_approved'], 0); ?></th></tr><?php endif; ?>
</table>
<?php if ($c['status'] === 'Paid'): $d = db()->prepare('SELECT * FROM disbursements WHERE claim_id=?'); $d->execute(array($id)); $dbt = $d->fetch(); ?>
<div class="grid">
 <div><b>Disbursed</b>UGX <?php echo number_format((float)$c['amount_approved'], 0); ?></div>
 <div><b>Method</b><?php echo e($c['pay_method']); ?></div>
 <div><b>Reference</b><?php echo e($c['pay_ref']); ?></div>
 <div><b>Paid on</b><?php echo e(date('d M Y H:i', strtotime($c['paid_at']))); ?></div>
 <?php if (!empty($c['invoice_no'])): ?><div><b>Facility invoice</b><?php echo e($c['invoice_no']); ?><?php echo !empty($c['invoice_date']) ? ' dated ' . e(date('d M Y', strtotime($c['invoice_date']))) : ''; ?><?php echo !empty($c['invoice_amount']) ? ' · UGX ' . e(number_format((float)$c['invoice_amount'], 0)) : ''; ?></div><?php endif; ?>
 <div><b>Released by</b><?php echo e($c['paid_by']); ?><?php echo $dbt && $dbt['note'] !== '' ? ' — ' . e($dbt['note']) : ''; ?></div>
</div>
<?php elseif ($c['status'] === 'Rejected' && $c['reject_reason'] !== ''): ?>
<p><b>Owner's note:</b> <?php echo e($c['reject_reason']); ?></p>
<?php endif; ?>
<?php if ($c['note'] !== ''): ?><p style="font-size:11.5px;color:#5b7c99"><b>Facility note:</b> <?php echo e($c['note']); ?></p><?php endif; ?>
<p style="font-size:10.5px;color:#9db8cf;margin-top:26px">Computer-generated receipt &middot; <?php echo e(date('d M Y H:i')); ?> &middot; MedSavu v3.9 multi-facility claims desk.</p>
</body></html>
