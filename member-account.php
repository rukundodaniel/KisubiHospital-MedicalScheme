<?php
/** v6.0 MEMBER DEPOSIT ACCOUNT — individual ledger with running balance, totals,
 *  pending transactions, statement print/export. Facility-locked users only ever see
 *  their own members. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));
if (!mb_require_tbl()) { include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/footer.php'; exit; }
$OWNER = fac_is_owner();
$mid = intval(get('member'));
$st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($mid)); $m = $st->fetch();
if (!$m) { http_response_code(404); die('Member not found.'); }
$locked = fac_scope_fid();
if ($locked > 0 && intval($m['facility_id']) > 0 && intval($m['facility_id']) !== $locked) {
    flash('This member belongs to another institution — your login is locked to your own facility.', 'err');
    redirect(fac_scope_home());
}
$GF = $locked > 0 ? $locked : intval(get('facility'));
$fac = $GF > 0 ? fac_of($GF) : null;
$plan = null;
try { require_once __DIR__ . '/includes/insurance-core.php'; $en = ins_enrolment($mid); if ($en) $plan = $en['plan_name']; } catch (Exception $e) {}
$led = mb_ledger($mid);
$cur = member_balance($mid);
$totDep = 0.0; $totDed = 0.0; $totAdj = 0.0; $firstDate = '';
foreach ($led as $r) { $totDep += $r['credit']; $totDed += $r['debit']; if (strpos((string)$r['desc'], 'APPROVED ADJUSTMENT') !== false) $totAdj += (float)$r['credit'] - (float)$r['debit']; if ($firstDate === '' && $r['date'] !== '') $firstDate = (string)$r['date']; }
$pendW = db()->prepare("SELECT * FROM withdrawals WHERE member_id=? AND status='Pending' ORDER BY id DESC LIMIT 10"); $pendW->execute(array($mid)); $pendW = $pendW->fetchAll();
$pendB = db()->prepare("SELECT * FROM member_bills WHERE member_id=? AND status='Pending' ORDER BY id DESC LIMIT 10"); $pendB->execute(array($mid)); $pendB = $pendB->fetchAll();

/* ---- authorized deposit (facility may fund a member) ---- */
$canDeposit = can(array('Super Admin', 'Administrator', 'Finance'));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canDeposit) {
    csrf_check();
    if (post('op') === 'add_deposit') {
        $amt = round((float)str_replace(',', '', post('amount')), 2);
        if ($amt <= 0) { flash('Deposit amount must be positive.', 'err'); redirect('member-account.php?member=' . $mid); }
        $ref0 = trim((string)post('ref'));
        if ($ref0 !== '') { // duplicate-submission guard: one reference credits once
            $du = db()->prepare('SELECT id FROM deposits WHERE member_id=? AND reference=? ORDER BY id DESC LIMIT 1'); $du->execute(array($mid, $ref0));
            if ($du->fetch()) { flash('Reference ' . $ref0 . ' was already recorded for this member — nothing was added.', 'err'); redirect('member-account.php?member=' . $mid . ($GF > 0 ? '&facility=' . $GF : '')); }
        }
        $maxDep = $amt; // authorized staff entry — posted immediately, correctable by reversal only
        $by = $_SESSION['uname'];
        try {
            db()->beginTransaction();
            db()->prepare('INSERT INTO deposits (member_id, deposit_date, amount, payment_method, reference, note, entered_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
               ->execute(array($mid, today(), $maxDep, mb_substr((string)(post('method') ?: 'Cash'), 0, 30), mb_substr((string)post('ref'), 0, 80),
                   mb_substr('Member account deposit' . (post('note') !== '' ? ' — ' . post('note') : ''), 0, 200), $by, date('Y-m-d H:i:s')));
            $did = intval(db()->lastInsertId());
            db()->prepare('UPDATE members SET deposit_amount=? WHERE id=?')->execute(array(member_balance($mid), $mid));
            db()->commit();
            gl_post(today(), array(array('1000', $maxDep, 0), array('2000', 0, $maxDep)), 'Member deposit #' . $did . ' for member #' . $mid, 'mbdeposit', $did, $GF);
            log_action('Deposit ' . ugx($maxDep) . ' recorded for ' . $m['member_no'] . ' (staff authorized)', 'deposits', $did);
            mb_notify('member_deposit', array('name' => $m['full_names'], 'amount' => ugx($maxDep), 'balance' => ugx(member_balance($mid)), 'ref' => 'DEP-' . $did, 'member_no' => $m['member_no']), $GF, $mid, 'deposits', $did);
            flash('Deposit recorded — member balance is now ' . ugx(member_balance($mid)) . '.', 'ok');
        } catch (Exception $e) { if (db()->inTransaction()) db()->rollBack(); flash('Deposit failed: ' . $e->getMessage(), 'err'); }
        redirect('member-account.php?member=' . $mid . ($GF > 0 ? '&facility=' . $GF : ''));
    }
    if (post('op') === 'adjust') { // v6.1 — approved correction (central finance only; never deletes history)
        $amtA = round((float)str_replace(',', '', post('amount')), 2);
        $dirA = post('dir') === '-' ? -1 : 1;
        $rsnA = trim((string)post('reason'));
        if ($amtA <= 0 || $rsnA === '') { flash('An adjustment needs a positive amount and a written reason.', 'err'); redirect('member-account.php?member=' . $mid); }
        if ($dirA < 0 && $cur - $amtA < -0.005 && trim((string)post('force')) !== '1') { flash('A downward adjustment cannot exceed the available deposit (' . ugx($cur) . ') — it is blocked unless an authorized credit facility exists.', 'err'); redirect('member-account.php?member=' . $mid . ($GF > 0 ? '&facility=' . $GF : '')); }
        $by = $_SESSION['uname'];
        try {
            db()->beginTransaction();
            if ($dirA > 0) {
                db()->prepare('INSERT INTO deposits (member_id, deposit_date, amount, payment_method, reference, note, entered_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
                   ->execute(array($mid, today(), $amtA, 'Adjustment', 'ADJ-' . strtoupper(substr(md5($mid . microtime()), 0, 8)), 'APPROVED ADJUSTMENT — ' . mb_substr($rsnA, 0, 165), $by, date('Y-m-d H:i:s')));
            } else {
                db()->prepare("INSERT INTO withdrawals (member_id, withdraw_date, amount, purpose, status, processed_by, paid_date, note, created_at, prev_balance, new_balance) VALUES (?,?,?,?, 'Paid', ?,?,?,?,?,?)")
                   ->execute(array($mid, today(), $amtA, 'APPROVED ADJUSTMENT (-) — ' . mb_substr($rsnA, 0, 150), $by, today(), 'Adjustment', date('Y-m-d H:i:s'), $cur, round($cur - $amtA, 2)));
            }
            $aid = intval(db()->lastInsertId());
            db()->prepare('UPDATE members SET deposit_amount=? WHERE id=?')->execute(array(member_balance($mid), $mid));
            db()->commit();
            $legsA = $dirA > 0 ? array(array('1000', $amtA, 0), array('2000', 0, $amtA)) : array(array('2000', $amtA, 0), array('1000', 0, $amtA));
            gl_post(today(), $legsA, 'Approved adjustment (' . ($dirA > 0 ? '+' : '-') . num($amtA) . ') member #' . $mid . ': ' . $rsnA, 'mbadjust', $aid, $GF);
            log_action('ADJUSTMENT (' . ($dirA > 0 ? '+' : '-') . ugx($amtA) . ') for ' . $m['member_no'] . ' by ' . $by . ': ' . $rsnA, $dirA > 0 ? 'deposits' : 'withdrawals', $aid);
            mb_notify('member_deposit', array('name' => (string)$m['full_names'], 'amount' => ($dirA > 0 ? '+' : '-') . ugx($amtA), 'balance' => ugx(member_balance($mid)), 'ref' => 'ADJ-' . $aid, 'member_no' => (string)$m['member_no']), $GF, $mid, 'members', $mid);
            flash('Adjustment posted — balance is now ' . ugx(member_balance($mid)) . '.', 'ok');
        } catch (Exception $e) { if (db()->inTransaction()) db()->rollBack(); flash('Adjustment failed: ' . $e->getMessage(), 'err'); }
        redirect('member-account.php?member=' . $mid . ($GF > 0 ? '&facility=' . $GF : ''));
    }
}

/* ---- CSV statement export (authorized download) ---- */
if (get('csv') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statement-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$m['member_no']) . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, array('Date', 'Reference', 'Description', 'Deposited (UGX)', 'Deducted (UGX)', 'Running balance (UGX)', 'Status'));
    foreach ($led as $r) fputcsv($o, array($r['date'], $r['ref'], $r['desc'], $r['credit'] ?: '', $r['debit'] ?: '', $r['balance'], $r['status']));
    fclose($o); exit;
}

