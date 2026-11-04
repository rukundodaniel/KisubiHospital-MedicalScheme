<?php
/** v5.0 INSTITUTIONAL PORTAL (spec §2/§14/§3): the institution's own landing — dashboard,
 *  deposit & claim ledger, branches, its users (with branch/permission locks), SMS settings, statements.
 *  Facility-locked logins land here; every query below is server-scoped to their institution. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/wallet-core.php';
require_once __DIR__ . '/includes/insurance-core.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_login();
if (!wa_require_tbl()) { include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/footer.php'; exit; }
$lockedFid = fac_scope_fid();
$OWNER = $lockedFid === 0;
$GF = $OWNER ? intval(get('facility')) : $lockedFid;
if ($GF <= 0) {
    if (!$OWNER) { flash('Your login is not attached to any institution — ask the Super Administrator.', 'err'); redirect('dashboard.php'); }
    $ls = fac_list(); $GF = count($ls) ? intval($ls[0]['id']) : 0;
}
$f = $GF > 0 ? fac_of($GF) : null;
if (!$f) { $page_title = 'Institution Portal'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>No institutions yet</h2><p>Create the first hospital, clinic or school under <a href="facilities.php">Hospitals &amp; Clinics</a> — the portal opens automatically.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit; }
$GF = intval($f['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $op = post('op');
    if ($op === 'branch') {
        $name = trim(post('name'));
        if ($name !== '') {
            $id = intval(post('id'));
            if ($id > 0) db()->prepare('UPDATE institution_branches SET name=?, code=?, address=?, phone=?, status=? WHERE id=? AND facility_id=?')->execute(array($name, strtoupper(trim(post('code'))), post('address'), post('phone'), post('status') === 'Inactive' ? 'Inactive' : 'Active', $id, $GF));
            else db()->prepare('INSERT INTO institution_branches (facility_id, name, code, address, phone, status, created_at, created_by) VALUES (?,?,?,?,?,?,?,?)')->execute(array($GF, $name, strtoupper(trim(post('code'))), post('address'), post('phone'), 'Active', date('Y-m-d H:i:s'), $_SESSION['uname']));
            log_action('Branch ' . ($id ? 'updated' : 'created') . ' "' . $name . '" for ' . $f['name'], 'institution_branches', $id ?: intval(db()->lastInsertId()));
            flash('Branch saved.', 'ok');
        }
        redirect('institution.php' . ($OWNER ? '?facility=' . $GF : ''));
    }
    if ($op === 'user' && (can(array('Super Admin', 'Facility Admin')) || $OWNER)) {
        $un = trim(post('username')); $pw = post('password');
        $roles = array('Facility Admin', 'Finance Officer', 'Accountant', 'Claims Officer', 'Receptionist', 'Billing Officer', 'Medical Officer', 'Nurse', 'Pharmacist', 'School Administrator', 'Bursar', 'Records Officer', 'User');
        $staff = in_array(post('staff_role'), $roles, true) ? post('staff_role') : 'User';
        $gate = in_array($staff, array('Facility Admin', 'School Administrator'), true) ? 'Facility Admin' : ($staff === 'User' ? 'User' : (in_array($staff, array('Receptionist'), true) ? 'Reception' : 'User'));
        $perms = array(); foreach (array('settle', 'users', 'import', 'clinical') as $p) if (in_array($p, (array)(isset($_POST['perms']) ? $_POST['perms'] : array()), true)) $perms[] = $p;
        $uid = intval(post('id'));
        try {
            if ($uid > 0) {
                $st = db()->prepare('SELECT * FROM users WHERE id=? AND facility_id=?'); $st->execute(array($uid, $GF)); $u = $st->fetch();
                if (!$u) { flash('User is not from this institution.', 'err'); redirect('institution.php' . ($OWNER ? '?facility=' . $GF : '')); }
                $set = array($staff, $gate, trim(post('full_name')) !== '' ? post('full_name') : $u['full_name'], post('phone'), intval(post('branch_id')), post('department'), implode(',', $perms), intval(post('active')) === 1 ? 1 : 0);
                $sql = 'UPDATE users SET staff_role=?, previledge=?, full_name=?, phone=?, branch_id=?, department=?, perms=?, active=?';
                $args = $set;
                if ($pw !== '') { $sql .= ', password_hash=?'; $args[] = password_hash($pw, PASSWORD_DEFAULT); }
                $sql .= ' WHERE id=? AND facility_id=?'; $args[] = $uid; $args[] = $GF;
                db()->prepare($sql)->execute($args);
                log_action('Institution user ' . $u['username'] . ' updated' . ($pw !== '' ? ' (password reset)' : ''), 'users', $uid);
                flash('User ' . e($u['username']) . ' updated' . ($pw !== '' ? ' — password reset done' : '') . '.', 'ok');
            } else {
                if ($un === '' || strlen((string)$pw) < 6) { flash('Username + password (6+) required.', 'err'); redirect('institution.php' . ($OWNER ? '?facility=' . $GF : '') . '#users'); }
                $st = db()->prepare('SELECT id FROM users WHERE username=?'); $st->execute(array($un));
                if ($st->fetch()) { flash('That username exists already.', 'err'); }
                else {
                    db()->prepare('INSERT INTO users (username, password_hash, full_name, previledge, phone, active, facility_id, created_at, staff_role, branch_id, department, perms) VALUES (?,?,?,?,?,?,?, ?,?,?,?,?)')
                       ->execute(array($un, password_hash($pw, PASSWORD_DEFAULT), trim(post('full_name')) !== '' ? post('full_name') : $un, $gate, trim(post('phone')), 1, $GF, date('Y-m-d H:i:s'), $staff, intval(post('branch_id')), post('department'), implode(',', $perms)));
                    log_action('Institution user ' . $un . ' created for ' . $f['name'] . ' (' . $staff . ')', 'users', intval(db()->lastInsertId()));
                    flash('User <b>' . e($un) . '</b> created — locked to ' . e($f['name']) . '.', 'ok');
                }
            }
        } catch (Exception $e) { flash('Could not save user: ' . e($e->getMessage()), 'err'); }
        redirect('institution.php' . ($OWNER ? '?facility=' . $GF : '') . '#users');
    }
    if ($op === 'sms') {
        db()->prepare("UPDATE facilities SET sms_active=? WHERE id=?")->execute(array(post('sms_active') === '1' ? 'Yes' : 'No', $GF));
        flash('Institution notification settings saved.', 'ok'); redirect('institution.php' . ($OWNER ? '?facility=' . $GF : '') . '#sms');
    }
}

/* -------- dashboard data -------- */
$bene = db()->prepare("SELECT COUNT(*) n, SUM(CASE WHEN status='Active' AND eligibility='Active' AND (cover_expiry IS NULL OR cover_expiry='' OR cover_expiry>=?) THEN 1 ELSE 0 END) act FROM members WHERE facility_id=?");
$bene->execute(array(today(), $GF)); $bene = $bene->fetch();
$mStart = date('Y-m-01');
function cl_count($fid, $st) { $s = db()->prepare("SELECT COUNT(*) FROM facility_claims WHERE facility_id=? AND status=?"); $s->execute(array($fid, $st)); return intval($s->fetchColumn()); }
function cl_sum($fid, $st) { $s = db()->prepare("SELECT COALESCE(SUM(COALESCE(amount_paid, COALESCE(amount_approved, amount_billed))),0) FROM facility_claims WHERE facility_id=? AND status=?"); $s->execute(array($fid, $st)); return (float)$s->fetchColumn(); }
$cSub = cl_count($GF, 'Pending') + cl_count($GF, 'Resubmitted');
$cApp = cl_count($GF, 'Cleared') + cl_count($GF, 'Paid') + cl_count($GF, 'PartPaid');
$cRej = cl_count($GF, 'Rejected'); $cPend = cl_count($GF, 'Under Review') + cl_count($GF, 'Partially Approved') + cl_count($GF, 'Approved');
$insPaid = db()->prepare("SELECT COALESCE(SUM(d.amount),0) FROM disbursements d WHERE d.facility_id=? AND d.reversed=0"); $insPaid->execute(array($GF)); $insPaid = (float)$insPaid->fetchColumn();
$txns = db()->prepare("SELECT t.*, b.name branch FROM facility_txns t LEFT JOIN institution_branches b ON b.id=t.branch_id WHERE t.facility_id=? ORDER BY t.id DESC LIMIT 8");
$txns->execute(array($GF)); $txns = $txns->fetchAll();
$setts = array(); try { $sq93 = db()->prepare("SELECT id, bill_no, patient_name, total, status, receipt_no FROM member_bills WHERE facility_id=? AND status<>'Cancelled' ORDER BY id DESC LIMIT 8"); $sq93->execute(array($GF)); $setts = $sq93->fetchAll(); } catch (Exception $e) {}
$branches = array(); try { $branches = db()->query('SELECT * FROM institution_branches WHERE facility_id=' . $GF . ' ORDER BY name')->fetchAll(); } catch (Exception $e) {}
$facUsers = array();
try { $st = db()->prepare("SELECT u.*, (SELECT MAX(at) FROM login_log lg WHERE lg.username=u.username) last_seen FROM users u WHERE u.facility_id=? ORDER BY u.username"); $st->execute(array($GF)); $facUsers = $st->fetchAll(); } catch (Exception $e) {}
$logins = array();
try { $names = array_map(function ($u) { return "'" . addslashes($u['username']) . "'"; }, $facUsers);
    if ($names) $logins = db()->query('SELECT * FROM login_log WHERE username IN (' . implode(',', $names) . ') ORDER BY id DESC LIMIT 15')->fetchAll(); } catch (Exception $e) {}
