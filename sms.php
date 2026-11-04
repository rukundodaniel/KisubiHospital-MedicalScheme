<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/smsdual.php';
require_role(array('Super Admin', 'Administrator', 'Reception'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

/** send an already-logged row through the SmsDualClient (live path) */
function sms_send_row($r) {
    $resp = SmsDualClient::fromSettings()->sendSms(smsdual_msisdn($r['phone_no']), $r['message']);
    $sent = ($resp === 'SUCCESS');
    db()->prepare('UPDATE sms_log SET status=?, response=?, sent_at=? WHERE id=?')
       ->execute(array($sent ? 'Sent' : 'Failed', mb_substr($resp, 0, 400), $sent ? date('Y-m-d H:i:s') : null, intval($r['id'])));
    return $sent;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'send') {
        $to = post('phone'); $msg = post('message'); $mid = intval(post('member_id')) ?: null; $ch = post('channel') === 'WhatsApp' ? 'WhatsApp' : 'SMS';
        if ($to === '' || $msg === '') { flash('Phone number and message are required.', 'err'); redirect('sms.php'); }
        $res = smsdual_send($mid, $to, $msg, $_SESSION['uname'], $ch);
        if ($res['status'] === 'Sent') flash('Message delivered by SmsDual to ' . $to . '.');
        elseif ($res['status'] === 'Failed') flash('SmsDual could not send to ' . $to . ' — ' . mb_substr(str_replace("\n", ' ', (string)$res['response']), 0, 160), 'err');
        else flash('Message queued to ' . $to . ' (' . setting('sms_send_mode', 'live') . ' mode — use "Send queued now" to deliver).', 'info');
        log_action('SMS ' . $res['status'] . ' to ' . $to, 'sms_log', isset($res['id']) ? $res['id'] : 0);
        redirect('sms.php');
    }
    if ($op === 'flush') {
        $rows = db()->query("SELECT * FROM sms_log WHERE status='Queued' AND channel='SMS' ORDER BY id LIMIT 20")->fetchAll();
        if (!$rows) { flash('No queued SMS messages to send.', 'info'); redirect('sms.php'); }
        if (setting('sms_send_mode', 'live') !== 'live') { flash('Send mode is set to queue — switch Settings → SmsDual gateway → Live first.', 'err'); redirect('sms.php'); }
        $okN = 0; $failN = 0; $lastErr = '';
        foreach ($rows as $r) { if (sms_send_row($r)) $okN++; else { $failN++; $lastErr = (string)$r['response']; } }
        flash('SmsDual flush complete: ' . $okN . ' sent' . ($failN ? ', ' . $failN . ' failed' . ($lastErr !== '' ? ' (last: ' . mb_substr(str_replace("\n", ' ', $lastErr), 0, 120) . ')' : '') : '') . '.', $failN ? 'err' : 'ok');
        log_action('Flushed ' . count($rows) . ' queued SMS', 'sms_log', 0);
        redirect('sms.php');
    }
    if ($op === 'sendnow') {
        $st = db()->prepare("SELECT * FROM sms_log WHERE id=? AND status IN ('Queued','Failed')");
        $st->execute(array(intval(post('id')))); $r = $st->fetch();
        if (!$r) { flash('That message is not waiting to be sent.', 'err'); redirect('sms.php'); }
        if (setting('sms_send_mode', 'live') !== 'live') { flash('Set Settings → SmsDual gateway → Live mode to send for real.', 'err'); redirect('sms.php'); }
        if (sms_send_row($r)) flash('Message sent to ' . $r['phone_no'] . ' via SmsDual.');
        else flash('SmsDual rejected/failed the send — see the outbox response.', 'err');
        log_action('SMS manual send to ' . $r['phone_no'], 'sms_log', intval($r['id']));
        redirect('sms.php');
    }
    if ($op === 'retry') {
        $id = intval(post('id'));
        db()->prepare("UPDATE sms_log SET status='Queued', response='Re-queued for sending.', sent_at=NULL WHERE id=? AND status='Failed'")->execute(array($id));
        flash('Failed message re-queued.', 'info'); redirect('sms.php');
    }
}

$rows = db()->query("SELECT s.*, m.full_names FROM sms_log s LEFT JOIN members m ON m.id=s.member_id ORDER BY s.id DESC LIMIT 150")->fetchAll();
$agg = db()->query("SELECT status, COUNT(*) c FROM sms_log GROUP BY status")->fetchAll();
$counts = array(); foreach ($agg as $a) $counts[$a['status']] = intval($a['c']);
$queuedN = isset($counts['Queued']) ? $counts['Queued'] : 0;
$members = db()->query('SELECT id, member_no, full_names, phone_no FROM members WHERE status="Active" ORDER BY full_names')->fetchAll();
$cli = SmsDualClient::fromSettings();

$pg_title = 'SMS Centre'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head"><div><h1>SMS &amp; WhatsApp Centre</h1>
<span class="sub">SmsDual client <?php echo e($cli->username); ?> &middot; sender <b><?php echo e($cli->senderId); ?></b> &middot; <a href="https://www.smsdual.info/bulksms-api.php" target="_blank" style="color:#5b7c99"><?php echo e($cli->apiUrl()); ?></a> &middot; mode: <b><?php echo e(strtoupper(setting('sms_send_mode', 'live'))); ?></b></span></div>
<div class="noprint"><form method="post" style="display:inline" onsubmit="return confirm('Send all queued SMS messages now via SmsDual?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="flush">
<button class="btn success" type="submit" <?php echo $queuedN ? '' : 'disabled'; ?>>&#9889; Send queued now (<?php echo $queuedN; ?>)</button></form></div></div>

<div class="grid grid-3" style="margin-bottom:14px">
  <div class="kpi"><div class="k-label">Total Messages</div><div class="k-value"><?php echo num(array_sum($counts)); ?></div><div class="k-sub">all time</div></div>
  <div class="kpi green"><div class="k-label">Sent</div><div class="k-value"><?php echo num(isset($counts['Sent']) ? $counts['Sent'] : 0); ?></div><div class="k-sub">via SmsDual</div></div>
  <div class="kpi red"><div class="k-label">Failed / Queued</div><div class="k-value"><?php echo num((isset($counts['Failed'])?$counts['Failed']:0) + $queuedN); ?></div><div class="k-sub"><?php echo $queuedN; ?> waiting</div></div>
</div>

<div class="panel"><h2>Compose message</h2>
<form method="post" action="sms.php"><?php echo csrf_field(); ?><input type="hidden" name="op" value="send">
<div class="formgrid">
  <div><label class="f">Channel</label><select class="f" name="channel"><option>SMS</option><option>WhatsApp</option></select></div>
  <div><label class="f">Member (optional, autofills phone)</label>
    <select class="f" id="membSel" onchange="document.getElementById('phoneBox').value=this.options[this.selectedIndex].getAttribute('data-phone')">
      <option value="0" data-phone="">-- none --</option>
      <?php foreach ($members as $mm): ?><option value="<?php echo e($mm['id']); ?>" data-phone="<?php echo e($mm['phone_no']); ?>"><?php echo e($mm['member_no'].' - '.$mm['full_names']); ?></option><?php endforeach; ?>
    </select><input type="hidden" name="member_id" value="" id="memberId"></div>
  <div><label class="f">Phone number *</label><input class="f" id="phoneBox" name="phone" placeholder="077X... / 25677X..." required></div>
  <div class="span3"><label class="f">Message *</label><textarea class="f" name="message" rows="3" required placeholder="Dear member, your deposit of ... has been received. - Kisubi Hospital Scheme"></textarea></div>
</div>
<script>document.getElementById('membSel').addEventListener('change',function(){document.getElementById('memberId').value=this.value;});</script>
<div style="margin-top:12px;display:flex;gap:10px;align-items:center">
  <button class="btn primary" type="submit"><?php echo setting('sms_send_mode','live')==='live' ? 'Send via SmsDual' : 'Queue message'; ?></button>
  <span style="font-size:11.5px;color:#5b7c99">Numbers are sent to the gateway as 256&hellip; automatically &middot; <?php echo $cli->username !== '' && trim($cli->password) !== '' ? 'account ready' : 'configure account in Settings'; ?>.</span>
</div>
</form></div>

<div class="panel"><h2>Outbox log</h2>
<div class="twrap" style="border:0"><table class="data">
<tr><th>When</th><th>To</th><th>Member</th><th>Channel</th><th>Message</th><th>Status</th><th class="noprint"></th></tr>
<?php if (!$rows): ?><tr><td colspan="7" style="color:#5b7c99">No messages yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $bc=$r['status']=='Sent'?'green':($r['status']=='Failed'?'red':'amber'); ?>
<tr><td style="white-space:nowrap"><?php echo e(date('d M H:i', strtotime($r['created_at']))); ?></td>
<td><?php echo e($r['phone_no']); ?></td><td><?php echo e($r['full_names']); ?></td>
<td><span class="badge <?php echo $r['channel']=='WhatsApp'?'green':'blue'; ?>"><?php echo e($r['channel']); ?></span></td>
<td style="max-width:340px"><?php echo e(mb_substr($r['message'], 0, 90)); ?><?php echo strlen($r['message'])>90?'…':''; ?></td>
<td><span class="badge <?php echo $bc; ?>"><?php echo e($r['status']); ?></span><br><span style="font-size:10.5px;color:#7a94aa" title="<?php echo e($r['response']); ?>"><?php echo e(mb_substr((string)$r['response'],0,40)); ?></span></td>
<td class="noprint" style="white-space:nowrap">
<?php if ($r['status']!=='Sent' && $r['channel']==='SMS'): ?><form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="sendnow"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><button class="btn small primary" type="submit">Send now</button></form><?php endif; ?>
<?php if ($r['status']==='Failed'): ?><form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="retry"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><button class="btn small plain" type="submit">Re-queue</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
