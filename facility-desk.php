<?php
/** v4.0 Facility Desk — one counter for every clinic, hospital, medical centre & health centre:
 *  look up any member's deposit account, bill from the facility price list, settle straight off
 *  the patient's deposits, enroll facility users, grow the price list, plug in an HMIS, and
 *  bundle pending work into a claim for the scheme owner. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facility-desk-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$lockedFid = fac_scope_fid();
$GF = intval($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['facility']) ? $_POST['facility'] : get('facility'));
if ($lockedFid > 0) $GF = $lockedFid; else fac_lock(0);
if (!fac_has_tbl()) { $page_title = 'Facility Desk'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>Facility module not installed</h2><p>Run <a href="migrate.php">migrate.php</a> once.</p></div>'; include __DIR__ . '/includes/footer.php'; exit; }
if ($GF <= 0) { $ls = fac_list(); $GF = count($ls) ? intval($ls[0]['id']) : 0; }
if ($GF <= 0) { $page_title = 'Facility Desk'; include __DIR__ . '/includes/header.php';
    echo '<div class="panel"><h2>No facilities yet</h2><p><a href="facilities.php?a=new">Create a hospital, clinic, medical or health centre</a> to open its desk.</p></div>'; include __DIR__ . '/includes/footer.php'; exit; }
$f = fac_of($GF);
if (!function_exists('phone_disp')) { function phone_disp($v) { $v = (string)$v; return strpos($v, '256') === 0 ? '0' . substr($v, 3) : $v; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'txn') {
        $lines = array();
        $qe = isset($_POST['qty']) && is_array($_POST['qty']) ? $_POST['qty'] : array();
        foreach ($qe as $pid => $qv) {
            $qty = (float)str_replace(',', '', (string)$qv); if ($qty <= 0) continue;
            $st = db()->prepare('SELECT * FROM facility_prices WHERE id=? AND facility_id=?'); $st->execute(array(intval($pid), $GF)); $p = $st->fetch();
            if ($p) $lines[] = array('name' => $p['item_name'], 'qty' => $qty, 'price' => (float)$p['price'], 'cat' => $p['category']);
        }
        if (isset($_POST['cx_name'])) {
            $cxn = (array)$_POST['cx_name']; $cxq = isset($_POST['cx_qty']) ? (array)$_POST['cx_qty'] : array(); $cxp = isset($_POST['cx_price']) ? (array)$_POST['cx_price'] : array();
            foreach ($cxn as $i => $nm) { $nm = trim((string)$nm); if ($nm === '') continue;
                $lines[] = array('name' => mb_substr($nm, 0, 140), 'qty' => max(1, (float)(isset($cxq[$i]) ? str_replace(',', '', (string)$cxq[$i]) : 1)), 'price' => (float)(isset($cxp[$i]) ? str_replace(',', '', (string)$cxp[$i]) : 0), 'cat' => 'Custom'); }
        }
        $res = fd_record_txn($GF, intval(post('member_id')), $lines, post('mode') === 'settle', $_SESSION['uname'], post('note'), 'desk', post('txn_date') !== '' ? post('txn_date') : null, post('patient_name'));
        flash($res['msg'], $res['ok'] ? 'ok' : 'err');
        redirect('facility-desk.php?facility=' . $GF . ($res['ok'] ? '#txns' : '#bill'));
    }
    if ($op === 'genclaim') {
        $res = fd_generate_claim($GF, $_SESSION['uname']);
        flash($res['msg'], $res['ok'] ? 'ok' : 'err');
        redirect('facility-desk.php?facility=' . $GF . ($res['ok'] ? '#claims' : ''));
    }
    if ($op === 'quick_price') {
        $nm = trim(post('item_name')); $pr = (float)str_replace(',', '', post('price'));
        if ($nm === '' || $pr <= 0) { flash('Price line needs a name and price.', 'err'); redirect('facility-desk.php?facility=' . $GF . '#pricelist'); }
        db()->prepare('INSERT INTO facility_prices (facility_id, category, item_name, unit, price, is_base, created_at, created_by) VALUES (?,?,?,?,?,0,?,?)')
           ->execute(array($GF, post('category') !== '' ? post('category') : 'Add-on', $nm, post('unit') !== '' ? post('unit') : 'each', $pr, date('Y-m-d H:i:s'), $_SESSION['uname']));
        log_action('Price ADD-ON "' . $nm . '" on ' . $f['code'] . ' (from facility desk)', 'facility_prices', intval(db()->lastInsertId()));
        flash('"' . $nm . '" added to ' . $f['name'] . "'s price list.", 'ok'); redirect('facility-desk.php?facility=' . $GF . '#pricelist');
    }
    if ($op === 'quick_user') {
        $un = trim(post('username')); $pw = post('password');
        if ($un === '' || strlen((string)$pw) < 6) { flash('Username + password (6+) required.', 'err'); redirect('facility-desk.php?facility=' . $GF . '#users'); }
        if (!in_array(post('role'), array('Facility Admin', 'Reception', 'User'), true)) post('role'); // role forced below
        $role = post('role'); if (!in_array($role, array('Facility Admin', 'Reception', 'User'), true)) $role = 'User';
        try {
            $dup = db()->prepare('SELECT id FROM users WHERE username=?'); $dup->execute(array($un));
            if ($dup->fetch()) { flash('That username exists already.', 'err'); }
            else {
                db()->prepare('INSERT INTO users (username, password_hash, full_name, previledge, phone, active, facility_id, created_at) VALUES (?,?,?,?,?,?,?,?)')
                   ->execute(array($un, password_hash($pw, PASSWORD_DEFAULT), trim(post('full_name')) !== '' ? post('full_name') : $un, $role, trim(post('phone')), 1, $GF, date('Y-m-d H:i:s')));
                log_action('Enrolled facility user ' . $un . ' at ' . $f['name'], 'users', intval(db()->lastInsertId()));
                flash('User <b>' . e($un) . '</b> enrolled at ' . $f['name'] . ' — their login is locked to this facility.', 'ok');
            }
        } catch (Exception $e) { flash('Could not create user: ' . $e->getMessage(), 'err'); }
        redirect('facility-desk.php?facility=' . $GF . '#users');
    }
    if ($op === 'hmis_token') {
        $tk = strtoupper(bin2hex(random_bytes(12)));
        try { db()->prepare('UPDATE facilities SET hmis_token=? WHERE id=?')->execute(array($tk, $GF)); flash('New HMIS token generated — replace the old one in your HMIS config.', 'ok'); }
        catch (Exception $e) { flash('Column hmis_token missing — run migrate.php.', 'err'); }
        redirect('facility-desk.php?facility=' . $GF . '#hmis');
    }
}

/* panels data */
$priceItems = fac_price_items($GF);
$srch = trim(get('s'));
$members = array();
if ($srch !== '') {
    $like = '%' . $srch . '%'; $dig = preg_replace('/\D/', '', $srch);
    $st = db()->prepare("SELECT id, member_no, full_names, phone_no, mtn_no, airtel_no, deposit_amount, status FROM members
        WHERE full_names LIKE ? OR member_no LIKE ? OR national_id LIKE ? OR REPLACE(REPLACE(REPLACE(mtn_no,'256',''),'0',''),'+','256') LIKE ? OR mtn_no LIKE ? OR airtel_no LIKE ? ORDER BY full_names LIMIT 8");
    $st->execute(array($like, $like, $like, '%' . $dig . '%', '%' . $srch . '%', '%' . $srch . '%'));
    $members = $st->fetchAll();
}
$txns = db()->prepare('SELECT * FROM facility_txns WHERE facility_id=? ORDER BY id DESC LIMIT 15'); $txns->execute(array($GF)); $txns = $txns->fetchAll();
$unb = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a FROM facility_txns WHERE facility_id=? AND status='Unbilled'"); $unb->execute(array($GF)); $unb = $unb->fetch();
$claims = db()->prepare('SELECT * FROM facility_claims WHERE facility_id=? ORDER BY id DESC LIMIT 8'); $claims->execute(array($GF)); $claims = $claims->fetchAll();
$facUsers = array();
try { $st = db()->prepare('SELECT username, full_name, previledge, active FROM users WHERE facility_id=? ORDER BY username'); $st->execute(array($GF)); $facUsers = $st->fetchAll(); } catch (Exception $e) {}
$hmisTok = ''; $hmisCol = false;
try { $hmisTok = (string)db()->query('SELECT hmis_token FROM facilities WHERE id=' . intval($GF))->fetchColumn(); $hmisCol = true; } catch (Exception $e) {}
$endpoint = (defined('HTTPS_SELF') ? HTTPS_SELF : ('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']))) . '/hmis-api.php';
$endpoint = rtrim($endpoint, '/');

