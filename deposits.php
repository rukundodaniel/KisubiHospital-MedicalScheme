<?php
/**
 * DEPOSITS — full parity with the VB RecordDepositForm + ManageDepositsForm.
 *  ?a=new          workbench: member picker (search by member no / names / phone / NIN),
 *                  status gate, registration-fee capture, live previous→new balance,
 *                  confirmed save, transaction receipt, SMS + audit fan-out, clear & stay.
 *  default         deposit register: TOP rows with Previous / Deposit / Total columns,
 *                  date-range + text filters, totals strip, row actions
 *                  (receipt, statement, email, WhatsApp, refund request), CSV export, pager.
 * Everything is recorded in the classic light-blue MedSavu look.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/smsdual.php';
require_once __DIR__ . '/includes/acc.php';
require_login();
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

const REG_FEE = 20000.0;

function dep_has_col($tbl, $col) {
    try { db()->query("SELECT $col FROM $tbl LIMIT 1"); return true; } catch (Exception $e) { return false; }
}
/** VB AuditTable twin: UserName, Computer, IP, LoginTime, LoginDate, Status, Activity, MemberNo, FullNames, Amount, EntryDate */
function dep_audit($activity, $memberNo, $fullNames, $amount = null) {
    static $withAmount = null;
    if ($withAmount === null) $withAmount = dep_has_col('audit_trail', 'amount');
    $u = isset($_SESSION['uname']) ? $_SESSION['uname'] : '';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'web';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    try {
        if ($withAmount) {
            $st = db()->prepare('INSERT INTO audit_trail (username, computer, ip, login_time, login_date, status, activity, member_no, full_names, amount, entry_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute(array($u, $host, $ip, date('H:i:s'), date('Y-m-d'), 'Logged In', $activity, $memberNo, $fullNames, $amount, date('Y-m-d H:i:s')));
        } else {
            $st = db()->prepare('INSERT INTO audit_trail (username, computer, ip, login_time, login_date, status, activity, member_no, full_names, entry_date) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute(array($u, $host, $ip, date('H:i:s'), date('Y-m-d'), 'Logged In', $activity, $memberNo, $fullNames, date('Y-m-d H:i:s')));
        }
    } catch (Exception $e) { /* audit never blocks */ }
}
/** VB receipt SMS: live mode sends via SmsDual immediately; queue mode logs it. Never blocks the money. */
function dep_sms($memberId, $phone, $text) {
    smsdual_send($memberId, $phone, $text, 'deposits', 'SMS');
}
function member_phone($m) {
    if (!empty($m['mtn_no'])) return $m['mtn_no'];
    if (!empty($m['phone_no'])) return $m['phone_no'];
    return '';
}
function fmt_ugx($n) { return number_format((float)$n, 0); }

// ---------------- POST ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');

    if ($op === 'save' || $op === 'regfee') {
        $mid = intval(post('member_id'));
        $amt = (float)str_replace(',', '', post('amount'));
        $date = post('date') !== '' ? post('date') : today();
        $st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($mid)); $m = $st->fetch();
        if (!$m) { flash('Select a scheme member first.', 'err'); redirect('deposits.php?a=new'); }
        if ($op === 'regfee') {
            if ($m['status'] === 'Active') { flash('This member is already fully registered.', 'info'); redirect('deposits.php?a=new&m=' . $mid); }
            if (post('accept_fee') !== '1') { flash("Tick 'Accept Registration Fees' before receiving payment.", 'err'); redirect('deposits.php?a=new&m=' . $mid); }
            if ($amt != REG_FEE) { flash('The registration fee must be UGX ' . fmt_ugx(REG_FEE) . '.', 'err'); redirect('deposits.php?a=new&m=' . $mid); }
        } elseif ($amt <= 0) {
            flash('Enter a valid deposit amount greater than zero.', 'err'); redirect('deposits.php?a=new&m=' . $mid);
        } elseif ($m['status'] !== 'Active') {
            flash('This member is not fully registered. Registration fees must be received before deposits.', 'err');
            redirect('deposits.php?a=new&m=' . $mid);
        }
        try {
            $method = $op === 'regfee' ? 'Cash' : post('method');
            $ref = post('reference'); if ($ref === '' && $op === 'regfee') $ref = 'REGFEE-' . $m['member_no'];
            $note = post('note'); if ($op === 'regfee') $note = trim($note . ' Registration fee UGX ' . fmt_ugx(REG_FEE) . ' (member marked Registered)');
            db()->prepare('INSERT INTO deposits (member_id, deposit_date, amount, payment_method, reference, note, entered_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
               ->execute(array($mid, $date, $amt, $method, $ref, $note, $_SESSION['uname'], date('Y-m-d H:i:s')));
            $tid = intval(db()->lastInsertId());
            gl_post($date, array(array(acc_method_acct($method), $amt, 0), array('2000', 0, $amt)), 'Deposit #' . $tid . ' member #' . $mid . ' (' . $method . ')', 'deposit', $tid);
            if ($op === 'regfee') db()->exec('UPDATE members SET status=\'Active\' WHERE id=' . $mid);
            if (dep_has_col('members', 'deposit_amount')) {
                db()->prepare('UPDATE members SET deposit_amount=? WHERE id=?')->execute(array(member_balance($mid), $mid));
            }
            $bal = member_balance($mid);
            if ($op === 'regfee') {
                dep_audit('RegistrationFee', $m['member_no'], $m['full_names'], $amt);
                log_action('Registration fee ' . ugx($amt) . ' for ' . $m['member_no'], 'deposits', $tid);
                dep_sms($mid, member_phone($m), 'KISUBI HOSPITAL MEDICAL SAVING SCHEME. Dear ' . $m['full_names'] . ', we have received your registration fee of UGX ' . fmt_ugx(REG_FEE) . '. Your membership is now REGISTERED. Member No: ' . $m['member_no'] . '. Ref: ' . $tid . '. Thank you.');
                flash('Registration fee received. Transaction ID ' . $tid . ' — member status is now REGISTERED. Balance: ' . ugx($bal));
            } else {
                dep_audit('Deposit', $m['member_no'], $m['full_names'], $amt);
                log_action('Deposit ' . ugx($amt) . ' for ' . $m['member_no'], 'deposits', $tid);
                dep_sms($mid, member_phone($m), 'KISUBI HOSPITAL MEDICAL SAVING SCHEME. Dear ' . $m['full_names'] . ', your deposit of UGX ' . fmt_ugx($amt) . ' has been received. Account: ' . $m['member_no'] . '. New balance: UGX ' . fmt_ugx($bal) . '. Ref: ' . $tid . '. Thank you.');
                flash('Deposit completed — Transaction ID ' . $tid . ', amount ' . ugx($amt) . ', new balance ' . ugx($bal) . '.');
            }
            redirect('deposits.php?a=new&m=' . $mid . '&last=' . $tid);
        } catch (Exception $ex) { flash('The deposit could not be completed: ' . $ex->getMessage(), 'err'); redirect('deposits.php?a=new&m=' . $mid); }
    }

    if ($op === 'refund') {
        $did = intval(post('deposit_id')); $ramt = (float)str_replace(',', '', post('refund_amount'));
        $st = db()->prepare('SELECT d.*, m.member_no, m.full_names FROM deposits d JOIN members m ON m.id=d.member_id WHERE d.id=?');
        $st->execute(array($did)); $d = $st->fetch();
        if (!$d) { flash('Deposit not found.', 'err'); redirect('deposits.php'); }
        if ($ramt <= 0 || $ramt > (float)$d['amount']) { flash('Enter a valid refund amount (not more than the deposit).', 'err'); redirect('deposits.php?detail=' . $did); }
        try {
            db()->prepare('INSERT INTO withdrawals (member_id, withdraw_date, amount, purpose, status, processed_by, created_at) VALUES (?,?,?,?,?,?,?)')
               ->execute(array($d['member_id'], today(), $ramt, 'Deposit refund for TXN #' . $did . ' — awaiting approval', 'Pending', '', date('Y-m-d H:i:s')));
            dep_audit('RefundRequest', $d['member_no'], $d['full_names'], $ramt);
            log_action('Refund request UGX ' . fmt_ugx($ramt) . ' for deposit #' . $did, 'withdrawals', $did);
            flash('Refund of UGX ' . fmt_ugx($ramt) . ' submitted. Two-level approval is required — approve it in Withdrawals (no money moved yet).', 'info');
        } catch (Exception $ex) { flash('Refund request failed: ' . $ex->getMessage(), 'err'); }
        redirect('deposits.php?detail=' . $did);
    }

    if ($op === 'confirm_cash' || $op === 'reject_cash') {
        $cid = intval(post('id'));
        $st = db()->prepare('SELECT c.*, m.member_no, m.full_names FROM cash_deposits c JOIN members m ON m.id=c.member_id WHERE c.id=? AND c.status=?');
        $st->execute(array($cid, 'Pending')); $cd = $st->fetch();
        if (!$cd) { flash('That declaration is not pending (or not found).', 'err'); redirect('deposits.php'); }
        if ($op === 'reject_cash') {
            $reason = trim((string)post('reason')); if ($reason === '') $reason = 'Cash not presented at the desk';
            db()->prepare("UPDATE cash_deposits SET status='Rejected', reject_reason=?, confirmed_by=?, confirmed_at=? WHERE id=?")
               ->execute(array(mb_substr($reason, 0, 240), $_SESSION['uname'], date('Y-m-d H:i:s'), $cid));
            dep_audit('CashDeclReject', $cd['member_no'], $cd['full_names'], (float)$cd['amount']);
            log_action('Rejected portal cash declaration #' . $cid, 'cash_deposits', $cid);
            $st2 = db()->prepare('SELECT mtn_no, airtel_no, phone_no FROM members WHERE id=?'); $st2->execute(array(intval($cd['member_id']))); $mm2 = $st2->fetch();
            if ($mm2) dep_sms(intval($cd['member_id']), member_phone($mm2), 'KISUBI HOSPITAL MEDICAL SAVING SCHEME. Dear ' . $cd['full_names'] . ', your cash declaration #' . $cid . ' of UGX ' . number_format((float)$cd['amount'], 0) . ' was NOT credited (' . $reason . '). Contact the scheme desk for details.');
            flash('Declaration #' . $cid . ' rejected and the member notified.', 'info'); redirect('deposits.php');
        }
        if (floatval($cd['amount']) <= 0) { flash('Declaration has an invalid amount.', 'err'); redirect('deposits.php'); }
        try {
            $ref = 'CASH-PORTAL-' . $cid;
            db()->prepare('INSERT INTO deposits (member_id, deposit_date, amount, payment_method, reference, note, entered_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
               ->execute(array($cd['member_id'], today(), floatval($cd['amount']), 'Cash', $ref, 'Confirmed portal cash declaration #' . $cid . ($cd['note'] !== '' ? ' (' . $cd['note'] . ')' : ''), $_SESSION['uname'], date('Y-m-d H:i:s')));
            $tid = intval(db()->lastInsertId());
            gl_post(today(), array(array('1000', floatval($cd['amount']), 0), array('2000', 0, floatval($cd['amount']))), 'Deposit #' . $tid . ' member #' . $cd['member_id'] . ' (Cash portal)', 'deposit', $tid);
            db()->prepare("UPDATE cash_deposits SET status='Confirmed', confirmed_by=?, confirmed_at=?, deposit_id=? WHERE id=?")
               ->execute(array($_SESSION['uname'], date('Y-m-d H:i:s'), $tid, $cid));
            if (dep_has_col('members', 'deposit_amount')) db()->prepare('UPDATE members SET deposit_amount=? WHERE id=?')->execute(array(member_balance(intval($cd['member_id'])), intval($cd['member_id'])));
            $bal = member_balance(intval($cd['member_id']));
            dep_audit('CashDeclaration', $cd['member_no'], $cd['full_names'], floatval($cd['amount']));
            log_action('Confirmed portal cash declaration #' . $cid . ' (' . ugx(floatval($cd['amount'])) . ') as deposit #' . $tid, 'cash_deposits', $cid);
            $st2 = db()->prepare('SELECT mtn_no, airtel_no, phone_no FROM members WHERE id=?'); $st2->execute(array(intval($cd['member_id']))); $mm2 = $st2->fetch();
            if ($mm2) dep_sms(intval($cd['member_id']), member_phone($mm2), 'KISUBI HOSPITAL MEDICAL SAVING SCHEME. Dear ' . $cd['full_names'] . ', your cash deposit of UGX ' . number_format(floatval($cd['amount']), 0) . ' has been CONFIRMED at the desk. Account: ' . $cd['member_no'] . '. New balance: UGX ' . number_format($bal, 0) . '. Ref: ' . $tid . '. Thank you.');
            flash('Cash confirmed — deposit #' . $tid . ' for ' . $cd['member_no'] . ' (' . ugx(floatval($cd['amount'])) . '), new balance ' . ugx($bal) . '. Member notified by SMS.');
        } catch (Exception $ex) { flash('Could not confirm: ' . $ex->getMessage(), 'err'); }
        redirect('deposits.php');
    }

    if ($op === 'delete') {
        require_role(array('Super Admin'));
        db()->prepare('DELETE FROM deposits WHERE id=?')->execute(array(intval(post('id'))));
        log_action('Deleted deposit record', 'deposits', intval(post('id')));
        flash('Deposit deleted.', 'info'); redirect('deposits.php');
    }
}

$ext = dep_has_col('members', 'mtn_no');

// ================= WORKBENCH (?a=new) =================
if (get('a') === 'new') {
    $selId = intval(get('m'));
    $sel = null;
    if ($selId > 0) { $st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($selId)); $sel = $st->fetch(); }

    // member grid with the four VB search boxes
    $fields = array('mno' => 'MemberNo', 'nm' => 'FullNames', 'tel' => '(AirtelNo/MtnNo)', 'nin' => 'NinNumber');
    $sf = get('sf'); $sv = trim(get('sv'));
    $where = '1=1'; $args = array();
    if ($sv !== '' && isset($fields[$sf])) {
        $like = '%' . $sv . '%';
        if ($sf === 'mno') $where .= ' AND UPPER(m.member_no) LIKE ?';
        elseif ($sf === 'nm') $where .= ' AND m.full_names LIKE ?';
        elseif ($sf === 'nin') $where .= ' AND m.national_id LIKE ?';
        else $where .= ' AND (m.phone_no LIKE ? OR m.alt_phone LIKE ?' . ($ext ? ' OR m.mtn_no LIKE ? OR m.airtel_no LIKE ?' : '') . ')';
        $n = ($sf === 'tel') ? ($ext ? 4 : 2) : 1;
        for ($k = 0; $k < $n; $k++) $args[] = $like;
    }
    $st = db()->prepare("SELECT m.* FROM members m WHERE $where ORDER BY m.full_names LIMIT 100");
    $st->execute($args); $mlist = $st->fetchAll();

    $lastTid = intval(get('last')); $lastRow = null;
    if ($lastTid > 0) { $st = db()->prepare('SELECT * FROM deposits WHERE id=?'); $st->execute(array($lastTid)); $lastRow = $st->fetch(); }

    $pg_title = 'Record Deposit'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head"><div><h1>Record Member Deposit <span class="sub">(cashier desk)</span></h1>
<span class="sub">Cash received by <b><?php echo e($_SESSION['fname']); ?></b> &middot; <?php echo date('l, d M Y'); ?> <span id="clock" style="color:#12476f;font-weight:bold"></span></span></div>
<div class="noprint"><a class="btn plain" href="deposits.php">&laquo; Deposit register</a>
<a class="btn plain" href="reports.php">&#128202; Report centre</a></div></div>

<div class="grid" style="grid-template-columns:380px 1fr;gap:14px;align-items:start">
<div class="panel noprint" style="padding:10px 12px">
  <h2 style="margin-top:0">Scheme members — search & click to select</h2>
  <form method="get" style="display:flex;gap:6px;margin-bottom:8px">
    <input type="hidden" name="a" value="new"><?php if ($selId): ?><input type="hidden" name="m" value="<?php echo $selId; ?>"><?php endif; ?>
    <select class="f" name="sf" style="max-width:130px">
      <option value="mno" <?php echo $sf==='mno'?'selected':''; ?>>Member No.</option>
      <option value="nm" <?php echo $sf==='nm'?'selected':''; ?>>Full names</option>
      <option value="tel" <?php echo $sf==='tel'?'selected':''; ?>>Telephone</option>
      <option value="nin" <?php echo $sf==='nin'?'selected':''; ?>>NIN</option>
    </select>
    <input class="f" name="sv" value="<?php echo e($sv); ?>" placeholder="contains…" style="flex:1">
    <button class="btn small primary" type="submit">Search</button>
    <a class="btn small plain" href="deposits.php?a=new<?php echo $selId ? '&m='.$selId : ''; ?>">Reset</a>
  </form>
  <div style="max-height:520px;overflow:auto">
  <table class="data" style="margin:0;font-size:12px">
  <tr><th>Member No.</th><th>Names</th><th>Residence</th><th class="num">Balance</th><th>Status</th></tr>
  <?php if (!$mlist): ?><tr><td colspan="5" style="color:#5b7c99">No members match.</td></tr><?php endif; ?>
  <?php foreach ($mlist as $x): $isReg = $x['status'] === 'Active'; ?>
  <tr style="cursor:pointer;<?php echo $selId === intval($x['id']) ? 'background:#DCEBFA' : ''; ?>" onclick="location='deposits.php?a=new&m=<?php echo e($x['id']); ?><?php echo $sv!=='' ? '&sf='.e($sf).'&sv='.rawurlencode($sv) : ''; ?>'">
    <td><b><?php echo e($x['member_no']); ?></b></td><td><?php echo e($x['full_names']); ?></td>
    <td style="color:#5b7c99"><?php echo e($x['address']); ?></td>
    <td class="num"><?php echo num(member_balance($x['id'])); ?></td>
    <td><span style="font:bold 10px Arial;padding:2px 7px;border-radius:3px;<?php echo $isReg ? 'background:#e2f4e6;color:#20603a' : 'background:#fbe6e4;color:#a03028'; ?>"><?php echo $isReg ? 'REGISTERED' : 'NOT REGISTERED'; ?></span></td>
  </tr>
  <?php endforeach; ?>
  </table></div>
</div>

<div>
<?php if ($lastRow): $lb = member_balance(intval($lastRow['member_id'])); ?>
<div class="panel" style="background:linear-gradient(90deg,#E9F7EC,#F6FCF7);border:1px solid #bfe3c6">
  <h2 style="margin-top:0;color:#20603a">&#10003; Transaction completed</h2>
  <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px">
    <span>Transaction ID: <b>#<?php echo e($lastRow['id']); ?></b></span>
    <span>Amount: <b><?php echo ugx($lastRow['amount']); ?></b></span>
    <span>New balance: <b><?php echo ugx($lb); ?></b></span>
    <a class="btn small primary" href="receipt-deposit.php?id=<?php echo e($lastRow['id']); ?>" target="_blank">&#129534; Print receipt</a>
  </div>
  <div style="font-size:11.5px;color:#3f7a4e;margin-top:6px">Thank you for Depositing with Kisubi Hospital Medical Saving Scheme. &middot; Receipt SMS queued.</div>
</div>
<?php endif; ?>

<?php if ($sel): $bal = member_balance(intval($sel['id'])); $isReg = $sel['status'] === 'Active'; ?>
<div class="panel" style="padding:12px 16px">
 <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
  <?php if ($ext): ?><img src="memberpic.php?id=<?php echo e($sel['id']); ?>" style="width:84px;height:100px;object-fit:cover;border:2px solid #12476f;border-radius:6px" onerror="this.style.display='none'"><?php endif; ?>
  <div style="flex:1;min-width:220px">
    <b style="font-size:17px;color:#12476f"><?php echo e($sel['full_names']); ?></b>
    <span style="background:#12476f;color:#ffd76e;font:bold 13px 'Courier New';padding:3px 10px;border-radius:3px;margin-left:8px"><?php echo e($sel['member_no']); ?></span>
    <div style="font-size:12px;color:#5b7c99;margin-top:4px">
      <?php echo $ext ? 'Airtel ' . e($sel['airtel_no']) . ' &middot; MTN ' . e($sel['mtn_no']) : e($sel['phone_no']); ?>
      &middot; <?php echo e($sel['address']); ?><?php if (!empty($sel['email_address'])): ?> &middot; <a href="mailto:<?php echo e($sel['email_address']); ?>"><?php echo e($sel['email_address']); ?></a><?php endif; ?></div>
  </div>
  <div style="text-align:right">
    <span style="font:bold 11px Arial;padding:4px 10px;border-radius:3px;<?php echo $isReg ? 'background:#e2f4e6;color:#20603a' : 'background:#fbe6e4;color:#a03028'; ?>"><?php echo $isReg ? 'REGISTERED' : 'NOT REGISTERED — fee required'; ?></span>
    <div style="margin-top:6px;font:bold 15px Georgia;color:#12476f">Balance: <?php echo ugx($bal); ?></div>
    <div style="margin-top:4px"><a class="btn small plain" href="member.php?id=<?php echo e($sel['id']); ?>">Member file</a>
    <a class="btn small plain" href="deposit-statement.php?member_id=<?php echo e($sel['id']); ?>" target="_blank">&#128196; Statement</a></div>
  </div>
 </div>
</div>

<?php if (!$isReg): ?>
<div class="panel" style="border:1px solid #f0c9a8;background:#FFF8F1"><h2 style="margin-top:0;color:#a05018">Registration fee &mdash; UGX <?php echo fmt_ugx(REG_FEE); ?></h2>
<form method="post" onsubmit="return confirm('Confirm registration fee payment?\n\nMember: <?php echo e($sel['full_names']); ?>\nMember No: <?php echo e($sel['member_no']); ?>\nRegistration Fee: UGX <?php echo fmt_ugx(REG_FEE); ?>')">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="regfee"><input type="hidden" name="member_id" value="<?php echo e($sel['id']); ?>">
<label style="font:bold 13px Arial;color:#7a4010;cursor:pointer"><input type="checkbox" name="accept_fee" value="1" onchange="document.getElementById('feeAmt').value=this.checked?'<?php echo fmt_ugx(REG_FEE); ?>':''"> Accept Registration Fees (UGX <?php echo fmt_ugx(REG_FEE); ?>)</label>
<div style="display:flex;gap:10px;margin-top:10px;align-items:flex-end;flex-wrap:wrap">
 <div><label class="f">Fee amount (must be <?php echo fmt_ugx(REG_FEE); ?>)</label><input class="f" id="feeAmt" name="amount" value="" placeholder="tick the box" required></div>
 <div><label class="f">Date</label><input class="f" type="date" name="date" value="<?php echo today(); ?>"></div>
 <button class="btn success" type="submit">Receive fee &amp; register member</button>
</div></form></div>
<?php endif; ?>

<div class="panel"><h2 style="margin-top:0">Deposit slip <?php echo $isReg ? '' : '(locked until fee received)'; ?></h2>
<form method="post" id="depForm" onsubmit="return confirmDeposit(this)">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="save"><input type="hidden" name="member_id" value="<?php echo e($sel['id']); ?>">
<div class="formgrid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
  <div><label class="f">Previous amount (UGX)</label><input class="f" value="<?php echo fmt_ugx($bal); ?>" readonly style="background:#F2F1EE;text-align:right;font-weight:bold"></div>
  <div><label class="f">Deposit amount (UGX) *</label><input class="f" type="number" min="100" step="50" name="amount" id="depAmt" required style="background:#FFFDE7;text-align:right;font-weight:bold"></div>
  <div><label class="f">New total (UGX)</label><input class="f" id="depTotal" value="<?php echo fmt_ugx($bal); ?>" readonly style="background:#F0FBF2;text-align:right;font-weight:bold;color:#20603a"></div>
  <div><label class="f">Date</label><input class="f" type="date" name="date" value="<?php echo today(); ?>"></div>
  <div><label class="f">Method</label><select class="f" name="method"><?php foreach (array('Cash','MTN MoMo','Airtel Money','Bank','Cheque') as $x): ?><option><?php echo $x; ?></option><?php endforeach; ?></select></div>
  <div><label class="f">Reference / Receipt no.</label><input class="f" name="reference" placeholder="optional — auto receipt generated"></div>
  <div style="grid-column:1/-1"><label class="f">Note</label><input class="f" name="note"></div>
</div>
<div style="margin-top:12px;display:flex;gap:8px">
  <button class="btn success" type="submit" <?php echo $isReg ? '' : 'disabled'; ?>>&#128181; Save deposit</button>
  <a class="btn plain" href="deposits.php?a=new">Clear (new member)</a>
</div>
</form></div>
<script>
function confirmDeposit(f){
  var a=parseFloat(document.getElementById('depAmt').value||0);
  if(!(a>0)){alert('Enter a valid deposit amount.');return false;}
  return confirm('Please confirm this deposit.\n\nMember: <?php echo e($sel['full_names']); ?>\nMember No: <?php echo e($sel['member_no']); ?>\nDeposit: UGX '+a.toLocaleString('en-US')+'\n\nThe new balance updates immediately and a receipt SMS is queued.');
}
(function(){ var bal=<?php echo (float)$bal; ?>; var i=document.getElementById('depAmt'), t=document.getElementById('depTotal');
  if(i&&t) i.addEventListener('input',function(){ var v=parseFloat(i.value||0); t.value=(bal+v).toLocaleString('en-US',{maximumFractionDigits:0}); });
  var c=document.getElementById('clock'); if(c){ var tick=function(){ c.textContent='— '+new Date().toLocaleTimeString(); }; tick(); setInterval(tick,1000);} })();
</script>
<?php else: ?>
<div class="panel"><h2 style="margin-top:0">Select a member</h2><p style="color:#5b7c99">Use the member list — search by Member No., names, telephone or NIN, then click a row to open the deposit slip.</p></div>
<?php endif; ?>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; exit; }

// ================= DEPOSIT REGISTER (default) =================
if (get('op') === 'sendmail') { /* handled in statement page */ }

$from = get('from', date('Y-m-01')); $to = get('to', date('Y-m-t'));
$q = get('q'); $qf = get('qf', 'all');
$where = 'd.deposit_date BETWEEN ? AND ?'; $args = array($from, $to);
if ($q !== '') {
    $like = "%$q%";
    if ($qf === 'tel') { $where .= ' AND (m.phone_no LIKE ? OR m.alt_phone LIKE ?' . ($ext ? ' OR m.mtn_no LIKE ? OR m.airtel_no LIKE ?' : '') . ')'; $args[] = $like; $args[] = $like; if ($ext) { $args[] = $like; $args[] = $like; } }
    elseif ($qf === 'nin') { $where .= ' AND m.national_id LIKE ?'; $args[] = $like; }
    elseif ($qf === 'nm') { $where .= ' AND m.full_names LIKE ?'; $args[] = $like; }
    elseif ($qf === 'mno') { $where .= ' AND UPPER(m.member_no) LIKE ?'; $args[] = strtoupper($q); }
    else { $where .= ' AND (m.full_names LIKE ? OR m.member_no LIKE ? OR d.reference LIKE ?)'; $args[] = $like; $args[] = $like; $args[] = $like; }
}
$st = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(d.amount),0) a FROM deposits d JOIN members m ON m.id=d.member_id WHERE $where");
$st->execute($args); $agg = $st->fetch();
$page = max(1, intval(get('page'))); $per = 25; $off = ($page - 1) * $per;
$st = db()->prepare("SELECT d.*, m.member_no, m.full_names, m.email_address, m.status FROM deposits d JOIN members m ON m.id=d.member_id WHERE $where ORDER BY d.id DESC LIMIT $per OFFSET $off");
$st->execute($args); $rows = $st->fetchAll();

