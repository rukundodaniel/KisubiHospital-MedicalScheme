<?php
/** v7.0 FACILITY PROFILE (spec §9 menu #12): own contact/service details, read-only scheme posture. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/facility70.php';
require_login();
f70_ensure();
$OWNER = fac_is_owner();
$fid = fac_scope_fid(); if ($OWNER) $fid = intval(get('facility')) ?: $fid;
if ($fid <= 0) { flash('No facility is attached to your login.', 'err'); redirect('facility-dashboard.php'); }
if (!$OWNER) fac_lock($fid);
if (!$OWNER && !can(array('Facility Admin'))) { flash('Only the facility administrator edits the profile.', 'err'); redirect('facility-dashboard.php'); }
$f = fac_of($fid);
if (!$f) { flash('Facility not found.', 'err'); redirect('facility-dashboard.php'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ph = preg_replace('/[^0-9+, ]/', '', post('phone'));
    $em = trim((string)post('email'));
    if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) { flash('That email address is not valid.', 'err'); }
    else {
        db()->prepare('UPDATE facilities SET address=?, phone=?, email=?, contact_person=? WHERE id=?')
           ->execute(array(mb_substr(post('address'), 0, 200), mb_substr($ph, 0, 30), mb_substr($em, 0, 120), mb_substr(post('contact_person'), 0, 120), $fid));
        log_action('Facility profile updated (contact block) for ' . $f['code'], 'facilities', $fid);
        flash('Profile saved. Scheme posture (funding, features, codes) stays under Kisubi central administration.', 'ok');
        redirect('facility-profile.php?facility=' . $fid);
    }
}
$page_title = 'Facility Profile';
include __DIR__ . '/includes/header.php';
$feat = array_filter(explode(',', (string)$f['features']));
?>
<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">
<div class="panel" style="flex:1 1 380px"><h2 style="margin:0 0 4px">Facility profile</h2>
 <p style="font-size:12px;color:#5c7a94;margin-top:0">Update the details printed on receipts and used for Kisubi correspondence.</p>
 <form method="post"><?php echo csrf_field(); ?>
  <?php foreach (array('Contact person' => array('contact_person', $f['contact_person']), 'Phone(s)' => array('phone', $f['phone']), 'Email' => array('email', $f['email']), 'Address' => array('address', $f['address'])) as $lbl => $d): ?>
  <div style="margin:8px 0"><label style="font:bold 10px 'Segoe UI',Arial;color:#5c7a94;text-transform:uppercase"><?php echo e($lbl); ?></label>
   <input name="<?php echo e($d[0]); ?>" value="<?php echo e($d[1]); ?>" style="width:100%;padding:8px;border:1px solid #c9d9e8;border-radius:5px" <?php echo $d[0] === 'address' ? '' : ''; ?>></div>
  <?php endforeach; ?>
  <button class="btn" type="submit" style="margin-top:6px">Save profile</button>
 </form>
</div>
<div class="panel" style="flex:1 1 300px"><h3 style="margin:0 0 6px;font-size:13px;color:#12476f">Scheme posture — read only</h3>
 <table class="data" style="margin:0">
 <tr><th>Code</th><td><?php echo e($f['code']); ?></td></tr>
 <tr><th>Type</th><td><?php echo e($f['ftype']); ?></td></tr>
 <tr><th>Parent / branch of</th><td><?php $p = intval($f['parent_id']) ? fac_of(intval($f['parent_id'])) : null; echo $p ? e($p['name']) . ' <span style="color:#7a94aa">(this is a branch)</span>' : '— (parent institution)'; ?></td></tr>
 <tr><th>Status</th><td><?php echo e($f['status']); ?></td></tr>
 <tr><th>Deposit funding model</th><td><?php echo e(strtoupper((string)(f70_colhas('facilities', 'deposit_funding') ? $f['deposit_funding'] : 'Self'))); ?> <span style="font-size:11px;color:#7a94aa">— set by Kisubi central</span></td></tr>
 <tr><th>Portal modules enabled</th><td><?php echo $feat ? e(implode(', ', $feat)) : '—'; ?></td></tr>
 <tr><th>Member authorisation</th><td><?php echo f70_colhas('facilities', 'require_member_auth') && intval($f['require_member_auth']) ? 'required (e-Card/OTP/portal)' : 'not required'; ?></td></tr>
 <tr><th>Logo</th><td><?php echo $f['logo'] !== '' ? '<img src="fac-logo.php?id=' . $fid . '" style="height:34px;vertical-align:middle" alt="logo">' : 'none — request from Kisubi central'; ?></td></tr>
 </table>
 <p style="font-size:11px;color:#7a94aa;margin:8px 0 0">Anything affecting money rules (funding model, billing policy, features, licence) is changed only by Kisubi Hospital's central administration on the facility record.</p>
</div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
