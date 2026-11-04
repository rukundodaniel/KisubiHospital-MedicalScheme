<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'save') {
        $mid = intval(post('member_id')); $amt = (float)str_replace(',', '', post('amount'));
        if ($mid <= 0 || $amt <= 0) { flash('Select a member and amount.', 'err'); redirect('withdrawals.php?a=new'); }
        $st = db()->prepare('INSERT INTO withdrawals (member_id, withdraw_date, amount, purpose, status, processed_by, created_at) VALUES (?,?,?,?,?,?,?)');
        $st->execute(array($mid, post('date') !== '' ? post('date') : today(), $amt, post('purpose'), 'Pending', $_SESSION['uname'], date('Y-m-d H:i:s')));
        log_action('Withdrawal request ' . ugx($amt), 'withdrawals', intval(db()->lastInsertId()));
        flash('Withdrawal request submitted (status: Pending).'); redirect('withdrawals.php');
    }
    if ($op === 'setstatus') {
        require_role(array('Super Admin', 'Administrator', 'Finance'));
        $id = intval(post('id')); $new = post('status');
        if (!in_array($new, array('Pending', 'Approved', 'Paid', 'Rejected'), true)) { flash('Bad status.', 'err'); redirect('withdrawals.php'); }
        $st = db()->prepare('UPDATE withdrawals SET status=?, processed_by=?, paid_date=? WHERE id=?');
        $st->execute(array($new, $_SESSION['uname'], $new === 'Paid' ? today() : null, $id));
        log_action('Withdrawal #' . $id . ' -> ' . $new, 'withdrawals', $id);
        flash('Withdrawal #' . $id . ' marked ' . $new . '.'); redirect('withdrawals.php');
    }
}

if (get('a') === 'new') {
    $members = db()->query('SELECT id, member_no, full_names FROM members WHERE status="Active" ORDER BY full_names')->fetchAll();
    $pg_title = 'New Withdrawal'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head"><h1>New Withdrawal / Settlement Request</h1><a class="btn plain" href="withdrawals.php">&laquo; Back</a></div>
<div class="panel"><h2>Request details</h2>
<form method="post" action="withdrawals.php"><?php echo csrf_field(); ?><input type="hidden" name="op" value="save">
<div class="formgrid">
  <div class="span2"><label class="f">Member *</label><select class="f" name="member_id" required><option value="0">-- select member --</option>
    <?php foreach ($members as $mm): ?><option value="<?php echo e($mm['id']); ?>"><?php echo e($mm['member_no'] . ' - ' . $mm['full_names']); ?></option><?php endforeach; ?></select></div>
  <div><label class="f">Amount (UGX) *</label><input class="f" type="number" min="1000" step="500" name="amount" required></div>
  <div><label class="f">Date</label><input class="f" type="date" name="date" value="<?php echo today(); ?>"></div>
  <div class="span2"><label class="f">Purpose</label><input class="f" name="purpose" placeholder="e.g. Refund, medical support"></div>
</div>
<div style="margin-top:16px"><button class="btn success" type="submit">Submit request</button> <a class="btn plain" href="withdrawals.php">Cancel</a></div>
</form>
<p style="font-size:12px;color:#5b7c99;margin-bottom:0">Requests go to <b>Pending</b>. Finance approves, then marks them <b>Paid</b> when money leaves the scheme.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; exit;
}

$status = get('status');
$where = '1=1'; $args = array();
if (in_array($status, array('Pending','Approved','Paid','Rejected'), true)) { $where = 'w.status=?'; $args = array($status); }
$hasSettle = false; try { db()->query('SELECT auth_method, invoice_no, patient_name, discount_percent FROM withdrawals LIMIT 1'); $hasSettle = true; } catch (Exception $e) {}
if (get('view') === 'settlements' && $hasSettle) { $where .= ' AND ' . "(w.auth_method IS NOT NULL AND w.auth_method <> '')"; }
$st = db()->prepare("SELECT COUNT(*) FROM withdrawals w WHERE $where"); $st->execute($args); $total = intval($st->fetchColumn());
$st = db()->prepare("SELECT COALESCE(SUM(CASE WHEN w.status='Paid' THEN w.amount ELSE 0 END),0) FROM withdrawals w"); $paidTotal = (float)$st->fetchColumn();
$settleSum = 0.0; $settleCnt = 0;
if ($hasSettle) { $st = db()->prepare("SELECT COALESCE(SUM(amount),0) t, COUNT(*) c FROM withdrawals WHERE auth_method IS NOT NULL AND auth_method <> '' AND status='Paid'"); $st->execute(array()); $x = $st->fetch(); $settleSum = (float)$x['t']; $settleCnt = intval($x['c']); }
$page = max(1, intval(get('page'))); $per = 25; $off = ($page-1)*$per;
$st = db()->prepare("SELECT w.*, m.member_no, m.full_names FROM withdrawals w JOIN members m ON m.id=w.member_id WHERE $where ORDER BY w.id DESC LIMIT $per OFFSET $off");
$st->execute($args); $rows = $st->fetchAll();