$detail = null;
if (intval(get('detail')) > 0) { $st = db()->prepare('SELECT d.*, m.member_no, m.full_names, m.email_address, m.status FROM deposits d JOIN members m ON m.id=d.member_id WHERE d.id=?'); $st->execute(array(intval(get('detail')))); $detail = $st->fetch(); }

function prior_balance($memberId, $depId, $amount, $date) {
    // balance just before this deposit (same ledger rules the dashboard uses)
    $st = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM deposits WHERE member_id=? AND (deposit_date < ? OR (deposit_date = ? AND id < ?))');
    $st->execute(array($memberId, $date, $date, $depId)); $dep = (float)$st->fetchColumn();
    $st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE member_id=? AND status='Paid' AND withdraw_date <= ?");
    $st->execute(array($memberId, $date)); $wd = (float)$st->fetchColumn();
    $st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM medical_bills WHERE member_id=? AND status='Paid' AND bill_date <= ?");
    $st->execute(array($memberId, $date)); $bl = (float)$st->fetchColumn();
    $prev = $dep - $wd - $bl;
    return $prev < 0 ? 0 : $prev;
}

$pg_title = 'Deposits'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head">
  <div><h1>Deposit Register</h1>
  <span class="sub">Previous &rarr; Deposit &rarr; Total balance for every entry &middot; showing <?php echo count($rows); ?> of <b><?php echo num($agg['c']); ?></b></span></div>
  <div class="noprint" style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn success" href="deposits.php?a=new">&#128181; Record deposit</a>
    <a class="btn plain" href="reports.php" target="_blank">&#128202; Financial report centre</a>
    <a class="btn plain" href="export.php?table=deposits&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>">Export CSV</a>
  </div>
