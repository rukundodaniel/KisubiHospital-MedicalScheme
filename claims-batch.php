<?php
/** v6.0 SUBMIT CLAIM TO KISUBI HOSPITAL — generate a claims schedule either from selected
 *  settled member bills or from a date range, with funding allocation per line (member
 *  deposit vs Kisubi payable vs other payer). Bills already claimed can never be picked
 *  again (claim_lines UNIQUE source guard). Drafts preview, print and export. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));
if (!mb_require_tbl()) { include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/footer.php'; exit; }
$OWNER = fac_is_owner();
$lockedFid = fac_scope_fid();
$GF = intval(get('facility'));
if ($lockedFid > 0) $GF = $lockedFid; else $GF = fac_lock($GF);
if ($GF <= 0) { echo '<meta http-equiv="refresh" content="0;url=index.php">'; exit; }
$fac = fac_of($GF);
$fundingModel = trim((string)$fac['deposit_funding']) === 'Kisubi' ? 'Kisubi' : 'Self';

/* ---------- rebuild preview rows from a selection (shared by render + export) ---------- */
function cb_rows($fid, $ids, $from, $to, $mid, $cat) {
    if ($ids) return mb_claimable($fid, '', '', 0, '', $ids);
    return mb_claimable($fid, $from, $to, $mid, $cat);
}
/** documents (export / print) read the bills regardless of claim locks */
function cb_doc_rows($fid, $ids) {
    $ids = array(); foreach (explode(',', (string)implode(',', (array)$ids)) as $v) if (intval($v) > 0) $ids[] = intval($v);
    return mb_bills_raw($fid, $ids);
}

