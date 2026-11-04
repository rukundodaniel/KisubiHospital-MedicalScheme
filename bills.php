<?php
/**
 * MEDICAL BILLS + SETTLEMENT DESK.
 *  ?a=settle   MakeATransactionForm twin: member picker (MEMBER/NAME/PHONE search), beneficiary or
 *              self pay, hospital HIS invoice load (eafyakisubidb provision w/ already-settled guard),
 *              discount live-recalc, E-CARD / OTP authorization, serialized settle w/ reserved-refund
 *              check, audit + SmsDual settlement SMS, printable receipt.
 *  ?a=new      classic bill submission form (kept).
 *  default     bill register with the Pending → Approved → Paid workflow (kept).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hospital.php';
require_once __DIR__ . '/includes/smsdual.php';
require_once __DIR__ . '/includes/acc.php';
require_login();

function bill_has_col($tbl, $col) { try { db()->query("SELECT $col FROM $tbl LIMIT 1"); return true; } catch (Exception $e) { return false; } }
function settle_audit($activity, $memberNo, $fullNames, $amount = null) {
    $u = isset($_SESSION['uname']) ? $_SESSION['uname'] : '';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'web';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    try {
        if (bill_has_col('audit_trail', 'amount')) {
            $st = db()->prepare('INSERT INTO audit_trail (username, computer, ip, login_time, login_date, status, activity, member_no, full_names, amount, entry_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute(array($u, $host, $ip, date('H:i:s'), date('Y-m-d'), 'Logged In', $activity, $memberNo, $fullNames, $amount, date('Y-m-d H:i:s')));
        } else {
            $st = db()->prepare('INSERT INTO audit_trail (username, computer, ip, login_time, login_date, status, activity, member_no, full_names, entry_date) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute(array($u, $host, $ip, date('H:i:s'), date('Y-m-d'), 'Logged In', $activity, $memberNo, $fullNames, date('Y-m-d H:i:s')));
        }
    } catch (Exception $e) { /* audit never blocks */ }
}
function fmt_n0($n) { return number_format((float)$n, 0); }
/** MidpointRounding.AwayFromZero twin for whole UGX */
function round_ugx($v) { return (int)round((float)$v, 0, PHP_ROUND_HALF_UP); }
/** member phone the way the VB desk picks it: Airtel first, then MTN, then phone_no */
function desk_phone($m) {
    foreach (array('airtel_no', 'mtn_no', 'phone_no') as $k) { if (!empty($m[$k])) return $m[$k]; }
    return '';
}
function can_settle() { return can(array('Super Admin', 'Administrator', 'Finance')); }
function desk_visits() { return array('OPD', 'IPD', 'ICU', 'Maternity', 'Pharmacy', 'Lab'); }

