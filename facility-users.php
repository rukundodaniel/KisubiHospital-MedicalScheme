<?php
/** v7.0 FACILITY USERS &amp; ROLES (spec §9 menu #11): a facility admin manages ONLY its own logins.
 *  The facility/branch assignment is fixed — staff can never move themselves (or others) across facilities. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/facility70.php';
require_role(array('Super Admin', 'Facility Admin'));
f70_ensure();
$OWNER = fac_is_owner();
$fid = $OWNER ? intval(get('facility')) : fac_scope_fid();
if ($fid <= 0 && !$OWNER) { flash('This page is for facility-managed accounts.', 'err'); redirect('facility-dashboard.php'); }
if (!$OWNER && !can(array('Facility Admin'))) { flash('Only the facility administrator manages portal accounts.', 'err'); redirect('facility-dashboard.php'); }
$f = fac_of($fid);
$FAC_ROLES = array('Facility Admin', 'Reception', 'User');
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op'); $by = isset($_SESSION['uname']) ? (string)$_SESSION['uname'] : 'admin';
    if ($op === 'add') {
        $un = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', post('username')));
        $fn = mb_substr(post('full_name'), 0, 120); $pw = (string)post('password'); $role = post('role'); $ph = preg_replace('/[^0-9+]/', '', post('phone'));
        if (strlen($un) < 4 || $fn === '') $err = 'Username (min 4 characters) and full name are required.';
        elseif (strlen($pw) < 8) $err = 'Password must be at least 8 characters.';
        elseif (!in_array($role, $FAC_ROLES, true)) $err = 'Pick a valid portal role.';
        else {
            try {
                $capQ = db()->prepare('SELECT name, max_users FROM facilities WHERE id=?'); $capQ->execute(array($fid)); $capF = $capQ->fetch();
                $capNow = intval(db()->query('SELECT COUNT(*) FROM users WHERE facility_id=' . intval($fid))->fetchColumn());
                $st = db()->prepare('SELECT id FROM users WHERE username=?'); $st->execute(array($un));
                if ($capF && intval($capF['max_users']) > 0 && $capNow >= intval($capF['max_users'])) $err = 'Licence limit: ' . $capF['name'] . ' allows at most ' . intval($capF['max_users']) . ' portal users. Raise it in the facility settings.';
                elseif ($st->fetch()) $err = 'That username already exists.';
                else {
                    $st = db()->prepare('INSERT INTO users (username, password_hash, full_name, previledge, phone, active, facility_id, job_title, created_at) VALUES (?,?,?,?,?,1,?,?,?)');
                    $st->execute(array($un, password_hash($pw, PASSWORD_DEFAULT), $fn, $role, $ph, $fid, mb_substr(post('job_title'), 0, 60), date('Y-m-d H:i:s')));
                    log_action('Facility portal user "' . $un . '" (' . $role . ') created for ' . $f['code'], 'users', intval(db()->lastInsertId()));
                    flash('Account ' . $un . ' created — locked to ' . $f['name'] . '.', 'ok'); redirect('facility-users.php?facility=' . $fid);
                }
            } catch (Throwable $e) { $err = 'Could not create the account: ' . $e->getMessage(); }
        }
    } elseif ($op === 'reset') {
        $pw = (string)post('password');
        if (strlen($pw) < 8) $err = 'New password must be at least 8 characters.';
        else {
            $st = db()->prepare('SELECT id, username, facility_id FROM users WHERE id=? AND facility_id=?'); $st->execute(array(intval(post('uid')), $fid)); $u = $st->fetch();
            if (!$u) $err = 'Account not found in your facility.';
            else { db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute(array(password_hash($pw, PASSWORD_DEFAULT), intval($u['id'])));
                log_action('Password reset for facility user ' . $u['username'] . ' by ' . $by, 'users', intval($u['id']));
                flash('Password reset for ' . $u['username'] . '.', 'ok'); redirect('facility-users.php?facility=' . $fid); }
        }
    } elseif ($op === 'toggle') {
        $st = db()->prepare('SELECT id, username, facility_id, previledge FROM users WHERE id=? AND facility_id=?'); $st->execute(array(intval(post('uid')), $fid)); $u = $st->fetch();
        if (!$u) $err = 'Account not found in your facility.';
        elseif (intval($u['id']) === intval(isset($_SESSION['uid']) ? $_SESSION['uid'] : 0)) $err = 'You cannot disable your own account.';
        else { db()->prepare('UPDATE users SET active=1-active WHERE id=?')->execute(array(intval($u['id'])));
            log_action('Facility user ' . $u['username'] . ' enabled/disabled by ' . $by, 'users', intval($u['id']));
            flash('Account state updated.', 'ok'); redirect('facility-users.php?facility=' . $fid); }
    } elseif ($op === 'edit') {
        $st = db()->prepare('SELECT id, username, facility_id FROM users WHERE id=? AND facility_id=?'); $st->execute(array(intval(post('uid')), $fid)); $u = $st->fetch();
        if (!$u) $err = 'Account not found in your facility.';
        else { $role = post('role'); if (!in_array($role, $FAC_ROLES, true)) $err = 'Pick a valid portal role.';
            else { db()->prepare('UPDATE users SET full_name=?, previledge=?, job_title=?, phone=? WHERE id=?')
                  ->execute(array(mb_substr(post('full_name'), 0, 120), $role, mb_substr(post('job_title'), 0, 60), preg_replace('/[^0-9+]/', '', post('phone')), intval($u['id'])));
                log_action('Facility user ' . $u['username'] . ' profile/role updated by ' . $by, 'users', intval($u['id']));
                flash('Account updated — facility assignment unchanged (it is fixed centrally).', 'ok'); redirect('facility-users.php?facility=' . $fid); } }
    }
}
$page_title = 'Facility Users';
include __DIR__ . '/includes/header.php';
$rows = array();
try { $st = db()->prepare('SELECT id, username, full_name, previledge, phone, job_title, active, last_login FROM users WHERE facility_id=? ORDER BY active DESC, username'); $st->execute(array($fid)); $rows = $st->fetchAll(); } catch (Throwable $e) {}
?>
<div class="panel" style="padding:12px 16px">
  <h2 style="margin:0">Portal accounts — <?php echo e($f ? $f['name'] : ''); ?></h2>
  <div style="font-size:12px;color:#5c7a94;margin:2px 0 6px">Every account here is permanently locked to this facility and branch. Central scheme administration, other facilities, deposit funding and Kisubi adjudication are <b>not</b> grantable from this page — that is by design.</div>
  <?php if ($err): ?><div style="background:#FDECEC;color:#8c2424;padding:8px 12px;border-radius:5px;font-size:13px">&#9888; <?php echo e($err); ?></div><?php endif; ?>
</div>
<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">
  <div class="panel" style="flex:2 1 420px">
    <h3 style="margin:0 0 6px;font-size:13px;color:#12476f">Accounts (<?php echo count($rows); ?>)</h3>
    <table class="data"><tr><th>User</th><th>Role</th><th>Job title</th><th>Last login</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $u): ?>
    <tr><td><b><?php echo e($u['username']); ?></b><br><span style="font-size:11px;color:#7a94aa"><?php echo e($u['full_name']); ?></span></td>
    <td><?php echo e($u['previledge']); ?></td><td style="font-size:12px"><?php echo e($u['job_title']); ?><br><span style="color:#7a94aa"><?php echo e($u['phone']); ?></span></td>
    <td style="font-size:11px;color:#5c7a94"><?php echo e(substr((string)$u['last_login'], 0, 16)); ?></td>
    <td><?php echo intval($u['active']) ? '<span style="color:#20603a;font-weight:bold">Active</span>' : '<span style="color:#8c2424;font-weight:bold">Disabled</span>'; ?></td>
    <td style="text-align:right;white-space:nowrap">
      <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="uid" value="<?php echo intval($u['id']); ?>"><input type="hidden" name="op" value="reset"><input name="password" type="password" placeholder="new password" style="width:110px;padding:4px;border:1px solid #c9d9e8;border-radius:4px;font-size:11px"><button class="btn" type="submit" style="font-size:11px;padding:4px 8px">Reset</button></form>
      <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="uid" value="<?php echo intval($u['id']); ?>"><input type="hidden" name="op" value="toggle"><button class="btn" type="submit" style="font-size:11px;padding:4px 8px"><?php echo intval($u['active']) ? 'Disable' : 'Enable'; ?></button></form>
    </td></tr>
    <?php endforeach; ?>
    </table>
  </div>
  <div class="panel" style="flex:1 1 260px">
    <h3 style="margin:0 0 6px;font-size:13px;color:#12476f">New portal account</h3>
    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="add">
      <div style="margin:6px 0"><label style="font:bold 10px 'Segoe UI',Arial;color:#5c7a94;text-transform:uppercase">Username</label><input name="username" required style="width:100%;padding:7px;border:1px solid #c9d9e8;border-radius:5px"></div>
      <div style="margin:6px 0"><label style="font:bold 10px 'Segoe UI',Arial;color:#5c7a94;text-transform:uppercase">Full name</label><input name="full_name" required style="width:100%;padding:7px;border:1px solid #c9d9e8;border-radius:5px"></div>
      <div style="margin:6px 0"><label style="font:bold 10px 'Segoe UI',Arial;color:#5c7a94;text-transform:uppercase">Job title</label><input name="job_title" style="width:100%;padding:7px;border:1px solid #c9d9e8;border-radius:5px"></div>
      <div style="margin:6px 0"><label style="font:bold 10px 'Segoe UI',Arial;color:#5c7a94;text-transform:uppercase">Phone (optional)</label><input name="phone" style="width:100%;padding:7px;border:1px solid #c9d9e8;border-radius:5px"></div>
      <div style="margin:6px 0"><label style="font:bold 10px 'Segoe UI',Arial;color:#5c7a94;text-transform:uppercase">Role</label>
        <select name="role" style="width:100%;padding:7px;border:1px solid #c9d9e8;border-radius:5px"><option>Reception</option><option>User</option><option>Facility Admin</option></select>
        <div style="font-size:10px;color:#7a94aa;margin-top:3px">Facility Admin = price lists, users, profile, audit. Reception/User = verification, billing, claims viewing.</div></div>
      <div style="margin:6px 0"><label style="font:bold 10px 'Segoe UI',Arial;color:#5c7a94;text-transform:uppercase">Password (min 8)</label><input name="password" type="password" required minlength="8" style="width:100%;padding:7px;border:1px solid #c9d9e8;border-radius:5px"></div>
      <button class="btn" type="submit" style="width:100%">Create account</button>
    </form>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
