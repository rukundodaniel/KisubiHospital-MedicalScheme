<?php
/**
 * MEMBERS REGISTRATION — full-featured (camera photo capture, file upload,
 * NFC/smart-card reader, KH generator, combined search, audit trail, ID card,
 * register / update / delete, portal access). Classic light-blue theme.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_role(array('Super Admin', 'Administrator', 'Reception'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$action = get('a');
$has_ext = false;
try { $has_ext = (bool)db()->query('SELECT mtn_no FROM members LIMIT 1')->fetchColumn() !== false || true; }
catch (Exception $e) { $has_ext = false; } // extended columns not migrated yet

function phone_norm($v) {
    $d = preg_replace('/\D/', '', (string)$v);
    if ($d === '') return '';
    if (strpos($d, '256') === 0) return $d;
    if ($d[0] === '0') return '256' . substr($d, 1);
    if ($d[0] === '7') return '256' . $d;
    return $d;
}
function phone_disp($v) { $v = (string)$v; return strpos($v, '256') === 0 ? '0' . substr($v, 3) : $v; }

/** VB-compatible audit row: UserName, Computer, IP, LoginTime, LoginDate, Status, Activity, MemberNo, FullNames, EntryDate */
function member_audit($activity, $memberNo, $fullNames) {
    try {
        if (!db()->query('SELECT 1 FROM audit_trail LIMIT 1')->fetchColumn() && false) return; // probe table
    } catch (Exception $e) { return; }
    try {
        $st = db()->prepare('INSERT INTO audit_trail (username, computer, ip, login_time, login_date, status, activity, member_no, full_names, entry_date) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute(array(
            $_SESSION['uname'], isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'web',
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            date('H:i:s'), date('Y-m-d'), 'Logged In', $activity, $memberNo, $fullNames, date('Y-m-d H:i:s')));
    } catch (Exception $e) { /* audit never blocks */ }
}

/** Bind values binary-safely: photo goes through PARAM_LOB on sqlite (execute-array
 *  truncates at NUL bytes) and NULLs really become SQL NULL everywhere. */
function exec_stmt($sql, $vals, $lobPos = 0) {
    $st = db()->prepare($sql);
    $drv = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
    foreach (array_values($vals) as $i => $v) {
        $p = $i + 1;
        if ($lobPos && $p === $lobPos && is_string($v)) {
            $st->bindValue($p, $v, $drv === 'mysql' ? PDO::PARAM_STR : PDO::PARAM_LOB);
        } elseif ($v === null) {
            $st->bindValue($p, null, PDO::PARAM_NULL);
        } else {
            $st->bindValue($p, (string)$v, PDO::PARAM_STR);
        }
    }
    $st->execute();
    return $st;
}

function save_photo_from_post() {
    $data = isset($_POST['photo_data']) ? trim($_POST['photo_data']) : '';
    if ($data === '' || strpos($data, ',') === false) return null;
    $b64 = preg_replace('/\s/', '', substr($data, strpos($data, ',') + 1));
    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) < 1024 || strlen($bytes) > 4000000) return null;
    if (substr($bytes, 0, 3) !== "\xFF\xD8\xFF") return null; // real JPEG only (camera + upload both re-encode to JPEG)
    return $bytes;
}

