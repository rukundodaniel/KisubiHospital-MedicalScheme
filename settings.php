<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(array('Super Admin'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$KEYS = array('hospital_name','scheme_name','sms_client','sms_username','sms_password','sms_sender_id','sms_api_url','sms_send_mode','whatsapp_phone_id','smtp_host','smtp_user','hospital_pg_enabled','hospital_pg_host','hospital_pg_port','hospital_pg_name','hospital_pg_user','hospital_pg_pass','momo_mode','momo_enabled','momo_base_url','momo_environment','momo_api_user','momo_api_key','momo_subscription_key','momo_currency','momo_min_amount','momo_max_amount','momo_callback_url',
    'member_billing_enabled','member_negative_balance','claim_selffunded_block','portal_sms_history','member_auth_enabled','auto_claim_on_settle','auto_disburse_on_clear',
    'f70_otp_expiry_mins','f70_otp_max_tries','f70_otp_resend_secs','f70_search_rate_per_hour','f70_balance_sms','f70_price_central_approval');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sel = db()->prepare('SELECT COUNT(*) FROM settings WHERE k=?');
    $upd = db()->prepare('UPDATE settings SET v=? WHERE k=?');
    $ins = db()->prepare('INSERT INTO settings (k, v) VALUES (?,?)');
    foreach ($KEYS as $k) {
        if (!array_key_exists('s_' . $k, $_POST)) continue; // partial saves from one panel must not blank the others
        $v = post('s_' . $k);
        if (in_array($k, array('sms_password','momo_api_key','momo_subscription_key','hospital_pg_pass'), true) && ($v === '' || $v === '********')) continue; // don't blank stored secrets
        $sel->execute(array($k));
        if (intval($sel->fetchColumn()) > 0) $upd->execute(array($v, $k)); else $ins->execute(array($k, $v));
    }
    log_action('Updated settings', 'settings');
    if (post('op') === 'testpg') {
        require_once __DIR__ . '/includes/hospital.php';
        list($okT, $msgT) = hospital_test_connection();
        flash($msgT, $okT ? 'ok' : 'err');
    }
    if (post('op') === 'test') {
        require_once __DIR__ . '/includes/momo.php';
        try {
            $tk = momo_token(true);
            flash('MoMo gateway OK - access token obtained (' . substr($tk, 0, 8) . '...). Gateway settings are valid.');
        } catch (Exception $ex) {
            flash('MoMo gateway test failed: ' . $ex->getMessage(), 'err');
        }
    } else {
        flash('Settings saved.');
    }
    redirect('settings.php');
}
$cur = array();
foreach (db()->query('SELECT k, v FROM settings') as $r) { $cur[$r['k']] = $r['v']; }

$logs = db()->query('SELECT * FROM activity_log ORDER BY id DESC LIMIT 40')->fetchAll();
$pg_title = 'Settings'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head"><h1>⚙ System Settings</h1></div>
<div class="panel"><h2>Integration &amp; identity configuration</h2>
<form method="post"><?php echo csrf_field(); ?>
<div class="formgrid">
  <div><label class="f">Hospital name</label><input class="f" name="s_hospital_name" value="<?php echo e(isset($cur['hospital_name'])?$cur['hospital_name']:''); ?>"></div>
  <div class="span2"><label class="f">Scheme name (prints on reports)</label><input class="f" name="s_scheme_name" value="<?php echo e(isset($cur['scheme_name'])?$cur['scheme_name']:''); ?>"></div>

  <div class="span3" style="border-top:2px solid #E3EFFA;padding-top:10px;margin-top:6px"><b style="color:#12476f">SmsDual gateway</b></div>
  <div><label class="f">Client ID</label><input class="f" name="s_sms_client" value="<?php echo e(isset($cur['sms_client'])&&$cur['sms_client']!==''?$cur['sms_client']:'256700541666'); ?>"></div>
  <div><label class="f">API username</label><input class="f" name="s_sms_username" value="<?php echo e(isset($cur['sms_username'])&&$cur['sms_username']!==''?$cur['sms_username']:'256700541666'); ?>"></div>
  <div><label class="f">API password</label><input class="f" type="password" name="s_sms_password" placeholder="******** (blank keeps current)"></div>
  <div><label class="f">Sender ID</label><input class="f" name="s_sms_sender_id" value="<?php echo e(isset($cur['sms_sender_id'])?$cur['sms_sender_id']:'KISUBI'); ?>"></div>
  <div class="span2"><label class="f">Send API URL (blank = built-in SmsDual endpoint)</label><input class="f" name="s_sms_api_url" value="<?php echo e(isset($cur['sms_api_url'])&&strpos((string)$cur['sms_api_url'],'smsdual.com')===false?$cur['sms_api_url']:''); ?>" placeholder="https://www.smsdual.info/bulksms-api.php — auto"></div>
  <div><label class="f">Send mode</label><select class="f" name="s_sms_send_mode">
    <option value="live" <?php echo !isset($cur['sms_send_mode'])||$cur['sms_send_mode']==='live'?'selected':''; ?>>Live (SmsDual sends immediately, like the desktop app)</option>
    <option value="queue" <?php echo (isset($cur['sms_send_mode'])&&$cur['sms_send_mode']==='queue')?'selected':''; ?>>Queue only (log, send via SMS Centre button)</option>
  </select></div>
  <div class="span3" style="border-top:2px solid #E3EFFA;padding-top:10px;margin-top:6px"><b style="color:#12476f">Hospital HIS — eafyakisubidb (PostgreSQL) for the Settlement Desk</b></div>
  <div><label class="f">Integration</label><select class="f" name="s_hospital_pg_enabled">
    <option value="0" <?php echo (isset($cur['hospital_pg_enabled'])&&$cur['hospital_pg_enabled']==='0')?'selected':''; ?>>Off — manual invoice entry</option>
    <option value="1" <?php echo (isset($cur['hospital_pg_enabled'])&&$cur['hospital_pg_enabled']==='1')?'selected':''; ?>>On — load paid invoice lines from HIS</option>
  </select></div>
  <div><label class="f">Host</label><input class="f" name="s_hospital_pg_host" value="<?php echo e(isset($cur['hospital_pg_host'])?$cur['hospital_pg_host']:''); ?>" placeholder="e.g. 192.168.70.20 or localhost"></div>
  <div><label class="f">Port</label><input class="f" name="s_hospital_pg_port" value="<?php echo e(isset($cur['hospital_pg_port'])?$cur['hospital_pg_port']:''); ?>" placeholder="5432"></div>
  <div><label class="f">Database</label><input class="f" name="s_hospital_pg_name" value="<?php echo e(isset($cur['hospital_pg_name'])&&$cur['hospital_pg_name']!==''?$cur['hospital_pg_name']:'eafyakisubidb'); ?>"></div>
  <div><label class="f">User</label><input class="f" name="s_hospital_pg_user" value="<?php echo e(isset($cur['hospital_pg_user'])?$cur['hospital_pg_user']:''); ?>"></div>
  <div><label class="f">Password</label><input class="f" type="password" name="s_hospital_pg_pass" placeholder="******** (blank keeps current)"></div>
  <div style="align-self:flex-end"><button class="btn plain" type="submit" name="op" value="testpg">Test HIS connection</button></div>
  <div><label class="f">WhatsApp Phone-Number ID</label><input class="f" name="s_whatsapp_phone_id" value="<?php echo e(isset($cur['whatsapp_phone_id'])?$cur['whatsapp_phone_id']:''); ?>"></div>
  <div><label class="f">SMTP host:port</label><input class="f" name="s_smtp_host" value="<?php echo e(isset($cur['smtp_host'])?$cur['smtp_host']:''); ?>"></div>
  <div><label class="f">SMTP username</label><input class="f" name="s_smtp_user" value="<?php echo e(isset($cur['smtp_user'])?$cur['smtp_user']:''); ?>"></div>
</div>
<div style="margin-top:14px"><button class="btn success" type="submit">Save all settings</button></div>
</form></div>


<div class="panel"><h2>&#129534; Member billing &amp; automation (Kisubi clearing policy)</h2>
<form method="post"><?php echo csrf_field(); ?>
<div class="formgrid">
  <div><label class="f">Member billing module</label><select class="f" name="s_member_billing_enabled">
    <option value="1" <?php echo setting('member_billing_enabled','1')==='1'?'selected':''; ?>>Enabled — facilities bill members from deposits</option>
    <option value="0" <?php echo setting('member_billing_enabled','1')==='0'?'selected':''; ?>>Disabled</option></select></div>
  <div><label class="f">Member authorization (e-Card + OTP)</label><select class="f" name="s_member_auth_enabled">
    <option value="1" <?php echo setting('member_auth_enabled','1')==='1'?'selected':''; ?>>Required before any deposit settlement (as at Kisubi Hospital)</option>
    <option value="0" <?php echo setting('member_auth_enabled','1')==='0'?'selected':''; ?>>Off — facility settles directly (legacy)</option></select></div>
  <div><label class="f">Auto-claim settled bills</label><select class="f" name="s_auto_claim_on_settle">
    <option value="1" <?php echo setting('auto_claim_on_settle','1')==='1'?'selected':''; ?>>Every settled Kisubi-funded bill is put on a claim and sent to Kisubi automatically</option>
    <option value="0" <?php echo setting('auto_claim_on_settle','1')==='0'?'selected':''; ?>>Off — facilities batch-submit claims themselves</option></select></div>
  <div><label class="f">Auto-schedule on approval</label><select class="f" name="s_auto_disburse_on_clear">
    <option value="1" <?php echo setting('auto_disburse_on_clear','1')==='1'?'selected':''; ?>>Approved member-bill claims are auto-cleared and scheduled for release (bank / cash / mobile money)</option>
    <option value="0" <?php echo setting('auto_disburse_on_clear','1')==='0'?'selected':''; ?>>Off — clear and schedule manually</option></select></div>
  <div><label class="f">Self-funded bills</label><select class="f" name="s_claim_selffunded_block">
    <option value="1" <?php echo setting('claim_selffunded_block','1')==='1'?'selected':''; ?>>Never claim amounts funded from members' own deposits</option>
    <option value="0" <?php echo setting('claim_selffunded_block','1')==='0'?'selected':''; ?>>Allow claiming deposit-funded bills too</option></select></div>
  <div><label class="f">Negative balances</label><select class="f" name="s_member_negative_balance">
    <option value="0" <?php echo setting('member_negative_balance','0')==='0'?'selected':''; ?>>Blocked unless the facility has an authorized credit limit</option>
    <option value="1" <?php echo setting('member_negative_balance','0')==='1'?'selected':''; ?>>Allowed</option></select></div>
  <div><label class="f">Portal SMS history for members</label><select class="f" name="s_portal_sms_history">
    <option value="1" <?php echo setting('portal_sms_history','1')==='1'?'selected':''; ?>>Shown</option>
    <option value="0" <?php echo setting('portal_sms_history','1')==='0'?'selected':''; ?>>Hidden</option></select></div>
</div>
<div style="margin-top:14px"><button class="btn success" type="submit">Save policy</button></div>
</form></div>

<div class="panel"><h2>&#128273; Facility portal security &amp; pricing policy (verification · OTP · price approval)</h2>
<form method="post"><?php echo csrf_field(); ?>
<div class="formgrid">
  <div><label class="f">OTP validity (minutes)</label><input class="f" name="s_f70_otp_expiry_mins" value="<?php echo e(setting('f70_otp_expiry_mins','10')); ?>" inputmode="numeric"></div>
  <div><label class="f">Max OTP attempts</label><input class="f" name="s_f70_otp_max_tries" value="<?php echo e(setting('f70_otp_max_tries','5')); ?>" inputmode="numeric"></div>
  <div><label class="f">Resend cooldown (seconds)</label><input class="f" name="s_f70_otp_resend_secs" value="<?php echo e(setting('f70_otp_resend_secs','60')); ?>" inputmode="numeric"></div>
  <div><label class="f">Lookups per facility / hour</label><input class="f" name="s_f70_search_rate_per_hour" value="<?php echo e(setting('f70_search_rate_per_hour','150')); ?>" inputmode="numeric"></div>
  <div><label class="f">Balance SMS after verification</label><select class="f" name="s_f70_balance_sms">
    <option value="1" <?php echo setting('f70_balance_sms','1')==='1'?'selected':''; ?>>Send the deposit-balance SMS automatically</option>
    <option value="0" <?php echo setting('f70_balance_sms','1')==='0'?'selected':''; ?>>Off</option></select></div>
  <div><label class="f">Central price approval</label><select class="f" name="s_f70_price_central_approval">
    <option value="0" <?php echo setting('f70_price_central_approval','0')==='0'?'selected':''; ?>>Off — facilities manage price lists freely</option>
    <option value="1" <?php echo setting('f70_price_central_approval','0')==='1'?'selected':''; ?>>On — facility price changes need Kisubi sign-off</option></select></div>
</div>
<div style="margin-top:14px"><button class="btn success" type="submit">Save portal policy</button></div>
</form></div>

<div class="panel" id="momo-settings"><h2>MTN MoMo Collections (member portal deposits)</h2>
<form method="post"><?php echo csrf_field(); ?>
<div class="formgrid">
  <div><label class="f">Gateway enabled</label><select class="f" name="s_momo_enabled">
    <option value="true" <?php echo setting('momo_enabled','true')==='true'?'selected':''; ?>>Enabled</option>
    <option value="false" <?php echo setting('momo_enabled','true')==='false'?'selected':''; ?>>Disabled</option></select></div>
  <div><label class="f">Mode</label><select class="f" name="s_momo_mode">
    <option value="demo" <?php echo setting('momo_mode','demo')==='demo'?'selected':''; ?>>Demo (no real money moves)</option>
    <option value="live" <?php echo setting('momo_mode','demo')==='live'?'selected':''; ?>>Live (calls MTN API)</option></select></div>
  <div><label class="f">Currency</label><input class="f" name="s_momo_currency" value="<?php echo e(setting('momo_currency','UGX')); ?>"></div>
  <div class="span2"><label class="f">API base URL</label><input class="f" name="s_momo_base_url" value="<?php echo e(setting('momo_base_url','https://sandbox.momodeveloper.mtn.com')); ?>"></div>
  <div><label class="f">Target environment</label><input class="f" name="s_momo_environment" value="<?php echo e(setting('momo_environment','sandbox')); ?>"></div>
  <div><label class="f">API User (UUID)</label><input class="f" name="s_momo_api_user" value="<?php echo e(setting('momo_api_user','')); ?>" placeholder="from Partner Portal"></div>
  <div><label class="f">API Key</label><input class="f" type="password" name="s_momo_api_key" placeholder="<?php echo setting('momo_api_key','')!==''?'******** (saved, leave blank to keep)':''; ?>"></div>
  <div><label class="f">Subscription key</label><input class="f" type="password" name="s_momo_subscription_key" placeholder="<?php echo setting('momo_subscription_key','')!==''?'******** (saved, leave blank to keep)':''; ?>"></div>
  <div><label class="f">Min amount (UGX)</label><input class="f" type="number" name="s_momo_min_amount" value="<?php echo e(setting('momo_min_amount','1000')); ?>"></div>
  <div><label class="f">Max per request (UGX)</label><input class="f" type="number" name="s_momo_max_amount" value="<?php echo e(setting('momo_max_amount','2000000')); ?>"></div>
  <div class="span2"><label class="f">Callback URL (register with MTN)</label><input class="f" name="s_momo_callback_url" value="<?php echo e(setting('momo_callback_url','')); ?>" placeholder="auto: https://yourhost/scheme/momo-callback.php"></div>
</div>
<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
  <button class="btn success" type="submit">Save MoMo settings</button>
  <button class="btn primary" type="submit" name="op" value="test" formnovalidate>Test gateway connection</button>
</div>
<?php if (post('op') === 'test'): ?><?php endif; ?>
</form></div>

<div class="panel"><h2>Recent activity log (last 40 events)</h2>
<div class="twrap" style="border:0"><table class="data">
<tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>IP</th></tr>
<?php foreach ($logs as $r): ?>
<tr><td style="white-space:nowrap"><?php echo e(date('d M Y H:i', strtotime($r['created_at']))); ?></td><td><?php echo e($r['username']); ?></td>
<td><?php echo e($r['action']); ?></td><td><?php echo e($r['record_table']); ?><?php echo $r['record_id'] ? ' #' . e($r['record_id']) : ''; ?></td><td><?php echo e($r['ip']); ?></td></tr>
<?php endforeach; if (!$logs): ?><tr><td colspan="5" style="color:#5b7c99">No activity yet.</td></tr><?php endif; ?>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