$page_title = 'Member account — ' . $m['full_names'];
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>&#128176; MEMBER DEPOSIT ACCOUNT</h1>
 <span class="sub"><?php echo e($m['full_names']); ?> (<?php echo e($m['member_no']); ?>)<?php echo $fac ? ' &middot; ' . e($fac['name']) : ''; ?></span>
 <span style="float:right;display:flex;gap:8px"><a class="btn small plain" onclick="window.print();return false" href="#">&#128424; Print statement</a>
 <a class="btn small plain" href="member-account.php?member=<?php echo $mid; ?>&csv=1">&#8615; Export CSV</a></span>
</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">
 <div class="kpi green"><div class="k-label">Current available balance</div><div class="k-value"><?php echo ugx($cur); ?></div><div class="k-sub">kept in sync with the ledger below</div></div>
 <div class="kpi"><div class="k-label">Opening balance (start of record)</div><div class="k-value"><?php echo ugx(0); ?></div><div class="k-sub">first movement <?php echo e($firstDate ?: '—'); ?></div></div>
 <div class="kpi"><div class="k-label">Total deposits</div><div class="k-value"><?php echo ugx($totDep); ?></div><div class="k-sub"><?php $c1 = db()->prepare('SELECT COUNT(*) FROM deposits WHERE member_id=?'); $c1->execute(array($mid)); echo num(intval($c1->fetchColumn())); ?> entries</div></div>
 <div class="kpi"><div class="k-label">Total consumed (bills + withdrawals)</div><div class="k-value"><?php echo ugx($totDed); ?></div><div class="k-sub">every deduction has a receipt reference</div></div>
 <div class="kpi"><div class="k-label">Approved adjustments (net)</div><div class="k-value"><?php echo ugx($totAdj); ?></div><div class="k-sub">corrections by authorized central finance only</div></div>
