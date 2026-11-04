<?php
/** v7.0 MEMBER VERIFICATION & CLEARING (spec §3/§4): e-card + secure OTP, masked data, balance SMS. */
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
$err = ''; $info = ''; $view = null; $pending = null; $tok = '';
$tab = get('tab', 'ecard');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op'); $by = isset($_SESSION['uname']) ? (string)$_SESSION['uname'] : 'staff';
    if ($op === 'search') {
        $res = f70_member_lookup($fid, post('q'));
        if (!$res['ok']) $err = $res['msg']; else $pending = $res['member'];
        $tab = post('method') === 'otp' ? 'otp' : 'ecard';
    } elseif ($op === 'otp_issue') {
        $res = f70_otp_issue($fid, intval(post('mid')), $by);
        if (!$res['ok']) $err = $res['msg'];
        else { $info = $res['msg']; $tok = (string)$res['token']; $_SESSION['f70_vtok'][$fid] = $res['token']; }
        if (!$pending) { $q = intval(post('mid')); $stq = db()->prepare('SELECT * FROM members WHERE id=?'); $stq->execute(array($q)); $pending = $stq->fetch(); }
        $tab = 'otp';
    } elseif ($op === 'otp_confirm') {
        $tok = post('vtok') !== '' ? post('vtok') : (isset($_SESSION['f70_vtok'][$fid]) ? (string)$_SESSION['f70_vtok'][$fid] : '');
        $res = f70_confirm($fid, $tok, post('code'), $by);
        if (!$res['ok']) $err = $res['msg']; else { $view = $res['summary']; $view['token'] = $tok; unset($_SESSION['f70_vtok'][$fid]); }
    } elseif ($op === 'ecard_verify') {
        $res = f70_ecard_pass($fid, intval(post('mid')), post('card'), $by);
        if (!$res['ok']) $err = $res['msg']; else $view = $res['summary'];
    }
}
$page_title = 'Member Verification';
include __DIR__ . '/includes/header.php';
?>
<div class="panel">
  <h2>Member verification &amp; clearing</h2>
  <p style="font-size:12px;color:#5c7a94;margin-top:0">Identify the member before servicing. One-time codes go to the <b>member's own phone</b> — staff can never see or read them out. Lookups are rate limited and every attempt is logged.</p>
  <div style="display:flex;gap:6px;margin:10px 0">
    <a href="facility-verify.php?facility=<?php echo $fid; ?>&tab=ecard" style="text-decoration:none;padding:7px 14px;border-radius:5px;font:bold 12px 'Segoe UI',Arial;<?php echo $tab === 'ecard' ? 'background:#1B6BB3;color:#fff' : 'background:#EAF2FA;color:#12476f' ?>">&#128179; Method A — E-card</a>
    <a href="facility-verify.php?facility=<?php echo $fid; ?>&tab=otp" style="text-decoration:none;padding:7px 14px;border-radius:5px;font:bold 12px 'Segoe UI',Arial;<?php echo $tab === 'otp' ? 'background:#1B6BB3;color:#fff' : 'background:#EAF2FA;color:#12476f' ?>">&#128241; Method B — Phone + OTP</a>
  </div>
  <?php if ($err): ?><div style="background:#FDECEC;border:1px solid #eabcbc;color:#8c2424;border-radius:5px;padding:9px 12px;font-size:13px;margin:8px 0">&#9888; <?php echo e($err); ?></div><?php endif; ?>
  <?php if ($info): ?><div style="background:#E6F6EA;border:1px solid #bfe3c9;color:#20603a;border-radius:5px;padding:9px 12px;font-size:13px;margin:8px 0">&#10003; <?php echo e($info); ?></div><?php endif; ?>
