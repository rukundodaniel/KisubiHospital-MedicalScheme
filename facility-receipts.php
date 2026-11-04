<?php
/** v7.0 FACILITY RECEIPTS (spec §9 menu #8): searchable register of the facility's member settlement receipts. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_once __DIR__ . '/includes/facility70.php';
require_login();
f70_ensure();
$OWNER = fac_is_owner();
$fid = $OWNER ? intval(get('facility')) : fac_scope_fid();
if (!$fid) { $fl = fac_list(); $fid = $fl ? intval($fl[0]['id']) : 0; }
if ($fid > 0 && !$OWNER) fac_lock($fid);
$q = get('q'); $from = get('from'); $to = get('to');
$rows = array();
try {
    $w = "b.facility_id=? AND b.status='Settled' AND b.receipt_no<>''"; $P = array($fid);
    if ($q !== '') { $w .= ' AND (b.receipt_no LIKE ? OR b.bill_no LIKE ? OR b.member_no LIKE ? OR b.patient_name LIKE ?)'; for ($i = 0; $i < 4; $i++) $P[] = '%' . $q . '%'; }
    if ($from !== '') { $w .= ' AND DATE(b.settled_at)>=?'; $P[] = $from; }
    if ($to !== '') { $w .= ' AND DATE(b.settled_at)<=?'; $P[] = $to; }
    $st = db()->prepare("SELECT b.id, b.receipt_no, b.bill_no, b.member_no, b.patient_name, b.total, b.balance_before, b.balance_after, b.settled_at, b.settled_by, b.claim_id FROM member_bills b WHERE $w ORDER BY b.id DESC LIMIT 150");
    $st->execute($P); $rows = $st->fetchAll();
} catch (Throwable $e) {}
$page_title = 'Receipts';
include __DIR__ . '/includes/header.php';
?>
<div class="panel" style="padding:12px 16px">
  <h2 style="margin:0">Settlement receipts</h2>
  <div style="font-size:12px;color:#5c7a94;margin:2px 0 8px">Official member receipts raised by this facility. Reversed settlements stay in the register marked as reversed — financial records are never deleted.</div>
  <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0">
    <input type="hidden" name="facility" value="<?php echo $fid; ?>">
    <input name="q" value="<?php echo e($q); ?>" placeholder="receipt / bill / member no / name" style="flex:1 1 250px;padding:7px 10px;border:1px solid #c9d9e8;border-radius:5px">
    <input type="date" name="from" value="<?php echo e($from); ?>" style="padding:6px;border:1px solid #c9d9e8;border-radius:5px">
    <input type="date" name="to" value="<?php echo e($to); ?>" style="padding:6px;border:1px solid #c9d9e8;border-radius:5px">
    <button class="btn" type="submit">Search</button>
  </form>
</div>
<div class="panel">
<?php if (!$rows) echo '<p style="color:#5c7a94;font-size:13px">No receipts found for this filter.</p>'; else { ?>
<table class="data"><tr><th>Receipt</th><th>Bill</th><th>Member</th><th>Amount</th><th>Bal before → after</th><th>Settled</th><th>By</th><th>Claim</th><th></th></tr>
<?php foreach ($rows as $x) echo '<tr><td><b style="font-family:monospace">' . e($x['receipt_no']) . '</b></td><td style="font-family:monospace;font-size:12px">' . e($x['bill_no']) . '</td><td>' . e($x['patient_name']) . ' <span style="color:#7a94aa">' . e($x['member_no']) . '</span></td><td style="text-align:right;font-weight:bold">' . ugx((float)$x['total']) . '</td><td style="font-size:11px;color:#5c7a94;text-align:right">' . ugx((float)$x['balance_before']) . ' → ' . ugx((float)$x['balance_after']) . '</td><td>' . e(substr((string)$x['settled_at'], 0, 16)) . '</td><td>' . e($x['settled_by']) . '</td><td>' . (intval($x['claim_id']) ? '<a href="claim-view.php?id=' . intval($x['claim_id']) . '">' . intval($x['claim_id']) . '</a>' : '—') . '</td><td><a class="btn" style="text-decoration:none;font-size:11px;padding:4px 10px" href="receipt-member.php?id=' . intval($x['id']) . '">Print</a></td></tr>'; ?>
</table>
<?php } ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
