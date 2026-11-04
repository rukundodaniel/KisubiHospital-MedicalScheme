<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_login();
if (function_exists('fac_scope_fid') && fac_scope_fid() > 0 && function_exists('fac_is_owner') && !fac_is_owner()) { redirect('facility-dashboard.php'); }

$months = month_keys(12);
$thisMonth = date('Y-m'); $thisFrom = date('Y-m-01'); $thisTo = date('Y-m-t');

// KPI numbers
$totalMembers   = intval(db()->query('SELECT COUNT(*) FROM members')->fetchColumn());
$activeMembers  = intval(db()->query("SELECT COUNT(*) FROM members WHERE status='Active'")->fetchColumn());
$newThisMonth   = intval(db()->query("SELECT COUNT(*) FROM members WHERE registration_date BETWEEN '$thisFrom' AND '$thisTo'")->fetchColumn());
$totalDeposits  = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM deposits')->fetchColumn();
$depThisMonth   = sum_between('deposits', 'deposit_date', $thisFrom, $thisTo);
$totalPaidOut   = sum_between('withdrawals', 'withdraw_date', '1900-01-01', '2099-12-31', "status='Paid'")
                + sum_between('medical_bills', 'bill_date', '1900-01-01', '2099-12-31', "status='Paid'");
$balance        = $totalDeposits - $totalPaidOut;
$pendW          = db()->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a FROM withdrawals WHERE status IN ('Pending','Approved')")->fetch();
$pendB          = db()->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a FROM medical_bills WHERE status='Pending'")->fetch();
$smsToday       = intval(db()->query("SELECT COUNT(*) FROM sms_log WHERE created_at LIKE '" . date('Y-m-d') . "%'")->fetchColumn());

// charts
$depM = monthly_sums('deposits', 'deposit_date', $months);
$wdM  = array(); $billM = array();
foreach (monthly_sums('withdrawals', 'withdraw_date', $months) as $k => $v) { $wdM[$k] = $v; }
foreach (monthly_sums('medical_bills', 'bill_date', $months) as $k => $v) { $billM[$k] = $v; }
$regM = monthly_counts('members', 'registration_date', $months);

$labels = array(); $d1 = array(); $d2 = array(); $d3 = array(); $d4 = array();
foreach ($months as $m) {
    $labels[] = mlabel($m); $d1[] = round($depM[$m]); $d2[] = round($wdM[$m]); $d3[] = round($billM[$m]); $d4[] = intval($regM[$m]);
}

// recent activity
$recentDep = db()->query("SELECT d.*, m.full_names, m.member_no FROM deposits d JOIN members m ON m.id=d.member_id ORDER BY d.id DESC LIMIT 6")->fetchAll();
$recentLog = db()->query("SELECT * FROM activity_log ORDER BY id DESC LIMIT 8")->fetchAll();

$pg_title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Operations Dashboard</h1>
    <span class="sub">Welcome back, <b><?php echo e($_SESSION['fname']); ?></b> &mdash; live summary of the scheme as at <?php echo date('d M Y, H:i'); ?></span>
  </div>
  <div class="noprint">
    <a class="btn plain" href="finance.php">Finance Centre</a>
    <a class="btn primary" href="reports.php">Reports &amp; Analytics</a>
  </div>
</div>