/* ---------- exports (authorized, read-only) ---------- */
if (get('exp') === 'csv' || get('exp') === 'xls') {
    $ids = array(); foreach (explode(',', (string)get('ids')) as $v) if (intval($v) > 0) $ids[] = intval($v);
    $rows = mb_bills_raw($GF, $ids);
    if (!$rows) die('Nothing to export.');
    $tag = $fac['code'] . '-' . date('Ymd-His');
    if (get('exp') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="claim-schedule-' . $tag . '.csv"');
        $o = fopen('php://output', 'w');
        fputcsv($o, array('Claim batch', 'Facility', 'Facility ID', 'Member no', 'Member name', 'Bill ref', 'Service', 'Category', 'Service date', 'Qty', 'Facility price', 'Bill total', 'Applicable coverage', 'Member-funded', 'Payer-funded', 'Requested from Kisubi', 'Funding'));
        foreach ($rows as $b) fputcsv($o, array((string)get('batch'), $fac['name'], $GF, $b['member_no'], $b['patient_name'], $b['bill_no'], 'Complete bill (' . count(mb_lines(intval($b['id']))) . ' lines)', '', $b['bill_date'], 1, $b['total'], $b['total'], $b['fund']['coverage'], $b['fund']['member_funded'], $b['fund']['payer_funded'], $b['fund']['requested'], $b['fund']['funding']));
        fclose($o); exit;
    }
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="claim-schedule-' . $tag . '.xls"');
    echo '<html><head><meta charset="utf-8"></head><body><table border="1"><tr><th>Bill ref</th><th>Member</th><th>Bill date</th><th>Bill total</th><th>Coverage</th><th>Member-funded</th><th>Payer-funded</th><th>Requested from Kisubi</th><th>Funding</th></tr>';
    foreach ($rows as $b) echo '<tr><td>' . e($b['bill_no']) . '</td><td>' . e($b['patient_name']) . '</td><td>' . e($b['bill_date']) . '</td><td>' . (float)$b['total'] . '</td><td>' . (float)$b['fund']['coverage'] . '</td><td>' . (float)$b['fund']['member_funded'] . '</td><td>' . (float)$b['fund']['payer_funded'] . '</td><td>' . (float)$b['fund']['requested'] . '</td><td>' . e($b['fund']['funding']) . '</td></tr>';
    echo '</table></body></html>'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'cancel_claim') {
        $cid = intval(post('id'));
        $st = db()->prepare("SELECT * FROM facility_claims WHERE id=? AND status='Draft' AND facility_id=?"); $st->execute(array($cid, $GF)); $c = $st->fetch();
        if (!$c) { flash('Only your own draft claims can be removed.', 'err'); redirect('claims-batch.php?facility=' . $GF); }
        $rel = mb_claim_release($cid);
        db()->prepare('DELETE FROM facility_claims WHERE id=?')->execute(array($cid));
        log_action('Draft batch claim ' . $c['claim_no'] . ' discarded — ' . $rel . ' bill(s) released back to the eligible pool', 'facility_claims', $cid);
        flash('Draft discarded — ' . $rel . ' bill(s) are claimable again.', 'ok'); redirect('claims-batch.php?facility=' . $GF);
    }
    if ($op === 'reclaim') {
        $cid = intval(post('id'));
        $st = db()->prepare("SELECT * FROM facility_claims WHERE id=? AND status='Returned' AND facility_id=?"); $st->execute(array($cid, $GF)); $c = $st->fetch();
        if (!$c) { flash('Only claims returned for correction can be rebuilt.', 'err'); redirect('claims-batch.php?facility=' . $GF); }
        $rel = mb_claim_release($cid);
        log_action('Returned claim ' . $c['claim_no'] . ' opened for rebuild — ' . $rel . ' bill(s) released', 'facility_claims', $cid);
        flash('Bill lines released — re-select the eligible bills below and submit the corrected claim.', 'info');
        redirect('claims-batch.php?facility=' . $GF . '&view=new');
    }
    $sel = array(); foreach (explode(',', (string)post('ids')) as $v) if (intval($v) > 0) $sel[] = intval($v);
    $rows = array(); $from = post('from'); $to = post('to'); $midSel = intval(post('member_id')); $cat = trim((string)post('cat'));
    if ($op === 'build' || $op === 'buildrange') {
        $rows = cb_rows($GF, $op === 'build' ? $sel : array(), $from, $to, $midSel, $cat);
        $built = $rows;
    } else {
        // draft/submit — re-validate the posted selection server-side
        $rows = cb_rows($GF, $sel, '', '', 0, '');
        $r = mb_make_claim($GF, $rows, array('note' => post('note'), 'period' => post('period') !== '' ? post('period') : date('M Y'),
            'member_id' => $midSel, 'patient' => post('patient'), 'batch_label' => 'CB-' . date('Y') . '-' . substr(md5($GF . microtime()), 0, 4)), $op === 'savedraft');
        if ($r['ok']) { flash(e($r['msg']), 'ok'); redirect($op === 'savedraft' ? 'claims-batch.php?facility=' . $GF . '&view=list' : 'facility-claims.php' . ($lockedFid > 0 ? '' : '?facility=' . $GF)); }
        flash(e($r['msg']), 'err'); redirect('claims-batch.php?facility=' . $GF . '&view=new');
    }
}
$printMode = get('print') === '1';
if ($printMode && !isset($built)) { // printable view reached directly via link
    $idsP = array(); foreach (explode(',', (string)get('ids')) as $v) if (intval($v) > 0) $idsP[] = intval($v);
    $built = mb_bills_raw($GF, $idsP);
}

$page_title = 'Submit Claim to Kisubi Hospital';
include __DIR__ . '/includes/header.php';
$eligN = 0; try { $eligN = count(mb_claimable($GF, '', '', 0, '', null)); } catch (Exception $e) {}
?>
<div class="page-head"><h1>&#128230; SUBMIT CLAIM TO KISUBI HOSPITAL</h1>
 <span class="sub">Generate a claims schedule from settled member bills — Kisubi Hospital only owes what the member&rsquo;s own pre-funded deposits do not already cover.</span>
 <span style="float:right;display:flex;gap:8px"><a class="btn small" href="claims-batch.php?facility=<?php echo $GF ?>&view=new">&#10133; New claim</a>
 <a class="btn small plain" href="claims-batch.php?facility=<?php echo $GF ?>&view=list">Claims &amp; tracking</a>
 <a class="btn small plain" href="facility-claims.php<?php echo $lockedFid > 0 ? '' : '?facility=' . $GF ?>">Claim review desk &#8599;</a></span>
