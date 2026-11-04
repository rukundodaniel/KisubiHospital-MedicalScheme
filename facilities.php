<?php
/** v3.9 Hospitals, Clinics & Branches — the parent registry page. Every facility created here
 *  comes standard with all features (claims desk, additive price list auto-filled from the
 *  hospital base list, facility users, owner portal visibility) and can ship with a facility
 *  admin login locked to it. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_role(array('Super Admin', 'Administrator'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$FTYPES = array('Hospital', 'Medical Centre', 'Health Centre IV', 'Health Centre III', 'Clinic', 'School', 'Pharmacy', 'Health Facility', 'Branch', 'Other');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'save' || $op === 'update') {
        $id = intval(post('id'));
        $name = trim(post('name')); $code = strtoupper(trim(post('code'))); $ftype = post('ftype');
        if ($ftype === 'Other') { $otype = trim(post('ftype_other')); if ($otype !== '') $ftype = mb_substr($otype, 0, 20); }
        if (!in_array($ftype, $FTYPES) && mb_strlen($ftype) < 2) $ftype = 'Clinic';
        if ($name === '') { flash('Facility name is required.', 'err'); redirect('facilities.php' . ($id ? '?a=edit&id=' . $id : '?a=new')); }
        $parent = intval(post('parent_id')); if ($parent === $id) $parent = 0;
        $set41 = ''; $args41 = array();
        foreach (array('max_users' => intval(post('max_users')), 'license_expires' => post('license_expires') !== '' ? post('license_expires') : null) as $ck => $cv) {
            try { db()->query('SELECT ' . $ck . ' FROM facilities LIMIT 1'); $set41 .= ', ' . $ck . '=?'; $args41[] = $cv; } catch (Exception $e) {}
        }
        try {
            if ($id > 0 && $op === 'update') {
                db()->prepare('UPDATE facilities SET name=?, code=?, ftype=?, parent_id=?, address=?, phone=?, email=?, contact_person=?, status=?' . $set41 . ' WHERE id=?')
                   ->execute(array_merge(array($name, $code, $ftype, $parent, post('address'), post('phone'), post('email'), post('contact_person'), post('status')), $args41, array($id)));
                log_action('Updated facility ' . $code . ' ' . $name, 'facilities', $id);
                flash('Facility updated.', 'ok');
            } else {
                if ($code === '') $code = fac_next_code($ftype);
                $taken = db()->prepare('SELECT id FROM facilities WHERE code=?'); $taken->execute(array($code));
                if ($taken->fetch()) { flash('That short code is already used.', 'err'); redirect('facilities.php?a=new'); }
                db()->prepare('INSERT INTO facilities (name, code, ftype, parent_id, address, phone, email, contact_person, status, features, created_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute(array($name, $code, $ftype, $parent, post('address'), post('phone'), post('email'), post('contact_person'), 'Active',
                                   'claims,pricelist,users,bills,sms', date('Y-m-d H:i:s'), $_SESSION['uname']));
                $fid = intval(db()->lastInsertId());
                $np = fac_clone_std_prices($fid);
                $who = '';
                $fun = trim(post('admin_user'));
                if ($fun !== '') {
                    $fp = post('admin_pass');
                    if (strlen((string)$fp) < 6) { flash('Facility created, but the admin password needs 6+ characters — create the user from Users &amp; Roles.', 'err'); }
                    else {
                        $dup = db()->prepare('SELECT id FROM users WHERE username=?'); $dup->execute(array($fun));
                        if ($dup->fetch()) flash('Facility created, but username ' . $fun . ' already exists — assign it in Users &amp; Roles.', 'err');
                        else {
                            db()->prepare('INSERT INTO users (username, password_hash, full_name, previledge, phone, active, facility_id, created_at) VALUES (?,?,?,?,?,?,?,?)')
                               ->execute(array($fun, password_hash($fp, PASSWORD_DEFAULT), trim(post('admin_name')) !== '' ? post('admin_name') : $name . ' Admin', 'Facility Admin', '', 1, $fid, date('Y-m-d H:i:s')));
                            $who = ' Admin login <b>' . e($fun) . '</b> created (locked to this facility).';
                        }
                    }
                }
                log_action('Created facility ' . $code . ' ' . $name . ' (standard features + ' . $np . ' base price items)', 'facilities', $fid);
                $initRep = null;
                try { require_once __DIR__ . '/includes/schema41.php'; require_once __DIR__ . '/includes/clinical-core.php'; $initRep = cl41_init_facility($fid);
                     require_once __DIR__ . '/includes/schema50.php'; $w50 = cl50_init_institution($fid); $initRep['wallet'] = $w50['wallet']; } catch (Exception $w50e) { $initRep['wallet'] = ''; } try {
                     db()->prepare('UPDATE facility_prices SET effective_date=? WHERE facility_id=? AND effective_date IS NULL')->execute(array(date('Y-m-d'), $fid)); } catch (Exception $ie) {}
                flash($name . ' initialised: ' . ($initRep ? $initRep['modules'] . ' modules on, ' : '') . 'STANDARD KIT price list (' . $np . ' items), facility users & SMS, claims submission ACTIVE' . (isset($initRep['wallet']) && $initRep['wallet'] !== '' ? ', institution deposit ledger opened' : '') . '.' . $who, 'ok');
                $_SESSION['fac_init_report'] = $initRep ? array_merge($initRep, array('name' => $name, 'code' => $code, 'prices' => $np, 'fid' => $fid)) : null;
            }
            redirect('facilities.php');
        } catch (Exception $ex) { flash('Save failed: ' . $ex->getMessage(), 'err'); redirect('facilities.php'); }
    }
    if ($op === 'branding') {
        $id = intval(post('id')); $f = fac_of($id); if (!$f) redirect('facilities.php');
        $up = array(); $ar = array();
        try { db()->query('SELECT max_users FROM facilities LIMIT 1'); $up[] = 'max_users=?'; $ar[] = max(0, intval(post('max_users')));
             $up[] = 'license_expires=?'; $ar[] = post('license_expires') !== '' ? post('license_expires') : null; } catch (Exception $e) {}
        try { db()->query('SELECT deposit_funding FROM facilities LIMIT 1');
             $up[] = 'deposit_funding=?'; $ar[] = post('deposit_funding') === 'Kisubi' ? 'Kisubi' : 'Self';
             $up[] = 'credit_limit=?'; $ar[] = max(0, round((float)str_replace(',', '', (string)post('credit_limit')), 2));
             $up[] = 'member_billing=?'; $ar[] = isset($_POST['member_billing']) ? 'On' : 'Off'; } catch (Exception $e) {}
        try { db()->query('SELECT require_member_auth FROM facilities LIMIT 1');
              $up[] = 'require_member_auth=?'; $ar[] = !empty($_POST['require_member_auth']) ? 1 : 0; } catch (Exception $e) {}
        try { db()->query('SELECT auto_claim FROM facilities LIMIT 1');
              $up[] = 'auto_claim=?'; $ar[] = !empty($_POST['auto_claim']) ? 1 : 0; } catch (Exception $e) {}
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, array('png','jpg','jpeg'), true) || $_FILES['logo']['size'] > 400 * 1024) { flash('Logo must be a PNG/JPG under 400 KB.', 'err'); redirect('facilities.php?a=edit&id=' . $id); }
            $dir = __DIR__ . '/data/logos'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $fn = 'fac' . $id . '-' . date('YmdHis') . '.' . $ext; @move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $fn);
            $old = trim((string)$f['logo']); if ($old !== '' && is_file(__DIR__ . '/data/' . $old)) @unlink(__DIR__ . '/data/' . $old);
            $up[] = 'logo=?'; $ar[] = 'logos/' . $fn;
        }
        $modsOn = isset($_POST['mods']) && is_array($_POST['mods']) ? $_POST['mods'] : array();
        try { require_once __DIR__ . '/includes/clinical-core.php';
            require_once __DIR__ . '/includes/schema41.php'; cl41_init_modules_fill($id, $modsOn); foreach (cl_modules() as $k => $md) { fac_set_module($id, $k, isset($modsOn[$k]) ? 1 : 0); }
        } catch (Exception $e) {}
        if ($up) { try { db()->prepare('UPDATE facilities SET ' . implode(', ', $up) . ' WHERE id=?')->execute(array_merge($ar, array($id))); } catch (Exception $e) { flash('Save failed: ' . $e->getMessage(), 'err'); }
            log_action('Facility settings updated for ' . $f['code'] . ' (' . count($modsOn) . ' modules on)', 'facilities', $id); }
        flash('Facility settings saved — modules, licence and branding are live.', 'ok'); redirect('facilities.php?a=edit&id=' . $id);
    }
    if ($op === 'setstatus') {
        if (!fac_is_owner()) { flash('Only the scheme owner changes facility lifecycle status.', 'err'); redirect('facilities.php'); }
        $id = intval(post('id')); $f2 = fac_of($id); $ns = post('st');
        if (!$f2 || !in_array($ns, array('Active', 'Inactive', 'Suspended', 'Archived'), true)) { flash('Unknown status.', 'err'); redirect('facilities.php'); }
        db()->prepare('UPDATE facilities SET status=? WHERE id=?')->execute(array($ns, $id));
        log_action('Facility ' . $f2['code'] . ' status -> ' . $ns, 'facilities', $id);
        flash($f2['name'] . ' is now ' . $ns . '.', $ns === 'Active' ? 'ok' : 'info'); redirect('facilities.php');
    }
    if ($op === 'toggle') {
        if (!fac_is_owner()) { flash('Only the scheme owner can deactivate a facility.', 'err'); redirect('facilities.php'); }
        $id = intval(post('id')); $f = fac_of($id);
        if (!$f) redirect('facilities.php');
        db()->prepare('UPDATE facilities SET status=? WHERE id=?')->execute(array($f['status'] === 'Active' ? 'Inactive' : 'Active'), $id);
        log_action('Facility ' . $f['code'] . ' -> ' . ($f['status'] === 'Active' ? 'Inactive' : 'Active'), 'facilities', $id);
        flash($f['name'] . ' set to ' . ($f['status'] === 'Active' ? 'Inactive' : 'Active') . '.', 'info');
        redirect('facilities.php');
    }
}

$a = get('a'); $edit = null;
if ($a === 'edit' && intval(get('id')) > 0 && fac_has_tbl()) { $edit = fac_of(intval(get('id'))); }
$row = $edit ? $edit : array('id'=>0,'name'=>'','code'=>'','ftype'=>'Clinic','parent_id'=>0,'address'=>'','phone'=>'','email'=>'','contact_person'=>'','status'=>'Active');

$stats = array();
try {
    foreach (db()->query('SELECT facility_id,
        SUM(CASE WHEN status IN (\'Pending\') THEN 1 ELSE 0 END) pend,
        SUM(CASE WHEN status=\'Paid\' THEN amount_approved ELSE 0 END) paidamt,
        COUNT(*) cnt FROM facility_claims GROUP BY facility_id')->fetchAll() as $r) { $stats[intval($r['facility_id'])] = $r; }
} catch (Exception $e) {}
$usersCnt = array(); $priceCnt = array();
try { foreach (db()->query('SELECT facility_id, COUNT(*) c FROM users WHERE facility_id>0 GROUP BY facility_id')->fetchAll() as $r) $usersCnt[intval($r['facility_id'])] = $r['c']; } catch (Exception $e) {}
try { foreach (db()->query('SELECT facility_id, COUNT(*) c FROM facility_prices GROUP BY facility_id')->fetchAll() as $r) $priceCnt[intval($r['facility_id'])] = $r['c']; } catch (Exception $e) {}
$parents = fac_list(true);
$kids = array();
foreach (fac_list() as $r) { if (intval($r['parent_id']) > 0) $kids[intval($r['parent_id'])][] = $r; }

$page_title = 'Hospitals, Clinics &amp; Branches';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>&#127973; Hospitals, Clinics &amp; Branches</h1>
<?php $rep41 = !empty($_SESSION['fac_init_report']) ? $_SESSION['fac_init_report'] : null; if ($rep41): unset($_SESSION['fac_init_report']); ?>
<div class="panel" style="margin:14px 0;border:2px solid #20603a;background:#F4FBF6">
<h2 style="margin-top:0;color:#20603a">&#10003; Facility initialization report — <?php echo e($rep41['name']); ?> (<?php echo e($rep41['code']); ?>)</h2>
<table class="data" style="margin:0;width:auto"><tr><th>Step</th><th>What was set up automatically</th></tr>
<tr><td>Facility record &amp; unique code</td><td>&#10003; live on the network registry (status Active)</td></tr>
<tr><td>Price list</td><td>&#10003; <?php echo num($rep41['prices']); ?> priced lines from the base kit — additive from now on, history tracked</td></tr>
<tr><td>Hospital modules</td><td>&#10003; <?php echo num($rep41['modules']); ?> modules enabled — patients, OPD, IPD, lab, radiology, pharmacy, theatre, emergency, claims, billing, reports…</td></tr>
<tr><td>Users</td><td>&#10003; <?php echo num($rep41['users']); ?> user(s) locked to this facility</td></tr>
<tr><td>Claims submission &amp; tracking</td><td>&#10003; active — the facility can bill the scheme and follow every claim to payment</td></tr>
<tr><td>Open the environment</td><td>&#10003; <a href="institution.php?facility=<?php echo intval($rep41['fid']); ?>">institution portal</a> &middot; <a href="member-bills.php?facility=<?php echo intval($rep41['fid']); ?>">member billing</a> &middot; <a href="facility-clinical.php?facility=<?php echo intval($rep41['fid']); ?>">clinical suite</a> &middot; <a href="facility-desk.php?facility=<?php echo intval($rep41['fid']); ?>">deposit desk</a> &middot; <a href="facility-claims.php?facility=<?php echo intval($rep41['fid']); ?>">claims desk</a> &middot; <a href="pricelist.php?facility=<?php echo intval($rep41['fid']); ?>">price list</a> &middot; <a href="users.php">users</a></td></tr></table>
<p style="margin:6px 0 0;color:#20603a;font-size:12.5px">The facility is immediately usable — its staff see only their own environment; you see all of it from here.</p>
</div><?php endif; ?>

<a class="btn primary" href="facilities.php?a=new">+ New facility</a></div>

<div class="kpi-row">
  <div class="kpi"><div class="k-label">Facilities on the network</div><div class="k-value"><?php echo num(count(fac_list())); ?></div><div class="k-sub">Hospitals, clinics &amp; branches</div></div>
  <div class="kpi green"><div class="k-label">All come standard</div><div class="k-value">5</div><div class="k-sub">claims &middot; price list &middot; users &middot; bills &middot; SMS</div></div>
  <div class="kpi gold"><div class="k-label">Claims awaiting you</div><div class="k-value"><?php echo num(fac_pending_count()); ?></div><div class="k-sub"><a href="facility-claims.php">Owner desk &raquo;</a></div></div>
</div>

<?php if (!$parents): ?>
<div class="panel"><h2>No facilities yet</h2><p>Create your first hospital or clinic &mdash; it automatically gets the full standard feature kit including an additive price list prefilled with the base hospital rates and (optionally) a facility admin login locked to it.</p></div>
<?php endif; ?>

<div class="panel" style="border:1px solid #bcd6ee">
<h2 style="margin-top:0">The network <span style="font:11px Arial;color:#5b7c99">(children are shown under their parent with a marker)</span></h2>
<table class="data" style="margin:0">
<tr><th>Facility</th><th>Type</th><th>Contact</th><th class="num">Price list</th><th class="num">Users</th><th class="num">Claims</th><th>Status</th><th class="noprint">Actions</th></tr>
<?php
function fac_row($r, $depth) {
    global $stats, $usersCnt, $priceCnt;
    $s = isset($stats[$r['id']]) ? $stats[$r['id']] : null;
    $std = htmlspecialchars('Claims · Price list · Users · Bills · SMS', ENT_QUOTES);
    ?>
<tr>
 <td style="padding-left:<?php echo 10 + $depth * 26; ?>px;white-space:nowrap">
   <?php if ($depth): ?><span style="color:#9db8cf">&#8627;</span> <?php endif; ?>
   <span style="font-size:16px"><?php echo fac_icon($r['ftype']); ?></span>
   <a href="pricelist.php?facility=<?php echo e($r['id']); ?>" style="font-weight:bold;text-decoration:none"><?php echo e($r['name']); ?></a>
   <span style="color:#7a94aa;font-size:11px">&nbsp;<?php echo e($r['code']); ?></span>
   <?php if ($r['status'] !== 'Active'): ?><span class="badge">SUSPENDED</span><?php endif; ?>
   <div style="margin-top:3px" title="<?php echo $std; ?>"><span style="font-size:10px;color:#20603a;background:#e6f6ea;border:1px solid #9fd8ae;border-radius:3px;padding:1px 6px;letter-spacing:.4px">&#10003; STANDARD KIT: claims &middot; price list &middot; users &middot; bills &middot; SMS</span></div>
 </td>
 <td><?php echo fac_chip($r['ftype']); ?></td>
 <td style="font-size:12px"><?php echo e($r['contact_person']); ?><?php if ($r['phone'] !== ''): ?><br><span style="color:#7a94aa"><?php echo e($r['phone']); ?></span><?php endif; ?></td>
 <td class="num"><?php echo num(isset($priceCnt[$r['id']]) ? $priceCnt[$r['id']] : 0); ?></td>
 <td class="num"><?php echo num(isset($usersCnt[$r['id']]) ? $usersCnt[$r['id']] : 0); ?></td>
 <td class="num"><?php echo $s ? num($s['cnt']) . ' <span style="font-size:10.5px;color:#8a6410">(' . num($s['pend']) . ' pend)</span>' : '0'; ?><?php if ($s && floatval($s['paidamt']) > 0): ?><br><span style="font-size:10.5px;color:#20603a">cleared <?php echo ugx($s['paidamt']); ?></span><?php endif; ?></td>
 <td><?php echo $r['status'] === 'Active' ? '<span class="badge" style="background:#e6f6ea;color:#20603a">ACTIVE</span>' : '<span class="badge" style="background:#FDECEC;color:#8c2424">INACTIVE</span>'; ?></td>
 <td class="noprint" style="white-space:nowrap">
   <a class="btn small" href="facilities.php?a=edit&amp;id=<?php echo e($r['id']); ?>">Edit</a>
   <a class="btn small" href="facility-claims.php?facility=<?php echo e($r['id']); ?>">Claims</a>
   <?php if (fac_is_owner()): ?>
   <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="setstatus"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><input type="hidden" name="st" value="<?php echo $r['status']==='Active'?'Suspended':'Active'; ?>"><button class="btn small <?php echo $r['status']==='Active'?'gold':'success'; ?>" type="submit"><?php echo $r['status']==='Active'?'&#9203; Suspend':'&#9654; Activate'; ?></button></form>
   <?php if ($r['status'] !== 'Archived'): ?><form method="post" style="display:inline" onsubmit="return confirm('Archive <?php echo e(addslashes($r['name'])); ?>? Historical data is kept for the record.')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="setstatus"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><input type="hidden" name="st" value="Archived"><button class="btn small plain" type="submit">&#128451; Archive</button></form><?php endif; ?>
   <?php endif; ?>
 </td>
</tr>
    <?php
}
foreach ($parents as $p) {
    fac_row($p, 0);
    if (!empty($kids[$p['id']])) foreach ($kids[$p['id']] as $k) fac_row($k, 1);
}
?>
</table>
<p style="font-size:11.5px;color:#7a94aa;margin:8px 0 0">Clinics and branches appear under their parent hospital, each marked with its type icon &#127973; hospital &middot; &#9877;&#65039; clinic &middot; &#127970; branch. Facility logins are locked to their allocated facility at sign-in.</p>
</div>

<?php if ($a === 'new' || $a === 'edit'): ?>
<div class="panel" style="border:1px solid #1B6BB3">
<h2 style="margin-top:0"><?php echo $edit ? 'Edit facility' : 'New facility — full standard kit'; ?></h2>
<form method="post">
<?php if ($edit && get('a') === 'edit'): ?>
<?php require_once __DIR__ . '/includes/clinical-core.php'; $ms41 = fac_modules_state(intval($row['id'])); ?>
<div class="panel" style="margin:14px 0;border-left:4px solid #1B6BB3">
<h2 style="margin-top:0">&#9881; Modules, branding &amp; licence — <?php echo e($row['name']); ?></h2>
<form method="post" enctype="multipart/form-data"><?php echo csrf_field(); ?><input type="hidden" name="op" value="branding"><input type="hidden" name="id" value="<?php echo e($row['id']); ?>">
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px 14px;margin:8px 0">
<?php foreach (cl_modules() as $k => $md): ?><label style="font-size:12.5px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="mods[<?php echo e($k); ?>]" value="1" <?php echo intval($ms41[$k]) ? 'checked' : ''; ?>> <?php echo $md[1]; ?> <?php echo e($md[0]); ?></label><?php endforeach; ?></div>
<div class="grid grid-3" style="gap:8px;align-items:end">
  <div><label class="f">Logo (PNG/JPG ≤400 KB)</label><input class="f" type="file" name="logo" accept="image/png,image/jpeg"></div>
  <div><label class="f">Max users (0 = unlimited)</label><input class="f" name="max_users" value="<?php echo e(isset($row['max_users']) ? intval($row['max_users']) : 0); ?>" inputmode="numeric"></div>
  <div><label class="f">Licence expires</label><input class="f" type="date" name="license_expires" value="<?php echo e(isset($row['license_expires']) ? substr((string)$row['license_expires'],0,10) : ''); ?>"></div>
</div>
<?php if (isset($row['deposit_funding'])): ?>
<div class="grid grid-3" style="gap:8px;align-items:end;margin-top:8px">
  <div><label class="f">Member deposit funding model</label><select class="f" name="deposit_funding" style="margin:0">
    <option value="Self" <?php echo (string)$row['deposit_funding']==='Self'?'selected':''; ?>>Self-funded — members&rsquo; own money (NOT claimable)</option>
    <option value="Kisubi" <?php echo (string)$row['deposit_funding']==='Kisubi'?'selected':''; ?>>Kisubi pre-funded — deposit-funded services ARE claimable</option></select></div>
  <div><label class="f" style="display:inline"><input type="checkbox" name="require_member_auth" value="1" <?php echo intval(isset($row['require_member_auth']) ? $row['require_member_auth'] : 1) ? 'checked' : ''; ?>> <b>Require member authorization</b> (e-Card + OTP, as at Kisubi Hospital) before any deposit settlement</label></div>
  <div><label class="f" style="display:inline"><input type="checkbox" name="auto_claim" value="1" <?php echo intval(!isset($row['auto_claim']) || $row['auto_claim'] !== '' ? (isset($row['auto_claim']) ? $row['auto_claim'] : 1) : 0) ? 'checked' : ''; ?>> <b>Auto-claim settled bills</b> — every settled deposit-funded bill is put on a claim and sent to Kisubi Hospital automatically</label></div>
  <div><label class="f">Authorized credit facility per member (UGX, 0 = no negative balances)</label><input class="f" name="credit_limit" value="<?php echo e(isset($row['credit_limit']) ? (float)$row['credit_limit'] : 0); ?>" inputmode="decimal" style="margin:0"></div>
  <div style="align-self:center"><label style="font-size:12.5px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="member_billing" value="1" <?php echo (string)$row['member_billing']!=='Off'?'checked':''; ?>> Member billing &amp; settlement module ON for this facility</label></div>
</div><?php endif; ?>
<button class="btn primary small" type="submit" style="margin-top:8px">Save facility settings</button>
<span style="font-size:11.5px;color:#5b7c99;margin-left:8px">switching a module off hides it for that facility's staff — data is preserved</span>
</form>
</div>
<?php endif; ?>
<?php echo csrf_field(); ?><input type="hidden" name="op" value="<?php echo $edit ? 'update' : 'save'; ?>"><input type="hidden" name="id" value="<?php echo e($row['id']); ?>">
<div class="formgrid" style="grid-template-columns:repeat(3,1fr)">
  <div><label class="f">Facility name *</label><input class="f" name="name" value="<?php echo e($row['name']); ?>" placeholder="e.g. Nsanji Health Centre IV"></div>
  <div><label class="f">Short code</label><input class="f" name="code" value="<?php echo e($row['code']); ?>" placeholder="auto if blank"></div>
  <div><label class="f">Category</label><select class="f" name="ftype" id="ftypeSel"><?php foreach ($FTYPES as $t): ?><option <?php echo $row['ftype']===$t?'selected':''; ?>><?php echo $t; ?></option><?php endforeach; ?></select>
     <input class="f" name="ftype_other" id="ftypeOther" placeholder="specify (e.g. Maternity Home)" style="margin-top:5px;display:none">
     <div id="ftypePrev" style="font-size:11.5px;color:#5b7c99;margin-top:4px"></div></div>
  <div><label class="f">Parent hospital (for clinics/branches)</label><select class="f" name="parent_id"><option value="0">&mdash; none (top-level) &mdash;</option>
     <?php foreach (fac_list() as $f): if ($f['ftype'] === 'Hospital' && intval($f['id']) !== intval($row['id'])): ?><option value="<?php echo e($f['id']); ?>" <?php echo intval($row['parent_id'])==intval($f['id'])?'selected':''; ?>><?php echo fac_icon('Hospital'); ?> <?php echo e($f['name']); ?></option><?php endif; endforeach; ?></select></div>
  <div><label class="f">Phone (for claim &amp; payment SMS)</label><input class="f" name="phone" value="<?php echo e($row['phone']); ?>" placeholder="07..."></div>
  <div><label class="f">Contact person</label><input class="f" name="contact_person" value="<?php echo e($row['contact_person']); ?>"></div>
  <div><label class="f">Email</label><input class="f" name="email" value="<?php echo e($row['email']); ?>"></div>
  <div style="grid-column:span 2"><label class="f">Address</label><input class="f" name="address" value="<?php echo e($row['address']); ?>"></div>
  <?php if ($edit): ?><div><label class="f">Status</label><select class="f" name="status"><?php foreach (array('Active','Inactive','Suspended','Archived') as $s): ?><option <?php echo $row['status']===$s?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?></select>
  <label class="f" style="margin-top:8px">Max users (licence, 0=unlimited)</label><input class="f" name="max_users" value="<?php echo e(isset($row['max_users']) ? $row['max_users'] : 0); ?>" inputmode="numeric">
  <label class="f" style="margin-top:6px">Licence expires</label><input class="f" type="date" name="license_expires" value="<?php echo e(isset($row['license_expires']) ? substr((string)$row['license_expires'],0,10) : ''); ?>"></div><?php endif; ?>
</div>
<?php if (!$edit): ?>
<fieldset class="fset" style="margin-top:10px;background:#F7FBFF"><legend>Facility admin login (optional)</legend>
<div class="formgrid" style="grid-template-columns:repeat(3,1fr)">
  <div><label class="f">Username</label><input class="f" name="admin_user" autocomplete="off" placeholder="leave blank to skip"></div>
  <div><label class="f">Password (6+)</label><input class="f" type="password" name="admin_pass" autocomplete="new-password"></div>
  <div><label class="f">Display name</label><input class="f" name="admin_name" placeholder="defaults to facility Admin"></div>
</div>
<p style="font-size:11.5px;color:#5b7c99;margin:4px 0 0">This login is <b>locked</b> to the new facility &mdash; it submits claims and edits only its own price list; the owner clears claims from the dashboard.</p>
</fieldset>
<?php endif; ?>
<div style="margin-top:12px"><button class="btn primary" type="submit"><?php echo $edit ? 'Save changes' : 'Create facility with standard kit'; ?></button>
<a class="btn plain" href="facilities.php">Cancel</a></div>
</form>
</div>
<script>
(function(){var s=document.getElementById('ftypeSel'),p=document.getElementById('ftypePrev'),o=document.getElementById('ftypeOther');
function up(){var m={'Hospital':'&#127973; Hospital — a parent facility','Medical Centre':'&#127973; Medical centre — full services, can hang under a hospital','Health Centre IV':'&#129658; Health Centre IV — government-level care','Health Centre III':'&#129658; Health Centre III','Clinic':'&#9877;&#65039; Clinic — can hang under a hospital','Branch':'&#127970; Branch — satellite of a hospital','Other':'&#127968; Type the category name below'};
p.innerHTML=m[s.value]||'';o.style.display=(s.value==='Other')?'block':'none';}s.addEventListener('change',up);up();})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