// ================= POST =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');

    // ---------- settlement desk ops ----------
    if (in_array($op, array('loadinvoice', 'loadmanual', 'clearinv', 'auth_ecard', 'otp_send', 'otp_verify', 'settle', 'clearauth'), true)) {
        if (!can_settle()) { flash('You do not have permission to settle medical bills.', 'err'); redirect('bills.php'); }
        $mid = intval(post('member_id'));
        $m = null;
        if ($mid > 0) { $st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($mid)); $m = $st->fetch(); }
        $inv = trim((string)post('invoice_no'));
        $pNo = trim((string)post('patient_no')); $pName = trim((string)post('patient_name'));
        $self = post('self_pay') === '1';
        if ($m && $self) { $pNo = $m['member_no']; $pName = $m['full_names']; }
        $visits = desk_visits();
        $visit = in_array(post('visit_type'), $visits, true) ? post('visit_type') : (post('visit_type') !== '' ? mb_substr(post('visit_type'), 0, 100) : 'OPD');
        $days = max(0, intval(post('hospital_days')));
        $disc = (float)post('discount'); if ($disc < 0) $disc = 0; if ($disc > 100) $disc = 100;
        $ret = 'bills.php?a=settle&m=' . $mid . '&inv=' . rawurlencode($inv) . '&pno=' . rawurlencode($pNo) . '&pname=' . rawurlencode($pName) . ($self ? '&self=1' : '');

        if ($op === 'clearauth') { unset($_SESSION['bill_auth']); redirect('deposits.php' === '' ? '' : 'bills.php?a=settle&m=' . $mid); }

        if ($op === 'clearinv') { unset($_SESSION['bill_invoice'], $_SESSION['bill_auth']); redirect($ret); }

        // ---- invoice load: HIS when wired, manual otherwise (same desk, same guards) ----
        if ($op === 'loadinvoice' || $op === 'loadmanual') {
            if (!$m) { flash('Select a member account first.', 'err'); redirect('bills.php?a=settle'); }
            if ($op === 'loadmanual') {
                $manualTotal = (float)str_replace(',', '', post('bill_amount'));
                if ($manualTotal <= 0) { flash('Enter the billed amount to load.', 'err'); redirect($ret); }
                $ex = 0;
                if ($inv !== '' && invoice_already_settled($inv, 0, $ex)) {
                    flash("This hospital invoice has already been settled.\n\nInvoice: $inv\nMedSavu Transaction: $ex\n\nNo additional payment can be made against this invoice.", 'err');
                    unset($_SESSION['bill_invoice'], $_SESSION['bill_auth']);
                    redirect($ret);
                }
                $_SESSION['bill_invoice'] = array('inv' => $inv, 'hid' => 0, 'total' => $manualTotal, 'lines' => array(array('item_name' => 'Manual bill entry', 'billing_stage' => $visit, 'quantity' => 1, 'unit_cost' => $manualTotal, 'date_created' => today(), 'status' => 'paid')), 'patient' => '', 'manual' => true);
                unset($_SESSION['bill_auth']);
                flash('Invoice loaded (manual entry).', 'info');
                redirect($ret);
            }
            if ($inv === '') { flash('Enter an invoice number.', 'info'); redirect('bills.php?a=settle&m=' . $mid); }
            $conn = hospital_pg_conn();
            if ($conn === false) {
                flash('Unable to retrieve the hospital invoice. ' . hospital_last_error() . ' Use manual entry below.', 'err');
                redirect($ret);
            }
            $hid = hospital_get_invoice_id($conn, $inv);
            if ($hid === 0) { flash('The invoice was not found.', 'info'); redirect($ret); }
            $exTxn = 0;
            if (invoice_already_settled($inv, $hid, $exTxn)) {
                flash("This hospital invoice has already been settled.\n\nInvoice: $inv\nHospital Invoice ID: $hid\nMedSavu Transaction: $exTxn\n\nNo additional payment can be made against this invoice.", 'err');
                redirect($ret);
            }
            $firstLine = 0;
            $pl = hospital_load_paid_lines($conn, $hid, $firstLine);
            $patientId = $firstLine > 0 ? hospital_get_patient_id($conn, $firstLine) : '';
            if ($patientId !== '') {
                // VB LoadPatientDetails: invoice patient must belong to the selected account
                $st = db()->prepare('SELECT full_names FROM beneficiaries WHERE member_id=? AND (national_id=? OR patient_no=? OR full_names=?) LIMIT 1');
                $found = false;
                try { $st->execute(array($mid, $patientId, $patientId, $patientId)); $found = (bool)$st->fetch(); } catch (Exception $e) {
                    $st2 = db()->prepare('SELECT full_names FROM beneficiaries WHERE member_id=? LIMIT 20'); $st2->execute(array($mid));
                    foreach ($st2->fetchAll() as $b) if (strcasecmp(trim((string)$patientId), trim((string)$b['full_names'])) === 0) { $found = true; break; }
                }
                if (!$found && !($self || strcasecmp($patientId, trim($m['member_no'])) === 0 || strcasecmp($patientId, trim($m['full_names'])) === 0)) {
                    unset($_SESSION['bill_invoice'], $_SESSION['bill_auth']);
                    flash("Unable to load the invoice.\nThe invoice patient does not belong to the selected MedSavu account.", 'err');
                    redirect($ret);
                }
                if (!$self) { $pNo = $patientId; $st = db()->prepare('SELECT full_names FROM beneficiaries WHERE member_id=? AND (national_id=? OR patient_no=? OR full_names=?) LIMIT 1'); $st->execute(array($mid, $patientId, $patientId, $patientId)); $bb = $st->fetch(); if ($bb) $pName = $bb['full_names']; }
            }
            $_SESSION['bill_invoice'] = array('inv' => $inv, 'hid' => $hid, 'total' => $pl['total'], 'lines' => $pl['lines'], 'patient' => $patientId, 'manual' => false);
            unset($_SESSION['bill_auth']);
            flash('Hospital invoice loaded from HIS (' . count($pl['lines']) . ' paid line(s)).');
            redirect('bills.php?a=settle&m=' . $mid . '&inv=' . rawurlencode($inv) . ($pNo !== '' ? '&pno=' . rawurlencode($pNo) . '&pname=' . rawurlencode($pName) : ''));
        }

        // ---- E-CARD authorization (VB AuthorizeWithCard) ----
        if ($op === 'auth_ecard') {
            $err = validate_desk($m, $mid, $inv, $pNo, $pName);
            if ($err !== '') { flash($err, 'err'); redirect($ret); }
            $enrolled = trim((string)$m['card_no']);
            if ($enrolled === '') {
                flash("This member does not have an enrolled e-Card UID.\n\nPlease enroll the member's card before authorizing payment.", 'err');
                redirect($ret);
            }
            $presented = strtoupper(trim((string)post('card_uid')));
            if ($presented === '' || $presented !== strtoupper($enrolled)) {
                flash('The card presented is not the enrolled e-Card for this member.', 'err');
                redirect($ret);
            }
            $pay = pay_amount($m, $disc);
            $_SESSION['bill_auth'] = array('method' => 'E-CARD', 'ref' => $enrolled, 'member' => $mid, 'inv' => strtoupper($inv), 'patient' => strtoupper($pNo), 'amount' => $pay['amount'], 'ts' => time());
            flash('Payment authorized by e-Card — ' . $m['full_names'] . ' (UGX ' . fmt_n0($pay['amount']) . '). You may settle now.');
            redirect($ret);
        }

        // ---- OTP authorization (VB AuthorizeWithOtp: SMS through the same SmsDual client) ----
        if ($op === 'otp_send') {
            $err = validate_desk($m, $mid, $inv, $pNo, $pName);
            if ($err !== '') { flash($err, 'err'); redirect($ret); }
            $phone = desk_phone($m);
            if ($phone === '') {
                flash("The selected member does not have a registered telephone number.\n\nOTP authorization cannot be used.", 'err');
                redirect($ret);
            }
            $pay = pay_amount($m, $disc);
            $code = (string)random_int(100000, 999999);
            $res = smsdual_send($mid, $phone, 'KISUBI HOSPITAL MEDICAL SAVING SCHEME. Your MedSavu settlement OTP is ' . $code . ' (valid 5 minutes). Do NOT share it. Member ' . $m['member_no'] . ', invoice ' . ($inv !== '' ? $inv : 'N/A') . ', amount UGX ' . fmt_n0($pay['amount']) . '.', 'settlement', 'SMS');
            $_SESSION['bill_otp'] = array('hash' => hash('sha256', $code . '|' . session_id()), 'exp' => time() + 300, 'tries' => 0, 'logid' => isset($res['id']) ? intval($res['id']) : 0, 'sent' => $res['status']);
            flash('OTP sent to ' . $phone . ' via SmsDual (' . $res['status'] . '). Enter the 6-digit code to authorize.', 'info');
            redirect($ret . '&otp=1');
        }
        if ($op === 'otp_verify') {
            if (empty($_SESSION['bill_otp'])) { flash('The OTP authorization reference is invalid. Please authorize the transaction again.', 'err'); redirect($ret); }
            $o = $_SESSION['bill_otp'];
            if ($o['exp'] < time()) { unset($_SESSION['bill_otp']); flash('The OTP has expired (5 minutes). Request a new code.', 'err'); redirect($ret); }
            if ($o['tries'] >= 3) { unset($_SESSION['bill_otp']); flash('Too many wrong attempts — the OTP was invalidated. Request a new code.', 'err'); redirect($ret); }
            $code = preg_replace('/\D/', '', post('otp_code'));
            if (hash('sha256', $code . '|' . session_id()) !== $o['hash']) {
                $o['tries']++; $_SESSION['bill_otp'] = $o;
                flash('Wrong OTP (' . $o['tries'] . '/3).', 'err'); redirect($ret . '&otp=1');
            }
            unset($_SESSION['bill_otp']);
            $err = validate_desk($m, $mid, $inv, $pNo, $pName);
            if ($err !== '') { flash($err, 'err'); redirect($ret); }
            $pay = pay_amount($m, $disc);
            $_SESSION['bill_auth'] = array('method' => 'OTP', 'ref' => 'SMS-' . $o['logid'], 'member' => $mid, 'inv' => strtoupper($inv), 'patient' => strtoupper($pNo), 'amount' => $pay['amount'], 'ts' => time());
            flash('OTP accepted — payment authorized. You may settle now.');
            redirect($ret);
        }

        // ---- settle (VB CompleteSettlement) ----
        if ($op === 'settle') {
            // ValidateAuthorization twin
            if (empty($_SESSION['bill_auth'])) {
                flash("This payment has not been authorized.\n\nAuthorize the payment using the member's e-Card or OTP before settlement.", 'err');
                redirect($ret);
            }
            $err = validate_desk($m, $mid, $inv, $pNo, $pName);
            if ($err !== '') { flash($err, 'err'); redirect($ret); }
            $pay = pay_amount($m, $disc);
            $auth = $_SESSION['bill_auth'];
            if ($auth['member'] !== $mid || $auth['inv'] !== strtoupper($inv) || $auth['patient'] !== strtoupper($pNo) || $auth['amount'] !== $pay['amount'] || $auth['ts'] < time() - 600) {
                unset($_SESSION['bill_auth']);
                $what = $auth['method'] === 'OTP' ? 'payment' : 'transaction';
                flash("The transaction details changed after " . ($auth['method'] === 'OTP' ? 'OTP' : 'e-Card') . " authorization.\n\nPlease authorize the $what again.", 'err');
                redirect($ret);
            }
            $amountToPay = $pay['amount'];
            $hid = intval($pay['hid']);
            if ($pay['inv_loaded'] && $hid < 0) { flash('The hospital invoice ID is invalid.', 'err'); redirect($ret); }

            // serialized save (VB SaveBillSettlement): duplicate guard, balance lock, reserved refunds, insert, update
            try {
                $pdo = db();
                $pdo->beginTransaction();
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { /* row lock via FOR UPDATE below */ }
                $exTxn = 0;
                if (invoice_already_settled($inv, $hid, $exTxn)) {
                    $pdo->rollBack();
                    throw new Exception("This hospital invoice has already been settled.\nExisting Transaction: $exTxn");
                }
                $sql = 'SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE member_id=? AND status=\'Pending\' AND UPPER(purpose) LIKE \'DEPOSIT REFUND%\'';
                $st = $pdo->prepare($sql); $st->execute(array($mid));
                $reserved = (float)$st->fetchColumn();

                $prev = member_balance($mid);
                if ($reserved > 0 && $amountToPay > $prev - $reserved) {
                    $pdo->rollBack();
                    throw new Exception('Insufficient available savings. UGX ' . fmt_n0($reserved) . ' is reserved for prepared refund payouts (available UGX ' . fmt_n0($prev - $reserved) . ').');
                }
                if ($amountToPay > $prev) {
                    $pdo->rollBack();
                    throw new Exception('The account has insufficient funds. Its current balance is UGX ' . fmt_n0($prev) . '.');
                }
                $newBalance = $prev - $amountToPay;
                $purpose = 'Bill settlement' . ($inv !== '' ? ' — Invoice ' . $inv : '') . ($pName !== '' ? ' — Patient ' . $pName : '');
                $cols = 'member_id, withdraw_date, amount, purpose, status, processed_by, paid_date, created_at, patient_no, patient_name, invoice_no, hospital_invoice_id, visit_type, bill_amount, discount_percent, hospital_days, ecard_uid, auth_method, auth_reference, auth_at, prev_balance, new_balance';
                $ins = $pdo->prepare("INSERT INTO withdrawals ($cols) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute(array($mid, post('date') !== '' ? post('date') : today(), $amountToPay, mb_substr($purpose, 0, 200), 'Paid', $_SESSION['uname'], today(), date('Y-m-d H:i:s'),
                    $pNo !== '' ? $pNo : null, $pName !== '' ? $pName : null, $inv !== '' ? $inv : null, $hid > 0 ? $hid : null, $visit, $pay['bill'], $disc, $days,
                    $auth['method'] === 'E-CARD' ? $auth['ref'] : null, $auth['method'], $auth['ref'], date('Y-m-d H:i:s'), $prev, $newBalance));
                $tid = intval($pdo->lastInsertId());
                if (bill_has_col('members', 'deposit_amount')) $pdo->prepare('UPDATE members SET deposit_amount=? WHERE id=?')->execute(array($newBalance, $mid));
                $pdo->commit();
                gl_post(post('date') !== '' ? post('date') : today(), array(array('2000', $amountToPay, 0), array('1000', 0, $amountToPay)), 'Bill settlement txn #' . $tid . ' member #' . $mid . ' (' . $auth['method'] . ')', 'settle', $tid);
            } catch (Exception $ex) {
                if (db()->inTransaction()) db()->rollBack();
                unset($_SESSION['bill_auth']);
                flash('The medical bill could not be settled.\n\n' . $ex->getMessage(), 'err');
                redirect($ret);
            }

            unset($_SESSION['bill_auth'], $_SESSION['bill_invoice']);
            settle_audit('Bill Settlement - ' . $auth['method'], $m['member_no'], $m['full_names'], $amountToPay);
            log_action('Settled bill ' . ugx($amountToPay) . ' (' . $auth['method'] . ') for ' . $m['member_no'], 'withdrawals', $tid);
            // VB QueueSettlementSms: Airtel then MTN, never blocks the settlement
            try {
                $phone = desk_phone($m);
                if ($phone !== '') smsdual_send($mid, $phone, 'KISUBI HOSPITAL MEDICAL SAVING SCHEME. Dear ' . $m['full_names'] . ', UGX ' . fmt_n0($amountToPay) . ' has been paid from your savings for patient ' . $pName . ', Invoice ' . ($inv !== '' ? $inv : 'N/A') . '. Remaining balance: UGX ' . fmt_n0($newBalance) . '. Ref: ' . $tid . '.', 'settlement', 'SMS');
            } catch (Exception $e) {}
            flash('Medical bill settled successfully — Transaction ID: ' . $tid . ' · Amount: UGX ' . fmt_n0($amountToPay) . ' · Remaining Balance: UGX ' . fmt_n0($newBalance) . '.');
            redirect('bills.php?a=settle&settled=' . $tid);
        }
    }

    // ---------- classic bill ops (kept) ----------
    if ($op === 'save') {
        $mid = intval(post('member_id')); $amt = (float)str_replace(',', '', post('amount'));
        if ($mid <= 0 || $amt <= 0) { flash('Select member and amount.', 'err'); redirect('bills.php?a=new'); }
        $st = db()->prepare('INSERT INTO medical_bills (member_id, provider, bill_date, description, amount, status, approved_by, created_at) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute(array($mid, post('provider'), post('date') !== '' ? post('date') : today(), post('description'), $amt, 'Pending', '', date('Y-m-d H:i:s')));
        log_action('Medical bill ' . ugx($amt) . ' from ' . post('provider'), 'medical_bills', intval(db()->lastInsertId()));
        flash('Bill submitted for review (Pending).'); redirect('bills.php');
    }
    if ($op === 'setstatus') {
        require_role(array('Super Admin', 'Administrator', 'Finance'));
        $id = intval(post('id')); $new = post('status');
        if (!in_array($new, array('Pending','Approved','Paid','Rejected'), true)) { flash('Bad status.', 'err'); redirect('bills.php'); }
        $st = db()->prepare('UPDATE medical_bills SET status=?, approved_by=?, paid_date=? WHERE id=?');
        $st->execute(array($new, $_SESSION['uname'], $new === 'Paid' ? today() : null, $id));
        log_action('Bill #' . $id . ' -> ' . $new, 'medical_bills', $id);
        flash('Bill #' . $id . ' marked ' . $new . '.'); redirect('bills.php');
    }
}

