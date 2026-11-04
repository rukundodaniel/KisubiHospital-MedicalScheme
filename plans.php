<?php
/** v5.0 CORE INSURANCE — plans, policies, beneficiary enrolment, renewals and an eligibility
 *  tester (spec §8). Plans are built here; settlement & claims consume the eligibility engine. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/wallet-core.php';
require_once __DIR__ . '/includes/insurance-core.php';
require_role(array('Super Admin', 'Administrator'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9
$page_title = 'Insurance Centre';
if (!ins_has_tbl()) { include __DIR__ . '/includes/header.php';
    echo '<div class="panel" style="border:2px solid #8a6410;background:#FFF6E0"><h2>Insurance module not installed</h2><p>Run <a href="migrate.php">migrate.php</a> once (additive), delete it, and reload.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $op = post('op');
    if ($op === 'plan') {
        $id = intval(post('id'));
        $d = array(trim(post('plan_code')), trim(post('plan_name')), post('plan_type'), (float)post('premium'), post('premium_cycle'),
            (float)post('annual_limit'), (float)post('per_visit_limit'), (float)post('per_service_limit'), (float)post('copay_percent'),
            (float)post('deductible'), intval(post('waiting_days')), post('referral_req') === '1' ? 1 : 0, post('preauth_req') === '1' ? 1 : 0,
            post('dependant_cover') === '1' ? 1 : 0, trim(post('categories')), trim(post('exclusions')), trim(post('renewal_terms')), post('status'));
        try {
            if ($id > 0) { db()->prepare('UPDATE insurance_plans SET plan_code=?,plan_name=?,plan_type=?,premium=?,premium_cycle=?,annual_limit=?,per_visit_limit=?,per_service_limit=?,copay_percent=?,deductible=?,waiting_days=?,referral_req=?,preauth_req=?,dependant_cover=?,categories=?,exclusions=?,renewal_terms=?,status=? WHERE id=?')->execute(array_merge($d, array($id))); }
            else {
                if ($d[0] === '') $d[0] = 'PLN-' . strtoupper(substr(md5((string)mt_rand()), 0, 5));
                db()->prepare('INSERT INTO insurance_plans (plan_code,plan_name,plan_type,premium,premium_cycle,annual_limit,per_visit_limit,per_service_limit,copay_percent,deductible,waiting_days,referral_req,preauth_req,dependant_cover,categories,exclusions,renewal_terms,status,created_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute(array_merge($d, array(date('Y-m-d H:i:s'), $_SESSION['uname'])));
            }
            log_action(($id ? 'Updated' : 'Created') . ' insurance plan ' . $d[1], 'insurance_plans', max($id, intval(db()->lastInsertId())));
            flash('Plan <b>' . e($d[1]) . '</b> saved.', 'ok');
        } catch (Exception $e) { flash('Save failed (plan code must be unique): ' . e($e->getMessage()), 'err'); }
        redirect('plans.php#a=' . post('back_tab', 'plans'));
    }
    if ($op === 'policy') {
        $id = intval(post('id')); $pid = intval(post('plan_id'));
        $pl = ins_plan($pid); if (!$pl) { flash('Pick a valid plan.', 'err'); redirect('plans.php#a=policies'); }
        $eno = strtoupper(trim(post('policy_no')));
        try {
            if ($id > 0) {
                db()->prepare('UPDATE insurance_policies SET plan_id=?,holder_type=?,facility_id=?,member_id=?,holder_name=?,effective_date=?,expiry_date=?,premium_arrangement=?,seats=?,status=?,note=? WHERE id=?')
                   ->execute(array($pid, post('holder_type'), intval(post('facility_id')), intval(post('member_id')), trim(post('holder_name')),
                       post('effective'), post('expiry'), trim(post('premium_arr')), intval(post('seats')), post('status'), trim(post('note')), $id));
                flash('Policy updated.', 'ok');
            } else {
                $pno = $eno !== '' ? $eno : ins_policy_no($pid);
                db()->prepare('INSERT INTO insurance_policies (policy_no, plan_id, holder_type, facility_id, member_id, holder_name, effective_date, expiry_date, premium_arrangement, seats, status, note, created_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute(array($pno, $pid, post('holder_type'), intval(post('facility_id')), intval(post('member_id')), trim(post('holder_name')),
                       post('effective'), post('expiry'), trim(post('premium_arr')), intval(post('seats')), 'Active', trim(post('note')), date('Y-m-d H:i:s'), $_SESSION['uname']));
                $id = intval(db()->lastInsertId());
                flash('Policy <b>' . e($pno) . '</b> issued on ' . e($pl['plan_name']) . '.', 'ok');
            }
            log_action('Saved policy #' . $id . ' (' . post('holder_type') . ')', 'insurance_policies', $id);
        } catch (Exception $e) { flash('Could not save: ' . e($e->getMessage()), 'err'); }
        redirect('plans.php#a=policies');
    }
    if ($op === 'renew') {
        $st = db()->prepare('SELECT * FROM insurance_policies WHERE id=?'); $st->execute(array(intval(post('id')))); $p = $st->fetch();
        if (!$p) { flash('Policy not found.', 'err'); redirect('plans.php#a=policies'); }
        $new = date('Y-m-d', strtotime((string)$p['expiry_date'] . ' +1 year +1 day'));
        db()->prepare("UPDATE insurance_policies SET expiry_date=?, status='Active', note=? WHERE id=?")
           ->execute(array($new, trim((string)$p['note'] . ' Renewed to ' . $new . ' by ' . $_SESSION['uname'] . ' on ' . today() . '.')), intval($p['id']));
        try { $st = db()->prepare('SELECT COUNT(*) FROM policy_beneficiaries WHERE policy_id=? AND status=\'Active\''); $st->execute(array(intval($p['id']))); $n = intval($st->fetchColumn()); } catch (Exception $e) { $n = 0; }
        if (function_exists('sms50_notify')) sms50_notify('renewal', array('name' => $p['holder_name'] !== '' ? $p['holder_name'] : 'policy holder', 'member_no' => $p['policy_no'], 'inst' => (int)$p['facility_id'] > 0 ? fac_name($p['facility_id']) : 'scheme', 'expiry' => $n . ' enrolled beneficiaries extended'), intval($p['facility_id']));
        log_action('Renewed policy ' . $p['policy_no'] . ' to ' . $new, 'insurance_policies', intval($p['id']));
        flash('Policy ' . e($p['policy_no']) . ' renewed — expiry now ' . e($new) . '.', 'ok'); redirect('plans.php#a=policies');
    }
    if ($op === 'enrol') {
        $r = ins_enrol(intval(post('policy_id')), intval(post('member_id')), post('category'), intval(post('sponsor_id')), post('relationship'), post('cover_limit'), post('start'), post('expiry'));
        flash($r['msg'], $r['ok'] ? 'ok' : 'err'); redirect('plans.php#a=policies&pol=' . intval(post('policy_id')));
    }
    if ($op === 'unenrol') {
        db()->prepare("UPDATE policy_beneficiaries SET status='Removed' WHERE id=?")->execute(array(intval(post('id'))));
        log_action('Removed beneficiary enrolment #' . post('id'), 'policy_beneficiaries', intval(post('id')));
        flash('Enrolment removed (history retained).', 'info'); redirect('plans.php#a=policies&pol=' . intval(post('policy_id')));
    }
}

$tab = get('a') === 'policies' ? 'policies' : 'plans';
$plans = ins_plans(); $policies = ins_policies();
$polFocus = intval(get('pol'));
$editing = null; if (intval(get('edit')) > 0) { $editing = ins_plan(intval(get('edit'))); }
$polEditing = null; if (intval(get('pedit')) > 0) { $st = db()->prepare('SELECT * FROM insurance_policies WHERE id=?'); $st->execute(array(intval(get('pedit')))); $polEditing = $st->fetch(); }
include __DIR__ . '/includes/header.php';
$sel = function ($name, $cur, $opts) { echo '<select class="f" name="' . $name . '" style="margin:0">'; foreach ($opts as $v) echo '<option ' . ((string)$cur === (string)$v ? 'selected ' : '') . '>' . e($v) . '</option>'; echo '</select>'; };
?>
<div class="page-head"><h1>&#9878;&#65039; Insurance Centre — plans, policies &amp; eligibility</h1>
 <span class="sub">The central payer's benefit engine: cover rules configured here decide what settlements can claim and how claims adjudicate.</span>
 <span class="noprint" style="float:right"><a class="btn small <?php echo $tab === 'plans' ? 'primary' : 'plain'; ?>" href="plans.php#a=plans">Plans</a>
 <a class="btn small <?php echo $tab === 'policies' ? 'primary' : 'plain'; ?>" href="plans.php#a=policies">Policies &amp; enrolments</a>
 <a class="btn small plain" href="plans.php?a=test">Eligibility tester</a></span></div>

<?php if (get('a') === 'test'):
$tm = intval(get('member')); $tf = intval(get('facility')); $tres = null;
if ($tm > 0 && $tf > 0) { $tlines = array(array('name' => 'Sample covered service', 'cat' => 'OPD', 'qty' => 1, 'price' => (float)get('amount'))); $tres = ins_eligibility($tm, $tf, $tlines, get('date') !== '' ? get('date') : today()); }
?>
<div class="panel"><h2 style="margin-top:0">&#129658; Eligibility tester</h2>
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><input type="hidden" name="a" value="test">
 <div><label class="f">Beneficiary</label><select class="f" name="member" style="margin:0;max-width:280px"><option value="0">choose…</option>
  <?php foreach (db()->query("SELECT id, member_no, full_names FROM members WHERE status='Active' ORDER BY full_names LIMIT 400") as $mo): ?><option value="<?php echo e($mo['id']); ?>" <?php echo $tm === intval($mo['id']) ? 'selected' : ''; ?>><?php echo e($mo['member_no']); ?> — <?php echo e($mo['full_names']); ?></option><?php endforeach; ?></select></div>
 <div><label class="f">Institution</label><select class="f" name="facility" style="margin:0"><option value="0">—</option><?php foreach (fac_list() as $o): ?><option value="<?php echo e($o['id']); ?>" <?php echo $tf === intval($o['id']) ? 'selected' : ''; ?>><?php echo e($o['name']); ?></option><?php endforeach; ?></select></div>
 <div><label class="f">Service date</label><input class="f" type="date" name="date" value="<?php echo e(get('date') ?: today()); ?>" style="margin:0"></div>
 <div><label class="f">Amount UGX</label><input class="f" type="number" name="amount" value="<?php echo e(get('amount') ?: '100000'); ?>" style="width:130px;margin:0"></div>
 <button class="btn primary" type="submit">Check cover</button></form>
<?php if ($tres): ?>
<div style="margin-top:12px;padding:10px 14px;border-radius:6px;background:<?php echo $tres['ok'] && $tres['covered'] > 0 ? '#E6F6EA' : '#FFF6E0'; ?>">
 <b>Result:</b> gross <?php echo ugx($tres['gross']); ?> &middot; payable by insurer <b><?php echo ugx($tres['covered']); ?></b> &middot; deductible <?php echo ugx($tres['deductible']); ?>
 &middot; copay <?php echo ugx($tres['copay']); ?> &middot; excluded <?php echo ugx($tres['excluded']); ?>
 <?php if ($tres['enrolment']): ?><br><span style="font-size:12px">via <?php echo e($tres['enrolment']['policy_no']); ?> / <?php echo e($tres['enrolment']['plan_name']); ?><?php if ($tres['annual_remaining'] !== null): ?> &middot; annual remaining <?php echo ugx($tres['annual_remaining']); ?><?php endif; ?><?php endif; ?>
 <?php foreach ($tres['reasons'] as $rr): ?><br><span style="font-size:12px;color:#8a6410">&#9888; <?php echo e($rr); ?></span><?php endforeach; ?>
</div><?php endif; ?>
<p style="font-size:12px;color:#5b7c99;margin-bottom:0">The same engine runs live at the settlement desks and at claim review.</p></div>
<?php elseif ($tab === 'plans'): ?>
<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">
<div class="panel" style="flex:2;min-width:420px"><h2 style="margin-top:0">Benefit plans</h2>
<div class="twrap" style="border:0"><table class="data" style="margin:0">
<tr><th>Code</th><th>Plan</th><th>Type</th><th>Limits (annual / visit / service)</th><th>Cost-sharing</th><th>Cover</th><th>Status</th><th></th></tr>
<?php foreach ($plans as $p): ?>
<tr><td><b><?php echo e($p['plan_code']); ?></b></td><td><?php echo e($p['plan_name']); ?></td><td><?php echo e($p['plan_type']); ?></td>
 <td style="font-size:11.5px"><?php echo (float)$p['annual_limit'] > 0 ? num($p['annual_limit']) : '&infin;'; ?> / <?php echo (float)$p['per_visit_limit'] > 0 ? num($p['per_visit_limit']) : '&infin;'; ?> / <?php echo (float)$p['per_service_limit'] > 0 ? num($p['per_service_limit']) : '&infin;'; ?></td>
 <td style="font-size:11.5px"><?php echo num($p['copay_percent']); ?>% copay &middot; ded. <?php echo num($p['deductible']); ?><?php echo intval($p['waiting_days']) > 0 ? ' &middot; wait ' . intval($p['waiting_days']) . 'd' : ''; ?></td>
 <td style="font-size:11px;max-width:180px"><?php echo $p['categories'] !== '' && $p['categories'] !== null ? 'incl: ' . e($p['categories']) : 'all categories'; ?><?php echo trim((string)$p['exclusions']) !== '' ? '<br><span style="color:#8c2424">excl: ' . e($p['exclusions']) . '</span>' : ''; ?></td>
 <td><b style="color:<?php echo $p['status'] === 'Active' ? '#20603a' : '#8c2424'; ?>"><?php echo e($p['status']); ?></b></td>
 <td><a class="btn small plain" href="plans.php?edit=<?php echo e($p['id']); ?>#a=plans">Edit</a></td></tr>
<?php endforeach; if (!$plans) echo '<tr><td colspan="8" style="color:#7a94aa">No plans yet — create the first one on the right.</td></tr>'; ?>
</table></div></div>

<div class="panel" style="flex:1;min-width:330px" id="newplan"><h2 style="margin-top:0"><?php echo $editing ? 'Edit plan #' . intval($editing['id']) : '+ New plan'; ?></h2>
<form method="post">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="plan"><input type="hidden" name="back_tab" value="plans">
<input type="hidden" name="id" value="<?php echo $editing ? intval($editing['id']) : '0'; ?>">
<label class="f">Plan code</label><input class="f" name="plan_code" value="<?php echo e($editing['plan_code'] ?? ''); ?>" placeholder="auto if blank">
<label class="f">Plan name *</label><input class="f" name="plan_name" required value="<?php echo e($editing['plan_name'] ?? ''); ?>">
<label class="f">Type</label><?php $t = $editing['plan_type'] ?? 'Group'; ?><select class="f" name="plan_type" style="margin:0"><?php foreach (array('Individual','Group','Institutional','School scheme','Corporate benefits','Family','Medical','Custom institutional') as $v): ?><option <?php echo $t === $v ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?></select>
<div style="display:flex;gap:8px"><div style="flex:1"><label class="f">Premium UGX</label><input class="f" type="number" min="0" name="premium" value="<?php echo e($editing['premium'] ?? '0'); ?>"></div>
<div style="flex:1"><label class="f">Cycle</label><select class="f" name="premium_cycle" style="margin:0"><?php foreach (array('Annual','Quarterly','Monthly','One-off') as $v): ?><option <?php echo (($editing['premium_cycle'] ?? 'Annual') === $v) ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div></div>
<div style="display:flex;gap:8px"><div style="flex:1"><label class="f">Annual limit (0=∞)</label><input class="f" type="number" min="0" name="annual_limit" value="<?php echo e($editing['annual_limit'] ?? '0'); ?>"></div>
<div style="flex:1"><label class="f">Per visit</label><input class="f" type="number" min="0" name="per_visit_limit" value="<?php echo e($editing['per_visit_limit'] ?? '0'); ?>"></div>
<div style="flex:1"><label class="f">Per service</label><input class="f" type="number" min="0" name="per_service_limit" value="<?php echo e($editing['per_service_limit'] ?? '0'); ?>"></div></div>
<div style="display:flex;gap:8px"><div style="flex:1"><label class="f">Co-pay %</label><input class="f" type="number" min="0" max="100" step="0.5" name="copay_percent" value="<?php echo e($editing['copay_percent'] ?? '0'); ?>"></div>
<div style="flex:1"><label class="f">Deductible</label><input class="f" type="number" min="0" name="deductible" value="<?php echo e($editing['deductible'] ?? '0'); ?>"></div>
<div style="flex:1"><label class="f">Waiting days</label><input class="f" type="number" min="0" name="waiting_days" value="<?php echo e($editing['waiting_days'] ?? '0'); ?>"></div></div>
<label class="f">Covered categories (CSV — blank = all)</label><input class="f" name="categories" value="<?php echo e($editing['categories'] ?? ''); ?>" placeholder="OPD,Laboratory,Pharmacy,Radiology,Admission,Dental,Eye,Theatre">
<label class="f">Exclusions (CSV keywords)</label><input class="f" name="exclusions" value="<?php echo e($editing['exclusions'] ?? ''); ?>" placeholder="cosmetic,experimental">
<label style="font-size:12px"><input type="checkbox" name="referral_req" value="1" <?php echo intval($editing['referral_req'] ?? 0) ? 'checked' : ''; ?>> referral required &nbsp; <input type="checkbox" name="preauth_req" value="1" <?php echo intval($editing['preauth_req'] ?? 0) ? 'checked' : ''; ?>> pre-auth required &nbsp; <input type="checkbox" name="dependant_cover" value="1" <?php echo intval($editing['dependant_cover'] ?? 1) ? 'checked' : ''; ?>> dependants covered</label>
<label class="f">Renewal conditions</label><input class="f" name="renewal_terms" value="<?php echo e($editing['renewal_terms'] ?? ''); ?>">
<label class="f">Status</label><select class="f" name="status" style="margin:0"><?php foreach (array('Active','Closed') as $v): ?><option <?php echo (($editing['status'] ?? 'Active') === $v) ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?></select>
<div style="margin-top:12px"><button class="btn primary" type="submit"><?php echo $editing ? 'Save changes' : 'Create plan'; ?></button> <?php if ($editing): ?><a class="btn small plain" href="plans.php">cancel edit</a><?php endif; ?></div>
</form></div></div>

<?php else: ?>
<div class="panel" style="border:1px solid #1B6BB3"><h2 style="margin-top:0"><?php echo $polEditing ? 'Edit policy #' . intval($polEditing['id']) : '+ Issue a policy'; ?></h2>
<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="policy"><input type="hidden" name="id" value="<?php echo $polEditing ? intval($polEditing['id']) : '0'; ?>">
 <div><label class="f">Policy no (blank = auto)</label><input class="f" name="policy_no" value="<?php echo e($polEditing['policy_no'] ?? ''); ?>" style="width:170px;margin:0"></div>
 <div><label class="f">Plan *</label><select class="f" name="plan_id" required style="margin:0"><option value="0">choose…</option>
  <?php foreach ($plans as $p): ?><option value="<?php echo e($p['id']); ?>" <?php echo intval($polEditing['plan_id'] ?? 0) === intval($p['id']) ? 'selected' : ''; ?>><?php echo e($p['plan_code']); ?> — <?php echo e($p['plan_name']); ?></option><?php endforeach; ?></select></div>
 <div><label class="f">Holder</label><select class="f" name="holder_type" style="margin:0"><?php foreach (array('Institution','Group','Individual') as $v): ?><option <?php echo (($polEditing['holder_type'] ?? 'Institution') === $v) ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
 <div><label class="f">Institution</label><select class="f" name="facility_id" style="margin:0"><option value="0">— none —</option>
  <?php foreach (fac_list() as $o): ?><option value="<?php echo e($o['id']); ?>" <?php echo intval($polEditing['facility_id'] ?? 0) === intval($o['id']) ? 'selected' : ''; ?>><?php echo e($o['name']); ?></option><?php endforeach; ?></select></div>
 <div><label class="f">Holder name</label><input class="f" name="holder_name" value="<?php echo e($polEditing['holder_name'] ?? ''); ?>" style="width:170px;margin:0"></div>
 <div><label class="f">Effective *</label><input class="f" type="date" name="effective" required value="<?php echo e($polEditing['effective_date'] ?? today()); ?>" style="margin:0"></div>
 <div><label class="f">Expiry *</label><input class="f" type="date" name="expiry" required value="<?php echo e($polEditing['expiry_date'] ?? date('Y-m-d', strtotime('+1 year'))); ?>" style="margin:0"></div>
 <div><label class="f">Premium / funding</label><input class="f" name="premium_arr" value="<?php echo e($polEditing['premium_arrangement'] ?? ''); ?>" style="width:150px;margin:0"></div>
 <div><label class="f">Seats</label><input class="f" type="number" min="0" name="seats" value="<?php echo e($polEditing['seats'] ?? '0'); ?>" style="width:80px;margin:0"></div>
 <div style="flex:1"><label class="f">Note</label><input class="f" name="note" value="<?php echo e($polEditing['note'] ?? ''); ?>" style="margin:0"></div>
 <button class="btn primary" type="submit"><?php echo $polEditing ? 'Save' : 'Issue policy'; ?></button>
 <?php if ($polEditing): ?><a class="btn small plain" href="plans.php#a=policies">cancel</a><?php endif; ?>
</form></div>

<div class="panel"><h2 style="margin-top:0">Policies</h2>
<table class="data" style="margin:0"><tr><th>Policy</th><th>Plan</th><th>Holder</th><th>Period</th><th>Seats / used</th><th>Status</th><th></th></tr>
<?php foreach ($policies as $p):
 $en = db()->prepare("SELECT COUNT(*) FROM policy_beneficiaries WHERE policy_id=? AND status='Active'"); $en->execute(array(intval($p['id']))); $en = intval($en->fetchColumn()); ?>
<tr><td><b><?php echo e($p['policy_no']); ?></b><?php echo $p['holder_name'] !== '' ? '<br><span style="font-size:11px;color:#5b7c99">' . e($p['holder_name']) . '</span>' : ''; ?></td>
 <td><?php echo e($p['plan_name']); ?></td>
 <td><?php echo e($p['holder_type']); ?><?php echo intval($p['facility_id']) > 0 ? ' &middot; ' . e(fac_name($p['facility_id'])) : ''; ?></td>
 <td style="font-size:12px"><?php echo e($p['effective_date']); ?> → <b><?php echo e($p['expiry_date']); ?></b></td>
 <td><?php echo $en; ?><?php echo intval($p['seats']) > 0 ? ' / ' . intval($p['seats']) : ''; ?></td>
 <td><b style="color:<?php echo today() > $p['expiry_date'] ? '#8c2424' : ($p['status'] === 'Active' ? '#20603a' : '#7a94aa'); ?>"><?php echo today() > $p['expiry_date'] && $p['status'] === 'Active' ? 'EXPIRED' : e($p['status']); ?></b></td>
 <td style="white-space:nowrap"><a class="btn small plain" href="plans.php?a=policies&pedit=<?php echo e($p['id']); ?>#a=policies">Edit</a>
  <a class="btn small gold" href="plans.php?a=policies&pol=<?php echo e($p['id']); ?>#a=policies">Enrolments (<?php echo $en; ?>)</a>
  <form method="post" style="display:inline" onsubmit="return confirm('Renew one year from current expiry?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="renew"><input type="hidden" name="id" value="<?php echo e($p['id']); ?>"><button class="btn small success" type="submit">&#8635; Renew</button></form></td></tr>
<?php endforeach; if (!$policies) echo '<tr><td colspan="7" style="color:#7a94aa">No policies yet.</td></tr>'; ?>
</table></div>

<?php if ($polFocus > 0):
$st = db()->prepare('SELECT * FROM insurance_policies WHERE id=?'); $st->execute(array($polFocus)); $pf = $st->fetch();
if ($pf): $pl = ins_plan($pf['plan_id']); ?>
<div class="panel" style="border:2px solid #1B6BB3"><h2 style="margin-top:0">Beneficiaries on <?php echo e($pf['policy_no']); ?> — <?php echo e($pl['plan_name'] ?? ''); ?></h2>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px">
<form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;flex:1">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="enrol"><input type="hidden" name="policy_id" value="<?php echo intval($pf['id']); ?>">
 <div><label class="f">Member *</label><select class="f" name="member_id" required style="min-width:220px;margin:0"><option value="0">choose…</option>
  <?php foreach (db()->query("SELECT id, member_no, full_names FROM members WHERE status='Active' ORDER BY full_names LIMIT 400") as $mo): ?><option value="<?php echo e($mo['id']); ?>"><?php echo e($mo['member_no']); ?> — <?php echo e($mo['full_names']); ?></option><?php endforeach; ?></select></div>
 <div><label class="f">Category</label><select class="f" name="category" style="margin:0"><?php foreach (array('Principal','Spouse','Child','Dependant','Student','Staff','Other') as $v): ?><option><?php echo $v; ?></option><?php endforeach; ?></select></div>
 <div><label class="f">Relationship</label><input class="f" name="relationship" style="width:120px;margin:0" placeholder="e.g. wife">
 <label class="f">Sponsor (member id)</label><input class="f" type="number" name="sponsor_id" style="width:120px;margin:0" placeholder="optional"></div>
 <div><label class="f">Personal limit (0=plan)</label><input class="f" type="number" min="0" name="cover_limit" value="0" style="width:120px;margin:0"></div>
 <div><label class="f">Cover from</label><input class="f" type="date" name="start" style="margin:0" value="<?php echo e($pf['effective_date']); ?>"></div>
 <div><label class="f">Cover to (blank=policy)</label><input class="f" type="date" name="expiry" style="margin:0" value="<?php echo e($pf['expiry_date']); ?>"></div>
 <button class="btn small primary" type="submit">+ Enrol</button>
</form></div>
<table class="data" style="margin:0"><tr><th>Member</th><th>Category</th><th>Relationship</th><th>Limit</th><th>Used</th><th>Cover</th><th>Status</th><th></th></tr>
<?php $st = db()->prepare("SELECT pb.*, m.member_no, m.full_names FROM policy_beneficiaries pb JOIN members m ON m.id=pb.member_id WHERE pb.policy_id=? ORDER BY pb.id DESC"); $st->execute(array($polFocus));
foreach ($st->fetchAll() as $r): ?>
<tr><td><b><?php echo e($r['member_no']); ?></b> — <?php echo e($r['full_names']); ?></td><td><?php echo e($r['category']); ?></td><td><?php echo e($r['relationship']); ?></td>
 <td><?php echo (float)$r['cover_limit'] > 0 ? num($r['cover_limit']) : 'plan'; ?></td><td><?php echo num($r['used_amount']); ?></td>
 <td style="font-size:11.5px"><?php echo e((string)$r['start_date']); ?> → <?php echo e((string)$r['expiry_date']); ?></td><td><?php echo e($r['status']); ?></td>
 <td><form method="post" onsubmit="return confirm('Remove enrolment? (history kept)')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="unenrol"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><input type="hidden" name="policy_id" value="<?php echo intval($pf['id']); ?>"><button class="btn small plain" type="submit">Remove</button></form></td></tr>
<?php endforeach; ?>
</table></div>
<?php endif; endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