// ---------------- POST actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');

    if ($op === 'genno') { redirect('members.php?a=new'); }

    if ($op === 'save' || $op === 'update') {
        $id = intval(post('id'));
        $memberNo = strtoupper(post('member_no'));
        $fields = array(
            'full_names' => post('full_names'), 'place' => post('place_of_residence'), 'email' => post('email'),
            'airtel' => phone_norm(post('airtel_no')), 'mtn' => phone_norm(post('mtn_no')),
            'village' => post('lc1_village'), 'district' => post('district'),
            'nin' => post('national_id') !== '' ? post('national_id') : null,
            'card' => post('card_no'), 'gender' => post('gender'), 'marital' => post('marital_status'),
            'dob' => post('dob') !== '' ? post('dob') : null, 'reg_date' => post('registration_date') !== '' ? post('registration_date') : today(),
            'deposit' => (float)str_replace(',', '', post('deposit_amount')), 'status' => post('status'), 'note' => post('note'),
        );
        if ($fields['full_names'] === '' || ($fields['mtn'] === '' && $fields['airtel'] === '')) {
            flash('Full names and at least one mobile number (No. 1 MTN or No. 2 Airtel) are required.', 'err');
            redirect('members.php?' . ($id ? 'a=edit&id=' . $id : 'a=new'));
        }
        if ($memberNo === '') $memberNo = next_member_no();
        $photo = save_photo_from_post();
        try {
            if ($has_ext) {
                if ($id > 0 && $op === 'update') {
                    $sql = 'UPDATE members SET member_no=?, full_names=?, phone_no=?, alt_phone=?, address=?, email_address=?, airtel_no=?, mtn_no=?, lc1_village=?, district=?, national_id=?, card_no=?, gender=?, marital_status=?, dob=?, registration_date=?, status=?, note=?' . ($photo !== null ? ', photo=?' : '') . ' WHERE id=?';
                    $vals = array($memberNo, $fields['full_names'], $fields['mtn'], $fields['airtel'], $fields['place'], $fields['email'], $fields['airtel'], $fields['mtn'], $fields['village'], $fields['district'], $fields['nin'], $fields['card'], $fields['gender'], $fields['marital'], $fields['dob'], $fields['reg_date'], $fields['status'], $fields['note']);
                    if ($photo !== null) $vals[] = $photo;
                    $vals[] = $id;
                    exec_stmt($sql, $vals, $photo !== null ? count($vals) - 1 : 0);
                    log_action('Updated member ' . $memberNo, 'members', $id);
                    member_audit('MemberUpdate', $memberNo, $fields['full_names']);
                    flash('Member ' . $memberNo . ' updated.');
                } else {
                    $sql = 'INSERT INTO members (member_no, full_names, national_id, gender, phone_no, alt_phone, address, email_address, airtel_no, mtn_no, lc1_village, district, card_no, marital_status, dob, registration_date, status, note, reg_by, deposit_amount' . ($photo !== null ? ', photo' : '') . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?' . ($photo !== null ? ',?' : '') . ')';
                    $vals = array($memberNo, $fields['full_names'], $fields['nin'], $fields['gender'], $fields['mtn'], $fields['airtel'], $fields['place'], $fields['email'], $fields['airtel'], $fields['mtn'], $fields['village'], $fields['district'], $fields['card'], $fields['marital'], $fields['dob'], $fields['reg_date'], $fields['status'], $fields['note'], $_SESSION['uname'], $fields['deposit']);
                    if ($photo !== null) $vals[] = $photo;
                    exec_stmt($sql, $vals, $photo !== null ? count($vals) : 0);
                    $id = intval(db()->lastInsertId());
                    if ($fields['deposit'] > 0) {
                        db()->prepare('INSERT INTO deposits (member_id, deposit_date, amount, payment_method, reference, note, entered_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
                           ->execute(array($id, $fields['reg_date'], $fields['deposit'], 'Cash', 'REG-' . $memberNo, 'Initial deposit at registration', $_SESSION['uname'], date('Y-m-d H:i:s')));
                    }
                    log_action('Registered new member ' . $memberNo, 'members', $id);
                    member_audit('MemberRegistration', $memberNo, $fields['full_names']);
                    flash('Member ' . $memberNo . ' registered successfully.');
                }
            } else {
                // fallback: legacy schema without extended columns
                if ($id > 0 && $op === 'update') {
                    db()->prepare('UPDATE members SET member_no=?, full_names=?, national_id=?, gender=?, phone_no=?, alt_phone=?, address=?, status=?, note=? WHERE id=?')
                       ->execute(array($memberNo, $fields['full_names'], (string)$fields['nin'], $fields['gender'], $fields['mtn'], $fields['airtel'], $fields['place'], $fields['status'], $fields['note'], $id));
                } else {
                    db()->prepare('INSERT INTO members (member_no, full_names, national_id, gender, phone_no, alt_phone, address, registration_date, status, note) VALUES (?,?,?,?,?,?,?,?,?,?)')
                       ->execute(array($memberNo, $fields['full_names'], (string)$fields['nin'], $fields['gender'], $fields['mtn'], $fields['airtel'], $fields['place'], $fields['reg_date'], $fields['status'], $fields['note']));
                    $id = intval(db()->lastInsertId());
                }
                log_action('Member saved (legacy schema)', 'members', $id);
                flash('Saved. Note: run migrate.php to enable photo/NFC/extended fields.');
            }
            redirect('members.php');
        } catch (Exception $ex) {
            $msg = $ex->getMessage();
            if (strpos($msg, '1062') !== false || stripos($msg, 'UNIQUE') !== false) {
                flash('That Member Number or National ID is already registered to another member.', 'err');
            } else { flash('Member could not be saved: ' . $msg, 'err'); }
            redirect('members.php' . ($id ? '?a=edit&id=' . $id : '?a=new'));
        }
    }

    if ($op === 'delete') {
        require_role(array('Super Admin', 'Administrator'));
        $id = intval(post('id'));
        try {
            $st = db()->prepare('SELECT member_no, full_names FROM members WHERE id=?'); $st->execute(array($id)); $mm = $st->fetch();
            db()->prepare('DELETE FROM members WHERE id=?')->execute(array($id));
            log_action('Deleted member ' . ($mm ? $mm['member_no'] : '#' . $id), 'members', $id);
            if ($mm) member_audit('MemberDelete', $mm['member_no'], $mm['full_names']);
            flash('Member deleted (their transactions were left for audit).', 'info');
        } catch (Exception $ex) { flash('Cannot delete: ' . $ex->getMessage(), 'err'); }
        redirect('members.php');
    }

    if ($op === 'portal') {
        $id = intval(post('id'));
        $pu = post('portal_username'); $pp = post('portal_password'); $pa = post('portal_action');
        try {
            if ($pa === 'revoke') {
                db()->prepare('UPDATE members SET portal_active=0 WHERE id=?')->execute(array($id));
                flash('Portal access revoked.', 'info');
            } elseif ($pa === 'grant') {
                if (strlen($pp) < 8) throw new Exception('Portal password must be 8+ characters.');
                db()->prepare('UPDATE members SET portal_username=?, portal_pass_hash=?, portal_active=1, portal_created_at=? WHERE id=?')
                   ->execute(array($pu, password_hash($pp, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), $id));
                log_action('Portal access granted for member #' . $id, 'members', $id);
                flash('Portal access granted (username: ' . $pu . ').');
            }
        } catch (Exception $ex) { flash($ex->getMessage(), 'err'); }
        redirect('members.php' . ($id ? '?a=edit&id=' . $id : ''));
    }
}

