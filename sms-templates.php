<?php
/** v5.0 SMS template studio (spec §13): configurable bodies for every event category.
 *  Queue lives in the SMS Centre; turning templates off simply stops the notifications. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/wallet-core.php';
require_role(array('Super Admin', 'Administrator'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9
$page_title = 'SMS Templates';
include __DIR__ . '/includes/header.php';
try { $rows = db()->query('SELECT * FROM sms_templates ORDER BY tkey')->fetchAll(); } catch (Exception $e) { $rows = array(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('op') === 'save' && $rows !== array()) {
        $st = db()->prepare('UPDATE sms_templates SET body=?, active=?, label=? WHERE tkey=?');
        foreach ($rows as $r) {
            $k = $r['tkey'];
            $hasBody = array_key_exists('t_' . $k, $_POST); $hasOn = array_key_exists('on_' . $k, $_POST);
            if (!$hasBody && !$hasOn) continue; // partial saves must never wipe untouched keys
            $body = $hasBody ? (string)post('t_' . $k) : (string)$r['body'];
            $act = $hasOn ? (post('on_' . $k) === '1' ? 1 : 0) : intval($r['active']);
            if ($body === '') $body = (string)$r['body']; // an empty submission cannot erase a live template
            $st->execute(array($body, $act, (string)post('l_' . $k) !== '' ? post('l_' . $k) : $r['label'], $k));
        }
        log_action('SMS templates updated', 'sms_templates', 0);
        flash('Templates saved. Tokens: {name} {member_no} {inst} {amount} {ref} {date} {balance} {settle_no} {claim_no} {reason} {method} {policy} {expiry} {status} {plan} {acct}.', 'ok');
        redirect('sms-templates.php');
    }
}
?>
<div class="page-head"><h1>&#128221; SMS Template Studio</h1>
 <span class="sub">Every v5.0 notification (registration, deposits, settlements, receipts, claims, disbursements, renewals). Gateway stays in the <a href="sms.php">SMS Centre</a>; with no gateway configured messages simply sit in the queue.</span></div>
<?php if (!$rows): ?><div class="panel"><h2>No templates yet</h2><p>Run <a href="migrate.php">migrate.php</a> once so the v5.0 seeds create them, then delete the file.</p></div><?php else: ?>
<form method="post"><input type="hidden" name="op" value="save"><?php echo csrf_field(); ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
<?php foreach ($rows as $r): ?>
 <div class="panel" style="margin:0;padding:12px 14px"><div style="display:flex;gap:8px;align-items:center">
   <input class="f" style="flex:1;margin:0" name="l_<?php echo e($r['tkey']); ?>" value="<?php echo e($r['label']); ?>">
   <select class="f" name="on_<?php echo e($r['tkey']); ?>" style="width:100px;margin:0"><option value="1" <?php echo intval($r['active']) ? 'selected' : ''; ?>>Active</option><option value="0" <?php echo intval($r['active']) ? '' : 'selected'; ?>>Off</option></select>
   <code style="font-size:10.5px;color:#7a94aa"><?php echo e($r['tkey']); ?></code></div>
  <textarea class="f" rows="3" style="margin-top:6px;font-size:12.5px" name="t_<?php echo e($r['tkey']); ?>"><?php echo e($r['body']); ?></textarea>
 </div>
<?php endforeach; ?></div>
<div style="margin-top:12px"><button class="btn primary" type="submit">Save all templates</button></div>
</form><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
