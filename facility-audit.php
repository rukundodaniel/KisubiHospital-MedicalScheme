<?php
/** v7.0 FACILITY AUDIT LOGS (spec §9 menu #13, §11): this facility's trail only. Facility Admin (or Kisubi owner) access. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/facility70.php';
require_role(array('Super Admin', 'Facility Admin'));
f70_ensure();
$OWNER = fac_is_owner();
$fid = $OWNER ? intval(get('facility')) : fac_scope_fid();
if ($fid <= 0 && !$OWNER) { flash('Audit logs are scoped to your facility.', 'err'); redirect('facility-dashboard.php'); }
if ($fid > 0 && !$OWNER) fac_lock($fid);
$q = get('q'); $from = get('from'); $to = get('to');
$rows = array();
try {
    $w = 'facility_id=?'; $P = array($fid);
    if ($q !== '') { $w .= ' AND (action LIKE ? OR username LIKE ?)'; $P[] = '%' . $q . '%'; $P[] = '%' . $q . '%'; }
    if ($from !== '') { $w .= ' AND created_at>=?'; $P[] = $from . ' 00:00:00'; }
    if ($to !== '') { $w .= ' AND created_at<=?'; $P[] = $to . ' 23:59:59'; }
    $st = db()->prepare("SELECT created_at, username, action, record_table, record_id, ip FROM activity_log WHERE $w ORDER BY id DESC LIMIT 300");
    $st->execute($P); $rows = $st->fetchAll();
} catch (Throwable $e) {}
$page_title = 'Facility Audit Log';
include __DIR__ . '/includes/header.php';
?>
<div class="panel" style="padding:12px 16px">
 <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center">
  <div><h2 style="margin:0">Audit log — this facility</h2>
  <div style="font-size:12px;color:#5c7a94">Every verification, settlement, price change and account action stamped to this facility. Append-only: entries cannot be edited or deleted from the portal.</div></div>
  <form method="get" style="display:flex;gap:6px;align-items:center;margin:0">
   <input type="hidden" name="facility" value="<?php echo $fid; ?>">
   <input name="q" value="<?php echo e($q); ?>" placeholder="search action or user" style="padding:6px 9px;border:1px solid #c9d9e8;border-radius:5px;width:190px">
   <input type="date" name="from" value="<?php echo e($from); ?>" style="padding:5px;border:1px solid #c9d9e8;border-radius:5px">
   <input type="date" name="to" value="<?php echo e($to); ?>" style="padding:5px;border:1px solid #c9d9e8;border-radius:5px">
   <button class="btn" type="submit" style="font-size:12px;padding:6px 12px">Filter</button>
  </form>
 </div>
</div>
<div class="panel">
<?php if (!$rows) echo '<p style="color:#5c7a94;font-size:13px">No entries in range — actions performed before this release carry no facility stamp and are visible only in the central log.</p>'; else {
 echo '<table class="data"><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>IP</th></tr>';
 foreach ($rows as $x) echo '<tr><td style="white-space:nowrap;font-size:12px">' . e(substr((string)$x['created_at'], 0, 19)) . '</td><td><b>' . e($x['username']) . '</b></td><td style="font-size:12px">' . e($x['action']) . '</td><td style="font-size:11px;color:#7a94aa">' . e($x['record_table']) . ($x['record_id'] ? ' #' . intval($x['record_id']) : '') . '</td><td style="font-size:11px;color:#7a94aa">' . e($x['ip']) . '</td></tr>';
 echo '</table><p style="font-size:11px;color:#7a94aa;margin:8px 0 0">' . count($rows) . ' entr(ies) shown (latest 300).</p>'; } ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
