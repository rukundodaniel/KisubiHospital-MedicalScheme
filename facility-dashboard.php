<?php
/** v7.0 FACILITY DASHBOARD — simple, analytical, fully DB-backed (spec §2). */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_once __DIR__ . '/includes/clinical-core.php';
require_once __DIR__ . '/includes/facility70.php';
require_login();
f70_ensure();
if (!fac_has_tbl()) { $page_title = 'Dashboard'; include __DIR__ . '/includes/header.php'; echo '<div class="panel"><h2>No facility module</h2></div>'; include __DIR__ . '/includes/footer.php'; exit; }
$OWNER = fac_is_owner();
$fid = $OWNER ? intval(get('facility')) : fac_scope_fid();
if (!$fid) { $fl = fac_list(); $fid = $fl ? intval($fl[0]['id']) : 0; }
if ($fid > 0 && !$OWNER) fac_lock($fid);
$f = fac_of($fid);
$r = get('r', 'month'); $from = get('from'); $to = get('to');
list($from, $to) = f70_range($r, $from, $to);
$S = f70_stats($fid, $from, $to);
$T = f70_trends($fid, $from, $to);
$page_title = 'Facility Dashboard';
include __DIR__ . '/includes/header.php';
$QP = 'r=' . rawurlencode($r) . '&from=' . rawurlencode($from) . '&to=' . rawurlencode($to);
function card($label, $value, $sub, $href, $accent = '#1B6BB3') {
    echo '<a href="' . e($href) . '" style="display:block;text-decoration:none;background:#fff;border:1px solid #dfe9f2;border-left:4px solid ' . $accent . ';border-radius:6px;padding:10px 12px;margin:0 10px 10px 0">'
       . '<div style="font:bold 10px \'Segoe UI\',Arial;letter-spacing:.6px;color:#5c7a94;text-transform:uppercase">' . e($label) . '</div>'
       . '<div style="font:bold 20px Georgia,serif;color:#12476f;margin:2px 0">' . e($value) . '</div>'
       . '<div style="font-size:11px;color:#7a94aa">' . e($sub) . '</div></a>';
}
?>
<div class="panel" style="padding:12px 16px">
  <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between">
    <div>
      <h2 style="margin:0"><?php echo e($f ? $f['name'] : 'Facility'); ?> <span style="font:normal 12px 'Segoe UI',Arial;color:#5c7a94"><?php echo e($f && $f['code'] ? $f['code'] : ''); ?></span></h2>
      <div style="font-size:12px;color:#5c7a94">Operational overview — every figure is a live count from scheme records.</div>
    </div>
    <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">
      <?php if ($OWNER): $fl = fac_list(); ?>
      <select onchange="location='facility-dashboard.php?facility='+this.value+'&<?php echo e($QP); ?>'" style="padding:5px 8px;border:1px solid #c9d9e8;border-radius:4px">
        <?php foreach ($fl as $fx): ?><option value="<?php echo intval($fx['id']); ?>" <?php echo intval($fx['id']) === $fid ? 'selected' : ''; ?>><?php echo e($fx['name']); ?></option><?php endforeach; ?>
      </select><?php endif; ?>
      <?php foreach (array('today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'quarter' => 'Quarter', 'year' => 'This year') as $k => $lbl): ?>
      <a href="facility-dashboard.php?r=<?php echo $k; ?>" style="text-decoration:none;font:bold 11px 'Segoe UI',Arial;padding:5px 10px;border-radius:4px;<?php echo $r === $k ? 'background:#1B6BB3;color:#fff' : 'background:#EAF2FA;color:#12476f'; ?>"><?php echo $lbl; ?></a><?php endforeach; ?>
      <form method="get" action="facility-dashboard.php" style="display:flex;gap:4px;align-items:center;margin:0">
        <input type="hidden" name="r" value="custom"><input type="hidden" name="facility" value="<?php echo $fid; ?>">
        <input type="date" name="from" value="<?php echo e($from); ?>" style="padding:4px;border:1px solid #c9d9e8;border-radius:4px">
        <input type="date" name="to" value="<?php echo e($to); ?>" style="padding:4px;border:1px solid #c9d9e8;border-radius:4px">
        <button class="btn" type="submit" style="font-size:11px;padding:5px 10px">Apply</button>
      </form>
    </div>
  </div>
  <div style="font-size:11px;color:#7a94aa;margin-top:6px">Period: <b><?php echo e($from); ?></b> → <b><?php echo e($to); ?></b> · totals that show <em>all-time</em> are not period-filtered (claims outstanding, lifetime deposits).</div>
</div>
<div style="display:flex;flex-wrap:wrap;margin:0 -0px">
<?php
card('Members served', number_format($S['members']), 'unique members billed', 'institution-members.php?facility=' . $fid);
card('Bills generated', number_format($S['bills']), 'in selected period', 'member-bills.php?facility=' . $fid);
card('Bill value', ugx($S['bills_value']), 'total recorded', 'member-bills.php?facility=' . $fid, '#5b3fa8');
card('Deposits utilized', ugx($S['deposits_used']), 'settled from member ledgers', 'member-bills.php?facility=' . $fid . '&st=Settled', '#20603a');
card('Claims submitted', number_format($S['claims_submitted']), 'to Kisubi (excl. drafts)', 'facility-claims.php?facility=' . $fid . '&st=Submitted', '#8a6410');
card('Pending review', number_format($S['claims_pending']), 'awaiting Kisubi decision', 'facility-claims.php?facility=' . $fid . '&st=Pending', '#8a6410');
card('Approved', number_format($S['claims_approved']), 'by Kisubi', 'facility-claims.php?facility=' . $fid . '&st=Approved', '#20603a');
card('Rejected / returned', number_format($S['claims_returned']), 'need correction', 'facility-claims.php?facility=' . $fid . '&st=Returned', '#8c2424');
card('Cleared for payment', number_format($S['claims_cleared']), 'approved, awaiting disbursement', 'facility-claims.php?facility=' . $fid . '&st=Cleared', '#0f766e');
card('Disbursed to facility', ugx($S['paid_total']), 'confirmed payments received', 'facility-disbursements.php?facility=' . $fid . '&r=' . rawurlencode($r) . '&to=' . rawurlencode($to) . '& ', '#20603a');
card('Outstanding (all-time)', ugx($S['outstanding']), 'approved minus paid', 'facility-disbursements.php?facility=' . $fid . '&view=outstanding', '#8a6410');
card('Received by method', ugx($S['bank'] + $S['cash'] + $S['momo'] + $S['other_m']), number_format($S['bank']) . ' bank · ' . number_format($S['cash']) . ' cash · ' . number_format($S['momo']) . ' MoMo (UGX)', 'facility-disbursements.php?facility=' . $fid, '#155e9e');
?>
</div>
<div style="display:flex;flex-wrap:wrap;gap:0">
  <div style="flex:1 1 46%;min-width:340px">
    <?php echo f70_svg_bar('Claims submitted vs approved (monthly)', array(
        array('k' => 'Submitted', 'v' => $T['claims'], 'l' => $T['labels']),
        array('k' => 'Approved',  'v' => $T['approved'], 'l' => $T['labels'])), array('#8a6410', '#20603a')); ?>
  </div>
  <div style="flex:1 1 46%;min-width:340px">
    <?php echo f70_svg_bar('Monthly billing trend — count (monthly bill value in chart below)', array(
        array('k' => 'Bills', 'v' => $T['bills'], 'l' => $T['labels'])), array('#1B6BB3')); ?>
  </div>
  <div style="flex:1 1 46%;min-width:340px">
    <?php echo f70_svg_bar('Monthly bill value (UGX)', array(
        array('k' => 'Value', 'v' => $T['billV'], 'l' => $T['labels'])), array('#5b3fa8'), 'ugx'); ?>
  </div>
  <div style="flex:1 1 46%;min-width:340px">
    <?php echo f70_svg_bar('Monthly disbursements (UGX, paid only)', array(
        array('k' => 'Paid', 'v' => $T['disb'], 'l' => $T['labels'])), array('#20603a'), 'ugx'); ?>
  </div>
  <div class="panel" style="flex:1 1 46%;min-width:340px"><h3 style="margin:0 0 8px;font-size:13px;color:#12476f">Disbursements by payment method (period)</h3>
    <?php echo f70_svg_donut('By method', array('Bank' => $S['bank'], 'Cash' => $S['cash'], 'Mobile money' => $S['momo'], 'Other' => $S['other_m'])); ?>
  </div>
  <div class="panel" style="flex:1 1 46%;min-width:340px"><h3 style="margin:0 0 8px;font-size:13px;color:#12476f">Claims status distribution (period)</h3>
    <?php $dist = array(); foreach ((isset($S['by_status']) ? $S['by_status'] : array()) as $k => $v) { $dist[function_exists('cl_slabel') ? cl_slabel($k) : $k] = intval($v); } echo f70_svg_donut('Status', $dist ? $dist : array('None' => 1)); ?>
  </div>
</div>
<p style="font-size:12px;color:#5c7a94;margin:14px 0 0">Quick actions:
 <a href="facility-verify.php" class="btn" style="text-decoration:none;padding:4px 12px">Verify a member</a>
 <a href="member-bills.php?facility=<?php echo $fid; ?>" class="btn" style="text-decoration:none;padding:4px 12px">Billing &amp; settlement</a>
 <a href="pricelist.php?facility=<?php echo $fid; ?>" class="btn" style="text-decoration:none;padding:4px 12px">Price list</a>
 <a href="facility-reports.php?facility=<?php echo $fid; ?>" class="btn" style="text-decoration:none;padding:4px 12px">Reports</a></p>
<?php include __DIR__ . '/includes/footer.php'; ?>