/** ValidateSettlement twin — returns '' or the exact VB message */
function validate_desk($m, $mid, $inv, $pNo, $pName) {
    if (!$m || $mid <= 0) return 'Select a member account first.';
    if (empty($_SESSION['bill_invoice'])) return 'Load a hospital invoice first.';
    if ($pNo === '' || $pName === '') return 'A valid patient must be selected or verified.';
    $pay = pay_amount($m, (float)post('discount'));
    if (!$pay['ok']) return 'The settlement amount is invalid.';
    $avail = member_balance($mid);
    if ($pay['amount'] > $avail) return "Insufficient savings balance.\n\nAvailable: UGX " . fmt_n0($avail) . "\nRequired: UGX " . fmt_n0($pay['amount']);
    return '';
}
/** compute settlement amount from the loaded invoice + discount (RecalculatePayment twin) */
function pay_amount($m, $disc) {
    $inv = isset($_SESSION['bill_invoice']) ? $_SESSION['bill_invoice'] : null;
    if ($inv === null) return array('ok' => false, 'amount' => 0, 'bill' => 0, 'hid' => 0, 'inv_loaded' => false);
    $bill = (float)$inv['total'];
    $d = (float)$disc; if ($d < 0) $d = 0; if ($d > 100) $d = 100;
    $amount = round_ugx($bill - ($d / 100) * $bill);
    return array('ok' => $amount > 0, 'amount' => $amount, 'bill' => $bill, 'hid' => intval($inv['hid']), 'inv_loaded' => true);
}

