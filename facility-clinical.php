<?php
/** v4.1 Clinical Suite — every facility's own working environment: patient registration,
 *  OPD / IPD / emergency encounters, departmental order sets (consultation, nursing, lab,
 *  radiology, pharmacy, theatre, admission), discharge and one-click claim from the episode.
 *  Facility logins see ONLY their facility; the scheme owner can step into any facility. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/clinical-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));

if (!fac_has_tbl()) { $page_title = 'Clinical Suite'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>Facility module not installed</h2><p>Run <a href="migrate.php">migrate.php</a> once.</p></div>'; include __DIR__ . '/includes/footer.php'; exit; }
if (!cl_ready()) { $page_title = 'Clinical Suite'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>Clinical suite tables not installed yet</h2><p>Run <a href="migrate.php">migrate.php</a> once — it adds the patient, encounter, order and claim-workflow tables without touching existing data.</p></div>'; include __DIR__ . '/includes/footer.php'; exit; }

$lockedFid = fac_scope_fid();
$GF = intval($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['facility']) ? $_POST['facility'] : get('facility'));
if ($lockedFid > 0) $GF = $lockedFid; else fac_lock(0);
if ($GF <= 0) { $ls = fac_list(); $GF = count($ls) ? intval($ls[0]['id']) : 0; }
if ($GF <= 0) { $page_title = 'Clinical Suite'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>No facilities yet</h2><p><a href="facilities.php?a=new">Create a hospital or clinic</a> first.</p></div>'; include __DIR__ . '/includes/footer.php'; exit; }
$F = fac_of($GF);
if ($lockedFid > 0 && $F && $F['status'] !== 'Active') { $page_title = 'Clinical Suite'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>Facility ' . e($F['status']) . '</h2><p>' . e($F['name']) . ' is currently ' . e($F['status']) . ' on the scheme — the clinical environment is read-only-disabled until Kisubi HQ re-activates it. No new patients, encounters or claims can be created.</p></div>'; include __DIR__ . '/includes/footer.php'; exit; }
$lic = cl_license_ok($F);
if (!$lic[0]) { $page_title = 'Clinical Suite'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>Licence suspended</h2><p>' . e($lic[1]) . '</p></div>'; include __DIR__ . '/includes/footer.php'; exit; }
$MODS = fac_modules_state($GF);
$OWNER = fac_is_owner() || can(array('Administrator', 'Finance'));
$by = $_SESSION['uname'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    /* ---------- patients ---------- */
    if ($op === 'patient') {
        $pid = intval(post('pid'));
        $data = array('full_names' => trim(post('full_names')), 'gender' => in_array(post('gender'), array('Male','Female'), true) ? post('gender') : 'Female',
            'age_years' => max(0, intval(str_replace(',', '', post('age_years')))), 'phone' => preg_replace('/[^0-9+ ]/', '', post('phone')),
            'national_id' => trim(post('national_id')), 'address' => trim(post('address')), 'next_of_kin' => trim(post('next_of_kin')),
            'member_id' => intval(post('member_id')), 'status' => post('status') === 'Inactive' ? 'Inactive' : 'Active');
        if ($data['full_names'] === '') { flash('Patient name is required.', 'err'); redirect('facility-clinical.php?facility=' . $GF . '&tab=patients'); }
        $r = cl_pt_save($GF, $pid, $data, $by);
        flash($r[1], $r[0] ? 'ok' : 'err');
        redirect('facility-clinical.php?facility=' . $GF . '&tab=patients' . ($r[0] && isset($r[2]) ? '&enc=new&pt=' . intval($r[2]) : ''));
    }
    /* ---------- encounter ---------- */
    if ($op === 'open_enc') {
        $r = cl_enc_open($GF, intval(post('patient_id')), post('enc_type'), post('department'), trim(post('clinician')), trim(post('enc_note')), trim(post('room_bed')), $by);
        flash($r[1], $r[0] ? 'ok' : 'err');
        redirect('facility-clinical.php?facility=' . $GF . ($r[0] ? '&tab=encounters&enc=' . intval($r[2]) : '&tab=encounters'));
    }
    if ($op === 'order') {
        $e = cl_enc_of(intval(post('enc')), $GF); if (!$e) { flash('Encounter not found.', 'err'); redirect('facility-clinical.php?facility=' . $GF . '&tab=encounters'); }
        $cat = post('category'); $item = ''; $unit = 'each'; $qty = 1; $price = 0.0;
        $pidPick = intval(post('price_id'));
        if ($pidPick > 0) {
            $st = db()->prepare('SELECT * FROM facility_prices WHERE id=? AND facility_id=?'); $st->execute(array($pidPick, $GF)); $p = $st->fetch();
            if (!$p) { $lp = fac_price_lookup($GF, post('custom_name')); if ($lp) { $p = $lp; } }
            if ($p) { $cat = $p['category']; $item = $p['item_name']; $unit = $p['unit']; $qty = (float)str_replace(',', '', post('qty')) ?: 1; $price = (float)$p['price']; }
        }
        if ($item === '') { $item = trim(post('custom_name')); $unit = trim(post('custom_unit')) !== '' ? trim(post('custom_unit')) : 'each';
            $qty = (float)str_replace(',', '', post('custom_qty')); if ($qty <= 0) $qty = 1; $price = (float)str_replace(',', '', post('custom_price'));
            if ($item !== '') $cat = in_array($cat, array('Consultation','Nursing','Laboratory','Radiology','Pharmacy','Procedure','Theatre','Admission','Other'), true) ? $cat : 'Other'; }
        if ($price <= 0) { $lp41 = fac_price_lookup($GF, $item); if ($lp41) { $price = (float)$lp41['price']; if ($unit === 'each' && trim((string)$lp41['unit']) !== '') $unit = $lp41['unit']; } }
        if ($item === '') { flash('Pick a price-list service or type a custom one.', 'err'); redirect('facility-clinical.php?facility=' . $GF . '&tab=encounters&enc=' . intval($e['id'])); }
        $r = cl_enc_add_order($e, $cat, $item, $unit, $qty, $price, $by);
        flash($r[1], $r[0] ? 'ok' : 'err');
        redirect('facility-clinical.php?facility=' . $GF . '&tab=encounters&enc=' . intval($e['id']));
    }
    if ($op === 'bed_nights') {
        $e = cl_enc_of(intval(post('enc')), $GF); if (!$e) redirect('facility-clinical.php?facility=' . $GF . '&tab=encounters');
        $n = max(1, min(60, intval(post('nights'))));
        $p = null; foreach (db()->query('SELECT * FROM facility_prices WHERE facility_id=' . intval($GF) . " AND item_name LIKE 'Ward bed%' LIMIT 1")->fetchAll() as $x) $p = $x;
        if (!$p) $p = fac_price_lookup($GF, 'Ward bed');
        $pr = $p ? (float)$p['price'] : 0.0;
        db()->prepare('INSERT INTO clinical_orders (encounter_id, facility_id, patient_id, category, item_name, unit, qty, price, status, ordered_by, ordered_at, billable) VALUES (?,?,?,?,?,?,?,?,\'Completed\',?,0,1)')
           ->execute(array($e['id'], $GF, $e['patient_id'], 'Admission', 'Ward bed — ' . $n . ' night(s) (' . date('d M', strtotime($e['started_at'])) . '–' . date('d M Y') . ')', 'per night', $n, $pr, $by));
        flash($n . ' bed-night(s) billed at ' . ugx($pr) . ' each.', 'ok');
        redirect('facility-clinical.php?facility=' . $GF . '&tab=encounters&enc=' . intval($e['id']));
    }
    if ($op === 'order_state') {
        $r = cl_enc_mark_order(intval(post('enc')), intval(post('oid')), post('to'), trim(post('result')), $by);
        flash($r[1], $r[0] ? 'ok' : 'err');
        redirect('facility-clinical.php?facility=' . $GF . '&tab=encounters&enc=' . intval(post('enc')));
    }
    if ($op === 'discharge') {
        $r = cl_enc_discharge(intval(post('enc')), $by);
        flash($r[1], $r[0] ? 'ok' : 'err');
        redirect('facility-clinical.php?facility=' . $GF . '&tab=encounters&enc=' . intval(post('enc')));
    }
    if ($op === 'enc_claim') {
        $r = cl_enc_make_claim(intval(post('enc')), post('mode') === 'submit' ? 'submit' : 'draft', $by);
        flash($r[1], $r[0] ? 'ok' : 'err');
        redirect('facility-clinical.php?facility=' . $GF . '&tab=encounters&enc=' . intval(post('enc')));
    }
}