<div class="panel noprint" style="margin:10px 0;padding:12px 14px"><b style="font-size:12px;color:#5b7c99;letter-spacing:.5px">QUICK ACCESS — SUPER ADMINISTRATOR</b>
 <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
  <a class="btn small plain" href="facilities.php">&#127973; All Facilities</a>
  <a class="btn small plain" href="members.php">&#128101; All Members</a>
  <a class="btn small plain" href="claims-central.php">&#128230; Claims Received</a>
  <a class="btn small plain" href="facility-claims.php">&#128202; Claims Review</a>
  <a class="btn small plain" href="claims-central.php#queue">&#9989; Claims Approval</a>
  <a class="btn small plain" href="disbursements-manager.php">&#128181; Claims Disbursement</a>
  <a class="btn small plain" href="claims-disburse.php">&#128197; Payment Scheduling</a>
  <a class="btn small plain" href="disbursements-manager.php">&#129534; Payment Vouchers</a>
  <a class="btn small plain" href="reconcile.php">&#9878; Financial Reconciliation</a>
  <a class="btn small plain" href="claims-reports.php?rep=statement">&#128196; Facility Statements</a>
  <a class="btn small plain" href="settings.php">&#128221; Audit Logs</a>
 </div>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="kpi"><div class="k-label">Total Members</div><div class="k-value"><?php echo num($totalMembers); ?></div><div class="k-sub"><?php echo num($activeMembers); ?> active &middot; +<?php echo num($newThisMonth); ?> this month</div></div>
  <div class="kpi green"><div class="k-label">Scheme Balance</div><div class="k-value"><?php echo ugx($balance); ?></div><div class="k-sub">Deposits &minus; paid out</div></div>
  <div class="kpi teal"><div class="k-label">Deposits This Month</div><div class="k-value"><?php echo ugx($depThisMonth); ?></div><div class="k-sub">All-time: <?php echo ugx($totalDeposits); ?></div></div>
  <div class="kpi gold"><div class="k-label">Pending Claims</div><div class="k-value"><?php echo num($pendW['c'] + $pendB['c']); ?></div><div class="k-sub">Worth <?php echo ugx($pendW['a'] + $pendB['a']); ?></div></div>
</div>

<div class="grid grid-2">
  <div class="panel">
    <h2>Cash Flow &mdash; last 12 months (UGX)</h2>
    <div class="chart-wrap"><canvas id="cashflow"></canvas></div>
  </div>
  <div class="panel">
    <h2>Member Registrations &mdash; last 12 months</h2>
    <div class="chart-wrap"><canvas id="regs"></canvas></div>
  </div>
</div>

<div class="grid grid-2">
  <div class="panel">
    <h2>Latest Deposits</h2>
    <div class="twrap" style="border:0">
      <table class="data">
        <tr><th>Date</th><th>Member</th><th class="num">Amount</th><th>Method</th></tr>
        <?php if (!$recentDep): ?><tr><td colspan="4" style="color:#5b7c99">No deposits recorded yet.</td></tr><?php endif; ?>
        <?php foreach ($recentDep as $r): ?>
        <tr>
          <td><?php echo e(date('d M Y', strtotime($r['deposit_date']))); ?></td>
          <td><?php echo e($r['full_names']); ?> <span style="color:#7a94aa">(<?php echo e($r['member_no']); ?>)</span></td>
          <td class="num"><b><?php echo ugx($r['amount']); ?></b></td>
          <td><?php echo e($r['payment_method']); ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <div class="panel">
    <h2>System Activity</h2>
    <div class="twrap" style="border:0">
      <table class="data">
        <tr><th>When</th><th>User</th><th>Action</th></tr>
        <?php if (!$recentLog): ?><tr><td colspan="3" style="color:#5b7c99">Nothing logged yet.</td></tr><?php endif; ?>
        <?php foreach ($recentLog as $r): ?>
        <tr>
          <td style="white-space:nowrap"><?php echo e(date('d M H:i', strtotime($r['created_at']))); ?></td>
          <td><?php echo e($r['username']); ?></td>
          <td><?php echo e($r['action']); ?><?php if ($r['record_table']) echo ' <span class="badge grey">' . e($r['record_table']) . ' #' . e($r['record_id']) . '</span>'; ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>