// ---------------- FORM (new / edit) ----------------
if ($action === 'new' || $action === 'edit') {
    $m = array('id'=>0,'member_no'=>'','full_names'=>'','national_id'=>'','gender'=>'Male','phone_no'=>'','alt_phone'=>'','address'=>'',
               'email'=>'','airtel'=>'','mtn'=>'','village'=>'','district'=>'','card'=>'','marital'=>'','dob'=>'',
               'next_of_kin'=>'','next_of_kin_phone'=>'','registration_date'=>today(),'status'=>'Active','note'=>'',
               'deposit'=>'','reg_by'=>'','portal_username'=>'','portal_active'=>0);
    $hasPhoto = false;
    if ($action === 'edit') {
        $st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array(intval(get('id'))));
        $found = $st->fetch();
        if (!$found) { flash('Member not found.', 'err'); redirect('members.php'); }
        $m = array_merge($m, array(
            'id' => intval($found['id']), 'member_no' => $found['member_no'], 'full_names' => $found['full_names'],
            'national_id' => $found['national_id'], 'gender' => $found['gender'], 'phone_no' => phone_disp($found['phone_no']),
            'alt_phone' => phone_disp($found['alt_phone']), 'address' => $found['address'],
            'next_of_kin' => isset($found['next_of_kin']) ? $found['next_of_kin'] : '',
            'next_of_kin_phone' => isset($found['next_of_kin_phone']) ? $found['next_of_kin_phone'] : '',
            'registration_date' => $found['registration_date'], 'status' => $found['status'], 'note' => $found['note'],
            'portal_username' => isset($found['portal_username']) ? $found['portal_username'] : '',
            'portal_active' => isset($found['portal_active']) ? intval($found['portal_active']) : 0));
        if ($has_ext) {
            $m['email'] = $found['email_address']; $m['airtel'] = phone_disp($found['airtel_no']); $m['mtn'] = phone_disp($found['mtn_no']);
            $m['village'] = $found['lc1_village']; $m['district'] = $found['district']; $m['card'] = $found['card_no'];
            $m['marital'] = $found['marital_status']; $m['dob'] = $found['dob'];
            $m['deposit'] = isset($found['deposit_amount']) ? rtrim(rtrim(number_format((float)$found['deposit_amount'], 2, '.', ''), '0'), '.') : '';
            $m['reg_by'] = isset($found['reg_by']) ? $found['reg_by'] : '';
            try { $pc = db()->prepare('SELECT (photo IS NOT NULL) p FROM members WHERE id=?'); $pc->execute(array($m['id'])); $hasPhoto = intval($pc->fetchColumn()) === 1; } catch (Exception $e) {}
        }
    }
    if ($has_ext && $m['id'] > 0) {
        try {
            $probe = db()->prepare('SELECT LENGTH(photo) FROM members WHERE id=?');
            $probe->execute(array($m['id']));
            $hasPhoto = intval($probe->fetchColumn()) > 0;
        } catch (Exception $e) {}
    }
    $gen = '';
    if ($action === 'new') { // KH-ABC-1234 generator server-side (CSPRNG), as in the VB form
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $chars = '';
        for ($i = 0; $i < 3; $i++) $chars .= $letters[random_int(0, 25)];
        $gen = 'KH-' . $chars . '-' . str_pad((string)random_int(1000, 9999), 4, '0');
    }
    $pg_title = $action === 'edit' ? 'Edit Member' : 'Member Registration';
    include __DIR__ . '/includes/header.php'; ?>
