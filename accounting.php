<?php
/** v4.0 Accounting hub — Chart of Accounts · General Ledger · Trial Balance · Income &
 *  Expenditure · Balance Sheet · Cash Flow · Bank Reconciliation · Manual Journals. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/acc.php';
require_role(array('Super Admin', 'Administrator', 'Finance'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$TAB = get('tab', 'overview');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'add_acc') {
        $code = trim(post('acc_code')); $name = trim(post('acc_name')); $type = post('acc_type');
        if ($code === '' || $name === '' || !preg_match('/^[0-9A-Z\-]{2,20}$/', $code) || !in_array($type, array('Asset','Liability','Equity','Income','Expense'), true)) {
            flash('Account: code (letters/digits, e.g. 5300), name and a valid type are required.', 'err'); redirect('accounting.php?tab=coa');
        }
        try {
            $dup = db()->prepare('SELECT id FROM accounts WHERE acc_code=?'); $dup->execute(array($code));
            if ($dup->fetch()) { flash('Account code ' . $code . ' already exists.', 'err'); }
            else {
                db()->prepare('INSERT INTO accounts (acc_code, acc_name, acc_type, description, is_system, active, created_at) VALUES (?,?,?,?,0,1,?)')
                   ->execute(array($code, $name, $type, trim(post('description')), date('Y-m-d H:i:s')));
                log_action('Created GL account ' . $code . ' ' . $name, 'accounts', intval(db()->lastInsertId()));
                flash('Account ' . $code . ' — ' . $name . ' added to the chart.', 'ok');
            }
        } catch (Exception $e) { flash('Could not add account: ' . $e->getMessage(), 'err'); }
        redirect('accounting.php?tab=coa');
    }
    if ($op === 'toggle_acc') {
        $st = db()->prepare('SELECT * FROM accounts WHERE id=?'); $st->execute(array(intval(post('id')))); $a = $st->fetch();
        if ($a && intval($a['is_system']) === 0) { db()->prepare('UPDATE accounts SET active=1-active WHERE id=?')->execute(array(intval($a['id']))); flash('Account toggled.', 'info'); }
        else flash('System accounts cannot be disabled.', 'err');
        redirect('accounting.php?tab=coa');
    }
    if ($op === 'journal') {
        $date = post('entry_date') !== '' ? post('entry_date') : today();
        $codes = isset($_POST['acc']) && is_array($_POST['acc']) ? $_POST['acc'] : array();
        $drs = isset($_POST['debit']) && is_array($_POST['debit']) ? $_POST['debit'] : array();
        $crs = isset($_POST['credit']) && is_array($_POST['credit']) ? $_POST['credit'] : array();
        $lines = array(); $td = 0.0; $tc = 0.0;
        foreach ($codes as $i => $cd) {
            $cd = trim((string)$cd); if ($cd === '') continue;
            $dv = (float)str_replace(',', '', (string)(isset($drs[$i]) ? $drs[$i] : 0)); $cv = (float)str_replace(',', '', (string)(isset($crs[$i]) ? $crs[$i] : 0));
            if ($dv <= 0 && $cv <= 0) continue;
            $lines[] = array($cd, $dv, $cv); $td += $dv; $tc += $cv;
        }
        if (count($lines) < 2) { flash('A journal needs at least two lines (a debit and a credit).', 'err'); redirect('accounting.php?tab=journal'); }
        if (abs($td - $tc) > 0.005) { flash('Journal does not balance: debits ' . ugx($td) . ' vs credits ' . ugx($tc) . '.', 'err'); redirect('accounting.php?tab=journal'); }
        if (gl_post($date, $lines, trim(post('memo')), 'journal', 0, intval(post('facility_id')))) { flash('Journal posted.', 'ok'); log_action('Manual journal: ' . trim(post('memo')), 'gl_entries', 0); }
        else flash('Posting failed — unknown account code?', 'err');
        redirect('accounting.php?tab=gl');
    }
    if ($op === 'rebuild') {
        if (!fac_is_owner() && !can(array('Finance'))) { flash('Only the owner or Finance may rebuild the ledger.', 'err'); redirect('accounting.php'); }
        $n = acc_rebuild();
        flash('Ledger rebuilt from history: ' . num($n) . ' journal entries generated (deposits, settlements, payouts, claim accruals & disbursements).', 'ok');
        redirect('accounting.php');
    }
    if ($op === 'stmt_add') {
        $acct = trim(post('acct')); $dt = post('stmt_date') !== '' ? post('stmt_date') : today();
        $amt = (float)str_replace(',', '', post('amount')); $desc = trim(post('desc'));
        if ($acct === '' || abs($amt) < 0.005) { flash('Statement line needs an account and a non-zero amount.', 'err'); redirect('accounting.php?tab=bank'); }
        try { db()->prepare('INSERT INTO bank_statements (acct_code, stmt_date, description, amount, matched_gl, created_by, created_at) VALUES (?,?,?,?,0,?,?)')
                ->execute(array($acct, $dt, mb_substr($desc, 0, 200), $amt, $_SESSION['uname'], date('Y-m-d H:i:s'))); flash('Statement line added.', 'ok'); }
        catch (Exception $e) { flash('Could not add: ' . $e->getMessage(), 'err'); }
        redirect('accounting.php?tab=bank');
    }
    if ($op === 'stmt_match') {
        $sid = intval(post('sid')); $glid = intval(post('glid'));
        $st = db()->prepare('SELECT g.acc_code, g.debit, g.credit FROM gl_entries g WHERE g.id=?'); $st->execute(array($glid)); $g = $st->fetch();
        $st = db()->prepare('SELECT * FROM bank_statements WHERE id=?'); $st->execute(array($sid)); $s = $st->fetch();
        if (!$g || !$s) { flash('Line or entry not found.', 'err'); redirect('accounting.php?tab=bank'); }
        $glAmt = (float)$g['debit'] - (float)$g['credit'];
        if (abs(abs($glAmt) - abs((float)$s['amount'])) > 0.005 && floatval(post('force')) !== 1) { flash('Amounts differ — tick force-match to override.', 'err'); redirect('accounting.php?tab=bank'); }
        db()->prepare('UPDATE bank_statements SET matched_gl=?, matched_at=? WHERE id=?')->execute(array($glid, date('Y-m-d H:i:s'), $sid));
        flash('Matched — reconciled.', 'ok'); redirect('accounting.php?tab=bank&acct=' . urlencode($s['acct_code']));
    }
    if ($op === 'stmt_unmatch') { db()->prepare('UPDATE bank_statements SET matched_gl=0, matched_at=NULL WHERE id=?')->execute(array(intval(post('sid')))); flash('Unmatched.', 'info'); redirect('accounting.php?tab=bank'); }
    if ($op === 'stmt_del') { db()->prepare('DELETE FROM bank_statements WHERE id=? AND matched_gl=0')->execute(array(intval(post('sid')))); flash('Line deleted.', 'info'); redirect('accounting.php?tab=bank'); }
}

/** rebuild GL from history — idempotent */
function acc_rebuild() {
    db()->exec("DELETE FROM gl_entries WHERE source<>'journal'");
    $n = 0;
    foreach (db()->query("SELECT d.* FROM deposits d ORDER BY d.id")->fetchAll() as $r) {
        gl_post(substr((string)$r['deposit_date'], 0, 10), array(array(acc_method_acct($r['payment_method']), (float)$r['amount'], 0), array('2000', 0, (float)$r['amount'])),
            'Deposit ' . $r['id'] . ' member #' . $r['member_id'] . ' (' . $r['payment_method'] . ')', 'deposit', intval($r['id'])); $n++;
    }
    foreach (db()->query("SELECT w.* FROM withdrawals w WHERE w.status='Paid'")->fetchAll() as $r) {
        if (trim((string)$r['auth_method']) !== '') {
            gl_post(substr((string)$r['withdraw_date'], 0, 10), array(array('2000', (float)$r['amount'], 0), array('1000', 0, (float)$r['amount'])),
                'Bill settlement ' . $r['id'] . ' member #' . $r['member_id'] . ' (' . $r['auth_method'] . ')', 'settle', intval($r['id'])); $n++;
        } else {
            gl_post(substr((string)$r['withdraw_date'], 0, 10), array(array('2000', (float)$r['amount'], 0), array('1000', 0, (float)$r['amount'])),
                'Payout ' . $r['id'] . ' member #' . $r['member_id'], 'withdrawal', intval($r['id'])); $n++;
        }
    }
    try {
        foreach (db()->query("SELECT c.* FROM facility_claims c WHERE c.submitted_at IS NOT NULL")->fetchAll() as $cl) {
            gl_post(substr((string)$cl['submitted_at'], 0, 10), array(array('5000', (float)$cl['amount_billed'], 0), array('2100', 0, (float)$cl['amount_billed'])),
                'Facility claim ' . $cl['claim_no'] . ' accrual — ' . $cl['facility_name'], 'claim', intval($cl['id']), intval($cl['facility_id'])); $n++;
        }
    } catch (Exception $e) {}
    try {
        $revOk = false; try { db()->query('SELECT reversed FROM disbursements LIMIT 1'); $revOk = true; } catch (Exception $e) {}
        foreach (db()->query('SELECT * FROM disbursements ORDER BY id')->fetchAll() as $d) {
            $amt = (float)$d['amount']; if ($amt <= 0) continue;
            if ($revOk && intval($d['reversed']) === 1) {
                gl_post(substr((string)$d['paid_at'], 0, 10), array(array(acc_method_acct($d['method']), $amt, 0), array('2100', 0, $amt)),
                    'REVERSAL of disbursement #' . intval($d['id']) . ' for ' . $d['claim_no'], 'claimreverse', intval($d['claim_id']), intval($d['facility_id'])); $n++;
            } else {
                gl_post(substr((string)$d['paid_at'], 0, 10), array(array('2100', $amt, 0), array(acc_method_acct($d['method']), 0, $amt)),
                    'Disbursement for ' . $d['claim_no'] . ' to ' . $d['facility_name'] . ' via ' . $d['method'], 'claimpay', intval($d['claim_id']), intval($d['facility_id'])); $n++;
            }
        }
    } catch (Exception $e) {}
    return $n;
}

