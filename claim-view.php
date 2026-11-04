<?php
/** v4.1 Claim review — the full life of one claim on a single page: services claimed with the
 *  facility price list beside them (variance flags), item-level approval decisions with reasons,
 *  supporting documents (invoices / medical reports), the complete action history, and every
 *  workflow button for the facility side (draft → submit → revise) and the owner desk
 *  (review → approve/partial → clear → pay → done). Printing: browser print of this page. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/clinical-core.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));

$OWNER = fac_is_owner();
$lockedFid = fac_scope_fid();
$id = intval(get('id')); if ($id <= 0) $id = intval(post('id'));
if ($id <= 0) { flash('Claim not specified.', 'err'); redirect('facility-claims.php'); }
$st = db()->prepare('SELECT c.*, f.code fac_code, f.parent_id fac_parent, f.ftype fac_type, f.address fac_address, f.phone fac_phone FROM facility_claims c LEFT JOIN facilities f ON f.id=c.facility_id WHERE c.id=?');
$st->execute(array($id)); $c = $st->fetch();
if (!$c) { flash('Claim not found.', 'err'); redirect('facility-claims.php'); }
if ($lockedFid > 0 && intval($c['facility_id']) !== $lockedFid) { flash('That claim belongs to another facility.', 'err'); redirect('facility-claims.php'); }
$IS_FAC = !$OWNER && !$lockedFid ? false : $lockedFid > 0;   // facility-side operator
if ($lockedFid === 0 && !$OWNER && !can(array('Administrator', 'Finance'))) { flash('Not permitted.', 'err'); redirect('facility-claims.php'); }
$by = $_SESSION['uname'];
$F = fac_of(intval($c['facility_id']));
$parent = $F && intval($F['parent_id']) > 0 ? fac_of(intval($F['parent_id'])) : null;
$items = json_decode((string)$c['items_json'], true); if (!is_array($items)) $items = array();
$reviews = $OWNER ? claim_reviews_of($id) : claim_reviews_of($id); // both sides may inspect
$events = claim_events_of($id);
if ($OWNER && cl_has('facility_claims', 'received_at') && in_array($c['status'], array('Submitted', 'Pending', 'Resubmitted'), true) && (string)$c['received_at'] === '') {
    db()->prepare("UPDATE facility_claims SET status='Received', received_at=? WHERE id=? AND status=?")->execute(array(date('Y-m-d H:i:s'), $id, $c['status']));
    claim_event($id, 'Received by Kisubi', 'Claim collected by the central claims desk.', $by);
    $c['status'] = 'Received';
    if (function_exists('mb_notify')) { } // (funding block below renders when claim_lines exist)
}
$CLINES = array();
try { db()->query('SELECT id FROM claim_lines LIMIT 1'); $stCL = db()->prepare('SELECT * FROM claim_lines WHERE claim_id=? ORDER BY line_no'); $stCL->execute(array($id)); $CLINES = $stCL->fetchAll(); } catch (Exception $e) {}
$docs = array(); if (cl_tbl('claim_docs')) { $stD = db()->prepare('SELECT * FROM claim_docs WHERE claim_id=? ORDER BY id'); $stD->execute(array($id)); $docs = $stD->fetchAll(); }
$appr = $c['amount_approved'] !== null ? (float)$c['amount_approved'] : (float)$c['amount_billed'];
$paid = claim_paid_total($id); $outstanding = max(0, $appr - $paid);
$pays = array();
try { $stP = db()->prepare('SELECT * FROM disbursements WHERE claim_id=? ORDER BY id'); $stP->execute(array($id)); $pays = $stP->fetchAll(); } catch (Exception $e) {}
$revs = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    /* ---------- facility side ---------- */
    if ($op === 'submit' || $op === 'resubmit') {
        if (!$IS_FAC && !fac_is_desk()) { flash('Facility staff submit their own claims.', 'err'); redirect('claim-view.php?id=' . $id); }
        if (!in_array($c['status'], array('Draft', 'Returned'), true)) { flash('Only a draft or a returned claim can be submitted.', 'err'); redirect('claim-view.php?id=' . $id); }
        $docCount = 0; if (cl_tbl('claim_docs')) { $stD2 = db()->prepare('SELECT COUNT(*) FROM claim_docs WHERE id IS NOT NULL'); $stD2 = db()->prepare('SELECT COUNT(*) FROM claim_docs WHERE claim_id=?'); $stD2->execute(array($id)); $docCount = intval($stD2->fetchColumn()); }
        $new = $c['status'] === 'Returned' ? 'Resubmitted' : 'Submitted';
        db()->prepare('UPDATE facility_claims SET status=?, submitted_by=?, submitted_at=? WHERE id=?')->execute(array($new, $by, date('Y-m-d H:i:s'), $id));
        claim_event($id, $c['status'] === 'Returned' ? 'Resubmitted to owner' : 'Submitted to owner', 'Claim is now with the scheme owner for review. ' . $docCount . ' document(s) attached.', $by);
        cl_claim_accrue($id);
        log_action('Claim ' . $c['claim_no'] . ' ' . strtolower($new), 'facility_claims', $id);
        fac_notify($F, 'Claim ' . $c['claim_no'] . ' (' . $c['facility_name'] . ', ' . ugx((float)$c['amount_billed']) . ') is awaiting the scheme owner.');
        flash('Claim ' . $c['claim_no'] . ' sent to the scheme owner.', 'ok'); redirect('claim-view.php?id=' . $id);
    }
    if ($op === 'doc') {
        if (!$IS_FAC && !$OWNER) { flash('Not permitted.', 'err'); redirect('claim-view.php?id=' . $id); }
        if ($IS_FAC && !in_array($c['status'], array('Draft', 'Returned', 'Resubmitted', 'Submitted', 'Pending'), true)) { flash('Documents cannot be changed after the owner closes review.', 'err'); redirect('claim-view.php?id=' . $id); }
        $kind = post('kind'); if (!in_array($kind, array('Invoice', 'Medical report', 'Discharge summary', 'Receipt', 'Other'), true)) $kind = 'Other';
        if (!isset($_FILES['doc']) || $_FILES['doc']['error'] !== UPLOAD_ERR_OK) { flash('Attach a file first.', 'err'); redirect('claim-view.php?id=' . $id); }
        if ($_FILES['doc']['size'] > 2 * 1024 * 1024) { flash('Files are limited to 2 MB.', 'err'); redirect('claim-view.php?id=' . $id); }
        $mime = ''; if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = (string)finfo_file($fi, $_FILES['doc']['tmp_name']); finfo_close($fi); }
        $ext = strtolower(pathinfo($_FILES['doc']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('pdf','png','jpg','jpeg','txt','csv','doc','docx'), true)) { flash('Allowed: PDF, image, Word, CSV or TXT.', 'err'); redirect('claim-view.php?id=' . $id); }
        $dir = __DIR__ . '/data/claims'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $stored = 'claim' . $id . '-' . date('YmdHis') . '-' . substr(sha1(uniqid((string)mt_rand(), true)), 0, 10) . '.' . $ext;
        if (!@move_uploaded_file($_FILES['doc']['tmp_name'], $dir . '/' . $stored)) { flash('Could not store the upload.', 'err'); redirect('claim-view.php?id=' . $id); }
        db()->prepare('INSERT INTO claim_docs (claim_id, kind, orig_name, stored_name, size_bytes, uploaded_by, uploaded_at) VALUES (?,?,?,?,?,?,?)')
           ->execute(array($id, $kind, mb_substr($_FILES['doc']['name'], 0, 120), $stored, intval($_FILES['doc']['size']), $by, date('Y-m-d H:i:s')));
        claim_event($id, 'Document attached', $kind . ': ' . $_FILES['doc']['name'], $by);
        log_action('Attached ' . $kind . ' to claim ' . $c['claim_no'], 'claim_docs', intval(db()->lastInsertId()));
        flash('Document attached.', 'ok'); redirect('claim-view.php?id=' . $id);
    }
    /* ---------- owner side ---------- */
    if (!$OWNER) { flash('Only the scheme owner reviews and clears claims here.', 'err'); redirect('claim-view.php?id=' . $id); }
    if ($op === 'start_review') {
        if (!cl_is_incoming($c['status'])) { flash('Nothing to review on this claim right now.', 'err'); redirect('claim-view.php?id=' . $id); }
        db()->prepare("UPDATE facility_claims SET status='Under Review' WHERE id=?")->execute(array($id));
        claim_event($id, 'Under review', 'Owner opened the review.', $by); flash($c['claim_no'] . ' is under review.', 'info'); redirect('claim-view.php?id=' . $id);
    }
    if ($op === 'save_review') {
        $lines = array();
        $amts = isset($_POST['appr']) && is_array($_POST['appr']) ? $_POST['appr'] : array();
        $rsns = isset($_POST['reason']) && is_array($_POST['reason']) ? $_POST['reason'] : array();
        foreach ($items as $i => $it) { $lines[$i] = array('amount' => isset($amts[$i]) ? str_replace(',', '', (string)$amts[$i]) : '', 'reason' => isset($rsns[$i]) ? (string)$rsns[$i] : ''); }
        $sum = claim_review_save($id, $lines, $by);
        claim_event($id, 'Item decisions saved', 'Σ approved line amounts ' . ugx($sum) . '.', $by);
        flash('Item decisions recorded.', 'ok'); redirect('claim-view.php?id=' . $id);
    }
    if ($op === 'approve') {
        if (!cl_is_incoming($c['status']) && !in_array($c['status'], array('Partially Approved'), true)) { flash('Only submitted / reviewed claims can be approved.', 'err'); redirect('claim-view.php?id=' . $id); }
        $amt = (float)str_replace(',', '', post('amount_approved'));
        if (post('use_items') === '1') { $rev = claim_reviews_of($id); if ($rev) { $amt = 0.0; foreach ($rev as $r) $amt += (float)$r['approved_amount']; } }
        if ($amt <= 0) $amt = (float)$c['amount_billed'];
        if ($amt > (float)$c['amount_billed'] + 0.001) { flash('The approved amount cannot exceed the billed amount.', 'err'); redirect('claim-view.php?id=' . $id); }
        $rev = claim_reviews_of($id);
        $partial = $amt + 0.005 < (float)$c['amount_billed'];
        db()->prepare("UPDATE facility_claims SET status=?, amount_approved=?, decided_by=?, decided_at=? WHERE id=?")
           ->execute(array($partial ? 'Partially Approved' : 'Approved', $amt, $by, date('Y-m-d H:i:s'), $id));
        claim_event($id, $partial ? 'Partially approved' : 'Approved', 'At ' . ugx($amt) . ($partial ? ' (items reduced — reasons on record)' : '') . ($c['status'] === 'Rejected' ? '' : ''), $by);
        log_action(($partial ? 'Partially approved' : 'Approved') . ' claim ' . $c['claim_no'] . ' at ' . ugx($amt) . ' (was ' . ugx((float)$c['amount_billed']) . ')', 'facility_claims', $id);
        fac_notify($F, 'Your claim ' . $c['claim_no'] . ' was ' . ($partial ? 'PARTIALLY APPROVED' : 'APPROVED') . ' for UGX ' . number_format($amt, 0) . '. It will be cleared for payment soon.');
        mb_notify('claim_approved', array('claim_no' => $c['claim_no'], 'amount' => ugx($amt), 'inst' => $c['facility_name']), intval($c['facility_id']), intval($c['member_id']), 'facility_claims', $id);
        $ad = cl60_auto_disburse($id, $_SESSION['uname']);
        flash($c['claim_no'] . ' ' . ($partial ? 'partially ' : '') . 'approved at ' . ugx($amt) . '. ' . ($ad['ok'] ? $ad['msg'] : 'Ready to clear when you choose.'), 'ok'); redirect('claim-view.php?id=' . $id);
    }
    if ($op === 'return') {
        if (!cl_is_incoming($c['status'])) { flash('This claim cannot be returned right now.', 'err'); redirect('claim-view.php?id=' . $id); }
        $why = trim(post('reason')); if ($why === '') { flash('A return must state what to correct.', 'err'); redirect('claim-view.php?id=' . $id); }
        db()->prepare("UPDATE facility_claims SET status='Returned', decided_by=?, decided_at=?, reject_reason=? WHERE id=?")
           ->execute(array($by, date('Y-m-d H:i:s'), mb_substr($why, 0, 240), $id));
        claim_event($id, 'Returned for correction', $why, $by);
        log_action('Returned claim ' . $c['claim_no'] . ': ' . mb_substr($why, 0, 120), 'facility_claims', $id);
        fac_notify($F, 'Claim ' . $c['claim_no'] . ' was RETURNED for correction: ' . $why);
        flash($c['claim_no'] . ' returned to the facility for correction.', 'info'); redirect('claim-view.php?id=' . $id);
    }
    if ($op === 'reject') {
        if (!in_array($c['status'], array('Submitted', 'Received', 'Pending', 'Resubmitted', 'Under Review', 'Approved', 'Partially Approved', 'Cleared', 'Returned'), true)) { flash('This claim cannot be rejected now.', 'err'); redirect('claim-view.php?id=' . $id); }
        $why = trim(post('reason')); if ($why === '') $why = 'Supporting documents incomplete';
        db()->prepare("UPDATE facility_claims SET status='Rejected', decided_by=?, decided_at=?, reject_reason=? WHERE id=?")
           ->execute(array($by, date('Y-m-d H:i:s'), mb_substr($why, 0, 240), $id));
        claim_event($id, 'Rejected', $why, $by);
        log_action('Rejected claim ' . $c['claim_no'] . ' of ' . $c['facility_name'], 'facility_claims', $id);
        fac_notify($F, 'Claim ' . $c['claim_no'] . ' was REJECTED: ' . $why);
        flash($c['claim_no'] . ' rejected — the facility is notified.', 'info'); redirect('claim-view.php?id=' . $id);
    }
    if ($op === 'clear') {
        if (!in_array($c['status'], array('Approved', 'Partially Approved'), true)) { flash('Only approved claims can be cleared for payment.', 'err'); redirect('claim-view.php?id=' . $id); }
        db()->prepare("UPDATE facility_claims SET status='Cleared' WHERE id=?")->execute(array($id));
        claim_event($id, 'Cleared for payment', 'Ready for disbursement.', $by);
        log_action('Cleared claim ' . $c['claim_no'] . ' for disbursement', 'facility_claims', $id);
        flash($c['claim_no'] . ' cleared — disburse it from this page or the Disbursement manager.', 'ok'); redirect('claim-view.php?id=' . $id);
    }
    if ($op === 'pay') {
        if (!cl_can_disburse($c['status'])) { flash('A claim must be approved AND cleared before disbursement.', 'err'); redirect('claim-view.php?id=' . $id); }
        $amt = (float)str_replace(',', '', post('amount_paid')); if ($amt <= 0) $amt = $outstanding;
        if ($amt > $outstanding + 0.001) { flash('You cannot pay more than the ' . ugx($outstanding) . ' outstanding.', 'err'); redirect('claim-view.php?id=' . $id); }
        $method = post('pay_method'); if (!in_array($method, array('Cash', 'Bank Transfer', 'MTN MoMo', 'Airtel Money'), true)) $method = 'Bank Transfer';
        $ref = trim(post('pay_ref')); if ($ref === '') $ref = strtoupper(substr(md5($c['claim_no'] . microtime()), 0, 10));
        $dup = db()->prepare('SELECT id FROM disbursements WHERE claim_id=? AND reference=? AND amount=?'); $dup->execute(array($id, $ref, $amt));
        if ($dup->fetch()) { flash('That reference + amount was already recorded for this claim — not duplicated.', 'err'); redirect('claim-view.php?id=' . $id); }
        $invNo = trim(post('invoice_no')); $invDate = post('invoice_date') !== '' ? post('invoice_date') : date('Y-m-d');
        $invAmt = (float)str_replace(',', '', post('invoice_amount')); if ($invAmt <= 0) $invAmt = $amt;
        $hasInv = cl_has('facility_claims', 'invoice_no');
        $hasBatch = cl_has('disbursements', 'batch_id');
        $bid = $hasBatch ? intval(post('batch_id')) : 0;
        $vno = cl_has('disbursements', 'voucher_no') ? cl_voucher_no() : '';
        try {
            db()->beginTransaction();
            if ($hasInv) db()->prepare("UPDATE facility_claims SET invoice_no=?, invoice_date=?, invoice_amount=? WHERE id=? AND (invoice_no='' OR invoice_no IS NULL)")
               ->execute(array(mb_substr($invNo, 0, 60), $invDate, $invAmt, $id));
            if ($hasBatch) db()->prepare('INSERT INTO disbursements (claim_id, claim_no, facility_id, facility_name, amount, method, reference, paid_by, paid_at, note, batch_id, voucher_no, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute(array($id, $c['claim_no'], $c['facility_id'], $c['facility_name'], $amt, $method, $ref, $by, date('Y-m-d H:i:s'), trim(post('note')), $bid, $vno, date('Y-m-d H:i:s')));
            else db()->prepare('INSERT INTO disbursements (claim_id, claim_no, facility_id, facility_name, amount, method, reference, paid_by, paid_at, note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
               ->execute(array($id, $c['claim_no'], $c['facility_id'], $c['facility_name'], $amt, $method, $ref, $by, date('Y-m-d H:i:s'), trim(post('note')), date('Y-m-d H:i:s')));
            $did = intval(db()->lastInsertId());
            $newst = claim_sync_pay_state($id);
            db()->commit();
            gl_post(date('Y-m-d'), array(array('2100', $amt, 0), array(acc_method_acct($method), 0, $amt)), 'Disbursement for ' . $c['claim_no'] . ' to ' . $c['facility_name'] . ' via ' . $method . ($vno !== '' ? ' [' . $vno . ']' : ''), 'claimpay', $id, intval($c['facility_id']));
            claim_event($id, 'Payment recorded', ugx($amt) . ' via ' . $method . ' ref ' . $ref . ($bid > 0 ? ' (batch #' . $bid . ')' : '') . ' — claim now ' . cl_slabel($newst ?: '—') . '.', $by);
            log_action('DISBURSED ' . ugx($amt) . ' to ' . $c['facility_name'] . ' for claim ' . $c['claim_no'] . ' via ' . $method . ' ref ' . $ref, 'disbursements', $did);
            fac_notify($F, 'PAYMENT: UGX ' . number_format($amt, 0) . ' for claim ' . $c['claim_no'] . ' sent via ' . $method . ' (ref ' . $ref . ').' . (cl_can_disburse($newst ?: 'x') ? ' Outstanding: UGX ' . number_format(max(0, $appr - ($paid + $amt)), 0) . '.' : ' Fully settled.'));
            flash('Disbursed ' . ugx($amt) . ' to ' . $c['facility_name'] . ' — ' . ($vno !== '' ? 'voucher ' . $vno : 'ref ' . $ref) . '.', 'ok');
        } catch (Exception $ex) { if (db()->inTransaction()) db()->rollBack(); flash('Payment failed: ' . $ex->getMessage(), 'err'); }
        redirect('claim-view.php?id=' . $id);
    }
    if ($op === 'reverse') {
        $did = intval(post('did'));
        $stD3 = db()->prepare('SELECT * FROM disbursements WHERE id=? AND claim_id=?'); $stD3->execute(array($did, $id)); $d = $stD3->fetch();
        if (!$d) { flash('Disbursement not found.', 'err'); redirect('claim-view.php?id=' . $id); }
        if (!cl_has('disbursements', 'reversed')) { flash('Reversals need the v4.1 schema — run migrate.php.', 'err'); redirect('claim-view.php?id=' . $id); }
        if (intval($d['reversed']) === 1) { flash('Already reversed.', 'err'); redirect('claim-view.php?id=' . $id); }
        $why = trim(post('why')); if ($why === '') { flash('State the reason for the reversal.', 'err'); redirect('claim-view.php?id=' . $id); }
        try {
            db()->beginTransaction();
            db()->prepare('UPDATE disbursements SET reversed=1, reverse_reason=? WHERE id=?')->execute(array(mb_substr($why, 0, 200), $did));
            $newst = claim_sync_pay_state($id);
            db()->commit();
            gl_post(date('Y-m-d'), array(array(acc_method_acct($d['method']), (float)$d['amount'], 0), array('2100', 0, (float)$d['amount'])), 'REVERSAL of disbursement #' . $did . ' for ' . $c['claim_no'], 'claimreverse', $id, intval($c['facility_id']));
            claim_event($id, 'Disbursement reversed', 'Payment #' . $did . ' of ' . ugx((float)$d['amount']) . ' reversed: ' . $why, $by);
            log_action('Reversed disbursement #' . $did . ' (' . ugx((float)$d['amount']) . ') for claim ' . $c['claim_no'] . ': ' . mb_substr($why, 0, 120), 'disbursements', $did);
            fac_notify($F, 'Disbursement of UGX ' . number_format((float)$d['amount'], 0) . ' for ' . $c['claim_no'] . ' was REVERSED: ' . $why);
            flash('Payment reversed — claim state updated.', 'info');
        } catch (Exception $ex) { if (db()->inTransaction()) db()->rollBack(); flash('Reversal failed: ' . $ex->getMessage(), 'err'); }
        redirect('claim-view.php?id=' . $id);
    }
}

/* claimed vs current list price comparison */
$cmp = array();
foreach ($items as $i => $it) {
    $lp = fac_price_lookup(intval($c['facility_id']), (string)preg_replace('/\s*\[ENC-[^\]]*\]$/', '', (string)$it['name']));
    $cmp[$i] = $lp ? (float)$lp['price'] : null;
}
$page_title = 'Claim ' . $c['claim_no'];
include __DIR__ . '/includes/header.php';
?>
<div class="page-head noprint">
  <div><h1>&#9878;&#65039; Claim <?php echo e($c['claim_no']); ?> <?php echo fac_status_chip($c['status']); ?></h1>
    <span class="sub"><b><?php echo e($c['facility_name']); ?></b>
      <?php if ($parent): ?><span style="color:#7a94aa">(branch of <?php echo e($parent['name']); ?>)</span><?php endif; ?>
      · <?php echo e($F ? $F['ftype'] : 'Facility'); ?> · reg <?php echo e($F ? $F['code'] : '—'); ?> · submitted by <b><?php echo e($c['submitted_by'] !== '' ? $c['submitted_by'] : '—'); ?></b><?php echo $c['submitted_at'] ? ' on ' . e(date('d M Y H:i', strtotime($c['submitted_at']))) : ''; ?></span></div>
  <div><a class="btn plain" href="javascript:window.print()">&#128424; Print claim report</a>
   <a class="btn plain" href="claim-receipt.php?id=<?php echo $id; ?>">Official receipt</a>
   <a class="btn" href="facility-claims.php">Back to desk</a></div>
</div>

<div class="grid grid-4">
  <div class="kpi"><div class="k-label">Patient</div><div class="k-value" style="font-size:15px;padding-top:6px"><?php echo e($c['patient_name'] !== '' ? $c['patient_name'] : 'Consolidated / facility'); ?></div>
    <div class="k-sub"><?php $mn = ''; if (intval($c['member_id'])>0) { $sm = db()->prepare('SELECT member_no FROM members WHERE id=?'); $sm->execute(array(intval($c['member_id']))); $rr=$sm->fetch(); if($rr) $mn=$rr['member_no']; }
      echo $mn !== '' ? 'Member ' . e($mn) : 'no member link'; ?><?php echo cl_has('facility_claims','service_date') && !empty($c['service_date']) ? ' · served ' . e(date('d M Y', strtotime($c['service_date']))) : ''; ?></div></div>
  <div class="kpi gold"><div class="k-label">Billed</div><div class="k-value"><?php echo ugx($c['amount_billed']); ?></div><div class="k-sub"><?php echo count($items); ?> service line(s)<?php echo cl_has('facility_claims','category') && $c['category'] !== '' ? ' · ' . e($c['category']) : ''; ?></div></div>
  <div class="kpi"><div class="k-label">Approved</div><div class="k-value"><?php echo ugx($appr); ?></div><div class="k-sub"><?php echo $c['amount_approved'] !== null && abs($appr-(float)$c['amount_billed'])>0.005 ? 'reduced from billed' : 'as billed'; ?></div></div>
  <div class="kpi green"><div class="k-label">Disbursed</div><div class="k-value"><?php echo ugx($paid); ?></div><div class="k-sub">outstanding <?php echo ugx($outstanding); ?></div></div>
</div>

<?php if ($c['status'] === 'Rejected' && trim((string)$c['reject_reason']) !== ''): ?>
<div class="panel" style="border-left:4px solid #8c2424;background:#FDECEC;margin:8px 0"><b>Rejection reason:</b> <?php echo e($c['reject_reason']); ?></div>
<?php elseif ($c['status'] === 'Returned'): ?>
<div class="panel" style="border-left:4px solid #b06d10;background:#FFF6E0;margin:8px 0"><b>Returned for correction:</b> <?php echo e($c['reject_reason']); ?> — fix, attach documents and resubmit below.</div>
<?php endif; ?>
<?php /* v5.0 funding provenance */
try {
  $hasSettleCol = cl_has('facility_claims', 'settlement_id');
  $sessId = $hasSettleCol ? intval($c['settlement_id']) : 0;
  $polTxt = $hasSettleCol && (string)$c['plan_name'] !== '' ? (string)$c['plan_name'] : '';
  if ($sessId > 0 || $polTxt !== ''):
      $setRow = $sessId > 0 ? db()->prepare('SELECT * FROM bill_settlements WHERE id=?') && (function ($q) { $q->execute(array($sessId)); return $q->fetch(); })(db()->prepare('SELECT * FROM bill_settlements WHERE id=?')) : null;
      $covered = $hasSettleCol && $c['covered_amount'] !== null ? (float)$c['covered_amount'] : (float)$c['amount_billed'];
      $copayAmt = $hasSettleCol ? (float)(isset($c['copay_amount']) && $c['copay_amount'] !== null ? $c['copay_amount'] : 0) : 0;
      $exclAmt = $hasSettleCol ? (float)(isset($c['excluded_amount']) && $c['excluded_amount'] !== null ? $c['excluded_amount'] : 0) : 0; ?>
<div class="panel" style="border-left:4px solid #155e9e;background:#F1F6FC;margin:8px 0">
 <b>&#9878;&#65039; Insurance funding</b> &mdash; this claim covers the <i>insurance portion</i> of
 <?php if ($setRow): ?><a href="settlement-receipt.php?id=<?php echo intval($setRow['id']); ?>">settlement <?php echo e($setRow['settle_no']); ?></a> (retired-desk settlement already paid <?php echo ugx($setRow['wallet_paid']); ?> — those lines must NOT be re-billed)<?php else: ?>the billed services<?php endif; ?>.
 <?php if ($polTxt !== ''): ?> Plan: <b><?php echo e($polTxt); ?></b> &middot;<?php endif; ?>
 gross <?php echo ugx((float)$c['amount_billed']); ?> &middot; proposed cover <?php echo ugx($covered); ?> &middot; copay <?php echo ugx($copayAmt); ?><?php echo $exclAmt > 0 ? ' &middot; excluded ' . ugx($exclAmt) : ''; ?>.
</div>
<?php endif;
} catch (Exception $e50v) {} ?>

<div class="grid grid-2">
 <div class="panel">
  <h2 style="margin-top:0">Services claimed &mdash; checked against the current <?php echo e($F ? $F['name'] : 'facility'); ?> price list</h2>
  <div class="twrap" style="border:0"><table class="data" style="margin:0">
   <tr><th>Dept</th><th>Service</th><th class="num">Qty</th><th class="num">Claimed</th><th class="num">List now</th><th class="num"><?php echo $OWNER?'Approved':'Approved'; ?></th><th>Decision reason</th></tr>
   <?php foreach ($items as $i => $it): $cur = $cmp[$i]; $rv = isset($reviews[$i]) ? $reviews[$i] : null; $var = $cur === null ? '' : (abs((float)$it['price'] - $cur) > 0.005 ? ((float)$it['price'] > $cur ? '▲ above list' : '▼ below list') : '='); ?>
   <tr><td><span class="badge grey"><?php echo e($it['cat']); ?></span></td>
     <td><?php echo e($it['name']); ?></td><td class="num"><?php echo e((string)(float)$it['qty']); ?></td>
     <td class="num"><?php echo e(number_format((float)$it['price'], 0)); ?> × qty</td>
     <td class="num"><?php echo $cur === null ? '—' : number_format($cur, 0); ?>
        <?php if ($var !== '' && $var !== '='): ?><br><span style="font-size:10px;color:<?php echo substr($var,0,1)==='▲'?'#8c2424':'#20603a'; ?>"><?php echo $var; ?></span><?php endif; ?></td>
     <td class="num"><?php echo $rv !== null ? '<b style="color:#20603a">' . e(number_format((float)$rv['approved_amount'],0)) . '</b>' : number_format((float)$it['price']*(float)$it['qty'], 0); ?></td>
     <td style="font-size:11px;color:#5b7c99"><?php echo $rv !== null ? e($rv['reason']) : '—'; ?></td></tr>
   <?php endforeach; ?>
  </table></div>
  <?php if ($CLINES): ?><div class="twrap" style="border:0;margin-top:10px"><table class="data" style="margin:0"><tr><th>Funding allocation — bill by bill (v6.0)</th><th class="num">Bill total</th><th class="num">Coverage</th><th class="num">Member-funded</th><th class="num">Other payer</th><th class="num">Requested from Kisubi</th><th class="num">Member deposit balance</th><th>Funding source</th></tr>
   <?php $aggB=array(); foreach ($CLINES as $ln){ $k=$ln['bill_ref']; if(!isset($aggB[$k])) $aggB[$k]=array('m'=>$ln['member_no'],'tot'=>0.0,'cov'=>0.0,'mem'=>0.0,'pay'=>0.0,'req'=>0.0,'fu'=>$ln['funding'],'d'=>$ln['service_date'],'n'=>0); $aggB[$k]['tot']+=(float)$ln['line_total']; $aggB[$k]['cov']+=(float)$ln['coverage_amount']; $aggB[$k]['mem']+=(float)$ln['member_funded']; $aggB[$k]['pay']+=(float)$ln['payer_funded']; $aggB[$k]['req']+=(float)$ln['requested_amount']; $aggB[$k]['n']++; }
   $balMap = array(); if ($aggB) { $in = implode(',', array_fill(0, count($aggB), '?')); $bs = db()->prepare('SELECT member_no, deposit_amount FROM members WHERE member_no IN (' . $in . ')'); $bs->execute(array_values(array_map(function ($x) { return $x['m']; }, $aggB))); foreach ($bs->fetchAll() as $br) $balMap[(string)$br['member_no']] = (float)$br['deposit_amount']; }
   foreach ($aggB as $k=>$a): ?>
   <tr><td><b><?php echo e($k); ?></b> <span style="font-size:11px;color:#5b7c99"><?php echo e($a['m']); ?> · <?php echo e((string)$a['d']); ?> · <?php echo $a['n']; ?> line(s)</span></td>
    <td class="num"><?php echo number_format($a['tot'],0); ?></td><td class="num" style="color:#20603a"><?php echo number_format($a['cov'],0); ?></td>
    <td class="num" style="color:#8a6410"><?php echo number_format($a['mem'],0); ?></td><td class="num"><?php echo number_format($a['pay'],0); ?></td>
    <td class="num"><b><?php echo number_format($a['req'],0); ?></b></td>
    <td class="num" style="color:<?php echo isset($balMap[$a['m']]) && $balMap[$a['m']] >= 0 ? '#20603a' : '#a33'; ?>"><?php echo number_format(isset($balMap[$a['m']]) ? $balMap[$a['m']] : 0, 0); ?></td><td style="font-size:11px;color:#5b7c99"><?php echo e($a['fu']); ?></td></tr>
   <?php endforeach; ?>
  </table></div>
  <p style="font-size:11px;color:#5b7c99;margin:2px 0 0">A member-bill amount already funded from the member's own deposit is shown under Member-funded and is NOT part of the Kisubi request unless the facility's deposits are Kisubi pre-funded or a third-party payer is recorded. The same bill can never appear on two live claims.</p><?php endif; ?>
  </table></div>
  <?php if ($OWNER && in_array($c['status'], array('Submitted','Received','Pending','Resubmitted','Under Review'), true)): ?>
  <form method="post" class="noprint"><?php echo csrf_field(); ?><input type="hidden" name="op" value="save_review"><input type="hidden" name="id" value="<?php echo $id; ?>">
   <h3 style="margin:12px 0 4px">Item-level decision</h3>
   <div class="twrap" style="border:0"><table class="data" style="margin:0"><tr><th>Line</th><th class="num">Claimed amount</th><th class="num">Approve (UGX)</th><th>Reason (mandatory if reduced)</th></tr>
    <?php foreach ($items as $i => $it): $lineAmt = round((float)$it['price']*(float)$it['qty'],2); $rv = isset($reviews[$i]) ? $reviews[$i] : null; ?>
    <tr><td style="font-size:11.5px"><?php echo e($it['name']); ?></td><td class="num"><?php echo e(number_format($lineAmt,0)); ?></td>
      <td class="num"><input class="f" name="appr[<?php echo $i; ?>]" style="width:110px;margin:0;text-align:right" value="<?php echo $rv !== null ? e(rtrim(rtrim((string)(float)$rv['approved_amount'],'0'),'.') === '' ? '0' : rtrim(rtrim((string)(float)$rv['approved_amount'],'0'),'.')) : number_format($lineAmt,0); ?>"></td>
      <td><input class="f" name="reason[<?php echo $i; ?>]" style="margin:0" value="<?php echo $rv !== null ? e($rv['reason']) : ''; ?>" placeholder="why reduced (if any)"></td></tr>
    <?php endforeach; ?></table></div>
   <button class="btn small primary" type="submit" style="margin-top:8px">Save item decisions</button>
  </form>
  <?php endif; ?>
 </div>
 <div style="display:flex;flex-direction:column;gap:10px">
  <?php if ($OWNER && (cl_is_incoming($c['status']) || in_array($c['status'], array('Partially Approved','Approved','Cleared','PartPaid'), true))): ?>
  <div class="panel" style="margin:0;border:2px solid #8a1c1c">
   <h3 style="margin-top:0">Owner decision</h3>
   <?php if (cl_is_incoming($c['status'])): ?>
   <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($c['status'] !== 'Under Review'): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="start_review"><input type="hidden" name="id" value="<?php echo $id; ?>"><button class="btn small" type="submit">&#128269; Start review</button></form><?php endif; ?>
    <form method="post" style="display:flex;gap:6px;align-items:center"><?php echo csrf_field(); ?><input type="hidden" name="op" value="approve"><input type="hidden" name="id" value="<?php echo $id; ?>">
      <label style="font-size:11px">Amount<input class="f" name="amount_approved" style="width:110px;margin:0" placeholder="default: billed"></label>
      <?php if (claim_reviews_of($id)): ?><label style="font-size:11px"><input type="checkbox" name="use_items" value="1" checked> use item decisions</label><?php endif; ?>
      <button class="btn small success" type="submit">&#10003; Approve</button></form>
    <form method="post" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('Return this claim to the facility for correction?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="return"><input type="hidden" name="id" value="<?php echo $id; ?>">
      <input class="f" name="reason" style="width:170px;margin:0" placeholder="what to correct (required)" required><button class="btn small" type="submit" style="background:#b06d10;color:#fff">&#8617; Return</button></form>
    <form method="post" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('Reject this claim outright?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="reject"><input type="hidden" name="id" value="<?php echo $id; ?>">
      <input class="f" name="reason" style="width:170px;margin:0" placeholder="rejection reason" required><button class="btn small danger" type="submit">&#10007; Reject</button></form>
   </div>
   <?php endif; ?>
   <?php if (in_array($c['status'], array('Approved','Partially Approved'), true)): ?>
   <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="clear"><input type="hidden" name="id" value="<?php echo $id; ?>">
    <button class="btn success" type="submit">&#128184; Clear for payment</button> <span style="font-size:11.5px;color:#5b7c99">only cleared claims can be disbursed</span></form>
   <?php endif; ?>
   <?php if (in_array($c['status'], array('Cleared','PartPaid'), true) && $outstanding > 0.001): ?>
   <h4 style="margin:10px 0 4px">Disburse — outstanding <?php echo ugx($outstanding); ?></h4>
   <form method="post" class="grid grid-2" style="gap:8px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="pay"><input type="hidden" name="id" value="<?php echo $id; ?>">
    <div><label class="f">Amount (UGX)</label><input class="f" name="amount_paid" value="<?php echo e(number_format($outstanding,0)); ?>" required></div>
    <div><label class="f">Method</label><select class="f" name="pay_method"><option>Bank Transfer</option><option>Cash</option><option>MTN MoMo</option><option>Airtel Money</option></select></div>
    <div><label class="f">Transaction ref</label><input class="f" name="pay_ref" placeholder="auto if blank"></div>
    <div><label class="f">Batch (optional)</label><select class="f" name="batch_id"><option value="0">— none —</option>
      <?php foreach (disb_batches() as $b): if ($b['status'] !== 'Open') continue; ?><option value="<?php echo e($b['id']); ?>"><?php echo e($b['title']); ?></option><?php endforeach; ?></select></div>
    <div><label class="f">Facility invoice no.</label><input class="f" name="invoice_no" value="<?php echo e($c['invoice_no']); ?>"></div>
    <div><label class="f">Invoice date</label><input class="f" type="date" name="invoice_date" value="<?php echo e($c['invoice_date']); ?>"></div>
    <div style="grid-column:1/-1"><label class="f">Bank / MoMo details · note</label><input class="f" name="note" placeholder="acct 1020… / auth code"></div>
    <div style="grid-column:1/-1"><button class="btn danger" type="submit">&#128179; Record payment &amp; voucher</button>
      <span style="font-size:11.5px;color:#5b7c99;margin-left:6px">part-payments allowed — status becomes Partially Disbursed until settled</span></div>
   </form>
   <?php endif; ?>
   <?php if ($c['status']==='Cleared' && $outstanding<=0.001): ?><p>&#10003; Settled.</p><?php endif; ?>
  </div>
  <?php elseif ($IS_FAC && in_array($c['status'], array('Draft','Returned'), true)): ?>
  <div class="panel" style="margin:0"><h3 style="margin-top:0">Your action</h3>
   <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="<?php echo $c['status']==='Returned'?'resubmit':'submit'; ?>"><input type="hidden" name="id" value="<?php echo $id; ?>">
   <button class="btn danger" type="submit"><?php echo $c['status']==='Returned' ? '&#8617; Revise & resubmit to the owner' : '&#9993; Submit to the scheme owner'; ?></button></form>
   <p style="font-size:11.5px;color:#5b7c99">Attach the invoice & medical report first — claims without documents are usually returned.</p></div>
  <?php elseif ($c['status']==='Paid'): ?>
  <div class="panel" style="margin:0;background:#E6F6EA"><h3 style="margin-top:0">&#10003; Fully disbursed</h3>
   <p><?php echo ugx($paid); ?> settled via <?php echo e($c['pay_method'] !== '' ? $c['pay_method'] : (count($pays) ? $pays[count($pays)-1]['method'] : '—')); ?>.
   <a class="btn small" href="claim-receipt.php?id=<?php echo $id; ?>">Receipt</a></p></div>
  <?php endif; ?>
  <div class="panel">
   <h3 style="margin-top:0">Supporting documents <?php if ($docs): ?><span style="font-size:11px;color:#5b7c99"><?php echo count($docs); ?> file(s)</span><?php endif; ?></h3>
   <?php foreach ($docs as $d): ?>
     <p style="margin:4px 0">&#128206; <a href="claim-doc.php?id=<?php echo e($d['id']); ?>"><?php echo e($d['orig_name']); ?></a>
       <span class="badge grey"><?php echo e($d['kind']); ?></span> <span style="font-size:10.5px;color:#7a94aa"><?php echo e(number_format((int)$d['size_bytes']/1024,0)); ?> KB · <?php echo e($d['uploaded_by']); ?> · <?php echo e(date('d M Y H:i', strtotime($d['uploaded_at']))); ?></span></p>
   <?php endforeach; ?>
   <?php if (!$docs): ?><p style="color:#7a94aa;font-size:12px">No documents yet.</p><?php endif; ?>
   <?php if (($IS_FAC && in_array($c['status'], array('Draft','Returned','Resubmitted','Submitted','Pending'), true)) || $OWNER): ?>
   <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;margin-top:6px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="doc"><input type="hidden" name="id" value="<?php echo $id; ?>">
     <select class="f" name="kind" style="width:130px;margin:0"><option>Invoice</option><option>Medical report</option><option>Discharge summary</option><option>Receipt</option><option>Other</option></select>
     <input type="file" name="doc" accept=".pdf,.png,.jpg,.jpeg,.txt,.csv,.doc,.docx" style="font-size:11px" required>
     <button class="btn small primary" type="submit">Upload</button></form>
   <?php endif; ?>
  </div>
 </div>
</div>

<div class="grid grid-2">
 <div class="panel"><h3 style="margin-top:0">Claim history &mdash; every action on record</h3>
  <div class="twrap" style="border:0;max-height:300px;overflow:auto"><table class="data" style="margin:0">
   <tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th></tr>
   <?php if (!$events): ?><tr><td colspan="4" style="color:#7a94aa">No recorded events (claims created before v4.1) — decisions appear from now on.</td></tr><?php endif; ?>
   <?php foreach (array_reverse($events) as $ev): ?>
   <tr><td style="white-space:nowrap;font-size:11px"><?php echo e(date('d M Y H:i', strtotime($ev['at']))); ?></td><td style="font-size:11.5px"><?php echo e($ev['actor']); ?></td>
     <td style="font-size:11.5px"><b><?php echo e($ev['action']); ?></b></td><td style="font-size:11.5px;color:#5b7c99"><?php echo e($ev['note']); ?></td></tr>
   <?php endforeach; ?>
  </table></div></div>
 <div class="panel"><h3 style="margin-top:0">Payments against this claim</h3>
  <div class="twrap" style="border:0;max-height:300px;overflow:auto"><table class="data" style="margin:0">
   <tr><th>Voucher</th><th>Date</th><th class="num">Amount</th><th>Method</th><th>Ref</th><th class="noprint"></th></tr>
   <?php if (!$pays): ?><tr><td colspan="6" style="color:#7a94aa">Nothing paid yet.</td></tr><?php endif; ?>
   <?php foreach ($pays as $d): $rvd = cl_has('disbursements','reversed') && intval($d['reversed'])===1; ?>
   <tr <?php echo $rvd ? 'style="opacity:.45;text-decoration:line-through"' : ''; ?>><td><?php echo cl_has('disbursements','voucher_no') ? '<b>' . e($d['voucher_no']) . '</b>' : '#' . e($d['id']); ?></td>
    <td style="font-size:11px"><?php echo e(date('d M Y H:i', strtotime($d['paid_at']))); ?></td><td class="num"><?php echo e(number_format((float)$d['amount'],0)); ?></td>
    <td><?php echo e($d['method']); ?></td><td style="font-size:11px"><?php echo e($d['reference']); ?></td>
    <td class="noprint"><?php if (!$rvd && $OWNER): ?><form method="post" style="display:inline" onsubmit="return confirm('Reverse this payment? A correcting entry will be posted.')">
       <?php echo csrf_field(); ?><input type="hidden" name="op" value="reverse"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="did" value="<?php echo e($d['id']); ?>"><input class="f" name="why" placeholder="reason" style="width:130px;margin:0;padding:3px 6px" required>
       <button class="btn small" type="submit" title="reverse">&#8634;</button></form><?php elseif ($rvd): ?><span style="font-size:10px;color:#8c2424">REVERSED: <?php echo e($d['reverse_reason']); ?></span><?php endif; ?></td></tr>
   <?php endforeach; ?>
  </table></div>
  <?php if (cl_has('facility_claims','invoice_no') && trim((string)$c['invoice_no']) !== ''): ?>
  <p style="font-size:12px;color:#5b7c99;margin-bottom:0">Facility invoice on file: <b><?php echo e($c['invoice_no']); ?></b><?php echo $c['invoice_date'] ? ' dated ' . e(date('d M Y', strtotime($c['invoice_date']))) : ''; ?><?php echo $c['invoice_amount'] !== null ? ' · UGX ' . e(number_format((float)$c['invoice_amount'],0)) : ''; ?></p>
  <?php endif; ?>
 </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