<div class="grid grid-3" style="margin-bottom:6px">
  <div class="kpi red"><div class="k-label">Outstanding Withdrawals</div><div class="k-value"><?php echo ugx($pendW['a']); ?></div><div class="k-sub"><?php echo num($pendW['c']); ?> claims awaiting payment</div></div>
  <div class="kpi red"><div class="k-label">Unpaid Medical Bills</div><div class="k-value"><?php echo ugx($pendB['a']); ?></div><div class="k-sub"><?php echo num($pendB['c']); ?> bills pending approval</div></div>
  <div class="kpi"><div class="k-label">SMS Activity Today</div><div class="k-value"><?php echo num($smsToday); ?></div><div class="k-sub">SmsDual + WhatsApp notifications</div></div>
</div>

<script>
var MS_LABELS = <?php echo json_encode($labels); ?>;
var MS_DEP   = <?php echo json_encode($d1); ?>;
var MS_WD    = <?php echo json_encode($d2); ?>;
var MS_BILL  = <?php echo json_encode($d3); ?>;
var MS_REG   = <?php echo json_encode($d4); ?>;
</script>
<?php if (fac_has_tbl()):
$dashClaims = array(); $dashK = array('pend'=>0,'amt'=>0,'paid'=>0);
try {
    $scoped = fac_scope_fid() > 0 ? ' AND c.facility_id=' . intval(fac_scope_fid()) : '';
    $st = db()->prepare("SELECT c.* FROM facility_claims c WHERE c.status='Pending'$scoped ORDER BY c.id DESC LIMIT 5");
    $st->execute(array()); $dashClaims = $st->fetchAll();
    $k = db()->query("SELECT COUNT(CASE WHEN status='Pending' THEN 1 END) pend, COALESCE(SUM(CASE WHEN status='Pending' THEN amount_billed END),0) amt, COALESCE(SUM(CASE WHEN status='Paid' THEN amount_approved END),0) paid FROM facility_claims")->fetch();
    $dashK = array('pend'=>intval($k['pend']),'amt'=>floatval($k['amt']),'paid'=>floatval($k['paid']));
} catch (Exception $e) {}
?>
<div class="panel" style="border:2px solid <?php echo $dashK['pend'] > 0 ? '#c0392b' : '#1B6BB3'; ?>;box-shadow:0 4px 18px rgba(138,28,28,.10)">
<h2 style="margin-top:0">&#9878;&#65039; Facility Claims &amp; Disbursements — <?php echo fac_is_owner() ? 'Owner desk' : 'Network view'; ?>
 <span style="font:normal 11.5px Arial;color:#5b7c99"><?php echo num($dashK['pend']); ?> awaiting decision (<?php echo ugx($dashK['amt']); ?>) &middot; cleared to date <?php echo ugx($dashK['paid']); ?></span>
 <a class="btn small danger" style="float:right" href="facility-claims.php">Open the desk &raquo;</a></h2>
<?php if (!$dashClaims): ?><p style="color:#20603a;margin:6px 0">&#10003; No facility claims are waiting — every clinic and hospital is clear.</p><?php else: ?>
<table class="data" style="margin:0">
<tr><th>Claim</th><th>From</th><th>Patient</th><th class="num">Billed</th><th>Sent</th><th class="noprint">Owner decision</th></tr>
<?php foreach ($dashClaims as $c): ?>
<tr><td><b><?php echo e($c['claim_no']); ?></b></td>
<td><?php $ff = fac_of(intval($c['facility_id'])); echo $ff ? fac_icon($ff['ftype']) : ''; ?> <b><?php echo e($c['facility_name']); ?></b></td>
<td style="font-size:12px"><?php echo e($c['patient_name'] !== '' ? $c['patient_name'] : '—'); ?></td>
<td class="num"><b><?php echo ugx($c['amount_billed']); ?></b></td>
<td style="font-size:11.5px;color:#5b7c99"><?php echo e($c['submitted_at'] ? date('d M H:i', strtotime($c['submitted_at'])) : '—'); ?></td>
<td class="noprint" style="white-space:nowrap"><?php if (fac_is_owner()): ?>
 <form method="post" action="facility-claims.php" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="approve"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>"><button class="btn small success" type="submit">&#10003; Approve</button></form>
 <form method="post" action="facility-claims.php" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="reject"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>"><input type="hidden" name="reason" value="See the owner desk for details"><button class="btn small danger" type="submit">&#10007;</button></form>
 <?php else: ?><a href="facility-claims.php">view</a><?php endif; ?></td></tr>