$page_title = 'Facility Desk — ' . $f['name'];
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1><?php echo fac_icon($f['ftype']); ?> Facility Desk — <?php echo e($f['name']); ?>
  <span style="font:normal 12px Arial;color:#5b7c99"><?php echo e($f['code']); ?> &middot; <?php echo e($f['ftype']); ?> &middot; member accounts are open at every facility on the network</span></h1>
<span class="noprint"><?php if ($lockedFid === 0): ?>
<form method="get" style="display:inline-flex;gap:6px;align-items:center"><select class="f" name="facility" onchange="this.form.submit()" style="margin:0;padding:5px 8px">
<?php foreach (fac_list() as $o): ?><option value="<?php echo e($o['id']); ?>" <?php echo intval($o['id'])===$GF?'selected':''; ?>><?php echo fac_icon($o['ftype']); ?> <?php echo e($o['name']); ?></option><?php endforeach; ?></select>
<button class="btn small" type="submit">Switch</button></form><?php endif; ?>
<a class="btn primary" href="facility-claims.php?facility=<?php echo e($GF); ?>">Owner claims desk &raquo;</a></span></div>

<div class="kpi-row">
 <div class="kpi gold"><div class="k-label">Unbilled work in hand</div><div class="k-value"><?php echo num($unb['c']); ?></div><div class="k-sub"><?php echo ugx($unb['a']); ?> ready to claim</div></div>
 <div class="kpi"><div class="k-label">Price list</div><div class="k-value"><?php echo num(count($priceItems)); ?></div><div class="k-sub">base + add-on lines</div></div>
 <div class="kpi green"><div class="k-label">Facility users</div><div class="k-value"><?php echo num(count($facUsers)); ?></div><div class="k-sub">locked to <?php echo e($f['code']); ?></div></div>
 <div class="kpi red"><div class="k-label">Claims with the owner</div><div class="k-value"><?php echo num(count($claims)); ?></div><div class="k-sub">latest: <?php echo $claims ? e($claims[0]['status']) : '—'; ?></div></div>