// ================= SETTLEMENT DESK =================
if (get('a') === 'settle') {
    $selId = intval(get('m'));
    $sel = null;
    if ($selId > 0) { $st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($selId)); $sel = $st->fetch(); }
    $ext = bill_has_col('members', 'mtn_no');

    $sf = get('sf'); $sv = trim(get('sv'));
    $where = "m.status='Active'"; $args = array();
    if ($sv !== '' && isset(array('mno'=>1,'nm'=>1,'tel'=>1)[$sf])) {
        $like = '%' . $sv . '%';
        if ($sf === 'mno') $where .= ' AND UPPER(m.member_no) LIKE ?';
        elseif ($sf === 'nm') $where .= ' AND m.full_names LIKE ?';
        else $where .= ' AND (m.airtel_no LIKE ? OR m.mtn_no LIKE ? OR m.phone_no LIKE ?)';
        $n = ($sf === 'tel' ? 3 : 1);
        for ($k = 0; $k < $n; $k++) $args[] = $like;
    }
    $st = db()->prepare("SELECT m.* FROM members m WHERE $where ORDER BY m.full_names LIMIT 100");
    $st->execute($args); $mlist = $st->fetchAll();

    $selBens = array();
    if ($sel) {
        try { $st = db()->prepare('SELECT * FROM beneficiaries WHERE member_id=? ORDER BY full_names'); $st->execute(array(intval($sel['id']))); $selBens = $st->fetchAll(); } catch (Exception $e) {}
    }
    $balance = $sel ? member_balance(intval($sel['id'])) : 0;
    $invoice = isset($_SESSION['bill_invoice']) ? $_SESSION['bill_invoice'] : null;
    $auth = isset($_SESSION['bill_auth']) ? $_SESSION['bill_auth'] : null;
    $invNo = get('inv');
    if ($invoice && $invNo === '') $invNo = $invoice['inv'];
    $pNo = get('pno'); $pName = get('pname');
    $isSelf = get('self') === '1';
    if ($isSelf && $sel) { $pNo = $sel['member_no']; $pName = $sel['full_names']; }
    if ($invoice && !$pNo && !empty($invoice['patient'])) { $pNo = $invoice['patient']; }
    if ($isSelf) $invoicePatientLock = true;
    $payP = pay_amount($sel, (float)post('discount'));
    $settled = intval(get('settled')); $settledRow = null;
    if ($settled > 0) { $st = db()->prepare('SELECT w.*, m.full_names, m.member_no FROM withdrawals w JOIN members m ON m.id=w.member_id WHERE w.id=?'); $st->execute(array($settled)); $settledRow = $st->fetch(); }
    $canS = can_settle();

    $visits = desk_visits();
    $pg_title = 'Settlement Desk'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head"><div><h1>Bill Settlement Desk <span class="sub">(transaction centre)</span></h1>
<span class="sub">Settling as <b><?php echo e($_SESSION['fname'] !== '' ? $_SESSION['fname'] : $_SESSION['uname']); ?></b> &middot; date <?php echo date('d/m/Y'); ?> &middot; HIS: <b style="color:<?php echo hospital_pg_available() ? '#20603a' : '#a0651a'; ?>"><?php echo hospital_pg_available() ? (hospital_pg_conn() !== false ? 'connected (eafyakisubidb)' : 'configured, offline') : 'manual entry mode'; ?></b></span></div>
<div class="noprint"><a class="btn plain" href="bills.php">&laquo; Bill register</a>
<a class="btn plain" href="withdrawals.php">Withdrawals</a></div></div>

<?php if (!$canS): ?><div class="panel" style="background:#fbe6e4;border:1px solid #eab8b2;color:#a03028;font-weight:bold">Access denied: you do not have permission to settle medical bills (TRANSACTION_CREATE).</div><?php endif; ?>

<?php if ($settledRow): ?>
<div class="panel" style="background:linear-gradient(90deg,#E9F7EC,#F6FCF7);border:1px solid #bfe3c6">
  <h2 style="margin-top:0;color:#20603a">&#10003; Medical bill settled successfully</h2>
  <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px">
    <span>Transaction ID: <b>#<?php echo e($settledRow['id']); ?></b></span>
    <span>Amount: <b><?php echo ugx($settledRow['amount']); ?></b></span>
    <span>Remaining Balance: <b><?php echo ugx(member_balance(intval($settledRow['member_id']))); ?></b></span>
    <a class="btn small primary" href="receipt-settlement.php?id=<?php echo e($settledRow['id']); ?>" target="_blank">&#129534; Print settlement receipt</a>
  </div>
  <div style="font-size:11.5px;color:#3f7a4e;margin-top:6px">Audit + settlement SMS fanned out &middot; member grid refreshed (no additional payment can repeat a settled invoice).</div>