</div>

<div class="panel" style="padding:12px 16px"><form class="inline" method="get">
  <div><label class="f">From</label><input class="f" type="date" name="from" value="<?php echo e($from); ?>"></div>
  <div><label class="f">To</label><input class="f" type="date" name="to" value="<?php echo e($to); ?>"></div>
  <div><label class="f">Search by</label><select class="f" name="qf">
    <option value="all" <?php echo $qf==='all'?'selected':''; ?>>Anything</option>
    <option value="mno" <?php echo $qf==='mno'?'selected':''; ?>>Member No.</option>
    <option value="nm" <?php echo $qf==='nm'?'selected':''; ?>>Full names</option>
    <option value="tel" <?php echo $qf==='tel'?'selected':''; ?>>Telephone (either)</option>
    <option value="nin" <?php echo $qf==='nin'?'selected':''; ?>>NIN</option>
  </select></div>
  <div style="min-width:200px"><label class="f">Value</label><input class="f" name="q" value="<?php echo e($q); ?>" placeholder="contains…"></div>
  <button class="btn primary" type="submit">Apply filters</button>
  <?php if ($q !== '' || $from !== date('Y-m-01')): ?><a class="btn plain" href="deposits.php">Reset</a><?php endif; ?>
</form></div>

<div style="display:flex;gap:14px;margin:10px 0 12px;flex-wrap:wrap">
  <div class="kpi green" style="flex:1;min-width:200px"><div class="k-label">TOTAL DEPOSITS (filtered)</div><div class="k-value"><?php echo num($agg['a']); ?></div><div class="k-sub">UGX &middot; <?php echo num($agg['c']); ?> transactions</div></div>
  <div class="kpi" style="flex:1;min-width:200px"><div class="k-label">Average entry</div><div class="k-value"><?php echo $agg['c'] ? num($agg['a'] / max(1, $agg['c'])) : 0; ?></div><div class="k-sub">UGX per transaction</div></div>
  <div class="kpi gold" style="flex:1;min-width:200px"><div class="k-label">Period</div><div class="k-value" style="font-size:15px"><?php echo e(date('d M', strtotime($from)) . ' — ' . date('d M Y', strtotime($to))); ?></div><div class="k-sub">selected range</div></div>
