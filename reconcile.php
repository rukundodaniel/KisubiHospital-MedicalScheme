<?php
/** v5.0 FINANCIAL RECONCILIATION (spec §12): Ledger A (institution deposits), B (insurance
 *  claims), C (beneficiary bills), D (central settlement). Compares module totals against the
 *  GL and flags variances + double-payment checks. Snapshots are stored, never overwritten. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/wallet-core.php';
require_once __DIR__ . '/includes/acc.php';
require_role(array('Super Admin', 'Administrator', 'Finance'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9
if (!wa_require_tbl()) { include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/footer.php'; exit; }
$from = get('from') !== '' ? get('from') : date('Y-01-01');
$to   = get('to') !== '' ? get('to') : today();

/* Ledger A — institution wallets */
$A = db()->query("SELECT COALESCE(SUM(CASE WHEN direction='Credit' THEN amount END),0) cr, COALESCE(SUM(CASE WHEN direction='Debit' THEN amount END),0) dr FROM institution_ledger WHERE entry_date BETWEEN '$from' AND '$to'")->fetch();
$Asum = db()->query('SELECT COALESCE(SUM(balance),0) s FROM institution_accounts')->fetchColumn();
/* Ledger B — insurance claims */
$B = db()->query("SELECT COALESCE(SUM(amount_billed),0) billed, COALESCE(SUM(CASE WHEN status IN ('Approved','Cleared','PartPaid','Paid','Partially Approved') THEN COALESCE(amount_approved, amount_billed) END),0) approved FROM facility_claims WHERE created_at BETWEEN '$from' AND '$to 23:59:59'")->fetch();
$Bpay = db()->query("SELECT COALESCE(SUM(amount),0) paid FROM disbursements WHERE reversed=0 AND paid_at BETWEEN '$from' AND '$to 23:59:59'")->fetchColumn();
/* Ledger C — beneficiary bills */
$C = db()->query("SELECT COUNT(*) n, COALESCE(SUM(total_bill),0) total, COALESCE(SUM(member_copay),0) copay FROM bill_settlements WHERE bill_date BETWEEN '$from' AND '$to' AND status NOT IN ('Reversed')")->fetch();
/* Ledger D — central settlements (member-deposit + wallet + insurer combined) */
$Dw = db()->query("SELECT COALESCE(SUM(wallet_paid),0) s FROM bill_settlements WHERE bill_date BETWEEN '$from' AND '$to' AND status NOT IN ('Reversed')")->fetchColumn();
$Dm = db()->query("SELECT COALESCE(SUM(amount),0) s FROM withdrawals WHERE status='Paid' AND withdraw_date BETWEEN '$from' AND '$to' AND auth_method='FACILITY-DESK'")->fetchColumn();
/* GL comparisons */
$gl2200 = 0.0; $gl2100 = 0.0;
foreach (acc_balances('', '') as $r) { if ($r['acc_code'] === '2200') $gl2200 = (float)$r['net']; if ($r['acc_code'] === '2100') $gl2100 = (float)$r['net']; }
$varWallet = round($Asum - $gl2200, 2);
$varClaims = round($gl2100 - ($Dw + max(0, (float)$B['approved'] - (float)$Bpay) + $Dm), 2);
/* double-payment check: any facility_txn counted twice (wallet-settled but also claimed) */
$dbl = array();
try { $dbl = db()->query("SELECT t.id, t.amount FROM facility_txns t JOIN facility_claims c ON c.id=t.claim_id WHERE t.settled=1 AND t.status='Claimed' AND c.status NOT IN ('Rejected') LIMIT 20")->fetchAll(); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('op') === 'snap') {
    csrf_check();
    $var = $varWallet;
    db()->prepare('INSERT INTO recon_snapshots (period_from, period_to, wallet_credits, wallet_debits, wallet_net, claims_billed, claims_approved, claims_paid, claims_liability, gl_wallet_bal, gl_claim_bal, variance, status, note, checked_by, checked_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute(array($from, $to, (float)$A['cr'], (float)$A['dr'], round((float)$A['cr'] - (float)$A['dr'], 2), (float)$B['billed'], (float)$B['approved'], (float)$Bpay,
           round((float)$B['approved'] - (float)$Bpay, 2), $gl2200, $gl2100, $var, (abs($var) < 0.5 && abs($varClaims) < 0.5 && !$dbl) ? 'OK' : 'Investigate',
           mb_substr(post('note'), 0, 250), $_SESSION['uname'], date('Y-m-d H:i:s')));
    log_action('Reconciliation snapshot saved ' . $from . '→' . $to, 'recon_snapshots', intval(db()->lastInsertId()));
    flash('Snapshot saved — status ' . (abs($var) < 0.5 && !$dbl ? 'OK' : 'INVESTIGATE') . '.', 'ok');
    redirect('reconcile.php?from=' . $from . '&to=' . $to);
}
if (get('a') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="recon-' . $from . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, array('Ledger', 'Metric', 'Amount UGX'));
    fputcsv($o, array('A Institutions', 'Verified credits (period)', $A['cr'])); fputcsv($o, array('A Institutions', 'Settlements/debits (period)', $A['dr']));
    fputcsv($o, array('A Institutions', 'Institution float (retired) all-time', $Asum)); fputcsv($o, array('A Institutions', 'GL 2200 net', $gl2200));
    fputcsv($o, array('B Claims', 'Billed', $B['billed'])); fputcsv($o, array('B Claims', 'Approved', $B['approved'])); fputcsv($o, array('B Claims', 'Paid out', $Bpay));
    fputcsv($o, array('C Beneficiaries', 'Bills', $C['n'])); fputcsv($o, array('C Beneficiaries', 'Total billed', $C['total'])); fputcsv($o, array('C Beneficiaries', 'Beneficiary outstanding', $C['copay']));
    fputcsv($o, array('D Central', 'Wallet-funded', $Dw)); fputcsv($o, array('D Central', 'Member-deposit funded', $Dm)); fputcsv($o, array('D Central', 'GL 2100 payable', $gl2100));
    fclose($o); exit;
}
$snap = db()->query('SELECT * FROM recon_snapshots ORDER BY id DESC LIMIT 10')->fetchAll();
$page_title = 'Reconciliation';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>&#9878; Financial Reconciliation — deposits vs claims</h1>
 <span class="sub">Four separate ledgers, reconciled. Every funding source is identified; no service is paid twice.</span></div>