</div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:370px 1fr;gap:14px;align-items:start">
<div class="panel noprint" style="padding:10px 12px">
  <h2 style="margin-top:0">Member accounts — search & click</h2>
  <form method="get" style="display:flex;gap:6px;margin-bottom:8px">
    <input type="hidden" name="a" value="settle">
    <select class="f" name="sf" style="max-width:120px">
      <option value="">All members</option>
      <option value="mno" <?php echo $sf==='mno'?'selected':''; ?>>Member No.</option>
      <option value="nm" <?php echo $sf==='nm'?'selected':''; ?>>Full names</option>
      <option value="tel" <?php echo $sf==='tel'?'selected':''; ?>>Telephone</option>
    </select>
    <input class="f" name="sv" value="<?php echo e($sv); ?>" placeholder="contains…" style="flex:1">
    <button class="btn small primary">Search</button>
    <a class="btn small plain" href="bills.php?a=settle">Reset</a>
  </form>
  <div style="max-height:480px;overflow:auto">
  <table class="data" style="margin:0;font-size:12px">
  <tr><th>Member No.</th><th>Names</th><th>Gender</th><th>Registered</th><th class="num">Balance</th></tr>
  <?php if (!$mlist): ?><tr><td colspan="5" style="color:#5b7c99">No member accounts match.</td></tr><?php endif; ?>
  <?php foreach ($mlist as $x): $xb = member_balance(intval($x['id'])); ?>
  <tr style="cursor:pointer;<?php echo $selId === intval($x['id']) ? 'background:#DCEBFA' : ''; ?>" onclick="location='bills.php?a=settle&m=<?php echo e($x['id']); ?>'">
    <td><b><?php echo e($x['member_no']); ?></b></td><td><?php echo e($x['full_names']); ?></td>
    <td><?php echo e($x['gender']); ?></td>
    <td style="color:#5b7c99"><?php echo e(date('d/m/Y', strtotime($x['registration_date']))); ?></td>
    <td class="num" style="font-weight:bold;color:<?php echo $xb > 0 ? '#20603a' : '#a03028'; ?>"><?php echo fmt_n0($xb); ?></td>
  </tr>
  <?php endforeach; ?>
  </table></div>
</div>

<div>
<?php if (!$sel): ?>
<div class="panel"><h2>Select a member</h2><p style="color:#5b7c99">Click a member account to start a settlement. Internal columns (Airtel/MTN/Card/Marital) stay hidden, like the desktop grid.</p></div>
<?php else: ?>

<div class="panel" style="padding:12px 16px">
 <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
  <?php if ($ext): ?><img src="memberpic.php?id=<?php echo e($sel['id']); ?>" style="width:80px;height:96px;object-fit:cover;border:2px solid #12476f;border-radius:6px" onerror="this.style.display='none'"><?php endif; ?>
  <div style="flex:1;min-width:230px">
    <b style="font-size:17px;color:#12476f"><?php echo e($sel['full_names']); ?></b>
    <span style="background:#12476f;color:#ffd76e;font:bold 13px 'Courier New';padding:3px 10px;border-radius:3px;margin-left:8px"><?php echo e($sel['member_no']); ?></span>
    <div style="font-size:12px;color:#5b7c99;margin-top:4px">
      <?php echo e($sel['gender']); ?> &middot; <?php echo $ext ? 'Airtel ' . e($sel['airtel_no']) . ' &middot; MTN ' . e($sel['mtn_no']) : e($sel['phone_no']); ?>
      <?php if (trim((string)$sel['card_no']) !== ''): ?> &middot; e-Card UID <b style="color:#20603a"><?php echo e(substr($sel['card_no'], 0, 20)); ?><?php echo strlen($sel['card_no'])>20?'…':''; ?></b><?php endif; ?></div>
  </div>
  <div style="text-align:right;min-width:170px">
    <div style="font:11px Arial;color:#5b7c99">AVAILABLE BALANCE</div>
    <div style="font:bold 20px Georgia;padding:3px 12px;border-radius:5px;background:<?php echo $balance > 0 ? 'Honeydew' : 'MistyRose'; ?>;color:<?php echo $balance > 0 ? 'DarkGreen' : 'DarkRed'; ?>;border:1px solid <?php echo $balance > 0 ? '#bfe3c6' : '#eab8b2'; ?>">UGX <?php echo fmt_n0($balance); ?></div>
  </div>
 </div>
</div>

<form method="post" id="settleForm" action="bills.php" <?php echo $canS ? '' : 'onsubmit="return false"'; ?>>
<?php echo csrf_field(); ?><input type="hidden" name="op" id="settleOp" value="settle">
<input type="hidden" name="member_id" value="<?php echo e($sel['id']); ?>">

<div class="panel"><h2 style="margin-top:0">Patient <span style="font:11px Arial;color:#5b7c99">beneficiaries: <?php echo count($selBens); ?></span>
<label style="float:right;font:bold 12.5px Arial;color:#12476F;cursor:pointer"><input type="checkbox" name="self_pay" id="selfPay" value="1" <?php echo (get('self')==='1'||$pNo===$sel['member_no'])?'checked':''; ?> onchange="toggleSelf()"> Member paying for self</label></h2>
<div style="display:flex;gap:10px;margin-bottom:8px">
 <div style="flex:1"><label class="f">Patient No</label><input class="f" name="patient_no" id="pNoBox" value="<?php echo e($pNo); ?>" <?php echo get('self')==='1'?'readonly':''; ?>></div>
 <div style="flex:2"><label class="f">Patient Name</label><input class="f" name="patient_name" id="pNameBox" value="<?php echo e($pName); ?>" <?php echo get('self')==='1'?'readonly':''; ?>></div>
