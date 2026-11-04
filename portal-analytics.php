<?php
/**
 * PORTAL: MY ANALYTICS — deposit statistics + charts (monthly rhythm, cumulative growth,
 * channel mix) and savings in vs out, all from the member's own ledger.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_auth.php';
$m = portal_require();

$st = db()->prepare('SELECT deposit_date, amount, payment_method, note FROM deposits WHERE member_id=? ORDER BY deposit_date, id');
$st->execute(array($m['id'])); $deposits = $st->fetchAll();

// last 12 month buckets
$months = array(); $labels = array();
$__anchor = date('Y-m-01');   // day-1 anchor avoids month-end overflow (30 Oct - 8m = 30 Feb -> 2 Mar)
for ($k = 11; $k >= 0; $k--) {
    $key = date('Y-m', strtotime($__anchor . " -$k months"));
    $months[$key] = array('dep' => 0.0, 'cnt' => 0, 'out' => 0.0);
    $labels[] = date('M Y', strtotime($key . '-01'));
}
$chans = array(); $cum = 0.0; $best = array('m' => '', 'v' => 0.0); $largest = 0.0; $lifeIn = 0.0;
foreach ($deposits as $d) {
    $key = substr($d['deposit_date'], 0, 7); $amt = (float)$d['amount']; $lifeIn += $amt;
    if ($amt > $largest) $largest = $amt;
    if (!isset($months[$key])) continue;
    $months[$key]['dep'] += $amt; $months[$key]['cnt']++;
    $cn = preg_replace('/\s*\(.*$/', '', (string)$d['payment_method']); if ($cn === '') $cn = 'Other';
    $chans[$cn] = (isset($chans[$cn]) ? $chans[$cn] : 0) + $amt;
}
// paid out per month (settlements + payouts)
$st = db()->prepare("SELECT withdraw_date, amount FROM withdrawals WHERE member_id=? AND status='Paid'");
$st->execute(array($m['id']));
$lifeOut = 0.0;
foreach ($st->fetchAll() as $w) {
    $key = substr($w['withdraw_date'], 0, 7); $lifeOut += (float)$w['amount'];
    if (isset($months[$key])) $months[$key]['out'] += (float)$w['amount'];
}
$seriesDep = array(); $seriesCum = array(); $seriesOut = array(); $activeMonths = 0;
$run = 0.0; $firstMonth = null;
foreach ($months as $key => $v) {
    $run += $v['dep'];
    $seriesDep[] = round($v['dep']); $seriesOut[] = round($v['out']); $seriesCum[] = round($run);
    if ($v['cnt'] > 0) { $activeMonths++; if ($v['dep'] > $best['v']) $best = array('m' => date('M Y', strtotime($key . '-01')), 'v' => $v['dep']); if ($firstMonth === null) $firstMonth = $key; }
}
$avgActive = $activeMonths ? round($lifeIn / $activeMonths) : 0;
$thisMonth = $months[date('Y-m')];
$bal = member_balance($m['id']);

$p_title = 'My Analytics';
include __DIR__ . '/includes/portal_header.php';
?>
<div class="page-head"><div><h1 style="margin:0;font:bold 21px Georgia,serif;color:#12476f">Deposit Statistics &amp; Analytics</h1>
<span style="font:12px Arial;color:#5b7c99">Your savings rhythm over the last 12 months</span></div></div>

<div class="grid grid-3" style="margin:12px 0">
  <div class="kpi green"><div class="k-label">Lifetime deposits</div><div class="k-value"><?php echo num($lifeIn); ?></div><div class="k-sub">UGX &middot; <?php echo count($deposits); ?> entries</div></div>
  <div class="kpi"><div class="k-label">Current balance</div><div class="k-value"><?php echo num($bal); ?></div><div class="k-sub">after UGX <?php echo num($lifeOut); ?> settled out</div></div>
  <div class="kpi"><div class="k-label">Average / active month</div><div class="k-value"><?php echo num($avgActive); ?></div><div class="k-sub"><?php echo $activeMonths; ?> active month(s)</div></div>
</div>
<div class="grid grid-3" style="margin:0 0 12px">
  <div class="kpi gold"><div class="k-label">This month</div><div class="k-value"><?php echo num($thisMonth['dep']); ?></div><div class="k-sub"><?php echo $thisMonth['cnt']; ?> deposit(s)</div></div>
  <div class="kpi"><div class="k-label">Best month</div><div class="k-value" style="font-size:17px"><?php echo $best['m'] !== '' ? num($best['v']) . ' <small style="font:11px Arial">' . e($best['m']) . '</small>' : '—'; ?></div><div class="k-sub">highest monthly total</div></div>
  <div class="kpi red"><div class="k-label">Largest single deposit</div><div class="k-value"><?php echo num($largest); ?></div><div class="k-sub">UGX</div></div>
</div>

<div class="grid grid-2">
  <div class="panel"><h2>Monthly deposits vs payouts</h2>
    <canvas id="barC" height="150"></canvas>
    <p style="font-size:11.5px;color:#7a94aa;margin:8px 0 0">Green = money in (deposits) &middot; red = money out (bill clearings &amp; payouts) &middot; blue line = cumulative deposited.</p></div>
  <div class="panel"><h2>How you pay in</h2>
    <?php if ($chans): ?><canvas id="pieC" height="190"></canvas>
    <div style="margin-top:8px"><?php foreach ($chans as $cn => $cv): ?><span class="badge blue" style="margin-right:6px"><?php echo e($cn); ?>: <?php echo num($cv); ?></span><?php endforeach; ?></div>
    <?php else: ?><p style="color:#5b7c99">No deposits in the last 12 months yet.</p><?php endif; ?></div>
</div>

<div class="panel"><h2>Month by month</h2>
<div class="twrap" style="border:0"><table class="data">
<tr><th>Month</th><th class="num">Deposits #</th><th class="num">In (UGX)</th><th class="num">Out (UGX)</th><th class="num">Net</th><th>Progress</th></tr>
<?php foreach ($months as $key => $v): $net = $v['dep'] - $v['out']; $w = $largest > 0 ? min(100, round($v['dep'] / max(1, $best['v']) * 100)) : 0; ?>
<tr><td><?php echo e(date('M Y', strtotime($key . '-01'))); ?></td>
<td class="num"><?php echo $v['cnt']; ?></td>
<td class="num" style="color:#20603a"><b><?php echo num($v['dep']); ?></b></td>
<td class="num" style="color:#a03028"><?php echo $v['out'] > 0 ? num($v['out']) : '—'; ?></td>
<td class="num"><b><?php echo num($net); ?></b></td>
<td><div style="background:#E3EFFA;height:9px;border-radius:5px;min-width:120px"><div style="background:linear-gradient(90deg,#1B6BB3,#20603a);height:9px;border-radius:5px;width:<?php echo $w; ?>%"></div></div></td></tr>
<?php endforeach; ?>
</table></div>
<p style="color:#5b7c99;font-size:12px;margin-top:10px">First deposit on record: <?php echo $firstMonth ? e(date('d M Y', strtotime($firstMonth . '-01'))) : '—'; ?> &middot; <a href="portal-statement.php">Full statement</a> &middot; <a href="portal-settlements.php">Bill clearings</a> &middot; <a href="portal.php">Deposit money &raquo;</a></p>
</div>

<script>
var L=<?php echo json_encode($labels); ?>, D=<?php echo json_encode($seriesDep); ?>, O=<?php echo json_encode($seriesOut); ?>, C=<?php echo json_encode($seriesCum); ?>;
new Chart(document.getElementById('barC'), {data:{labels:L, datasets:[
 {type:'bar', label:'Deposited', data:D, backgroundColor:'#3f9e5f', borderRadius:4},
 {type:'bar', label:'Settled out', data:O, backgroundColor:'#d98a80', borderRadius:4},
 {type:'line', label:'Cumulative in', data:C, borderColor:'#1B6BB3', backgroundColor:'rgba(27,107,179,.08)', fill:true, tension:.35, pointRadius:2, yAxisID:'y1'}
]}, options:{plugins:{legend:{labels:{font:{size:11}}}}, scales:{y:{ticks:{callback:function(v){return v>=1000000?(v/1000000)+'M':(v/1000)+'k';}}}, y1:{display:false}}}});
<?php if ($chans): ?>
new Chart(document.getElementById('pieC'), {type:'doughnut', data:{labels:<?php echo json_encode(array_keys($chans)); ?>, datasets:[{data:<?php echo json_encode(array_values($chans)); ?>, backgroundColor:['#1B6BB3','#3f9e5f','#c89211','#7a5cc4','#d98a80','#2aa8b8'], borderWidth:1}]}, options:{plugins:{legend:{position:'right', labels:{font:{size:11}}}}}});
<?php endif; ?>
</script>
<?php include __DIR__ . '/includes/portal_footer.php'; ?>
