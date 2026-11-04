<?php
/** v7.0 FACILITY SMS HISTORY (spec §9 menu #9): own-facility message log, delivery status, failed-message retry. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_once __DIR__ . '/includes/facility70.php';
require_login();
f70_ensure();
$OWNER = fac_is_owner();
$fid = $OWNER ? intval(get('facility')) : fac_scope_fid();
if (!$fid) { $fl = fac_list(); $fid = $fl ? intval($fl[0]['id']) : 0; }
if ($fid > 0 && !$OWNER) fac_lock($fid);
$err = ''; $info = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('op') === 'retry') {
        $lid = intval(post('logid'));
        $st = db()->prepare("SELECT * FROM sms_log WHERE id=? AND facility_id=? AND status='Failed'"); $st->execute(array($lid, $fid)); $row = $st->fetch();
        if (!$row) $err = 'Only failed messages queued by this facility can be retried here.';
        else {
            $cnt = intval($row['retry_count']);
            if ($cnt >= 5) $err = 'Retry limit (5) reached for this message — check the member phone number or the gateway, or let Kisubi support intervene.';
            else {
                db()->prepare('UPDATE sms_log SET status=?, response=?, retry_count=retry_count+1 WHERE id=?')->execute(array('Retrying', 'Manual facility retry #' . ($cnt + 1), $lid));
                $res = smsdual_send(isset($row['member_id']) ? intval($row['member_id']) : 0, (string)$row['phone_no'], (string)$row['message'], 'sms70:retry', 'SMS');
                db()->prepare('UPDATE sms_log SET status=?, response=? WHERE id=? AND status=?')->execute(array('Re-sent (logged separately)', 'Retry of message ' . $lid . ' by ' . (isset($_SESSION['uname']) ? $_SESSION['uname'] : 'staff'), $lid, 'Retrying'));
                $info = 'Message re-queued to ' . f70_mask_phone($row['phone_no']) . ' (gateway: ' . (isset($res['status']) ? $res['status'] : 'queued') . '). The underlying financial transaction was never affected.';
                log_action('Facility SMS retry #' . ($cnt + 1) . ' for sms_log ' . $lid, 'sms_log', $lid);
            }
        }
    }
}
$page_title = 'SMS History';
include __DIR__ . '/includes/header.php';
$rows = array(); $fails = 0;
try {
    $st = db()->prepare("SELECT * FROM sms_log WHERE facility_id=? ORDER BY id DESC LIMIT 120"); $st->execute(array($fid)); $rows = $st->fetchAll();
    foreach ($rows as $x) if ($x['status'] === 'Failed') $fails++;
} catch (Throwable $e) {}
?>
<div class="panel" style="padding:12px 16px">
  <h2 style="margin:0">SMS history &amp; delivery status</h2>
  <div style="font-size:12px;color:#5c7a94;margin:2px 0 8px">Messages raised by this facility's settlements, authorisations and verifications. A failed SMS never rolls back money — the financial record stands; you can retry the message safely (each retry is logged, and duplicates are flagged).</div>
  <?php if ($err): ?><div style="background:#FDECEC;color:#8c2424;border-radius:5px;padding:8px 12px;font-size:13px;margin:6px 0">&#9888; <?php echo e($err); ?></div><?php endif; ?>
  <?php if ($info): ?><div style="background:#E6F6EA;color:#20603a;border-radius:5px;padding:8px 12px;font-size:13px;margin:6px 0">&#10003; <?php echo e($info); ?></div><?php endif; ?>
  <?php if ($fails > 0): ?><div style="background:#FFF6E0;color:#8a6410;border-radius:5px;padding:8px 12px;font-size:12px;margin:6px 0"><?php echo $fails; ?> failed message(s) in the last 120 — use Retry, or ask the member to confirm receipt from the portal.</div><?php endif; ?>
</div>
<div class="panel">
<?php if (!$rows) echo '<p style="color:#5c7a94;font-size:13px">No messages recorded for this facility yet.</p>'; else {
echo '<table class="data"><tr><th>When</th><th>To</th><th>Message</th><th>Status</th><th>Gateway response</th><th></th></tr>';
foreach ($rows as $x) {
    $sc = $x['status'] === 'Sent' ? '#20603a' : ($x['status'] === 'Failed' ? '#8c2424' : ($x['status'] === 'Queued' ? '#8a6410' : '#155e9e'));
    echo '<tr><td style="white-space:nowrap">' . e(substr((string)$x['created_at'], 0, 16)) . '</td><td>' . e(f70_mask_phone($x['phone_no'])) . '</td><td style="max-width:420px;font-size:12px">' . e(mb_substr((string)$x['message'], 0, 150)) . ((strlen((string)$x['message']) > 150) ? '…' : '') . '</td><td style="color:' . $sc . ';font-weight:bold">' . e($x['status']) . (intval($x['retry_count']) ? ' <span style="color:#7a94aa;font-weight:normal">(retry ' . intval($x['retry_count']) . ')</span>' : '') . '</td><td style="font-size:11px;color:#7a94aa">' . e(mb_substr((string)$x['response'], 0, 60)) . '</td><td>';
    if ($x['status'] === 'Failed') echo '<form method="post" style="margin:0">' . csrf_field() . '<input type="hidden" name="op" value="retry"><input type="hidden" name="logid" value="' . intval($x['id']) . '"><button class="btn" type="submit" style="font-size:11px;padding:4px 10px">&#8635; Retry</button></form>';
    echo '</td></tr>';
}
echo '</table>'; } ?>
<p style="font-size:11px;color:#7a94aa;margin:8px 0 0">SMS gateway configuration and templates are central administration (Kisubi) and are intentionally not exposed here.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