</div>
<div id="benGrid" style="max-height:190px;overflow:auto;<?php echo (get('self')==='1')?'opacity:.45;pointer-events:none':''; ?>">
<table class="data" style="margin:0;font-size:12px">
<tr><th>Patient No</th><th>Beneficiary Name</th><th>Residence</th><th>Gender</th><th>Age</th><th>Relationship</th></tr>
<?php if (!$selBens): ?><tr><td colspan="6" style="color:#5b7c99">No beneficiaries — tick "paying for self" or add them in Beneficiaries.</td></tr><?php endif; ?>
<?php foreach ($selBens as $b): ?>
<tr style="cursor:pointer" onclick="pickBen('<?php echo e(addslashes(isset($b['patient_no'])?$b['patient_no']:(isset($b['national_id'])?$b['national_id']:'P-'.$b['id']))); ?>','<?php echo e(addslashes($b['full_names'])); ?>')">
<td><?php echo e(isset($b['patient_no'])?$b['patient_no']:(isset($b['national_id'])?$b['national_id']:'P-'.$b['id'])); ?></td><td><b><?php echo e($b['full_names']); ?></b></td>
<td><?php echo e(isset($b['residence'])?$b['residence']:''); ?></td><td><?php echo e(isset($b['gender'])?$b['gender']:''); ?></td><td><?php echo e(isset($b['age'])?$b['age']:''); ?></td><td><?php echo e($b['relationship']); ?></td></tr>
<?php endforeach; ?>
</table></div>
</div>

<div class="panel"><h2 style="margin-top:0">Hospital invoice
<?php if ($invoice): ?><button class="btn small plain" style="float:right" type="submit" formnovalidate onclick="document.getElementById('settleOp').value='clearinv'">Clear invoice</button><?php endif; ?></h2>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
  <div style="flex:2;min-width:170px"><label class="f">Invoice number (HIS eafyakisubidb)</label><input class="f" name="invoice_no" id="invNo" value="<?php echo e($invNo); ?>" placeholder="e.g. INV-2026-00457"></div>
  <?php if (hospital_pg_available()): ?><button class="btn primary" type="submit" formnovalidate onclick="document.getElementById('settleOp').value='loadinvoice'">&#128269; Load invoice</button>
  <?php else: ?><span style="font:11.5px Arial;color:#a0651a;padding-bottom:8px">HIS not wired — configure Settings → Hospital HIS, or use manual entry:</span><?php endif; ?>
  <div style="flex:1;min-width:150px"><label class="f">Manual bill total (UGX)</label><input class="f" type="number" min="0" step="100" name="bill_amount" id="manTotal" value="<?php echo $invoice && $invoice['manual'] ? e($invoice['total']) : ''; ?>" <?php echo hospital_pg_available()?'disabled style="background:#F2F1EE"':''; ?>></div>
  <button class="btn success" type="submit" formnovalidate onclick="document.getElementById('settleOp').value='loadmanual'"><?php echo hospital_pg_available() ? 'Set manual total' : 'Load manual invoice'; ?></button>
</div>
<?php if ($invoice): $invDisc = 0; ?>
<div style="margin-top:10px">
 <div style="display:flex;gap:14px;font-size:12.5px;margin-bottom:6px">
   <span>Bill total: <b id="billTotalLbl" data-v="<?php echo e($invoice['total']); ?>"><?php echo number_format((float)$invoice['total'], 2); ?></b> UGX</span>
   <?php if ($invoice['hid'] > 0): ?><span>Hospital Invoice ID: <b><?php echo e($invoice['hid']); ?></b></span><?php endif; ?>
   <?php if ($invoice['manual']): ?><span style="color:#a0651a">(manual entry)</span><?php endif; ?>
 </div>
 <div class="twrap" style="border:0;max-height:210px;overflow:auto"><table class="data" style="margin:0;font-size:12px">
 <tr><th>Item / Service</th><th>Visit type</th><th class="num">Qty</th><th class="num">Unit cost</th><th>Date</th><th>Status</th></tr>
 <?php foreach ($invoice['lines'] as $l): ?>
 <tr><td><?php echo e($l['item_name']); ?></td><td><?php echo e($l['billing_stage']); ?></td>
 <td class="num"><?php echo e($l['quantity']); ?></td><td class="num"><?php echo number_format((float)$l['unit_cost'], 0); ?></td>
 <td><?php echo e(substr((string)$l['date_created'], 0, 10)); ?></td><td><span class="badge green">paid</span></td></tr>
 <?php endforeach; ?>
 </table></div>
</div>
<?php endif; ?>
</div>

<div class="panel"><h2 style="margin-top:0">Payment</h2>
<div class="formgrid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
  <div><label class="f">Visit type</label><select class="f" name="visit_type"><?php foreach ($visits as $v): ?><option <?php echo post('visit_type')===$v?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
  <div><label class="f">Hospital days</label><input class="f" type="number" min="0" name="hospital_days" value="0" id="hDays"></div>
  <div><label class="f">Discount given (%)</label><input class="f" type="number" min="0" max="100" step="0.5" name="discount" id="disc" value="0" oninput="recalc()"></div>
  <div><label class="f">Settlement date</label><input class="f" type="date" name="date" value="<?php echo today(); ?>"></div>
  <div><label class="f">Amount to pay (UGX)</label><input class="f" id="payLbl" value="<?php echo $invoice ? fmt_n0($invoice['total']) : '0'; ?>" readonly style="font:bold 14px Georgia;background:#FFFDE7;text-align:right"></div>
  <div><label class="f">Balance after payment</label><input class="f" id="afterLbl" value="<?php echo fmt_n0($balance); ?>" readonly style="font:bold 14px Georgia;text-align:right;background:<?php echo $balance >= 0 ? 'Honeydew' : 'MistyRose'; ?>;color:<?php echo $balance >= 0 ? 'DarkGreen' : 'DarkRed'; ?>"></div>
</div>

<div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <button type="button" class="btn" id="btnEcard" style="background:#166534;color:#fff;font-weight:bold;height:43px;border:none;border-radius:5px;cursor:pointer" <?php echo $canS && $invoice ? '' : 'disabled'; ?> onclick="openEcard()">&#128179; Authorize with E-CARD</button>
  <button type="button" class="btn" id="btnOtp" style="background:#183153;color:#fff;font-weight:bold;height:43px;border:none;border-radius:5px;cursor:pointer" <?php echo $canS && $invoice ? '' : 'disabled'; ?> onclick="sendOtp()">&#128241; Authorize with OTP</button>
  <button type="submit" class="btn" id="btnSettle" style="background:<?php echo $auth ? '#166534' : '#8aa2b8'; ?>;color:#fff;font-weight:bold;height:43px;border:none;border-radius:5px;cursor:pointer" <?php echo $canS ? '' : 'disabled'; ?> onclick="return confirmSettle()">&#128181; Settle payment</button>
  <?php if ($auth): ?><span style="font:bold 12px Arial;color:#20603a;background:#e2f4e6;padding:6px 12px;border-radius:4px;border:1px solid #bfe3c6">&#10003; AUTHORIZED: <?php echo e($auth['method']); ?> &middot; <?php echo e($auth['ref']); ?></span>
  <button type="submit" class="btn small plain" formnovalidate onclick="document.getElementById('settleOp').value='clearauth'">Clear authorization</button>
  <?php else: ?><span style="font:12px Arial;color:#a03028">Not authorized — settle will be rejected.</span><?php endif; ?>