</div>

<div class="grid-2">
<div class="panel" id="members"><h2 style="margin-top:0">&#128269; Member deposit lookup <span style="font:normal 11px Arial;color:#5b7c99">(works at every facility)</span></h2>
<form method="get"><input type="hidden" name="facility" value="<?php echo e($GF); ?>">
<div style="display:flex;gap:8px"><input class="f" name="s" value="<?php echo e($srch); ?>" placeholder="name, member no., NIN or phone" style="margin:0"><button class="btn primary" type="submit">Find</button></div></form>
<?php if ($srch !== ''): ?><?php if (!$members): ?><p style="color:#8c2424;font-size:12.5px;margin-top:8px">No member matched &ldquo;<?php echo e($srch); ?>&rdquo;.</p><?php endif; ?>
<?php foreach ($members as $mm): $bal = member_balance(intval($mm['id'])); ?>
<div style="border:1px solid #cfe0ef;border-radius:5px;padding:10px 12px;margin-top:8px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;background:#fff">
 <div><b><?php echo e($mm['full_names']); ?></b> <span style="color:#7a94aa"><?php echo e($mm['member_no']); ?></span>
   <?php if ($mm['status'] !== 'Active'): ?><span class="badge" style="background:#FDECEC;color:#8c2424"><?php echo e($mm['status']); ?></span><?php endif; ?>
   <br><span style="font-size:11.5px;color:#5b7c99"><?php echo e(phone_disp($mm['mtn_no'])); ?> &middot; NIN <?php echo e($mm['national_id']); ?></span></div>
 <div style="text-align:right"><div style="font:bold 17px Georgia;color:#12476f"><?php echo ugx($bal); ?></div>
   <div style="font-size:10.5px;color:#7a94aa">deposit balance</div>
   <a class="btn small success" href="facility-desk.php?facility=<?php echo e($GF); ?>&bill_for=<?php echo e($mm['id']); ?>#bill">Bill this member &raquo;</a></div>
</div><?php endforeach; ?>
<p style="font-size:11px;color:#7a94aa;margin-top:8px">Balances are live: deposits minus settled bills, valid at any hospital, clinic, medical or health centre registered with the scheme.</p>
<?php endif; ?></div>