/* ---------- data ---------- */
$TAB = in_array(get('tab'), array('patients','encounters'), true) ? get('tab') : 'patients';
$q = trim(get('q'));
$patients = array();
$stP = db()->prepare('SELECT * FROM patients WHERE facility_id=?' . ($q !== '' ? ' AND (full_names LIKE ? OR patient_no LIKE ? OR phone LIKE ? OR national_id LIKE ?)' : '') . ' ORDER BY id DESC LIMIT 200');
$argsP = array($GF); if ($q !== '') { $argsP = array_merge($argsP, array("%$q%","%$q%","%$q%","%$q%")); }
$stP->execute($argsP); $patients = $stP->fetchAll();
$ptSel = 0; if (get('enc') === 'new' && intval(get('pt')) > 0) $ptSel = intval(get('pt'));
$editPt = null; if (intval(get('editpt')) > 0) { $stE = db()->prepare('SELECT * FROM patients WHERE id=? AND facility_id=?'); $stE->execute(array(intval(get('editpt')), $GF)); $editPt = $stE->fetch(); }

$encs = array(); $ENC = null; $orders = array(); $estats = null;
$stE = db()->prepare('SELECT e.*, p.full_names, p.patient_no FROM encounters e JOIN patients p ON p.id=e.patient_id WHERE e.facility_id=?' . ($q !== '' && $TAB === 'encounters' ? ' AND (e.enc_no LIKE ? OR p.full_names LIKE ?)' : '') . ' ORDER BY (e.status=\'Open\') DESC, e.id DESC LIMIT 200');
$argsE = array($GF); if ($q !== '' && $TAB === 'encounters') $argsE = array_merge($argsE, array("%$q%","%$q%"));
$stE->execute($argsE); $encs = $stE->fetchAll();
if (intval(get('enc')) > 0) {
    $ENC = cl_enc_of(intval(get('enc')), $GF);
    if ($ENC) { $orders = cl_enc_orders(intval($ENC['id'])); $estats = cl_enc_stats(intval($ENC['id']));
        if ($ENC['claim_id'] > 0) { $stC = db()->prepare('SELECT id,claim_no,status,amount_billed FROM facility_claims WHERE id=?'); $stC->execute(array(intval($ENC['claim_id']))); $ENC['claim'] = $stC->fetch(); } }
}
$priceItems = array(); foreach (fac_price_items($GF) as $p) $priceItems[$p['category']][] = $p;
$memberOpts = array();
try { $memberOpts = db()->query("SELECT id, member_no, full_names FROM members WHERE status='Active' ORDER BY full_names LIMIT 400")->fetchAll(); } catch (Exception $e) {}
$openEnc = 0; $billedTotal = 0.0;
foreach ($encs as $x) { if ($x['status'] === 'Open') $openEnc++; }
$srow = db()->prepare("SELECT COALESCE(SUM(price*qty),0) t FROM clinical_orders WHERE facility_id=? AND status IN ('Billed','Claimed','Completed')"); $srow->execute(array($GF)); $billedTotal = (float)$srow->fetchColumn();