<div class="page-head">
  <div><h1><?php echo $action === 'edit' ? 'Edit Member — ' . e($m['member_no']) : 'New Member Registration'; ?></h1>
  <span class="sub">Capture a photo, read the NFC card, then register. Fields marked * are required.</span></div>
  <div class="noprint">
    <?php if ($action === 'edit'): ?><a class="btn plain" href="idcard.php?id=<?php echo e($m['id']); ?>" target="_blank">🪪 Print ID Card</a><?php endif; ?>
    <a class="btn plain" href="members.php">&laquo; Back to list</a>
  </div>
</div>

<form method="post" action="members.php" id="regForm">
<?php echo csrf_field(); ?>
<input type="hidden" name="op" value="<?php echo $action === 'edit' ? 'update' : 'save'; ?>">
<input type="hidden" name="id" value="<?php echo e($m['id']); ?>">
<input type="hidden" name="photo_data" id="photo_data" value="">
<div class="grid" style="grid-template-columns:5fr 3fr;align-items:start">
<div>
  <fieldset class="fset"><legend>Registration</legend>
  <div class="formgrid" style="grid-template-columns:repeat(3,1fr)">
    <div><label class="f">Member No.</label>
      <div style="display:flex;gap:6px"><input class="f" name="member_no" id="member_no" value="<?php echo $action==='edit'?e($m['member_no']):e($gen); ?>" placeholder="auto KMH-0000">
      <?php if ($action==='new'): ?>
      <button type="button" class="btn small plain" title="Generate a new random KH-ABC-1234" onclick="genNo()">&#x21bb; New</button><?php endif; ?></div></div>
    <div class="span2"><label class="f">Full names *</label><input class="f" name="full_names" value="<?php echo e($m['full_names']); ?>" placeholder="Surname, other names as on NIN"></div>
    <div><label class="f">Gender</label><select class="f" name="gender"><option <?php echo $m['gender']==='Male'?'selected':''; ?>>Male</option><option <?php echo $m['gender']==='Female'?'selected':''; ?>>Female</option></select></div>
    <div><label class="f">Marital status</label><select class="f" name="marital_status"><?php $ms=array('Single','Married','Divorced','Widowed'); foreach($ms as $x){echo '<option '.($m['marital']===$x?'selected':'').'>'.$x.'</option>';} ?></select></div>
    <div><label class="f">Date of birth</label><input class="f" type="date" name="dob" value="<?php echo e($m['dob']); ?>"></div>
    <div><label class="f">Registration date</label><input class="f" type="date" name="registration_date" value="<?php echo e($m['registration_date']); ?>"></div>
    <div><label class="f">Registered by</label><input class="f" value="<?php echo $action==='edit'?e($m['reg_by']):e($_SESSION['fname']); ?>" readonly></div>
    <div><label class="f">Status</label><select class="f" name="status"><?php foreach(array('Active','Inactive','Suspended') as $s){echo '<option '.($m['status']===$s?'selected':'').'>'.$s.'</option>';} ?></select></div>
  </div></fieldset>

  <fieldset class="fset"><legend>Contact</legend>
  <div class="formgrid" style="grid-template-columns:repeat(3,1fr)">
    <div><label class="f">Mobile No. 1 (MTN)</label><input class="f mob1" name="mtn_no" value="<?php echo e($m['mtn']); ?>" placeholder="077... / 078..."></div>
    <div><label class="f">Mobile No. 2 (Airtel)</label><input class="f mob2" name="airtel_no" value="<?php echo e($m['airtel']); ?>" placeholder="075... / 070..."></div>
    <div style="grid-column:1/-1;font-size:11.5px;color:#7a94aa;margin-top:-2px">At least <b>one</b> mobile number is required &mdash; either line receives SMS alerts and MoMo prompts. Both can be changed any time on this form.</div>
    <script>document.addEventListener('submit', function (ev) { var a = ev.target.querySelector('.mob1'), b = ev.target.querySelector('.mob2'); if (a && b && !a.value.trim() && !b.value.trim()) { alert('Enter at least one mobile number: No. 1 (MTN) or No. 2 (Airtel).'); ev.preventDefault(); a.focus(); } });</script>
    <div><label class="f">Email address</label><input class="f" type="email" name="email" value="<?php echo e($m['email']); ?>"></div>
  </div></fieldset>

  <fieldset class="fset"><legend>Address (Uganda)</legend>
  <div class="formgrid" style="grid-template-columns:repeat(3,1fr)">
    <div><label class="f">LC1 village</label><input class="f" name="lc1_village" value="<?php echo e($m['village']); ?>"></div>
    <div><label class="f">District</label><input class="f" name="district" value="<?php echo e($m['district']); ?>"></div>
    <div><label class="f">Place of residence</label><input class="f" name="place_of_residence" value="<?php echo e($m['address']); ?>"></div>
  </div></fieldset>

  <fieldset class="fset"><legend>ID &amp; Smart card</legend>
  <div class="formgrid" style="grid-template-columns:repeat(3,1fr)">
    <div><label class="f">National ID (NIN)</label><input class="f" name="national_id" value="<?php echo e($m['national_id']); ?>"></div>
    <div><label class="f">Card No. / NFC UID</label>
      <div style="display:flex;gap:6px"><input class="f" name="card_no" id="card_no" value="<?php echo e($m['card']); ?>">
      <button type="button" class="btn small primary" id="nfcBtn" title="Read UID from the NFC tap (Android Chrome / desktop with WebNFC)">&#xf7e4; Read NFC</button></div></div>
    <div><label class="f">Initial deposit (UGX)</label><input class="f" type="number" min="0" step="500" name="deposit_amount" value="<?php echo e($m['deposit']); ?>"></div>
  </div>
  <p style="font-size:11.5px;color:#5b7c99;margin:6px 0 0">Tap the member's card on the reader and press <b>Read NFC</b> — the UID is captured into Card No. If the device has no WebNFC, key the number manually.</p>
  </fieldset>

  <fieldset class="fset"><legend>Note</legend>
  <textarea class="f" name="note" rows="2"><?php echo e($m['note']); ?></textarea></fieldset>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px">
    <button class="btn success" style="min-width:180px;padding:12px" type="submit"><?php echo $action==='edit'?'Save changes':'Register member'; ?></button>
    <a class="btn plain" href="members.php">Cancel</a>
    <?php if ($action === 'edit' && can(array('Super Admin','Administrator'))): ?>
    <button class="btn danger" type="button" style="margin-left:auto" onclick="if(confirm('Remove this member? Transactions are kept for audit.')){document.getElementById('delForm').submit();}">Delete member</button>
    <?php endif; ?>
  </div>
