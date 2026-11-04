<?php
/** v6.0 FACILITY MEMBER BILLING AND SETTLEMENT — CREATE MEMBER BILL.
 *  Bills are built from the logged-in facility's OWN price list and settled against the
 *  member's individual deposit account (verified balance, shortfall display, one-shot
 *  duplicate protection, full receipt, SMS after commit). */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));
if (!mb_require_tbl()) { include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/footer.php'; exit; }

$OWNER = fac_is_owner();
$lockedFid = fac_scope_fid();
$GF = intval(get('facility'));
if ($lockedFid > 0) $GF = $lockedFid; else $GF = fac_lock($GF);
if ($GF <= 0) { echo '<meta http-equiv="refresh" content="0;url=index.php">'; exit; }
$canSettle = can(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin'));
try { $pu = db()->prepare('SELECT perms FROM users WHERE id=?'); $pu->execute(array(intval($_SESSION['uid'])));
    $pr = $pu->fetch(); if ($pr && $pr['perms'] !== '' && strpos((string)$pr['perms'], 'settle') !== false) $canSettle = true; } catch (Exception $e) {}
$canRev = can(array('Super Admin', 'Administrator', 'Finance'));
$f = fac_of($GF);
/* v7.0 §3 — verification hand-off badge */
require_once __DIR__ . '/includes/facility70.php'; f70_ensure();
$vkw = trim((string) get('vk'));
$verNote = '';
if ($vkw !== '') { try { $vs = db()->prepare("SELECT v.*, m.full_names, m.member_no FROM member_verifications v JOIN members m ON m.id=v.member_id WHERE v.vtoken=? AND v.facility_id=? AND v.status='Verified' AND v.verified_at>=? ORDER BY v.id DESC LIMIT 1");
    $vs->execute(array($vkw, $GF, date('Y-m-d H:i:s', time() - 3600))); $vr = $vs->fetch();
    if ($vr) $verNote = 'Member ' . $vr['full_names'] . ' (' . $vr['member_no'] . ') verified via ' . strtoupper($vr['method']) . ' at ' . substr($vr['verified_at'], 11, 5) . ' — balance disclosed to the member by SMS. Settle with the member authorisation as usual.';
    else $verNote = 'That verification link has expired (30-minute window) — verify the member again to refresh the SMS trail.'; } catch (Throwable $e) {} }
$msg = ''; $msgType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'create') {
        $mid = intval(post('member'));
        $st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($mid)); $m = $st->fetch();
        if (!$m) { $msg = 'Pick a registered member first.'; }
        else {
            $lines = array();
            $qty = isset($_POST['qty']) && is_array($_POST['qty']) ? $_POST['qty'] : array();
            foreach ($qty as $pid => $q) { $q = (float)str_replace(',', '', (string)$q); if ($q <= 0) continue; $lines[] = array('price_id' => intval($pid), 'qty' => $q, 'name' => 'x'); }
            if (isset($_POST['cx_name'])) {
                foreach ((array)$_POST['cx_name'] as $i => $nm) {
                    $nm = trim((string)$nm); if ($nm === '') continue;
                    $lines[] = array('price_id' => 0, 'name' => $nm, 'qty' => max(1, (float)(string)(isset($_POST['cx_qty'][$i]) ? $_POST['cx_qty'][$i] : 1)),
                        'price' => (float)str_replace(',', '', (string)(isset($_POST['cx_price'][$i]) ? $_POST['cx_price'][$i] : 0)),
                        'cat' => (string)(isset($_POST['cx_cat'][$i]) ? $_POST['cx_cat'][$i] : 'Other'));
                }
            }
            $r = mb_create($GF, $m, $lines, array('date' => post('date'), 'discount_percent' => str_replace(',', '', post('discount')),
                'note' => post('note'), 'branch_id' => intval(post('branch')), 'payer_type' => post('payer_type'), 'payer_name' => post('payer_name')));
            if ($r['ok']) { $msgType = 'ok'; $msg = $r['msg']; redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . $r['id']); }
            $msg = $r['msg']; $msgType = 'err';
        }
    }
    if ($op === 'rmline') {
        $ok = mb_rm_line(intval(post('id')), intval(post('line')), $GF);
        flash($ok ? 'Line removed from the draft bill.' : 'Line not found (only your facility\u2019s pending bills can be edited).', $ok ? 'ok' : 'err');
        redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . intval(post('id')));
    }
    if ($op === 'mb_auth_send') {
        if (!$canSettle) { flash('You are not authorized to settle member bills.', 'err'); redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . intval(post('id'))); }
        $r = mb_auth_send(intval(post('id')), $_SESSION['uname']);
        flash(e($r['msg']), $r['ok'] ? 'ok' : 'err'); redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . intval(post('id')));
    }
    if ($op === 'mb_auth_verify') {
        if (!$canSettle) { flash('You are not authorized to settle member bills.', 'err'); redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . intval(post('id'))); }
        $r = mb_auth_verify(intval(post('id')), post('otp'), post('ecard'), $_SESSION['uname']);
        flash(e($r['msg']), $r['ok'] ? 'ok' : 'err'); redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . intval(post('id')));
    }
    if ($op === 'settle') {
        if (!$canSettle) { flash('You are not authorized to settle member bills.', 'err'); redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . intval(post('id'))); }
        $bid = intval(post('id'));
        $tok = isset($_SESSION['mbtok'][$bid]) ? (string)$_SESSION['mbtok'][$bid] : '';
        if ($tok === '' || !hash_equals($tok, post('tok'))) { flash('This settlement was already processed or has expired — reload the bill and try again (nothing was deducted).', 'err'); redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . $bid); }
        $r = mb_settle($bid, $_SESSION['uname']);
        if ($r['ok']) { unset($_SESSION['mbtok'][$bid]); flash(e($r['msg']), 'ok'); redirect($r['receipt']); }
        flash(e($r['msg']), 'err'); redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . $bid);
    }
    if ($op === 'cancel') {
        $b = mb_get(intval(post('id')));
        if (!$b || ($GF > 0 && intval($b['facility_id']) !== $GF && !$OWNER)) { flash('Bill not found.', 'err'); redirect('member-bills.php'); }
        if (!$OWNER && $_SESSION['role'] !== 'Facility Admin' && (string)$b['created_by'] !== (string)$_SESSION['uname']) { flash('Only the bill creator or a facility admin can cancel it.', 'err'); redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . intval(post('id'))); }
        $r = mb_cancel(intval(post('id')), post('reason'), $_SESSION['uname']);
        flash(e($r['msg']), $r['ok'] ? 'ok' : 'err'); redirect('member-bills.php');
    }
    if ($op === 'reverse') {
        if (!$canRev) { flash('Authorized reversal is for scheme finance roles only.', 'err'); redirect('member-bills.php'); }
        $r = mb_reverse(intval(post('id')), post('reason'), $_SESSION['uname']);
        flash(e($r['msg']), $r['ok'] ? 'ok' : 'err'); redirect('member-bills.php?view=bill&facility=' . $GF . '&id=' . intval(post('id')));
    }
}

