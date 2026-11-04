<?php
/** v7.0 FACILITY REPORTS (spec §10): 15 analytical reports, date-filtered, print + CSV export, facility-scoped. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_once __DIR__ . '/includes/clinical-core.php';
require_once __DIR__ . '/includes/facility70.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));
f70_ensure();
$OWNER = fac_is_owner();
$fid = $OWNER ? intval(get('facility')) : fac_scope_fid();
if (!$fid) { $fl = fac_list(); $fid = $fl ? intval($fl[0]['id']) : 0; }
if ($fid > 0 && !$OWNER) fac_lock($fid);
$reps = f70_reports_list();
$key = get('rep', 'daily-billing'); if (!isset($reps[$key])) $key = 'daily-billing';
$r = get('r', 'month'); $from = get('from'); $to = get('to');
list($from, $to) = f70_range($r, $from, $to);
$f = fac_of($fid);
$D = f70_report_run($key, $fid, $from, $to);
if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $key . '-' . $f['code'] . '-' . $from . 'to' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('Report: ' . $reps[$key], $f['name'], 'Period', $from, 'to', $to));
    if ($D['cols']) fputcsv($out, $D['cols']);
    foreach ($D['rows'] as $row) fputcsv($out, array_values($row));
    fclose($out); exit;
}
$page_title = 'Facility Reports';
include __DIR__ . '/includes/header.php';
$QP = 'facility=' . $fid . '&rep=' . rawurlencode($key) . '&r=' . rawurlencode($r) . '&from=' . rawurlencode($from) . '&to=' . rawurlencode($to);
?>
<div class="panel" style="padding:12px 16px">
  <h2 style="margin:0">Reports — <?php echo e($f ? $f['name'] : ''); ?></h2>
  <div style="font-size:12px;color:#5c7a94;margin:2px 0 10px">Every report is generated live from scheme records and shows only this facility's data. Excel-compatible CSV export and print provided; central reports remain a Kisubi administrator function.</div>
  <div style="display:flex;flex-wrap:wrap;gap:5px">
    <?php foreach ($reps as $k => $lbl): ?><a href="facility-reports.php?facility=<?php echo $fid; ?>&rep=<?php echo e($k); ?>&r=<?php echo e($r); ?>" style="text-decoration:none;font:11px 'Segoe UI',Arial;padding:5px 10px;border-radius:4px;<?php echo $k === $key ? 'background:#1B6BB3;color:#fff;font-weight:bold' : 'background:#EAF2FA;color:#12476f' ?>"><?php echo e($lbl); ?></a><?php endforeach; ?>
  </div>
  <form method="get" action="facility-reports.php" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:12px">
    <input type="hidden" name="facility" value="<?php echo $fid; ?>"><input type="hidden" name="rep" value="<?php echo e($key); ?>">
    <select name="r" onchange="this.form.submit()" style="padding:6px;border:1px solid #c9d9e8;border-radius:4px">
      <?php foreach (array('today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'quarter' => 'Quarter', 'year' => 'This year', 'custom' => 'Custom…') as $k => $lbl): ?><option value="<?php echo $k; ?>" <?php echo $r === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option><?php endforeach; ?>
    </select>
    <input type="hidden" name="facility" value="<?php echo $fid; ?>"><input type="date" name="from" value="<?php echo e($from); ?>" style="padding:5px;border:1px solid #c9d9e8;border-radius:4px">
    <input type="date" name="to" value="<?php echo e($to); ?>" style="padding:5px;border:1px solid #c9d9e8;border-radius:4px">
    <button class="btn" type="submit" style="padding:6px 12px;font-size:12px">Run</button>
    <a class="btn noprint" style="text-decoration:none;padding:6px 12px;font-size:12px" href="facility-reports.php?<?php echo e($QP); ?>&export=csv">&#8681; CSV (Excel)</a>
    <a class="btn noprint" style="text-decoration:none;padding:6px 12px;font-size:12px" href="facility-reports.php?<?php echo e($QP); ?>&print=1">&#128424; Print</a>
  </form>
</div>
<div class="panel"><h3 style="margin:0 0 4px;font-size:14px;color:#12476f"><?php echo e($reps[$key]); ?> <span style="font:normal 11px 'Segoe UI',Arial;color:#7a94aa"><?php echo e($from); ?> → <?php echo e($to); ?></span></h3>
<?php
if (!$D['cols']) echo '<p style="color:#5c7a94;font-size:13px">No records for this report in the selected period.</p>';
else {
    echo '<table class="data"><tr>'; foreach ($D['cols'] as $c) echo '<th>' . e(str_replace('_', ' ', $c)) . '</th>'; echo '</tr>';
    $numish = array('amount', 'bills', 'visits', 'total', 'used', 'payable', 'paid', 'outstanding', 'price', 'old_price', 'new_price', 'amount_billed', 'amount_approved', 'settled', 'before_bal', 'after_bal');
    foreach ($D['rows'] as $row) { echo '<tr>'; foreach ($row as $c => $v) { $isNum = in_array($c, $numish, true); echo '<td' . ($isNum ? ' style="text-align:right;font-variant-numeric:tabular-nums"' : '') . '>' . e($isNum && $v !== null ? number_format((float)$v, 0) : (string)$v) . '</td>'; } echo '</tr>'; }
    echo '</table><p style="font-size:11px;color:#7a94aa;margin:8px 0 0">' . count($D['rows']) . ' row(s) · figures from the live scheme database · report access is recorded in the audit log.</p>';
}
?>
</div>
<?php if (get('print')) echo '<script>window.print();</script>'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
