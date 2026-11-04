<?php
/** v6.0 KISUBI CLAIMS DISBURSEMENT SCHEDULE — only approved & CLEARED claims enter here.
 *  Finance officers schedule full or partial payments, group them into payment batches,
 *  and release funds: each payment generates a voucher, a journal entry, an event trail
 *  and a facility notification. Over-disbursement is mathematically blocked. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/acc.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

/* v6.1 — per-method payment channel details captured at release (additive; self-healing) */
try {
    $__isM = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    db()->exec("CREATE TABLE IF NOT EXISTS disb_paydetails (" . ($__isM ? "id INT AUTO_INCREMENT PRIMARY KEY" : "id INTEGER PRIMARY KEY AUTOINCREMENT") . ", disbursement_id INT NOT NULL, method TEXT, bank_name TEXT, bank_account TEXT, mm_provider TEXT, mm_phone TEXT, rec_name TEXT, rec_id TEXT, proof_name TEXT, proof_stored TEXT, note TEXT, created_by TEXT, created_at TEXT)" . ($__isM ? " ENGINE=InnoDB" : ""));

} catch (Exception $e) {}

$by = $_SESSION['uname'];

/** pay one scheduled row — reuses the disbursement ledger exactly as the payments desk does */
function cds_pay($sid, $by, $ov = array()) {
    $out = array('ok' => false, 'msg' => '');
    $st = db()->prepare("SELECT * FROM disb_schedules WHERE id=? AND status='Scheduled'"); $st->execute(array(intval($sid))); $sc = $st->fetch();
    if (!$sc) { $out['msg'] = 'Schedule row not found or already actioned.'; return $out; }
    $st = db()->prepare('SELECT * FROM facility_claims WHERE id=?'); $st->execute(array(intval($sc['claim_id']))); $c = $st->fetch();
    if (!$c) { $out['msg'] = 'Claim not found.'; return $out; }
    if (!cl_can_disburse($c['status']) && $c['status'] !== 'Scheduled') { $out['msg'] = 'Claim state no longer payable (' . cl_slabel($c['status']) . ').'; return $out; }
    $appr = $c['amount_approved'] !== null ? (float)$c['amount_approved'] : (float)$c['amount_billed'];
    $paid = claim_paid_total(intval($c['id'])); $outst = max(0, $appr - $paid);
    $amt = round((float)$sc['amount'], 2);
    if ($amt <= 0 || $amt > $outst + 0.001) { $out['msg'] = 'Blocked: maximum payable now is ' . ugx($outst) . ' — the schedule amount exceeds the approved outstanding balance.'; return $out; }
    $mth = trim((string)(isset($ov['method']) ? $ov['method'] : '')); $rfIn = trim((string)(isset($ov['reference']) ? $ov['reference'] : ''));
    if ($mth !== '' || $rfIn !== '') { // the clearing facility chooses the channel (bank / cash / mobile money) at release
        $u = array(); $ua = array();
        if ($mth !== '') { $u[] = 'method=?'; $ua[] = $mth; }
        if ($rfIn !== '') { $u[] = 'reference=?'; $ua[] = $rfIn; }
        $ua[] = intval($sid);
        db()->prepare("UPDATE disb_schedules SET " . implode(',', $u) . " WHERE id=? AND status='Scheduled'")->execute($ua);
        $st = db()->prepare("SELECT * FROM disb_schedules WHERE id=? AND status='Scheduled'"); $st->execute(array(intval($sid))); $sc = $st->fetch();
        if (!$sc) { $out = array('ok' => false, 'msg' => 'That schedule was just paid or cancelled by someone else — nothing was released.'); return $out; }
    }
    $ref = (string)$sc['reference']; if ($ref === '') $ref = strtoupper(substr(md5($c['claim_no'] . $sid . microtime()), 0, 10));
    $dup = db()->prepare('SELECT id FROM disbursements WHERE claim_id=? AND reference=? AND amount>0'); $dup->execute(array(intval($c['id']), $ref));
    if ($dup->fetch()) { $out['msg'] = 'Reference ' . $ref . ' already paid on this claim — every payment needs its own reference.'; return $out; }
    $vno = cl_has('disbursements', 'voucher_no') ? cl_voucher_no() : '';
    $payDate = today();
    try {
        db()->beginTransaction();
        // consume the schedule row first — replay protection
        $up = db()->prepare("UPDATE disb_schedules SET status='Paid', paid_by=?, paid_at=?, disb_id=? WHERE id=? AND status='Scheduled'");
        $up->execute(array($by, date('Y-m-d H:i:s'), intval($sid), intval($sid)));
        if ($up->rowCount() !== 1) { db()->rollBack(); $out['msg'] = 'This payment was already released elsewhere — nothing changed.'; return $out; }
        $insCols = 'claim_id, claim_no, facility_id, facility_name, amount, method, reference, paid_by, paid_at, note, created_at';
        $insVal = '?,?,?,?,?,?,?,?,?,?,?';
        $insArgs = array(intval($c['id']), $c['claim_no'], intval($c['facility_id']), $c['facility_name'], $amt, (string)$sc['method'], $ref, $by, $payDate . ' ' . date('H:i:s'), 'Scheduled payment ' . $sc['sched_no'] . ($sc['note'] !== '' ? ' — ' . $sc['note'] : ''), date('Y-m-d H:i:s'));
        $bid = 0;
        if (cl_has('disbursements', 'batch_id') && $sc['batch_title'] !== '') {
            $stB = db()->prepare("SELECT id FROM disb_batches WHERE title=? AND status='Open' ORDER BY id DESC LIMIT 1"); $stB->execute(array($sc['batch_title'])); $rr = $stB->fetch();
            if ($rr) $bid = intval($rr['id']);
            else { db()->prepare("INSERT INTO disb_batches (title, batch_date, method, reference, note, status, created_by, created_at) VALUES (?,?,?,?,?,'Open',?,?)")
                    ->execute(array($sc['batch_title'], today(), (string)$sc['method'], $ref, 'Created by the disbursement scheduler', $by, date('Y-m-d H:i:s'))); $bid = intval(db()->lastInsertId()); }
            $insCols .= ', batch_id'; $insVal .= ',?'; $insArgs[] = $bid;
        }
        if (cl_has('disbursements', 'voucher_no')) { $insCols .= ', voucher_no'; $insVal .= ',?'; $insArgs[] = $vno; }
        db()->prepare('INSERT INTO disbursements (' . $insCols . ') VALUES (' . $insVal . ')')->execute($insArgs);
        $did = intval(db()->lastInsertId());
        db()->prepare('UPDATE disb_schedules SET disb_id=? WHERE id=?')->execute(array($did, intval($sid)));
        if (cl_has('facility_claims', 'paid_at')) {
            if ($outst - $amt <= 0.001) db()->prepare('UPDATE facility_claims SET paid_at=?, paid_by=?, pay_method=?, pay_ref=? WHERE id=?')->execute(array($payDate . ' ' . date('H:i:s'), $by, (string)$sc['method'], $ref, intval($c['id'])));
            elseif (cl_has('facility_claims', 'pay_method')) db()->prepare('UPDATE facility_claims SET pay_method=?, pay_ref=? WHERE id=?')->execute(array((string)$sc['method'], $ref, intval($c['id'])));
        }
        $newst = claim_sync_pay_state(intval($c['id']));
        db()->commit();
        gl_post($payDate, array(array('2100', $amt, 0), array(acc_method_acct(strtolower(str_replace(' ', '', (string)$sc['method']))), 0, $amt)), 'Scheduled disbursement ' . $sc['sched_no'] . ' for ' . $c['claim_no'] . ' to ' . $c['facility_name'] . ($vno !== '' ? ' [' . $vno . ']' : ''), 'claimpay', intval($c['id']), intval($c['facility_id']));
        claim_event(intval($c['id']), 'Disbursed (scheduled)', ugx($amt) . ' via ' . $sc['method'] . ' ref ' . $ref . ' — claim now ' . cl_slabel($newst ?: $c['status']) . '.', $by);
        log_action('DISBURSED (schedule ' . $sc['sched_no'] . ') ' . ugx($amt) . ' to ' . $c['facility_name'] . ' (' . $c['claim_no'] . ') via ' . $sc['method'] . ' ref ' . $ref, 'disbursements', $did);
        $fac = fac_of(intval($c['facility_id']));
        if ($fac) fac_notify($fac, 'PAYMENT: UGX ' . number_format($amt, 0) . ' for claim ' . $c['claim_no'] . ' via ' . $sc['method'] . ' (ref ' . $ref . ')' . ($newst === 'PartPaid' ? '. Balance still outstanding — it remains on the payment schedule.' : '. Claim fully settled.'));
        mb_notify('disb_paid', array('inst' => $c['facility_name'], 'amount' => ugx($amt), 'claim_no' => $c['claim_no'], 'method' => (string)$sc['method'], 'ref' => $ref), intval($c['facility_id']), 0, 'disbursements', $did);
        $out['ok'] = true; $out['msg'] = 'Paid ' . ugx($amt) . ' to ' . $c['facility_name'] . ($vno !== '' ? ' — voucher ' . $vno : '') . ($newst === 'PartPaid' ? ' · balance ' . ugx($outst - $amt) . ' outstanding.' : ' — fully settled.');
        $out['voucher'] = $vno; $out['did'] = $did;
        return $out;
    } catch (Exception $e) { if (db()->inTransaction()) db()->rollBack(); $out['msg'] = 'Payment failed: ' . $e->getMessage(); return $out; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'schedule') {
        $amts = isset($_POST['amount']) && is_array($_POST['amount']) ? $_POST['amount'] : array();
        $date = post('sched_date') !== '' ? post('sched_date') : today();
        $method = post('method'); if (!in_array($method, array('Cash', 'Bank Transfer', 'MTN MoMo', 'Airtel Money'), true)) $method = 'Bank Transfer';
        $ref0 = trim(post('reference')); $note = trim(post('note')); $batchT = mb_substr(trim(post('batch_title')), 0, 140);
        $n = 0; $errs = array();
        foreach ($amts as $cid => $aRaw) {
            $cid = intval($cid); $amt = round((float)str_replace(',', '', (string)$aRaw), 2);
            if ($amt <= 0) continue;
            $st = db()->prepare("SELECT * FROM facility_claims WHERE id=? AND status IN ('Cleared','Approved','Partially Approved','PartPaid','Scheduled')"); $st->execute(array($cid)); $c = $st->fetch();
            if (!$c) { $errs[] = 'Claim #' . $cid . ' is not schedulable (must be approved & cleared).'; continue; }
            $appr = $c['amount_approved'] !== null ? (float)$c['amount_approved'] : (float)$c['amount_billed'];
            $already = claim_paid_total($cid);
            $committed = 0.0; try { $stC = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM disb_schedules WHERE claim_id=? AND status='Scheduled'"); $stC->execute(array($cid)); $committed = (float)$stC->fetchColumn(); } catch (Exception $e) {}
            $room = $appr - $already - $committed;
            if ($amt > $room + 0.001) { $errs[] = $c['claim_no'] . ': ' . ugx($amt) . ' exceeds the payable room ' . ugx(max(0, $room)) . ' (approved − paid − already scheduled).'; continue; }
            $sno = mb_seq_no('disb_schedules', 'sched_no', 'DSCH');
            db()->prepare('INSERT INTO disb_schedules (sched_no, facility_id, claim_id, claim_no, amount, sched_date, method, reference, note, status, batch_title, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,\'Scheduled\',?,?,?)')
               ->execute(array($sno, intval($c['facility_id']), $cid, $c['claim_no'], $amt, $date, $method, mb_substr((string)$ref0, 0, 80), mb_substr($note, 0, 250), $batchT, $by, date('Y-m-d H:i:s')));
            db()->prepare("UPDATE facility_claims SET status='Scheduled', scheduled_at=? WHERE id=? AND status='Cleared'")->execute(array(date('Y-m-d H:i:s'), $cid));
            claim_event($cid, 'Scheduled for payment', $sno . ': ' . ugx($amt) . ' on ' . $date . ' via ' . $method . '.', $by);
            $fac = fac_of(intval($c['facility_id']));
            mb_notify('disb_scheduled', array('inst' => $c['facility_name'], 'claim_no' => $c['claim_no'], 'amount' => ugx($amt), 'date' => $date, 'method' => $method, 'ref' => $ref0 !== '' ? $ref0 : $sno), intval($c['facility_id']), 0, 'facility_claims', $cid);
            $n++;
        }
        if ($n > 0) log_action('Scheduled ' . $n . ' disbursement(s) — ' . ($batchT !== '' ? 'batch “' . $batchT . '”' : 'individual'), 'disb_schedules', 0);
        if ($n > 0 && !$errs) flash('Scheduled ' . $n . ' payment(s). Facilities have been notified.', 'ok');
        elseif ($n > 0) flash($n . ' scheduled; ' . implode(' ', $errs), 'info');
        else flash(implode(' ', $errs) ?: 'Enter at least one payable amount.', 'err');
        redirect('claims-disburse.php');
    }
    if ($op === 'pay') {
        $sidP = intval(post('sid')); $mmP = trim((string)post('pay_method'));
        if ($mmP === '') { try { $qM = db()->prepare('SELECT method FROM disb_schedules WHERE id=?'); $qM->execute(array($sidP)); $mmP = (string)$qM->fetchColumn(); } catch (Exception $e) {} }
        if (stripos($mmP, 'cash') !== false && trim((string)post('rec_name')) === '') { flash('Cash disbursement needs the recipient\'s name — it is printed on the voucher for acknowledgement. Nothing was paid.', 'err'); redirect('claims-disburse.php'); }
        $phP = trim((string)post('mm_phone'));
        if ($phP !== '' && !preg_match('/^[0-9+][0-9+ ]{6,18}$/', $phP)) { flash('That mobile money number is not valid — nothing was paid.', 'err'); redirect('claims-disburse.php'); }
        $r = cds_pay($sidP, $by, array('method' => (string)post('pay_method'), 'reference' => (string)post('pay_ref')));
        if (!empty($r['ok']) && intval($r['did']) > 0) { // channel details + optional proof (metadata — never affects the committed payment)
            try {
                $didP = intval($r['did']); $proofN = ''; $proofS = '';
                if (isset($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK && intval($_FILES['proof']['size']) > 0) {
                    $extP = strtolower(pathinfo((string)$_FILES['proof']['name'], PATHINFO_EXTENSION));
                    if (intval($_FILES['proof']['size']) > 2 * 1024 * 1024) { flash('Proof file exceeds 2 MB — payment recorded WITHOUT the attachment.', 'err'); }
                    elseif (!in_array($extP, array('pdf', 'png', 'jpg', 'jpeg', 'txt'), true)) { flash('Proof must be PDF, image or TXT — payment recorded WITHOUT the attachment.', 'err'); }
                    else { $dirP = __DIR__ . '/data/payments'; if (!is_dir($dirP)) @mkdir($dirP, 0775, true);
                        $proofS = 'pay' . $didP . '-' . date('YmdHis') . '-' . substr(sha1(uniqid((string)mt_rand(), true)), 0, 10) . '.' . $extP;
                        if (!@move_uploaded_file($_FILES['proof']['tmp_name'], $dirP . '/' . $proofS)) { $proofS = ''; flash('Could not store the proof file — payment itself is recorded.', 'err'); } else $proofN = mb_substr((string)$_FILES['proof']['name'], 0, 120); }
                }
                $dvals = array(post('bank_name'), post('bank_account'), post('mm_provider'), $phP, post('rec_name'), post('rec_id'), post('pay_note'), $proofS);
                $hasD = false; foreach ($dvals as $dv) if (trim((string)$dv) !== '') { $hasD = true; break; }
                if ($hasD) {
                    $exP = db()->prepare('SELECT id FROM disb_paydetails WHERE disbursement_id=?'); $exP->execute(array($didP));
                    $dargs = array(mb_substr((string)$mmP, 0, 28), mb_substr((string)post('bank_name'), 0, 118), mb_substr((string)post('bank_account'), 0, 58), mb_substr((string)post('mm_provider'), 0, 28), mb_substr($phP, 0, 18), mb_substr((string)post('rec_name'), 0, 118), mb_substr((string)post('rec_id'), 0, 58), $proofN, $proofS, mb_substr((string)post('pay_note'), 0, 250), $by, date('Y-m-d H:i:s'));
                    if ($exP->fetch()) db()->prepare('UPDATE disb_paydetails SET method=?, bank_name=?, bank_account=?, mm_provider=?, mm_phone=?, rec_name=?, rec_id=?, proof_name=?, proof_stored=?, note=?, created_by=?, created_at=? WHERE disbursement_id=?')->execute(array_merge($dargs, array($didP)));
                    else db()->prepare('INSERT INTO disb_paydetails (disbursement_id, method, bank_name, bank_account, mm_provider, mm_phone, rec_name, rec_id, proof_name, proof_stored, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge(array($didP), $dargs));
                    log_action('Payment channel details recorded for disbursement #' . $didP, 'disbursements', $didP);
                }
                if ($proofS !== '') flash('Proof of payment attached — download from the Payments ledger.', 'ok');
            } catch (Exception $e) {}
        }
        flash(e($r['msg']), $r['ok'] ? 'ok' : 'err'); redirect('claims-disburse.php');
    }
    if ($op === 'pay_due') {
        $due = db()->query("SELECT id FROM disb_schedules WHERE status='Scheduled' AND sched_date<='" . today() . "' ORDER BY id LIMIT 50")->fetchAll();
        $okN = 0; $msgs = array();
        foreach ($due as $d) { $r = cds_pay(intval($d['id']), $by, array('method' => 'Bank Transfer')); if ($r['ok']) $okN++; else $msgs[] = $r['msg']; }
        flash('Released ' . $okN . ' due payment(s)' . ($msgs ? ' — held: ' . e(implode(' | ', array_slice($msgs, 0, 3))) : '.') . ($okN > 0 ? ' Facilities notified.' : ''), $okN > 0 ? 'ok' : 'info');
        redirect('claims-disburse.php');
    }
    if ($op === 'cancel_sched') {
        $sid = intval(post('sid')); $why = trim(post('reason')); if ($why === '') $why = 'Cancelled by finance';
        $st = db()->prepare("SELECT * FROM disb_schedules WHERE id=? AND status='Scheduled'"); $st->execute(array($sid)); $sc = $st->fetch();
        if (!$sc) { flash('Only a scheduled (unpaid) row can be cancelled.', 'err'); redirect('claims-disburse.php'); }
        db()->prepare("UPDATE disb_schedules SET status='Cancelled', cancel_reason=? WHERE id=?")->execute(array(mb_substr($why, 0, 200) . ' — ' . $by, $sid));
        $st = db()->prepare('SELECT COUNT(*) FROM disb_schedules WHERE claim_id=? AND status=\'Scheduled\''); $st->execute(array(intval($sc['claim_id'])));
        if (intval($st->fetchColumn()) === 0) db()->prepare("UPDATE facility_claims SET status='Cleared', scheduled_at=NULL WHERE id=? AND status='Scheduled'")->execute(array(intval($sc['claim_id'])));
        claim_event(intval($sc['claim_id']), 'Payment schedule cancelled', $sc['sched_no'] . ': ' . $why, $by);
        log_action('Cancelled schedule ' . $sc['sched_no'] . ': ' . $why, 'disb_schedules', $sid);
        flash('Schedule cancelled — the claim is back on the cleared list.', 'info'); redirect('claims-disburse.php');
    }
}

/* cleared / payable pool */
$pool = db()->query("SELECT * FROM facility_claims WHERE status='Cleared' ORDER BY decided_at LIMIT 100")->fetchAll();
try { $pool2 = db()->query("SELECT * FROM facility_claims WHERE status IN ('Approved','Partially Approved','PartPaid') ORDER BY decided_at DESC LIMIT 50")->fetchAll(); } catch (Exception $e) { $pool2 = array(); }
$scheds = db()->query("SELECT s.*, f.name fac FROM disb_schedules s JOIN facilities f ON f.id=s.facility_id ORDER BY CASE s.status WHEN 'Scheduled' THEN 0 WHEN 'Paid' THEN 1 ELSE 2 END, s.sched_date, s.id LIMIT 120")->fetchAll();

$page_title = 'Claims Disbursement Schedule';
if (intval(get('proof')) > 0) { // serve stored proof to authorized portal users only
    try { $qF = db()->prepare('SELECT proof_stored, proof_name FROM disb_paydetails WHERE disbursement_id=?'); $qF->execute(array(intval(get('proof')))); $prF = $qF->fetch();
        if ($prF && (string)$prF['proof_stored'] !== '') { $fpF = __DIR__ . '/data/payments/' . basename((string)$prF['proof_stored']);
            if (is_file($fpF)) { $ctF = strtolower(substr($fpF, -3)) === 'pdf' ? 'application/pdf' : (strtolower(substr($fpF, -3)) === 'txt' ? 'text/plain' : 'image/png');
                header('Content-Type: ' . $ctF); header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string)$prF['proof_name']) . '"'); header('Content-Length: ' . filesize($fpF)); readfile($fpF); exit; } } } catch (Exception $e) {}
    flash('No proof file is attached to that payment.', 'err'); redirect('claims-disburse.php');
}
echo '<script>function pdtToggle(sel){var f=sel.form,m=((sel.value||"")+" "+(f.getAttribute("data-sched")||"")).toLowerCase();[["pd-bank","bank transfer bank"],["pd-momo","mobile momo"],["pd-cash","cash"]].forEach(function(pair){var el=f.querySelector("."+pair[0]);if(!el)return;var hit=false;pair[1].split(" ").forEach(function(k){if(m.indexOf(k)>=0)hit=true;});el.style.display=hit?"inline-flex":"none";});}window.addEventListener("load",function(){document.querySelectorAll("form.payForm select[name=pay_method]").forEach(function(x){pdtToggle(x);});});</script>';
include __DIR__ . '/includes/header.php';
$dueN = 0; foreach ($scheds as $s) if ($s['status'] === 'Scheduled' && strcmp((string)$s['sched_date'], today()) <= 0) $dueN++;
?>
<div class="page-head"><h1>&#128197; KISUBI CLAIMS DISBURSEMENT SCHEDULE</h1>
 <span class="sub">Approved &amp; cleared claims only — partial or full, grouped into payment batches, each payment generating a voucher, a journal entry and a facility notification. Overpayment is impossible by design.</span>
 <span style="float:right;display:flex;gap:8px"><a class="btn small" href="disbursements-manager.php">Payments desk &#8599;</a>
 <a class="btn small plain" href="claims-central.php">Central claims &#8599;</a>
 <a class="btn small plain" href="claims-reports.php">Reports &#8599;</a></span>