</div>
<div class="panel" style="border-left:6px solid <?php echo $fundingModel === 'Kisubi' ? '#1e7f3c' : '#547086'; ?>">
 <b>Funding model for <?php echo e($fac['name']); ?>:</b>
 <?php if ($fundingModel === 'Kisubi'): ?><span style="color:#1e7f3c;font-weight:bold">Kisubi pre-funded deposits</span> — services consumed from member deposits ARE claimable and reconcile against the central pre-funding account.
 <?php else: ?><span style="color:#12476f;font-weight:bold">Member self-funded deposits</span> — amounts deducted from members&rsquo; own deposits are <u>not</u> claimable (that money is the members&rsquo;, not Kisubi&rsquo;s). Only bills marked as payable by an approved third-party payer flow to claims. <?php endif;
 if (!$lockedFid > 0): ?> &middot; the scheme owner can change this under Hospitals &amp; Clinics.<?php endif; ?>
 &middot; <b><?php echo num($eligN); ?></b> settled bill(s) currently eligible to claim.
</div>
<?php if (get('view') === 'list' || get('view') === false): ?>
<div class="panel"><h2 style="margin-top:0">&#128201; My claims — live status tracking (no phone calls needed)</h2>
 <table class="tbl"><tr><th>Claim no</th><th>Batch</th><th>Period</th><th style="text-align:right">Requested</th><th style="text-align:right">Approved</th><th style="text-align:right">Paid</th><th style="text-align:right">Outstanding</th><th>Status</th><th>Submitted</th><th></th></tr>
<?php
$st = db()->prepare("SELECT * FROM facility_claims WHERE facility_id=? ORDER BY id DESC LIMIT 80"); $st->execute(array($GF)); $claims = $st->fetchAll();
if (!$claims) echo '<tr><td colspan="10" style="color:#7a94aa">No claims yet — build one from settled bills.</td></tr>';
foreach ($claims as $c): $appr = $c['amount_approved'] !== null ? (float)$c['amount_approved'] : 0.0; $paid = claim_paid_total(intval($c['id'])); $outst = max(0, $appr - $paid); ?>
 <tr><td><b><?php echo e($c['claim_no']); ?></b></td><td><?php echo e($c['batch_label'] ?: '—'); ?></td><td><?php echo e($c['period_label']); ?></td>
  <td style="text-align:right"><?php echo num($c['amount_billed']); ?></td><td style="text-align:right"><?php echo $appr > 0 ? num($appr) : '—'; ?></td>
  <td style="text-align:right"><?php echo $paid > 0 ? num($paid) : '—'; ?></td><td style="text-align:right"><?php echo $appr > 0 && $outst > 0 ? '<b style="color:#a33">' . num($outst) . '</b>' : '—'; ?></td>
  <td><span style="font-weight:bold;color:<?php echo in_array($c['status'], array('Paid', 'Fully'), true) ? '#1e7f3c' : ($c['status'] === 'Rejected' ? '#a33' : '#8a6410'); ?>"><?php echo e(cl_slabel($c['status'])); ?></span></td>
  <td><?php echo e(substr((string)$c['submitted_at'], 0, 10) ?: '—'); ?></td>
  <td style="white-space:nowrap"><a class="btn small plain" href="claim-view.php?id=<?php echo intval($c['id']); ?>">Open</a>
   <?php if ($c['status'] === 'Draft'): ?><form method="post" style="display:inline" onsubmit="return confirm('Discard this draft? Bill lines are released for re-claiming.')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="cancel_claim"><input type="hidden" name="id" value="<?php echo intval($c['id']); ?>"><button class="btn small plain" type="submit">&#10006;</button></form><?php endif; ?>
   <?php if ($c['status'] === 'Returned'): ?><form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="reclaim"><input type="hidden" name="id" value="<?php echo intval($c['id']); ?>"><button class="btn small" type="submit">&#8617; Rebuild</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></table>