</div>

<div>
  <fieldset class="fset"><legend>Photograph</legend>
  <div class="photo-pane" id="livePane">
    <video id="cam" autoplay playsinline muted></video>
    <div class="photo-hint" id="camHint">Camera off — press <b>Capture photo</b> to start the live view.</div>
  </div>
  <div class="photo-pane" id="shotPane" style="display:none">
    <img id="shot" alt="captured photo">
  </div>
  <?php if ($hasPhoto): ?>
  <div class="photo-pane" id="dbPane"><img src="memberpic.php?id=<?php echo e($m['id']); ?>&s=1" alt="stored photo">
  <div class="photo-hint">Current photo on file. Capture a new one to replace it.</div></div>
  <?php endif; ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
    <button type="button" class="btn primary" onclick="startCam()">&#x1f4f7; Capture photo</button>
    <button type="button" class="btn plain" onclick="snap()" id="snapBtn" disabled>Snap</button>
    <button type="button" class="btn plain" onclick="retake()" id="retakeBtn" style="display:none">&#x21ba; Retake</button>
    <label class="btn plain" style="cursor:pointer">&#x1f4c1; Upload file…<input type="file" accept="image/*" id="fileInp" style="display:none"></label>
  </div>
  <div style="margin-top:8px;text-align:right" id="shotActions"></div>
  </fieldset>

  <?php if ($action === 'edit'): ?>
  <fieldset class="fset"><legend>Member portal access</legend>
    <p style="font-size:12px;margin:0 0 8px;color:#5b7c99"><?php echo $m['portal_active'] ? '<span class="badge green">ACTIVE</span> username <b>' . e($m['portal_username']) . '</b>' : 'No portal account yet.'; ?></p>
    <div style="display:grid;gap:8px">
      <div><label class="f">Portal username</label><input class="f" name="portal_username_f" value="<?php echo e($m['portal_username']); ?>" disabled></div>
    </div>
    <div class="noprint" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
      <button type="button" class="btn small success" onclick="portalAction('grant')">Grant / reset</button>
      <?php if ($m['portal_active']): ?><button type="button" class="btn small danger" onclick="portalAction('revoke')">Revoke</button><?php endif; ?>
      <a class="btn small plain" href="idcard.php?id=<?php echo e($m['id']); ?>" target="_blank">ID card</a>
    </div>
  </fieldset>
  <?php endif; ?>