<div class="panel" id="pricelist" style="border:1px solid #bcd6ee"><h2 style="margin-top:0">&#128203; Grow the price list <span style="font:normal 11px Arial;color:#5b7c99">(additive)</span></h2>
<form method="post"><?php echo csrf_field(); ?><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="op" value="quick_price">
<div class="formgrid" style="grid-template-columns:2fr 1fr 1fr 1fr">
 <div><label class="f">Item / service</label><input class="f" name="item_name" placeholder="e.g. Dental filling"></div>
 <div><label class="f">Category</label><input class="f" name="category" placeholder="Add-on"></div>
 <div><label class="f">Unit</label><input class="f" name="unit" placeholder="each"></div>
 <div><label class="f">Price UGX</label><input class="f" name="price" inputmode="numeric"></div>
</div><button class="btn primary small" type="submit" style="margin-top:6px">Add price line</button>
<a class="btn small plain" href="pricelist.php?facility=<?php echo e($GF); ?>" style="margin-top:6px">Full list view &raquo;</a></form></div>
</div>

<div class="panel" id="bill" style="border:2px solid #1B6BB3"><h2 style="margin-top:0">&#128179; New transaction from the price list</h2>
<form method="post" id="txnForm"><?php echo csrf_field(); ?><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="op" value="txn">
<div class="formgrid" style="grid-template-columns:repeat(4,1fr)">
 <div><label class="f">Member (for deposit settlement)</label>
  <select class="f" name="member_id"><option value="0">— not a member (claim route) —</option>
  <?php $pre = intval(get('bill_for'));
  foreach (db()->query("SELECT id, member_no, full_names FROM members WHERE status='Active' ORDER BY full_names LIMIT 300")->fetchAll() as $mo): ?>
  <option value="<?php echo e($mo['id']); ?>" <?php echo intval($mo['id'])===$pre?'selected':''; ?>><?php echo e($mo['member_no']); ?> — <?php echo e($mo['full_names']); ?></option><?php endforeach; ?></select></div>
 <div><label class="f">Patient name (if not a member)</label><input class="f" name="patient_name"></div>
 <div><label class="f">Date</label><input class="f" type="date" name="txn_date" value="<?php echo e(today()); ?>"></div>
 <div><label class="f">Note</label><input class="f" name="note" placeholder="optional"></div>
</div>
<?php if ($priceItems): ?>
<div style="max-height:280px;overflow:auto;border:1px solid #cfe0ef;border-radius:4px;margin-top:8px">
<table class="data" style="margin:0"><tr><th style="width:34px">&#10003;</th><th><?php echo e($f['name']); ?> price list</th><th class="num">Unit price</th><th style="width:80px">Qty</th></tr>
<?php foreach ($priceItems as $p): ?>
<tr><td><input type="checkbox" name="ck[<?php echo e($p['id']); ?>]" data-price="<?php echo e($p['price']); ?>"></td>
<td><?php echo e($p['item_name']); ?> <i style="color:#7a94aa;font-size:10.5px"><?php echo e($p['unit']); ?></i> <?php echo intval($p['is_base']) ? '<span style="font:9px Arial;color:#1B6BB3">STANDARD</span>' : '<span style="font:9px Arial;color:#8a6410">ADD-ON</span>'; ?></td>
<td class="num"><?php echo number_format((float)$p['price'], 0); ?></td>
<td><input class="f qtyin" name="qty[<?php echo e($p['id']); ?>]" style="width:70px;margin:0;text-align:right" placeholder="0" inputmode="numeric"></td></tr>
<?php endforeach; ?></table></div>
<?php else: ?><p style="color:#8a6410;font-size:12.5px">No priced items yet — add lines on the right, then come back.</p><?php endif; ?>
<div style="margin-top:8px"><label class="f">Custom lines</label><div id="cxRows"></div>
<button class="btn small" type="button" onclick="cxAdd()">+ Add custom line</button></div>
<div style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap">
 <button class="btn danger" type="submit" name="mode" value="settle">&#128179; Settle &amp; deduct from member deposits</button>
 <button class="btn" type="submit" name="mode" value="record">Record unbilled (goes into next claim)</button>
 <span style="font:bold 16px Georgia;color:#12476f">Total: UGX <span id="txnTot">0</span></span>