$months = month_keys(6);
$moSettle = array();
foreach ($months as $mk50) { $fromS = $mk50 . '-01'; $toS = date('Y-m-t', strtotime($fromS));
  $sq = db()->prepare("SELECT COALESCE(SUM(total),0) FROM member_bills WHERE facility_id=? AND status='Settled' AND bill_date BETWEEN ? AND ?");
  $sq->execute(array($GF, $fromS, $toS)); $moSettle[$mk50] = (float)$sq->fetchColumn(); }
$smsActive = !isset($f['sms_active']) || (string)$f['sms_active'] !== 'No';

$page_title = 'Portal — ' . $f['name'];
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>&#127970; <?php echo e($f['name']); ?> — Institution Portal</h1>
 <span class="sub"><?php echo fac_chip($f['ftype']); ?> &middot; institution ID <b><?php echo e($f['code']); ?></b> &middot; all records you create are tagged to this institution automatically.</span></div>

<div style="display:flex;gap:12px;flex-wrap:wrap;margin:10px 0">
 <div class="kpi"><div class="k-label">Beneficiaries</div><div class="k-value"><?php echo num(intval($bene['n'])); ?></div><div class="k-sub"><?php echo num(intval($bene['act'])); ?> active · <?= num(intval($bene['n']) - intval($bene['act'])) ?> blocked</div></div>
 <div class="kpi green"><div class="k-label">Deposit-funded settlements this month</div><div class="k-value"><?php echo ugx(mb_stats($GF)['deducted']); ?></div><div class="k-sub">member deposits → facility claims → Kisubi pays the facility</div></div>
 <div class="kpi gold"><div class="k-label">Claims — pending review</div><div class="k-value"><?php echo num($cPend + $cSub); ?></div><div class="k-sub"><?php echo num($cApp); ?> approved · <?php echo num($cRej); ?> rejected · insurer paid <?php echo ugx($insPaid); ?></div></div>