$bid = intval(get('id'));
$bill = $bid > 0 ? mb_get($bid) : null;
if ($bill && intval($bill['facility_id']) !== $GF && !$OWNER) { flash('That bill belongs to another institution.', 'err'); redirect('member-bills.php'); }
$st = db()->prepare('SELECT COUNT(*) FROM institution_branches WHERE facility_id=?'); $st->execute(array($GF)); $hasBranches = intval($st->fetchColumn()) > 0;

$page_title = 'Member Clearing & Bill Settlement — ' . $f['name'];
include __DIR__ . '/includes/header.php';
$stats = mb_stats($GF);
?>
<div class="page-head"><h1>&#129534; FACILITY MEMBER BILLING &amp; SETTLEMENT</h1>
 <span class="sub">Create bills from <?php echo e($f['name']); ?>&rsquo;s own price list, verify the member&rsquo;s deposit balance and settle in one safe step. Facility prices, facility members, facility money — never mixed with another institution.</span>
<?php if ($OWNER): ?><form method="get" style="display:inline-flex;gap:6px;align-items:center;float:right"><input type="hidden" name="view" value="<?php echo e(get('view')); ?>"><input type="hidden" name="id" value="<?php echo $bid; ?>">
 <select class="f" name="facility" onchange="this.form.submit()" style="margin:0;padding:5px 8px"><?php foreach (fac_list() as $o): ?><option value="<?php echo e($o['id']); ?>" <?php echo intval($o['id'])===$GF?'selected':''; ?>><?php echo fac_icon($o['ftype']); ?> <?php echo e($o['name']); ?></option><?php endforeach; ?></select>
 <button class="btn small" type="submit">Switch</button></form><?php endif; ?>
