<?php
/** v3.9 CLAIMS & DISBURSEMENTS — clinics/hospitals bill the scheme here; the scheme owner
 *  (Super Admin) reviews every claim that carries its facility name, decides the approved
 *  amount and clears it — the disbursement. Facility logins are locked to their facility. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/acc.php';
require_once __DIR__ . '/includes/clinical-core.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));

$OWNER = fac_is_owner();
if (function_exists('mb_retry_sweep')) { try { mb_retry_sweep(); } catch (Throwable $rx) {} }                       // only the scheme owner decides & clears
$lockedFid = fac_scope_fid();                 // >0 for facility-locked logins
$GF = intval(get('facility'));
if ($lockedFid > 0) $GF = $lockedFid; else fac_lock(0);   // locked users: their facility, full stop

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');

    /* ---------- facility side: build & submit claims ---------- */
    if ($op === 'save_claim') {
        if ($lockedFid > 0) { $fs = db()->prepare('SELECT status FROM facilities WHERE id=?'); $fs->execute(array($lockedFid)); $fsr = $fs->fetch(); if (!$fsr || $fsr['status'] !== 'Active') { flash('Your facility is not active on the scheme right now — claims are paused. Contact Kisubi HQ.', 'err'); redirect('facility-claims.php'); } }
        $fid = $lockedFid > 0 ? $lockedFid : intval(post('facility_id'));
        if ($fid <= 0 || !fac_of($fid)) { flash('Choose the claiming facility.', 'err'); redirect('facility-claims.php'); }
        $f = fac_of($fid);
        $rows = array();
        $pids = isset($_POST['qty']) && is_array($_POST['qty']) ? $_POST['qty'] : array();
        foreach ($pids as $pid => $qv) {
            $qty = (float)str_replace(',', '', (string)$qv); if ($qty <= 0) continue;
            $st = db()->prepare('SELECT * FROM facility_prices WHERE id=? AND facility_id=?'); $st->execute(array(intval($pid), $fid)); $p = $st->fetch();
            if (!$p) continue;
            $rows[] = array('name' => $p['item_name'], 'cat' => $p['category'], 'unit' => $p['unit'], 'price' => (float)$p['price'], 'qty' => $qty);
        }
        if (isset($_POST['cx_name'])) {
            $cxn = (array)$_POST['cx_name']; $cxq = isset($_POST['cx_qty']) ? (array)$_POST['cx_qty'] : array(); $cxp = isset($_POST['cx_price']) ? (array)$_POST['cx_price'] : array();
            foreach ($cxn as $i => $nm) {
                $nm = trim((string)$nm); if ($nm === '') continue;
                $cqty = (float)str_replace(',', '', (string)(isset($cxq[$i]) ? $cxq[$i] : 1)); $cpr = (float)str_replace(',', '', (string)(isset($cxp[$i]) ? $cxp[$i] : 0));
                if ($cqty <= 0) $cqty = 1;
                $rows[] = array('name' => mb_substr($nm, 0, 140), 'cat' => 'Custom', 'unit' => 'each', 'price' => $cpr, 'qty' => $cqty);
            }
        }
        if (!$rows) { flash('A claim needs at least one priced line — tick list items or add a custom line.', 'err'); redirect('facility-claims.php?view=new'); }
        $total = 0.0; foreach ($rows as $r) $total += round($r['price'] * $r['qty'], 2);
        if ($total <= 0) { flash('Claim total is zero — check the quantities.', 'err'); redirect('facility-claims.php?view=new'); }
        $st = db()->prepare('SELECT deposit_amount FROM members WHERE id=?'); $st->execute(array(intval(post('member_id')))); $mm = $st->fetch();
        $isSub = post('mode') === 'submit';
        $cat41 = post('category'); if (!in_array($cat41, array('OPD','IPD','Emergency','Maternity','Surgical','Dental','Optical','Ortho','Theatre','Combined'), true)) $cat41 = '';
        $sv41 = post('service_date') !== '' ? post('service_date') : null;
        $cols41 = 'claim_no, facility_id, facility_name, contact, member_id, patient_name, visit_date, period_label, amount_billed, amount_approved, status, items_json, note, submitted_by, submitted_at, created_at, created_by';
        $val41 = '?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?'; $args41 = array(fac_claim_no(), $fid, $f['name'], $f['contact_person'], intval(post('member_id')), trim(post('patient_name')),
            post('visit_date') !== '' ? post('visit_date') : null, trim(post('period_label')), $total, null, $isSub ? 'Submitted' : 'Draft',
            json_encode($rows), trim(post('note')), $_SESSION['uname'], $isSub ? date('Y-m-d H:i:s') : null, date('Y-m-d H:i:s'), $_SESSION['uname']);
        if (cl_has('facility_claims', 'category')) { $cols41 .= ', category, service_date'; $val41 .= ',?,?'; $args41[] = $cat41; $args41[] = $sv41; }
        db()->prepare('INSERT INTO facility_claims (' . $cols41 . ') VALUES (' . $val41 . ')')->execute($args41);
        $cid = intval(db()->lastInsertId());
        $cno = db()->query('SELECT claim_no FROM facility_claims WHERE id=' . $cid)->fetchColumn();
        if ($isSub) { cl_claim_accrue($cid); claim_event($cid, 'Submitted to owner', count($rows) . ' service line(s), ' . ugx($total) . ' billed.', $_SESSION['uname']); }
        else claim_event($cid, 'Draft created', count($rows) . ' service line(s), ' . ugx($total) . ' billed.', $_SESSION['uname']);
        log_action(($isSub ? 'Submitted' : 'Saved draft of') . ' claim ' . $cno . ' (' . ugx($total) . ') from ' . $f['name'], 'facility_claims', $cid);
        if ($isSub) flash('Claim ' . $cno . ' of ' . ugx($total) . ' sent to the scheme owner for clearing.', 'ok');
        else flash('Draft ' . $cno . ' saved — submit it when ready.', 'info');
        redirect('facility-claims.php');
    }
    if ($op === 'submit_draft') {
        $cid = intval(post('id'));
        $st = db()->prepare('SELECT * FROM facility_claims WHERE id=? AND status=\'Draft\'' . ($lockedFid > 0 ? ' AND facility_id=' . $lockedFid : '')); $st->execute(array($cid)); $c = $st->fetch();
        if (!$c) { flash('Draft not found (only your own drafts can be submitted).', 'err'); redirect('facility-claims.php'); }
        db()->prepare("UPDATE facility_claims SET status='Submitted', submitted_by=?, submitted_at=? WHERE id=?")->execute(array($_SESSION['uname'], date('Y-m-d H:i:s'), $cid));
        cl_claim_accrue($cid); claim_event($cid, 'Submitted to owner', 'Draft sent for review.', $_SESSION['uname']);
        log_action('Draft ' . $c['claim_no'] . ' submitted to owner', 'facility_claims', $cid);
        flash($c['claim_no'] . ' is now with the owner for decision.', 'ok'); redirect('facility-claims.php');
    }
    if ($op === 'cancel_draft') {
        $cid = intval(post('id'));
        $st = db()->prepare('SELECT * FROM facility_claims WHERE id=? AND status=\'Draft\'' . ($lockedFid > 0 ? ' AND facility_id=' . $lockedFid : '')); $st->execute(array($cid)); $c = $st->fetch();
        if (!$c) { flash('Draft not found.', 'err'); redirect('facility-claims.php'); }
        $rel60 = cl60_release($cid);
        db()->prepare('DELETE FROM facility_claims WHERE id=?')->execute(array($cid));
        log_action('Deleted draft ' . $c['claim_no'] . ($rel60 ? ' — ' . $rel60 . ' member bill(s) released for re-claiming' : ''), 'facility_claims', $cid);
        flash('Draft removed.', 'info'); redirect('facility-claims.php');
    }
    if ($op === 'revise') {
        $cid = intval(post('id'));
        $st = db()->prepare('SELECT * FROM facility_claims WHERE id=?' . ($lockedFid > 0 ? ' AND facility_id=' . $lockedFid : '')); $st->execute(array($cid)); $c = $st->fetch();
        if (!$c || !in_array($c['status'], array('Returned', 'Draft'), true)) { flash('Only a returned (or draft) claim of your own facility can be corrected.', 'err'); redirect('facility-claims.php'); }
        $rows = array();
        $nmr = isset($_POST['rx_name']) ? (array)$_POST['rx_name'] : array();
        $qtr = isset($_POST['rx_qty']) ? (array)$_POST['rx_qty'] : array();
        $prr = isset($_POST['rx_price']) ? (array)$_POST['rx_price'] : array();
        foreach ($nmr as $i => $nm) {
            $nm = trim((string)$nm); if ($nm === '') continue;
            $qy = (float)str_replace(',', '', (string)(isset($qtr[$i]) ? $qtr[$i] : 1)); if ($qy <= 0) $qy = 1;
            $pr = (float)str_replace(',', '', (string)(isset($prr[$i]) ? $prr[$i] : 0));
            $rows[] = array('name' => mb_substr($nm, 0, 140), 'cat' => 'Corrected', 'unit' => 'each', 'price' => $pr, 'qty' => $qy);
        }
        if (!$rows) { flash('Keep at least one priced line in the corrected claim.', 'err'); redirect('facility-claims.php?view=fix&id=' . $cid); }
        $total = 0.0; foreach ($rows as $r) $total += round($r['price'] * $r['qty'], 2);
        $wasReturned = $c['status'] === 'Returned';
        db()->prepare("UPDATE facility_claims SET items_json=?, amount_billed=?, status=?, submitted_by=?, submitted_at=?, note=?, amount_approved=NULL, decided_by='', decided_at=NULL, reject_reason='' WHERE id=?")
           ->execute(array(json_encode($rows), $total, $wasReturned ? 'Resubmitted' : 'Submitted', $_SESSION['uname'], date('Y-m-d H:i:s'), trim(post('note')), $cid));
        cl_claim_accrue($cid);
        claim_event($cid, $wasReturned ? 'Resubmitted after corrections' : 'Submitted to owner', 'Corrected lines: ' . count($rows) . ', ' . ugx($total) . ' billed. ' . trim(post('note')), $_SESSION['uname']);
        log_action(($wasReturned ? 'Resubmitted' : 'Submitted corrected') . ' claim ' . $c['claim_no'] . ' (' . ugx($total) . ') from ' . $c['facility_name'], 'facility_claims', $cid);
        if ($wasReturned && cl_has('facility_claims', 'category')) { try { db()->prepare("UPDATE facility_claims SET category=?, service_date=? WHERE id=?")->execute(array($c['category'], $c['service_date'], $cid)); } catch (Exception $e) {} }
        flash($c['claim_no'] . ' resubmitted to the owner with your corrections.', 'ok'); redirect('facility-claims.php');
    }

    /* ---------- owner desk: decide & clear ---------- */
    if (!$OWNER) { flash('Only the scheme owner (Super Admin) decides and clears facility claims.', 'err'); redirect('facility-claims.php'); }
    $st = db()->prepare("SELECT c.*, f.phone fac_phone FROM facility_claims c LEFT JOIN facilities f ON f.id=c.facility_id WHERE c.id=?");
    $st->execute(array(intval(post('id')))); $c = $st->fetch();
    if (!$c) { flash('Claim not found.', 'err'); redirect('facility-claims.php'); }
    if ($op === 'approve') {
        if (!cl_is_incoming($c['status']) && $c['status'] !== 'Partially Approved') { flash('Only submitted claims can be approved (open the review sheet to decide per item).', 'err'); redirect('facility-claims.php'); }
        $amt = (float)str_replace(',', '', post('amount_approved'));
        if (post('use_items') === '1') { $rv = claim_reviews_of(intval($c['id'])); if ($rv) { $amt = 0.0; foreach ($rv as $rw) $amt += (float)$rw['approved_amount']; } }
        if ($amt <= 0) $amt = (float)$c['amount_billed'];
        if ($amt > (float)$c['amount_billed'] + 0.001) { flash('The approved amount cannot exceed the billed amount.', 'err'); redirect('facility-claims.php'); }
        $rv2 = claim_reviews_of(intval($c['id']));
        $part = $amt + 0.005 < (float)$c['amount_billed'];
        db()->prepare('UPDATE facility_claims SET status=?, amount_approved=?, decided_by=?, decided_at=? WHERE id=?')
           ->execute(array($part ? 'Partially Approved' : 'Approved', $amt, $_SESSION['uname'], date('Y-m-d H:i:s'), $c['id']));
        claim_event($c['id'], $part ? 'Partially approved' : 'Approved', 'At ' . ugx($amt), $_SESSION['uname']);
        log_action('Approved claim ' . $c['claim_no'] . ' of ' . $c['facility_name'] . ' at ' . ugx($amt), 'facility_claims', intval($c['id']));
        fac_notify(fac_of(intval($c['facility_id'])), 'Your claim ' . $c['claim_no'] . ' (' . $c['facility_name'] . ') has been APPROVED for UGX ' . number_format($amt, 0) . ' by the scheme. It will be cleared soon.');
        mb_notify('claim_approved', array('claim_no' => $c['claim_no'], 'amount' => ugx($amt), 'inst' => $c['facility_name']), intval($c['facility_id']), intval($c['member_id']), 'facility_claims', intval($c['id']));
        $ad = cl60_auto_disburse(intval($c['id']), $_SESSION['uname']);
        flash($c['claim_no'] . ' approved at ' . ugx($amt) . '. ' . ($ad['ok'] ? $ad['msg'] : 'Ready to clear.'), 'ok'); redirect('facility-claims.php' . ($GF ? '?facility=' . $GF : ''));
    }
    if ($op === 'clear') {
        if (!in_array($c['status'], array('Approved', 'Partially Approved'), true)) { flash('Only approved claims can be cleared for payment.', 'err'); redirect('facility-claims.php'); }
        db()->prepare("UPDATE facility_claims SET status='Cleared' WHERE id=?")->execute(array($c['id']));
        claim_event($c['id'], 'Cleared for payment', 'Ready for disbursement.', $_SESSION['uname']);
        log_action('Cleared claim ' . $c['claim_no'] . ' for disbursement', 'facility_claims', intval($c['id']));
        flash($c['claim_no'] . ' cleared for payment.', 'ok'); redirect('facility-claims.php' . ($GF ? '?facility=' . $GF : ''));
    }
    if ($op === 'return') {
        if (!cl_is_incoming($c['status'])) { flash('Only submitted claims can be returned for correction.', 'err'); redirect('facility-claims.php'); }
        $why = trim(post('reason')); if ($why === '') { flash('State what the facility must correct.', 'err'); redirect('facility-claims.php'); }
        db()->prepare("UPDATE facility_claims SET status='Returned', decided_by=?, decided_at=?, reject_reason=? WHERE id=?")
           ->execute(array($_SESSION['uname'], date('Y-m-d H:i:s'), mb_substr($why, 0, 240), $c['id']));
        claim_event($c['id'], 'Returned for correction', $why, $_SESSION['uname']);
        fac_notify(fac_of(intval($c['facility_id'])), 'Claim ' . $c['claim_no'] . ' was RETURNED for correction: ' . $why);
        mb_notify('claim_returned', array('claim_no' => $c['claim_no'], 'reason' => $why, 'inst' => $c['facility_name']), intval($c['facility_id']), intval($c['member_id']), 'facility_claims', intval($c['id']));
        flash($c['claim_no'] . ' returned to the facility for correction.', 'info'); redirect('facility-claims.php' . ($GF ? '?facility=' . $GF : ''));
    }
    if ($op === 'reject') {
        if (!in_array($c['status'], array('Pending', 'Submitted', 'Received', 'Resubmitted', 'Under Review', 'Approved', 'Partially Approved', 'Cleared', 'Returned'), true)) { flash('This claim cannot be rejected now.', 'err'); redirect('facility-claims.php'); }
        $why = trim(post('reason')); if ($why === '') $why = 'Supporting documents incomplete';
        db()->prepare("UPDATE facility_claims SET status='Rejected', decided_by=?, decided_at=?, reject_reason=? WHERE id=?")
           ->execute(array($_SESSION['uname'], date('Y-m-d H:i:s'), mb_substr($why, 0, 240), $c['id']));
        $rel60b = cl60_release(intval($c['id']));
        log_action('Rejected claim ' . $c['claim_no'] . ' of ' . $c['facility_name'] . ($rel60b ? ' — ' . $rel60b . ' member bill(s) returned to the claimable pool' : ''), 'facility_claims', intval($c['id']));
        fac_notify(fac_of(intval($c['facility_id'])), 'Your claim ' . $c['claim_no'] . ' was NOT cleared: ' . $why . '. Contact the scheme office for details.');
        mb_notify('claim_rejected', array('claim_no' => $c['claim_no'], 'reason' => $why, 'inst' => $c['facility_name'], 'amount' => ugx($c['amount_billed'])), intval($c['facility_id']), intval($c['member_id']), 'facility_claims', intval($c['id']));
        flash($c['claim_no'] . ' rejected — the facility is notified.', 'info'); redirect('facility-claims.php' . ($GF ? '?facility=' . $GF : ''));
    }
    if ($op === 'pay') {
        if (!cl_can_disburse($c['status'])) { flash('A claim must be approved AND cleared before it can be disbursed.', 'err'); redirect('facility-claims.php'); }
        $appr = $c['amount_approved'] !== null ? (float)$c['amount_approved'] : (float)$c['amount_billed'];
        $paidSoFar = claim_paid_total(intval($c['id']));
        $outst = max(0, $appr - $paidSoFar);
        if ($outst <= 0.001) { flash('This claim is fully settled — no duplicate payments.', 'err'); redirect('facility-claims.php'); }
        $amt = (float)str_replace(',', '', post('amount_paid')); if ($amt <= 0) $amt = $outst;
        if ($amt > $outst + 0.001) { flash('You cannot pay more than the outstanding ' . ugx($outst) . '.', 'err'); redirect('facility-claims.php'); }
        $method = post('pay_method'); if (!in_array($method, array('Cash', 'Bank Transfer', 'MTN MoMo', 'Airtel Money'), true)) $method = 'Bank Transfer';
        $ref = trim(post('pay_ref')); if ($ref === '') $ref = strtoupper(substr(md5($c['claim_no'] . microtime()), 0, 10));
        $invNo = trim(post('invoice_no')); $invDate = post('invoice_date') !== '' ? post('invoice_date') : date('Y-m-d');
        $invAmt = (float)str_replace(',', '', post('invoice_amount')); if ($invAmt <= 0) $invAmt = $amt;
        $hasInv = cl_has('facility_claims', 'invoice_no');
        $hasBatch = cl_has('disbursements', 'batch_id'); $hasV = cl_has('disbursements', 'voucher_no');
        $vno = $hasV ? cl_voucher_no() : '';
        try {
            db()->beginTransaction();
            if ($hasInv) db()->prepare("UPDATE facility_claims SET invoice_no=?, invoice_date=?, invoice_amount=? WHERE id=? AND (invoice_no='' OR invoice_no IS NULL)")
               ->execute(array(mb_substr($invNo, 0, 60), $invDate, $invAmt, $c['id']));
            $iC = 'claim_id, claim_no, facility_id, facility_name, amount, method, reference, paid_by, paid_at, note, created_at';
            $iV = '?,?,?,?,?,?,?,?,?,?,?'; $iA = array($c['id'], $c['claim_no'], $c['facility_id'], $c['facility_name'], $amt, $method, $ref, $_SESSION['uname'], date('Y-m-d H:i:s'), trim(post('note')), date('Y-m-d H:i:s'));
            if ($hasBatch) { $iC .= ', batch_id'; $iV .= ',?'; $iA[] = intval(post('batch_id')); }
            if ($hasV) { $iC .= ', voucher_no'; $iV .= ',?'; $iA[] = $vno; }
            db()->prepare('INSERT INTO disbursements (' . $iC . ') VALUES (' . $iV . ')')->execute($iA);
            $did = intval(db()->lastInsertId());
            $newst = claim_sync_pay_state(intval($c['id']));
            if (cl_has('facility_claims', 'pay_method')) db()->prepare('UPDATE facility_claims SET pay_method=?, pay_ref=?, paid_at=?, paid_by=? WHERE id=?')
               ->execute(array($method, $ref, date('Y-m-d H:i:s'), $_SESSION['uname'], $c['id']));
            db()->commit();
            gl_post(date('Y-m-d'), array(array('2100', $amt, 0), array(acc_method_acct($method), 0, $amt)), 'Disbursement for ' . $c['claim_no'] . ' to ' . $c['facility_name'] . ' via ' . $method . ($vno !== '' ? ' [' . $vno . ']' : ''), 'claimpay', intval($c['id']), intval($c['facility_id']));
            claim_event($c['id'], 'Payment recorded', ugx($amt) . ' via ' . $method . ' ref ' . $ref . ' — ' . cl_slabel($newst ?: $c['status']) . '.', $_SESSION['uname']);
            log_action('CLEARED ' . ugx($amt) . ' to ' . $c['facility_name'] . ' for claim ' . $c['claim_no'] . ' via ' . $method . ' ref ' . $ref, 'disbursements', $did);
            fac_notify(fac_of(intval($c['facility_id'])), 'CLAIM PAYMENT: UGX ' . number_format($amt, 0) . ' for ' . $c['claim_no'] . ' via ' . $method . ' (ref ' . $ref . ').' . ($newst === 'PartPaid' ? ' Outstanding: UGX ' . number_format($outst - $amt, 0) . '.' : ' Fully settled.'));
            flash($c['claim_no'] . ' CLEARED: ' . ugx($amt) . ' via ' . $method . ' ref ' . $ref . ($vno !== '' ? ' (voucher ' . $vno . ')' : '') . ($newst === 'PartPaid' ? ' — balance ' . ugx($outst - $amt) . ' outstanding.' : ' — fully settled.'), 'ok');
        } catch (Exception $ex) { if (db()->inTransaction()) db()->rollBack(); flash('Clearing failed: ' . $ex->getMessage(), 'err'); }
        redirect('facility-claims.php' . ($GF ? '?facility=' . $GF : ''));
    }
}