</div>

<?php if (mb_has_tbl()): $mbS = mb_stats($GF);
 $cl = db()->query("SELECT COUNT(*) n, COUNT(CASE WHEN status IN ('Submitted','Pending','Resubmitted','Under Review','Cleared','Scheduled','Received') THEN 1 END) wait, COUNT(CASE WHEN status IN ('Approved','Partially Approved') THEN 1 END) app, COUNT(CASE WHEN status='Rejected' THEN 1 END) rej FROM facility_claims WHERE facility_id=" . $GF)->fetch();
 $paidF = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM disbursements WHERE facility_id=' . $GF . (function_exists('cl_has') && cl_has('disbursements','reversed') ? ' AND reversed=0' : ''))->fetchColumn(); ?>
<div style="display:flex;gap:12px;flex-wrap:wrap;margin:10px 0">
 <div class="kpi"><div class="k-label">Member deposit balances</div><div class="k-value"><?php echo ugx($mbS['deposits']); ?></div><div class="k-sub"><?php echo num($mbS['funded']); ?> funded · <?php echo num($mbS['short']); ?> at zero</div></div>
 <div class="kpi"><div class="k-label">Member bills</div><div class="k-value"><?php echo num($mbS['settled']); ?> settled</div><div class="k-sub"><?php echo num($mbS['bills']); ?> created · <?php echo ugx($mbS['deducted']); ?> deducted · <?php echo num($mbS['pending_n']); ?> pending funds</div></div>
 <div class="kpi"><div class="k-label">Claims with Kisubi HQ</div><div class="k-value"><?php echo num(intval($cl['n'])); ?></div><div class="k-sub"><?php echo num(intval($cl['wait'])); ?> awaiting action · <?php echo num(intval($cl['app'])); ?> approved · <?php echo num(intval($cl['rej'])); ?> rejected</div></div>
 <div class="kpi green"><div class="k-label">Disbursements received</div><div class="k-value"><?php echo ugx($paidF); ?></div><div class="k-sub">tracked live — no phone calls needed · <a href="claims-batch.php?facility=<?php echo $GF ?>&view=list">claim tracking &#8599;</a></div></div>