</div>

<?php
$hasCashTbl = false; $cashPend = array();
try { $cashPend = db()->query("SELECT c.*, m.member_no, m.full_names FROM cash_deposits c JOIN members m ON m.id=c.member_id WHERE c.status='Pending' ORDER BY c.id")->fetchAll(); $hasCashTbl = true; } catch (Exception $e) {}
if ($hasCashTbl && $cashPend): ?>
<div class="panel" style="border:1px solid #e8c98a;background:#FFFCF4"><h2 style="margin-top:0;color:#8a6410">&#128176; Portal cash declarations awaiting confirmation (<?php echo count($cashPend); ?>)</h2>
<table class="data" style="margin:0">
<tr><th>When</th><th>Member</th><th class="num">Amount</th><th>Note</th><th class="noprint">Desk action</th></tr>
<?php foreach ($cashPend as $cd): ?>
<tr><td style="white-space:nowrap"><?php echo e(date('d M H:i', strtotime($cd['created_at']))); ?></td>
<td><b><?php echo e($cd['member_no']); ?></b> <?php echo e($cd['full_names']); ?></td>
<td class="num"><b><?php echo ugx($cd['amount']); ?></b></td>
<td style="color:#5b7c99"><?php echo e($cd['note']); ?></td>
<td class="noprint" style="white-space:nowrap">
<form method="post" style="display:inline" onsubmit="return confirm('Confirm you have RECEIVED UGX <?php echo e(number_format((float)$cd['amount'],0)); ?> cash from <?php echo e(addslashes($cd['full_names'])); ?> (<?php echo e($cd['member_no']); ?>)?