</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">
 <div class="kpi"><div class="k-label">Registered members</div><div class="k-value"><?php echo num($stats['members']); ?></div><div class="k-sub"><?php echo num($stats['active']); ?> active &middot; <?php echo num($stats['funded']); ?> with deposits</div></div>
 <div class="kpi"><div class="k-label">Member deposit balances</div><div class="k-value"><?php echo ugx($stats['deposits']); ?></div><div class="k-sub"><?php echo num($stats['short']); ?> member(s) at zero / negative</div></div>
 <div class="kpi"><div class="k-label">Bills settled</div><div class="k-value"><?php echo num($stats['settled']); ?></div><div class="k-sub">deducted <?php echo ugx($stats['deducted']); ?></div></div>
 <div class="kpi <?php echo $stats['pending_n'] > 0 ? 'amber' : ''; ?>"><div class="k-label">Pending (unsettled) bills</div><div class="k-value"><?php echo num($stats['pending_n']); ?></div><div class="k-sub"><?php echo ugx($stats['pending_bills']); ?> awaiting funds</div></div>
</div>
<div style="margin:6px 0 14px;display:flex;gap:8px;flex-wrap:wrap">
 <a class="btn <?php echo get('view')==='new'?'primary':'' ?>" href="member-bills.php?view=new&facility=<?php echo $GF ?>">CREATE MEMBER BILL</a>
 <a class="btn <?php echo get('view')==='bill'||get('view')===false||get('view')==='list'?'primary':'' ?>" href="member-bills.php?view=list&facility=<?php echo $GF ?>">Bill register &amp; receipts</a>
 <a class="btn plain" href="pricelist.php?facility=<?php echo $GF ?>">Price list</a>
 <a class="btn plain" href="claims-batch.php?facility=<?php echo $GF ?>">Submit claim to Kisubi &#9878;&#65039;</a>
 <a class="btn plain" href="institution.php">Institution home</a>
</div>
<?php if (!empty($verNote)): ?><div style="background:#EAF2FA;border:1px solid #bcd4e8;color:#12476f;border-radius:6px;padding:9px 13px;font-size:13px;margin:10px 0">&#10003; <?php echo $verNote; ?></div><?php endif; ?>
<?php if ($msg !== ''): ?><div class="flash flash-<?php echo $msgType; ?>"><?php echo e($msg); ?></div><?php endif; ?>

<?php if (get('view') === 'new'): ?>
<?php // ---------------- CREATE MEMBER BILL ----------------
$mids = intval(get('member'));
$member = null;
if ($mids > 0) { $st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($mids)); $member = $st->fetch(); }
$sq = trim((string)get('s')); $picks = array();
if ($member === null) {
    if ($sq !== '') {
        $sql = "SELECT * FROM members WHERE (full_names LIKE ? OR member_no LIKE ? OR phone_no LIKE ? OR card_no LIKE ? OR national_id LIKE ? OR inst_member_no LIKE ?)"
             . ($lockedFid > 0 || trim((string)setting('allow_multi_beneficiaries','1'))==='0' ? ' AND (facility_id=' . $GF . ' OR facility_id=0)' : '')
             . " AND status<>'Suspended' ORDER BY full_names LIMIT 20";
        $like = '%' . $sq . '%'; $st = db()->prepare($sql); $st->execute(array($like,$like,$like,$like,$like,$like)); $picks = $st->fetchAll();
    }
} else {
    if (!$OWNER && intval($member['facility_id']) > 0 && intval($member['facility_id']) !== $GF && trim((string)setting('allow_multi_beneficiaries','1'))==='0') { $member = null; $msg = 'That member belongs to another institution.'; $msgType='err'; }
}
$avail = $member ? mb_avail(intval($member['id']), $GF) : null;
?>
<div class="panel"><h2 style="margin-top:0">&#128269; Step 1 — choose the member (member no, name, phone, card no or NIN)</h2>
 <form method="get" style="display:flex;gap:8px"><input type="hidden" name="view" value="new"><input type="hidden" name="facility" value="<?php echo $GF ?>">
  <input class="f" name="s" style="flex:1;margin:0" placeholder="search members registered at this facility…" value="<?php echo e($sq); ?>"><button class="btn primary" type="submit">Search</button></form>
<?php if ($member === null && $sq !== ''): ?>
 <table class="tbl" style="margin-top:8px"><tr><th>Member no</th><th>Name</th><th>Phone</th><th>Status</th><th>Deposit balance</th><th></th></tr>
 <?php if (!$picks) echo '<tr><td colspan="6" style="color:#7a94aa">No matching member registered at your facility.</td></tr>';
 foreach ($picks as $p): ?>
 <tr><td><b><?php echo e($p['member_no']); ?></b><?php echo $p['inst_member_no']!==''?'<br><span class="sub">'.e($p['inst_member_no']).'</span>':''; ?></td><td><?php echo e($p['full_names']); ?></td><td><?php echo e($p['phone_no']); ?></td><td><?php echo e($p['status']); ?></td>
 <td><?php echo ugx((float)$p['deposit_amount']); ?></td><td><a class="btn small primary" href="member-bills.php?view=new&facility=<?php echo $GF ?>&member=<?php echo $p['id']; ?>">Select</a></td></tr>
 <?php endforeach; ?></table>