</div>

<div class="panel"><h2 style="margin-top:0">&#128100; Member profile</h2>
 <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;font-size:14px">
  <div><b>Membership no.</b><br><?php echo e($m['card_no'] !== '' ? $m['card_no'] : $m['member_no']); ?></div>
  <div><b>Institution member no.</b><br><?php echo e($m['inst_member_no'] !== '' ? $m['inst_member_no'] : '—'); ?></div>
  <div><b>Telephone</b><br><?php echo e($m['phone_no']); ?></div>
  <div><b>National ID</b><br><?php echo e($m['national_id'] !== '' ? $m['national_id'] : '—'); ?></div>
  <div><b>Category / plan</b><br><?php echo e($m['bcategory'] !== '' ? $m['bcategory'] : 'Member'); ?><?php echo $plan ? ' &middot; ' . e($plan) : ''; ?></div>
  <div><b>Status</b><br><?php echo e($m['status']); ?> &middot; eligibility <?php echo e($m['eligibility'] ?: 'Active'); ?></div>
 </div>
</div>

<?php if ($pendW || $pendB): ?>
<div class="panel" style="border:2px solid #8a6410;background:#FFF9EA"><h2 style="margin-top:0">&#8987; Pending transactions (not yet affecting the balance)</h2>
 <table class="tbl"><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th style="text-align:right">Amount</th></tr>
 <?php foreach ($pendB as $p): ?><tr><td><?php echo e($p['bill_date']); ?></td><td>Unsettled bill</td><td><a href="member-bills.php?view=bill&id=<?php echo intval($p['id']); ?>"><?php echo e($p['bill_no']); ?></a></td><td>Bill awaiting funds — <?php echo e($p['patient_name']); ?></td><td style="text-align:right"><?php echo num($p['total']); ?></td></tr><?php endforeach;
 foreach ($pendW as $p): ?><tr><td><?php echo e($p['withdraw_date']); ?></td><td><?php echo e($p['purpose']); ?></td><td>#<?php echo intval($p['id']); ?></td><td>Waiting for finance processing</td><td style="text-align:right"><?php echo num($p['amount']); ?></td></tr><?php endforeach; ?>
 </table>