<form method="get" style="display:flex;gap:8px;align-items:flex-end;margin:8px 0">
 <div><label class="f">From</label><input class="f" type="date" name="from" value="<?php echo e($from); ?>" style="margin:0"></div>
 <div><label class="f">To</label><input class="f" type="date" name="to" value="<?php echo e($to); ?>" style="margin:0"></div>
 <button class="btn small primary" type="submit">Run</button>
 <a class="btn small plain" href="reconcile.php?from=<?php echo e($from); ?>&to=<?php echo e($to); ?>&a=csv">&#128190; Export CSV</a>
</form>

<div style="display:flex;gap:12px;flex-wrap:wrap">
<div class="panel" style="flex:1;min-width:300px"><h3 style="margin:0 0 6px">A &middot; Institutional deposit ledger</h3>
 <table class="data" style="margin:0"><tr><td>Verified credits (period)</td><td style="text-align:right"><?php echo num($A['cr']); ?></td></tr>
 <tr><td>Settlements &amp; reversals (period)</td><td style="text-align:right"><?php echo num($A['dr']); ?></td></tr>
 <tr><td>Wallet balances held (all-time)</td><td style="text-align:right"><b><?php echo num($Asum); ?></b></td></tr>
 <tr><td>GL account 2200 net</td><td style="text-align:right"><?php echo num($gl2200); ?></td></tr>
 <tr><td><b>Variance</b></td><td style="text-align:right"><b style="color:<?php echo abs($varWallet) < 0.5 ? '#20603a' : '#8c2424'; ?>"><?php echo num($varWallet); ?></b></td></tr></table></div>
<div class="panel" style="flex:1;min-width:300px"><h3 style="margin:0 0 6px">B &middot; Insurance claims ledger</h3>
 <table class="data" style="margin:0"><tr><td>Claims submitted</td><td style="text-align:right"><?php echo num($B['billed']); ?></td></tr>
 <tr><td>Approved by payer</td><td style="text-align:right"><?php echo num($B['approved']); ?></td></tr>
 <tr><td>Disbursed to institutions</td><td style="text-align:right"><?php echo num($Bpay); ?></td></tr>
 <tr><td><b>Outstanding insurance liability</b></td><td style="text-align:right"><b><?php echo num(max(0, (float)$B['approved'] - (float)$Bpay)); ?></b></td></tr></table></div>