</div><?php endif; ?>

<div class="panel"><h2 style="margin-top:0">Shortcuts</h2>
<div style="display:flex;gap:8px;flex-wrap:wrap">
 <a class="btn primary" href="member-bills.php?facility=<?php echo $GF; ?>">&#129534; Bill members (deposit settle)</a>
 <a class="btn" href="institution-members.php?facility=<?php echo $GF; ?>">&#128101; Beneficiaries</a>
 <a class="btn" href="pricelist.php?facility=<?php echo $GF; ?>">&#128203; Price list</a>
 <a class="btn" href="facility-desk.php?facility=<?php echo $GF; ?>">&#127970; Service desk</a>
 <a class="btn" href="facility-claims.php?facility=<?php echo $GF; ?>">&#9878;&#65039; Claims &amp; tracking</a>
 <a class="btn" href="facility-clinical.php?facility=<?php echo $GF; ?>">&#129658; Clinical</a>
 <a class="btn" href="member-account.php?member=<?php echo intval(get('member')); ?>&facility=<?php echo $GF; ?>&csv=1">&#128196; Member statement (CSV)</a>
 <a class="btn primary" style="background:#1e7f3c" href="member-bills.php?facility=<?php echo $GF; ?>">&#129534; CREATE MEMBER BILL</a>
 <a class="btn" href="member-bills.php?view=list&facility=<?php echo $GF; ?>">&#128203; Bill register &amp; receipts</a>
 <a class="btn" href="claims-batch.php?facility=<?php echo $GF; ?>">&#128230; Submit claim to Kisubi</a>
</div></div>