</div>
</div>
</form>

<div id="ecardModal" style="display:none;position:fixed;inset:0;background:rgba(18,44,74,.55);z-index:99">
 <div style="max-width:420px;margin:9vh auto;background:#fff;border-radius:8px;padding:18px 20px;font-family:Georgia">
  <b style="font-size:16px;color:#12476F">E-CARD AUTHORIZATION</b>
  <div style="font-size:12.5px;color:#5b7c99;margin:6px 0 10px">Member: <b><?php echo e($sel['full_names']); ?></b> &middot; Amount: UGX <b id="ecAmt">0</b><br>Present the enrolled card (UID <?php echo e(substr(trim((string)$sel['card_no']), 0, 16) !== '' ? substr(trim((string)$sel['card_no']), 0, 16) . '…' : 'none enrolled'); ?>) to the reader.</div>
  <form method="post"><input type="hidden" name="op" value="auth_ecard"><?php echo csrf_field(); ?><input type="hidden" name="member_id" value="<?php echo e($sel['id']); ?>">
  <input type="hidden" name="invoice_no" value="<?php echo e($invNo); ?>"><input type="hidden" name="discount" id="ecDisc" value="0">
  <input type="hidden" name="patient_no" id="ecPno" value=""><input type="hidden" name="patient_name" id="ecPname" value="">
  <input type="hidden" name="visit_type" value="<?php echo e(post('visit_type','OPD')); ?>"><input type="hidden" name="hospital_days" value="0">
  <label class="f">Card UID (scan or type)</label>
  <input class="f" name="card_uid" id="ecUid" placeholder="hold card to reader / paste UID" required style="font:bold 15px 'Courier New'">
  <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end">
    <button type="button" class="btn plain" onclick="hideModals()">Cancel</button>
    <button type="submit" class="btn success" style="background:#166534;color:#fff" onclick="syncModal()">Authorize &amp; settle-ready</button>
  </div></form>
 </div>
</div>

<div id="otpModal" style="display:none;position:fixed;inset:0;background:rgba(18,44,74,.55);z-index:99">
 <div style="max-width:420px;margin:9vh auto;background:#fff;border-radius:8px;padding:18px 20px;font-family:Georgia">
  <b style="font-size:16px;color:#183153">OTP AUTHORIZATION</b>
  <div style="font-size:12.5px;color:#5b7c99;margin:6px 0 10px">A 6-digit code was sent to <b><?php echo e(desk_phone($sel)); ?></b> via SmsDual. Valid 5 minutes, 3 attempts.</div>
  <form method="post"><input type="hidden" name="op" value="otp_verify"><?php echo csrf_field(); ?><input type="hidden" name="member_id" value="<?php echo e($sel['id']); ?>">
  <input type="hidden" name="invoice_no" value="<?php echo e($invNo); ?>"><input type="hidden" name="discount" id="otDisc" value="0">
  <input type="hidden" name="patient_no" id="otPno" value=""><input type="hidden" name="patient_name" id="otPname" value="">
  <input type="hidden" name="visit_type" value="<?php echo e(post('visit_type','OPD')); ?>"><input type="hidden" name="hospital_days" value="0">
  <label class="f">Enter OTP</label><input class="f" name="otp_code" pattern="[0-9]{6}" maxlength="6" placeholder="______" required style="font:bold 18px 'Courier New';letter-spacing:8px;text-align:center">
  <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end">
    <button type="button" class="btn plain" onclick="hideModals()">Cancel</button>
    <button type="submit" class="btn primary" onclick="syncModal()">Verify OTP</button>
  </div></form>
 </div>
</div>

<script>
var BILL=<?php echo $invoice ? json_encode((float)$invoice['total']) : '0'; ?>, BAL=<?php echo (float)$balance ?>, CAN=<?php echo $canS?'true':'false'; ?>;
function money(n){return Math.round(n).toLocaleString('en-US');}
function recalc(){var d=parseFloat(document.getElementById('disc').value||0);if(d<0)d=0;if(d>100)d=100;
 var pay=Math.round(BILL-(d/100)*BILL);document.getElementById('payLbl').value=money(pay);
 var after=BAL-pay;var f=document.getElementById('afterLbl');f.value=money(after);
 f.style.background=after<0?'MistyRose':'Honeydew';f.style.color=after<0?'DarkRed':'DarkGreen';}
function vals(){return {pno:document.getElementById('pNoBox').value,pname:document.getElementById('pNameBox').value,disc:document.getElementById('disc').value};}
function syncModal(){var v=vals();['ec','ot'].forEach(function(p){var d=document.getElementById(p+'Disc');if(d){d.value=v.disc;}var n=document.getElementById(p+'Pno');if(n)n.value=v.pno;var m=document.getElementById(p+'Pname');if(m)m.value=v.pname;});
 var a=document.getElementById('ecAmt');if(a)a.value=0;var b=document.getElementById('ecAmt');if(b)b.textContent=money(parseFloat(document.getElementById('payLbl').value.replace(/,/g,''))||0);}
function openEcard(){syncModal();document.getElementById('ecardModal').style.display='block';document.getElementById('ecUid').focus();
 if('NDEFReader' in window){try{var r=new NDEFReader();r.scan().then(function(){document.getElementById('ecUid').placeholder='card detected…';}).catch(function(){});}catch(e){}}}
function hideModals(){document.getElementById('ecardModal').style.display='none';document.getElementById('otpModal').style.display='none';}
function sendOtp(){syncModal();var f=document.getElementById('settleForm');document.getElementById('settleOp');var op=document.createElement('input');
 var form=document.createElement('form');form.method='post';form.action='bills.php';
 ['member_id','invoice_no','discount','patient_no','patient_name','visit_type','hospital_days'].forEach(function(n){var el=document.querySelector('[name="'+n+'"]');if(el){var c=document.createElement('input');c.type='hidden';c.name=n;c.value=el.value;form.appendChild(c);}});
 var o=document.createElement('input');o.type='hidden';o.name='op';o.value='otp_send';form.appendChild(o);
 var c=document.querySelector('input[name=csrf]');if(c){var c2=c.cloneNode();form.appendChild(c2);}
 document.body.appendChild(form);form.submit();}
function toggleSelf(){var c=document.getElementById('selfPay');var g=document.getElementById('benGrid');
 g.style.opacity=c.checked?'.45':'1';g.style.pointerEvents=c.checked?'none':'';
 var p=document.getElementById('pNoBox'),n=document.getElementById('pNameBox');
 if(c.checked){p.readonly=true;n.readonly=true;}else{p.readonly=false;n.readonly=false;}}