</div>
<?php if ($dueN > 0): ?><div class="flash flash-amber">&#9200; <b><?php echo $dueN; ?> scheduled payment(s) are due today or earlier.</b>
 <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="pay_due"><button class="btn small primary" type="submit" onclick="this.disabled=true;this.form.submit()">&#128181; Release all due payments</button></form></div><?php endif; ?>

<div class="panel"><h2 style="margin-top:0">&#128203; Cleared for payment — build the schedule</h2>
<?php if (!$pool): ?><p class="sub" style="color:#1e7f3c">Nothing on hold: every cleared claim is scheduled or paid. Claims must be <b>cleared</b> on the review desk before they can be scheduled here.</p><?php endif; ?>
<?php if ($pool): ?>
<form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="schedule">
 <table class="tbl"><tr><th>Claim</th><th>Facility</th><th style="text-align:right">Approved</th><th style="text-align:right">Already paid</th><th style="text-align:right">Payable now</th><th style="width:150px">Payment amount (UGX)</th></tr>
 <?php foreach ($pool as $c): $appr = $c['amount_approved'] !== null ? (float)$c['amount_approved'] : (float)$c['amount_billed']; $paid = claim_paid_total(intval($c['id'])); $room = max(0, $appr - $paid); ?>
 <tr><td><b><?php echo e($c['claim_no']); ?></b><br><span class="sub"><?php echo e($c['period_label']); ?></span></td><td><?php echo e($c['facility_name']); ?></td>
  <td style="text-align:right"><?php echo num($appr); ?></td><td style="text-align:right"><?php echo $paid > 0 ? num($paid) : '—'; ?></td>
  <td style="text-align:right;color:#1e7f3c"><b><?php echo num($room); ?></b></td>
  <td><input class="f" type="number" min="0" max="<?php echo $room; ?>" step="1000" name="amount[<?php echo intval($c['id']); ?>]" value="<?php echo $room; ?>" style="margin:0;text-align:right"></td></tr>
 <?php endforeach; ?>
 </table>
 <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:flex-end">
  <div><label class="f">Payment date</label><input class="f" type="date" name="sched_date" value="<?php echo e(today()); ?>" style="margin:0"></div>
  <div><label class="f">Method</label><select class="f" name="method" style="margin:0"><option>Bank Transfer</option><option>Cash</option><option>MTN MoMo</option><option>Airtel Money</option></select></div>
  <div><label class="f">Transaction reference</label><input class="f" name="reference" placeholder="BT-4471 (optional)" style="margin:0;width:170px"></div>
  <div><label class="f">Payment batch (optional)</label><input class="f" name="batch_title" placeholder="e.g. March facilities batch" style="margin:0;width:190px"></div>
  <div style="flex:1;min-width:170px"><label class="f">Remarks</label><input class="f" name="note" style="margin:0"></div>
  <button class="btn primary" type="submit" onclick="this.disabled=true;this.form.submit()">Add to schedule &#10145;</button>
 </div>
 <p class="sub" style="margin:6px 0 0">Leave an amount at 0 to skip a claim. Multiple claims with the same batch title pay out of one batch; each keeps its own voucher.</p>