</div>
<?php endif; ?>

<?php if ($canDeposit): ?>
<div class="panel"><h2 style="margin-top:0">&#128176; Record an authorized deposit</h2>
 <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end"><?php echo csrf_field(); ?><input type="hidden" name="op" value="add_deposit">
  <div><label class="f">Amount (UGX)</label><input class="f" type="number" name="amount" min="1" step="100" required style="width:150px;margin:0"></div>
  <div><label class="f">Method</label><select class="f" name="method" style="margin:0"><option>Cash</option><option>MTN MoMo</option><option>Airtel Money</option><option>Bank Transfer</option></select></div>
  <div><label class="f">Reference</label><input class="f" name="ref" placeholder="till / txn ref" style="margin:0;width:160px"></div>
  <div style="flex:1;min-width:180px"><label class="f">Note</label><input class="f" name="note" style="margin:0"></div>
  <button class="btn primary" type="submit">Credit account + SMS member</button></form>
 <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:8px;border-top:1px dashed #bcd6ea;padding-top:8px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="adjust">
  <div><label class="f">Approved adjustment (correction)</label><div style="display:flex;gap:4px"><select class="f" name="dir" style="margin:0;width:80px"><option value="+">Add</option><option value="-">Deduct</option></select><input class="f" name="amount" type="number" step="0.01" min="0.01" placeholder="amount" style="margin:0;width:130px" required></div></div>
  <div style="flex:1;min-width:200px"><label class="f">Reason — mandatory, fully audited</label><input class="f" name="reason" style="margin:0" placeholder="e.g. MoMo receipt duplicated — corrected"></div>
  <button class="btn small primary" type="submit" onclick="return confirm('Post this approved adjustment to the member deposit ledger?')">Post adjustment</button>
 </form>
 <p class="sub" style="margin:6px 0 0">Deposits here are posted immediately because they are entered by authorized scheme staff; every entry is stamped in the ledger and can only be corrected by reversal.</p>
</div>
<?php endif; ?>

<div class="panel"><h2 style="margin-top:0">&#128220; Account transaction history — running balance</h2>
 <table class="tbl"><tr><th>Date</th><th>Reference</th><th>Description</th><th style="text-align:right">Deposited</th><th style="text-align:right">Deducted</th><th style="text-align:right">Balance</th><th>Status</th></tr>
 <?php if (!$led) echo '<tr><td colspan="7" style="color:#7a94aa">No transactions yet.</td></tr>';
 foreach (array_reverse($led) as $r): ?>
 <tr><td><?php echo e($r['date']); ?></td><td><?php echo e($r['ref']); ?></td><td><?php echo e($r['desc']); ?></td>
  <td style="text-align:right;color:#1e7f3c"><?php echo $r['credit'] > 0 ? '+' . num($r['credit']) : ''; ?></td>
  <td style="text-align:right;color:#a33"><?php echo $r['debit'] > 0 ? '−' . num($r['debit']) : ''; ?></td>
  <td style="text-align:right"><b><?php echo num($r['balance']); ?></b></td>
  <td><?php echo e($r['status']); ?></td></tr>
 <?php endforeach; ?>
 <tr style="background:#EAF2FA"><td colspan="3"><b>Current available balance</b></td><td style="text-align:right"><b><?php echo num($totDep); ?></b></td><td style="text-align:right"><b><?php echo num($totDed); ?></b></td><td style="text-align:right"><b><?php echo num($cur); ?></b></td><td></td></tr></table>
</div>
<?php if ($GF > 0): ?><p><a class="btn plain" href="member-bills.php?view=new&facility=<?php echo $GF ?>&member=<?php echo $mid; ?>">&#129534; Create a bill for this member</a></p><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
