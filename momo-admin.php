<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/momo.php';
require_role(array('Super Admin', 'Administrator', 'Finance'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

if (!momo_tables_ready()) { flash('MoMo tables not found — run migrate.php once.', 'err'); redirect('dashboard.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'recheck') {
        $id = intval(post('id'));
        $st = db()->prepare('SELECT id FROM momo_transactions WHERE id=?'); $st->execute(array($id));
        if ($st->fetch()) { $t = momo_finalize($id); flash('Payment ' . $t['external_id'] . ' is now ' . $t['status'] . '.'); }
        redirect('momo-admin.php');
    }
    if ($op === 'expire') {
        db()->prepare("UPDATE momo_transactions SET status='Failed', reason='Manually expired by staff.', updated_at=? WHERE id=? AND status='Pending'")
          ->execute(array(date('Y-m-d H:i:s'), intval(post('id'))));
        log_action('Expired MoMo request #' . intval(post('id')), 'momo_transactions', intval(post('id')));
        flash('Pending request marked as not completed.', 'info'); redirect('momo-admin.php');
    }
}

$f = get('status');
$where = '1=1'; $args = array();
if (in_array($f, array('Pending','Success','Failed'), true)) { $where = 't.status=?'; $args = array($f); }
$st = db()->prepare("SELECT t.*, m.member_no, m.full_names FROM momo_transactions t JOIN members m ON m.id=t.member_id WHERE $where ORDER BY t.id DESC LIMIT 200");
$st->execute($args); $rows = $st->fetchAll();

$todayFrom = date('Y-m-d') . ' 00:00:00';
$k = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a FROM momo_transactions WHERE status='Success' AND updated_at >= ?");
$k->execute(array($todayFrom)); $today = $k->fetch();
$kp = db()->query("SELECT COUNT(*) FROM momo_transactions WHERE status='Pending'")->fetchColumn();
$kt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM momo_transactions WHERE status='Success'");
$kt->execute(array()); $totSucceeded = (float)$kt->fetchColumn();

$pg_title = 'MoMo Payments'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head">
  <div><h1>MTN MoMo Payment Centre</h1>
  <span class="sub">Mode: <b><?php echo e(strtoupper(setting('momo_mode', 'demo'))); ?></b> &middot; Endpoint: <?php echo e(setting('momo_base_url', 'https://sandbox.momodeveloper.mtn.com')); ?></span></div>
  <div class="noprint"><a class="btn plain" href="export.php?table=momo">Export CSV</a>
  <a class="btn primary" href="settings.php#momo-settings">Gateway settings</a></div>
</div>

<div class="grid grid-4" style="margin-bottom:14px">
  <div class="kpi green"><div class="k-label">Credited today</div><div class="k-value"><?php echo ugx($today['a']); ?></div><div class="k-sub"><?php echo num($today['c']); ?> payments</div></div>
  <div class="kpi gold"><div class="k-label">Awaiting approval</div><div class="k-value"><?php echo num($kp); ?></div><div class="k-sub">pending MoMo prompts</div></div>
  <div class="kpi teal"><div class="k-label">MoMo deposits all-time</div><div class="k-value"><?php echo ugx($totSucceeded); ?></div><div class="k-sub">via member portal</div></div>
  <div class="kpi"><div class="k-label">Callbacks log</div><div class="k-value" style="font-size:13px">data/momo_callback.log</div><div class="k-sub">server filesystem</div></div>
</div>

<div class="panel"><h2>Transactions</h2>
<p class="noprint" style="font-size:12.5px;margin:0 0 10px">
  <a href="momo-admin.php">All</a> | <a href="momo-admin.php?status=Pending">Pending</a> |
  <a href="momo-admin.php?status=Success">Success</a> | <a href="momo-admin.php?status=Failed">Failed</a>
</p>
<div class="twrap" style="border:0"><table class="data">
<tr><th>Requested</th><th>Member</th><th>MTN No.</th><th>Reference</th><th class="num">Amount</th><th>Status</th><th>MoMo</th><th>Deposit</th><th class="noprint">Staff</th></tr>
<?php if (!$rows): ?><tr><td colspan="9" style="color:#5b7c99">No MoMo transactions yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $bc = $r['status']==='Success'?'green':($r['status']==='Failed'?'red':'amber'); ?>
<tr>
<td style="white-space:nowrap"><?php echo e(date('d M H:i', strtotime($r['created_at']))); ?></td>
<td><b><?php echo e($r['member_no']); ?></b><br><span style="font-size:11.5px"><?php echo e($r['full_names']); ?></span></td>
<td><?php echo e($r['phone']); ?></td>
<td style="font-size:11px"><b><?php echo e($r['external_id']); ?></b><br><?php echo e(mb_substr((string)$r['reference_id'],0,18)); ?></td>
<td class="num"><b><?php echo ugx($r['amount']); ?></b></td>
<td><span class="badge <?php echo $bc; ?>"><?php echo e($r['status']); ?></span><br><span style="font-size:10.5px;color:#7a94aa"><?php echo e(mb_substr((string)$r['reason'],0,50)); ?></span></td>
<td style="font-size:11px"><?php echo e($r['momo_status']); ?></td>
<td><?php echo $r['deposit_id'] ? '<a href="deposits.php">#' . e($r['deposit_id']) . '</a>' : '—'; ?></td>
<td class="noprint actions">
<form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="recheck"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
<button class="btn small plain" type="submit" title="Poll MTN for the latest status">Re-check</button></form>
<?php if ($r['status'] === 'Pending'): ?>
<form method="post" style="display:inline" onsubmit="return confirm('Mark this request as not completed?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="expire"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
<button class="btn small danger" type="submit">Expire</button></form>
<?php endif; ?>
</td></tr>
<?php endforeach; ?>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
