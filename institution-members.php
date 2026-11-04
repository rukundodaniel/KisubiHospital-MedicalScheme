<?php
/** v5.0 INSTITUTIONAL BENEFICIARY MANAGEMENT (spec §5): each institution registers and manages
 *  its own beneficiaries (members are stamped with its facility_id), with duplicate guarding,
 *  Excel-compatible CSV import/export and eligibility maintenance. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/wallet-core.php';
require_once __DIR__ . '/includes/insurance-core.php';
require_login();
require_role(array('Super Admin', 'Administrator', 'Reception', 'Facility Admin', 'Finance', 'User')); // v5.0: facility accounts (any role) may manage their own beneficiary list — locked + gated
if (!wa_require_tbl()) { include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/footer.php'; exit; }
$lockedFid = fac_scope_fid();
$GF = $lockedFid > 0 ? $lockedFid : intval(get('facility') ?: post('facility'));
if ($GF <= 0) { $ls = fac_list(); $GF = count($ls) ? intval($ls[0]['id']) : 0; }
$f = $GF > 0 ? fac_of($GF) : null;
if (!$f) { $page_title = 'Beneficiaries'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>No institutions yet</h2><p>Create one first — beneficiaries attach to it automatically.</p></div>'; include __DIR__ . '/includes/footer.php'; exit; }
$GF = intval($f['id']);
$CAN_EDIT = can(array('Super Admin', 'Administrator', 'Facility Admin', 'Reception'));
$multiAllowed = trim(setting('allow_multi_beneficiaries', '1')) !== '0';
$CATS = array('Principal', 'Spouse', 'Child', 'Dependant', 'Student', 'Staff', 'Other');

function im_next_inst_no($fid, $code) {
    $st = db()->prepare('SELECT COUNT(*) FROM members WHERE facility_id=?'); $st->execute(array($fid)); $n = intval($st->fetchColumn());
    $prefix = preg_replace('/[^A-Z0-9]/i', '', (string)$code) ?: 'MBR';
    for ($i = 0; $i < 800; $i++) {
        $try = $prefix . '-' . str_pad((string)($n + 1 + $i), 4, '0', STR_PAD_LEFT);
        $s2 = db()->prepare('SELECT id FROM members WHERE inst_member_no=?'); $s2->execute(array($try));
        if (!$s2->fetch()) return $try;
    }
    return $prefix . '-' . substr(md5((string)mt_rand()), 0, 6);
}
function im_dedupe($fid, $nat, $dob, $phone, $name) {
    $d = preg_replace('/\s+/', '', strtoupper((string)$name));
    $key = $fid . '|' . strtoupper(trim((string)$nat));
    $st = db()->prepare('SELECT id, member_no, full_names FROM members WHERE dedupe_key=? AND facility_id=? AND status<>?');
    try { $st->execute(array($key, $fid, 'Deceased')); if ($r = $st->fetch()) return $r; } catch (Exception $e) {}
    if (trim((string)$nat) !== '') { // national id clash anywhere in this institution
        $st = db()->prepare('SELECT id, member_no, full_names FROM members WHERE national_id=? AND facility_id=?');
        $st->execute(array(trim((string)$nat), $fid)); if ($r = $st->fetch()) return $r;
    }
    if (trim((string)$phone) !== '') {
        $dig = preg_replace('/\D/', '', (string)$phone);
        $st = db()->prepare("SELECT id, member_no, full_names FROM members WHERE facility_id=? AND REPLACE(REPLACE(REPLACE(phone_no,'+',''),'256','256'),' ','') LIKE ? LIMIT 1");
        $st->execute(array($fid, '%' . substr($dig, -9) . '%')); if ($r = $st->fetch()) return $r;
    }
    return null;
}
/** @return array(ok,msg,row) — row is the member (existing or new) */
function im_register($fid, $data) {
    $name = trim((string)(isset($data['full_names']) ? $data['full_names'] : ''));
    if ($name === '' || mb_strlen($name) < 3) return array('ok' => false, 'msg' => 'Full name required (3+ chars).');
    $nat = strtoupper(trim((string)(isset($data['national_id']) ? $data['national_id'] : '')));
    $phone = preg_replace('/[^0-9+]/', '', (string)(isset($data['phone_no']) ? $data['phone_no'] : ''));
    $dup = im_dedupe($fid, $nat, (string)(isset($data['dob']) ? $data['dob'] : ''), $phone, $name);
    if ($dup) return array('ok' => false, 'msg' => 'Duplicate: ' . $dup['member_no'] . ' — ' . $dup['full_names'] . ' matches the same national ID/phone at this institution.', 'dup' => true);
    if (trim(setting('allow_multi_beneficiaries', '1')) === '0' && $nat !== '') {
        try { $st = db()->prepare('SELECT member_no FROM members WHERE national_id=? AND facility_id<>? LIMIT 1'); $st->execute(array($nat, $fid));
            if ($r = $st->fetch()) return array('ok' => false, 'msg' => 'Multi-institution membership is disabled — NIN already registered at another institution (' . $r['member_no'] . ').', 'dup' => true); } catch (Exception $e) {}
    }
    $f = fac_of($fid);
    $key = $fid . '|' . $nat;
    $no = next_member_no();
    $instno = im_next_inst_no($fid, $f['code']);
    $cat = isset($data['bcategory']) && in_array($data['bcategory'], array('Principal', 'Spouse', 'Child', 'Dependant', 'Student', 'Staff', 'Other'), true) ? $data['bcategory'] : 'Principal';
    $by = isset($_SESSION['uname']) ? $_SESSION['uname'] : 'system';
    $cols50 = array(
        'member_no' => $no, 'full_names' => $name, 'national_id' => ($nat !== '' ? $nat : null),
        'gender' => in_array((string)(isset($data['gender']) ? $data['gender'] : ''), array('Male', 'Female'), true) ? $data['gender'] : 'Female',
        'phone_no' => $phone, 'address' => mb_substr((string)(isset($data['address']) ? $data['address'] : ''), 0, 200),
        'dob' => !empty($data['dob']) ? $data['dob'] : null, 'registration_date' => today(), 'status' => 'Active', 'note' => '', 'reg_by' => $by,
        'facility_id' => $fid, 'inst_member_no' => $instno, 'bcategory' => $cat, 'sponsor_id' => intval(isset($data['sponsor_id']) ? $data['sponsor_id'] : 0),
        'relationship' => mb_substr((string)(isset($data['relationship']) ? $data['relationship'] : ''), 0, 30), 'eligibility' => 'Active',
        'cover_start' => !empty($data['cover_start']) ? $data['cover_start'] : today(),
        'cover_expiry' => !empty($data['cover_expiry']) ? $data['cover_expiry'] : date('Y-m-d', strtotime('+1 year')), 'dedupe_key' => $key,
    );
    $ph50 = implode(',', array_fill(0, count($cols50), '?'));
    db()->prepare('INSERT INTO members (' . implode(',', array_keys($cols50)) . ') VALUES (' . $ph50 . ')')->execute(array_values($cols50));
    $id = intval(db()->lastInsertId());
    return array('ok' => true, 'msg' => 'Registered ' . $name . ' (' . $no . ')', 'id' => $id, 'member_no' => $no, 'inst_no' => $instno);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $op = post('op');
    if ($op === 'register') {
        if (!$CAN_EDIT) { flash('You cannot register beneficiaries here.', 'err'); redirect('institution-members.php?facility=' . $GF); }
        $r = im_register($GF, array('full_names' => post('full_names'), 'national_id' => post('national_id'), 'phone_no' => post('phone_no'),
            'gender' => post('gender'), 'dob' => post('dob'), 'address' => post('address'), 'bcategory' => post('bcategory'),
            'relationship' => post('relationship'), 'sponsor_id' => post('sponsor_id'), 'cover_start' => post('cover_start'), 'cover_expiry' => post('cover_expiry')));
        if ($r['ok']) {
            log_action('Beneficiary ' . $r['member_no'] . ' registered for ' . $f['name'], 'members', $r['id']);
            sms50_notify('bene_registered', array('name' => post('full_names'), 'member_no' => $r['member_no'], 'inst' => $f['name'], 'status' => 'Active', 'expiry' => post('cover_expiry') !== '' ? post('cover_expiry') : date('Y-m-d', strtotime('+1 year'))), $GF, $r['id'], 'members', $r['id']);
            flash($r['msg'] . ' — institution no <b>' . e($r['inst_no']) . '</b>.', 'ok');
        } else flash($r['msg'], 'err');
        redirect('institution-members.php?facility=' . $GF);
    }
    if ($op === 'update' && $CAN_EDIT) {
        $id = intval(post('id'));
        $st = db()->prepare('SELECT * FROM members WHERE id=? AND (facility_id=? OR facility_id=0)'); $st->execute(array($id, $GF)); $m = $st->fetch();
        if (!$m) { flash('Beneficiary not found in this institution.', 'err'); redirect('institution-members.php?facility=' . $GF); }
        $elig = in_array(post('eligibility'), array('Active', 'Suspended', 'Expired', 'Pending'), true) ? post('eligibility') : (string)$m['eligibility'];
        db()->prepare('UPDATE members SET bcategory=?, relationship=?, sponsor_id=?, eligibility=?, cover_start=?, cover_expiry=?, branch_id=? WHERE id=?')
           ->execute(array(post('bcategory'), post('relationship'), intval(post('sponsor_id')), $elig, post('cover_start'), post('cover_expiry'), intval(post('branch_id')), $id));
        log_action('Beneficiary ' . $m['member_no'] . ' updated (eligibility ' . $elig . ')', 'members', $id);
        sms50_notify('eligibility', array('name' => $m['full_names'], 'inst' => $f['name'], 'status' => $elig, 'plan' => (function () use ($id, $GF) { $e = ins_enrolment($id, $GF); return $e ? $e['plan_name'] : 'none'; })()), $GF, $id, 'members', $id);
        flash('Beneficiary updated.', 'ok'); redirect('institution-members.php?facility=' . $GF);
    }
    if ($op === 'import' && $CAN_EDIT) {
        $text = post('csv_text');
        if (isset($_FILES['csv']) && $_FILES['csv']['error'] === 0) {
            $raw = file_get_contents($_FILES['csv']['tmp_name']);
            if (strlen((string)$raw) < 400000) $text .= ($text !== '' ? "\n" : '') . $raw;
        }
        if (function_exists('normalise_to_utf8')) { $text = normalise_to_utf8($text); }
        $lines = preg_split('/\r\n|\r|\n/', trim(str_replace("\xEF\xBB\xBF", '', (string)$text)));
        $made = 0; $skipped = array();
        foreach ($lines as $li => $ln) {
            $ln = trim($ln); if ($ln === '') continue;
            $c = str_getcsv($ln);
            if ($li === 0 && stripos((string)$c[0], 'name') !== false && stripos((string)$c[0], 'full') === false) continue; // header-ish
            $c = array_map(function ($x) { return trim((string)$x); }, $c);
            $r = im_register($GF, array('full_names' => $c[0] ?? '', 'gender' => $c[1] ?? '', 'dob' => $c[2] ?? '', 'phone_no' => $c[3] ?? '',
                'national_id' => $c[4] ?? '', 'bcategory' => $c[5] ?? 'Principal', 'relationship' => $c[6] ?? '', 'cover_start' => $c[7] ?? '',
                'cover_expiry' => $c[8] ?? '', 'address' => $c[9] ?? '', 'sponsor_id' => $c[10] ?? 0));
            if ($r['ok']) $made++; else $skipped[] = 'line ' . ($li + 1) . ': ' . $r['msg'];
        }
        log_action('CSV import: ' . $made . ' beneficiaries added to ' . $f['name'], 'members', 0);
        $rep50 = 'Import finished — <b>' . $made . '</b> registered';
        if ($skipped) $rep50 .= ' &middot; ' . count($skipped) . ' skipped (duplicates/invalid): ' . e(implode('; ', array_slice($skipped, 0, 6))) . (count($skipped) > 6 ? ' …' : '');
        flash($rep50, $made ? 'ok' : 'info');
        redirect('institution-members.php?facility=' . $GF);
    }
}
if (get('a') === 'csv') { // export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="beneficiaries-' . preg_replace('/\W/', '', $f['code']) . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, array('Full name', 'Gender', 'DOB', 'Phone', 'National ID', 'Category', 'Relationship', 'Cover start', 'Cover expiry', 'Address', 'Member no', 'Institution no', 'Eligibility', 'Branch'));
    $st = db()->prepare('SELECT m.*, b.name branch FROM members m LEFT JOIN institution_branches b ON b.id=m.branch_id WHERE m.facility_id=? ORDER BY m.full_names');
    $st->execute(array($GF));
    foreach ($st->fetchAll() as $r) fputcsv($o, array($r['full_names'], $r['gender'], $r['dob'], $r['phone_no'], $r['national_id'], $r['bcategory'], $r['relationship'], $r['cover_start'], $r['cover_expiry'], $r['address'], $r['member_no'], $r['inst_member_no'], $r['eligibility'], $r['branch']));
    fclose($o); exit;
}