<?php endforeach; ?>
</table><?php endif; ?>
</div>
<?php endif; ?>

<?php /* ---- v4.1 network command center ---- */
try { require_once __DIR__ . '/includes/clinical-core.php'; if (fac_has_tbl()): $NET = db()->query("SELECT
    COUNT(CASE WHEN ftype IN ('Hospital') THEN 1 END) hosp,
    COUNT(CASE WHEN ftype IN ('Clinic','Medical Centre','Health Centre IV','Health Centre III') THEN 1 END) clc,
    COUNT(CASE WHEN ftype='Branch' THEN 1 END) br FROM facilities")->fetch();
  $netUsers = 0; try { $netUsers = intval(db()->query('SELECT COUNT(*) FROM users WHERE facility_id>0')->fetchColumn()); } catch (Exception $e) {}
  $netPts = 0; if (cl_tbl('patients')) $netPts = intval(db()->query('SELECT COUNT(*) FROM patients')->fetchColumn());
  $netCl = db()->query("SELECT COUNT(*) sub,
    COUNT(CASE WHEN status IN ('Pending','Submitted','Resubmitted','Under Review') THEN 1 END) pend,
    COALESCE(SUM(CASE WHEN status IN ('Approved','Partially Approved','Cleared','PartPaid') THEN COALESCE(amount_approved, amount_billed) END),0) appr
    FROM facility_claims WHERE status<>'Draft'")->fetch();
  $paidAll = 0.0; try { $paidAll = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM disbursements' . (cl_has('disbursements','reversed') ? ' WHERE reversed=0' : ''))->fetchColumn(); } catch (Exception $e) {}
  $outAll = 0.0;
  if (cl_has('facility_claims','amount_paid')) { $outAll = (float)db()->query("SELECT COALESCE(SUM(CASE WHEN status IN ('Cleared','PartPaid','Approved','Partially Approved') THEN COALESCE(amount_approved, amount_billed) - amount_paid END),0) FROM facility_claims")->fetchColumn(); }
  else { $outAll = (float)db()->query("SELECT COALESCE(SUM(CASE WHEN status='Approved' THEN COALESCE(amount_approved, amount_billed) END),0) FROM facility_claims")->fetchColumn(); } ?>
<div class="panel" style="margin-top:16px">
<h2 style="margin-top:0">&#127961; Network at a glance</h2>
<div class="grid grid-5" style="gap:10px;margin-bottom:4px">
 <div class="kpi"><div class="k-label">Hospitals</div><div class="k-value"><?php echo num($NET['hosp']); ?></div></div>
 <div class="kpi teal"><div class="k-label">Clinics &amp; centres</div><div class="k-value"><?php echo num($NET['clc']); ?></div></div>
 <div class="kpi"><div class="k-label">Branches</div><div class="k-value"><?php echo num($NET['br']); ?></div></div>
 <div class="kpi gold"><div class="k-label">Facility users</div><div class="k-value"><?php echo num($netUsers); ?></div><div class="k-sub"><?php echo num($netPts); ?> patients registered</div></div>
 <div class="kpi red"><div class="k-label">Outstanding to facilities</div><div class="k-value"><?php echo ugx($outAll); ?></div><div class="k-sub">disbursed to date <?php echo ugx($paidAll); ?></div></div>
</div>
<div class="grid grid-4" style="gap:10px">
 <div class="kpi"><div class="k-label">Claims submitted</div><div class="k-value"><?php echo num($netCl['sub']); ?></div></div>
 <div class="kpi gold"><div class="k-label">Awaiting decision</div><div class="k-value"><?php echo num($netCl['pend']); ?></div><div class="k-sub"><a href="facility-claims.php">open the desk &raquo;</a></div></div>
 <div class="kpi green"><div class="k-label">Approved / cleared</div><div class="k-value"><?php echo ugx($netCl['appr']); ?></div></div>
 <div class="kpi teal"><div class="k-label">Payments manager</div><div class="k-value" style="font-size:15px;padding-top:10px"><a class="btn small danger" href="disbursements-manager.php">&#128181; Disburse</a></div></div>
</div>
<h3 style="margin:16px 0 6px">Facilities — click to enter a facility's own environment</h3>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px">
<?php foreach (fac_list() as $fo): $s41 = cl_fac_stats(intval($fo['id'])); ?>
  <a href="facility-clinical.php?facility=<?php echo e($fo['id']); ?>" style="text-decoration:none;color:inherit">
  <div class="panel" style="margin:0;padding:12px 14px;border-top:3px solid <?php echo fac_chip_border($fo['ftype']); ?>">
    <div style="display:flex;align-items:center;gap:10px">
      <span style="font-size:22px"><?php echo fac_icon($fo['ftype']); ?></span>
      <div style="flex:1;min-width:0"><b style="font-size:13.5px"><?php echo e($fo['name']); ?></b><br>
      <span style="font-size:10.5px;color:#5b7c99"><?php echo e($fo['ftype']); ?> · <?php echo e($fo['code']); ?><?php echo intval($fo['parent_id']) > 0 ? ' · ' . e(fac_name(intval($fo['parent_id']))) : ''; ?></span></div>
      <span class="badge" style="background:<?php echo $fo['status']==='Active'?'#e6f6ea;color:#20603a':'#FDECEC;color:#8c2424'; ?>"><?php echo strtoupper(e($fo['status'])); ?></span>
    </div>
    <div style="display:flex;gap:12px;margin-top:8px;font-size:11px;color:#33546e;flex-wrap:wrap">
      <span>&#128100; <?php echo num($s41['users']); ?></span><span>&#129658; <?php echo num($s41['patients']); ?></span>
      <span>&#9878; <?php echo num($s41['claims']); ?> claims</span>
      <span style="color:<?php echo $s41['pending']>0?'#8c2424':'#20603a'; ?>">&#8987; <?php echo num($s41['pending']); ?> pending</span>
      <span style="color:<?php echo $s41['outstanding']>0?'#8a6410':'#20603a'; ?>">&#128181; <?php echo num($s41['outstanding']); ?> owed</span>
    </div>
  </div></a>
<?php endforeach; ?>
</div>
</div>
<div class="grid grid-2" style="margin-top:12px">
  <div class="panel"><h2 style="margin-top:0">Claims &amp; disbursements — 12 months (UGX)</h2>
   <div class="chart-wrap"><canvas id="facClaimChart"></canvas></div></div>
  <div class="panel"><h2 style="margin-top:0">Disbursed by payment method</h2>
   <div class="chart-wrap"><canvas id="payMethodChart"></canvas></div></div>
</div>
<script>
<?php
$months41 = month_keys(12); $subM = array(); $payM = array();
foreach ($months41 as $mm) { $subM[$mm] = 0.0; $payM[$mm] = 0.0; }
try {
  foreach (db()->query("SELECT submitted_at, amount_billed FROM facility_claims WHERE status<>'Draft' AND submitted_at IS NOT NULL")->fetchAll() as $r41) { $k41 = substr((string)$r41['submitted_at'], 0, 7); if (isset($subM[$k41])) $subM[$k41] += (float)$r41['amount_billed']; }
  $q41 = 'SELECT paid_at, amount FROM disbursements' . (cl_has('disbursements','reversed') ? ' WHERE reversed=0' : '');
  foreach (db()->query($q41)->fetchAll() as $r41) { $k41 = substr((string)$r41['paid_at'], 0, 7); if (isset($payM[$k41])) $payM[$k41] += (float)$r41['amount']; }
} catch (Exception $e) {}
$lab41 = array(); $v1 = array(); $v2 = array();
foreach ($months41 as $mm) { $lab41[] = mlabel($mm); $v1[] = round($subM[$mm]); $v2[] = round($payM[$mm]); }
$pmL = array(); $pmV = array();
try { foreach (db()->query("SELECT method, COALESCE(SUM(amount),0) a FROM disbursements" . (cl_has('disbursements','reversed')?' WHERE reversed=0':'') . " GROUP BY method ORDER BY a DESC LIMIT 6")->fetchAll() as $r41) { if ($r41['method'] !== '') { $pmL[] = $r41['method']; $pmV[] = round((float)$r41['a']); } } } catch (Exception $e) {}
?>
if (window.Chart) {
  new Chart(document.getElementById('facClaimChart'), { type:'line', data:{ labels: <?php echo json_encode($lab41); ?>, datasets:[
    { label:'Claims submitted', data: <?php echo json_encode($v1); ?>, borderColor:'#8a1c1c', backgroundColor:'rgba(138,28,28,.10)', fill:true, tension:.3, pointRadius:3 },
    { label:'Disbursed', data: <?php echo json_encode($v2); ?>, borderColor:'#20603a', backgroundColor:'rgba(32,96,58,.10)', fill:true, tension:.3, pointRadius:3 }]},
    options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ ticks:{ callback: msMoney } } }, plugins:{ legend:{ position:'bottom', labels:{ boxWidth:12, font:{size:11} } } } } });
  if (<?php echo count($pmV) ?>) msDoughnut('payMethodChart', <?php echo json_encode($pmL); ?>, <?php echo json_encode($pmV); ?>, ['#1B6BB3','#20603a','#C89211','#B23A3A','#7a5ea8','#0f766e']);
}
</script>
<?php endif; } catch (Exception $e41) {} ?>

