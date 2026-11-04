<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_auth.php';
require_once __DIR__ . '/includes/momo.php';

$m = portal_require();
if (!momo_tables_ready()) { flash('Mobile money is not configured yet on this system.', 'info'); redirect('portal.php'); }
$st = db()->prepare('SELECT * FROM momo_transactions WHERE member_id=? ORDER BY id DESC LIMIT 100');
$st->execute(array($m['id'])); $rows = $st->fetchAll();

$p_title = 'My Mobile Money Payments';
include __DIR__ . '/includes/portal_header.php';
?>
<div class="page-head"><h1 style="margin:0;font:bold 21px Georgia,serif;color:#12476f">Mobile Money Payments</h1>
<a class="btn success" href="portal.php">+ New deposit</a></div>
<div class="twrap"><table class="data">
<tr><th>When</th><th>Reference</th><th class="num">Amount</th><th>Status</th><th>Note</th><th></th></tr>
<?php if (!$rows): ?><tr><td colspan="6" style="color:#5b7c99">No payment requests yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $bc = $r['status']==='Success'?'green':($r['status']==='Failed'?'red':'amber'); ?>
<tr><td><?php echo e(date('d M Y H:i', strtotime($r['created_at']))); ?></td>
<td style="font-size:11.5px"><b><?php echo e($r['external_id']); ?></b></td>
<td class="num"><?php echo ugx($r['amount']); ?></td>
<td><span class="badge <?php echo $bc; ?>"><?php echo e($r['status']); ?></span></td>
<td style="font-size:12px;color:#5b7c99"><?php echo e(mb_substr((string)$r['reason'], 0, 70)); ?></td>
<td><a class="btn small plain" href="portal-payment.php?id=<?php echo e($r['id']); ?>">View</a></td></tr>
<?php endforeach; ?>
</table></div>
<?php include __DIR__ . '/includes/portal_footer.php'; ?>