</div></form>
<script>
function fmt(n){return n.toLocaleString('en-US');}
function cxAdd(){var d=document.createElement('div');d.style.cssText='display:flex;gap:6px;margin-top:6px';
d.innerHTML='<input class="f cxn" placeholder="Item name" style="flex:2;margin:0"><input class="f cxq" placeholder="qty" style="width:70px;margin:0"><input class="f cxp" placeholder="unit price" style="width:120px;margin:0"><button class="btn small" type="button" onclick="this.parentNode.remove();calc2()">&#10007;</button>';
document.getElementById('cxRows').appendChild(d);d.querySelectorAll('input').forEach(function(i){i.addEventListener('input',calc2);});}
function calc2(){var t=0;
document.querySelectorAll('input[name^="ck["]').forEach(function(ch){var id=ch.name.slice(3,-1);var q=document.querySelector('input[name="qty['+id+']"]');if(!q)return;var v=parseFloat((q.value||'0').replace(/,/g,''))||0;if(!ch.checked){q.disabled=true;v=0;}else{q.disabled=false;if(!q.value){q.value='1';v=1;}}t+=v*parseFloat(ch.getAttribute('data-price'));});
document.querySelectorAll('#cxRows>div').forEach(function(r){var n=r.querySelector('.cxn'),q=r.querySelector('.cxq'),p=r.querySelector('.cxp');if(n&&n.value.trim())t+=(parseFloat((q.value||'1').replace(/,/g,''))||1)*(parseFloat((p.value||'0').replace(/,/g,''))||0);});
document.getElementById('txnTot').textContent=fmt(Math.round(t));}
document.getElementById('txnForm').addEventListener('input',calc2);document.getElementById('txnForm').addEventListener('change',calc2);
document.getElementById('txnForm').addEventListener('submit',function(ev){if((parseInt(document.getElementById('txnTot').textContent.replace(/,/g,''),10)||0)<=0){alert('Add at least one priced line.');ev.preventDefault();}});
calc2();
</script></div>

<div class="grid-2">
<div class="panel" id="txns"><h2 style="margin-top:0">&#128203; Recent transactions &amp; claim dispatch</h2>
<p style="font-size:12.5px;margin:0 0 8px"><?php echo num($unb['c']); ?> unbilled worth <b><?php echo ugx($unb['a']); ?></b>. Bundle them into a claim for the scheme owner (Kisubi):</p>
<?php if ($lockedFid === 0 || $GF > 0): ?><form method="post" onsubmit="return confirm('Generate a claim for <?php echo num($unb['c']); ?> transaction(s) totalling UGX <?php echo e(number_format((float)$unb['a'],0)); ?> and send it to the super admin?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="genclaim"><button class="btn danger" type="submit" <?php echo intval($unb['c']) ? '' : 'disabled'; ?>>&#9878;&#65039; Generate claim to super admin</button></form><?php endif; ?>
<table class="data" style="margin:10px 0 0"><tr><th>#</th><th>Member / patient</th><th class="num">Amount</th><th>State</th><th>By</th></tr>
<?php foreach ($txns as $t): ?>
<tr><td><?php echo e($t['id']); ?></td><td style="font-size:12px"><?php echo e($t['member_no'] !== '' ? $t['member_no'] : '—'); ?> <?php echo e($t['patient_name']); ?></td>
<td class="num"><?php echo ugx($t['amount']); ?></td>
<td><span style="font:bold 9.5px Arial;padding:1px 7px;border-radius:3px;<?php echo $t['status']==='Unbilled' ? 'background:#FFF6E0;color:#8a6410' : 'background:#E3EFFA;color:#1B6BB3'; ?>"><?php echo e($t['status']); ?><?php echo intval($t['claim_id']) ? ' #' . e($t['claim_id']) : ''; ?></span></td>
<td style="font-size:11px;color:#7a94aa"><?php echo e($t['created_by']); ?> &middot; <?php echo e($t['source']); ?></td></tr>
<?php endforeach; if (!$txns): ?><tr><td colspan="5" style="color:#7a94aa">No transactions yet — create one above.</td></tr><?php endif; ?></table></div>

<div class="panel" id="users"><h2 style="margin-top:0">&#128101; Enroll users at <?php echo e($f['code']); ?></h2>
<form method="post"><?php echo csrf_field(); ?><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="op" value="quick_user">
<div class="formgrid" style="grid-template-columns:1fr 1fr 1fr">
 <div><label class="f">Username *</label><input class="f" name="username"></div>
 <div><label class="f">Password (6+) *</label><input class="f" type="password" name="password"></div>
 <div><label class="f">Role</label><select class="f" name="role"><option>Facility Admin</option><option>Reception</option><option>User</option></select></div>
 <div><label class="f">Full name</label><input class="f" name="full_name"></div>
 <div><label class="f">Phone</label><input class="f" name="phone"></div>
 <div style="align-self:end"><button class="btn primary" type="submit">Enroll</button></div>
