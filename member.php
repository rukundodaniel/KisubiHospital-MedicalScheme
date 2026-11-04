<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = intval(get('id'));
$st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($id));
$m = $st->fetch();
$has_ext = array_key_exists('mtn_no', (array)$m);
if (!$m) { flash('Member not found.', 'err'); redirect('members.php'); }

$st = db()->prepare('SELECT * FROM deposits WHERE member_id=? ORDER BY deposit_date DESC, id DESC'); $st->execute(array($id)); $deps = $st->fetchAll();
$st = db()->prepare('SELECT * FROM withdrawals WHERE member_id=? ORDER BY withdraw_date DESC, id DESC'); $st->execute(array($id)); $wds = $st->fetchAll();
$st = db()->prepare('SELECT * FROM medical_bills WHERE member_id=? ORDER BY bill_date DESC, id DESC'); $st->execute(array($id)); $bills = $st->fetchAll();
$st = db()->prepare('SELECT * FROM beneficiaries WHERE member_id=? ORDER BY id'); $st->execute(array($id)); $bens = $st->fetchAll();

$totalDep = 0; foreach ($deps as $x) $totalDep += $x['amount'];
$totalWd = 0;  foreach ($wds as $x) if ($x['status'] === 'Paid') $totalWd += $x['amount'];
$totalBill = 0; foreach ($bills as $x) if ($x['status'] === 'Paid') $totalBill += $x['amount'];
$bal = $totalDep - $totalWd - $totalBill;

$pg_title = 'Member File'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head">
  <div><h1><?php echo e($m['full_names']); ?> <span style="color:#5b7c99;font-size:15px">(<?php echo e($m['member_no']); ?>)</span></h1>
  <span class="sub">Member since <?php echo e(date('d M Y', strtotime($m['registration_date']))); ?> &middot; Tel <?php echo e($m['phone_no']); ?> &middot; <span class="badge <?php echo $m['status']=='Active'?'green':'grey'; ?>"><?php echo e($m['status']); ?></span></span></div>
  <div class="noprint"><a class="btn plain" href="members.php">&laquo; Members</a>
  <a class="btn plain" href="#" onclick="window.print();return false">Print file</a></div>
</div>

<div class="grid grid-4" style="margin-bottom:14px">
  <div class="kpi green"><div class="k-label">Total Deposited</div><div class="k-value"><?php echo ugx($totalDep); ?></div><div class="k-sub"><?php echo count($deps); ?> deposits</div></div>
  <div class="kpi red"><div class="k-label">Withdrawn</div><div class="k-value"><?php echo ugx($totalWd); ?></div><div class="k-sub"><?php echo count($wds); ?> requests</div></div>
  <div class="kpi gold"><div class="k-label">Bills Settled</div><div class="k-value"><?php echo ugx($totalBill); ?></div><div class="k-sub"><?php echo count($bills); ?> bills</div></div>
  <div class="kpi"><div class="k-label">Account Balance</div><div class="k-value"><?php echo ugx($bal); ?></div><div class="k-sub">Deposits &minus; payouts</div></div>
</div>

<div class="panel"><h2>Member particulars</h2>
  <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">
    <?php if ($has_ext): ?><img src="memberpic.php?id=<?php echo e($m['id']); ?>" style="width:150px;height:170px;object-fit:cover;border:2px solid #12476f;border-radius:6px" onerror="this.style.display='none'"><?php endif; ?>
    <table class="data" style="flex:1;min-width:280px;border:0">
      <tr><td style="color:#5b7c99;width:30%;border:0">Gender / Marital</td><td style="border:0"><?php echo e($m['gender']); ?><?php echo $has_ext && !empty($m['marital_status']) ? ' &middot; ' . e($m['marital_status']) : ''; ?><?php echo $has_ext && !empty($m['dob']) ? ' &middot; DOB ' . e($m['dob']) : ''; ?></td></tr>
      <tr><td style="color:#5b7c99;border:0">Mobile</td><td style="border:0"><?php echo $has_ext ? ('MTN ' . e($m['mtn_no']) . ' &middot; Airtel ' . e($m['airtel_no'])) : e($m['phone_no']); ?></td></tr>
      <tr><td style="color:#5b7c99;border:0">Address</td><td style="border:0"><?php echo e($m['address']); ?><?php echo $has_ext && !empty($m['district']) ? ' &middot; ' . e($m['district']) . ' / ' . e($m['lc1_village']) : ''; ?></td></tr>
      <tr><td style="color:#5b7c99;border:0">NIN / Card</td><td style="border:0"><?php echo e($m['national_id']); ?><?php echo $has_ext && !empty($m['card_no']) ? ' &middot; card <b style="font-family:Courier New">' . e($m['card_no']) . '</b>' : ''; ?></td></tr>
      <?php if ($has_ext && !empty($m['email_address'])): ?><tr><td style="color:#5b7c99;border:0">Email</td><td style="border:0"><?php echo e($m['email_address']); ?></td></tr><?php endif; ?>
      <?php if ($has_ext && !empty($m['reg_by'])): ?><tr><td style="color:#5b7c99;border:0">Registered by</td><td style="border:0"><?php echo e($m['reg_by']); ?></td></tr><?php endif; ?>
      <tr><td style="color:#5b7c99;border:0">Actions</td><td style="border:0"><a class="btn small plain noprint" href="idcard.php?id=<?php echo e($m['id']); ?>" target="_blank">🪪 ID Card</a>
      <a class="btn small plain noprint" href="members.php?a=edit&id=<?php echo e($m['id']); ?>">Edit particulars</a></td></tr>
    </table>
  </div>