<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">
<div class="panel" style="flex:2;min-width:430px"><h2 style="margin-top:0">&#128200; Deposit-funded settlements — last 6 months</h2>
 <canvas id="cSettle" height="110"></canvas>
 <script>window.addEventListener('load',function(){if(window.Chart){new Chart(document.getElementById('cSettle'),{type:'bar',data:{labels:<?php echo json_encode(array_map('mlabel', $months)); ?>,datasets:[{label:'Beneficiary bill settlements (UGX)',data:<?php echo json_encode(array_values(array_map('intval', $moSettle))); ?>,backgroundColor:'#1B6BB3'}],options:{plugins:{legend:{display:false}},scales:{y:{ticks:{callback:function(v){return (v/1e6)+'M';}}}}}});}});</script>
 <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:10px;border-top:1px dashed #bcd6ea;padding-top:8px">
  <div style="flex:1;min-width:260px"><b style="font-size:12.5px;color:#12476f">Recent settlements</b>
   <?php foreach ($setts as $s): ?><div style="font-size:12px;border-bottom:1px solid #eef4fa;padding:4px 0"><a href="receipt-member.php?id=<?php echo e($s['id']); ?>"><b><?php echo e($s['bill_no']); ?></b></a> · <?php echo e($s['patient_name']); ?> · <?php echo ugx($s['total']); ?> <span style="color:#7a94aa">(member deposit<?php echo $s['status'] !== 'Settled' ? ' · ' . strtoupper(e($s['status'])) : ''; ?>)</span></div><?php endforeach;
   if (!$setts) echo '<div style="font-size:12px;color:#7a94aa">None yet.</div>'; ?></div>
  <div style="flex:1;min-width:260px"><b style="font-size:12.5px;color:#12476f">Recent desk activity</b>
   <?php foreach ($txns as $t): ?><div style="font-size:12px;border-bottom:1px solid #eef4fa;padding:4px 0"><?php echo e($t['txn_date']); ?> · <?php echo e($t['patient_name']); ?> · <?php echo ugx($t['amount']); ?> <span style="color:<?php echo $t['status'] === 'Settled' ? '#20603a' : ($t['status'] === 'Void' ? '#8c2424' : '#8a6410'); ?>"><?php echo strtoupper(e($t['status'])); ?></span><?php echo $t['branch'] ? ' · ' . e($t['branch']) : ''; ?></div><?php endforeach;
   if (!$txns) echo '<div style="font-size:12px;color:#7a94aa">Nothing on the desk yet.</div>'; ?></div>
 </div></div>

<div class="panel" style="flex:1;min-width:320px" id="sms"><h2 style="margin-top:0">&#128241; SMS notifications</h2>
 <form method="post"><input type="hidden" name="op" value="sms"><?php echo csrf_field(); ?>
  <label style="font-size:13px"><input type="checkbox" name="sms_active" value="1" <?php echo $smsActive ? 'checked' : ''; ?> <?php echo $OWNER || can(array('Facility Admin')) ? '' : 'disabled'; ?>> Send settlement / claim / receipt SMS for this institution</label>
  <div style="margin-top:8px"><button class="btn small" type="submit" <?php echo $OWNER || can(array('Facility Admin')) ? '' : 'disabled'; ?>>Save</button></div>
 </form>
 <p style="font-size:11.5px;color:#5b7c99;margin-bottom:0">Delivery follows the central SMS Centre mode (live vs queue). Pending templates stay queued until the gateway is activated — settlement is never blocked by SMS<?php echo $smsActive ? '' : ' <b>(currently OFF for this institution)</b>'; ?>.</p></div>
</div>

<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">
<div class="panel" style="flex:1;min-width:330px"><h2 style="margin-top:0">&#127970; Branches</h2>
 <table class="data" style="margin:0"><tr><th>Branch</th><th>Code</th><th>Status</th><th></th></tr>
 <?php foreach ($branches as $b): ?>
 <tr><td><b><?php echo e($b['name']); ?></b><?php echo $b['address'] !== '' ? '<br><span style="font-size:11px;color:#7a94aa">' . e($b['address']) . '</span>' : ''; ?></td>
  <td><?php echo e($b['code']); ?></td><td><?php echo e($b['status']); ?></td>
  <td><?php if (can(array('Super Admin', 'Facility Admin')) || $OWNER): ?><a class="btn small plain" href="institution.php<?php echo $OWNER ? '?facility=' . $GF : ''; ?>#branchForm" onclick="document.getElementById('bId').value='<?php echo e($b['id']); ?>';document.getElementById('bName').value=<?php echo e(json_encode($b['name'])); ?>;document.getElementById('bCode').value=<?php echo e(json_encode((string)$b['code'])); ?>;return true;">Edit</a><?php endif; ?></td></tr>
 <?php endforeach; if (!$branches) echo '<tr><td colspan="4" style="color:#7a94aa">Single-site so far — add a branch to lock users and bills to it.</td></tr>'; ?>
 </table>
 <?php if (can(array('Super Admin', 'Facility Admin')) || $OWNER): ?>
 <form method="post" id="branchForm" style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;align-items:flex-end">
  <?php echo csrf_field(); ?><input type="hidden" name="op" value="branch"><input type="hidden" name="id" id="bId" value="0">
  <div style="flex:2"><label class="f">Name *</label><input class="f" id="bName" name="name" required style="margin:0"></div>
  <div style="flex:1"><label class="f">Code</label><input class="f" id="bCode" name="code" style="margin:0"></div>
  <div style="flex:1"><label class="f">Status</label><select class="f" name="status" style="margin:0"><option>Active</option><option>Inactive</option></select></div>
  <button class="btn small primary" type="submit">Save</button>
 </form><?php endif; ?></div>

<div class="panel" style="flex:2;min-width:420px" id="users"><h2 style="margin-top:0">&#128100; Institution users &middot;
 <?php echo $OWNER || can(array('Super Admin', 'Facility Admin')) ? '+ create' : 'view'; ?></h2>
 <table class="data" style="margin:0"><tr><th>User</th><th>Staff role</th><th>Access</th><th>Branch / dept</th><th>Last seen</th><th></th></tr>
 <?php foreach ($facUsers as $u): $canEdit = $OWNER || can(array('Facility Admin')); ?>
 <tr><td><b><?php echo e($u['username']); ?></b><br><span style="font-size:11px;color:#7a94aa"><?php echo e($u['full_name']); ?></span></td>
  <td><?php echo e((string)$u['staff_role']); ?></td>
  <td style="font-size:11px"><b style="color:<?php echo intval($u['active']) ? '#20603a' : '#8c2424'; ?>"><?php echo intval($u['active']) ? 'ACTIVE' : 'DISABLED'; ?></b> · <?php echo e($u['previledge']); ?><?php echo $u['perms'] !== '' ? '<br>perms: ' . e($u['perms']) : ''; ?></td>
  <td style="font-size:11px"><?php $bn = ''; foreach ($branches as $b) if (intval($b['id']) === intval($u['branch_id'])) $bn = $b['name']; echo $bn !== '' ? e($bn) : 'all'; echo $u['department'] !== '' ? ' · ' . e($u['department']) : ''; ?></td>
  <td style="font-size:11px"><?php echo e(substr((string)$u['last_seen'], 0, 16)); ?></td>
  <td><?php if ($canEdit): ?><button class="btn small plain" type="button" onclick="edU(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES); ?>)">Edit / reset pw</button><?php endif; ?></td></tr>
 <?php endforeach; if (!$facUsers) echo '<tr><td colspan="6" style="color:#7a94aa">No portal users yet.</td></tr>'; ?>
 </table>
 <?php if (can(array('Super Admin', 'Facility Admin')) || $OWNER): ?>
 <form method="post" id="uForm" style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:8px">
  <?php echo csrf_field(); ?><input type="hidden" name="op" value="user"><input type="hidden" name="id" id="uId" value="0">
  <div><label class="f">Username *</label><input class="f" id="uUn" name="username" style="margin:0"></div>
  <div><label class="f">Full name</label><input class="f" id="uFn" name="full_name" style="margin:0"></div>
  <div><label class="f">Password <?php echo '(blank = keep)'; ?></label><input class="f" id="uPw" name="password" type="text" style="margin:0"></div>
  <div><label class="f">Staff role</label><select class="f" id="uRole" name="staff_role" style="margin:0"><?php foreach (array('Facility Admin','Finance Officer','Accountant','Claims Officer','Receptionist','Billing Officer','Medical Officer','Nurse','Pharmacist','School Administrator','Bursar','Records Officer','User') as $r): ?><option><?php echo $r; ?></option><?php endforeach; ?></select></div>
  <div><label class="f">Phone</label><input class="f" id="uPh" name="phone" style="margin:0"></div>
  <div><label class="f">Branch</label><select class="f" id="uBr" name="branch_id" style="margin:0"><option value="0">All branches</option><?php foreach ($branches as $b): ?><option value="<?php echo e($b['id']); ?>"><?php echo e($b['name']); ?></option><?php endforeach; ?></select></div>
  <div><label class="f">Department</label><input class="f" id="uDp" name="department" style="margin:0" placeholder="e.g. Laboratory"></div>
  <div><label class="f">Active</label><select class="f" id="uAct" name="active" style="margin:0"><option value="1">Yes</option><option value="0">No — deactivate</option></select></div>
  <div style="grid-column:1/-1"><label class="f" style="display:inline">Permissions:</label>
   <?php foreach (array('settle' => 'settle member bills', 'users' => 'manage users', 'import' => 'bulk imports', 'clinical' => 'clinical suite') as $k => $lbl): ?>
   <label style="font-size:12px;margin-right:10px"><input type="checkbox" class="uPerm" value="<?php echo $k; ?>"> <?php echo $lbl; ?></label><?php endforeach; ?>
   <span style="font-size:11px;color:#7a94aa">(blank = full standard access for the staff role)</span></div>
  <div style="grid-column:1/-1"><button class="btn small primary" type="submit">Save user</button> <button class="btn small plain" type="button" onclick="edU(null)">Clear</button></div>
 </form>
 <script>function edU(u){var f=document.getElementById('uForm');if(!u){f.reset();document.getElementById('uId').value=0;document.getElementById('uUn').readOnly=false;document.querySelectorAll('.uPerm').forEach(function(c){c.checked=false;});return;}
 document.getElementById('uId').value=u.id;var un=document.getElementById('uUn');un.value=u.username;un.readOnly=true;document.getElementById('uFn').value=u.full_name;document.getElementById('uPh').value=u.phone||'';document.getElementById('uRole').value=u.staff_role||'User';document.getElementById('uBr').value=u.branch_id||0;document.getElementById('uDp').value=u.department||'';document.getElementById('uAct').value=String(u.active);
 var ps=(u.perms||'').split(',');document.querySelectorAll('.uPerm').forEach(function(c){c.checked=ps.indexOf(c.value)>=0 && ps[0]!=='';});f.scrollIntoView({behavior:'smooth'});}</script>
 <?php endif; ?>
 <?php if ($logins): ?><details style="margin-top:8px"><summary style="cursor:pointer;font-size:12px;color:#1B6BB3">Login history (this institution)</summary>
  <table class="data" style="margin:6px 0 0"><tr><th>When</th><th>User</th><th>Outcome</th><th>IP</th></tr>
  <?php foreach ($logins as $lg): ?><tr><td style="font-size:11.5px"><?php echo e(substr((string)$lg['at'], 0, 19)); ?></td><td><?php echo e($lg['username']); ?></td><td><b style="color:<?php echo $lg['outcome'] === 'OK' ? '#20603a' : '#8c2424'; ?>"><?php echo e($lg['outcome']); ?></b></td><td style="font-size:11px"><?php echo e($lg['ip']); ?></td></tr><?php endforeach; ?></table></details><?php endif; ?>
</div></div>
<?php if ($OWNER): ?><p class="noprint" style="font-size:12px;color:#5b7c99">You are viewing as the scheme owner — switch institution from the top of <a href="facilities.php">Hospitals &amp; Clinics</a> pages. Institution-locked staff land here straight after sign-in.</p><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