</div>
</div>
</form>
<form id="delForm" method="post" action="members.php" style="display:none"><?php echo csrf_field(); ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?php echo e($m['id']); ?>"></form>
<script>
var CSRF = '<?php echo e(csrf_token()); ?>';
function genNo(){ var L='ABCDEFGHJKLMNPQRSTUVWXYZ', s=''; for(var i=0;i<3;i++) s+=L[Math.floor(Math.random()*L.length)];
 document.getElementById('member_no').value='KH-'+s+'-'+(1000+Math.floor(Math.random()*9000)); }
function portalAction(a){
 var un = prompt('Portal username:', '<?php echo e($m['portal_username']); ?>'); if(un===null) return;
 var pw = a==='revoke' ? '' : prompt('Portal password (8+ chars):'); if(pw===null) return;
 var f=document.createElement('form'); f.method='post'; f.action='members.php';
 function inp(n,v){var i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;f.appendChild(i);}
 inp('csrf',CSRF); inp('op','portal'); inp('id','<?php echo e($m['id']); ?>'); inp('portal_action',a); inp('portal_username',un); inp('portal_password',pw);
 document.body.appendChild(f); f.submit(); }

var stream=null, capturedBlobURL=null;
function startCam(){
  if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){ alert('Camera needs HTTPS + a modern browser. Use "Upload file" instead.'); return; }
  navigator.mediaDevices.getUserMedia({video:{facingMode:'user', width:{ideal:1280}, height:{ideal:720}}, audio:false})
   .then(function(s){ stream=s; document.getElementById('cam').srcObject=s;
     document.getElementById('livePane').style.display='block'; document.getElementById('shotPane').style.display='none';
     var db=document.getElementById('dbPane'); if(db) db.style.display='none';
     document.getElementById('camHint').style.display='none';
     document.getElementById('snapBtn').disabled=false;
   }).catch(function(e){ alert('Camera not available: '+e.message+'\nUse Upload file instead.'); });
}
function stopCam(){ if(stream){ stream.getTracks().forEach(function(t){t.stop();}); stream=null; } document.getElementById('snapBtn').disabled=true; }
function setPhoto(canvas){
  // center-square crop, resize 512, JPEG 0.85 (like the desktop app saving to the Photo BLOB)
  var size=Math.min(canvas.width,canvas.height), out=document.createElement('canvas'); out.width=512; out.height=512;
  var ctx=out.getContext('2d'); ctx.drawImage(canvas,(canvas.width-size)/2,(canvas.height-size)/2,size,size,0,0,512,512);
  var url=out.toDataURL('image/jpeg',0.85);
  document.getElementById('photo_data').value=url;
  document.getElementById('shot').src=url;
  document.getElementById('livePane').style.display='none';
  document.getElementById('shotPane').style.display='block';
  var db=document.getElementById('dbPane'); if(db) db.style.display='none';
  document.getElementById('retakeBtn').style.display='inline-block';
  stopCam();
}
function snap(){ var v=document.getElementById('cam'); var c=document.createElement('canvas'); c.width=v.videoWidth; c.height=v.videoHeight;
  c.getContext('2d').drawImage(v,0,0); setPhoto(c); }
