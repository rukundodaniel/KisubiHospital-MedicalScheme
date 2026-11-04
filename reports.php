<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(array('Super Admin', 'Administrator', 'Finance'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$months = month_keys(12);
$from = $months[0] . '-01'; $to = date('Y-m-t');

$depM = monthly_sums('deposits', 'deposit_date', $months);
$wdM  = monthly_sums('withdrawals', 'withdraw_date', $months, "status='Paid'");
$billM = monthly_sums('medical_bills', 'bill_date', $months, "status='Paid'");
$regM = monthly_counts('members', 'registration_date', $months);
$depCountM = monthly_counts('deposits', 'deposit_date', $months);

// cumulative balance + net
$labels = array(); $income = array(); $expense = array(); $netA = array(); $cum = array(); $regA = array();
$running = 0;
foreach ($months as $m) {
    $in = $depM[$m]; $out = $wdM[$m] + $billM[$m]; $net = $in - $out; $running += $net;
    $labels[] = mlabel($m); $income[] = round($in); $expense[] = round($out); $netA[] = round($net); $cum[] = round($running); $regA[] = intval($regM[$m]);
}

// monthly summary table rows
$trows = array(); $prevNet = null;
foreach ($months as $m) {
    $in = $depM[$m]; $out = $wdM[$m] + $billM[$m]; $net = $in - $out;
    $growth = ($prevNet !== null && $prevNet != 0) ? round((($net - $prevNet) / abs($prevNet)) * 100, 1) : null;
    $trows[] = array('m' => mlabel($m), 'reg' => intval($regM[$m]), 'dc' => intval($depCountM[$m]), 'in' => $in, 'out' => $out, 'net' => $net, 'g' => $growth);
    $prevNet = $net;
}

// member statistics
$totM = intval(db()->query('SELECT COUNT(*) FROM members')->fetchColumn());
$actM = intval(db()->query("SELECT COUNT(*) FROM members WHERE status='Active'")->fetchColumn());
$inactM = $totM - $actM;
$maleM = intval(db()->query("SELECT COUNT(*) FROM members WHERE gender='Male'")->fetchColumn());

// top members by deposits
$top = db()->query("SELECT m.member_no, m.full_names, COALESCE(SUM(d.amount),0) a
                   FROM members m LEFT JOIN deposits d ON d.member_id=m.id
                   GROUP BY m.id, m.member_no, m.full_names ORDER BY a DESC LIMIT 10")->fetchAll();
$topLabels = array(); $topData = array();
foreach ($top as $r) { $topLabels[] = $r['member_no']; $topData[] = round($r['a']); }

// avg ticket + activity rate
$allDepCount = intval(db()->query('SELECT COUNT(*) FROM deposits')->fetchColumn());
$allDepSum = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM deposits')->fetchColumn();
$avgDep = $allDepCount > 0 ? $allDepSum / $allDepCount : 0;
$activePayers = intval(db()->query("SELECT COUNT(DISTINCT member_id) FROM deposits WHERE deposit_date >= '" . date('Y-m-d', strtotime('-90 days')) . "'")->fetchColumn());
$engRate = $actM > 0 ? round($activePayers / $actM * 100, 1) : 0;

// claims stats
$cl = db()->query("SELECT status, COUNT(*) c, COALESCE(SUM(amount),0) a FROM withdrawals GROUP BY status")->fetchAll();
$clMap = array(); foreach ($cl as $r) $clMap[$r['status']] = $r;
$drv = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
$daydiff = $drv === 'mysql' ? 'DATEDIFF(paid_date, withdraw_date)' : '(JULIANDAY(paid_date) - JULIANDAY(withdraw_date))';
$avgApproval = db()->query("SELECT AVG($daydiff) FROM withdrawals WHERE status='Paid' AND paid_date IS NOT NULL AND paid_date != ''")->fetchColumn();
if ($avgApproval === null) { try { $avgApproval = 0; } catch (Exception $e) { $avgApproval = 0; } }

$pg_title = 'Reports & Analytics'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head">
  <div><h1>📈 Advanced Reports &amp; Analytics</h1><span class="sub">Rolling 12-month intelligence · generated <?php echo date('d M Y H:i'); ?></span></div>
  <div class="noprint">
    <a class="btn plain" href="#" onclick="window.print();return false">🖨 Print</a>
    <a class="btn plain" href="finance.php">Financial Centre</a>
  </div>
</div>

<div class="grid grid-4" style="margin-bottom:14px">
  <div class="kpi"><div class="k-label">Members (active)</div><div class="k-value"><?php echo num($actM); ?> <span style="font-size:12px;color:#7a94aa">/ <?php echo num($totM); ?></span></div><div class="k-sub"><?php echo $maleM; ?> male · <?php echo max(0,$totM-$maleM); ?> female · <?php echo num($inactM); ?> inactive</div></div>
  <div class="kpi green"><div class="k-label">Average deposit</div><div class="k-value"><?php echo ugx($avgDep); ?></div><div class="k-sub"><?php echo num($allDepCount); ?> deposits · all-time <?php echo ugx($allDepSum); ?></div></div>
  <div class="kpi teal"><div class="k-label">90-day engagement</div><div class="k-value"><?php echo $engRate; ?>%</div><div class="k-sub"><?php echo num($activePayers); ?> of <?php echo num($actM); ?> active members deposited</div></div>
  <div class="kpi gold"><div class="k-label">Avg claim turnaround</div><div class="k-value"><?php echo $avgApproval ? number_format((float)$avgApproval, 1) : '—'; ?> days</div><div class="k-sub">Request → payment (withdrawals)</div></div>
</div>

<div class="grid grid-2">
  <div class="panel"><h2>Income vs Expenditure with cumulative balance</h2><div class="chart-wrap"><canvas id="cashflow"></canvas></div></div>
  <div class="panel"><h2>Balance trajectory &amp; monthly net</h2><div class="chart-wrap"><canvas id="cumulative"></canvas></div></div>
</div>

<div class="grid grid-2">
  <div class="panel"><h2>Top 10 members by deposits</h2><div class="chart-wrap small"><canvas id="topmembers"></canvas></div></div>
  <div class="panel"><h2>Withdrawal claims pipeline</h2><div class="twrap" style="border:0"><table class="data">
    <tr><th>Status</th><th class="num">Count</th><th class="num">Amount</th><th>Progress</th></tr>
    <?php $totW = max(1, intval($clMap['Pending']['c'] ?? 0) + intval($clMap['Approved']['c'] ?? 0) + intval($clMap['Paid']['c'] ?? 0) + intval($clMap['Rejected']['c'] ?? 0)); ?>
    <?php foreach (array('Pending'=>'amber','Approved'=>'blue','Paid'=>'green','Rejected'=>'red') as $s => $col): $c = intval($clMap[$s]['c'] ?? 0); $a = floatval($clMap[$s]['a'] ?? 0); $pct = round($c / $totW * 100); ?>
    <tr><td><span class="badge <?php echo $col; ?>"><?php echo $s; ?></span></td><td class="num"><?php echo num($c); ?></td><td class="num"><?php echo ugx($a); ?></td>
    <td><div style="background:#E3EFFA;height:12px;width:140px;border-radius:3px;display:inline-block;vertical-align:middle"><div style="background:#1B6BB3;height:12px;width:<?php echo $pct; ?>%;border-radius:3px"></div></div> <?php echo $pct; ?>%</td></tr>
    <?php endforeach; ?>
  </table></div></div>
</div>

<div class="panel"><h2>Monthly statistics — 12-month table</h2>
<div class="twrap" style="border:0"><table class="data">
<tr><th>Month</th><th class="num">New members</th><th class="num">Deposits (n)</th><th class="num">Income</th><th class="num">Expenditure</th><th class="num">Net</th><th class="num">Net change</th></tr>
<?php foreach ($trows as $r): ?>
<tr><td><b><?php echo e($r['m']); ?></b></td><td class="num"><?php echo num($r['reg']); ?></td><td class="num"><?php echo num($r['dc']); ?></td>
<td class="num"><?php echo ugx($r['in']); ?></td><td class="num"><?php echo ugx($r['out']); ?></td>
<td class="num" style="color:<?php echo $r['net']<0?'#8c2424':'#20603a'; ?>;font-weight:bold"><?php echo ugx($r['net']); ?></td>
<td class="num"><?php if ($r['g'] === null) echo '—'; else echo ($r['g']>=0?'▲ ':'▼ ') . abs($r['g']) . '%'; ?></td></tr>
<?php endforeach; ?>
</table></div>
<div class="table-footnote">Income = all deposits · Expenditure = paid withdrawals + paid medical bills · Net change compares month-on-month movement.</div>
</div>

<script>
var MS_LABELS = <?php echo json_encode($labels); ?>;
var MS_DEP    = <?php echo json_encode($income); ?>;
var MS_WD     = <?php echo json_encode($expense); ?>;
var MS_BILL   = <?php echo json_encode(array_fill(0, count($months), 0)); ?>;
var MS_REG    = <?php echo json_encode($regA); ?>;
var MS_NET    = <?php echo json_encode($netA); ?>;
var MS_CUM    = <?php echo json_encode($cum); ?>;
var MS_TOP    = { labels: <?php echo json_encode($topLabels); ?>, data: <?php echo json_encode($topData); ?> };
</script>
<div class="panel"><h2 style="margin-top:0">&#128202; Full financial statements (v4.0 accounting suite)</h2>
<p style="margin:0 0 8px;font-size:13px;color:#5b7c99">The Report Centre now links into the double-entry ledger — every statement below is generated live from the general ledger:</p>
<p style="margin:0"><a class="btn" href="accounting.php?tab=tb">Trial Balance</a>
<a class="btn" href="accounting.php?tab=ie">Income &amp; Expenditure</a>
<a class="btn" href="accounting.php?tab=bs">Balance Sheet</a>
<a class="btn" href="accounting.php?tab=cf">Cash Flow Statement</a>
<a class="btn" href="accounting.php?tab=bank">Bank Reconciliation</a>
<a class="btn" href="accounting.php?tab=gl">General Ledger</a>
<a class="btn" href="accounting.php?tab=coa">Chart of Accounts</a>
<a class="btn" href="accounting.php">Ledger overview &raquo;</a></p></div>
<div class="panel" style="margin-top:14px"><h2 style="margin-top:0">&#9878;&#65039; Facility claims &amp; payments</h2>
<p style="font-size:13px;margin:4px 0 10px">Cross-facility claims analytics live with the workflow — filters, per-facility totals, outstanding balances and CSV export for Excel/PDF printing.</p>
<a class="btn" href="facility-claims.php">Owner claims desk</a>
<a class="btn" href="facility-claims.php?export=1">&nbsp;</a>
<a class="btn plain" href="facility-claims.php?export=csv">&#128229; Export claims CSV (filtered)</a>
<a class="btn plain" href="disbursements-manager.php">&#128181; Disbursement manager &amp; vouchers</a>
<a class="btn plain" href="facility-desk.php">&#128740; Facility deposit desks</a></div>
<script src="assets/js/charts.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