<?php endif; ?>
<?php if ($member !== null): ?>
 <div style="margin-top:10px;padding:10px 12px;background:#EAF6EE;border:1px solid #bfe0c8;border-radius:6px">
  <b><?php echo e($member['full_names']); ?></b> (<?php echo e($member['member_no']); ?>) &middot; <?php echo e($member['phone_no']); ?> &middot;
  deposit balance <b><?php echo ugx($avail['bal']); ?></b><?php echo $avail['credit'] > 0 ? ' + credit facility ' . ugx($avail['credit']) : ''; ?>.
  <a class="btn small plain" style="margin-left:8px" target="_blank" href="member-account.php?member=<?php echo $member['id']; ?>&facility=<?php echo $GF ?>">View account &#8599;</a>
 </div>
 <form method="post" id="billform">
  <?php echo csrf_field(); ?><input type="hidden" name="op" value="create"><input type="hidden" name="member" value="<?php echo intval($member['id']); ?>">
  <h2 style="margin:16px 0 6px">&#129529; Step 2 — pick services from <u>your facility&rsquo;s price list</u></h2>
  <div style="display:flex;gap:8px;margin-bottom:6px"><input class="f" name="pq" style="flex:1;margin:0" placeholder="filter services — e.g. consultation, malaria, lab…" value="<?php echo e(get('pq')); ?>"></div>
