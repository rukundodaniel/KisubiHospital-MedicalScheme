<?php
/** Member portal — MY BILLS & RECEIPTS: facility bills raised against the member,
 *  their settlement status, printable receipts, consumed services, and (where the
 *  scheme allows it) the SMS notification history for this account. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_auth.php';
require_once __DIR__ . '/includes/member-billing-core.php';
$m = portal_require();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $r = mb_portal_authorize(intval(post('bid')), intval($m['id']), (string)post('pass'), post('act') === 'approve');
    flash(e($r['msg']), $r['ok'] ? 'ok' : 'err'); redirect('portal-bills.php');
}
$st = db()->prepare('SELECT b.*, f.name fac FROM member_bills b JOIN facilities f ON f.id=b.facility_id WHERE b.member_id=? ORDER BY b.id DESC LIMIT 200');
$st->execute(array(intval($m['id']))); $bills = $st->fetchAll();
$led = mb_ledger(intval($m['id']));
$cur = member_balance(intval($m['id']));
$showSms = trim((string)setting('portal_sms_history', '1')) === '1';
$sms = array();
if ($showSms) { try { $st = db()->prepare('SELECT created_at, message, status FROM sms_log WHERE member_id=? ORDER BY id DESC LIMIT 25'); $st->execute(array(intval($m['id']))); $sms = $st->fetchAll(); } catch (Exception $e) {} }
$p_self = 'portal-bills.php';
include __DIR__ . '/includes/portal_header.php';
?>
<h2>My bills &amp; receipts</h2>
<?php $await = array(); foreach ($bills as $bb) if ($bb['status'] === 'Pending' && (string)$bb['payer_type'] !== 'DirectPayer' && mb_auth_required(intval($bb['facility_id']))) $await[] = $bb; ?>
<?php if ($await): ?><div class="panel" style="border:2px solid #b8860b;background:#FFF9EC">
 <h2 style="margin-top:0">&#128274; Awaiting your authorization</h2>
 <p style="font-size:13px;margin:2px 0 8px">These facility bills are ready to be paid from your deposit. <b>Nothing is deducted until you approve.</b> Enter your portal password to confirm — exactly once, for that one bill.</p>
 <?php foreach ($await as $aw): ?>
 <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;border-top:1px solid #eadfa8">
  <input type="hidden" name="op" value="bill_auth"><input type="hidden" name="bid" value="<?php echo intval($aw['id']); ?>"><?php echo csrf_field(); ?>
  <div style="flex:1;min-width:200px"><b><?php echo e($aw['bill_no']); ?></b> — <?php echo e($aw['fac']); ?><br><span style="font-size:12px;color:#7a6a3a"><?php echo e($aw['bill_date']); ?> · <?php echo e((string)$aw['note'] !== '' ? $aw['note'] : 'facility bill'); ?></span></div>
  <div style="font:bold 15px Georgia"><?php echo ugx($aw['total']); ?></div>
  <input class="f" type="password" name="pass" placeholder="your portal password" style="margin:0;width:190px" required>
  <button class="btn" style="background:#1e7f3c;color:#fff;border:none" type="submit" name="act" value="approve" onclick="this.disabled=true;this.form.submit()">&#10004; Approve payment</button>
  <button class="btn plain" type="submit" name="act" value="decline" onclick="return confirm('Decline this payment? The facility will be told you did not authorize it.')">&#10006; Decline</button>
 </form>
 <?php endforeach; ?></div><?php endif; ?>
<div style="display:flex;gap:12px;flex-wrap:wrap;margin:10px 0 14px">
 <div style="flex:1;min-width:160px;background:#fff;border:1px solid #d9e4ee;border-radius:8px;padding:12px 14px"><div style="font-size:11px;color:#5b7c99;text-transform:uppercase">Current savings balance</div><div style="font:bold 20px Georgia;color:#12476f"><?php echo ugx($cur); ?></div></div>
 <div style="flex:1;min-width:160px;background:#fff;border:1px solid #d9e4ee;border-radius:8px;padding:12px 14px"><div style="font-size:11px;color:#5b7c99;text-transform:uppercase">Bills settled</div><div style="font:bold 20px Georgia;color:#1e7f3c"><?php echo num(count(array_filter($bills, function ($x) { return $x['status'] === 'Settled' || $x['status'] === 'Reversed'; }))); ?></div><div style="font-size:11px;color:#5b7c99">each with a printable receipt below</div></div>
</div>
<div class="twrap" style="border:0"><table class="data">
 <tr><th>Bill no</th><th>Facility</th><th>Date</th><th>Services</th><th style="text-align:right">Amount</th><th>Status</th><th></th></tr>
 <?php if (!$bills) echo '<tr><td colspan="7" style="color:#5b7c99">No facility bills on your account yet.</td></tr>';
 foreach ($bills as $r): $items = mb_lines(intval($r['id'])); ?>
 <tr><td><b><?php echo e($r['bill_no']); ?></b></td><td><?php echo e($r['fac']); ?></td><td><?php echo e($r['bill_date']); ?></td>
  <td style="font-size:12px"><?php $k = 0; foreach ($items as $l) { if ($k++ >= 3) { echo '…'; break; } echo e($l['item_name']) . ' ×' . e($l['qty']) . '<br>'; } ?></td>
  <td style="text-align:right"><?php echo ugx($r['total']); ?></td>
  <td><?php echo e($r['status']); ?></td>
  <td><?php if ($r['status'] === 'Settled' || $r['status'] === 'Reversed'): ?><a class="btn small" href="receipt-member.php?id=<?php echo intval($r['id']); ?>">&#128424; Receipt<?php echo $r['receipt_no'] !== '' ? ' ' . e($r['receipt_no']) : ''; ?></a><?php else: echo '<span style="font-size:11px;color:#8a6410">awaiting settlement</span>'; endif; ?></td></tr>
 <?php endforeach; ?></table></div>

<h2 style="margin-top:22px">Account transaction history</h2>
<div class="twrap" style="border:0"><table class="data">
 <tr><th>Date</th><th>Description</th><th style="text-align:right">In</th><th style="text-align:right">Out</th><th style="text-align:right">Balance</th></tr>
 <?php $rev = array_reverse($led); if (!$rev) echo '<tr><td colspan="5" style="color:#5b7c99">No transactions yet.</td></tr>';
 foreach ($rev as $r): if ((string)$r['status'] !== 'Paid' && (string)$r['status'] !== 'Posted') continue; ?>
 <tr><td><?php echo e($r['date']); ?></td><td><?php echo e($r['desc']); ?></td>
  <td style="text-align:right;color:#1e7f3c"><?php echo $r['credit'] > 0 ? '+' . num($r['credit']) : ''; ?></td>
  <td style="text-align:right;color:#a33"><?php echo $r['debit'] > 0 ? '−' . num($r['debit']) : ''; ?></td>
  <td style="text-align:right"><b><?php echo num($r['balance']); ?></b></td></tr>
 <?php endforeach; ?></table></div>
<p style="color:#5b7c99;font-size:12px;margin-top:8px">Full statement with pending items on <a href="portal-statement.php">My Statement</a>. Completed transactions are part of your legal record and can never be deleted — corrections appear as reversals.</p>

<?php if ($showSms && $sms): ?>
<h2 style="margin-top:22px">SMS notification history</h2>
<div class="twrap" style="border:0"><table class="data">
 <tr><th>When</th><th>Message</th><th>Status</th></tr>
 <?php foreach ($sms as $s): ?><tr><td style="white-space:nowrap"><?php echo e(substr((string)$s['created_at'], 0, 16)); ?></td><td style="font-size:12px"><?php echo e($s['message']); ?></td><td><?php echo e((string)$s['status']); ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/portal_footer.php'; ?>