function retake(){ document.getElementById('shotPane').style.display='none'; startCam(); document.getElementById('retakeBtn').style.display='none'; }
document.getElementById('fileInp').addEventListener('change', function(){
  var f=this.files && this.files[0]; if(!f) return;
  var img=new Image(); img.onload=function(){ var c=document.createElement('canvas'); c.width=img.width; c.height=img.height; c.getContext('2d').drawImage(img,0,0); setPhoto(c); };
  img.src=URL.createObjectURL(f);
});
// WebNFC read (Chrome Android etc.)
document.getElementById('nfcBtn').addEventListener('click', async function(){
  if(!('NDEFReader' in window)){ alert('This browser/device has no WebNFC support.\nUse the hospital card reader workstation, or key the Card No manually.'); return; }
  try{
    var reader=new NDEFReader(); this.disabled=true; this.textContent='Tap card…';
    await reader.scan();
    reader.onreading=function(e){ document.getElementById('card_no').value=(e.serialNumber||'').replace(/:/g,'').toUpperCase();
      navigator.vibrate && navigator.vibrate(120); };
    setTimeout(function(){ var b=document.getElementById('nfcBtn'); b.disabled=false; b.innerHTML='&#xf7e4; Read NFC'; }, 15000);
  }catch(err){ alert('NFC unavailable: '+err.message); document.getElementById('nfcBtn').disabled=false; document.getElementById('nfcBtn').innerHTML='&#xf7e4; Read NFC'; }
});
</script>
<?php include __DIR__ . '/includes/footer.php'; exit;
}

