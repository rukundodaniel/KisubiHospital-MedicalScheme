<?php
/** v7.0 FACILITY DISBURSEMENT TRACKING (spec §8): read-only view of Kisubi payments to this facility. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_once __DIR__ . '/includes/clinical-core.php';
require_once __DIR__ . '/includes/facility70.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));
f70_ensure();
$OWNER = fac_is_owner();
$fid = $OWNER ? intval(get('facility')) : fac_scope_fid();
if (!$fid) { $fl = fac_list(); $fid = $fl ? intval($fl[0]['id']) : 0; }
if ($fid > 0 && !$OWNER) fac_lock($fid);
$r = get('r', 'year'); $from = get('from'); $to = get('to');
list($from, $to) = f70_range($r, $from, $to);
$mth = get('m'); $q = get('q');
$f = fac_of($fid);
$tot = array('approved' => 0, 'cleared' => 0, 'paid' => 0, 'outstanding' => 0, 'part' => 0, 'unpaid' => 0);
try {
    $st = db()->prepare("SELECT status, COALESCE(amount_approved, amount_billed) ap, COALESCE((SELECT SUM(CASE WHEN reversed=0 THEN amount ELSE -amount END) FROM disbursements d WHERE d.claim_id=facility_claims.id),0) pd FROM facility_claims WHERE facility_id=? AND status IN ('Approved','Partially Approved','Cleared','Scheduled','PartPaid','Paid')");
    $st->execute(array($fid));
    foreach ($st->fetchAll() as $c) {
        $tot['approved'] += (float)$c['ap'];
        if ($c['status'] === 'Paid') $tot['paid'] += (float)$c['pd'];
        elseif ($c['status'] === 'PartPaid') { $tot['paid'] += (float)$c['pd']; $tot['part']++; }
        elseif ($c['status'] === 'Cleared' || $c['status'] === 'Scheduled') { $tot['cleared'] += (float)$c['ap'] - (float)$c['pd']; if ((float)$c['pd'] > 0) $tot['part']++; }
        else $tot['unpaid']++;
    }
    $tot['outstanding'] = $tot['approved'] - $tot['paid'];
} catch (Throwable $e) {}
$pays = array(); $sched = array();
try {
    $w = 'd.facility_id=? AND DATE(d.paid_at) BETWEEN ? AND ?'; $P = array($fid, $from, $to);
    if ($mth !== '') { $w .= ' AND UPPER(d.method) LIKE ?'; $P[] = '%' . strtoupper($mth) . '%'; }
    if ($q !== '') { $w .= ' AND (d.reference LIKE ? OR d.claim_no LIKE ? OR d.voucher_no LIKE ?)'; $P[] = '%' . $q . '%'; $P[] = '%' . $q . '%'; $P[] = '%' . $q . '%'; }
    $st = db()->prepare("SELECT d.* FROM disbursements d WHERE $w AND d.reversed=0 ORDER BY d.paid_at DESC LIMIT 200"); $st->execute($P); $pays = $st->fetchAll();
    $st = db()->prepare("SELECT s.* FROM disb_schedules s WHERE s.facility_id=? AND s.status='Scheduled' AND s.sched_date>=? ORDER BY s.sched_date DESC LIMIT 50"); $st->execute(array($fid, date('Y-m-d'))); $sched = $st->fetchAll();
} catch (Throwable $e) {}
$page_title = 'Disbursement Tracking';
include __DIR__ . '/includes/header.php';
$QP = 'facility=' . $fid . '&r=' . rawurlencode($r) . '&from=' . rawurlencode($from) . '&to=' . rawurlencode($to) . ($mth !== '' ? '&m=' . rawurlencode($mth) : '') . ($q !== '' ? '&q=' . rawurlencode($q) : '');
?>
<div class="panel" style="padding:12px 16px">
  <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between">
    <div><h2 style="margin:0">Disbursement tracking — <?php echo e($f ? $f['name'] : ''); ?></h2>
    <div style="font-size:12px;color:#5c7a94">Payments are recorded exclusively by Kisubi Finance. A <b>scheduled</b> line is a plan, not money received; only <b>Paid</b> lines count.</div></div>
    <div style="display:flex;gap:4px;flex-wrap:wrap">
      <?php foreach (array('today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year') as $k => $lbl): ?><a href="facility-disbursements.php?r=<?php echo $k; ?>&facility=<?php echo $fid; ?>" style="text-decoration:none;font:bold 11px 'Segoe UI',Arial;padding:5px 10px;border-radius:4px;<?php echo $r === $k ? 'background:#1B6BB3;color:#fff' : 'background:#EAF2FA;color:#12476f' ?>"><?php echo $lbl; ?></a><?php endforeach; ?>
      <a href="facility-disbursements.php?<?php echo e($QP); ?>&print=1" class="btn noprint" style="text-decoration:none;padding:5px 10px;font-size:11px">&#128424; Print statement</a>
    </div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:center">
    <form method="get" action="facility-disbursements.php" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
      <input type="hidden" name="facility" value="<?php echo $fid; ?>">
      <select name="m" onchange="this.form.submit()" style="padding:6px;border:1px solid #c9d9e8;border-radius:4px">
        <?php foreach (array('' => 'All methods', 'BANK' => 'Bank transfer', 'CASH' => 'Cash', 'MOMO' => 'Mobile money') as $k => $lbl): ?><option value="<?php echo e($k); ?>" <?php echo $mth === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option><?php endforeach; ?>
      </select>
      <input name="q" value="<?php echo e($q); ?>" placeholder="reference, claim no, voucher" style="padding:6px 9px;border:1px solid #c9d9e8;border-radius:4px;width:210px">
      <input type="hidden" name="r" value="<?php echo e($r); ?>"><input type="date" name="from" value="<?php echo e($from); ?>" style="padding:5px;border:1px solid #c9d9e8;border-radius:4px"><input type="date" name="to" value="<?php echo e($to); ?>" style="padding:5px;border:1px solid #c9d9e8;border-radius:4px">
      <button class="btn" type="submit" style="padding:5px 10px;font-size:11px">Apply</button>
    </form>
  </div>
</div>
<div style="display:flex;flex-wrap:wrap;margin-bottom:8px">
<?php foreach (array('Approved claims total' => ugx($tot['approved']), 'Cleared, awaiting payment' => ugx($tot['cleared']), 'Disbursed (confirmed)' => ugx($tot['paid']),
                     'Outstanding' => ugx($tot['outstanding']), 'Partially paid claims' => number_format($tot['part']), 'Approved, unpaid claims' => number_format($tot['unpaid'])) as $k => $v): ?>
  <div style="flex:1 1 200px;background:#fff;border:1px solid #dfe9f2;border-top:3px solid #0f766e;border-radius:6px;padding:9px 12px;margin:0 10px 8px 0">
    <div style="font:bold 9px 'Segoe UI',Arial;letter-spacing:.6px;color:#5c7a94;text-transform:uppercase"><?php echo e($k); ?></div>
    <div style="font:bold 18px Georgia,serif;color:#12476f;margin-top:2px"><?php echo e($v); ?></div></div>
<?php endforeach; ?>
</div>
<?php if ($sched): ?>
<div class="panel" style="border-left:4px solid #8a6410"><h3 style="margin:0 0 6px;font-size:13px;color:#8a6410">Scheduled by Kisubi — not yet paid (<?php echo count($sched); ?>)</h3>
<table class="data"><tr><th>Schedule</th><th>Claim</th><th>Amount</th><th>Planned date</th><th>Method (planned)</th><th>Note</th></tr>
<?php foreach ($sched as $s) echo '<tr><td>' . e($s['sched_no']) . '</td><td>' . e($s['claim_no']) . '</td><td><b>' . ugx((float)$s['amount']) . '</b></td><td>' . e($s['sched_date']) . '</td><td>' . e($s['method'] !== '' ? $s['method'] : 'to be set at release') . '</td><td style="font-size:11px;color:#5c7a94">' . e($s['note']) . '</td></tr>'; ?>
</table><p style="font-size:11px;color:#8a6410;margin:6px 0 0">Scheduled rows become "disbursed" only when Kisubi Finance confirms an actual payment. Facilities cannot create, edit or confirm these.</p></div>
<?php endif; ?>
<div class="panel"><h3 style="margin:0 0 8px;font-size:13px;color:#12476f">Payments received <?php echo e($from); ?> → <?php echo e($to); ?> (<?php echo count($pays); ?>)</h3>
<?php if (!$pays) echo '<p style="color:#5c7a94;font-size:13px">No confirmed payments in this period.</p>'; else { ?>
<table class="data"><tr><th>Date</th><th>Voucher</th><th>Claim</th><th>Amount</th><th>Method</th><th>Reference</th><th>Confirmed by</th><th></th></tr>
<?php foreach ($pays as $d) {
    $det = '';
    if (f70_colhas('disb_paydetails', 'disbursement_id')) { try { $st = db()->prepare('SELECT * FROM disb_paydetails WHERE disbursement_id=?'); $st->execute(array(intval($d['id']))); $det = $st->fetch(); } catch (Throwable $e) {} }
    $ch = array();
    if ($det) {
        if (isset($det['bank_name']) && $det['bank_name'] !== '') $ch[] = 'Bank ' . e($det['bank_name']) . ' a/c ' . e($det['bank_account']);
        if (isset($det['mm_provider']) && $det['mm_provider'] !== '') $ch[] = e($det['mm_provider']) . ' ' . e($det['mm_phone']) . ($det['confirmation'] ? ' · ' . e($det['confirmation']) : '');
        if (isset($det['rec_name']) && $det['rec_name'] !== '') $ch[] = 'Cash to ' . e($det['rec_name']) . ($det['rec_id'] !== '' ? ' (ID ' . e($det['rec_id']) . ')' : '');
        if (isset($det['proof_name']) && $det['proof_name'] !== '') $ch[] = '<a href="claim-doc.php?disb=' . intval($d['id']) . '">proof</a>';
    }
    echo '<tr><td>' . e(substr((string)$d['paid_at'], 0, 16)) . '</td><td>' . e($d['voucher_no']) . '</td><td><b>' . e($d['claim_no']) . '</b></td><td><b style="color:#20603a">' . ugx((float)$d['amount']) . '</b></td><td>' . e($d['method']) . '</td><td style="font-family:monospace;font-size:12px">' . e($d['reference']) . '</td><td>' . e($d['paid_by']) . '</td>'
       . '<td style="font-size:11px;color:#5c7a94;text-align:right">' . ($ch ? implode(' · ', $ch) : (f70_colhas('disbursements', 'batch_id') && intval($d['batch_id']) > 0 ? 'batch payment' : '')) . ' <a href="disbursement-voucher.php?id=' . intval($d['id']) . '" style="text-decoration:none">&nbsp;&#128424;</a></td></tr>';
} echo '</table>'; } ?>
<p style="font-size:11px;color:#7a94aa;margin:8px 0 0">Reversals by Kisubi Finance reduce these totals automatically. Facility users have read-only access here — creating or editing Kisubi payments is a central-administrator function and is blocked at the server.</p></div>
<?php if (get('print')) echo '<script>window.print();</script>'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