The deposit, balance, audit trail and receipt SMS all fire on confirm.')">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="confirm_cash"><input type="hidden" name="id" value="<?php echo e($cd['id']); ?>">
<button class="btn small success" type="submit">&#10003; Cash received — confirm</button></form>
<form method="post" style="display:inline" onsubmit="var r=prompt('Reason for rejecting (sent to member):','Cash not presented at the desk'); if(r===null)return false; this.querySelector('[name=reason]').value=r; return confirm('Reject declaration #<?php echo e($cd['id']); ?>?');">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="reject_cash"><input type="hidden" name="id" value="<?php echo e($cd['id']); ?>"><input type="hidden" name="reason" value="">
<button class="btn small danger" type="submit">&#10007; Reject</button></form>
</td></tr>
<?php endforeach; ?>
</table>
<p style="font-size:11.5px;color:#8a6410;margin:8px 0 0">Count the cash first — confirming credits the member immediately and cannot auto-reverse (use a deposit refund request to unwind).</p></div>
<?php endif; ?>

<?php if ($detail): ?>
<div class="panel" style="border:1px solid #1B6BB3"><h2 style="margin-top:0">Deposit #<?php echo e($detail['id']); ?> — details</h2>
<div style="display:flex;gap:26px;flex-wrap:wrap;font-size:13px">
  <table class="data" style="margin:0;min-width:300px"><tr><td>Member</td><td><b><?php echo e($detail['full_names']); ?></b> (<?php echo e($detail['member_no']); ?>)</td></tr>
  <tr><td>Deposit date / time</td><td><?php echo e($detail['deposit_date']); ?> <?php echo e(substr((string)$detail['created_at'], 11, 8)); ?></td></tr>
  <tr><td>Previous amount</td><td><?php echo ugx(prior_balance(intval($detail['member_id']), intval($detail['id']), (float)$detail['amount'], $detail['deposit_date'])); ?></td></tr>
  <tr><td>Deposit</td><td><b style="color:#20603a"><?php echo ugx($detail['amount']); ?></b></td></tr>
  <tr><td>Total balance now</td><td><b><?php echo ugx(member_balance(intval($detail['member_id']))); ?></b></td></tr>
  <tr><td>Method / Reference</td><td><?php echo e($detail['payment_method']); ?> / <?php echo e($detail['reference']); ?></td></tr>
  <tr><td>Cashier</td><td><?php echo e($detail['entered_by']); ?></td></tr>
  <tr><td>Note</td><td><?php echo e($detail['note']); ?></td></tr></table>
  <div style="display:flex;flex-direction:column;gap:8px">
    <a class="btn primary" href="receipt-deposit.php?id=<?php echo e($detail['id']); ?>" target="_blank">&#129534; Deposit receipt</a>
    <a class="btn plain" href="deposit-statement.php?member_id=<?php echo e($detail['member_id']); ?>&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>" target="_blank">&#128196; Member statement (PDF-print)</a>
    <?php if (!empty($detail['email_address'])): ?>
    <a class="btn plain" href="deposit-statement.php?member_id=<?php echo e($detail['member_id']); ?>&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>&autosend=1" target="_blank">&#9993; Email statement to <?php echo e($detail['email_address']); ?></a><?php endif; ?>
    <a class="btn plain" target="_blank" href="https://wa.me/?text=<?php echo rawurlencode('Kisubi Hospital Health Savings: deposit UGX ' . fmt_ugx($detail['amount']) . ' received on ' . $detail['deposit_date'] . ' (TXN #' . $detail['id'] . ', balance ' . fmt_ugx(member_balance(intval($detail['member_id']))) . ' UGX).'); ?>">📱 Share on WhatsApp</a>
    <form method="post" onsubmit="return confirm('Submit this deposit refund for approval?\n\nNo money moves yet — two-level approval is required in Withdrawals.')">
      <?php echo csrf_field(); ?><input type="hidden" name="op" value="refund"><input type="hidden" name="deposit_id" value="<?php echo e($detail['id']); ?>">
      <label class="f" style="font-size:11px">Refund amount (max <?php echo fmt_ugx($detail['amount']); ?>)</label>
      <div style="display:flex;gap:6px"><input class="f" type="number" name="refund_amount" min="1" step="100" max="<?php echo e($detail['amount']); ?>" value="<?php echo e($detail['amount']); ?>" style="width:150px">
      <button class="btn danger" type="submit">Request refund</button></div>
    </form>
  </div>