</div>

<div class="grid grid-2">
  <div class="panel"><h2>Deposit ledger</h2><div class="twrap" style="border:0"><table class="data">
    <tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr>
    <?php if (!$deps): ?><tr><td colspan="4" style="color:#5b7c99">None yet.</td></tr><?php endif; ?>
    <?php foreach ($deps as $r): ?><tr><td><?php echo e(date('d M Y', strtotime($r['deposit_date']))); ?></td><td><?php echo e($r['payment_method']); ?></td><td><?php echo e($r['reference']); ?></td><td class="num"><?php echo ugx($r['amount']); ?></td></tr><?php endforeach; ?>
  </table></div></div>
  <div class="panel"><h2>Claims &amp; bills</h2><div class="twrap" style="border:0"><table class="data">
    <tr><th>Date</th><th>Type</th><th>Detail</th><th class="num">Amount</th><th>Status</th></tr>
    <?php
    $all = array();
    foreach ($wds as $r) $all[] = array('d'=>$r['withdraw_date'],'t'=>'Withdrawal','x'=>$r['purpose'],'a'=>$r['amount'],'s'=>$r['status']);
    foreach ($bills as $r) $all[] = array('d'=>$r['bill_date'],'t'=>'Bill: '.$r['provider'],'x'=>$r['description'],'a'=>$r['amount'],'s'=>$r['status']);
    usort($all, function ($a, $b) { return strtotime($b['d']) - strtotime($a['d']); });
    if (!$all): ?>
    <tr><td colspan="5" style="color:#5b7c99">None yet.</td></tr>
    <?php endif; foreach ($all as $r): $bc=$r['s']=='Paid'?'green':($r['s']=='Rejected'?'red':($r['s']=='Approved'?'blue':'amber')); ?>
    <tr><td><?php echo e(date('d M Y', strtotime($r['d']))); ?></td><td><?php echo e($r['t']); ?></td><td><?php echo e($r['x']); ?></td><td class="num"><?php echo ugx($r['a']); ?></td><td><span class="badge <?php echo $bc; ?>"><?php echo e($r['s']); ?></span></td></tr>
    <?php endforeach; ?>
  </table></div></div>
</div>

<div class="panel"><h2>Registered beneficiaries (beneficially)
  <a class="btn small plain" style="float:right;margin-top:-30px" href="report-beneficiaries.php?member_id=<?php echo e($m['id']); ?>" target="_blank">Print report</a></h2>
<div class="twrap" style="border:0"><table class="data">
<tr><th>Full names</th><th>Relationship</th><th>National ID</th><th>Phone</th><th class="noprint"></th></tr>
<?php foreach ($bens as $b): ?>
<tr><td><?php echo e($b['full_names']); ?></td><td><?php echo e($b['relationship']); ?></td><td><?php echo e($b['national_id']); ?></td><td><?php echo e($b['phone_no']); ?></td>
<td class="noprint"><?php if (can(array('Super Admin','Administrator'))): ?><form method="post" action="beneficiaries.php" style="display:inline" onsubmit="return confirm('Remove beneficiary?')">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?php echo e($b['id']); ?>"><input type="hidden" name="member_id" value="<?php echo e($id); ?>">
<button class="btn small danger" type="submit">Remove</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$bens): ?><tr><td colspan="5" style="color:#5b7c99">No beneficiaries registered for this member.</td></tr><?php endif; ?>
</table></div>
<?php if (can(array('Super Admin','Administrator','Reception'))): ?>
<form method="post" action="beneficiaries.php" class="inline" style="margin-top:12px"><?php echo csrf_field(); ?>
<input type="hidden" name="op" value="save"><input type="hidden" name="member_id" value="<?php echo e($id); ?>">
<div style="min-width:200px"><label class="f">Add beneficiary</label><input class="f" name="full_names" placeholder="Full names *" required></div>
<div style="min-width:140px"><label class="f">Relationship</label><select class="f" name="relationship"><?php foreach(array('Spouse','Child','Parent','Sibling','Other') as $rr) echo '<option>'.$rr.'</option>'; ?></select></div>
<div style="min-width:150px"><label class="f">National ID</label><input class="f" name="national_id"></div>
<div style="min-width:130px"><label class="f">Phone</label><input class="f" name="phone_no"></div>
<button class="btn primary" type="submit">Add</button></form>
<?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