$page_title = 'Clinical Suite — ' . $F['name'];
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><h1><?php echo fac_chip($F['ftype']); ?> Clinical Suite — <?php echo e($F['name']); ?></h1>
    <span class="sub">This environment serves <?php echo e($F['name']); ?> only — <?php echo $lockedFid > 0 ? 'your login is locked here.' : 'owner view, switching facilities below.' ?></span></div>
  <?php if (!$lockedFid): ?>
  <form method="get" style="display:inline-flex;gap:6px;align-items:center">
    <select class="f" name="facility" onchange="this.form.submit()" style="margin:0;padding:6px 8px">
      <?php foreach (fac_list() as $fo): ?><option value="<?php echo e($fo['id']); ?>" <?php echo intval($fo['id'])===$GF?'selected':''; ?>><?php echo fac_icon($fo['ftype']); ?> <?php echo e($fo['name']); ?></option><?php endforeach; ?>
    </select><input type="hidden" name="tab" value="<?php echo e($TAB); ?>"></form>
  <?php endif; ?>
</div>
<div class="kpi-row">
  <div class="kpi"><div class="k-label">Patients registered</div><div class="k-value"><?php echo num(count($patients) > 199 ? db()->query('SELECT COUNT(*) FROM patients WHERE facility_id=' . intval($GF))->fetchColumn() : count($patients)); ?></div><div class="k-sub">this facility</div></div>
  <div class="kpi teal"><div class="k-label">Open encounters</div><div class="k-value"><?php echo num($openEnc); ?></div><div class="k-sub">OPD · IPD · Emergency</div></div>
  <div class="kpi gold"><div class="k-label">Billed via clinical</div><div class="k-value"><?php echo ugx($billedTotal); ?></div><div class="k-sub">completed + claimed lines</div></div>
