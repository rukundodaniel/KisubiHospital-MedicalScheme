<?php
/** v4.1 Disbursement manager — the owner's payment office for the whole network: outstanding
 *  balances per facility, cleared claims queue, payment batches, full or partial payments with
 *  bank / mobile-money details, printed vouchers, facility payment statements and authorised
 *  reversals. Every payment posts the double-entry journal and the claim's own history. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/clinical-core.php';
require_role(array('Super Admin'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9
if (!fac_has_tbl()) { $page_title = 'Disbursements'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>Run <a href="migrate.php">migrate.php</a> first</h2></div>'; include __DIR__ . '/includes/footer.php'; exit; }
$by = $_SESSION['uname'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'batch') {
        if (!cl_tbl('disb_batches')) { flash('Batches need the v4.1 schema — run migrate.php once.', 'err'); redirect('disbursements-manager.php'); }
        $title = trim(post('title')); if ($title === '') $title = 'Batch ' . date('d M Y');
        db()->prepare('INSERT INTO disb_batches (title, batch_date, method, reference, note, status, created_by, created_at) VALUES (?,?,?,?,?,\'Open\',?,?)')
           ->execute(array(mb_substr($title, 0, 120), post('batch_date') !== '' ? post('batch_date') : today(), post('method'), trim(post('reference')), trim(post('note')), $by, date('Y-m-d H:i:s')));
        log_action('Opened disbursement batch "' . $title . '"', 'disb_batches', intval(db()->lastInsertId()));
        flash('Batch opened — pay claims into it below.', 'ok'); redirect('disbursements-manager.php');
    }
    if ($op === 'batch_close') {
        if (!cl_tbl('disb_batches')) redirect('disbursements-manager.php');
        db()->prepare("UPDATE disb_batches SET status='Completed' WHERE id=?")->execute(array(intval(post('id'))));
        flash('Batch closed.', 'info'); redirect('disbursements-manager.php');
    }
    if ($op === 'pay') {
        $cid = intval(post('claim_id'));
        $st = db()->prepare('SELECT * FROM facility_claims WHERE id=?'); $st->execute(array($cid)); $c = $st->fetch();
        if (!$c) { flash('Claim not found.', 'err'); redirect('disbursements-manager.php'); }
        if (!cl_can_disburse($c['status'])) { flash($c['claim_no'] . ' cannot be paid in state ' . cl_slabel($c['status']) . ' — approve and clear it first.', 'err'); redirect('disbursements-manager.php'); }
        $appr = $c['amount_approved'] !== null ? (float)$c['amount_approved'] : (float)$c['amount_billed'];
        $paid = claim_paid_total($cid); $out = max(0, $appr - $paid);
        if ($out <= 0.001) { flash('This claim is already settled — check for a duplicate payment.', 'err'); redirect('disbursements-manager.php'); }
        $amt = (float)str_replace(',', '', post('amount')); if ($post_amt_zero = ($amt <= 0)) $amt = $out;
        if ($amt > $out + 0.001) { flash('Maximum payable now is ' . ugx($out) . '.', 'err'); redirect('disbursements-manager.php'); }
        $method = post('method'); if (!in_array($method, array('Cash', 'Bank Transfer', 'MTN MoMo', 'Airtel Money'), true)) $method = 'Bank Transfer';
        $ref = trim(post('reference')); if ($ref === '') $ref = strtoupper(substr(md5($c['claim_no'] . microtime()), 0, 10));
        $dup = db()->prepare('SELECT id FROM disbursements WHERE claim_id=? AND reference=? AND amount>0'); $dup->execute(array($cid, $ref));
        if ($dup->fetch()) { flash('Reference ' . $ref . ' already exists on this claim — give each payment its own reference.', 'err'); redirect('disbursements-manager.php'); }
        $bid = cl_has('disbursements', 'batch_id') ? intval(post('batch_id')) : 0;
        $vno = cl_has('disbursements', 'voucher_no') ? cl_voucher_no() : '';
        $payDate = post('pay_date') !== '' ? post('pay_date') : date('Y-m-d');
        try {
            db()->beginTransaction();
            $insCols = 'claim_id, claim_no, facility_id, facility_name, amount, method, reference, paid_by, paid_at, note, created_at';
            $insVal  = '?,?,?,?,?,?,?,?,?,?,?';
            $insArgs = array($cid, $c['claim_no'], $c['facility_id'], $c['facility_name'], $amt, $method, $ref, $by, $payDate . ' ' . date('H:i:s'), trim(post('details')), date('Y-m-d H:i:s'));
            if (cl_has('disbursements', 'batch_id')) { $insCols .= ', batch_id'; $insVal .= ',?'; $insArgs[] = $bid; }
            if (cl_has('disbursements', 'voucher_no')) { $insCols .= ', voucher_no'; $insVal .= ',?'; $insArgs[] = $vno; }
            db()->prepare('INSERT INTO disbursements (' . $insCols . ') VALUES (' . $insVal . ')')->execute($insArgs);
            $did = intval(db()->lastInsertId());
            if ($out - $amt <= 0.001 && cl_has('facility_claims', 'paid_at')) db()->prepare("UPDATE facility_claims SET paid_at=?, paid_by=?, pay_method=?, pay_ref=? WHERE id=?")->execute(array($payDate . ' ' . date('H:i:s'), $by, $method, $ref, $cid));
            elseif (cl_has('facility_claims', 'pay_method')) db()->prepare("UPDATE facility_claims SET pay_method=?, pay_ref=? WHERE id=?")->execute(array($method, $ref, $cid));
            $newst = claim_sync_pay_state($cid);
            db()->commit();
            gl_post($payDate, array(array('2100', $amt, 0), array(acc_method_acct($method), 0, $amt)), 'Disbursement for ' . $c['claim_no'] . ' to ' . $c['facility_name'] . ' via ' . $method . ($vno !== '' ? ' [' . $vno . ']' : ''), 'claimpay', $cid, intval($c['facility_id']));
            claim_event($cid, 'Payment recorded', ugx($amt) . ' via ' . $method . ' ref ' . $ref . ($bid > 0 ? ' in batch #' . $bid : '') . ' — ' . cl_slabel($newst ?: $c['status']) . '.', $by);
            log_action('DISBURSED ' . ugx($amt) . ' to ' . $c['facility_name'] . ' (' . $c['claim_no'] . ') via ' . $method . ' ref ' . $ref, 'disbursements', $did);
            fac_notify(fac_of(intval($c['facility_id'])), 'PAYMENT: UGX ' . number_format($amt, 0) . ' for ' . $c['claim_no'] . ' via ' . $method . ' (ref ' . $ref . ')' . ($newst === 'PartPaid' ? '. Balance outstanding: UGX ' . number_format($out - $amt, 0) . '.' : '. Fully settled.'));
            flash('Paid ' . ugx($amt) . ' to ' . $c['facility_name'] . ($vno !== '' ? ' — voucher ' . $vno : '') . ($newst === 'PartPaid' ? ' · balance ' . ugx($out - $amt) . ' outstanding.' : ' — claim fully settled.'), 'ok');
        } catch (Exception $ex) { if (db()->inTransaction()) db()->rollBack(); flash('Payment failed: ' . $ex->getMessage(), 'err'); }
        redirect('disbursements-manager.php');
    }
    if ($op === 'reverse') {
        if (!cl_has('disbursements', 'reversed')) { flash('Reversals need the v4.1 schema — run migrate.php once.', 'err'); redirect('disbursements-manager.php'); }
        $did = intval(post('did'));
        $st = db()->prepare('SELECT * FROM disbursements WHERE id=?'); $st->execute(array($did)); $d = $st->fetch();
        if (!$d || intval($d['reversed']) === 1) { flash('Payment not found or already reversed.', 'err'); redirect('disbursements-manager.php'); }
        $why = trim(post('why')); if ($why === '') { flash('A reversal must carry a reason.', 'err'); redirect('disbursements-manager.php'); }
        try {
            db()->beginTransaction();
            db()->prepare('UPDATE disbursements SET reversed=1, reverse_reason=? WHERE id=?')->execute(array(mb_substr($why, 0, 200), $did));
            $newst = claim_sync_pay_state(intval($d['claim_id']));
            db()->commit();
            gl_post(today(), array(array(acc_method_acct($d['method']), (float)$d['amount'], 0), array('2100', 0, (float)$d['amount'])), 'REVERSAL of payment #' . $did . ' for ' . $d['claim_no'] . ' — ' . mb_substr($why, 0, 80), 'claimreverse', intval($d['claim_id']), intval($d['facility_id']));
            claim_event(intval($d['claim_id']), 'Disbursement reversed', 'Payment #' . $did . ' of ' . ugx((float)$d['amount']) . ' reversed: ' . $why, $by);
            log_action('Reversed disbursement #' . $did . ' (' . ugx((float)$d['amount']) . ', ' . $d['claim_no'] . '): ' . mb_substr($why, 0, 120), 'disbursements', $did);
            flash('Payment #' . $did . ' reversed — journal and claim state updated.', 'info');
        } catch (Exception $ex) { if (db()->inTransaction()) db()->rollBack(); flash('Reversal failed: ' . $ex->getMessage(), 'err'); }
        redirect('disbursements-manager.php');
    }
}

/* ---------- data ---------- */
$k = db()->query("SELECT
  COALESCE(SUM(CASE WHEN status IN ('Approved','Partially Approved') THEN COALESCE(amount_approved, amount_billed) END),0) appr,
  COALESCE(SUM(CASE WHEN status IN ('Cleared','PartPaid') THEN COALESCE(amount_approved, amount_billed) END),0) clramt,
  COUNT(CASE WHEN status IN ('Cleared','PartPaid') THEN 1 END) clrn,
  COUNT(CASE WHEN status IN ('Approved','Partially Approved') THEN 1 END) apprN
  FROM facility_claims")->fetch();
$extra = cl_has('disbursements', 'reversed') ? ' WHERE reversed=0' : '';
$paidTot = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM disbursements' . $extra)->fetchColumn();
$outstTot = max(0.0, (float)$k['appr'] + (float)$k['clramt'] - $paidTot);

$queue = db()->query("SELECT * FROM facility_claims WHERE status IN ('Approved','Partially Approved','Cleared','PartPaid') ORDER BY (status='Cleared') DESC, id DESC LIMIT 80")->fetchAll();
$facRows = array();
foreach (fac_list() as $fo) {
    $s = cl_fac_stats(intval($fo['id']));
    if ($s['pending'] > 0 || $s['outstanding'] > 0 || $s['paid'] > 0 || $s['claims'] > 0) $facRows[] = array('f' => $fo, 's' => $s);
}
$recent = db()->query('SELECT d.*' . (cl_has('disbursements','reversed') ? ', d.reversed, d.reverse_reason' : '') . (cl_has('disbursements','voucher_no') ? ', d.voucher_no' : '') . ' FROM disbursements d ORDER BY d.id DESC LIMIT 40')->fetchAll();
$batches = disb_batches();
$openB = 0; foreach ($batches as $b) if ($b['status'] === 'Open') $openB++;

$page_title = 'Disbursement Manager';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><h1>&#128181; Disbursement Manager</h1>
    <span class="sub">Payments to hospitals, clinics and health centres — every transfer here becomes a voucher, a journal entry and a facility notification.</span></div>
  <div><a class="btn plain" href="facility-claims.php">&#9878;&#65039; Owner claims desk</a>
   <a class="btn plain" href="accounting.php?tab=bank">Bank reconciliation</a></div>
</div>
<div class="kpi-row">
  <div class="kpi"><div class="k-label">Approved, not yet cleared</div><div class="k-value"><?php echo ugx($k['appr']); ?></div><div class="k-sub"><?php echo num($k['apprN']); ?> claim(s)</div></div>
  <div class="kpi gold"><div class="k-label">Cleared awaiting payment</div><div class="k-value"><?php echo ugx($k['clramt']); ?></div><div class="k-sub"><?php echo num($k['clrn']); ?> claim(s) · <?php echo num($openB); ?> open batch(es)</div></div>
  <div class="kpi green"><div class="k-label">Disbursed all-time</div><div class="k-value"><?php echo ugx($paidTot); ?></div><div class="k-sub">excluding reversals</div></div>
  <div class="kpi red"><div class="k-label">Outstanding balances</div><div class="k-value"><?php echo ugx($outstTot); ?></div><div class="k-sub">owed to facilities</div></div>
</div>

<div class="grid grid-2">
 <div class="panel">
  <h2 style="margin-top:0">&#9878;&#65039; Payment queue — cleared &amp; approved claims</h2>
  <div class="twrap" style="border:0;max-height:430px;overflow:auto"><table class="data" style="margin:0">
   <tr><th>Claim</th><th>Facility</th><th class="num">Approved</th><th class="num">Paid</th><th class="num">Outstanding</th><th>Status</th><th class="noprint">Disburse</th></tr>
   <?php if (!$queue): ?><tr><td colspan="7" style="color:#5b7c99">Nothing awaiting payment — every cleared claim has been settled.</td></tr><?php endif; ?>
   <?php foreach ($queue as $c): $appr = $c['amount_approved'] !== null ? (float)$c['amount_approved'] : (float)$c['amount_billed']; $pd = claim_paid_total(intval($c['id'])); $out = max(0,$appr-$pd); ?>
   <tr><td><a href="claim-view.php?id=<?php echo e($c['id']); ?>"><b><?php echo e($c['claim_no']); ?></b></a><br><span style="font-size:10.5px;color:#7a94aa"><?php echo e(date('d M Y', strtotime($c['submitted_at'] ?: $c['created_at']))); ?></span></td>
    <td><?php $ff = fac_of(intval($c['facility_id'])); echo $ff ? fac_icon($ff['ftype']) : ''; ?> <span style="font-size:12px"><?php echo e($c['facility_name']); ?></span></td>
    <td class="num"><?php echo e(number_format($appr,0)); ?></td>
    <td class="num"><?php echo $pd>0 ? e(number_format($pd,0)) : '—'; ?></td>
    <td class="num"><b style="color:#8c2424"><?php echo e(number_format($out,0)); ?></b></td>
    <td><span class="badge" style="background:<?php echo $c['status']==='Cleared'?'#E3EFFA;color:#1B6BB3':($c['status']==='PartPaid'?'#FFF6E0;color:#8a6410':'#F0F4F8;color:#5b7c99'); ?>"><?php echo e(cl_slabel($c['status'])); ?></span></td>
    <td class="noprint"><?php if (cl_can_disburse($c['status']) && $out>0.001): ?>
      <button class="btn small danger" type="button" onclick="payOpen(<?php echo e($c['id']); ?>,'<?php echo e(addslashes($c['claim_no'])); ?>','<?php echo e(addslashes($c['facility_name'])); ?>',<?php echo (int)$out; ?>)">Pay</button>
    <?php else: ?>—<?php endif; ?></td></tr>
   <?php endforeach; ?>
  </table></div>
 </div>
 <div class="panel">
  <h2 style="margin-top:0">&#128188; Facility balances</h2>
  <div class="twrap" style="border:0;max-height:430px;overflow:auto"><table class="data" style="margin:0">
   <tr><th>Facility</th><th class="num">Claims</th><th class="num">Awaiting</th><th class="num">Disbursed</th><th class="num">Outstanding</th></tr>
   <?php foreach ($facRows as $r): ?>
   <tr><td><?php echo fac_icon($r['f']['ftype']); ?> <b><?php echo e($r['f']['name']); ?></b><br><span style="font-size:10.5px;color:#7a94aa"><?php echo e($r['f']['code']); ?><?php echo intval($r['f']['parent_id'])>0 ? ' · branch of '.e(fac_name(intval($r['f']['parent_id']))) : ''; ?></span></td>
     <td class="num"><?php echo num($r['s']['claims']); ?></td><td class="num"><?php echo num($r['s']['pending']); ?></td>
     <td class="num"><?php echo e(number_format($r['s']['paid'],0)); ?></td>
     <td class="num"><b style="color:<?php echo $r['s']['outstanding']>0?'#8c2424':'#20603a'; ?>"><?php echo e(number_format($r['s']['outstanding'],0)); ?></b></td></tr>
   <?php endforeach; ?>
   <?php if (!$facRows): ?><tr><td colspan="5" style="color:#5b7c99">No facility financial activity yet.</td></tr><?php endif; ?>
  </table></div>
  <?php if (cl_tbl('disb_batches')): ?>
  <h3>Payment batches</h3>
  <form method="post" class="grid grid-2" style="gap:8px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="batch">
    <div><label class="f">Batch title</label><input class="f" name="title" placeholder="e.g. October facility payouts"></div>
    <div><label class="f">Method for the batch</label><select class="f" name="method"><option>Bank Transfer</option><option>MTN MoMo</option><option>Airtel Money</option><option>Cash</option></select></div>
    <div><label class="f">Date</label><input class="f" type="date" name="batch_date" value="<?php echo today(); ?>"></div>
    <div><label class="f">Payment reference (bank / MoMo / cash receipt no.)</label><input class="f" name="reference" placeholder="txn ref"></div>
    <div style="grid-column:1/-1"><button class="btn small primary" type="submit">Open batch</button></div>
  </form>
  <div class="twrap" style="border:0;margin-top:8px"><table class="data" style="margin:0">
   <tr><th>Batch</th><th class="num">Pays</th><th class="num">Total</th><th>Status</th><th></th></tr>
   <?php foreach ($batches as $b): ?>
   <tr><td><b><?php echo e($b['title']); ?></b> <span style="font-size:10.5px;color:#7a94aa"><?php echo e($b['method']); ?> · <?php echo e(date('d M Y', strtotime($b['batch_date']))); ?></span></td>
     <td class="num"><?php echo num($b['n']); ?></td><td class="num"><?php echo e(number_format((float)$b['t'],0)); ?></td>
     <td><span class="badge" style="background:<?php echo $b['status']==='Open'?'#FFF6E0;color:#8a6410':'#E6F6EA;color:#20603a'; ?>"><?php echo strtoupper($b['status']); ?></span></td>
     <td style="white-space:nowrap"><a class="btn small" href="disbursement-voucher.php?batch=<?php echo e($b['id']); ?>">Statement</a>
      <?php if ($b['status']==='Open'): ?><form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="batch_close"><input type="hidden" name="id" value="<?php echo e($b['id']); ?>"><button class="btn small plain" type="submit">close</button></form><?php endif; ?></td></tr>
   <?php endforeach; ?>
   <?php if (!$batches): ?><tr><td colspan="5" style="color:#7a94aa;font-size:12px">No batches yet — group several claims into one payment run.</td></tr><?php endif; ?>
  </table></div>
  <?php endif; ?>
 </div>
</div>

<div class="panel">
 <h2 style="margin-top:0">Recent disbursements &mdash; the money trail</h2>
 <div class="twrap" style="border:0;max-height:360px;overflow:auto"><table class="data" style="margin:0">
  <tr><th>Voucher</th><th>Date</th><th>Facility</th><th>Claim</th><th class="num">Amount</th><th>Method</th><th>Reference</th><th>By</th><th class="noprint">Actions</th></tr>
  <?php foreach ($recent as $d): $rvd = isset($d['reversed']) && intval($d['reversed'])===1; ?>
  <tr <?php echo $rvd?'style="opacity:.5"':''; ?>>
   <td><?php echo isset($d['voucher_no']) && $d['voucher_no'] !== '' ? '<b>'.e($d['voucher_no']).'</b>' : '#'.e($d['id']); ?></td>
   <td style="font-size:11px"><?php echo e(substr((string)$d['paid_at'],0,10)); ?></td>
   <td style="font-size:12px"><?php echo e($d['facility_name']); ?></td><td><?php echo e($d['claim_no']); ?></td>
   <td class="num"><b><?php echo e(number_format((float)$d['amount'],0)); ?></b><?php if ($rvd): ?> <span style="font-size:9.5px;color:#8c2424">REVERSED</span><?php endif; ?></td>
   <td><?php echo e($d['method']); ?></td><td style="font-size:11px"><?php echo e($d['reference']); ?></td>
   <td style="font-size:11px"><?php echo e($d['paid_by']); ?></td>
   <td class="noprint" style="white-space:nowrap">
    <a class="btn small plain" href="disbursement-voucher.php?id=<?php echo e($d['id']); ?>">Voucher</a>
    <?php if (!$rvd): ?><form method="post" style="display:inline" onsubmit="return confirm('Reverse this payment? A correcting journal entry is posted and the claim reopens for payment.')">
      <?php echo csrf_field(); ?><input type="hidden" name="op" value="reverse"><input type="hidden" name="did" value="<?php echo e($d['id']); ?>">
      <input class="f" name="why" placeholder="reason" style="width:110px;margin:0;padding:3px 6px" required>
      <button class="btn small" type="submit" title="Reverse">&#8634;</button></form><?php else: ?><span style="font-size:10px;color:#8c2424"><?php echo e($d['reverse_reason']); ?></span><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$recent): ?><tr><td colspan="9" style="color:#5b7c99">No payments yet.</td></tr><?php endif; ?>
 </table></div>
</div>

<?php if (cl_tbl('disb_batches') || true): ?>
<div id="payModal" style="display:none;position:fixed;inset:0;background:rgba(18,40,60,.55);z-index:99" onclick="if(event.target===this)this.style.display='none'">
 <form method="post" class="panel" style="max-width:520px;margin:8vh auto;background:#fff" ><?php echo csrf_field(); ?><input type="hidden" name="op" value="pay"><input type="hidden" name="claim_id" id="pmClaim">
  <h3 style="margin-top:0">Disburse <span id="pmNo"></span> — <span id="pmFac"></span></h3>
  <div class="grid grid-2" style="gap:8px">
   <div><label class="f">Amount (UGX) — max <span id="pmMax"></span></label><input class="f" name="amount" id="pmAmt" required></div>
   <div><label class="f">Payment date</label><input class="f" type="date" name="pay_date" value="<?php echo date('Y-m-d'); ?>"></div>
   <div><label class="f">Method</label><select class="f" name="method"><option>Bank Transfer</option><option>MTN MoMo</option><option>Airtel Money</option><option>Cash</option></select></div>
   <div><label class="f">Batch</label><select class="f" name="batch_id"><option value="0">— none —</option>
     <?php foreach ($batches as $b): if ($b['status']!=='Open') continue; ?><option value="<?php echo e($b['id']); ?>"><?php echo e($b['title']); ?></option><?php endforeach; ?></select></div>
   <div style="grid-column:1/-1"><label class="f">Transaction reference</label><input class="f" name="reference" placeholder="bank/momo txn ref — auto if blank"></div>
   <div style="grid-column:1/-1"><label class="f">Payment channel + account details for the voucher</label><input class="f" name="details" placeholder="Acct 1020-4455 Stanbic · ref BT-441"></div>
  </div>
  <p style="margin-bottom:0"><button class="btn danger" type="submit">&#128179; Record payment</button>
   <button class="btn plain" type="button" onclick="document.getElementById('payModal').style.display='none'">Cancel</button>
   <span style="font-size:11px;color:#5b7c99">partial amounts stay as an outstanding balance</span></p>
 </form>
</div>
<script>
function payOpen(id,no,fac,max){var m=document.getElementById('payModal');m.style.display='block';
document.getElementById('pmClaim').value=id;document.getElementById('pmNo').textContent=no;document.getElementById('pmFac').textContent=fac;
document.getElementById('pmMax').textContent=max.toLocaleString();document.getElementById('pmAmt').value=max.toLocaleString();}
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