/* v7.0 §7 — printable claim schedule (facility & central) */
if (intval(get('printclaim')) > 0) {
    $pcid = intval(get('printclaim'));
    $stP = db()->prepare("SELECT * FROM facility_claims WHERE id=?" . ($lockedFid > 0 ? ' AND facility_id=' . $lockedFid : '')); $stP->execute(array($pcid)); $pc = $stP->fetch();
    if (!$pc) { flash('Claim not found for this facility.', 'err'); redirect('facility-claims.php'); }
    $plines = json_decode((string)$pc['items_json'], true); if (!is_array($plines)) $plines = array();
    $pappr = $pc['amount_approved'] !== null ? (float)$pc['amount_approved'] : null;
    $ppaid = function_exists('claim_paid_total') ? claim_paid_total($pcid) : 0.0;
    $pmn = ''; $pmc = '';
    try { $pq7 = db()->prepare('SELECT member_no, card_no FROM members WHERE id=?'); $pq7->execute(array(intval($pc['member_id']))); $pm = $pq7->fetch();
      if ($pm) { $pmn = (string)$pm['member_no']; $pmc = (string)$pm['card_no']; } } catch (Throwable $e) {}
    $evs = function_exists('claim_events_of') ? claim_events_of($pcid) : array();
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Claim ' . e($pc['claim_no']) . '</title>'
      . '<style>body{font:13px Georgia,serif;color:#132a3e;margin:34px}h1{font-size:19px;margin:0 0 2px}table{border-collapse:collapse;width:100%;margin:12px 0}th,td{border:1px solid #b9ccdd;padding:6px 8px;text-align:left;font-size:12px}th{background:#EAF2FA}.num{text-align:right}.muted{color:#5c7a94;font-size:11px}@media print{.noprint{display:none}}</style></head><body>'
      . '<div style="display:flex;justify-content:space-between;border-bottom:3px solid #1B6BB3;padding-bottom:8px"><div><h1>Kisubi Medical Saving Scheme</h1><span class="muted">Claims &amp; Facility Settlement — CLAIM SCHEDULE</span></div>'
      . '<div style="text-align:right"><b style="font-size:16px;color:#12476f">' . e($pc['claim_no']) . '</b><br><span class="muted">printed ' . date('d M Y H:i') . '</span></div></div>'
      . '<table style="margin-top:10px;border:none"><tr><td style="border:none;padding:2px 14px 2px 0"><span class="muted">Facility</span><br><b>' . e($pc['facility_name']) . '</b></td>'
      . '<td style="border:none"><span class="muted">Member</span><br><b>' . e($pc['patient_name']) . '</b>' . ($pmn !== '' ? '<br><span class="muted">' . e($pmn) . ($pmc !== '' ? ' · card ' . e($pmc) : '') . '</span>' : '') . '</td>'
      . '<td style="border:none"><span class="muted">Status</span><br><b>' . e(cl_slabel($pc['status'])) . '</b></td>'
      . '<td style="border:none"><span class="muted">Submitted</span><br>' . e(substr((string)($pc['submitted_at'] ?: $pc['created_at']), 0, 16)) . ' <span class="muted">by ' . e($pc['submitted_by']) . '</span></td></tr></table>'
      . '<table><tr><th>#</th><th>Service / item</th><th>Category</th><th class="num">Qty</th><th class="num">Unit price (UGX)</th><th class="num">Amount (UGX)</th></tr>';
    $n70 = 0;
    foreach ($plines as $ln) {
        $n70++; $amtL = round((float)(isset($ln['qty']) ? $ln['qty'] : 1) * (float)(isset($ln['price']) ? $ln['price'] : 0), 2);
        $billRef = (isset($ln['bill_no']) && $ln['bill_no'] !== '') ? ' <span class="muted">bill ' . e($ln['bill_no']) . (isset($ln['receipt_no']) && $ln['receipt_no'] !== '' ? ' · receipt ' . e($ln['receipt_no']) : '') . '</span>' : '';
        echo '<tr><td>' . $n70 . '</td><td><b>' . e(isset($ln['name']) ? $ln['name'] : '') . '</b>' . $billRef . '</td><td>' . e(isset($ln['cat']) ? $ln['cat'] : '') . '</td>'
          . '<td class="num">' . e(rtrim(rtrim(number_format((float)(isset($ln['qty']) ? $ln['qty'] : 1), 2, '.', ''), '0'), '.')) . '</td>'
          . '<td class="num">' . number_format((float)(isset($ln['price']) ? $ln['price'] : 0), 0) . '</td><td class="num">' . number_format($amtL, 0) . '</td></tr>';
    }
    echo '<tr><th colspan="5" style="text-align:right">Total billed</th><th class="num">' . number_format((float)$pc['amount_billed'], 0) . '</th></tr>';
    if ($pappr !== null) {
        echo '<tr><th colspan="5" style="text-align:right">Approved by Kisubi</th><th class="num">' . number_format($pappr, 0) . '</th></tr>'
          . '<tr><th colspan="5" style="text-align:right">Disbursed to date</th><th class="num">' . number_format($ppaid, 0) . '</th></tr>'
          . '<tr><th colspan="5" style="text-align:right;color:#8a1c1c">Outstanding</th><th class="num" style="color:#8a1c1c">' . number_format(max(0, $pappr - $ppaid), 0) . '</th></tr>';
    }
    echo '</table>';
    if ((string)$pc['note'] !== '') echo '<p><span class="muted">Facility note</span><br>' . e($pc['note']) . '</p>';
    if ((string)$pc['reject_reason'] !== '') echo '<p style="color:#8a1c1c"><span class="muted">Kisubi remarks (decision)</span><br>' . e($pc['reject_reason']) . '</p>';
    if ($evs) { $txt7 = ''; foreach (array_slice($evs, -6) as $ev) $txt7 .= '• ' . substr((string)$ev['at'], 0, 16) . ' — ' . $ev['action'] . ($ev['note'] !== '' ? ': ' . $ev['note'] : '') . '<br>'; echo '<p class="muted"><b>Event trail:</b><br>' . $txt7 . '</p>'; }
    echo '<p class="muted" style="margin-top:26px">Generated live from the scheme database. Deposit-funded portions are tracked separately from Kisubi-payable amounts — nothing is paid twice. Facility signature ____________________ · Kisubi officer ____________________</p>'
      . '<div class="noprint" style="margin-top:14px"><button onclick="window.print()" style="padding:8px 18px;font-weight:bold;background:#1B6BB3;color:#fff;border:none;border-radius:5px;cursor:pointer">Print</button> <a href="facility-claims.php" style="font-size:12px">Back to claims</a></div></body></html>';
    exit;
}