</div>
<p style="margin:2px 0 10px">
  <a class="btn <?php echo $TAB==='patients'?'primary':''; ?>" href="facility-clinical.php?facility=<?php echo $GF; ?>&tab=patients">&#128101; Patient registry</a>
  <a class="btn <?php echo $TAB==='encounters'?'primary':''; ?>" href="facility-clinical.php?facility=<?php echo $GF; ?>&tab=encounters">&#129658; Encounters &amp; orders</a>
  <a class="btn plain" href="facility-desk.php?facility=<?php echo $GF; ?>">&#128740; Deposit desk</a>
  <a class="btn plain" href="facility-claims.php<?php echo $lockedFid>0?'':'?facility='.$GF; ?>">&#9878;&#65039; Claims</a>
  <a class="btn plain" href="pricelist.php?facility=<?php echo $GF; ?>">&#128203; Price list</a>
</p>
<?php if ($TAB === 'patients'): ?>
<div class="grid grid-2">
  <div class="panel">
    <h2 style="margin-top:0"><?php echo $editPt ? 'Edit patient — ' . e($editPt['patient_no']) : ($MODS['registry'] ? 'Register a patient' : 'Registry module switched off'); ?></h2>
    <?php if (!$MODS['registry']): ?><p style="color:#8a6410">The owner has switched off patient registration for this facility.</p>
    <?php else: ?>
    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="patient"><input type="hidden" name="facility" value="<?php echo $GF; ?>">
      <?php if ($editPt): ?><input type="hidden" name="pid" value="<?php echo e($editPt['id']); ?>"><?php endif; ?>
      <div class="grid grid-2" style="gap:8px">
        <div><label class="f">Full names *</label><input class="f" name="full_names" value="<?php echo e($editPt ? $editPt['full_names'] : post('full_names')); ?>" required></div>
        <div><label class="f">National ID / passport</label><input class="f" name="national_id" value="<?php echo e($editPt ? $editPt['national_id'] : ''); ?>"></div>
        <div><label class="f">Gender</label><select class="f" name="gender"><option <?php echo $editPt && $editPt['gender']==='Female'?'selected':''; ?>>Female</option><option <?php echo $editPt && $editPt['gender']==='Male'?'selected':''; ?>>Male</option></select></div>
        <div><label class="f">Age (years)</label><input class="f" name="age_years" value="<?php echo e($editPt ? $editPt['age_years'] : ''); ?>" inputmode="numeric"></div>
        <div><label class="f">Phone</label><input class="f" name="phone" value="<?php echo e($editPt ? $editPt['phone'] : ''); ?>"></div>
        <div><label class="f">Next of kin</label><input class="f" name="next_of_kin" value="<?php echo e($editPt ? $editPt['next_of_kin'] : ''); ?>"></div>
        <div style="grid-column:1/-1"><label class="f">Address</label><input class="f" name="address" value="<?php echo e($editPt ? $editPt['address'] : ''); ?>"></div>
        <div><label class="f">Scheme member (optional link)</label><select class="f" name="member_id"><option value="0">— none —</option>
          <?php foreach ($memberOpts as $mo): ?><option value="<?php echo e($mo['id']); ?>" <?php echo $editPt && intval($editPt['member_id'])===intval($mo['id'])?'selected':''; ?>><?php echo e($mo['member_no']); ?> — <?php echo e($mo['full_names']); ?></option><?php endforeach; ?></select></div>
        <div><label class="f">Status</label><select class="f" name="status"><option <?php echo $editPt && $editPt['status']==='Active'?'selected':''; ?>>Active</option><option <?php echo $editPt && $editPt['status']==='Inactive'?'selected':''; ?>>Inactive</option></select></div>
      </div>
      <button class="btn primary" type="submit"><?php echo $editPt ? 'Save changes' : 'Register patient'; ?></button>
      <?php if ($editPt): ?><a class="btn plain" href="facility-clinical.php?facility=<?php echo $GF; ?>&tab=patients">Cancel</a><?php endif; ?>
    </form>
    <?php endif; ?>
  </div>
  <div class="panel">
    <h2 style="margin-top:0">Patients at <?php echo e($F['name']); ?>
      <form method="get" style="float:right;margin-top:-4px"><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="tab" value="patients">
      <input class="f" name="q" value="<?php echo e($q); ?>" placeholder="name, number, phone, NIN" style="width:170px;margin:0;padding:5px 8px;display:inline-block"></form></h2>
    <div class="twrap" style="border:0;max-height:420px;overflow:auto"><table class="data" style="margin:0">
      <tr><th>Patient no</th><th>Name</th><th>Age/sex</th><th>Phone</th><th>Member</th><th class="noprint"></th></tr>
      <?php if (!$patients): ?><tr><td colspan="6" style="color:#5b7c99">No patients yet — register the first one.</td></tr><?php endif; ?>
      <?php foreach ($patients as $p): $mm = ''; if (intval($p['member_id'])>0) { foreach ($memberOpts as $mo) if (intval($mo['id'])===intval($p['member_id'])) { $mm = $mo['member_no']; break; } } ?>
      <tr><td><b><?php echo e($p['patient_no']); ?></b></td><td><?php echo e($p['full_names']); ?><br><span style="font-size:10.5px;color:#7a94aa"><?php echo e($p['address']); ?></span></td>
        <td><?php echo e($p['age_years']); ?> / <?php echo e(substr($p['gender'],0,1)); ?></td><td style="font-size:11.5px"><?php echo e($p['phone']); ?></td>
        <td><?php echo $mm !== '' ? '<span class="badge" style="background:#E6F6EA;color:#20603a">' . e($mm) . '</span>' : '<span style="color:#a9bccd">cash</span>'; ?></td>
        <td class="noprint" style="white-space:nowrap"><a class="btn small" href="facility-clinical.php?facility=<?php echo $GF; ?>&tab=patients&editpt=<?php echo e($p['id']); ?>">edit</a>
        <a class="btn small success" href="facility-clinical.php?facility=<?php echo $GF; ?>&tab=patients&enc=new&pt=<?php echo e($p['id']); ?>">+ encounter</a></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
