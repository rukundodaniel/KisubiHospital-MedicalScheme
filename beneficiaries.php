<?php
/**
 * BENEFICIARIES (Beneficially) — full VB AddBeneficiallyForm parity:
 * photo via live camera or file upload, Gender, Age, Residence, Relationship,
 * PatientNo(NIN), phone; save/update/delete; list with search; Crystal-style print.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9
require_role(array('Super Admin', 'Administrator', 'Reception'));

$has_ext = false;
try { db()->query('SELECT residence FROM beneficiaries LIMIT 1'); $has_ext = true; } catch (Exception $e) { $has_ext = false; }

function save_ben_photo() {
    $data = isset($_POST['photo_data']) ? trim($_POST['photo_data']) : '';
    if ($data === '' || strpos($data, ',') === false) return null;
    $b64 = preg_replace('/\s/', '', substr($data, strpos($data, ',') + 1));
    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) < 1024 || strlen($bytes) > 4000000) return null;
    if (substr($bytes, 0, 3) !== "\xFF\xD8\xFF") return null; // JPEG magic only
    return $bytes;
}
function ben_stmt($sql, $vals, $lobPos = 0) {
    $st = db()->prepare($sql);
    $drv = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
    foreach (array_values($vals) as $i => $v) {
        $p = $i + 1;
        if ($lobPos && $p === $lobPos && is_string($v)) { $st->bindValue($p, $v, $drv === 'mysql' ? PDO::PARAM_STR : PDO::PARAM_LOB); }
        elseif ($v === null) { $st->bindValue($p, null, PDO::PARAM_NULL); }
        else { $st->bindValue($p, (string)$v, PDO::PARAM_STR); }
    }
    $st->execute();
    return $st;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op'); $mid = intval(post('member_id')); $bid = intval(post('id'));
    if ($op === 'save' || $op === 'update') {
        if ($mid <= 0 || post('full_names') === '') { flash('Member and beneficiary names are required.', 'err'); redirect('beneficiaries.php?a=new&member_id=' . intval($mid)); }
        $photo = save_ben_photo();
        if ($has_ext) {
            $base = array($mid, post('full_names'), post('relationship'), post('national_id'), post('phone_no'), post('residence'), post('gender'), post('age'));
            if ($op === 'update' && $bid > 0) {
                $sql = 'UPDATE beneficiaries SET member_id=?, full_names=?, relationship=?, national_id=?, phone_no=?, residence=?, gender=?, age=?' . ($photo !== null ? ', photo=?' : '') . ' WHERE id=?';
                $vals = $base; if ($photo !== null) $vals[] = $photo; $vals[] = $bid;
                ben_stmt($sql, $vals, $photo !== null ? count($base) + 1 : 0);
                flash('Beneficiary updated.'); log_action('Updated beneficiary ' . post('full_names'), 'beneficiaries', $bid);
            } else {
                $sql = 'INSERT INTO beneficiaries (member_id, full_names, relationship, national_id, phone_no, residence, gender, age' . ($photo !== null ? ', photo' : '') . ', created_at) VALUES (?,?,?,?,?,?,?,?' . ($photo !== null ? ',?' : '') . ',?)';
                $vals = $base; if ($photo !== null) $vals[] = $photo; $vals[] = date('Y-m-d H:i:s');
                ben_stmt($sql, $vals, $photo !== null ? 9 : 0);
                flash('Beneficiary added.'); log_action('Added beneficiary ' . post('full_names'), 'beneficiaries', intval(db()->lastInsertId()));
            }
        } else {
            if ($op === 'update' && $bid > 0) {
                db()->prepare('UPDATE beneficiaries SET member_id=?, full_names=?, relationship=?, national_id=?, phone_no=? WHERE id=?')
                   ->execute(array($mid, post('full_names'), post('relationship'), post('national_id'), post('phone_no'), $bid));
            } else {
                db()->prepare('INSERT INTO beneficiaries (member_id, full_names, relationship, national_id, phone_no, created_at) VALUES (?,?,?,?,?,?)')
                   ->execute(array($mid, post('full_names'), post('relationship'), post('national_id'), post('phone_no'), date('Y-m-d H:i:s')));
            }
            flash('Saved. Note: run migrate.php to enable photo/Gender/Age/Residence.');
        }
        if ($op === 'update' && $bid > 0) { redirect('beneficiaries.php?a=edit&id=' . $bid); }
        redirect('beneficiaries.php?a=new&member_id=' . intval($mid));   // VB: clear fields, keep member, refresh their list
    }
    if ($op === 'delete') {
        require_role(array('Super Admin', 'Administrator'));
        $bid = intval(post('id'));
        try { $owner = db()->prepare('SELECT member_id FROM beneficiaries WHERE id=?'); $owner->execute(array($bid)); $om = intval($owner->fetchColumn());
            db()->prepare('DELETE FROM beneficiaries WHERE id=?')->execute(array($bid));
            log_action('Removed beneficiary #' . $bid, 'beneficiaries', $bid);
            flash('Beneficiary removed.', 'info');
        } catch (Exception $ex) { flash('Cannot delete: ' . $ex->getMessage(), 'err'); }
        redirect('beneficiaries.php');
    }
}

$action = get('a');
$edit = null; $hasPhoto = false;
$memSel = array(); $selMember = null; $selBens = array(); $memExt = false;
if ($action === 'edit' && intval(get('id')) > 0) {
    $st = db()->prepare('SELECT * FROM beneficiaries WHERE id=?'); $st->execute(array(intval(get('id')))); $edit = $st->fetch();
    if ($edit && $has_ext) {
        try { $p = db()->prepare('SELECT LENGTH(photo) FROM beneficiaries WHERE id=?'); $p->execute(array($edit['id'])); $hasPhoto = intval($p->fetchColumn()) > 0; } catch (Exception $e) {}
    }
}

$members = db()->query('SELECT id, member_no, full_names FROM members ORDER BY full_names')->fetchAll();

$rows = array(); $fmid = intval(get('member_id')); $q = get('q');
if ($action === '' || $action === 'list') {
    $where = '1=1'; $args = array();
    if ($fmid > 0) { $where .= ' AND b.member_id=?'; $args[] = $fmid; }
    if ($q !== '') { $like = '%' . $q . '%'; $where .= ' AND (b.full_names LIKE ? OR b.relationship LIKE ? OR b.national_id LIKE ? OR m.full_names LIKE ? OR m.member_no LIKE ?)'; $args = array_merge($args, array($like, $like, $like, $like, $like)); }
    $sel = 'b.id, b.member_id, b.full_names, b.relationship, b.national_id, b.phone_no, b.created_at' . ($has_ext ? ', b.residence, b.gender, b.age, (b.photo IS NOT NULL) AS has_photo' : '');
    $st = db()->prepare("SELECT $sel, m.member_no, m.full_names AS member_name FROM beneficiaries b JOIN members m ON m.id=b.member_id WHERE $where ORDER BY m.full_names, b.id");
    $st->execute($args); $rows = $st->fetchAll();
}

if ($action === 'new' || $action === 'edit') {
    try { $memExt = true;
        $memSel = db()->query('SELECT id, member_no, full_names, address, phone_no, mtn_no, airtel_no, national_id, (photo IS NOT NULL) AS has_photo FROM members ORDER BY full_names')->fetchAll();
    } catch (Exception $e) { $memExt = false;
        $memSel = db()->query('SELECT id, member_no, full_names, address, phone_no FROM members ORDER BY full_names')->fetchAll();
    }
    $pick = $edit ? intval($edit['member_id']) : intval(get('member_id'));
    if ($pick > 0) {
        foreach ($memSel as $x) { if (intval($x['id']) === $pick) { $selMember = $x; break; } }
        $sb = db()->prepare($has_ext ? 'SELECT * FROM beneficiaries WHERE member_id=? ORDER BY id DESC' : 'SELECT id, member_id, full_names, relationship, national_id, phone_no, created_at FROM beneficiaries WHERE member_id=? ORDER BY id DESC');
        $sb->execute(array($pick)); $selBens = $sb->fetchAll();
    }
}

$pg_title = 'Beneficiaries'; include __DIR__ . '/includes/header.php'; ?>

<?php if ($action === 'new' || $action === 'edit'): $b = $edit ? $edit : array('member_id'=>intval(get('member_id')),'full_names'=>'','relationship'=>'','national_id'=>'','phone_no'=>'','residence'=>'','gender'=>'','age'=>''); ?>
<div class="page-head"><div><h1><?php echo $edit ? 'Edit beneficiary' : 'Add beneficiary'; ?> <span class="sub">(Beneficially workbench)</span></h1></div>
<div class="noprint"><a class="btn plain" href="beneficiaries.php">&laquo; All beneficiaries</a>
<?php if ($b['member_id']): ?><a class="btn plain" href="member.php?id=<?php echo e($b['member_id']); ?>">Member file</a>
<a class="btn plain" href="report-beneficiaries.php?member_id=<?php echo e($b['member_id']); ?>" target="_blank">Print report</a><?php endif; ?></div></div>

<div class="grid" style="grid-template-columns:330px 1fr;gap:14px;align-items:start">
<div class="panel noprint" style="padding:10px 12px;position:sticky;top:8px"><h2 style="margin-top:0">Members — click to select</h2>
<input class="f" id="memQ" placeholder="type to filter: name / member no / phone" style="margin-bottom:8px" autocomplete="off">
<div id="memList" style="max-height:560px;overflow:auto">
<?php foreach ($memSel as $x): $txt = strtolower($x['member_no'].' '.$x['full_names'].' '.(isset($x['phone_no'])?$x['phone_no']:'').' '.(isset($x['mtn_no'])?$x['mtn_no']:'')); ?>
<div class="memRow" data-txt="<?php echo e($txt); ?>" onclick="pickMember(<?php echo e($x['id']); ?>)"
  style="display:flex;gap:8px;align-items:center;padding:6px;border-bottom:1px solid #e3eef8;cursor:pointer;<?php echo intval($b['member_id'])===intval($x['id'])?'background:#DCEBFA;border-left:3px solid #1B6BB3':''; ?>">
  <?php if ($memExt && !empty($x['has_photo'])): ?><img src="memberpic.php?id=<?php echo e($x['id']); ?>&s=1" style="width:34px;height:40px;object-fit:cover;border-radius:3px;border:1px solid #cfe0ef">
  <?php else: ?><div style="width:34px;height:40px;background:#E3EFFA;border-radius:3px;border:1px dashed #9db8cc"></div><?php endif; ?>
  <div style="min-width:0"><b style="font-size:12px"><?php echo e($x['member_no']); ?></b>
  <div style="font-size:12px;color:#33536e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo e($x['full_names']); ?></div></div></div>
<?php endforeach; if (!$memSel): ?><div style="color:#5b7c99;font-size:12px">No members yet — register one first.</div><?php endif; ?>
</div>
<script>
document.getElementById('memQ').addEventListener('input', function(){ var v=this.value.toLowerCase().trim();
  document.querySelectorAll('.memRow').forEach(function(r){ r.style.display = !v || r.getAttribute('data-txt').indexOf(v)>-1 ? '' : 'none'; }); });
function pickMember(id){ location='beneficiaries.php?a=new&member_id='+id; }
</script></div>

<div>
<?php if ($selMember): ?>
<div class="panel" style="padding:10px 14px;display:flex;gap:14px;align-items:center">
  <?php if ($memExt && !empty($selMember['has_photo'])): ?><img src="memberpic.php?id=<?php echo e($selMember['id']); ?>" style="width:84px;height:100px;object-fit:cover;border:2px solid #12476f;border-radius:6px"><?php endif; ?>
  <div><b style="font-size:15px;color:#12476f"><?php echo e($selMember['full_names']); ?></b>
   <span style="background:#12476f;color:#ffd76e;font:bold 12px 'Courier New';padding:2px 8px;border-radius:3px;margin-left:8px"><?php echo e($selMember['member_no']); ?></span>
   <div style="font-size:12px;color:#5b7c99;margin-top:3px"><?php echo $memExt ? 'MTN '.e($selMember['mtn_no']).' &middot; Airtel '.e($selMember['airtel_no']) : e($selMember['phone_no']); ?>
   &middot; <?php echo e($selMember['address']); ?><?php echo $memExt && !empty($selMember['national_id']) ? ' &middot; NIN '.e($selMember['national_id']) : ''; ?></div></div>
</div>
<div class="panel" style="padding:8px 14px;margin-top:10px"><h2 style="margin:0 0 6px;font-size:14px">Beneficiaries of this member (<?php echo count($selBens); ?>)</h2>
<?php if ($selBens): ?><table class="data" style="margin:0">
<tr><th>#</th><th>Patient No</th><th>Name</th><th>Gender/Age</th><th>Relationship</th><th>Residence</th><th class="noprint"></th></tr>
<?php $bn=0; foreach ($selBens as $bb): $bn++; ?>
<tr><td><?php echo intval(count($selBens)-$bn+1); ?></td><td><?php echo e($bb['national_id']); ?></td>
<td><?php echo $has_ext ? '<img src="memberpic.php?id='.e($bb['id']).'&t=ben&s=1" style="width:26px;height:32px;object-fit:cover;border-radius:3px;margin-right:6px;vertical-align:middle">' : ''; ?><b><?php echo e($bb['full_names']); ?></b></td>
<td><?php echo $has_ext ? e($bb['gender']).($bb['age']!==''?' · '.e($bb['age']):'') : ''; ?></td><td><?php echo e($bb['relationship']); ?></td><td><?php echo $has_ext ? e($bb['residence']) : ''; ?></td>
<td class="noprint" style="white-space:nowrap"><a class="btn small plain" href="beneficiaries.php?a=edit&id=<?php echo e($bb['id']); ?>">Edit</a></td></tr>
<?php endforeach; ?></table>
<?php else: ?><div style="color:#5b7c99;font-size:12px">No beneficiaries for this member yet.</div><?php endif; ?></div>
<?php endif; ?>

<div class="panel"><form method="post" action="beneficiaries.php" id="benForm">
<?php echo csrf_field(); ?>
<input type="hidden" name="op" value="<?php echo $edit ? 'update' : 'save'; ?>">
<input type="hidden" name="id" value="<?php echo e($edit ? $edit['id'] : 0); ?>">
<input type="hidden" name="photo_data" id="photo_data" value="">
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px 16px">
  <div><label class="f">Member *</label>
    <select class="f" name="member_id" required>
      <option value="0">— select member —</option>
      <?php foreach ($members as $mm): ?><option value="<?php echo e($mm['id']); ?>" <?php echo intval($b['member_id'])==intval($mm['id'])?'selected':''; ?>><?php echo e($mm['member_no'].' — '.$mm['full_names']); ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="f">Beneficiary full names *</label><input class="f" name="full_names" value="<?php echo e($b['full_names']); ?>" placeholder="As on the patient card" required></div>
  <div><label class="f">Relationship</label>
    <select class="f" name="relationship"><?php $rel=array('','Spouse','Son','Daughter','Brother','Sister','Father','Mother','Guardian','Other');
    foreach ($rel as $r): ?><option <?php echo $b['relationship']===$r?'selected':''; ?>><?php echo e($r); ?></option><?php endforeach; ?></select></div>
  <div><label class="f">Patient No. / National ID</label><input class="f" name="national_id" value="<?php echo e($b['national_id']); ?>"></div>
  <div><label class="f">Phone</label><input class="f" name="phone_no" value="<?php echo e($b['phone_no']); ?>" placeholder="07... or 256..."></div>
  <div><label class="f">Residence</label><input class="f" name="residence" value="<?php echo e($b['residence']); ?>" placeholder="e.g. Kisubi Bbuleni"></div>
  <div><label class="f">Gender</label><select class="f" name="gender"><option <?php echo $b['gender']==='Male'?'selected':''; ?>>Male</option><option <?php echo $b['gender']==='Female'?'selected':''; ?>>Female</option></select></div>
  <div><label class="f">Age</label><input class="f" name="age" value="<?php echo e($b['age']); ?>" placeholder="e.g. 6"></div>
</div>

<fieldset style="border:1px solid #cfe0ef;border-radius:6px;padding:12px;margin-top:14px"><legend style="color:#12476f;font-weight:bold;padding:0 8px">Beneficiary photo</legend>
<div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start">
  <div id="photoBox" style="width:190px;text-align:center">
    <video id="camVid" autoplay playsinline style="display:none;width:190px;height:210px;object-fit:cover;background:#000;border:2px dashed #1B6BB3;border-radius:6px"></video>
    <img id="photoPrev" src="<?php echo $hasPhoto ? 'memberpic.php?id=' . e($b['id']) . '&t=ben' : ''; ?>" style="<?php echo $hasPhoto ? '' : 'display:none;'; ?>width:190px;height:210px;object-fit:cover;border:2px solid #12476f;border-radius:6px;background:#E3EFFA">
    <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;justify-content:center">
      <button type="button" class="btn small primary" id="btnCam">&#128247; Open camera</button>
      <button type="button" class="btn small" id="btnSnap" style="display:none">Snap</button>
      <button type="button" class="btn small plain" id="btnRetake" style="display:none">Retake</button>
    </div>
    <label class="btn small plain" style="cursor:pointer;margin-top:6px">&#128193; Upload file…<input type="file" accept="image/*" id="fileInp" style="display:none"></label>
    <canvas id="snapCv" style="display:none"></canvas>
    <?php if ($hasPhoto): ?><div style="font-size:11px;color:#5b7c99;margin-top:4px">Current photo saved in database.<br>Snap/upload to replace it.</div><?php endif; ?>
  </div>
  <div style="flex:1;min-width:220px;color:#5b7c99;font-size:12px">Same as the VB Beneficially form: take the photo with the webcam or pick a file. The picture is trimmed to a square, resized and stored as a JPEG image in the database. Leave untouched on edit to keep the current photo.
  <div style="margin-top:8px"><span id="camHint"></span></div></div>
</div></fieldset>

<div style="margin-top:14px;display:flex;gap:8px">
  <button class="btn primary" type="submit"><?php echo $edit ? 'Update beneficiary' : 'Save beneficiary'; ?></button>
  <button class="btn plain" type="button" onclick="clearBen()"><?php echo $edit ? 'Reset fields' : 'Clear form'; ?></button>
  <a class="btn plain" href="beneficiaries.php">Cancel</a>
</div>
</form></div>
</div>
</div>

<script>
(function(){
 var form=document.getElementById('benForm'); if(!form) return;
 form.addEventListener('submit', function(ev){
   var op=form.querySelector('[name=op]').value;
   var pd=document.getElementById('photo_data').value;
   if (op==='save' && pd==='') { ev.preventDefault(); alert('Please capture beneficiary photo first.'); return; }
   if (op==='update' && form.querySelector('[name=national_id]').value.trim()==='') { ev.preventDefault(); alert('Please enter Patient No.'); form.querySelector('[name=national_id]').focus(); return; }
   if (op==='update' && form.querySelector('[name=full_names]').value.trim()==='') { ev.preventDefault(); alert('Please enter beneficiary name.'); form.querySelector('[name=full_names]').focus(); return; }
 });
})();
function clearBen(){ ['full_names','national_id','phone_no','residence','age'].forEach(function(n){ var i=document.querySelector('#benForm [name='+n+']'); if(i) i.value=''; });
 var d=document.getElementById('photo_data'); if(d) d.value='';
 var im=document.getElementById('photoPrev'); if(im){ im.style.display='none'; im.removeAttribute('src'); }
 var bc=document.getElementById('btnCam'); if(bc){ bc.style.display=''; }
}
var CSRF_BEN='<?php echo e(csrf_token()); ?>'; (function(){
 var vid=document.getElementById('camVid'), img=document.getElementById('photoPrev'), cv=document.getElementById('snapCv');
 var bCam=document.getElementById('btnCam'), bSnap=document.getElementById('btnSnap'), bRet=document.getElementById('btnRetake'), file=document.getElementById('fileInp');
 var stream=null, dataUrl='';
 function showData(u){ dataUrl=u; document.getElementById('photo_data').value=u; img.src=u; img.style.display=''; vid.style.display='none'; bCam.style.display='none'; bSnap.style.display='none'; bRet.style.display=''; file.parentElement.style.display='none'; }
 function toJpeg(src, cb){ var im=new Image(); im.onload=function(){ var s=Math.min(im.width,im.height); var c=document.createElement('canvas'); c.width=384;c.height=384; var x=(im.width-s)/2,y=(im.height-s)/2; c.getContext('2d').drawImage(im,x,y,s,s,0,0,384,384); cb(c.toDataURL('image/jpeg',0.85)); }; im.src=src; }
 bCam.onclick=function(){ if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){ document.getElementById('camHint').textContent='Camera not available here — use Upload file.'; return; }
  navigator.mediaDevices.getUserMedia({video:{width:640,height:480},audio:false}).then(function(s){ stream=s; vid.srcObject=s; vid.style.display=''; img.style.display='none'; bCam.style.display='none'; bSnap.style.display=''; bRet.style.display='none'; }).catch(function(e){ document.getElementById('camHint').textContent='Camera blocked: '+e.message+' — use Upload file.'; }); };
 bSnap.onclick=function(){ var s=Math.min(vid.videoWidth,vid.videoHeight); cv.width=384;cv.height=384; var ctx=cv.getContext('2d'); ctx.drawImage(vid,(vid.videoWidth-s)/2,(vid.videoHeight-s)/2,s,s,0,0,384,384); if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;} showData(cv.toDataURL('image/jpeg',0.85)); };
 bRet.onclick=function(){ dataUrl=''; document.getElementById('photo_data').value=''; img.style.display='none'; img.removeAttribute('src'); if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;} bCam.style.display=''; bSnap.style.display='none'; bRet.style.display='none'; file.parentElement.style.display=''; };
 file.onchange=function(){ if(!file.files||!file.files[0])return; var r=new FileReader(); r.onload=function(){ toJpeg(r.result, showData); }; r.readAsDataURL(file.files[0]); };
})();
</script>

<?php else: ?>
<div class="page-head"><div><h1>Beneficiaries (Beneficially)</h1>
<span class="sub"><?php echo count($rows); ?> beneficiaries across all members &mdash; covered dependants for claims</span></div>
<div class="noprint" style="display:flex;gap:8px">
  <a class="btn primary" href="beneficiaries.php?a=new<?php echo $fmid ? '&member_id=' . $fmid : ''; ?>">+ Add beneficiary</a>
  <a class="btn plain" href="report-beneficiaries.php<?php echo $fmid ? '?member_id=' . $fmid : ''; ?>" target="_blank">&#128424; Print Beneficially report</a>
</div></div>

<div class="panel" style="padding:12px 16px"><form method="get" action="beneficiaries.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
  <div><label class="f">Search names / NIN / relationship</label><input class="f" name="q" value="<?php echo e($q); ?>" placeholder="type and Enter"></div>
  <div><label class="f">Member</label>
    <select class="f" name="member_id"><option value="0">All members</option>
    <?php foreach ($members as $mm): ?><option value="<?php echo e($mm['id']); ?>" <?php echo $fmid==intval($mm['id'])?'selected':''; ?>><?php echo e($mm['member_no'].' — '.$mm['full_names']); ?></option><?php endforeach; ?></select></div>
  <button class="btn primary" type="submit">Search</button>
  <?php if ($q!=='' || $fmid): ?><a class="btn plain" href="beneficiaries.php">Clear</a><?php endif; ?>
</form></div>

<div class="twrap"><table class="data">
<tr><th>Member</th><?php if ($has_ext): ?><th>Photo</th><?php endif; ?><th>Beneficiary</th><th>Relationship</th><?php if ($has_ext): ?><th>Gender / Age</th><th>Residence</th><?php endif; ?><th>National ID</th><th>Phone</th><th class="noprint">Actions</th></tr>
<?php if (!$rows): ?><tr><td colspan="9" style="color:#5b7c99">No beneficiaries found. Add them from a member's file page or the + Add button.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr><td><b><?php echo e($r['member_no']); ?></b><br><span style="font-size:11px;color:#5b7c99"><?php echo e($r['member_name']); ?></span></td>
<?php if ($has_ext): ?><td><?php echo !empty($r['has_photo']) ? '<img src="memberpic.php?id=' . e($r['id']) . '&t=ben&s=1" style="width:44px;height:52px;object-fit:cover;border-radius:4px;border:1px solid #cfe0ef">' : '<span style="color:#9db8cc;font-size:11px">none</span>'; ?></td><?php endif; ?>
<td><?php echo e($r['full_names']); ?></td><td><span class="badge blue"><?php echo e($r['relationship']); ?></span></td>
<?php if ($has_ext): ?><td><?php echo e($r['gender']); ?><?php echo $r['age']!==''?' · '.e($r['age']):''; ?></td><td><?php echo e($r['residence']); ?></td><?php endif; ?>
<td><?php echo e($r['national_id']); ?></td><td><?php echo e($r['phone_no']); ?></td>
<td class="noprint" style="white-space:nowrap">
  <a class="btn small plain" href="beneficiaries.php?a=edit&id=<?php echo e($r['id']); ?>">Edit</a>
  <a class="btn small plain" href="member.php?id=<?php echo e($r['member_id']); ?>">File</a>
  <?php if (can(array('Super Admin','Administrator'))): ?>
  <form method="post" action="beneficiaries.php" style="display:inline" onsubmit="return confirm('Remove beneficiary <?php echo e($r['full_names']); ?>?')">
    <?php echo csrf_field(); ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
    <button class="btn small danger" type="submit">Del</button></form><?php endif; ?>
</td></tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