// ---------------- LIST + SEARCH ----------------
$q  = get('q');
$qn = get('qn');   // names
$qi = get('nin');  // NIN
$qp = get('mob');  // mobile (either network)
$page = max(1, intval(get('page'))); $per = 15;
$conds = array(); $args = array();
if ($q  !== '') { $conds[] = '(member_no LIKE ? OR full_names LIKE ? OR national_id LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; $args[]="%$q%"; }
if ($qn !== '') { $conds[] = 'full_names LIKE ?'; $args[]="%$qn%"; }
if ($qi !== '') { $conds[] = 'national_id LIKE ?'; $args[]="%$qi%"; }
if ($qp !== '') { $d = preg_replace('/\D/','',preg_replace('/^0/','256',$qp)); $conds[]='(mtn_no LIKE ? OR airtel_no LIKE ? OR mtn_no LIKE ? OR airtel_no LIKE ?)'; $args[]="%$d%"; $args[]="%$d%"; $args[]="%0$qp%"; $args[]="%0$qp%"; }
$where = $conds ? implode(' AND ', $conds) : '1=1';
$st = db()->prepare("SELECT COUNT(*) FROM members WHERE $where"); $st->execute($args); $total = intval($st->fetchColumn());
$off = ($page - 1) * $per;
if ($has_ext) {
    $st = db()->prepare("SELECT id, member_no, full_names, gender, phone_no, mtn_no, airtel_no, district, lc1_village, national_id, card_no, registration_date, status, deposit_amount, (photo IS NOT NULL) AS has_photo FROM members WHERE $where ORDER BY full_names LIMIT $per OFFSET $off");
} else {
    $st = db()->prepare("SELECT id, member_no, full_names, gender, phone_no, national_id, registration_date, status FROM members WHERE $where ORDER BY full_names LIMIT $per OFFSET $off");
}
$st->execute($args); $rows = $st->fetchAll();

$pg_title = 'Members'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head">
  <div><h1>Members Registry</h1><span class="sub"><?php echo num($total); ?> member(s) <?php echo ($q.$qn.$qi.$qp)!==''?'matching search':''; ?> &middot; registration with photo, card &amp; audit</span></div>
  <div class="noprint"><a class="btn success" style="font-size:14px;padding:10px 22px" href="members.php?a=new">+ Register new member</a></div>
</div>

<div class="panel noprint" style="padding-bottom:10px">
<form method="get" action="members.php">
  <div class="formgrid" style="grid-template-columns:repeat(4,1fr);gap:10px">
    <div><label class="f">Member No.</label><input class="f" name="q" value="<?php echo e($q); ?>" placeholder="KH- or KMH-…"></div>
    <div><label class="f">Full names</label><input class="f" name="qn" value="<?php echo e($qn); ?>" placeholder="contains…"></div>
    <div><label class="f">NIN number</label><input class="f" name="nin" value="<?php echo e($qi); ?>"></div>
    <div style="display:flex;gap:8px;align-items:flex-end">
      <div style="flex:1"><label class="f">Mobile (MTN/Airtel)</label><input class="f" name="mob" value="<?php echo e($qp); ?>"></div>
      <button class="btn primary" type="submit" style="height:38px">Search</button>
      <?php if (($q.$qn.$qi.$qp)!==''): ?><a class="btn plain" href="members.php" style="height:38px;line-height:22px">Clear</a><?php endif; ?>
    </div>
  </div>
</form>
</div>

<div class="twrap"><table class="data">
<tr><th style="width:46px"></th><th>Member No.</th><th>Full names</th><th>Mobile (MTN/Airtel)</th><th>District / LC1</th><th>NIN</th><th>Card No.</th><th>Reg. date</th><th>Status</th><th class="num">Deposit</th><th class="noprint">Actions</th></tr>
<?php if (!$rows): ?><tr><td colspan="11" style="color:#5b7c99">No members found<?php echo ($q.$qn.$qi.$qp)!==''?' for your search — try partial values.':''; ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr>
<td><?php echo $has_ext ? '<img src="memberpic.php?id=' . e($r['id']) . '&amp;s=1" class="mini-photo" onerror="this.style.display=&quot;none&quot;">' : ''; ?></td>
<td><b><?php echo e($r['member_no']); ?></b></td>
<td><?php echo e($r['full_names']); ?> <span style="color:#7a94aa;font-size:11px"><?php echo e($r['gender']); ?></span></td>
<td style="font-size:12px"><?php echo e($has_ext ? phone_disp($r['mtn_no']) : phone_disp($r['phone_no'])); ?><br><span style="color:#7a94aa"><?php echo $has_ext ? e(phone_disp($r['airtel_no'])) : ''; ?></span></td>
<td><?php echo $has_ext ? e($r['district']) . ' <span style="color:#7a94aa">/ ' . e($r['lc1_village']) . '</span>' : '—'; ?></td>
<td style="font-size:12px"><?php echo e($r['national_id']); ?></td>
<td style="font-size:11px"><?php echo $has_ext ? e($r['card_no']) : '—'; ?></td>
<td><?php echo e(date('d M Y', strtotime($r['registration_date']))); ?></td>
<td><span class="badge <?php echo $r['status']=='Active'?'green':($r['status']=='Suspended'?'red':'grey'); ?>"><?php echo e($r['status']); ?></span></td>
<td class="num" style="font-size:12px"><?php echo $has_ext && floatval($r['deposit_amount'])>0 ? ugx($r['deposit_amount']) : '—'; ?></td>
<td class="noprint actions">
  <a class="btn small plain" href="member.php?id=<?php echo e($r['id']); ?>" title="Open file">File</a>
  <a class="btn small plain" href="members.php?a=edit&id=<?php echo e($r['id']); ?>" title="Edit / re-capture photo">Edit</a>
  <a class="btn small plain" href="idcard.php?id=<?php echo e($r['id']); ?>" target="_blank" title="Print ID card">ID</a>
</td></tr>
<?php endforeach; ?>
</table></div>
<?php echo pager($total, $per, $page, 'members.php' . (($q||$qn||$qi||$qp)!=='' ? '?q=' . urlencode($q) . '&qn=' . urlencode($qn) . '&nin=' . urlencode($qi) . '&mob=' . urlencode($qp) : '')); ?>
<style>
.fset { border:1px solid #bcd6ea; border-radius:4px; background:#fff; padding:12px 16px 14px; margin:0 0 14px; box-shadow:0 1px 2px rgba(18,71,111,.07); }
.fset legend { font:bold 12.5px "Segoe UI",Arial; color:#12476f; text-transform:uppercase; letter-spacing:.6px; background:#E3EFFA; border:1px solid #bcd6ea; border-radius:3px; padding:2px 12px; margin-left:6px; }
.photo-pane { border:1px dashed #a9cbe6; border-radius:4px; background:#F4F9FE; text-align:center; padding:8px; position:relative; }
.photo-pane video, .photo-pane img { max-width:100%; width:100%; max-height:340px; border-radius:3px; background:#000; display:block; }
.photo-pane img#shot { background:#fff; }
.photo-hint { font-size:12px; color:#5b7c99; padding:30px 10px; }
.mini-photo { width:34px; height:34px; border-radius:4px; object-fit:cover; border:1px solid #bcd6ea; background:#F4F9FE; }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