<?php /* ---- v6.1 member deposit oversight ---- */
try { require_once __DIR__ . '/includes/member-billing-core.php'; if (mb_has_tbl()):
 $v5s = db()->query("SELECT COUNT(*) n, COALESCE(SUM(total),0) a FROM member_bills WHERE status='Settled' AND bill_date>='" . date('Y-m-01') . "'")->fetch();
 $v5c = db()->query("SELECT COUNT(*) n FROM facility_claims WHERE status IN ('Pending','Resubmitted','Under Review','Partially Approved','Submitted','Received')")->fetch();
 $depTot = (float)db()->query("SELECT COALESCE(SUM(deposit_amount),0) FROM members WHERE status='Active'")->fetchColumn();
 $depFac = array(); $depCat = array();
 try { foreach (db()->query("SELECT f.name fname, COUNT(m.id) mn, COALESCE(SUM(m.deposit_amount),0) da FROM facilities f LEFT JOIN members m ON m.facility_id=f.id AND m.status='Active' WHERE f.parent_id=0 GROUP BY f.id, f.name ORDER BY da DESC LIMIT 6")->fetchAll() as $trDf) $depFac[] = $trDf; } catch (Exception $e) {}
 try { foreach (db()->query("SELECT COALESCE(NULLIF(bcategory,''),'(general)') cat, COUNT(*) n, COALESCE(SUM(deposit_amount),0) da FROM members WHERE status='Active' GROUP BY COALESCE(NULLIF(bcategory,''),'(general)') ORDER BY da DESC LIMIT 6")->fetchAll() as $trDc) $depCat[] = $trDc; } catch (Exception $e) {}
 $payable = 0.0; try { $payable = (float)db()->query("SELECT COALESCE(SUM(COALESCE(amount_approved,amount_billed,0) - COALESCE(amount_paid,0)),0) FROM facility_claims WHERE status IN ('Approved','Partially Approved','Cleared','Scheduled')")->fetchColumn(); } catch (Exception $e) {}
 $v5ty = array(); foreach (db()->query("SELECT ftype, COUNT(*) c FROM facilities WHERE parent_id=0 GROUP BY ftype") as $tr) $v5ty[$tr['ftype']] = intval($tr['c']);
 $v5pol = 0; try { $v5pol = intval(db()->query("SELECT COUNT(*) FROM insurance_policies WHERE status='Active' AND expiry_date>='" . today() . "'")->fetchColumn()); } catch (Exception $e) {}
?>
<div class="panel" style="border:2px solid #12476f;background:linear-gradient(180deg,#fff,#F5FAFF);margin-top:14px"><h2 style="margin:0 0 8px">&#127970; Member deposit &amp; claim oversight</h2>
 <div style="display:flex;gap:10px;flex-wrap:wrap;font-size:12.5px">
  <span class="tb-badge" style="background:#E6F6EA;color:#20603a;padding:5px 10px;border-radius:4px"><b><?php echo num(intval($v5s['n'])); ?></b> member bills settled from deposits this month (<?php echo num($v5s['a']); ?>)</span>
  <span class="tb-badge" style="background:#EAF3FB;color:#12476f;padding:5px 10px;border-radius:4px">total member deposits <b><?php echo num($depTot); ?></b></span>
  <span class="tb-badge" style="background:#FFF6E5;color:#8a6410;padding:5px 10px;border-radius:4px">claims payable to facilities <b><?php echo num($payable); ?></b></span>
  <span class="tb-badge" style="background:#F1EDFB;color:#5b3fa8;padding:5px 10px;border-radius:4px"><b><?php echo num(intval($v5c['n'])); ?></b> claims awaiting payer decision</span>
  <?php if ($v5pol): ?><span class="tb-badge" style="background:#FDECEC;color:#8c2424;padding:5px 10px;border-radius:4px"><b><?php echo num($v5pol); ?></b> active insurance policies</span><?php endif; ?>
  <span style="flex:1"></span>
  <?php foreach ($v5ty as $tname => $cnt): ?><span style="color:#5b7c99"><?php echo fac_chip($tname); ?> &times;<?php echo $cnt; ?></span><?php endforeach; ?>
 </div>
 <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
  <a class="btn small primary" href="member-bills.php">&#129534; Open member clearing desk</a>
  <a class="btn small" href="claims-disburse.php">&#128197; Disbursement schedule</a>
  <a class="btn small" href="reconcile.php">&#9878; Reconciliation</a>
  <a class="btn small" href="plans.php">&#9878;&#65039; Insurance centre</a>
 <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:10px">
  <div style="flex:1;min-width:250px"><b style="font-size:12px;color:#12476f">Deposits by facility</b>
   <table class="data" style="margin:4px 0 0;font-size:12px"><tr><th>Facility</th><th>Members</th><th style="text-align:right">Available deposits</th></tr>
   <?php foreach ($depFac as $df): ?><tr><td><?php echo e($df['fname']); ?></td><td><?php echo num(intval($df['mn'])); ?></td><td style="text-align:right"><b><?php echo num($df['da']); ?></b></td></tr><?php endforeach; if (!$depFac): ?><tr><td colspan="3" style="color:#7a94aa">No facility members yet.</td></tr><?php endif; ?></table></div>
  <div style="flex:1;min-width:250px"><b style="font-size:12px;color:#12476f">Deposits by beneficiary category</b>
   <table class="data" style="margin:4px 0 0;font-size:12px"><tr><th>Category</th><th>Members</th><th style="text-align:right">Available deposits</th></tr>
   <?php foreach ($depCat as $dc): ?><tr><td><?php echo e($dc['cat']); ?></td><td><?php echo num(intval($dc['n'])); ?></td><td style="text-align:right"><b><?php echo num($dc['da']); ?></b></td></tr><?php endforeach; if (!$depCat): ?><tr><td colspan="3" style="color:#7a94aa">No members yet.</td></tr><?php endif; ?></table></div>
 </div>
 </div>
</div>
<?php endif; } catch (Exception $e50d) {} ?>
<script src="assets/js/charts.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