$pg_title = 'Withdrawals'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head">
  <div><h1>Withdrawals &amp; Settlements</h1><span class="sub">All-time paid out: <b><?php echo ugx($paidTotal); ?></b><?php if ($hasSettle): ?> &middot; desk settlements: <b><?php echo ugx($settleSum); ?></b> (<?php echo $settleCnt; ?>)</span><?php endif; ?> &middot; view:</span>
  <span class="sub">
    <a href="withdrawals.php">All</a> | <a href="withdrawals.php?status=Pending">Pending</a> |
    <a href="withdrawals.php?status=Approved">Approved</a> | <a href="withdrawals.php?status=Paid">Paid</a> |
    <a href="withdrawals.php?view=settlements">Settlements</a>
  </span></div>
  <div class="noprint"><a class="btn success" href="bills.php?a=settle">&#127979; Settlement Desk</a>
  <a class="btn plain" href="withdrawals.php?a=new">+ New request</a>
  <a class="btn plain" href="export.php?table=withdrawals">Export CSV</a></div>
</div>
<div class="twrap"><table class="data">
<tr><th>#</th><th>Date</th><th>Member</th><?php if ($hasSettle): ?><th>Settlement</th><?php endif; ?><th class="num">Amount</th><th>Purpose</th><th>Status</th><th>Paid</th><th class="noprint">Workflow</th></tr>
<?php if (!$rows): ?><tr><td colspan="<?php echo $hasSettle?9:8; ?>" style="color:#5b7c99">No withdrawal records.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $bc = $r['status']=='Paid'?'green':($r['status']=='Rejected'?'red':($r['status']=='Approved'?'blue':'amber')); $isSettle = $hasSettle && !empty($r['auth_method']); ?>
<?php $pre = ''; ?>
<tr><td><?php echo e($r['id']); ?></td>
<td><?php echo e(date('d M Y', strtotime($r['withdraw_date']))); ?></td>
<td><b><?php echo e($r['member_no']); ?></b> <?php echo e($r['full_names']); ?></td>
<?php if ($hasSettle): ?><td><?php if ($isSettle): ?><span class="badge blue">SETTLED</span><br><span style="font-size:10.5px;color:#5b7c99"><?php echo e($r['invoice_no'] !== '' && $r['invoice_no'] !== null ? 'Inv ' . $r['invoice_no'] : 'no. inv'); ?><?php echo $r['patient_name'] ? ' &middot; ' . e(mb_substr($r['patient_name'],0,18)) : ''; ?><br><?php echo e($r['auth_method']); ?><?php echo (float)$r['discount_percent'] > 0 ? ' &middot; disc ' . e($r['discount_percent']) . '%' : ''; ?></span><?php else: ?>—<?php endif; ?></td><?php endif; ?>
<td class="num"><b><?php echo ugx($r['amount']); ?></b></td>
<td><?php echo e($r['purpose']); ?></td>
<td><span class="badge <?php echo $bc; ?>"><?php echo e($r['status']); ?></span></td>
<td><?php echo $r['paid_date'] ? e(date('d M Y', strtotime($r['paid_date']))) : '—'; ?></td>
<td class="noprint" style="white-space:nowrap"><?php if ($isSettle): ?><a class="btn small primary" href="receipt-settlement.php?id=<?php echo e($r['id']); ?>" target="_blank">Receipt</a> <?php endif; ?><?php if (can(array('Super Admin','Administrator','Finance')) && $r['status']!=='Paid' && $r['status']!=='Rejected'): ?>
<form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="setstatus"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
<?php if ($r['status']==='Pending'): ?><input type="hidden" name="status" value="Approved"><button class="btn small plain" type="submit">Approve</button><?php endif; ?>
<?php if ($r['status']==='Approved'): ?><input type="hidden" name="status" value="Paid"><button class="btn small success" type="submit">Pay</button><?php endif; ?>
<?php if ($r['status']!=='Rejected'): ?><input type="hidden" name="status" value="Rejected"><button class="btn small danger" type="submit">Reject</button><?php endif; ?>
</form><?php else: ?>—<?php endif; ?></td></tr>
<?php endforeach; ?>
</table></div>
<?php echo pager($total, $per, $page, 'withdrawals.php' . ($status ? '?status=' . $status : '')); ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