function pickBen(a,b){document.getElementById('pNoBox').value=a;document.getElementById('pNameBox').value=b;hideModals();}
function needAuth(){alert('This payment has not been authorized.\n\nAuthorize the payment using the member\u2019s e-Card or OTP before settlement.');return false;}
function confirmSettle(){if(!CAN)return false;var v=vals();var pay=parseFloat(document.getElementById('payLbl').value.replace(/,/g,''))||0;
 if(pay<=0){alert('The settlement amount is invalid.');return false;}
 if(pay>BAL){alert('Insufficient savings balance.\n\nAvailable: UGX '+money(BAL)+'\nRequired: UGX '+money(pay));return false;}
 <?php if (!$auth): ?>if(!confirm('NOT authorized. Authorize with e-Card or OTP first — the server will reject this.'))return false;return true;<?php endif; ?>
 return confirm('Confirm medical bill settlement?\n\nMember: <?php echo e(addslashes($sel['full_names'])); ?>\nPatient: '+(v.pname||'')+'\nInvoice: '+(document.getElementById('invNo').value||'')+'\nAmount: UGX '+money(pay));}
document.addEventListener('DOMContentLoaded',function(){recalc();
 <?php if (get('otp')==='1'): ?>document.getElementById('otpModal').style.display='block';<?php endif; ?>
 <?php if (get('open')==='ecard'): ?>openEcard();<?php endif; ?>
});
</script>
<?php endif; ?>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; exit; }

// ================= classic pages (kept) =================
if (get('a') === 'new') {
    $members = db()->query('SELECT id, member_no, full_names FROM members WHERE status="Active" ORDER BY full_names')->fetchAll();
    $pg_title = 'New Bill'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head"><h1>Submit Medical Bill</h1><a class="btn plain" href="bills.php">&laquo; Back</a></div>
<div class="panel"><h2>Bill details</h2>
<form method="post" action="bills.php"><?php echo csrf_field(); ?><input type="hidden" name="op" value="save">
<div class="formgrid">
  <div class="span2"><label class="f">Member *</label><select class="f" name="member_id" required><option value="0">-- select member --</option>
    <?php foreach ($members as $mm): ?><option value="<?php echo e($mm['id']); ?>"><?php echo e($mm['member_no'] . ' - ' . $mm['full_names']); ?></option><?php endforeach; ?></select></div>
  <div><label class="f">Provider / Hospital</label><input class="f" name="provider" value="Kisubi Hospital"></div>
  <div><label class="f">Amount (UGX) *</label><input class="f" type="number" min="100" name="amount" required></div>
  <div><label class="f">Bill date</label><input class="f" type="date" name="date" value="<?php echo today(); ?>"></div>
  <div><label class="f">Treatment / description</label><input class="f" name="description"></div>
</div>
<div style="margin-top:16px"><button class="btn success" type="submit">Submit bill</button> <a class="btn plain" href="bills.php">Cancel</a></div>
</form></div>
<?php include __DIR__ . '/includes/footer.php'; exit;
}

$status = get('status');
$where = '1=1'; $args = array();
if (in_array($status, array('Pending','Approved','Paid','Rejected'), true)) { $where = 'b.status=?'; $args = array($status); }
$st = db()->prepare("SELECT COUNT(*) FROM medical_bills b WHERE $where"); $st->execute($args); $total = intval($st->fetchColumn());
$page = max(1, intval(get('page'))); $per = 25; $off = ($page-1)*$per;
$st = db()->prepare("SELECT b.*, m.member_no, m.full_names FROM medical_bills b JOIN members m ON m.id=b.member_id WHERE $where ORDER BY b.id DESC LIMIT $per OFFSET $off");
$st->execute($args); $rows = $st->fetchAll();

$paidSett = db()->query("SELECT COALESCE(SUM(amount),0) t, COUNT(*) c FROM withdrawals WHERE auth_method IS NOT NULL AND auth_method <> '' AND status='Paid'")->fetch();

$pg_title = 'Medical Bills'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head">
  <div><h1>Medical Bills</h1><span class="sub">View: <a href="bills.php">All</a> | <a href="bills.php?status=Pending">Pending</a> | <a href="bills.php?status=Paid">Paid</a>
  &middot; settled at desk: <b><?php echo num($paidSett['t']); ?></b> UGX (<?php echo num($paidSett['c']); ?>)</span></div>
  <div class="noprint"><a class="btn success" href="bills.php?a=settle">&#127979; Settlement Desk</a>
  <a class="btn plain" href="bills.php?a=new">+ Submit bill</a>
  <a class="btn plain" href="export.php?table=bills">Export CSV</a></div>
</div>
<div class="twrap"><table class="data">
<tr><th>#</th><th>Date</th><th>Member</th><th>Provider</th><th>Treatment</th><th class="num">Amount</th><th>Status</th><th class="noprint">Workflow</th></tr>
<?php if (!$rows): ?><tr><td colspan="8" style="color:#5b7c99">No bills recorded.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $bc = $r['status']=='Paid'?'green':($r['status']=='Rejected'?'red':($r['status']=='Approved'?'blue':'amber')); ?>
<tr><td><?php echo e($r['id']); ?></td>
<td><?php echo e(date('d M Y', strtotime($r['bill_date']))); ?></td>
<td><b><?php echo e($r['member_no']); ?></b><br><?php echo e($r['full_names']); ?></td>
<td><?php echo e($r['provider']); ?></td>
<td><?php echo e($r['description']); ?></td>
<td class="num"><b><?php echo ugx($r['amount']); ?></b></td>
<td><span class="badge <?php echo $bc; ?>"><?php echo e($r['status']); ?></span></td>
<td class="noprint"><?php if (can(array('Super Admin','Administrator','Finance')) && $r['status']!=='Paid' && $r['status']!=='Rejected'): ?>
<form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="setstatus"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
<?php if ($r['status']==='Pending'): ?><input type="hidden" name="status" value="Approved"><button class="btn small plain" type="submit">Approve</button><?php endif; ?>
<?php if ($r['status']==='Approved'): ?><input type="hidden" name="status" value="Paid"><button class="btn small success" type="submit">Pay</button><?php endif; ?>
<input type="hidden" name="status" value="Rejected"><button class="btn small danger" type="submit">Reject</button>
</form><?php else: ?>—<?php endif; ?></td></tr>
<?php endforeach; ?>
</table></div>
<?php echo pager($total, $per, $page, 'bills.php' . ($status ? '?status=' . $status : '')); ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