</form>
<?php endif; ?>
<?php if ($pool2): ?><details style="margin-top:8px"><summary class="sub">Not-cleared queue — approved/partial claims waiting to be cleared on the review desk (<?php echo count($pool2); ?>)</summary>
 <table class="tbl" style="margin-top:6px"><tr><th>Claim</th><th>Facility</th><th style="text-align:right">Approved</th><th>Status</th><th></th></tr>
 <?php foreach ($pool2 as $c): ?><tr><td><?php echo e($c['claim_no']); ?></td><td><?php echo e($c['facility_name']); ?></td><td style="text-align:right"><?php echo num($c['amount_approved'] !== null ? $c['amount_approved'] : $c['amount_billed']); ?></td><td><?php echo e(cl_slabel($c['status'])); ?></td><td><a class="btn small plain" href="claim-view.php?id=<?php echo intval($c['id']); ?>">Review</a></td></tr><?php endforeach; ?></table></details><?php endif; ?>
</div>

<div class="panel"><h2 style="margin-top:0">&#128640; The schedule — pending, released and cancelled payments</h2>
 <table class="tbl"><tr><th>Schedule</th><th>Date</th><th>Facility / claim</th><th style="text-align:right">Amount</th><th>Method</th><th>Reference</th><th>Batch</th><th>Status</th><th>Voucher / payment</th><th></th></tr>
 <?php if (!$scheds) echo '<tr><td colspan="10" style="color:#7a94aa">Nothing scheduled yet.</td></tr>';
 foreach ($scheds as $s): ?>
 <tr><td><b><?php echo e($s['sched_no']); ?></b></td><td><?php echo e($s['sched_date']); ?></td>
  <td><?php echo e($s['fac']); ?><br><span class="sub"><?php echo e($s['claim_no']); ?></span></td>
  <td style="text-align:right"><b><?php echo num($s['amount']); ?></b></td><td><?php echo e($s['method']); ?></td><td><?php echo e($s['reference'] ?: '—'); ?></td>
  <td><span class="sub"><?php echo e($s['batch_title'] ?: '—'); ?></span></td>
  <td><?php $col = $s['status'] === 'Paid' ? '#1e7f3c' : ($s['status'] === 'Scheduled' ? '#8a6410' : '#a33'); ?><b style="color:<?php echo $col; ?>"><?php echo e($s['status']); ?></b><?php if ($s['status'] === 'Paid'): ?><br><span class="sub"><?php echo e(substr((string)$s['paid_at'], 0, 16)); ?> by <?php echo e($s['paid_by']); ?></span><?php endif; ?></td>
  <td><?php if ($s['status'] === 'Paid'): ?><?php if ($s['voucher_no'] !== ''): ?><b><?php echo e($s['voucher_no']); ?></b><br><a class="sub" href="disbursement-voucher.php?id=<?php echo intval($s['disb_id']); ?>">open voucher &#8599;</a><?php else: ?>#<?php echo intval($s['disb_id']); ?><?php endif; ?><?php else: ?>—<?php endif; ?></td>
  <td style="white-space:nowrap"><?php if ($s['status'] === 'Scheduled'): ?>
   <form method="post" enctype="multipart/form-data" class="payForm" data-sched="<?php echo e((string)$s['method']); ?>" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin:0" onsubmit="if(!this.pay_method.value&&!this.form.dataset.sched){alert('Choose how Kisubi pays: bank, cash or mobile money.');return false}if(stripos(this.dataset.sched||'','cash')>=0&&this.rec_name.value.trim()===''&&!this.pay_method.value){alert('Cash payments need the recipient name on the voucher.');return false}return confirm('Release <?php echo e($s['sched_no']); ?> — pay <?php echo ugx($s['amount']); ?> to <?php echo e($s['fac']); ?>?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="pay"><input type="hidden" name="sid" value="<?php echo intval($s['id']); ?>">
   <select class="f" name="pay_method" onchange="pdtToggle(this)" style="margin:0;width:118px;font-size:11px"><option value="">channel&#8230;</option><option>Bank Transfer</option><option>Cash</option><option>Mobile Money</option><option>Cheque</option></select><input class="f" name="pay_ref" placeholder="txn ref" style="margin:0;width:92px;font-size:11px">
   <span class="pd-bank" style="display:none;gap:4px"><input class="f" name="bank_name" placeholder="bank name" style="margin:0;width:96px;font-size:11px"><input class="f" name="bank_account" placeholder="a/c no." style="margin:0;width:86px;font-size:11px"></span>
   <span class="pd-momo" style="display:none;gap:4px"><select class="f" name="mm_provider" style="margin:0;width:86px;font-size:11px"><option>MTN MoMo</option><option>Airtel Money</option></select><input class="f" name="mm_phone" placeholder="momo no." style="margin:0;width:92px;font-size:11px"></span>
   <span class="pd-cash" style="display:none;gap:4px"><input class="f" name="rec_name" placeholder="received by" style="margin:0;width:96px;font-size:11px"><input class="f" name="rec_id" placeholder="ID no." style="margin:0;width:78px;font-size:11px"></span>
   <input class="f" name="pay_note" placeholder="note" style="margin:0;width:80px;font-size:11px"><label title="attach proof of payment (PDF/image ≤2MB)" style="font-size:10.5px;color:#5b7c99">&#128206;<input type="file" name="proof" accept=".pdf,.png,.jpg,.jpeg,.txt" style="width:118px;font-size:10px;border:0;padding:0"></label><button class="btn small primary" type="submit">&#128181; Pay</button></form>
   <form method="post" style="display:inline" onsubmit="var r=prompt('Cancellation reason?');if(!r)return false;this.querySelector('[name=reason]').value=r;return true"><?php echo csrf_field(); ?><input type="hidden" name="op" value="cancel_sched"><input type="hidden" name="sid" value="<?php echo intval($s['id']); ?>"><input type="hidden" name="reason" value=""><button class="btn small plain" type="submit">&#10006;</button></form>
   <?php endif; ?></td></tr>
 <?php endforeach; ?></table>
 <p class="sub">Paid rows are immutable — mistakes are corrected by reversing the payment on the <a href="disbursements-manager.php">payments desk</a> (a counter-entry, never a deletion).</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
