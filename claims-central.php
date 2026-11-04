<?php
/** v6.0 KISUBI CENTRAL CLAIMS AND DISBURSEMENT MANAGEMENT — the authority dashboard.
 *  Summary cards for every claim state, monthly trends (submitted vs approved vs paid),
 *  per-facility load, and the live intake queue the claims officers work from.
 *  Read-only view: decisions happen on the review desk (claim-view / facility-claims). */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/acc.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$S = array_fill_keys(array('fac', 'rec', 'pend', 'rev', 'appr', 'rej', 'clr', 'sched', 'part', 'full', 'apprVal', 'paidVal', 'outVal'), 0);
$S['fac'] = intval(db()->query("SELECT COUNT(*) FROM facilities WHERE status='Active'")->fetchColumn());
try {
    $row = db()->query("SELECT
      COUNT(CASE WHEN status IN ('Submitted','Pending','Resubmitted','Under Review','Cleared','Approved','Partially Approved','PartPaid','Paid','Scheduled') THEN 1 END) rec,
      COUNT(CASE WHEN status IN ('Submitted','Pending','Resubmitted','Received') THEN 1 END) pend,
      COUNT(CASE WHEN status = 'Under Review' THEN 1 END) rev,
      COUNT(CASE WHEN status IN ('Approved','Partially Approved') THEN 1 END) appr,
      COUNT(CASE WHEN status = 'Rejected' THEN 1 END) rej,
      COUNT(CASE WHEN status = 'Cleared' THEN 1 END) clr,
      COUNT(CASE WHEN status = 'Scheduled' OR id IN (SELECT claim_id FROM disb_schedules WHERE status='Scheduled') THEN 1 END) sched,
      COUNT(CASE WHEN status = 'PartPaid' THEN 1 END) part,
      COUNT(CASE WHEN status = 'Paid' THEN 1 END) full,
      COALESCE(SUM(CASE WHEN status IN ('Approved','Partially Approved','Cleared','Scheduled','PartPaid','Paid') THEN COALESCE(amount_approved, amount_billed) END),0) apprVal
      FROM facility_claims")->fetch();
    foreach (array('rec', 'pend', 'rev', 'appr', 'rej', 'clr', 'sched', 'part', 'full') as $k) $S[$k] = intval($row[$k]);
    $S['apprVal'] = (float)$row['apprVal'];
    // total actually disbursed comes from the payment ledger (authoritative)
    $S['paidVal'] = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM disbursements' . (cl_has('disbursements', 'reversed') ? ' WHERE reversed=0' : ''))->fetchColumn();
} catch (Exception $e) {}
// outstanding = approved & not yet fully paid (liability view)
try {
    $S['outVal'] = 0.0;
    $stq = db()->query("SELECT id, COALESCE(amount_approved, amount_billed) a FROM facility_claims WHERE status IN ('Approved','Partially Approved','Cleared','Scheduled','PartPaid')");
    foreach ($stq->fetchAll() as $cc) $S['outVal'] += max(0, (float)$cc['a'] - claim_paid_total(intval($cc['id'])));
} catch (Exception $e) {}

/* ---- chart data (last 6 months) ---- */
$months = array(); $m0 = date('n'); $y0 = date('Y');
for ($i = 5; $i >= 0; $i--) { $mm = $m0 - $i; $yy = $y0; while ($mm <= 0) { $mm += 12; $yy--; } $months[] = sprintf('%04d-%02d', $yy, $mm); }
$series = array('sub' => array_fill_keys($months, 0.0), 'appr' => array_fill_keys($months, 0.0), 'paid' => array_fill_keys($months, 0.0));
foreach (array('sub' => "SELECT substr(COALESCE(submitted_at, created_at),1,7) k, SUM(amount_billed) v FROM facility_claims WHERE status<>'Draft' GROUP BY k",
               'appr' => "SELECT substr(COALESCE(decided_at, created_at),1,7) k, SUM(COALESCE(amount_approved, amount_billed)) v FROM facility_claims WHERE status IN ('Approved','Partially Approved','Cleared','Scheduled','PartPaid','Paid') GROUP BY k",
               'paid' => "SELECT substr(paid_at,1,7) k, SUM(amount) v FROM disbursements" . (cl_has('disbursements', 'reversed') ? ' WHERE reversed=0' : '') . ' GROUP BY k') as $key => $sqlq) {
    try { foreach (db()->query($sqlq)->fetchAll() as $r) { $k = (string)$r['k']; if (isset($series[$key][$k])) $series[$key][$k] += (float)$r['v']; } } catch (Exception $e) {}
}
$byFac = array();
try { $byFac = db()->query("SELECT f.name, COUNT(c.id) n, COALESCE(SUM(CASE WHEN c.status IN ('Approved','Partially Approved') THEN COALESCE(c.amount_approved,c.amount_billed) ELSE 0 END),0) appr,
    COALESCE(SUM((SELECT SUM(d.amount) FROM disbursements d WHERE d.claim_id=c.id AND d.reversed=0)),0) paid
    FROM facility_claims c JOIN facilities f ON f.id=c.facility_id GROUP BY c.facility_id ORDER BY appr DESC LIMIT 8")->fetchAll(); } catch (Exception $e) {}

/** simple inline SVG grouped bar chart */
function cc_chart($months, $series, $colors, $labels) {
    $max = 1.0; foreach ($series as $v) foreach ($v as $x) if ($x > $max) $max = $x;
    $W = 640; $H = 190; $pad = 34; $bw = ($W - $pad * 2) / count($months); $n = count($colors);
    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;max-width:' . $W . 'px;font-family:Arial;font-size:10px">';
    $svg .= '<line x1="' . $pad . '" y1="' . ($H - 24) . '" x2="' . ($W - 12) . '" y2="' . ($H - 24) . '" stroke="#c3d3e0"/>';
    foreach ($months as $i => $mo) {
        $x0 = $pad + $i * $bw;
        foreach ($colors as $j => $col) {
            $val = $series[$labels[$j]][$mo];
            $h = round(($H - 52) * $val / $max, 1);
            if ($val > 0) $svg .= '<rect x="' . round($x0 + 6 + $j * ($bw - 12) / $n, 1) . '" y="' . round($H - 24 - $h, 1) . '" width="' . round(max(6, ($bw - 14) / $n - 2), 1) . '" height="' . $h . '" fill="' . $col . '" rx="2"><title>' . $mo . ' ' . $labels[$j] . ': ' . number_format($val, 0) . ' UGX</title></rect>';
        }
        $svg .= '<text x="' . round($x0 + $bw / 2, 1) . '" y="' . ($H - 9) . '" text-anchor="middle" fill="#5c7d97">' . date('M', strtotime($mo . '-01')) . '</text>';
    }
    $svg .= '<text x="' . $pad . '" y="14" fill="#12476f" font-weight="bold">UGX ' . num($max) . ' scale</text></svg>';
    return $svg;
}

$page_title = 'Central Claims & Disbursement';
include __DIR__ . '/includes/header.php';
$cards = array(
    array('Registered facilities', num($S['fac']), 'active on the scheme', ''),
    array('Total claims received', num($S['rec']), 'all time, excl. drafts', ''),
    array('Pending claims', num($S['pend']), 'awaiting first touch', $S['pend'] > 0 ? 'amber' : ''),
    array('Under review', num($S['rev']), 'opened by an officer', ''),
    array('Approved claims', num($S['appr']), 'approved / awaiting clearance', ''),
    array('Rejected claims', num($S['rej']), '', $S['rej'] > 0 ? 'red' : ''),
    array('Awaiting clearance', num($S['clr']), 'cleared for the schedule', ''),
    array('Scheduled for payment', num($S['sched']), 'on the disbursement schedule', ''),
    array('Partially disbursed', num($S['part']), 'balance outstanding', $S['part'] > 0 ? 'amber' : ''),
    array('Fully disbursed', num($S['full']), '', ''),
    array('Total approved value', ugx($S['apprVal']), 'what Kisubi owes for cleared services', ''),
    array('Total disbursed', ugx($S['paidVal']), 'paid to facilities via this system', 'green'),
    array('Outstanding liabilities', ugx($S['outVal']), 'approved − paid', $S['outVal'] > 0 ? 'red' : 'green'),
);
?>
<div class="page-head"><h1>&#9878;&#65039; KISUBI CENTRAL CLAIMS &amp; DISBURSEMENT MANAGEMENT</h1>
 <span class="sub">Every facility submits here. Kisubi Hospital reviews, decides, clears and disburses — one authority, one audit trail, no double payments.</span>
 <span style="float:right;display:flex;gap:8px"><a class="btn small primary" href="facility-claims.php">&#128202; Review desk</a>
 <a class="btn small" href="claims-disburse.php">&#128197; Disbursement schedule</a>
 <a class="btn small plain" href="claims-reports.php">&#128196; Reports &amp; statements</a></span>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:12px 0">
<?php foreach ($cards as $c): ?><div class="kpi <?php echo $c[3]; ?>"><div class="k-label"><?php echo e($c[0]); ?></div><div class="k-value" style="font-size:<?php echo strlen((string)$c[1]) > 9 ? '15px' : '20px'; ?>;padding-top:6px"><?php echo e($c[1]); ?></div><div class="k-sub"><?php echo e($c[2]); ?></div></div><?php endforeach; ?>
</div>

<div style="display:flex;gap:12px;flex-wrap:wrap">
 <div class="panel" style="flex:1;min-width:340px"><h2 style="margin-top:0">&#128200; Claims trend — submitted vs approved vs paid (6 months)</h2>
  <?php echo cc_chart($months, $series, array('#1B6BB3', '#1e7f3c', '#8a6410'), array('sub', 'appr', 'paid')); ?>
  <div class="sub" style="margin-top:6px"><span style="color:#1B6BB3">&#9632;</span> submitted &middot; <span style="color:#1e7f3c">&#9632;</span> approved &middot; <span style="color:#8a6410">&#9632;</span> disbursed — hover bars for figures</div>
 </div>
 <div class="panel" style="flex:1;min-width:340px"><h2 style="margin-top:0">&#127973; Claims by facility — approved vs paid</h2>
  <table class="tbl"><tr><th>Facility</th><th style="text-align:right">Claims</th><th style="text-align:right">Approved</th><th style="text-align:right">Paid</th><th style="text-align:right">Owing</th><th>Progress</th></tr>
  <?php if (!$byFac) echo '<tr><td colspan="6" style="color:#7a94aa">No claims recorded yet.</td></tr>';
  foreach ($byFac as $r): $owing = max(0, (float)$r['appr'] - (float)$r['paid']); $pct = (float)$r['appr'] > 0 ? min(100, round(100 * $r['paid'] / $r['appr'])) : 0; ?>
   <tr><td><?php echo e($r['name']); ?></td><td style="text-align:right"><?php echo num($r['n']); ?></td><td style="text-align:right"><?php echo num($r['appr']); ?></td>
   <td style="text-align:right;color:#1e7f3c"><?php echo num($r['paid']); ?></td><td style="text-align:right;color:<?php echo $owing > 0 ? '#a33' : '#1e7f3c'; ?>"><?php echo num($owing); ?></td>
   <td style="width:120px"><div style="background:#e4ecf3;border-radius:4px;height:8px"><div style="background:#1B6BB3;height:8px;border-radius:4px;width:<?php echo $pct; ?>%"></div></div></td></tr>
  <?php endforeach; ?>
  </table></div>
</div>

<div class="panel"><h2 style="margin-top:0">&#128230; Intake queue — what the claims officers must action today</h2>
 <table class="tbl"><tr><th>Claim</th><th>Facility</th><th>Members</th><th style="text-align:right">Billed</th><th>Status</th><th>Waiting</th><th></th></tr>
 <?php
 $queue = db()->query("SELECT * FROM facility_claims WHERE status IN ('Submitted','Pending','Resubmitted','Under Review','Received') ORDER BY (status='Under Review') DESC, submitted_at ASC LIMIT 25")->fetchAll();
 if (!$queue) echo '<tr><td colspan="7" style="color:#1e7f3c">Queue is clear — no claims awaiting review. 🎉</td></tr>';
 foreach ($queue as $q): $days = $q['submitted_at'] ? max(0, intval((time() - strtotime((string)$q['submitted_at'])) / 86400)) : 0; $members = intval(db()->query('SELECT COUNT(DISTINCT member_id) FROM claim_lines WHERE claim_id=' . intval($q['id']))->fetchColumn()); ?>
  <tr><td><b><?php echo e($q['claim_no']); ?></b><?php if ($q['batch_label'] !== ''): ?><br><span class="sub"><?php echo e($q['batch_label']); ?></span><?php endif; ?></td>
   <td><?php echo e($q['facility_name']); ?></td><td><?php echo $members > 0 ? num($members) : e($q['patient_name'] ?: '—'); ?></td>
   <td style="text-align:right"><?php echo num($q['amount_billed']); ?></td><td><?php echo e(cl_slabel($q['status'])); ?></td>
   <td><?php echo $days > 2 ? '<b style="color:#a33">' . $days . ' days</b>' : $days . ' day(s)'; ?></td>
   <td><a class="btn small primary" href="claim-view.php?id=<?php echo intval($q['id']); ?>">Open &amp; mark received &#8594;</a></td></tr>
 <?php endforeach; ?></table>
 <p class="sub">Opening a claim stamps it “Received by Kisubi” automatically — facilities see that instantly on their tracking page. Approve / partially approve / return / reject happen on the review sheet.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