/* per-tab data */
$from = post('from') !== '' ? post('from') : get('from'); $to = get('to');
if (!acc_tbls()) { $page_title = 'Accounting'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>Accounting not yet initialised</h2><p>Run <a href="migrate.php">migrate.php</a> once, then rebuild from history here.</p></div>'; include __DIR__ . '/includes/footer.php'; exit; }
$bals = acc_balances($from, $to);
$totD = 0.0; $totC = 0.0; foreach ($bals as $b) { $totD += (float)$b['d']; $totC += (float)$b['c']; }

$page_title = 'Accounting & Financial Statements';
include __DIR__ . '/includes/header.php';
$tabs = array('overview' => 'Overview', 'coa' => 'Chart of Accounts', 'gl' => 'General Ledger', 'tb' => 'Trial Balance',
              'ie' => 'Income &amp; Expenditure', 'bs' => 'Balance Sheet', 'cf' => 'Cash Flow', 'bank' => 'Bank Reconciliation', 'journal' => 'Manual Journal');
?>
<div class="page-head"><h1>&#128202; Accounting Suite</h1>
<span class="sub">Double-entry core for the saving scheme — every deposit, settlement and disbursement posts here automatically.</span></div>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
<?php foreach ($tabs as $k => $lab): ?><a class="btn small <?php echo $TAB===$k?'primary':'plain'; ?>" href="accounting.php?tab=<?php echo $k; ?>"><?php echo $lab; ?></a><?php endforeach; ?>
<form method="get" style="display:inline-flex;gap:5px;margin-left:auto;align-items:center"><input type="hidden" name="tab" value="<?php echo e($TAB); ?>">
 <input class="f" type="date" name="from" value="<?php echo e($from); ?>" style="margin:0;padding:4px 8px"> <span style="color:#7a94aa">→</span>
 <input class="f" type="date" name="to" value="<?php echo e($to); ?>" style="margin:0;padding:4px 8px"><button class="btn small" type="submit">Filter</button></form></div>

<?php if ($TAB === 'overview'): $inc = acc_type_total('Income',$from,$to); $exp = acc_type_total('Expense',$from,$to);
$assets = acc_type_total('Asset',$from,$to); $liab = acc_type_total('Liability',$from,$to); ?>
<div class="kpi-row">
 <div class="kpi green"><div class="k-label">Total assets (cash + banks + institution float)</div><div class="k-value"><?php echo ugx($assets); ?></div><div class="k-sub">live from GL</div></div>
 <?php $depOwed = 0.0; foreach (db()->query("SELECT COALESCE(SUM(credit),0)-COALESCE(SUM(debit),0) v FROM gl_entries WHERE acc_code='2000'")->fetchAll() as $rr) $depOwed = (float)$rr[0]; ?><div class="kpi"><div class="k-label">Member deposits owed</div><div class="k-value"><?php echo ugx($depOwed); ?></div><div class="k-sub">GL 2000 net credit</div></div>
 <div class="kpi gold"><div class="k-label">Net surplus (period)</div><div class="k-value"><?php echo ugx($inc - $exp); ?></div><div class="k-sub">income − expense</div></div>
 <div class="kpi red"><div class="k-label">Ledger balance check</div><div class="k-value"><?php echo abs($totD-$totC) < 0.01 ? 'IN BALANCE' : 'OUT'; ?></div><div class="k-sub">Dr <?php echo number_format($totD,0); ?> / Cr <?php echo number_format($totC,0); ?></div></div>
</div>
<div class="panel"><h2 style="margin-top:0">Rebuild from history</h2>
<p style="font-size:13px;color:#5b7c99">First time? Generate the general ledger from everything the scheme has already recorded (deposits, settlements, payouts, claims, disbursements). Safe to re-run — only auto-posted entries are replaced; manual journals are kept.</p>
<form method="post" onsubmit="return confirm('Rebuild the auto ledger from all history?\nManual journals survive.')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="rebuild"><button class="btn primary" type="submit">&#8635; Rebuild ledger from history</button></form></div>
<div class="grid-2">
<div class="panel"><h2 style="margin-top:0">Balances by account (all time)</h2>
<table class="data" style="margin:0"><tr><th>Acct</th><th>Name</th><th>Type</th><th class="num">Balance</th></tr>
<?php foreach ($bals as $b): if (abs((float)$b['net']) < 0.005) continue; ?>
<tr><td><?php echo e($b['acc_code']); ?></td><td><?php echo e($b['acc_name']); ?></td><td><?php echo e($b['acc_type']); ?></td><td class="num"><b><?php echo ugx($b['net']); ?></b></td></tr>
<?php endforeach; ?></table></div>
<div class="panel"><h2 style="margin-top:0">Quick statements</h2>
<p><a class="btn" href="accounting.php?tab=tb">Trial balance</a> <a class="btn" href="accounting.php?tab=ie">Income &amp; Expenditure</a><br style="line-height:2.4">
<a class="btn" href="accounting.php?tab=bs">Balance sheet</a> <a class="btn" href="accounting.php?tab=cf">Cash flow</a><br style="line-height:2.4">
<a class="btn" href="accounting.php?tab=bank">Bank reconciliation</a> <a class="btn" href="accounting.php?tab=journal">Manual journal</a></p>
<p style="font-size:12px;color:#5b7c99">Auto-posting is live on: staff deposits &amp; cash confirmations, bill settlements at the desk, member payouts, facility claim accruals &amp; disbursements, HMIS-created transactions.</p></div>
</div>

<?php elseif ($TAB === 'coa'): ?>
<div class="panel"><h2 style="margin-top:0">Chart of Accounts</h2>
<table class="data" style="margin:0"><tr><th>Code</th><th>Account</th><th>Type</th><th>Balance</th><th>Origin</th><th class="noprint"></th></tr>
<?php foreach ($bals as $b): ?>
<tr><td><b><?php echo e($b['acc_code']); ?></b></td><td><?php echo e($b['acc_name']); ?><br><span style="font-size:11px;color:#7a94aa"><?php echo e($b['description']); ?></span></td>
<td><?php echo e($b['acc_type']); ?></td><td class="num"><?php echo ugx($b['net']); ?></td>
<td><?php echo intval($b['is_system']) ? '<span style="font:bold 9.5px Arial;color:#5b7c99;background:#F0F4F8;padding:1px 7px;border-radius:3px">SYSTEM</span>' : '<span style="font:bold 9.5px Arial;color:#20603a;background:#e6f6ea;padding:1px 7px;border-radius:3px">CUSTOM</span>'; ?> <?php echo intval($b['active'])===0?'<span class="badge" style="background:#FDECEC;color:#8c2424">inactive</span>':''; ?></td>
<td class="noprint"><?php if (!intval($b['is_system'])): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="toggle_acc"><input type="hidden" name="id" value="<?php echo e($b['id']); ?>"><button class="btn small" type="submit">Enable/Disable</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></table>
<form method="post" style="display:flex;gap:8px;align-items:flex-end;margin-top:12px;flex-wrap:wrap"><?php echo csrf_field(); ?><input type="hidden" name="op" value="add_acc">
 <div><label class="f">Code</label><input class="f" name="acc_code" style="width:100px" placeholder="5300"></div>
 <div style="flex:1;min-width:180px"><label class="f">Account name</label><input class="f" name="acc_name"></div>
 <div><label class="f">Type</label><select class="f" name="acc_type"><?php foreach(array('Asset','Liability','Equity','Income','Expense') as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?></select></div>
 <div style="flex:1;min-width:150px"><label class="f">Description</label><input class="f" name="description"></div>
 <button class="btn primary" type="submit">Add account</button></form></div>

<?php elseif ($TAB === 'gl'):
$w = '1=1'; $args = array();
if ($from !== '' && $from !== null) { $w .= ' AND entry_date>=?'; $args[] = $from; }
if ($to !== '' && $to !== null) { $w .= ' AND entry_date<=?'; $args[] = $to; }
$fa = intval(get('fac')); if ($fa > 0) { $w .= ' AND (facility_id=? OR facility_id=0)'; $args[] = $fa; }
$accf = get('acc'); if ($accf !== '' && $accf !== null) { $w .= ' AND acc_code=?'; $args[] = $accf; }
$st = db()->prepare("SELECT * FROM gl_entries WHERE $w ORDER BY entry_date DESC, id DESC LIMIT 400"); $st->execute($args); $gl = $st->fetchAll(); ?>
<div class="panel"><h2 style="margin-top:0">General Ledger (<?php echo count($gl); ?> latest)</h2>
<table class="data" style="margin:0"><tr><th>Date</th><th>Acct</th><th>Account</th><th>Details</th><th>Source</th><th class="num">Debit</th><th class="num">Credit</th></tr>
<?php $names = array(); foreach ($bals as $b) $names[$b['acc_code']] = $b['acc_name'];
foreach ($gl as $e): ?>
<tr><td style="white-space:nowrap"><?php echo e(date('d M Y', strtotime($e['entry_date']))); ?></td>
<td><?php echo e($e['acc_code']); ?></td><td style="font-size:12px"><?php echo e(isset($names[$e['acc_code']]) ? $names[$e['acc_code']] : $e['acc_code']); ?></td>
<td style="font-size:12px"><?php echo e($e['memo']); ?><?php if (intval($e['facility_id'])): ?> <span style="color:#1B6BB3"><?php echo fac_label($e['facility_id']); ?></span><?php endif; ?></td>
<td style="font-size:10.5px;color:#7a94aa"><?php echo e($e['source']); ?> #<?php echo e($e['source_id']); ?></td>
<td class="num"><?php echo floatval($e['debit']) ? number_format((float)$e['debit'], 0) : ''; ?></td>
<td class="num"><?php echo floatval($e['credit']) ? number_format((float)$e['credit'], 0) : ''; ?></td></tr>
<?php endforeach; if (!$gl): ?><tr><td colspan="7" style="color:#7a94aa">No entries yet — run “Rebuild ledger from history” on the Overview tab.</td></tr><?php endif; ?></table></div>

<?php elseif ($TAB === 'tb'): ?>
<div class="panel"><h2 style="margin-top:0">Trial Balance <?php echo $from||$to ? '(' . e($from ?: 'start') . ' → ' . e($to ?: date('d M Y')) . ')' : '(all time)'; ?></h2>
<table class="data" style="margin:0"><tr><th>Code</th><th>Account</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th></tr>
<?php $sd = 0.0; $sc = 0.0;
foreach ($bals as $b): if (abs((float)$b['d']) < 0.005 && abs((float)$b['c']) < 0.005) continue; $sd += (float)$b['d']; $sc += (float)$b['c']; ?>
<tr><td><?php echo e($b['acc_code']); ?></td><td><?php echo e($b['acc_name']); ?></td><td><?php echo e($b['acc_type']); ?></td>
<td class="num"><?php echo floatval($b['d']) ? number_format((float)$b['d'], 0) : '—'; ?></td>
<td class="num"><?php echo floatval($b['c']) ? number_format((float)$b['c'], 0) : '—'; ?></td></tr>
<?php endforeach; ?>
<tr><th colspan="3" class="num">TOTAL</th><th class="num"><?php echo number_format($sd, 0); ?></th><th class="num"><?php echo number_format($sc, 0); ?></th></tr>
</table>
<p style="margin-top:8px;font-weight:bold;color:<?php echo abs($sd-$sc)<0.01?'#20603a':'#8c2424'; ?>">&#10003; <?php echo abs($sd-$sc)<0.01 ? 'Books in balance (debits = credits)' : 'OUT OF BALANCE by ' . ugx(abs($sd-$sc)); ?></p>
<button class="btn noprint" onclick="window.print()">&#128424; Print</button></div>

<?php elseif ($TAB === 'ie'): $inc = acc_type_total('Income',$from,$to); $exp = acc_type_total('Expense',$from,$to); ?>
<div class="panel"><h2 style="margin-top:0">Statement of Income &amp; Expenditure</h2>
<table class="data" style="margin:0"><tr><th>Income accounts</th><th class="num">UGX</th></tr>
<?php foreach ($bals as $b): if ($b['acc_type'] !== 'Income' || abs((float)$b['net']) < 0.005) continue; ?>
<tr><td><?php echo e($b['acc_code']); ?> — <?php echo e($b['acc_name']); ?></td><td class="num"><?php echo number_format((float)$b['net'], 0); ?></td></tr><?php endforeach; ?>
<tr><th>Total income</th><th class="num"><?php echo number_format($inc, 0); ?></th></tr></table>
<table class="data" style="margin:12px 0 0"><tr><th>Expenditure accounts</th><th class="num">UGX</th></tr>
<?php foreach ($bals as $b): if ($b['acc_type'] !== 'Expense' || abs((float)$b['net']) < 0.005) continue; ?>
<tr><td><?php echo e($b['acc_code']); ?> — <?php echo e($b['acc_name']); ?></td><td class="num"><?php echo number_format((float)$b['net'], 0); ?></td></tr><?php endforeach; ?>
<tr><th>Total expenditure</th><th class="num"><?php echo number_format($exp, 0); ?></th></tr></table>
<h3 style="color:<?php echo $inc-$exp >= 0 ? '#20603a' : '#8c2424'; ?>;margin-bottom:0">Net surplus / (deficit): <?php echo ugx($inc - $exp); ?></h3>
<button class="btn noprint" onclick="window.print()">&#128424; Print</button></div>

<?php elseif ($TAB === 'bs'):
$assets = 0.0; $liab = 0.0; $eq = 0.0; $inc = acc_type_total('Income',$from,$to); $exp = acc_type_total('Expense',$from,$to);
foreach ($bals as $b) { if ($b['acc_type']==='Asset') $assets += (float)$b['net']; if ($b['acc_type']==='Liability') $liab += (float)$b['net']; if ($b['acc_type']==='Equity') $eq += (float)$b['net']; }
$plug = $assets - $liab - $eq - ($inc - $exp); ?>
<div class="grid-2">
<div class="panel"><h2 style="margin-top:0">Assets</h2><table class="data" style="margin:0">
<?php foreach ($bals as $b): if ($b['acc_type'] !== 'Asset' || abs((float)$b['net']) < 0.005) continue; ?>
<tr><td><?php echo e($b['acc_code']); ?> — <?php echo e($b['acc_name']); ?></td><td class="num"><?php echo number_format((float)$b['net'], 0); ?></td></tr><?php endforeach; ?>
<tr><th>Total assets</th><th class="num"><?php echo number_format($assets, 0); ?></th></tr></table></div>
<div class="panel"><h2 style="margin-top:0">Liabilities &amp; Equity</h2><table class="data" style="margin:0">
<?php foreach ($bals as $b): if ($b['acc_type'] !== 'Liability' || abs((float)$b['net']) < 0.005) continue; ?>
<tr><td><?php echo e($b['acc_code']); ?> — <?php echo e($b['acc_name']); ?></td><td class="num"><?php echo number_format((float)$b['net'], 0); ?></td></tr><?php endforeach; ?>
<tr><td style="color:#5b7c99">Current-period surplus</td><td class="num"><?php echo number_format($inc-$exp, 0); ?></td></tr>
<?php foreach ($bals as $b): if ($b['acc_type'] !== 'Equity' || abs((float)$b['net']) < 0.005) continue; ?>
<tr><td><?php echo e($b['acc_code']); ?> — <?php echo e($b['acc_name']); ?></td><td class="num"><?php echo number_format((float)$b['net'], 0); ?></td></tr><?php endforeach; ?>
<?php if (abs($plug) > 0.005): ?><tr><td style="color:#8a6410">Opening balances plug</td><td class="num"><?php echo number_format($plug, 0); ?></td></tr><?php endif; ?>
<tr><th>Total liabilities &amp; equity</th><th class="num"><?php echo number_format($liab + $eq + ($inc-$exp) + $plug, 0); ?></th></tr></table>
<p style="font-size:11.5px;color:<?php echo abs($assets-($liab+$eq+($inc-$exp)+$plug))<0.01?'#20603a':'#8c2424'; ?>">&#10003; balances</p>
<button class="btn noprint" onclick="window.print()">&#128424; Print</button></div></div>

<?php elseif ($TAB === 'cf'):
$months = array(); $anchor = date('Y-m-01');
for ($k = 11; $k >= 0; $k--) { $months[date('Y-m', strtotime($anchor . " -$k month"))] = array('in'=>0.0,'out'=>0.0); }
foreach (db()->query("SELECT entry_date, g.acc_code AS acc_code, debit, credit FROM gl_entries g JOIN accounts a ON a.acc_code=g.acc_code WHERE a.acc_type='Asset'")->fetchAll() as $e) {
    $key = substr((string)$e['entry_date'], 0, 7); if (!isset($months[$key])) continue;
    $net = (float)$e['debit'] - (float)$e['credit'];
    if ($net >= 0) $months[$key]['in'] += $net; else $months[$key]['out'] += -$net;
}
$ti = 0.0; $to2 = 0.0; foreach ($months as $m) { $ti += $m['in']; $to2 += $m['out']; } ?>
<div class="panel"><h2 style="margin-top:0">Cash Flow Statement (12 months)</h2>
<table class="data" style="margin:0"><tr><th>Month</th><th class="num">Money in (deposits, transfers in)</th><th class="num">Money out (settlements, disbursements)</th><th class="num">Net movement</th></tr>
<?php foreach ($months as $mo => $m): $net = $m['in'] - $m['out']; ?>
<tr><td><?php echo e(date('M Y', strtotime($mo . '-01'))); ?></td><td class="num" style="color:#20603a"><?php echo number_format($m['in'], 0); ?></td>
<td class="num" style="color:#8c2424"><?php echo number_format($m['out'], 0); ?></td>
<td class="num"><b style="color:<?php echo $net>=0?'#20603a':'#8c2424'; ?>"><?php echo number_format($net, 0); ?></b></td></tr><?php endforeach; ?>
<tr><th>12-month total</th><th class="num"><?php echo number_format($ti,0); ?></th><th class="num"><?php echo number_format($to2,0); ?></th><th class="num"><?php echo number_format($ti-$to2,0); ?></th></tr></table>
<button class="btn noprint" onclick="window.print()">&#128424; Print</button></div>

<?php elseif ($TAB === 'bank'):
$bAccts = array(); foreach ($bals as $b) if ($b['acc_type'] === 'Asset' && preg_match('/^1[0-5]/', $b['acc_code'])) $bAccts[] = $b;
$sel = get('acct') !== null && get('acct') !== '' ? get('acct') : (count($bAccts) ? $bAccts[0]['acc_code'] : '1010');
$st = db()->prepare('SELECT * FROM bank_statements WHERE acct_code=? ORDER BY stmt_date DESC, id DESC LIMIT 200'); $st->execute(array($sel)); $lines = $st->fetchAll();
$unmatched = array(); foreach ($lines as $l) if (!intval($l['matched_gl'])) $unmatched[] = $l;
$st = db()->prepare('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM gl_entries WHERE acc_code=?'); $st->execute(array($sel)); $g = $st->fetch();
$glBal = (float)$g['d'] - (float)$g['c']; $stmtSum = 0.0; foreach ($lines as $l) $stmtSum += (float)$l['amount']; ?>
<div class="kpi-row">
 <div class="kpi"><div class="k-label">Ledger balance</div><div class="k-value"><?php echo ugx($glBal); ?></div><div class="k-sub"><?php echo e($sel); ?> per GL</div></div>
 <div class="kpi green"><div class="k-label">Bank statement total</div><div class="k-value"><?php echo ugx($stmtSum); ?></div><div class="k-sub"><?php echo count($lines); ?> lines</div></div>
 <div class="kpi red"><div class="k-label">Difference</div><div class="k-value"><?php echo ugx($glBal - $stmtSum); ?></div><div class="k-sub"><?php echo count($unmatched); ?> unmatched statement lines</div></div>
</div>
<div class="grid-2">
<div class="panel"><h2 style="margin-top:0">Account</h2>
<form method="get" style="display:flex;gap:8px;align-items:flex-end"><input type="hidden" name="tab" value="bank">
<select class="f" name="acct" onchange="this.form.submit()"><?php foreach ($bAccts as $b): ?><option value="<?php echo e($b['acc_code']); ?>" <?php echo $sel===$b['acc_code']?'selected':''; ?>><?php echo e($b['acc_code']); ?> — <?php echo e($b['acc_name']); ?></option><?php endforeach; ?></select>
<button class="btn small" type="submit">Go</button></form>
<form method="post" style="margin-top:14px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="stmt_add"><input type="hidden" name="acct" value="<?php echo e($sel); ?>">
<div class="formgrid" style="grid-template-columns:1fr 1fr"><div><label class="f">Date</label><input class="f" type="date" name="stmt_date" value="<?php echo e(today()); ?>"></div>
<div><label class="f">Amount (negative = withdrawal)</label><input class="f" name="amount" inputmode="numeric" placeholder="e.g. 150000 or -20000"></div></div>
<label class="f">Description</label><input class="f" name="desc" placeholder="bank line as printed on statement">
<button class="btn primary small" type="submit" style="margin-top:8px">+ Add statement line</button></form></div>
<div class="panel"><h2 style="margin-top:0">Unmatched GL entries for <?php echo e($sel); ?></h2>
<table class="data" style="margin:0"><tr><th>Date</th><th>Memo</th><th class="num">Amount</th><th class="noprint"></th></tr>
<?php $st = db()->prepare("SELECT g.* FROM gl_entries g WHERE g.acc_code=? AND g.id NOT IN (SELECT matched_gl FROM bank_statements WHERE matched_gl>0) ORDER BY g.entry_date DESC LIMIT 25"); $st->execute(array($sel)); $free = $st->fetchAll();
$un1 = count($unmatched) ? $unmatched[0] : null;
foreach ($free as $e): ?>
<tr><td style="white-space:nowrap"><?php echo e(date('d M', strtotime($e['entry_date']))); ?></td><td style="font-size:12px"><?php echo e($e['memo']); ?></td>
<td class="num"><?php echo number_format((float)$e['debit'] - (float)$e['credit'], 0); ?></td>
<td class="noprint"><?php if ($un1): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="stmt_match"><input type="hidden" name="sid" value="<?php echo e($un1['id']); ?>"><input type="hidden" name="glid" value="<?php echo e($e['id']); ?>"><button class="btn small" type="submit">match with top line</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$free): ?><tr><td colspan="4" style="color:#7a94aa">Everything in the GL for this account is already matched.</td></tr><?php endif; ?></table></div></div>
<div class="panel"><h2 style="margin-top:0">Bank statement — <?php echo e($sel); ?></h2>
<table class="data" style="margin:0"><tr><th>Date</th><th>Description</th><th class="num">Amount</th><th>Status</th><th class="noprint"></th></tr>
<?php foreach ($lines as $l): ?>
<tr><td style="white-space:nowrap"><?php echo e(date('d M Y', strtotime($l['stmt_date']))); ?></td><td><?php echo e($l['description']); ?></td>
<td class="num"><?php echo number_format((float)$l['amount'], 0); ?></td>
<td><?php echo intval($l['matched_gl']) ? '<span style="color:#20603a;font-weight:bold">&#10003; matched (gl #' . e($l['matched_gl']) . ')</span>' : '<span style="color:#8a6410">unmatched</span>'; ?></td>
<td class="noprint"><?php if (!intval($l['matched_gl'])): ?><form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="stmt_del"><input type="hidden" name="sid" value="<?php echo e($l['id']); ?>"><button class="btn small" type="submit">&#10007;</button></form><?php else: ?><form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="stmt_unmatch"><input type="hidden" name="sid" value="<?php echo e($l['id']); ?>"><button class="btn small" type="submit">unmatch</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$lines): ?><tr><td colspan="5" style="color:#7a94aa">No statement lines yet — paste your monthly bank lines on the left to reconcile against the ledger.</td></tr><?php endif; ?></table></div>

<?php elseif ($TAB === 'journal'): ?>
<div class="panel" style="max-width:760px"><h2 style="margin-top:0">Manual Journal Entry</h2>
<form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="journal">
<div class="formgrid" style="grid-template-columns:1fr 1fr 1fr">
 <div><label class="f">Date</label><input class="f" type="date" name="entry_date" value="<?php echo e(today()); ?>"></div>
 <div><label class="f">Facility (optional)</label><select class="f" name="facility_id"><option value="0">— none —</option><?php foreach (fac_list() as $fo): ?><option value="<?php echo e($fo['id']); ?>"><?php echo fac_icon($fo['ftype']); ?> <?php echo e($fo['name']); ?></option><?php endforeach; ?></select></div>
 <div><label class="f">Memo</label><input class="f" name="memo" placeholder="e.g. Bank charges Mar"></div>
</div>
<table class="data" id="jrows" style="margin:10px 0"><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th><th></th></tr>
<?php for ($i = 0; $i < 3; $i++): ?>
<tr><td><select class="f" name="acc[]" style="margin:0"><option value="">—</option><?php foreach ($bals as $b): ?><option value="<?php echo e($b['acc_code']); ?>"><?php echo e($b['acc_code']); ?> — <?php echo e($b['acc_name']); ?></option><?php endforeach; ?></select></td>
<td><input class="f" name="debit[]" style="text-align:right;margin:0" inputmode="numeric"></td>
<td><input class="f" name="credit[]" style="text-align:right;margin:0" inputmode="numeric"></td>
<td></td></tr>
<?php endfor; ?></table>
<button class="btn primary" type="submit">Post journal</button> <span style="font-size:11.5px;color:#7a94aa">debits must equal credits</span></form></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