</div>
<?php elseif (isset($built) && $built): ?>
<?php // ---------------- SCHEDULE PREVIEW ----------------
$sumReq = 0.0; $sumMem = 0.0; $sumPay = 0.0; $sumBilled = 0.0;
foreach ($built as $b) { $sumReq += (float)$b['fund']['requested']; $sumMem += (float)$b['fund']['member_funded']; $sumPay += (float)$b['fund']['payer_funded']; $sumBilled += (float)$b['total']; }
$idsCsv = implode(',', array_map('intval', array_map(function ($b) { return $b['id']; }, $built)));
?>
<div class="panel"><h2 style="margin-top:0">&#128203; Claims schedule preview — <?php echo count($built); ?> bill(s), <?php echo e($fac['name']); ?></h2>
 <table class="tbl"><tr><th>Bill ref</th><th>Member</th><th>Service date</th><th>Services (from facility price list)</th><th style="text-align:right">Facility prices</th><th style="text-align:right">Coverage applicable</th><th style="text-align:right">Member-funded</th><th style="text-align:right">Other payer</th><th style="text-align:right">Requested from Kisubi</th><th>Funding</th></tr>
 <?php foreach ($built as $b): ?>
 <tr><td><b><?php echo e($b['bill_no']); ?></b><?php if ($b['receipt_no'] !== ''): ?><br><span class="sub">receipt <?php echo e($b['receipt_no']); ?></span><?php endif; ?></td>
  <td><?php echo e($b['patient_name']); ?><br><span class="sub"><?php echo e($b['member_no']); ?></span></td><td><?php echo e($b['bill_date']); ?></td>
  <td><?php $ls = mb_lines(intval($b['id'])); foreach ($ls as $l) echo e($l['item_name']) . ' ×' . e($l['qty']) . '<br>'; ?></td>
  <td style="text-align:right"><?php echo num($b['total']); ?></td><td style="text-align:right;color:#1e7f3c"><?php echo num($b['fund']['coverage']); ?></td>
  <td style="text-align:right"><?php echo num($b['fund']['member_funded']); ?></td><td style="text-align:right"><?php echo num($b['fund']['payer_funded']); ?></td>
  <td style="text-align:right"><b><?php echo num($b['fund']['requested']); ?></b></td><td><span class="sub"><?php echo e($b['fund']['funding']); ?></span></td></tr>
 <?php endforeach; ?>
 <tr class="tot" style="background:#EAF2FA;font-weight:bold"><td colspan="4">TOTAL — amount requested from Kisubi Hospital</td><td style="text-align:right"><?php echo num($sumBilled); ?></td><td style="text-align:right"><?php echo num($sumReq); ?></td><td style="text-align:right"><?php echo num($sumMem); ?></td><td style="text-align:right"><?php echo num($sumPay); ?></td><td style="text-align:right"><?php echo num($sumReq); ?></td><td></td></tr></table>
 <p class="sub">Supporting documents (invoices, receipts) attach on the review desk after submission — every listed bill already carries a system receipt on file.</p>