</div>
<?php else: ?>
<?php if ($ENC): ?>
<div class="panel" style="border-left:4px solid #1B6BB3">
  <h2 style="margin-top:0"><?php echo e($ENC['enc_no']); ?> — <?php echo e($ENC['full_names']); ?>
    <span class="badge" style="background:<?php echo $ENC['status']==='Open'?'#FFF6E0;color:#8a6410':'#E6F6EA;color:#20603a'; ?>"><?php echo strtoupper($ENC['status']); ?></span>
    <span style="font:normal 12px Arial;color:#5b7c99"><?php echo e($ENC['enc_type']); ?> · <?php echo e($ENC['department'] !== '' ? $ENC['department'] : 'General'); ?> · opened <?php echo e(date('d M Y H:i', strtotime($ENC['started_at']))); ?><?php echo $ENC['clinician'] !== '' ? ' · clinician ' . e($ENC['clinician']) : ''; ?><?php echo $ENC['room_bed'] !== '' ? ' · ' . e($ENC['room_bed']) : ''; ?></span>
    <?php if ($ENC['claim_id'] > 0 && !empty($ENC['claim'])): ?><a class="btn small danger" style="float:right" href="claim-view.php?id=<?php echo e($ENC['claim']['id']); ?>">Claim <?php echo e($ENC['claim']['claim_no']); ?> — <?php echo cl_slabel($ENC['claim']['status']); ?></a><?php endif; ?>
  </h2>
  <div class="grid grid-4">
    <div class="kpi"><div class="k-label">Order lines</div><div class="k-value"><?php echo num($estats['n']); ?></div></div>
    <div class="kpi gold"><div class="k-label">Ready to claim</div><div class="k-value"><?php echo ugx($estats['ready']); ?></div></div>
    <div class="kpi teal"><div class="k-label">Pending completion</div><div class="k-value"><?php echo ugx($estats['pending']); ?></div></div>
    <div class="kpi green"><div class="k-label">Billed</div><div class="k-value"><?php echo ugx($estats['billed']); ?></div></div>
  </div>
  <?php if ($ENC['status'] === 'Open'): ?>
  <div class="grid grid-2" style="margin-top:6px">
    <div class="panel" style="margin:0;background:#F7FAFD">
      <h3 style="margin-top:0">Add service order</h3>
      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="order"><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="enc" value="<?php echo e($ENC['id']); ?>">
        <label class="f">From <?php echo e($F['name']); ?>'s price list</label>
        <select class="f" name="price_id" onchange="var o=this.options[this.selectedIndex];document.getElementById('catBox').value=o.getAttribute('data-cat')||'';">
          <option value="0">— custom service below —</option>
          <?php foreach ($priceItems as $cat => $plist): foreach ($plist as $p): ?>
          <option value="<?php echo e($p['id']); ?>" data-cat="<?php echo e($p['category']); ?>"><?php echo e($p['category']); ?>: <?php echo e($p['item_name']); ?> — <?php echo e(number_format((float)$p['price'],0)); ?><?php echo !empty($p['is_base'])?' ·STD':' · own'; ?></option>
          <?php endforeach; endforeach; ?>
        </select>
        <div class="grid grid-2" style="gap:8px">
          <div><label class="f">Department</label><input class="f" id="catBox" name="category" value="Other" placeholder="auto from list"></div>
          <div><label class="f">Qty</label><input class="f" name="qty" value="1" inputmode="numeric"></div>
        </div>
        <details style="margin:6px 0"><summary style="font-size:12px;color:#1B6BB3;cursor:pointer">Custom service (not on the list)</summary>
          <div class="grid grid-3" style="gap:8px;margin-top:6px">
            <div><label class="f">Service</label><input class="f" name="custom_name" placeholder="e.g. wound dressing"></div>
            <div><label class="f">Qty</label><input class="f" name="custom_qty" value="1"></div>
            <div><label class="f">Price (UGX)</label><input class="f" name="custom_price" inputmode="numeric"></div>
          </div></details>
        <button class="btn primary small" type="submit">Add order line</button>
      </form>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php if ($ENC['enc_type'] === 'IPD'): ?>
      <form method="post" class="panel" style="margin:0;background:#FBF7EF"><?php echo csrf_field(); ?><input type="hidden" name="op" value="bed_nights"><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="enc" value="<?php echo e($ENC['id']); ?>">
        <label class="f">Admission — bill bed nights</label>
        <input class="f" name="nights" value="1" style="width:80px" inputmode="numeric"> <button class="btn small" type="submit">Add at ward tariff</button>
      </form><?php endif; ?>
      <form method="post" class="panel" style="margin:0" onsubmit="return confirm('Close this encounter and mark the patient discharged?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="discharge"><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="enc" value="<?php echo e($ENC['id']); ?>">
        <label class="f">Discharge</label><button class="btn small" type="submit">&#128715; Discharge patient</button>
      </form>
      <form method="post" class="panel" style="margin:0;background:#FDF3F2"><?php echo csrf_field(); ?><input type="hidden" name="op" value="enc_claim"><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="enc" value="<?php echo e($ENC['id']); ?>">
        <label class="f">Claim this episode</label>
        <button class="btn small danger" type="submit" name="mode" value="submit" <?php echo floatval($estats['ready'])>0?'':'disabled'; ?>>&#9878;&#65039; Bundle completed lines &amp; send to owner</button>
        <button class="btn small" type="submit" name="mode" value="draft" <?php echo floatval($estats['ready'])>0?'':'disabled'; ?>>save as draft</button>
        <p style="font-size:11.5px;color:#5b7c99;margin:6px 0 0">Billed prices are frozen at what was charged today — later price-list changes never alter submitted claims.</p>
      </form>
    </div>
  </div>
  <?php else: ?>
  <p style="margin:4px 0"><b><?php echo e($ENC['full_names']); ?></b> was discharged on <?php echo e($ENC['discharged_at'] ? date('d M Y H:i', strtotime($ENC['discharged_at'])) : '—'); ?>.
   <?php if (floatval($estats['ready'])>0): ?>
   <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="enc_claim"><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="enc" value="<?php echo e($ENC['id']); ?>">
   <button class="btn small danger" type="submit" name="mode" value="submit">Claim remaining <?php echo ugx($estats['ready']); ?></button></form>
   <?php endif; ?></p>
  <?php endif; ?>
  <div class="twrap" style="border:0;margin-top:8px"><table class="data" style="margin:0">
    <tr><th>Dept</th><th>Service</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th><th>Status</th><th>Result / done by</th><th class="noprint">Actions</th></tr>
    <?php if (!$orders): ?><tr><td colspan="8" style="color:#5b7c99">No orders yet.</td></tr><?php endif; ?>
    <?php foreach ($orders as $o): $amt = round((float)$o['price']*(float)$o['qty'],2); ?>
    <tr><td><span class="badge grey"><?php echo e($o['category']); ?></span></td><td><?php echo e($o['item_name']); ?></td>
      <td class="num"><?php echo e(rtrim(rtrim(number_format((float)$o['qty'],2),'0'),'.') === '' ? '1' : rtrim(rtrim(number_format((float)$o['qty'],2),'0'),'.')); ?></td>
      <td class="num"><?php echo e(number_format((float)$o['price'],0)); ?></td><td class="num"><b><?php echo e(number_format($amt,0)); ?></b></td>
      <td><?php $col = array('Requested'=>'#FFF6E0;color:#8a6410','Completed'=>'#E3EFFA;color:#1B6BB3','Billed'=>'#E6F6EA;color:#20603a','Claimed'=>'#E6F6EA;color:#20603a','Cancelled'=>'#F0F4F8;color:#7a94aa');
        echo '<span style="font:bold 10px Arial;padding:2px 7px;border-radius:3px;background:' . (isset($col[$o['status']])?$col[$o['status']]:$col['Requested']) . '">' . strtoupper($o['status']) . '</span>'; ?></td>
      <td style="font-size:11px;max-width:220px"><?php echo e($o['result_text'] !== '' ? mb_substr($o['result_text'],0,120) : '—'); ?><?php echo $o['completed_by'] ? ' <span style="color:#7a94aa">(' . e($o['completed_by']) . ')</span>' : ''; ?></td>
      <td class="noprint" style="white-space:nowrap"><?php if ($o['status']==='Requested'): ?>
        <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="order_state"><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="enc" value="<?php echo e($ENC['id']); ?>"><input type="hidden" name="oid" value="<?php echo e($o['id']); ?>">
        <input type="hidden" name="to" value="Completed"><input type="hidden" name="result" value="<?php echo e($o['result_text']); ?>">
        <button class="btn small success" type="submit">&#10003; complete</button></form>
      <?php elseif ($o['status']==='Completed' && $ENC['status']==='Open'): ?>
        <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="order_state"><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="enc" value="<?php echo e($ENC['id']); ?>"><input type="hidden" name="oid" value="<?php echo e($o['id']); ?>">
        <input type="hidden" name="to" value="Completed"><input class="f" name="result" placeholder="add result…" style="width:130px;margin:0;display:inline-block;padding:4px 6px"><button class="btn small" type="submit">save result</button></form>
      <?php endif; ?><?php if ($o['status']==='Requested' && $ENC['status']==='Open'): ?>
        <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="order_state"><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="enc" value="<?php echo e($ENC['id']); ?>"><input type="hidden" name="oid" value="<?php echo e($o['id']); ?>">
        <input type="hidden" name="to" value="Cancelled"><button class="btn small" type="submit">&#10007;</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php else: ?>
