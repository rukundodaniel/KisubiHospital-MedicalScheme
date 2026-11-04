<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_role(array('Super Admin', 'Administrator', 'Facility Admin'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$ROLES = array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User');
$hasFac = false; try { db()->query('SELECT facility_id FROM users LIMIT 1'); $hasFac = true; } catch (Exception $e) {}
$hasJob = false; try { db()->query('SELECT job_title FROM users LIMIT 1'); $hasJob = true; } catch (Exception $e) {}
$JOBTITLES = array('', 'Hospital Administrator', 'Clinic Administrator', 'Branch Administrator', 'Doctor / Clinician', 'Nurse', 'Clinical Officer', 'Laboratory Officer', 'Radiographer', 'Pharmacist', 'Theatre Officer', 'Cashier', 'Accountant', 'Claims Officer', 'Records Officer', 'Receptionist', 'Inventory Officer', 'Human Resource Officer', 'Insurance Officer', 'Procurement Officer');
$MYFID = fac_scope_fid(); /* facility-locked logins can only manage their own clinic's users */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'save') {
        $id = intval(post('id')); $un = post('username'); $pw = post('password');
        if ($un === '' || ($id === 0 && strlen($pw) < 6)) { flash('Username required; new users need a password of 6+ characters.', 'err'); redirect('users.php'); }
        $role = post('previledge'); $fid = intval(post('facility_id'));
        if ($MYFID > 0) {
            $fid = $MYFID;
            if (!in_array($role, array('Facility Admin', 'User', 'Reception'), true)) $role = 'User';
            if ($id > 0) { $chk = db()->prepare('SELECT facility_id FROM users WHERE id=?'); $chk->execute(array($id)); $cf = $chk->fetch(); if (!$cf || intval($cf['facility_id']) !== $MYFID) { flash('You can only manage users of your own facility.', 'err'); redirect('users.php'); } }
        } else {
            if (!in_array($role, array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'), true)) $role = 'User';
        }
        if ($id > 0) {
            if (strlen($pw) >= 6) {
                $sqlU = 'UPDATE users SET full_name=?, previledge=?, phone=?, active=?, password_hash=?' . ($hasFac ? ', facility_id=?' : '') . ($hasJob ? ', job_title=?' : '') . ' WHERE id=?';
                $argsU = array(post('full_name'), $role, post('phone'), post('active') === '1' ? 1 : 0, password_hash($pw, PASSWORD_DEFAULT));
                if ($hasFac) $argsU[] = $fid;
                if ($hasJob) $argsU[] = mb_substr(post('job_title'), 0, 60);
                $argsU[] = $id;
                $st = db()->prepare($sqlU);
                $st->execute($argsU);
            } else {
                $sqlU = 'UPDATE users SET full_name=?, previledge=?, phone=?, active=?' . ($hasFac ? ', facility_id=?' : '') . ($hasJob ? ', job_title=?' : '') . ' WHERE id=?';
                $argsU = array(post('full_name'), $role, post('phone'), post('active') === '1' ? 1 : 0);
                if ($hasFac) $argsU[] = $fid;
                if ($hasJob) $argsU[] = mb_substr(post('job_title'), 0, 60);
                $argsU[] = $id;
                $st = db()->prepare($sqlU);
                $st->execute($argsU);
            }
            log_action('Updated user ' . $un, 'users', $id); flash('User updated.');
        } else {
            if ($fid > 0) {
                try {
                    $fl = db()->prepare('SELECT * FROM facilities WHERE id=?'); $fl->execute(array($fid)); $ff = $fl->fetch();
                    if ($ff) {
                        if (!empty($ff['license_expires']) && substr((string)$ff['license_expires'], 0, 10) < today()) { flash('That facility\'s licence has expired — renew it before adding users.', 'err'); redirect('users.php'); }
                        if (intval($ff['max_users']) > 0 && intval(db()->query('SELECT COUNT(*) FROM users WHERE facility_id=' . intval($fid))->fetchColumn()) >= intval($ff['max_users'])) { flash('Licence limit: ' . $ff['name'] . ' allows at most ' . intval($ff['max_users']) . ' users. Raise it in the facility settings.', 'err'); redirect('users.php'); }
                    }
                } catch (Exception $lz) {}
            }
            $st = db()->prepare('INSERT INTO users (username, password_hash, full_name, previledge, phone, active, created_at' . ($hasFac ? ', facility_id' : '') . ($hasJob ? ', job_title' : '') . ') VALUES (?,?,?,?,?,?,?' . ($hasFac ? ',?' : '') . ($hasJob ? ',?' : '') . ')');
            try {
                $insA = array($un, password_hash($pw, PASSWORD_DEFAULT), post('full_name'), $role, post('phone'), 1, date('Y-m-d H:i:s'));
                if ($hasFac) $insA[] = $fid;
                if ($hasJob) $insA[] = mb_substr(post('job_title'), 0, 60);
                $st->execute($insA);
                log_action('Created user ' . $un, 'users', intval(db()->lastInsertId())); flash('User ' . $un . ' created.');
            } catch (Exception $ex) { flash('Could not create user: username may already exist.', 'err'); }
        }
        redirect('users.php');
    }
    if ($op === 'toggle') {
        if (intval(post('id')) === 1) { flash('Cannot disable the primary Super Admin.', 'err'); redirect('users.php'); }
        db()->prepare('UPDATE users SET active = 1 - active WHERE id=?')->execute(array(intval(post('id'))));
        log_action('Toggled user #' . intval(post('id')), 'users', intval(post('id'))); redirect('users.php');
    }
}

$edit = null;
if (get('a') === 'edit') { $st = db()->prepare('SELECT * FROM users WHERE id=?'); $st->execute(array(intval(get('id')))); $edit = $st->fetch(); }
$showNew = get('a') === 'new';
$rows = ($MYFID > 0 && $hasFac) ? db()->query('SELECT * FROM users WHERE facility_id=' . intval($MYFID) . ' ORDER BY id')->fetchAll() : db()->query('SELECT * FROM users ORDER BY id')->fetchAll();

$pg_title = 'Users & Roles'; include __DIR__ . '/includes/header.php';
$formRow = $edit ? $edit : array('id'=>0,'username'=>'','full_name'=>'','previledge'=>'User','phone'=>'','active'=>1,'facility_id'=>0);
if ($showNew || $edit): ?>
<div class="page-head"><h1><?php echo $edit ? 'Edit User' : 'New User'; ?></h1><a class="btn plain" href="users.php">&laquo; Back</a></div>
<div class="panel"><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?php echo e($formRow['id']); ?>">
<div class="formgrid">
  <div><label class="f">Username *</label><input class="f" name="username" value="<?php echo e($formRow['username']); ?>" <?php echo $edit?'readonly':''; ?>></div>
  <div><label class="f">Full name</label><input class="f" name="full_name" value="<?php echo e($formRow['full_name']); ?>"></div>
  <div><label class="f">Phone</label><input class="f" name="phone" value="<?php echo e($formRow['phone']); ?>"></div>
  <div><label class="f">Previledge (role)</label><select class="f" name="previledge">
    <?php foreach ($ROLES as $r): ?><option <?php echo $formRow['previledge']===$r?'selected':''; ?>><?php echo $r; ?></option><?php endforeach; ?>
  </select><label class="f" style="margin-top:6px">Job title (clinical role)</label><select class="f" name="job_title">
    <?php foreach ($JOBTITLES as $jt): ?><option <?php echo (isset($formRow['job_title']) && $formRow['job_title']===$jt)?'selected':''; ?>><?php echo e($jt); ?></option><?php endforeach; ?>
    <?php if (isset($formRow['job_title']) && $formRow['job_title'] !== '' && !in_array($formRow['job_title'], $JOBTITLES, true)): ?><option selected><?php echo e($formRow['job_title']); ?></option><?php endif; ?>
  </select></div>
  <?php if ($hasFac): ?><div><label class="f">Locked to facility</label>
    <?php if ($MYFID > 0): ?><input class="f" value="<?php echo e(fac_name($MYFID)); ?>" readonly><div style="font-size:10.5px;color:#7a94aa">Users you create stay locked to <?php echo e(fac_name($MYFID)); ?></div>
    <?php else: ?><select class="f" name="facility_id"><option value="0">&#127963; HQ / scheme-wide (not locked)</option>
      <?php foreach (fac_list() as $fo): ?><option value="<?php echo e($fo['id']); ?>" <?php echo intval($formRow['facility_id'] ?? 0)===intval($fo['id'])?'selected':''; ?>><?php echo fac_icon($fo['ftype']); ?> <?php echo e($fo['name']); ?> (<?php echo e($fo['code']); ?>)</option><?php endforeach; ?></select>
      <div style="font-size:10.5px;color:#7a94aa">A user given a facility is LOCKED to it at sign-in: username &amp; password only open that clinic/hospital's claims desk and price list.</div><?php endif; ?>
  </div><?php else: ?><input type="hidden" name="facility_id" value="0"><?php endif; ?>
  <div><label class="f"><?php echo $edit ? 'New password (leave blank = keep)' : 'Password *'; ?></label><input class="f" type="password" name="password"></div>
  <div><label class="f">Active?</label><select class="f" name="active"><option value="1" <?php echo intval($formRow['active'])===1?'selected':''; ?>>Yes</option><option value="0" <?php echo intval($formRow['active'])===0?'selected':''; ?>>No</option></select></div>
</div>
<div style="margin-top:14px"><button class="btn success" type="submit">Save user</button> <a class="btn plain" href="users.php">Cancel</a></div>
</form></div>
<?php include __DIR__ . '/includes/footer.php'; exit; endif; ?>

<div class="page-head"><div><h1>Users &amp; Roles (Permissions)</h1> <a class="btn small plain" href="users.php?a=logins" style="margin-left:8px">Sign-in activity</a>
<span class="sub">Roles: <b>Super Admin</b> owner &mdash; everything incl. clearing facility claims · <b>Administrator</b> all except settings · <b>Finance</b> money + reports · <b>Facility Admin</b> locked to one clinic/hospital · <b>Reception</b> members + SMS · <b>User</b> view dashboard</span></div>
<div class="noprint"><a class="btn success" href="users.php?a=new">+ Add user</a></div></div>
<div class="twrap"><table class="data">
<tr><th>#</th><th>Username</th><th>Full name</th><th>Previledge</th><?php if ($hasFac): ?><th>Locked to facility</th><?php endif; ?><th>Phone</th><th>Last login</th><th>Status</th><th class="noprint">Actions</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?php echo e($r['id']); ?></td><td><b><?php echo e($r['username']); ?></b></td><td><?php echo e($r['full_name']); ?></td>
<td><span class="badge <?php echo $r['previledge']==='Super Admin'?'amber':($r['previledge']==='Administrator'?'red':($r['previledge']==='Facility Admin'?'gold':'blue')); ?>"><?php echo e($r['previledge']); ?></span></td>
<?php if ($hasFac): ?><td style="font-size:12px"><?php $rfid = intval($r['facility_id'] ?? 0); echo $rfid > 0 ? fac_label($rfid) : '<span style="color:#9db8cf">HQ &#127963;</span>'; ?></td><?php endif; ?>
<td><?php echo e($r['phone']); ?></td><td><?php echo $r['last_login'] ? e(date('d M Y H:i', strtotime($r['last_login']))) : 'never'; ?></td>
<td><span class="badge <?php echo intval($r['active'])===1?'green':'grey'; ?>"><?php echo intval($r['active'])===1?'Active':'Disabled' ?></span></td>
<td class="noprint actions"><a class="btn small plain" href="users.php?a=edit&id=<?php echo e($r['id']); ?>">Edit</a>
<?php if (intval($r['id']) !== 1): ?><form style="display:inline" method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
<button class="btn small danger" type="submit"><?php echo intval($r['active'])===1?'Disable':'Enable'; ?></button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
</table></div>
<?php if (get('a') === 'logins'): $lgOK = true; try { db()->query('SELECT id FROM login_log LIMIT 1'); } catch (Exception $e) { $lgOK = false; }
if ($lgOK) { $lg = db()->query('SELECT l.username, l.at AS when_ts, l.ip, l.outcome AS ok, u.full_name, f.name AS fname FROM login_log l LEFT JOIN users u ON u.username=l.username LEFT JOIN facilities f ON f.id=u.facility_id ORDER BY l.id DESC LIMIT 300')->fetchAll(); ?>
<div class="panel" style="margin-top:14px"><h2 style="margin-top:0">&#128272; Sign-in activity — last 300 events</h2>
<table class="data"><tr><th>When</th><th>User</th><th>Facility</th><th>IP</th><th>Result</th></tr>
<?php foreach ($lg as $l): ?><tr><td style="white-space:nowrap"><?php echo e($l['when_ts']); ?></td><td><b><?php echo e($l['username']); ?></b> <?php echo e((string)$l['full_name']); ?></td><td><?php echo e($l['fname'] !== null && $l['fname'] !== '' ? $l['fname'] : 'Scheme HQ'); ?></td><td><?php echo e((string)$l['ip']); ?></td><td><span class="badge" style="background:<?php echo $l['ok']==='OK'?'#e6f6ea;color:#20603a':'#FDECEC;color:#8c2424'; ?>"><?php echo e($l['ok']); ?></span></td></tr><?php endforeach; ?>
</table></div>
<?php } else { ?><div class="panel"><p>Sign-in logging table not present yet — run <code>migrate.php</code>.</p></div><?php } ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