$srch = trim(get('s'));
$where = 'm.facility_id=?'; $args = array($GF);
if ($srch !== '') { $where .= ' AND (m.full_names LIKE ? OR m.member_no LIKE ? OR m.inst_member_no LIKE ? OR m.national_id LIKE ? OR m.phone_no LIKE ? OR m.card_no LIKE ?)';
    $like = '%' . $srch . '%'; for ($i = 0; $i < 6; $i++) $args[] = $like; }
$st = db()->prepare("SELECT m.*, b.name branch,
   (SELECT COALESCE(SUM(d.amount),0) FROM deposits d WHERE d.member_id=m.id) tot_dep,
   (SELECT COALESCE(SUM(w.amount),0) FROM withdrawals w WHERE w.member_id=m.id AND w.status='Paid') tot_cons,
   (SELECT COALESCE(SUM(mb.total),0) FROM member_bills mb WHERE mb.member_id=m.id AND mb.status='Pending') tot_open,
   (SELECT ip.plan_name FROM policy_beneficiaries pb JOIN insurance_plans ip ON ip.id=pb.plan_id WHERE pb.member_id=m.id AND pb.status='Active' ORDER BY pb.id DESC LIMIT 1) plan_name
   FROM members m LEFT JOIN institution_branches b ON b.id=m.branch_id WHERE $where ORDER BY m.full_names LIMIT 300");
$st->execute($args); $rows = $st->fetchAll();
$counts = db()->prepare("SELECT COUNT(*) n, SUM(CASE WHEN eligibility='Active' THEN 1 ELSE 0 END) act FROM members WHERE facility_id=?"); $counts->execute(array($GF)); $counts = $counts->fetch();
$editRow = null; if (intval(get('edit')) > 0) { $st = db()->prepare('SELECT * FROM members WHERE id=? AND facility_id=?'); $st->execute(array(intval(get('edit')), $GF)); $editRow = $st->fetch(); }
$branches = array(); try { $branches = db()->query('SELECT * FROM institution_branches WHERE facility_id=' . $GF . " AND status='Active'")->fetchAll(); } catch (Exception $e) {}

$page_title = 'Beneficiaries — ' . $f['name'];
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>&#128101; Beneficiaries — <?php echo e($f['name']); ?></h1>
 <span class="sub">Independent registry for this institution; records are stamped <?php echo e($f['code']); ?> server-side. Central policy: <?php echo $multiAllowed ? 'a person MAY hold membership at several institutions' : 'one institution per person — duplicates are blocked'; ?>.</span>
 <?php if ($lockedFid === 0): ?><span class="noprint" style="float:right"><form method="get" style="display:inline-flex;gap:6px"><select class="f" name="facility" onchange="this.form.submit()" style="margin:0;padding:5px 8px">
  <?php foreach (fac_list() as $o): ?><option value="<?php echo e($o['id']); ?>" <?php echo intval($o['id'])===$GF?'selected':''; ?>><?php echo e($o['name']); ?></option><?php endforeach; ?></select></form></span><?php endif; ?>
</div>
<div style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0">
 <div class="kpi"><div class="k-label">Registered</div><div class="k-value"><?php echo num(intval($counts['n'])); ?></div><div class="k-sub"><?php echo num(intval($counts['act'])); ?> eligible · <?= num(intval($counts['n']) - intval($counts['act'])) ?> blocked/expired</div></div>
 <div class="kpi green"><div class="k-label">Settled bills</div><div class="k-value"><?php echo num(intval(db()->query('SELECT COUNT(*) FROM bill_settlements WHERE facility_id=' . $GF . ' AND status<>\'Reversed\'')->fetchColumn())); ?></div><div class="k-sub">via bill settlement</div></div>
 <div class="kpi"><div class="k-label">On a policy</div><div class="k-value"><?php $onp = db()->query("SELECT COUNT(DISTINCT pb.member_id) FROM policy_beneficiaries pb JOIN members mm ON mm.id=pb.member_id WHERE pb.status='Active' AND mm.facility_id=" . $GF)->fetchColumn(); echo num(intval($onp)); ?></div><div class="k-sub">enrolled beneficiaries of this institution</div></div>
 <span style="flex:1"></span><a class="btn small plain" style="align-self:center" href="institution-members.php?facility=<?php echo $GF; ?>&a=csv">&#128190; Export Excel/CSV</a>
</div>

<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">
<div class="panel" style="flex:2;min-width:440px">
 <form method="get" style="display:flex;gap:8px;margin-bottom:8px"><input type="hidden" name="facility" value="<?php echo $GF; ?>">
  <input class="f" name="s" placeholder="search name / member no / institution no / phone / NIN" value="<?php echo e($srch); ?>" style="margin:0;flex:1">
  <button class="btn small primary" type="submit">Search</button></form>
<div class="twrap" style="border:0"><table class="data" style="margin:0">
<tr><th>Member</th><th>Names</th><th>Category / plan</th><th style="text-align:right">Deposit balance</th><th style="text-align:right">Deposits</th><th style="text-align:right">Consumed</th><th style="text-align:right">Open bills</th><th>Cover</th><th>Eligibility</th><th>Branch</th><th></th></tr>
<?php foreach ($rows as $r): $exp = (string)$r['cover_expiry']; ?>
<tr><td><b><?php echo e($r['inst_member_no'] !== '' ? $r['inst_member_no'] : $r['member_no']); ?></b><br><span style="font-size:10.5px;color:#7a94aa"><?php echo e($r['member_no']); ?></span></td>
 <td><?php echo e($r['full_names']); ?><br><span style="font-size:10.5px;color:#7a94aa"><?php echo e((string)$r['phone_no']); ?><?php echo (string)$r['national_id'] !== '' ? ' · NIN ' . e((string)$r['national_id']) : ''; ?><?php echo $r['dob'] ? ' · b. ' . e(substr((string)$r['dob'], 0, 10)) : ''; ?></span></td>
 <td><?php echo e((string)$r['bcategory']); ?><?php echo (string)$r['plan_name'] !== '' ? '<br><span style="font-size:10.5px;color:#1B6BB3">' . e((string)$r['plan_name']) . '</span>' : ''; ?><?php echo $r['relationship'] !== '' ? '<br><span style="font-size:10.5px;color:#7a94aa">' . e($r['relationship']) . '</span>' : ''; ?></td>
 <td style="text-align:right"><b><?php echo num((float)$r['deposit_amount']); ?></b></td>
 <td style="text-align:right;color:#20603a"><?php echo num((float)$r['tot_dep']); ?></td>
 <td style="text-align:right;color:#8a6410"><?php echo num((float)$r['tot_cons']); ?></td>
 <td style="text-align:right"><?php echo (float)$r['tot_open'] > 0 ? '<b style="color:#8c2424">' . num((float)$r['tot_open']) . '</b>' : '—'; ?></td>
 <td style="font-size:11.5px"><?php echo e((string)$r['cover_start']); ?> → <?php echo $exp !== '' ? ($exp < today() ? '<b style="color:#8c2424">EXPIRED ' . e($exp) . '</b>' : e($exp)) : '—'; ?></td>
 <td><b style="color:<?php echo $r['eligibility'] === 'Active' ? '#20603a' : '#8c2424'; ?>"><?php echo strtoupper(e((string)$r['eligibility'])); ?></b></td>
 <td><?php echo e((string)$r['branch']); ?></td>
 <td style="white-space:nowrap"><a class="btn small plain" href="member-account.php?member=<?php echo e($r['id']); ?>&facility=<?php echo $GF; ?>">&#128176; View account</a> <a class="btn small plain" href="institution-members.php?facility=<?php echo $GF; ?>&edit=<?php echo e($r['id']); ?>#editForm">Edit</a>
  <a class="btn small primary" href="member-bills.php?view=new&facility=<?php echo $GF; ?>&member=<?php echo e($r['id']); ?>">Bill / settle</a>
  <a class="btn small plain" href="member.php?id=<?php echo e($r['id']); ?>">File</a></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="7" style="color:#7a94aa">No beneficiaries yet — register on the right, or import a CSV.</td></tr>'; ?>
</table></div></div>

<div class="panel" style="flex:1;min-width:330px" id="<?php echo $editRow ? 'editForm' : 'regForm'; ?>">
<h2 style="margin-top:0"><?php echo $editRow ? 'Edit beneficiary' : '+ Register beneficiary'; ?></h2>
<form method="post">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="<?php echo $editRow ? 'update' : 'register'; ?>">
<?php if ($editRow): ?><input type="hidden" name="id" value="<?php echo intval($editRow['id']); ?>"><?php endif; ?>
<label class="f">Full names *</label><input class="f" name="full_names" required value="<?php echo e($editRow['full_names'] ?? ''); ?>" <?php echo $editRow ? 'readonly' : ''; ?>>
<div style="display:flex;gap:8px"><div style="flex:1"><label class="f">Gender</label><select class="f" name="gender" style="margin:0">
 <option <?php echo (($editRow['gender'] ?? 'Female') === 'Female') ? 'selected' : ''; ?>>Female</option><option <?php echo (($editRow['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option></select></div>
 <div style="flex:1"><label class="f">Date of birth</label><input class="f" type="date" name="dob" value="<?php echo e(substr((string)($editRow['dob'] ?? ''), 0, 10)); ?>" style="margin:0"></div></div>
<label class="f">Phone</label><input class="f" name="phone_no" value="<?php echo e($editRow['phone_no'] ?? ''); ?>" placeholder="07xx…">
<?php if (!$editRow): ?><label class="f">National ID (NIN)</label><input class="f" name="national_id" placeholder="duplicate guard"><?php endif; ?>
<div style="display:flex;gap:8px"><div style="flex:1"><label class="f">Category</label><select class="f" name="bcategory" style="margin:0">
 <?php foreach ($CATS as $c): ?><option <?php echo (($editRow['bcategory'] ?? 'Principal') === $c) ? 'selected' : ''; ?>><?php echo $c; ?></option><?php endforeach; ?></select></div>
 <div style="flex:1"><label class="f">Relationship</label><input class="f" name="relationship" value="<?php echo e($editRow['relationship'] ?? ''); ?>" placeholder="spouse/child…"></div></div>
<div style="display:flex;gap:8px"><div style="flex:1"><label class="f">Cover start</label><input class="f" type="date" name="cover_start" value="<?php echo e((string)($editRow['cover_start'] ?? '')); ?>" style="margin:0"></div>
 <div style="flex:1"><label class="f">Cover expiry</label><input class="f" type="date" name="cover_expiry" value="<?php echo e((string)($editRow['cover_expiry'] ?? '')); ?>" style="margin:0"></div></div>
<?php if ($editRow): ?>
<div style="display:flex;gap:8px"><div style="flex:1"><label class="f">Eligibility</label><select class="f" name="eligibility" style="margin:0">
 <?php foreach (array('Active', 'Suspended', 'Expired', 'Pending') as $c): ?><option <?php echo (($editRow['eligibility'] ?? 'Active') === $c) ? 'selected' : ''; ?>><?php echo $c; ?></option><?php endforeach; ?></select></div>
 <div style="flex:1"><label class="f">Branch</label><select class="f" name="branch_id" style="margin:0"><option value="0">Main</option>
  <?php foreach ($branches as $b): ?><option value="<?php echo e($b['id']); ?>" <?php echo intval($editRow['branch_id'] ?? 0) === intval($b['id']) ? 'selected' : ''; ?>><?php echo e($b['name']); ?></option><?php endforeach; ?></select></div></div>
<label class="f">NIN</label><input class="f" value="<?php echo e((string)($editRow['national_id'] ?? '')); ?>" readonly>
<label class="f">Address</label><input class="f" name="address" value="<?php echo e($editRow['address'] ?? ''); ?>">
<?php endif; ?>
<?php if ($editRow && $editRow['national_id']): ?><p style="font-size:11.5px;color:#5b7c99;margin:6px 0 0">NIN: <b><?php echo e($editRow['national_id']); ?></b> — used for duplicate guarding.</p><?php endif; ?>
<div style="margin-top:10px"><button class="btn primary" type="submit"><?php echo $editRow ? 'Save changes' : 'Register beneficiary'; ?></button>
<?php if ($editRow): ?><a class="btn small plain" href="institution-members.php?facility=<?php echo $GF; ?>">cancel</a><?php endif; ?></div>
<?php if (!$editRow): ?><p style="font-size:11.5px;color:#5b7c99;margin:8px 0 0">Duplicate check: same NIN or same phone at this institution is rejected automatically.</p><?php endif; ?>
</form>
<?php if (!$editRow && $CAN_EDIT): ?>
<details style="margin-top:12px;border-top:1px dashed #bcd6ea;padding-top:8px"><summary style="cursor:pointer;font-weight:bold;color:#1B6BB3;font-size:13px">&#128228; Import from Excel (save sheet as CSV)</summary>
<form method="post" enctype="multipart/form-data">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="import"><input type="hidden" name="facility" value="<?php echo $GF; ?>">
<p style="font-size:12px;color:#5b7c99">Columns: <code>full name, gender, dob(YYYY-MM-DD), phone, national id, category, relationship, cover start, cover expiry, address</code>. Rows that fail the duplicate check are skipped, never overwrite.</p>
<input class="f" type="file" name="csv" accept=".csv,.txt" style="margin:6px 0">
<textarea class="f" name="csv_text" rows="5" placeholder="…or paste CSV rows here" style="font:12px Consolas,monospace"></textarea>
<button class="btn small gold" type="submit">Import</button></form></details>
<?php endif; ?>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