<?php if ($printMode): ?><script>window.print()</script><?php endif; ?>
<?php if (!$printMode): ?>
 <form method="post" style="margin-top:10px">
  <?php echo csrf_field(); ?><input type="hidden" name="op" value="submit"><input type="hidden" name="ids" value="<?php echo e($idsCsv); ?>">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
   <div><label class="f">Period covered (label)</label><input class="f" name="period" value="<?php echo e(date('M Y')); ?>"></div>
   <div><label class="f">Primary member (optional)</label><input class="f" name="patient" value="<?php echo count($built) === 1 ? e($built[0]['patient_name']) : ''; ?>"></div>
   <div style="grid-column:1/-1"><label class="f">Note to the claims officer</label><input class="f" name="note" placeholder="context, document references, urgent cases…"></div>
  </div>
  <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
   <button class="btn" type="submit" name="op" value="savedraft">&#128221; Save as draft</button>
   <button class="btn primary" type="submit" name="op" value="submit" onclick="this.disabled=true;this.form.submit()">&#128640; SUBMIT CLAIM TO KISUBI HOSPITAL</button>
   <a class="btn plain" href="claims-batch.php?facility=<?php echo $GF; ?>">&larr; Adjust selection</a>
   <a class="btn plain" href="claims-batch.php?facility=<?php echo $GF ?>&print=1&ids=<?php echo e($idsCsv); ?>" target="_blank">&#128424; Print schedule</a>
   <a class="btn plain" href="claims-batch.php?facility=<?php echo $GF ?>&exp=csv&ids=<?php echo e($idsCsv); ?>">&#8615; CSV</a>
   <a class="btn plain" href="claims-batch.php?facility=<?php echo $GF ?>&exp=xls&ids=<?php echo e($idsCsv); ?>">&#8615; Excel</a>
  </div>
 </form>
<?php endif; ?>
</div>
<?php elseif (isset($built)): ?>
 <div class="flash flash-err">No eligible bills matched that selection — bills must be <b>Settled</b>, not reversed, not already claimed, and payable under the facility&rsquo;s funding model.</div>
<?php else: ?>
<div class="panel"><h2 style="margin-top:0">&#9312; Method A — pick settled bills</h2>
 <form method="post"><input type="hidden" name="op" value="build"><?php echo csrf_field(); ?>
  <table class="tbl"><tr><th></th><th>Bill</th><th>Member</th><th>Date</th><th style="text-align:right">Total</th><th style="text-align:right">Claimable (Kisubi)</th><th>Funding</th></tr>
  <?php $el = mb_claimable($GF, '', '', 0, '');
  if (!$el) echo '<tr><td colspan="7" style="color:#7a94aa">Nothing claimable right now. Settle bills first — and remember: self-funded consumption only becomes claimable when the scheme owner marks your deposits as Kisubi pre-funded.</td></tr>';
  foreach ($el as $b): ?>
   <tr><td><input type="checkbox" class="pick" value="<?php echo intval($b['id']); ?>"></td>
    <td><b><?php echo e($b['bill_no']); ?></b><br><span class="sub"><?php echo e($b['receipt_no'] ?: '—'); ?></span></td>
    <td><?php echo e($b['patient_name']); ?></td><td><?php echo e($b['bill_date']); ?></td>
    <td style="text-align:right"><?php echo num($b['total']); ?></td><td style="text-align:right;color:#1e7f3c"><b><?php echo num($b['fund']['requested']); ?></b></td>
    <td><span class="sub"><?php echo e($b['fund']['funding']); ?></span></td></tr>
  <?php endforeach; ?>
  </table>
  <p style="margin-top:8px"><button class="btn primary" type="submit" onclick="return pickIds(this.form)">Build schedule from ticked bills &#10145;</button></p>
  <script>function pickIds(form){var ids=[];form.querySelectorAll('.pick:checked').forEach(function(c){ids.push(c.value)});if(!ids.length){alert('Tick at least one bill first.');return false}
   var h=document.createElement('input');h.type='hidden';h.name='ids';h.value=ids.join(',');form.appendChild(h);return true}</script>
 </form>
 <h2 style="margin:18px 0 6px">&#9313; Method B — whole period (date range)</h2>
 <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end"><?php echo csrf_field(); ?><input type="hidden" name="op" value="buildrange">
  <div><label class="f">Start date</label><input class="f" type="date" name="from" style="margin:0"></div>
  <div><label class="f">End date</label><input class="f" type="date" name="to" style="margin:0"></div>
  <div><label class="f">Member id (0 = all)</label><input class="f" type="number" name="member_id" value="0" min="0" style="margin:0;width:110px"></div>
  <div><label class="f">Service category</label><input class="f" name="cat" placeholder="e.g. Consultation" style="margin:0;width:160px"></div>
  <button class="btn" type="submit">Build schedule for the range &#10145;</button></form>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