<?php
$pw = array('facility_id=?'); $pa = array($GF);
try { db()->query('SELECT active FROM facility_prices LIMIT 1'); $pw[] = '(active IS NULL OR active=1)'; } catch (Throwable $e70) {}
if (trim((string)get('pq')) !== '') { $pw[] = '(item_name LIKE ? OR category LIKE ?)'; $pa[] = '%' . trim((string)get('pq')) . '%'; $pa[] = '%' . trim((string)get('pq')) . '%'; }
$st = db()->prepare('SELECT * FROM facility_prices WHERE ' . implode(' AND ', $pw) . ' ORDER BY category, item_name LIMIT 300'); $st->execute($pa); $items = $st->fetchAll();
$byCat = array(); foreach ($items as $it) $byCat[$it['category']][] = $it;
if (!$items): ?><p style="color:#8a6410">Your facility has no price list entries yet — <a href="pricelist.php?facility=<?php echo $GF ?>">create them here</a> first.</p><?php endif;
$cat50 = $items ? array() : array();
foreach ($byCat as $cat => $rows): ?>
 <table class="tbl" style="margin-bottom:8px"><tr><th colspan="5" style="background:#EAF2FA"><?php echo e($cat); ?> (<?php echo count($rows); ?>)</th></tr>
 <tr><th>Code</th><th>Service / item</th><th>Unit price</th><th>Unit</th><th style="width:110px">Quantity</th></tr>
 <?php foreach ($rows as $p): $pid = intval($p['id']); ?>
 <tr><td style="color:#7a94aa"><?php echo e(strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/','',$cat),0,3)) . '-' . $pid); ?></td>
  <td><b><?php echo e($p['item_name']); ?></b><br><span class="sub">effective <?php echo e($p['effective_date'] ?: '—'); ?></span></td>
  <td><?php echo num($p['price']); ?></td><td><?php echo e($p['unit']); ?></td>
  <td><input class="f qty-in" data-price="<?php echo (float)$p['price']; ?>" type="number" step="0.5" min="0" name="qty[<?php echo $pid; ?>]" value="0" style="margin:0;width:96px;text-align:right"></td></tr>
 <?php endforeach; ?></table>
<?php endforeach; ?>
  <h2 style="margin:14px 0 6px">&#10133; Other configured / custom lines</h2>
  <table class="tbl" id="cxt"><tr><th>Description</th><th>Category</th><th style="width:90px">Qty</th><th style="width:130px">Unit price</th><th style="width:40px"></th></tr>
   <tr><td><input class="f" name="cx_name[0]" placeholder="e.g. Special procedure — ward round"></td><td><input class="f" name="cx_cat[0]" placeholder="Other"></td><td><input class="f" type="number" name="cx_qty[0]" value="1" min="1"></td><td><input class="f" type="number" name="cx_price[0]" value="0" min="0"></td><td></td></tr></table>
  <button type="button" class="btn small plain" onclick="addcx()">+ Add line</button>
  <script>function addcx(){var t=document.getElementById('cxt');var n=t.querySelectorAll('input[name^="cx_name"]').length;var tr=document.createElement('tr');
  tr.innerHTML='<td><input class="f" name="cx_name['+n+']"></td><td><input class="f" name="cx_cat['+n+']"></td><td><input class="f" type="number" name="cx_qty['+n+']" value="1" min="1"></td><td><input class="f" type="number" name="cx_price['+n+']" value="0" min="0"></td><td></td>';t.appendChild(tr);}</script>
  <h2 style="margin:16px 0 6px">&#128179; Bill details</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
   <div><label class="f">Bill date</label><input class="f" type="date" name="date" value="<?php echo e(today()); ?>"></div>
   <div><label class="f">Authorized discount %</label><input class="f" type="number" min="0" max="100" step="0.5" name="discount" value="0" <?php echo $canSettle ? '' : 'readonly'; ?>></div>
   <?php if ($hasBranches): ?><div><label class="f">Branch</label><select class="f" name="branch"><option value="0">Main</option>
     <?php $bs = db()->prepare('SELECT id,name FROM institution_branches WHERE facility_id=? AND status=?'); $bs->execute(array($GF,'Active'));
     foreach ($bs->fetchAll() as $br): ?><option value="<?php echo intval($br['id']); ?>"><?php echo e($br['name']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
   <?php if ($OWNER || $canSettle): ?><div><label class="f">Third-party payer (optional)</label><select class="f" name="payer_type"><option value="None">None — member deposit</option><option value="DirectPayer">Another approved payer funds this bill</option></select></div>
   <div><label class="f">Payer name</label><input class="f" name="payer_name" placeholder="e.g. UN Staff Insurance"></div><?php endif; ?>
   <div style="grid-column:1/-1"><label class="f">Note / justification</label><input class="f" name="note" placeholder="optional"></div>
  </div>
  <div style="margin-top:12px;display:flex;gap:10px;align-items:center">
   <button class="btn primary" type="submit" onclick="this.disabled=true;this.form.submit()">Create bill &amp; verify balance &#10145;</button>
   <span class="sub">Live bill total: <b id="livetotal">0</b> UGX &middot; member balance <b><?php echo $avail ? num($avail['bal']) : '—'; ?></b></span>
  </div>
 </form>
 <script>
 (function(){function rec(){var t=0;document.querySelectorAll('.qty-in').forEach(function(i){t+=(parseFloat(i.value)||0)*(parseFloat(i.getAttribute('data-price'))||0);});
  document.querySelectorAll('#cxt tr').forEach(function(tr){var n=tr.querySelector('[name^="cx_name"]');if(!n)return;var q=parseFloat((tr.querySelector('[name^="cx_qty"]')||{}).value)||0,p=parseFloat((tr.querySelector('[name^="cx_price"]')||{}).value)||0;if(n.value.trim()!=='')t+=q*p;});
  var d=parseFloat((document.querySelector('[name=discount]')||{}).value)||0;var net=t*(1-Math.min(100,Math.max(0,d))/100);
  var el=document.getElementById('livetotal');if(el)el.textContent=Math.round(net).toLocaleString();}
  document.addEventListener('input',rec);rec();})();
 </script>
<?php endif; ?>
</div>
<?php elseif ($bill): ?>
<?php // ---------------- BILL DETAIL / SETTLE DESK ----------------
$lines = mb_lines(intval($bill['id']));
$chk = mb_check(intval($bill['id']));
if (empty($_SESSION['mbtok'][$bid])) $_SESSION['mbtok'][$bid] = bin2hex(random_bytes(8));
$tok = $_SESSION['mbtok'][$bid];
?>
<div class="panel"><h2 style="margin-top:0">&#129534; Bill <?php echo e($bill['bill_no']); ?>
 <?php $sc = array('Pending'=>'#8a6410','Settled'=>'#1e7f3c','Cancelled'=>'#7a94aa','Reversed'=>'#a33'); ?>
 <span style="color:<?php echo isset($sc[$bill['status']]) ? $sc[$bill['status']] : '#333'; ?>">&middot; <?php echo e($bill['status']); ?></span></h2>
 <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px;font-size:14px">
  <div><b>Member</b><br><?php echo e($bill['patient_name']); ?> (<?php echo e($bill['member_no']); ?>)</div>
  <div><b>Facility / branch</b><br><?php echo e($f['name']); ?><?php if (intval($bill['branch_id'])>0){ $bt = db()->prepare('SELECT name FROM institution_branches WHERE id=?'); $bt->execute(array(intval($bill['branch_id']))); echo ' &middot; ' . e((string)$bt->fetchColumn()); } ?></div>
  <div><b>Bill date</b><br><?php echo e($bill['bill_date']); ?></div>
  <div><b>Subtotal</b><br><?php echo ugx($bill['subtotal']); ?></div>
  <div><b>Discount</b><br><?php echo e($bill['discount_percent']); ?>%</div>
  <div><b>Total bill amount</b><br><b style="font-size:17px"><?php echo ugx($bill['total']); ?></b></div>
  <?php if ($bill['payer_type'] === 'DirectPayer'): ?><div><b>Payer</b><br><?php echo e($bill['payer_name'] ?: 'Approved payer'); ?></div><?php endif; ?>
  <?php if ($bill['receipt_no'] !== ''): ?><div><b>Receipt</b><br><a href="receipt-member.php?id=<?php echo $bid; ?>"><?php echo e($bill['receipt_no']); ?> &#8595;print</a></div><?php endif; ?>
 </div>
 <table class="tbl" style="margin-top:12px"><tr><th>Code</th><th>Service / item</th><th>Category</th><th style="text-align:right">Unit price</th><th style="text-align:right">Qty</th><th style="text-align:right">Total</th><?php if ($bill['status']==='Pending'): ?><th></th><?php endif; ?></tr>
 <?php foreach ($lines as $l): ?>
 <tr><td style="color:#7a94aa"><?php echo e($l['item_code']); ?></td><td><b><?php echo e($l['item_name']); ?></b></td><td><?php echo e($l['category']); ?></td>
  <td style="text-align:right"><?php echo num($l['unit_price']); ?></td><td style="text-align:right"><?php echo e($l['qty']); ?></td><td style="text-align:right"><b><?php echo num($l['line_total']); ?></b></td>
  <?php if ($bill['status']==='Pending'): ?><td><form method="post" style="margin:0" onsubmit="return confirm('Remove this line from the draft bill?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="rmline"><input type="hidden" name="id" value="<?php echo $bid; ?>"><input type="hidden" name="line" value="<?php echo intval($l['id']); ?>"><button class="btn small plain" type="submit">&#10006;</button></form></td><?php endif; ?>
 </tr><?php endforeach; if (!$lines): ?><tr><td colspan="7" style="color:#7a94aa">No lines.</td></tr><?php endif; ?></table>

 <h2 style="margin:16px 0 6px">&#128270; Step 3 — deposit balance verification</h2>
<?php if ($chk): ?>
 <div style="padding:12px;border-radius:8px;border:2px solid <?php echo $chk['sufficient'] ? '#1e7f3c' : '#a33'; ?>;background:<?php echo $chk['sufficient'] ? '#EDF9F0' : '#FDF0F0'; ?>;display:flex;gap:26px;flex-wrap:wrap">
  <div><div class="k-label">Available Deposit</div><div style="font:bold 19px Georgia"><?php echo ugx($chk['bal']); ?></div><?php echo $chk['credit']>0?'<div class="sub">+ authorized credit facility '.ugx($chk['credit']).'</div>':''; ?><?php echo $chk['reserved']>0?'<div class="sub" style="color:#a33">'.ugx($chk['reserved']).' reserved for pending refund payouts</div>':''; ?></div>
  <div><div class="k-label">Amount to be settled (bill <?php echo e($bill['bill_no']); ?>)</div><div style="font:bold 19px Georgia"><?php echo ugx($chk['total']); ?></div></div>
  <div><div class="k-label"><?php echo $chk['sufficient'] ? 'Remaining Deposit after settlement' : 'Funding shortfall — settlement blocked'; ?></div>
   <div style="font:bold 19px Georgia;color:<?php echo $chk['sufficient'] ? '#1e7f3c' : '#a33'; ?>"><?php echo $chk['sufficient'] ? ugx(round($chk['spendable'] - $chk['total'], 2)) : ugx($chk['shortfall']); ?></div></div>
  <div style="align-self:center"><?php echo $chk['sufficient'] ? '<span style="color:#1e7f3c;font-weight:bold">&#10004; Sufficient funds — settlement enabled.</span>' : '<span style="color:#a33;font-weight:bold">&#9888; Insufficient funds — settlement is blocked. Save as pending or fund the account.</span>'; ?></div>
 </div>
<?php endif; ?>
<?php $authNeed = ((string)$bill['payer_type'] !== 'DirectPayer') && mb_auth_required($GF); $authOK = !$authNeed || (string)$bill['auth_status'] === 'Authorized'; ?>
<?php if ($bill['status'] === 'Pending' && $authNeed): ?>
 <div class="panel" style="margin:0 0 10px;padding:12px 14px;border:2px solid <?php echo $authOK ? '#1e7f3c' : '#b8860b'; ?>;background:<?php echo $authOK ? '#EDF9F0' : '#FFF9EC'; ?>">
  <b>&#128274; Member authorization — e-Card + OTP (Kisubi Hospital standard)</b>
  <?php if ($authOK): ?><div style="color:#1e7f3c;font-weight:bold;margin-top:4px">&#10004; Authorized by the member (<?php echo e((string)$bill['auth_method'] ?: 'member'); ?><?php echo (string)$bill['auth_ref'] !== '' ? ' · ' . e((string)$bill['auth_ref']) : ''; ?> <?php echo e(substr((string)$bill['auth_at'], 0, 16)); ?>) — you may settle now.</div>
  <?php else: ?>
   <div class="sub" style="margin:4px 0 8px">The member must confirm this payment before it deducts their deposit: present the <b>e-Card</b> and enter the <b>OTP</b> sent to the member's phone (the member may also authorize from their portal). The bill stays Pending until authorized.</div>
   <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <form method="post" style="display:flex;gap:6px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="mb_auth_send"><input type="hidden" name="id" value="<?php echo $bid; ?>"><button class="btn" type="submit">&#128242; Send OTP to member</button></form>
    <form method="post" style="display:flex;gap:6px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="mb_auth_verify"><input type="hidden" name="id" value="<?php echo $bid; ?>"><input class="f" name="ecard" placeholder="e-Card number" style="margin:0;width:170px" required><input class="f" name="otp" placeholder="6-digit OTP" style="margin:0;width:120px" maxlength="6" pattern="[0-9]{6}" required><button class="btn primary" type="submit">&#128273; Verify &amp; authorize</button></form>
    <?php if ((string)$bill['auth_status'] === 'Awaiting member'): ?><span class="sub" style="color:#8a6410">An OTP was already requested — awaiting the member's code (valid 5 minutes).</span><?php elseif ((string)$bill['auth_status'] === 'Declined'): ?><div style="color:#a33;font-weight:bold;margin-top:6px">&#9888; The member DECLINED this payment from their portal — settlement stays blocked. Agree with the member (they may re-authorize), cancel this bill, or record a new one.</div><?php endif; ?>
   </div>
  <?php endif; ?>
 </div>
<?php endif; ?>
 <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
<?php if ($bill['status'] === 'Pending'): ?>
 <?php if ($canSettle): ?>
 <form method="post" onsubmit="this.querySelector('button').disabled=true"><?php echo csrf_field(); ?><input type="hidden" name="op" value="settle"><input type="hidden" name="id" value="<?php echo $bid; ?>"><input type="hidden" name="tok" value="<?php echo e($tok); ?>">
  <button class="btn primary" type="submit" <?php echo ($chk && $chk['sufficient'] && $authOK) ? '' : 'disabled title="' . ($authOK ? 'Insufficient member balance' : 'Awaiting member authorization') . '"'; ?> style="<?php echo ($chk && $chk['sufficient'] && $authOK) ? '' : 'opacity:.45;'; ?>">&#9989; SETTLE BILL</button></form>
 <?php endif; ?>
 <form method="post" style="display:inline-flex;gap:6px;align-items:center" onsubmit="return confirm('Cancel this bill? It stays on record as cancelled.')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="cancel"><input type="hidden" name="id" value="<?php echo $bid; ?>">
  <input class="f" name="reason" placeholder="reason for cancel" style="margin:0;width:220px" required><button class="btn plain" type="submit">Cancel bill</button></form>
<?php elseif ($bill['status'] === 'Settled' && $canRev): ?>
 <form method="post" style="display:inline-flex;gap:6px;align-items:center" onsubmit="return confirm('Post a reversal — refunds the member and records a correction?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="reverse"><input type="hidden" name="id" value="<?php echo $bid; ?>">
  <input class="f" name="reason" placeholder="authorized reversal reason (required)" style="margin:0;width:320px" required><button class="btn" type="submit">&#8617; Reverse settlement</button></form>
<?php endif; ?>
 <a class="btn" href="receipt-member.php?id=<?php echo $bid; ?>">&#128424; Receipt</a>
 <a class="btn plain" href="member-account.php?member=<?php echo intval($bill['member_id']); ?>&facility=<?php echo $GF ?>">Member account &#8599;</a>
 <a class="btn plain" href="member-bills.php?view=list&facility=<?php echo $GF ?>">Back to register</a>
 </div>
 <?php if ($bill['note'] !== ''): ?><p class="sub" style="margin-top:10px"><b>Note:</b> <?php echo e($bill['note']); ?></p><?php endif; ?>
 <?php if ($bill['reverse_reason'] !== ''): ?><p class="sub" style="color:#a33"><b>Reversal:</b> <?php echo e($bill['reverse_reason']); ?></p><?php endif; ?>
 <?php if (intval($bill['claim_id'])>0): $cn = db()->prepare('SELECT claim_no,status FROM facility_claims WHERE id=?'); $cn->execute(array(intval($bill['claim_id']))); $cc = $cn->fetch();
   if ($cc): ?><p class="sub">On claim <a href="claim-view.php?id=<?php echo intval($bill['claim_id']); ?>"><?php echo e($cc['claim_no']); ?></a> (<?php echo e(cl_slabel($cc['status'])); ?>).</p><?php endif; endif; ?>
</div>
<?php else: ?>
<?php // ---------------- BILL REGISTER / RECEIPT SEARCH ----------------
$q = trim((string)get('q')); $fw = array('b.facility_id=?'); $fa = array($GF);
if ($q !== '') { $fw[] = '(b.bill_no LIKE ? OR b.patient_name LIKE ? OR b.member_no LIKE ? OR m.phone_no LIKE ? OR b.receipt_no LIKE ?)';
  for ($i=0;$i<5;$i++) $fa[] = '%' . $q . '%'; }
if (in_array((string)get('status'), array('Pending','Settled','Cancelled','Reversed'), true)) { $fw[] = 'b.status=?'; $fa[] = (string)get('status'); }
$st = db()->prepare('SELECT b.*, m.phone_no FROM member_bills b JOIN members m ON m.id=b.member_id WHERE ' . implode(' AND ', $fw) . ' ORDER BY b.id DESC LIMIT 150');
$st->execute($fa); $rowsB = $st->fetchAll();
?>
<div class="panel"><h2 style="margin-top:0">&#128203; Member bill register — search, settle, reprint receipts</h2>
 <form method="get" style="display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="view" value="list"><input type="hidden" name="facility" value="<?php echo $GF ?>">
  <input class="f" name="q" style="flex:1;margin:0;min-width:220px" placeholder="bill no, receipt no, member name/no, phone…" value="<?php echo e($q); ?>">
  <select class="f" name="status" style="margin:0;width:150px"><option value="">Any status</option><?php foreach (array('Pending','Settled','Cancelled','Reversed') as $s): ?><option <?php echo (string)get('status')===$s?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?></select>
  <button class="btn primary" type="submit">Search</button></form>
 <table class="tbl" style="margin-top:10px"><tr><th>Bill no</th><th>Date</th><th>Member</th><th style="text-align:right">Total</th><th>Status</th><th style="text-align:right">Member balance</th><th>Processed by</th><th></th></tr>
 <?php if (!$rowsB) echo '<tr><td colspan="8" style="color:#7a94aa">No bills match.</td></tr>';
 foreach ($rowsB as $r): ?>
 <tr><td><b><?php echo e($r['bill_no']); ?></b><?php if ($r['receipt_no']!==''): ?><br><span class="sub">receipt <?php echo e($r['receipt_no']); ?></span><?php endif; ?></td>
  <td><?php echo e($r['bill_date']); ?></td><td><?php echo e($r['patient_name']); ?><br><span class="sub"><?php echo e($r['member_no']); ?> &middot; <?php echo e($r['phone_no']); ?></span></td>
  <td style="text-align:right"><?php echo num($r['total']); ?></td><td><?php echo e($r['status']); ?></td>
  <td style="text-align:right"><?php echo $r['balance_after'] !== null ? num($r['balance_after']) : '<span class="sub">now ' . num((float)db()->query('SELECT deposit_amount FROM members WHERE id=' . intval($r['member_id']))->fetchColumn()) . '</span>'; ?></td>
  <td><?php echo e($r['status']==='Settled' ? $r['settled_by'] : $r['created_by']); ?></td>
  <td style="white-space:nowrap"><?php if ($r['status']==='Settled'): ?><a class="btn small" href="receipt-member.php?id=<?php echo intval($r['id']); ?>">Receipt</a> <?php endif; ?><a class="btn small plain" href="member-bills.php?view=bill&facility=<?php echo $GF ?>&id=<?php echo intval($r['id']); ?>">Open</a></td></tr>
 <?php endforeach; ?></table>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