</div></form>
<table class="data" style="margin:10px 0 0"><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th></tr>
<?php foreach ($facUsers as $u): ?><tr><td><b><?php echo e($u['username']); ?></b></td><td><?php echo e($u['full_name']); ?></td><td><?php echo e($u['previledge']); ?></td><td><?php echo intval($u['active']) ? '<span style="color:#20603a">active</span>' : '<span style="color:#8c2424">disabled</span>'; ?></td></tr><?php endforeach; ?>
<?php if (!$facUsers): ?><tr><td colspan="4" style="color:#7a94aa">No facility users yet — every login created here is locked to <?php echo e($f['name']); ?>.</td></tr><?php endif; ?></table></div>
</div>

<div class="panel" id="hmis" style="border:1px dashed #1B6BB3"><h2 style="margin-top:0">&#128225; HMIS integration — connect an existing hospital system</h2>
<div class="grid-2">
<div>
<p style="font-size:12.5px;color:#5b7c99;margin-top:0">Your HMIS (or any system) can post transactions into <?php echo e($f['name'])?> with a bearer token — member lookup, deposit balance, bill recording and deposit-funded settlement all work over one JSON endpoint:</p>
<div style="background:#0f2c44;color:#bfe3ff;border-radius:5px;padding:10px 12px;font:12px/1.6 Consolas,monospace;overflow:auto">
POST <?php echo e($endpoint); ?><br>
{ "token": "<?php echo $hmisTok !== '' ? e($hmisTok) : '(generate below)'; ?>",<br>
&nbsp; "action": "create_transaction",<br>
&nbsp; "member_no": "KH-0001", "settle": true,<br>
&nbsp; "lines": [ {"item":"Consultation (general)","qty":2}, {"item":"Dressing","qty":1,"price":5000} ] }<br>
<span style="color:#7fb8e6">// actions: ping &middot; member_search &middot; balance &middot; price_list &middot; create_transaction &middot; generate_claim</span>
</div>
<p style="font-size:11px;color:#7a94aa">Items without an explicit price are matched (case-insensitive) against this facility's price list. <code>settle:true</code> deducts from the member's deposit account instantly; false queues into the claim route.</p>
</div>
<div>
<h3 style="margin:0 0 6px">Token</h3>
<?php if (!$hmisCol): ?><p style="color:#8a6410;font-size:12.5px">hmis_token column missing — run <a href="migrate.php">migrate.php</a>.</p><?php else: ?>
<div style="background:#F0F7FF;border:1px solid #bcd6ee;border-radius:4px;padding:8px 10px;font:12px Consolas,monospace;word-break:break-all"><?php echo $hmisTok !== '' ? e($hmisTok) : 'no token yet'; ?></div>
<form method="post" style="margin-top:8px" onsubmit="return confirm('Generating a new token immediately disables the old one.');"><?php echo csrf_field(); ?><input type="hidden" name="facility" value="<?php echo $GF; ?>"><input type="hidden" name="op" value="hmis_token"><button class="btn small" type="submit">&#128273; <?php echo $hmisTok !== '' ? 'Regenerate' : 'Generate'; ?> token</button></form>
<p style="font-size:11px;color:#7a94aa;margin-top:6px">Tokens are per facility &middot; over HTTPS &middot; every call audited. One token can only ever touch its own facility.</p>
<?php endif; ?>
<h3 style="margin:14px 0 6px">Latest claims &amp; disbursements</h3>
<table class="data" style="margin:0"><tr><th>Claim</th><th class="num">Amount</th><th>Status</th><th>Invoice</th></tr>
<?php foreach ($claims as $cl): ?><tr><td><a href="claim-receipt.php?id=<?php echo e($cl['id']); ?>" target="_blank"><?php echo e($cl['claim_no']); ?></a></td>
<td class="num"><?php echo ugx($cl['status']==='Paid' ? $cl['amount_approved'] : $cl['amount_billed']); ?></td>
<td><?php echo fac_status_chip($cl['status']); ?></td>
<td style="font-size:11px"><?php echo e($cl['status']==='Paid' ? ($cl['pay_method'] . ' · ' . $cl['pay_ref']) : ($cl['status']==='Pending' ? 'awaiting owner' : '—')); ?></td></tr><?php endforeach; ?>
<?php if (!$claims): ?><tr><td colspan="4" style="color:#7a94aa" id="claims">No claims yet.</td></tr><?php endif; ?></table>
</div></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