<?php
if ($view) {
    echo '<div style="border:2px solid #20603a;border-radius:8px;padding:14px;background:#F7FCF8;margin:6px 0">'
       . '<div style="font:bold 11px \'Segoe UI\',Arial;letter-spacing:.6px;color:#20603a">&#10003; VERIFIED — ' . e($view['method']) . ' · ' . e($view['verified_at']) . '</div>'
       . '<div style="display:flex;flex-wrap:wrap;gap:0;margin-top:8px">';
    $fields = array('Member name' => $view['name'], 'Member number' => $view['member_no'], 'E-card number' => $view['card_no'] !== '' ? $view['card_no'] : 'not enrolled',
        'Registered phone' => $view['phone_masked'], 'Member status' => $view['status'], 'Scheme' => $view['scheme'],
        'Available deposit balance' => ugx($view['balance']), 'Eligibility / restrictions' => $view['restrict'] !== '' ? $view['restrict'] : 'none — cleared for services');
    foreach ($fields as $k => $v) echo '<div style="flex:1 1 45%;min-width:220px;padding:6px 10px 6px 0"><div style="font:bold 9px \'Segoe UI\',Arial;letter-spacing:.6px;color:#7a94aa;text-transform:uppercase">' . e($k) . '</div><div style="font:15px Georgia,serif;color:#12476f">' . e($v) . '</div></div>';
    echo '</div><div style="margin-top:8px;font-size:12px;color:#5c7a94">Balance SMS to member: <b>' . e($view['sms']) . '</b> (once per verification — never duplicated).</div>'
       . '<div style="margin-top:10px;display:flex;gap:8px">'
       . '<a class="btn" style="text-decoration:none;padding:7px 14px" href="member-bills.php?facility=' . $fid . '&member=' . intval(isset($view['mid']) ? $view['mid'] : 0) . '&vk=' . e((string)(isset($view['token']) ? $view['token'] : '')) . '">Open Billing &amp; Settlement for this member</a>'
       . '<a class="btn" style="text-decoration:none;padding:7px 14px;background:#EAF2FA;color:#12476f" href="facility-verify.php?facility=' . $fid . '">Verify another member</a></div></div>';
} elseif ($pending) {
    $mid = intval($pending['id']);
    echo '<div class="panel" style="background:#EAF2FA;border:1px solid #bcd4e8;padding:10px 12px;margin:4px 0">'
       . '<b>' . e($pending['full_names']) . '</b> · ' . e($pending['member_no']) . ' · status ' . e($pending['status']) . ' · phone ' . e(f70_mask_phone($pending['phone_no']))
       . '<div style="font-size:12px;color:#5c7a94;margin-top:3px">Member found in the central registry — complete the verification below. Full contact details are not disclosed.</div></div>';
    echo '<form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:10px 0">' . csrf_field() . '<input type="hidden" name="mid" value="' . $mid . '">'
       . '<input type="hidden" name="vtok" value="' . e($tok) . '">';
    if ($tab === 'otp') {
        echo '<div><div style="font:bold 10px \'Segoe UI\',Arial;color:#5c7a94;text-transform:uppercase;margin-bottom:3px">One-time password</div>'
           . '<button class="btn" name="op" value="otp_issue" type="submit" style="padding:7px 12px">Send OTP to member\'s phone</button>'
           . '<div style="font-size:11px;color:#7a94aa;max-width:340px;margin-top:4px">The code is sent to the member. Ask them to key it in themselves — staff must never ask to see the SMS.</div></div>'
           . '<div><div style="font:bold 10px \'Segoe UI\',Arial;color:#5c7a94;text-transform:uppercase;margin-bottom:3px">Member keys the code</div>'
           . '<input name="code" inputmode="numeric" maxlength="6" placeholder="••••••" style="width:110px;padding:8px;border:1px solid #c9d9e8;border-radius:5px;font:16px monospace;letter-spacing:6px;text-align:center">'
           . '<button class="btn" name="op" value="otp_confirm" type="submit" style="padding:7px 12px;margin-left:6px">Verify</button></div>';
    } else {
        echo '<div><div style="font:bold 10px \'Segoe UI\',Arial;color:#5c7a94;text-transform:uppercase;margin-bottom:3px">E-card number (type or scan)</div>'
           . '<input name="card" style="width:220px;padding:8px;border:1px solid #c9d9e8;border-radius:5px;font:14px monospace" placeholder="card number presented" autocomplete="off">'
           . '<button class="btn" name="op" value="ecard_verify" type="submit" style="padding:7px 12px;margin-left:6px">Verify e-card</button></div>'
           . '<div style="font-size:11px;color:#7a94aa;max-width:420px">Checked against the enrolled e-card in the central registry; the member must be Active. Prefer a phone OTP if the card is unreadable.</div>';
    }
    echo '<input type="hidden" name="method" value="' . ($tab === 'otp' ? 'otp' : 'ecard') . '"></form>';
}
echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 2px">' . csrf_field()
   . '<input name="q" value="' . e(isset($_POST['q']) ? (string)$_POST['q'] : '') . '" placeholder="e-card number or registered phone" style="flex:1 1 300px;padding:9px 11px;border:1px solid #c9d9e8;border-radius:5px">'
   . '<select name="method" style="padding:8px;border:1px solid #c9d9e8;border-radius:5px"><option value="ecard">via E-card</option><option value="otp"' . ($tab === 'otp' ? ' selected' : '') . '>via phone + OTP</option></select>'
   . '<button class="btn" name="op" value="search" type="submit">Search central registry</button></form>';
?>
</div>
<div class="panel"><h3 style="margin:0 0 6px;font-size:13px;color:#12476f">Recent verifications at this facility</h3>
<?php
$rows = array();
try { $st = db()->prepare("SELECT v.*, m.member_no, m.full_names FROM member_verifications v LEFT JOIN members m ON m.id=v.member_id WHERE v.facility_id=? ORDER BY v.id DESC LIMIT 15"); $st->execute(array($fid)); $rows = $st->fetchAll(); } catch (Throwable $e) {}
if (!$rows) echo '<p style="color:#5c7a94;font-size:13px">No verifications yet.</p>';
else { echo '<table class="data"><tr><th>When</th><th>Member</th><th>Method</th><th>Result</th><th>Attempts</th><th>Balance shown</th><th>Balance SMS</th><th>Staff</th></tr>';
foreach ($rows as $v) {
    $col = $v['status'] === 'Verified' ? '#20603a' : ($v['status'] === 'Pending' ? '#8a6410' : '#8c2424');
    echo '<tr><td>' . e(substr((string)$v['issued_at'], 0, 16)) . '</td><td><b>' . e($v['full_names']) . '</b> <span style="color:#7a94aa">' . e($v['member_no']) . '</span></td><td>' . e(strtoupper((string)$v['method'])) . '</td><td style="color:' . $col . ';font-weight:bold">' . e($v['status']) . '</td><td>' . intval($v['attempts']) . '</td><td>' . ugx((float)$v['balance']) . '</td><td>' . (intval($v['balance_sms']) ? 'sent once' : '—') . '</td><td>' . e($v['by_user']) . '</td></tr>';
} echo '</table>'; }
?>
<p style="font-size:11px;color:#7a94aa;margin:8px 0 0">OTP policy: valid <?php echo f70_otp_mins(); ?> min · max <?php echo f70_otp_tries(); ?> attempts (then invalidated) · resend cooldown <?php echo f70_resend_secs(); ?>s · single-use · lookup rate limit <?php echo f70_search_limit(); ?>/hour. Policy keys are central-admin settings (<code>f70_*</code> in Settings) — facility staff cannot change them.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