/* ---------- data ---------- */
if (!fac_has_tbl()) { $page_title = 'Claims & Disbursements'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>Facility module not installed yet</h2><p>Run <a href="migrate.php">migrate.php</a> once to create the facilities, claims and disbursement tables.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit; }
$where = '1=1'; $args = array();
if ($GF > 0) { $where .= ' AND c.facility_id=?'; $args[] = $GF; }
$fsel = get('status'); if (in_array($fsel, cl_statuses(), true)) { $where .= ' AND c.status=?'; $args[] = $fsel; }
$minAmt = (float)str_replace(',', '', get('min')); $maxAmt = (float)str_replace(',', '', get('max'));
if ($minAmt > 0) { $where .= ' AND c.amount_billed>=?'; $args[] = $minAmt; }
if ($maxAmt > 0) { $where .= ' AND c.amount_billed<=?'; $args[] = $maxAmt; }
$df = get('date_from'); $dt2 = get('date_to');
if ($df !== '') { $where .= ' AND COALESCE(c.submitted_at, c.created_at) >= ?'; $args[] = $df . ' 00:00:00'; }
if ($dt2 !== '') { $where .= ' AND COALESCE(c.submitted_at, c.created_at) <= ?'; $args[] = $dt2 . ' 23:59:59'; }
$kp = get('q'); if ($kp !== '') { $where .= ' AND (c.claim_no LIKE ? OR c.facility_name LIKE ? OR c.patient_name LIKE ? OR EXISTS (SELECT 1 FROM members mmq WHERE mmq.id=c.member_id AND mmq.member_no LIKE ?))'; $args[] = "%$kp%"; $args[] = "%$kp%"; $args[] = "%$kp%"; $args[] = "%$kp%"; }
$pgN = max(0, intval(get('page'))); $perPg = 40;
$stC = db()->prepare("SELECT COUNT(*) FROM facility_claims c WHERE $where"); $stC->execute($args); $totClaims = intval($stC->fetchColumn());
$st = db()->prepare("SELECT c.* FROM facility_claims c WHERE $where ORDER BY (c.status='Pending') DESC, c.id DESC LIMIT $perPg OFFSET " . ($pgN * $perPg));
$st->execute($args); $claims = $st->fetchAll();
$pagesN = max(1, intval(ceil($totClaims / $perPg)));
if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="claims-' . date('Ymd-His') . '.csv"');
    $out41 = fopen('php://output', 'w');
    fputcsv($out41, array('Claim no', 'Facility', 'Code', 'Patient', 'Service date', 'Submitted', 'Billed', 'Approved', 'Paid', 'Outstanding', 'Status', 'Category', 'Submitting user'));
    foreach ($claims as $cx) {
        $apx = $cx['amount_approved'] !== null ? (float)$cx['amount_approved'] : (float)$cx['amount_billed'];
        $pdx = claim_paid_total(intval($cx['id']));
        fputcsv($out41, array($cx['claim_no'], $cx['facility_name'], ($fx41 = fac_of(intval($cx['facility_id']))) ? $fx41['code'] : '', $cx['patient_name'], (cl_has('facility_claims','service_date') && $cx['service_date']) ? substr((string)$cx['service_date'],0,10) : '', substr((string)($cx['submitted_at'] ?: $cx['created_at']),0,10), round((float)$cx['amount_billed'],2), round($apx,2), round($pdx,2), round(max(0,$apx-$pdx),2), cl_slabel($cx['status']), cl_has('facility_claims','category') ? $cx['category'] : '', $cx['submitted_by']));
    }
    fclose($out41); exit;
}
$_in = "'Pending','Submitted','Resubmitted','Under Review'"; $_ap = "'Approved','Partially Approved','Cleared'";
$kpis = db()->query("SELECT COUNT(CASE WHEN status IN ($_in) THEN 1 END) pend, COALESCE(SUM(CASE WHEN status IN ($_in) THEN amount_billed END),0) pendamt,
    COALESCE(SUM(CASE WHEN status IN ($_ap) THEN COALESCE(amount_approved, amount_billed) END),0) apptamt,
    COALESCE(SUM(CASE WHEN status IN ('Paid','PartPaid') THEN COALESCE(amount_paid, COALESCE(amount_approved, amount_billed)) END),0) paidamt,
    COUNT(CASE WHEN status IN ('Paid','PartPaid') THEN 1 END) paidc FROM facility_claims")->fetch();

$view = get('view');
$newClaim = null;
$priceItems = array();
if ($view === 'new') {
    $fidN = $lockedFid > 0 ? $lockedFid : ($GF > 0 ? $GF : (count(fac_list()) ? intval(fac_list()[0]['id']) : 0));
    if ($fidN) { $newClaim = fac_of($fidN); $priceItems = fac_price_items($fidN); }
}

$page_title = $OWNER ? 'Owner desk — Claims &amp; Disbursements' : 'Claims &amp; Disbursements';
include __DIR__ . '/includes/header.php';
?>
<?php if (function_exists('mb_retry_pending') && mb_retry_pending() > 0): ?><div class="panel" style="border:2px solid #b8860b;background:#FFF9EC;padding:9px 13px;margin:8px 0">&#9888; <b><?php echo num(mb_retry_pending()); ?></b> settled bill transmission(s) failed their automatic claim build and are awaiting retry — the portal retries each time it is opened (up to 10 attempts); nothing is duplicated because a bill already on a claim line is skipped. A facility can also submit them manually via the claims batch.</div><?php endif; ?>
<div class="page-head"><h1 style="color:#8a1c1c">&#9878;&#65039; Claims &amp; Disbursements — <?php echo $OWNER ? 'Owner desk' : ($lockedFid > 0 ? e(fac_name($lockedFid)) : 'All facilities'); ?></h1>
<?php if ($view !== 'new'): ?><a class="btn danger" href="facility-claims.php?view=new" style="box-shadow:0 3px 10px rgba(138,28,28,.28)">+ New claim from <?php echo $lockedFid > 0 ? e(fac_name($lockedFid)) : 'my facility'; ?></a><?php endif; ?></div>

<div class="kpi-row">
  <div class="kpi gold"><div class="k-label">Awaiting owner decision</div><div class="k-value"><?php echo num($kpis['pend']); ?></div><div class="k-sub"><?php echo ugx($kpis['pendamt']); ?> billed</div></div>
  <div class="kpi"><div class="k-label">Approved, unpaid</div><div class="k-value"><?php echo ugx($kpis['apptamt']); ?></div><div class="k-sub">ready to clear</div></div>
  <div class="kpi green"><div class="k-label">Cleared (disbursed)</div><div class="k-value"><?php echo ugx($kpis['paidamt']); ?></div><div class="k-sub"><?php echo num($kpis['paidc']); ?> payment<?php echo intval($kpis['paidc']) === 1 ? '' : 's'; ?></div></div>
</div>

<form method="get" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:10px;flex-wrap:wrap">
<?php if (!$lockedFid && count(fac_list()) > 0): ?>
<div style="min-width:230px"><label class="f" style="margin-bottom:2px">Facility</label>
<select class="f" name="facility" onchange="this.form.submit()"><option value="0">All facilities</option>
<?php foreach (fac_list() as $o): ?><option value="<?php echo e($o['id']); ?>" <?php echo intval($o['id'])===$GF?'selected':''; ?>><?php echo fac_icon($o['ftype']); ?> <?php echo e($o['name']); ?> (<?php echo e($o['code']); ?>)</option><?php endforeach; ?></select></div>
<?php endif; ?>
<div style="min-width:200px"><label class="f" style="margin-bottom:2px">Status</label>
<select class="f" name="status" onchange="this.form.submit()"><option value="">Any</option>
<?php foreach (array('Draft','Submitted','Under Review','Returned','Resubmitted','Partially Approved','Approved','Cleared','PartPaid','Paid','Rejected') as $s): ?><option value="<?php echo e($s); ?>" <?php echo $fsel===$s?'selected':''; ?>><?php echo e(cl_slabel($s)); ?></option><?php endforeach; ?></select></div>
<div style="min-width:96px"><label class="f" style="margin-bottom:2px">Amount UGX</label><input class="f" name="min" value="<?php echo e(get('min')); ?>" placeholder="from" style="margin:0;padding:5px 8px" inputmode="numeric"></div>
<div style="min-width:96px"><label class="f" style="margin-bottom:2px">&nbsp;</label><input class="f" name="max" value="<?php echo e(get('max')); ?>" placeholder="to" style="margin:0;padding:5px 8px" inputmode="numeric"></div>
<div style="min-width:120px"><label class="f" style="margin-bottom:2px">Submitted from</label><input class="f" type="date" name="date_from" value="<?php echo e(get('date_from')); ?>" style="margin:0;padding:5px 8px"></div>
<div style="min-width:120px"><label class="f" style="margin-bottom:2px">to</label><input class="f" type="date" name="date_to" value="<?php echo e(get('date_to')); ?>" style="margin:0;padding:5px 8px"></div>
<div style="flex:1;min-width:180px"><label class="f" style="margin-bottom:2px">Search</label><input class="f" name="q" value="<?php echo e($kp); ?>" placeholder="claim no, facility, patient"></div>
<button class="btn" type="submit">Apply</button>
</form>

<?php /* correct & resubmit view */ if ($view === 'fix'): $fx = null;
  $stf = db()->prepare('SELECT * FROM facility_claims WHERE id=?' . ($lockedFid > 0 ? ' AND facility_id=' . $lockedFid : '')); $stf->execute(array(intval(get('id')))); $fx = $stf->fetch();
  if (!$fx || !in_array($fx['status'], array('Returned', 'Draft'), true)) { flash('That claim cannot be corrected here.', 'err'); redirect('facility-claims.php'); }
  $itx = json_decode((string)$fx['items_json'], true); if (!is_array($itx)) $itx = array(); ?>
<div class="panel" style="border-top:3px solid #C89211;max-width:900px">
<h2 style="margin-top:0">&#9998;&#65039; Correct &amp; resubmit — <?php echo e($fx['claim_no']); ?> <span style="font:normal 11px Arial;color:#7a94aa">(originating facility: <?php echo e($fx['facility_name']); ?> — fixed)</span></h2>
<p style="font-size:12.5px;color:#8c2424;margin:4px 0 10px"><b>Owner's reason:</b> <?php echo e((string)$fx['reject_reason'] !== '' ? $fx['reject_reason'] : '—'); ?></p>
<form method="post"><input type="hidden" name="op" value="revise"><input type="hidden" name="id" value="<?php echo e($fx['id']); ?>"><?php echo csrf_field(); ?>
<table class="data" style="margin:0 0 10px"><tr><th>Service item</th><th style="width:110px">Qty</th><th style="width:150px">Unit price (UGX)</th><th class="noprint" style="width:30px"></th></tr>
<?php for ($i = 0; $i < max(1, count($itx)) + 3; $i++): $r0 = isset($itx[$i]) ? $itx[$i] : null; ?>
<tr><td><input class="f" name="rx_name[]" value="<?php echo e($r0 ? $r0['name'] : ''); ?>" placeholder="Item / service description"></td>
<td><input class="f num" type="number" step="0.25" min="0" name="rx_qty[]" value="<?php echo e($r0 ? rtrim(rtrim(number_format((float)$r0['qty'], 2, '.', ''), '0'), '.') : '1'); ?>"></td>
<td><input class="f num" type="number" step="1" min="0" name="rx_price[]" value="<?php echo e($r0 ? number_format((float)$r0['price'], 0, '.', '') : ''); ?>"></td>
<td class="noprint" style="color:#9db8cf;text-align:center">&middot;</td></tr>
<?php endfor; ?></table>
<div class="formgrid" style="grid-template-columns:1fr 1fr">
  <div><label class="f">Patient name</label><input class="f" value="<?php echo e($fx['patient_name']); ?>" disabled></div>
  <div><label class="f">What was corrected (for the owner)</label><input class="f" name="note" placeholder="e.g. removed non-covered panel, re-typed qty"></div>
</div>
<p style="margin:12px 0 0"><button class="btn danger" type="submit">Resubmit claim to owner</button> <a class="btn plain" href="facility-claims.php">Cancel</a></p>
</form></div>
<?php endif; ?>
<div class="panel" style="border-top:3px solid #8a1c1c">
<h2 style="margin-top:0"><?php echo $GF ? e(fac_name($GF)) . ' — claims register' : 'Claims register'; ?> <span style="font:normal 11px Arial;color:#7a94aa">(<?php echo count($claims); ?> shown)</span></h2>
<?php if (!$claims): ?><p style="color:#5b7c99">No claims here yet — facilities build a claim from their price list and submit it; the owner decides below each row.</p><?php else: ?>
<table class="data" style="margin:0">
<tr><th>Claim</th><th>From (facility)</th><th>Patient / member</th><th class="num">Billed</th><th class="num">Approved</th><th>Status</th><th>Timeline</th><th>Owner actions</th></tr>
<?php foreach ($claims as $c): $itemsC = json_decode((string)$c['items_json'], true); $nl = is_array($itemsC) ? count($itemsC) : 0; ?>
<tr>
 <td style="white-space:nowrap"><b><a href="claim-view.php?id=<?php echo e($c['id']); ?>" title="Open the full review sheet" style="text-decoration:none"><?php echo e($c['claim_no']); ?></a></b>&nbsp;<a href="claim-receipt.php?id=<?php echo e($c['id']); ?>" target="_blank" style="font-size:10px;color:#5b7c99">&#128438;</a>&nbsp;<a href="facility-claims.php?printclaim=<?php echo e($c['id']); ?>" target="_blank" title="Print claim schedule" style="font-size:10px;color:#5b7c99">&#128424;</a><br><span style="font-size:10.5px;color:#7a94aa"><?php echo num($nl); ?> line<?php echo $nl === 1 ? '' : 's'; ?> &middot; visit <?php echo e($c['visit_date'] !== '' && $c['visit_date'] !== null ? date('d M Y', strtotime($c['visit_date'])) : '—'); ?></span></td>
 <td style="white-space:nowrap"><b><?php echo $c['facility_name']; ?></b><?php $ff = fac_of(intval($c['facility_id'])); if ($ff) echo ' ' . fac_chip($ff['ftype']); ?></td>
 <td style="font-size:12px"><?php echo e($c['patient_name'] !== '' ? $c['patient_name'] : '—'); ?><?php if (intval($c['member_id']) > 0): $mm2 = db()->prepare('SELECT member_no,full_names FROM members WHERE id=?'); $mm2->execute(array(intval($c['member_id']))); $mrow = $mm2->fetch(); if ($mrow) echo '<br><span style="color:#7a94aa">' . e($mrow['member_no']) . '</span>'; endif; ?></td>
 <td class="num"><?php echo ugx($c['amount_billed']); ?></td>
 <td class="num"><b><?php echo $c['amount_approved'] !== null ? ugx($c['amount_approved']) : '—'; ?></b></td>
 <td><?php echo fac_status_chip($c['status']); ?><?php if ($c['status'] === 'Rejected' && $c['reject_reason'] !== ''): ?><br><span style="font-size:10.5px;color:#8c2424"><?php echo e($c['reject_reason']); ?></span><?php endif; ?></td>
 <td style="font-size:10.5px;color:#5b7c99;white-space:nowrap">
  <?php if ($c['submitted_at']): ?>sent <?php echo e(date('d M H:i', strtotime($c['submitted_at']))); ?><?php endif; ?>
  <?php if ($c['decided_at']): ?><br>decided <?php echo e(date('d M H:i', strtotime($c['decided_at']))); ?> by <?php echo e($c['decided_by']); ?><?php endif; ?>
  <?php if ($c['paid_at']): ?><br><span style="color:#20603a">cleared <?php echo e(date('d M H:i', strtotime($c['paid_at']))); ?> &middot; <?php echo e($c['pay_method']); ?> &middot; ref <?php echo e($c['pay_ref']); ?></span><?php endif; ?>
 </td>
 <td style="white-space:nowrap;font-size:12px">
  <?php if ($c['status'] === 'Draft' && ($OWNER || $lockedFid == intval($c['facility_id']))): ?>
    <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="submit_draft"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>"><button class="btn small primary" type="submit">Submit to owner</button></form>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this draft?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="cancel_draft"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>"><button class="btn small" type="submit">&#10007;</button></form>
  <?php elseif ($c['status'] === 'Returned' && ($OWNER || $lockedFid == intval($c['facility_id']))): ?><a class="btn small gold" href="facility-claims.php?view=fix&id=<?php echo e($c['id']); ?>">&#9998;&#65039; Correct &amp; resubmit</a>
  <?php elseif ($OWNER && in_array($c['status'], array('Pending', 'Submitted', 'Resubmitted', 'Under Review', 'Partially Approved'), true)): ?>
    <details<?php echo $c['status'] === 'Pending' || $c['status'] === 'Submitted' ? ' open' : ''; ?>><summary style="cursor:pointer;font-weight:bold;color:#8a1c1c">Decide&#9662;</summary>
     <div style="padding:6px 0">
     <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><?php echo csrf_field(); ?><input type="hidden" name="op" value="approve"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>">
      Approve <input class="f" name="amount_approved" style="width:120px;margin:0" value="<?php echo number_format((float)$c['amount_billed'], 0); ?>"> <button class="btn small success" type="submit">&#10003; Approve</button></form>
     <form method="post" style="display:flex;gap:6px;align-items:center;margin-top:6px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="return"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>">
      <input class="f" name="reason" style="flex:1;margin:0" placeholder="return for correction — what must the facility fix?"> <button class="btn small gold" type="submit">&#9998;&#65039; Return</button></form>
     <form method="post" style="display:flex;gap:6px;align-items:center;margin-top:6px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="reject"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>">
      <input class="f" name="reason" style="flex:1;margin:0" placeholder="reason (sent to the facility)"> <button class="btn small danger" type="submit">&#10007; Reject</button></form>
     <div style="font-size:11px;color:#7a94aa;margin-top:4px">Per-line decisions live on the <a href="claim-view.php?id=<?php echo e($c['id']); ?>">review sheet</a>. Lines: <?php
        $linesTxt = array();
        foreach ((is_array($itemsC) ? array_slice($itemsC, 0, 6) : array()) as $r1) {
            $q1 = rtrim(rtrim(number_format((float)$r1['qty'], 2, '.', ''), '0'), '.');
            $linesTxt[] = $r1['name'] . '×' . ($q1 !== '' ? $q1 : '1') . '@' . number_format((float)$r1['price'], 0);
        }
        echo e(implode(' · ', $linesTxt)); if ($nl > 6) echo ' …'; ?></div>
     </div></details>
  <?php elseif ($OWNER && in_array($c['status'], array('Approved', 'Cleared', 'PartPaid'), true)): $outv41 = max(0, (float)($c['amount_approved'] !== null ? $c['amount_approved'] : $c['amount_billed']) - claim_paid_total(intval($c['id']))); ?>
    <?php if ($c['status'] === 'Approved'): ?><form method="post" style="display:inline;margin:0 0 6px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="clear"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>"><button class="btn small gold" type="submit" onclick="return confirm('Clear this claim for payment?')">&#10003; Clear for payment</button></form><?php endif; ?>
    <details open><summary style="cursor:pointer;font-weight:bold;color:#1B6BB3">Disburse &#128181; <span style="font:normal 11px Arial;color:#5b7c99">(outstanding <?php echo ugx($outv41); ?>)</span></summary>
     <form method="post" style="padding:6px 0;display:flex;gap:6px;align-items:center;flex-wrap:wrap"><?php echo csrf_field(); ?><input type="hidden" name="op" value="pay"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>">
      <select class="f" name="pay_method" style="width:150px;margin:0"><?php foreach (array('Bank Transfer','Cash','MTN MoMo','Airtel Money') as $m0): ?><option><?php echo $m0; ?></option><?php endforeach; ?></select>
      <input class="f" name="amount_paid" style="width:120px;margin:0" value="<?php echo number_format($outv41, 0); ?>" title="full outstanding, or a partial amount">
      <input class="f" name="pay_ref" style="width:130px;margin:0" placeholder="txn ref (auto)">
      <input class="f" name="invoice_no" style="width:140px;margin:0" placeholder="facility invoice no.">
      <input class="f" type="date" name="invoice_date" style="width:140px;margin:0" value="<?php echo e(today()); ?>">
      <input class="f" name="invoice_amount" style="width:110px;margin:0" placeholder="invoice UGX" value="<?php echo number_format((float)$c['amount_billed'], 0); ?>">
      <button class="btn small success" type="submit" onclick="return confirm('Disburse UGX <?php echo e(number_format($outv41, 0)); ?> to <?php echo e(addslashes($c['facility_name'])); ?>?')">Pay</button></form>
    </details>
  <?php elseif ($c['status'] === 'Paid'): ?>
    <a class="btn small" href="claim-receipt.php?id=<?php echo e($c['id']); ?>" target="_blank">&#128424; Receipt</a>
  <?php elseif ($OWNER): ?><a class="btn small plain" href="claim-view.php?id=<?php echo e($c['id']); ?>">Open sheet</a>
  <?php else: ?><span style="color:#9db8cf">—</span><?php endif; ?>
 </td>
</tr>
<?php endforeach; ?>
</table>
<?php if ($pagesN > 1): $qs70 = http_build_query(array_filter(array('q' => get('q'), 'status' => get('status'), 'date_from' => get('date_from'), 'date_to' => get('date_to')))); ?>
<div style="display:flex;gap:8px;align-items:center;padding:10px 0 0;font-size:12px;color:#5b7c99">Page <?php echo $pgN + 1; ?> of <?php echo $pagesN; ?> &middot; <?php echo $totClaims; ?> claim<?php echo $totClaims == 1 ? '' : 's'; ?> in view<?php if ($pgN > 0): ?> <a class="btn small plain" style="text-decoration:none" href="facility-claims.php?<?php echo e($qs70); ?>&page=<?php echo $pgN - 1; ?>">&laquo; Previous</a><?php endif; ?><?php if ($pgN + 1 < $pagesN): ?> <a class="btn small plain" style="text-decoration:none" href="facility-claims.php?<?php echo e($qs70); ?>&page=<?php echo $pgN + 1; ?>">Next &raquo;</a><?php endif; ?></div><?php endif; ?>
<?php endif; ?>
</div>

<?php if ($view === 'new' && $newClaim): $f = $newClaim; ?>
<div class="panel" style="border:2px solid #1B6BB3;background:#F7FBFF">
<h2 style="margin-top:0">&#9997; New claim from <?php echo fac_icon($f['ftype']); ?> <?php echo e($f['name']); ?>
  <span style="font:normal 11px Arial;color:#5b7c99">— tick &amp; price your lines from this facility's additive list</span></h2>
<form method="post" id="claimForm"><?php echo csrf_field(); ?><input type="hidden" name="op" value="save_claim">
<div class="formgrid" style="grid-template-columns:repeat(4,1fr)">
  <?php if (!$lockedFid): ?><div><label class="f">Facility</label><input class="f" value="<?php echo e($f['name']); ?>" readonly></div><?php endif; ?>
  <div><label class="f">Patient name</label><input class="f" name="patient_name" placeholder="as on the member file"></div>
  <div><label class="f">Member (optional)</label><select class="f" name="member_id"><option value="0">— not a named member —</option>
   <?php foreach (db()->query("SELECT id, member_no, full_names FROM members WHERE status='Active' ORDER BY full_names LIMIT 300")->fetchAll() as $mo): ?><option value="<?php echo e($mo['id']); ?>"><?php echo e($mo['member_no']); ?> — <?php echo e($mo['full_names']); ?></option><?php endforeach; ?></select></div>
  <div><label class="f">Visit / service date</label><input class="f" type="date" name="visit_date" value="<?php echo e(today()); ?>"></div>
  <div><label class="f">Claim category</label><select class="f" name="category"><option value="">General</option>
    <?php foreach (array('OPD','IPD','Emergency','Maternity','Surgical','Dental','Optical','Ortho','Theatre','Combined') as $ct41): ?><option><?php echo $ct41; ?></option><?php endforeach; ?></select></div>
  <div><label class="f">Service date(s) covered</label><input class="f" type="date" name="service_date" value=""></div>
  <div><label class="f">Period (e.g. Oct 2026)</label><input class="f" name="period_label" value="<?php echo e(date('M Y')); ?>"></div>
</div>
<?php if ($priceItems): ?>
<div style="max-height:300px;overflow:auto;border:1px solid #cfe0ef;border-radius:4px;margin-top:8px">
<table class="data" style="margin:0">
<tr><th style="width:34px">&#10003;</th><th>Item (<?php echo e($f['name']); ?> list)</th><th>Cat.</th><th class="num">Unit price</th><th style="width:90px">Qty</th></tr>
<?php foreach ($priceItems as $p): ?>
<tr><td><input type="checkbox" name="qtyck[<?php echo e($p['id']); ?>]" data-price="<?php echo e($p['price']); ?>" data-name="<?php echo e($p['item_name']); ?>"></td>
<td><b><?php echo e($p['item_name']); ?></b> <?php echo intval($p['is_base']) ? '<span style="font:9px Arial;color:#1B6BB3">STANDARD</span>' : '<span style="font:9px Arial;color:#8a6410">ADD-ON</span>'; ?> <i style="color:#7a94aa;font-size:11px">(<?php echo e($p['unit']); ?>)</i></td>
<td style="font-size:12px;color:#5b7c99"><?php echo e($p['category']); ?></td>
<td class="num"><?php echo number_format((float)$p['price'], 0); ?></td>
<td><input class="f qtyin" name="qty[<?php echo e($p['id']); ?>]" value="" style="width:80px;margin:0;text-align:right" placeholder="0" inputmode="numeric"></td></tr>
<?php endforeach; ?>
</table></div>
<?php else: ?><p style="color:#8a6410;font-size:12.5px">This facility's price list is empty — lines can still be added below, or seed the list in <a href="pricelist.php?facility=<?php echo e($f['id']); ?>">Price lists</a>.</p><?php endif; ?>

<div style="margin-top:10px"><label class="f">Extra additive lines (not on the list)</label>
<div id="cxRows"></div>
<button class="btn small" type="button" onclick="cxAdd()">+ Add custom line</button></div>
<div class="formgrid" style="grid-template-columns:2fr 1fr;margin-top:10px">
<div><label class="f">Note to the owner (optional)</label><input class="f" name="note" placeholder="e.g. admit since 12 Oct, discharged 16 Oct"></div>
<div class="num" style="align-self:end;font:bold 18px Georgia;color:#12476f">Total: UGX <span id="claimTot">0</span></div>
</div>
<div style="margin-top:12px;display:flex;gap:8px">
  <button class="btn danger" type="submit" name="mode" value="submit">Send to owner for clearing</button>
  <button class="btn" type="submit" name="mode" value="draft">Save as draft</button>
  <a class="btn plain" href="facility-claims.php">Cancel</a>
</div>
</form>
<script>
function fmt(n){return n.toLocaleString('en-US');}
function cxAdd(){var d=document.createElement('div');d.style.cssText='display:flex;gap:6px;margin-top:6px';
d.innerHTML='<input class="f cxn" placeholder="Item name" style="flex:2;margin:0"><input class="f cxq" placeholder="qty" style="width:70px;margin:0" inputmode="decimal"><input class="f cxp" placeholder="unit price" style="width:120px;margin:0" inputmode="numeric"><button class="btn small" type="button" onclick="this.parentNode.remove();calc()">&#10007;</button>';
document.getElementById('cxRows').appendChild(d);d.querySelectorAll('input').forEach(function(i){i.addEventListener('input',calc);});}
function calc(){var t=0;
document.querySelectorAll('input[name^="qtyck"]').forEach(function(ch){var id=ch.name.slice(7,-1);var q=document.querySelector('input[name="qty['+id+']"]');var v=ch.checked?parseFloat((q.value||'0').replace(/,/g,''))||0:0;if(ch.checked&&!q.value)q.value='1',v=1;t+=v*parseFloat(ch.getAttribute('data-price'));});
document.querySelectorAll('#cxRows>div').forEach(function(r){var n=r.querySelector('.cxn'),q=r.querySelector('.cxq'),p=r.querySelector('.cxp');if(n&&n.value.trim())t+=(parseFloat((q.value||'1').replace(/,/g,''))||1)*(parseFloat((p.value||'0').replace(/,/g,''))||0);});
document.getElementById('claimTot').textContent=fmt(Math.round(t));}
document.getElementById('claimForm').addEventListener('input',calc);
document.getElementById('claimForm').addEventListener('change',calc);
document.querySelector('input[name="patient_name"]').addEventListener('input',function(){});
document.getElementById('claimForm').addEventListener('submit',function(ev){var t=parseInt(document.getElementById('claimTot').textContent.replace(/,/g,''),10)||0;if(t<=0){alert('The claim is UGX 0 — tick at least one priced line.');ev.preventDefault();}});
calc();
</script>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
