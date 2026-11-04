<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_auth.php';

$m = portal_require();
$st = db()->prepare('SELECT * FROM deposits WHERE member_id=? ORDER BY deposit_date ASC, id ASC');
$st->execute(array($m['id'])); $rows = $st->fetchAll();
$running = 0.0; $ledger = array();
foreach ($rows as $r) { $running += floatval($r['amount']); $ledger[] = array('r' => $r, 'bal' => $running); }

$p_title = 'My Statement';
include __DIR__ . '/includes/portal_header.php';
?>
<div class="page-head noprint"><h1 style="margin:0;font:bold 21px Georgia,serif;color:#12476f">My Deposit Statement</h1>
<a class="btn plain" href="#" onclick="window.print();return false">🖨 Print statement</a></div>
<div class="twrap"><table class="data">
<tr><th>#</th><th>Date</th><th>Reference</th><th>Method</th><th class="num">Amount</th><th class="num">Running balance</th></tr>
<?php if (!$rows): ?><tr><td colspan="6" style="color:#5b7c99">No deposits on record yet.</td></tr><?php endif; ?>
<?php $i = 1; foreach (array_reverse($ledger) as $l): ?>
<tr><td><?php echo $i++; ?></td>
<td><?php echo e(date('d M Y', strtotime($l['r']['deposit_date']))); ?></td>
<td style="font-size:11.5px"><?php echo e($l['r']['reference']); ?></td>
<td><?php echo e($l['r']['payment_method']); ?></td>
<td class="num"><?php echo ugx($l['r']['amount']); ?></td>
<td class="num"><b><?php echo ugx($l['bal']); ?></b></td></tr>
<?php endforeach; ?>
</table></div>
<p style="color:#5b7c99;font-size:12px;margin-top:10px">Track hospital bill clearings on <a href="portal-settlements.php">My Bill Clearings</a> and your saving trends in <a href="portal-analytics.php">Analytics</a> &middot; <a href="portal.php">Deposit money (MoMo or cash) &raquo;</a></p>
<?php include __DIR__ . '/includes/portal_footer.php'; ?>