<div class="grid" style="grid-template-columns:340px 1fr;gap:14px">
  <div class="panel" style="margin:0">
    <h3 style="margin-top:0">Open an encounter<?php echo $ptSel ? ' for patient #' . $ptSel : ''; ?></h3>
    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="open_enc"><input type="hidden" name="facility" value="<?php echo $GF; ?>">
      <label class="f">Patient *</label>
      <select class="f" name="patient_id" required><option value="">— pick from registry —</option>
      <?php $stAll = db()->query('SELECT id, patient_no, full_names FROM patients WHERE facility_id=' . intval($GF) . " AND status='Active' ORDER BY full_names LIMIT 500")->fetchAll();
      foreach ($stAll as $pa): ?><option value="<?php echo e($pa['id']); ?>" <?php echo intval($ptSel)===intval($pa['id'])?'selected':''; ?>><?php echo e($pa['patient_no']); ?> — <?php echo e($pa['full_names']); ?></option><?php endforeach; ?>
      </select>
      <div class="grid grid-2" style="gap:8px">
        <div><label class="f">Type</label><select class="f" name="enc_type"><option>OPD</option><option>IPD</option><option>Emergency</option></select></div>
        <div><label class="f">Department</label><select class="f" name="department"><option>General OPD</option><option>Specialty clinic</option><option>Emergency</option><option>Maternity / MCH</option><option>Surgical ward</option><option>Medical ward</option><option>Paediatric ward</option><option>Theatre</option><option>ICU</option></select></div>
      </div>
      <label class="f">Clinician on duty</label><input class="f" name="clinician" placeholder="Dr. …">
      <label class="f">Ward / room / bed (IPD)</label><input class="f" name="room_bed" placeholder="e.g. W3 / bed 4">
      <label class="f">Presenting complaint</label><input class="f" name="enc_note" placeholder="brief">
      <button class="btn primary" type="submit">Open encounter</button>
    </form>
  </div>
  <div class="panel" style="margin:0">
    <h3 style="margin-top:0">Encounters
      <form method="get" style="float:right;margin-top:-4px"><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="tab" value="encounters">
      <input class="f" name="q" value="<?php echo e($q); ?>" placeholder="encounter no, patient" style="width:170px;margin:0;padding:5px 8px;display:inline-block"></form></h3>
    <div class="twrap" style="border:0;max-height:480px;overflow:auto"><table class="data" style="margin:0">
      <tr><th>Encounter</th><th>Patient</th><th>Type</th><th>Dept</th><th>Opened</th><th>Status</th><th class="num">Billed</th><th>Claim</th></tr>
      <?php if (!$encs): ?><tr><td colspan="8" style="color:#5b7c99">No encounters yet.</td></tr><?php endif; ?>
      <?php foreach ($encs as $x): $xs = cl_enc_stats(intval($x['id'])); ?>
      <tr><td><a href="facility-clinical.php?facility=<?php echo $GF; ?>&tab=encounters&enc=<?php echo e($x['id']); ?>"><b><?php echo e($x['enc_no']); ?></b></a></td>
      <td><?php echo e($x['full_names']); ?></td><td><?php echo e($x['enc_type']); ?></td><td style="font-size:11.5px"><?php echo e($x['department']); ?></td>
      <td style="font-size:11.5px"><?php echo e(date('d M Y', strtotime($x['started_at']))); ?></td>
      <td><span class="badge" style="background:<?php echo $x['status']==='Open'?'#FFF6E0;color:#8a6410':'#E6F6EA;color:#20603a'; ?>"><?php echo strtoupper($x['status']); ?></span></td>
      <td class="num"><?php echo e(number_format((float)$xs['billed'],0)); ?></td>
      <td><?php echo intval($x['claim_id'])>0 ? '<a href="claim-view.php?id='.e($x['claim_id']).'">#'.e($x['claim_id']).'</a>' : '—'; ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