</div></div>
<?php endif; ?>

<div class="twrap"><table class="data">
<tr><th>#</th><th>Date</th><th>Member</th><th class="num">Previous (UGX)</th><th class="num">Deposit (UGX)</th><th class="num">Total balance (UGX)</th><th>Method</th><th>Reference</th><th>Cashier</th><th class="noprint"></th></tr>
<?php if (!$rows): ?><tr><td colspan="10" style="color:#5b7c99">No deposits in this period.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $prev = prior_balance(intval($r['member_id']), intval($r['id']), (float)$r['amount'], $r['deposit_date']); ?>
<tr style="cursor:pointer;<?php echo $detail && intval($detail['id']) === intval($r['id']) ? 'background:#DCEBFA' : ''; ?>" onclick="location='deposits.php?from=<?php echo e($from); ?>&to=<?php echo e($to); ?><?php echo $q!=='' ? '&q='.rawurlencode($q).'&qf='.e($qf) : ''; ?>&page=<?php echo $page; ?>&detail=<?php echo e($r['id']); ?>'">
<td><?php echo e($r['id']); ?></td>
<td><?php echo e(date('d M Y', strtotime($r['deposit_date']))); ?></td>
<td><b><?php echo e($r['member_no']); ?></b> <?php echo e($r['full_names']); ?></td>
<td class="num" style="color:#5b7c99"><?php echo num($prev); ?></td>
<td class="num"><b style="color:#20603a"><?php echo num($r['amount']); ?></b></td>
<td class="num" style="background:#F4F9FE"><b><?php echo num($prev + (float)$r['amount']); ?></b></td>
<td><?php echo e($r['payment_method']); ?></td><td><?php echo e($r['reference']); ?></td><td><?php echo e($r['entered_by']); ?></td>
<td class="noprint" style="white-space:nowrap">
  <a class="btn small primary" href="receipt-deposit.php?id=<?php echo e($r['id']); ?>" target="_blank" onclick="event.stopPropagation()">Receipt</a>
  <?php if (can(array('Super Admin'))): ?>
  <form style="display:inline" method="post" onsubmit="event.stopPropagation();return confirm('Delete this deposit?')"><?php echo csrf_field(); ?>
  <input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
  <button class="btn small danger" type="submit">Del</button></form><?php endif; ?>
</td></tr>
<?php endforeach; ?>
</table></div>
<?php echo pager(intval($agg['c']), $per, $page, 'deposits.php?from=' . urlencode($from) . '&to=' . urlencode($to) . '&q=' . urlencode($q) . '&qf=' . urlencode($qf)); ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