<div class="panel" style="flex:1;min-width:300px"><h3 style="margin:0 0 6px">C &middot; Beneficiary ledger</h3>
 <table class="data" style="margin:0"><tr><td>Bills settled (period)</td><td style="text-align:right"><?php echo num(intval($C['n'])); ?></td></tr>
 <tr><td>Total billed</td><td style="text-align:right"><?php echo num($C['total']); ?></td></tr>
 <tr><td>Beneficiary outstanding (copay)</td><td style="text-align:right"><?php echo num($C['copay']); ?></td></tr>
 <tr><td>Funded from wallets / insurance</td><td style="text-align:right"><?php echo num($Dw); ?> / <?php echo num((float)$C['total'] - (float)$C['copay'] - (float)$Dw); ?></td></tr></table></div>
<div class="panel" style="flex:1;min-width:300px"><h3 style="margin:0 0 6px">D &middot; Central settlement ledger</h3>
 <table class="data" style="margin:0"><tr><td>Payables to facilities (GL 2100)</td><td style="text-align:right"><?php echo num($gl2100); ?></td></tr>
 <tr><td>Expected: unsettled claims + wallet + desk</td><td style="text-align:right"><?php echo num((float)$Dw + max(0, (float)$B['approved'] - (float)$Bpay) + (float)$Dm); ?></td></tr>
 <tr><td><b>Variance</b></td><td style="text-align:right"><b style="color:<?php echo abs($varClaims) < 0.5 ? '#20603a' : '#8a6410'; ?>"><?php echo num($varClaims); ?></b></td></tr>
 <tr><td>Double-payment exceptions</td><td style="text-align:right"><b style="color:<?php echo $dbl ? '#8c2424' : '#20603a'; ?>"><?php echo count($dbl); ?></b></td></tr></table></div>
</div>

<?php if ($dbl): ?><div class="panel" style="border:2px solid #8c2424;background:#FDECEC"><h3 style="margin:0 0 6px">&#9888; Services counted twice</h3>
 <ul style="margin:0;padding-left:20px"><?php foreach ($dbl as $d): ?><li style="font-size:12.5px">txn #<?php echo intval($d['id']); ?> (<?php echo ugx($d['amount']); ?>) was wallet-settled AND included in an active claim — reject or adjust the claim line.</li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="panel"><div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
 <div style="flex:1"><h3 style="margin:0">Snapshot history</h3></div>
 <form method="post" style="display:flex;gap:8px;align-items:flex-end"><?php echo csrf_field(); ?><input type="hidden" name="op" value="snap">
  <div><label class="f">Note</label><input class="f" name="note" style="margin:0" placeholder="review remarks"></div>
  <button class="btn small primary" type="submit">&#128220; Save current period</button></form></div>
<table class="data" style="margin:8px 0 0"><tr><th>Period</th><th>Wallet net</th><th>Claims liability</th><th>GL 2200 / 2100</th><th>Variance</th><th>Status</th><th>By</th></tr>
<?php foreach ($snap as $r): ?>
<tr><td><?php echo e($r['period_from']); ?> → <?php echo e($r['period_to']); ?></td><td><?php echo num($r['wallet_net']); ?></td><td><?php echo num($r['claims_liability']); ?></td>
 <td><?php echo num($r['gl_wallet_bal']); ?> / <?php echo num($r['gl_claim_bal']); ?></td>
 <td><?php echo num($r['variance']); ?></td>
 <td><b style="color:<?php echo $r['status'] === 'OK' ? '#20603a' : '#8c2424'; ?>"><?php echo e($r['status']); ?></b></td><td style="font-size:11px"><?php echo e($r['checked_by']); ?> · <?php echo e(substr((string)$r['checked_at'], 0, 16)); ?></td></tr>
<?php endforeach; if (!$snap) echo '<tr><td colspan="7" style="color:#7a94aa">No snapshots yet.</td></tr>'; ?>
</table></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
