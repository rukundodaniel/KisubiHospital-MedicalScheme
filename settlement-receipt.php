<?php
/** v5.0 settlement receipt — print, download (print dialog) and SMS resend. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/wallet-core.php';
require_login();
if (!wa_require_tbl()) { include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/footer.php'; exit; }
$id = intval(get('id'));
$st = db()->prepare('SELECT * FROM bill_settlements WHERE id=?'); $st->execute(array($id)); $s = $st->fetch();
if (!$s) { http_response_code(404); die('Settlement not found.'); }
fac_lock($s['facility_id']); // institution users only ever see their own receipts
$items = db()->prepare('SELECT * FROM settlement_items WHERE settlement_id=? ORDER BY id'); $items->execute(array($id)); $items = $items->fetchAll();
$f = fac_of($s['facility_id']);
$st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array(intval($s['member_id']))); $m = $st->fetch();
$acc = wa_acct($s['facility_id']);
$claim = null; if (intval($s['claim_id']) > 0) { $st = db()->prepare('SELECT * FROM facility_claims WHERE id=?'); $st->execute(array(intval($s['claim_id']))); $claim = $st->fetch(); }
$branchName = ''; if (intval($s['branch_id']) > 0) { try { $st = db()->prepare('SELECT name FROM institution_branches WHERE id=?'); $st->execute(array(intval($s['branch_id']))); $branchName = (string)$st->fetchColumn(); } catch (Exception $e) {} }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('op') === 'resend') {
        sms50_notify('receipt_ready', array('settle_no' => $s['settle_no'], 'name' => $s['patient_name'], 'amount' => ugx($s['wallet_paid']), 'inst' => $f['name'], 'ref' => $s['settle_no']),
            intval($s['facility_id']), intval($s['member_id']), 'bill_settlements', $id);
        flash('Receipt SMS re-queued to ' . ($m ? e($m['phone_no']) : 'the beneficiary') . ' (delivery follows the SMS Centre mode).', 'ok');
        redirect('settlement-receipt.php?id=' . $id);
    }
    if (post('op') === 'reverse') {
        require_role(array('Super Admin'));
        $r = wa_settlement_reverse($id, post('reason'), $_SESSION['uname']);
        flash($r['msg'], $r['ok'] ? 'ok' : 'err'); redirect('settlement-receipt.php?id=' . $id);
    }
}
$page_title = 'Receipt ' . $s['settle_no'];
include __DIR__ . '/includes/header.php';
?>
<div class="noprint" style="display:flex;gap:8px;margin-bottom:10px">
 <a class="btn small plain" href="institution.php?facility=<?php echo intval($s['facility_id']); ?>">&laquo; Back to institution</a>
 <button class="btn small primary" type="button" onclick="window.print()">&#128424;&#65039; Print / download PDF</button>
 <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="resend"><button class="btn small gold" type="submit">&#128241; Re-send receipt SMS</button></form>
 <span style="flex:1"></span>
 <span style="font-size:12px;color:#5b7c99;align-self:center">Settlement <b><?php echo e($s['settle_no']); ?></b> &middot; <?php echo e($s['status']); ?><?php echo $claim ? ' &middot; claim <a href="claim-view.php?id=' . intval($claim['id']) . '">' . e($claim['claim_no']) . '</a> (' . e($claim['status']) . ')' : ''; ?></span>
 <?php if (can(array('Super Admin')) && (int)$s['reversed'] === 0 && $s['status'] !== 'Reversed'): ?>
 <details style="display:inline-block"><summary class="btn small plain" style="list-style:none">&#9888; Authorized reversal</summary>
  <form method="post" style="position:absolute;right:0;background:#fff;border:1px solid #bcd6ea;padding:10px;z-index:9;width:280px" onsubmit="return confirm('Reverse this settlement? The wallet is refunded and the linked claim (if unpaid) is voided.')"><?php echo csrf_field(); ?>
   <input type="hidden" name="op" value="reverse"><label class="f">Reason (required)</label><input class="f" name="reason" required maxlength="200"><button class="btn small danger" type="submit">Reverse</button></form>
 </details><?php endif; ?>
</div>

<div id="receipt" style="max-width:720px;margin:0 auto;background:#fff;border:2px solid #12476f;border-radius:8px;padding:26px 30px;font-family:Georgia,serif;color:#1b3a57">
 <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #12476f;padding-bottom:12px">
  <div><h2 style="margin:0;color:#12476f"><?php echo e(setting('scheme_name', 'Kisubi Hospital Medical Saving Scheme')); ?></h2>
   <div style="font-size:12px;color:#5b7c99">Institutional deposit settlement &mdash; official receipt</div></div>
  <div style="text-align:right"><b style="font-size:16px;color:#1B6BB3"><?php echo e($s['settle_no']); ?></b>
   <div style="font-size:12px;color:#5b7c99"><?php echo e($s['bill_date']); ?> &middot; settled <?php echo e(substr((string)$s['settled_at'], 0, 16)); ?></div>
   <div style="margin-top:4px"><b style="color:<?php echo $s['status'] === 'Reversed' ? '#8c2424' : '#20603a'; ?>"><?php echo strtoupper(e($s['status'])); ?></b></div></div>
 </div>
 <div style="display:flex;gap:26px;margin:14px 0;font-size:13px">
  <div style="flex:1"><b>INSTITUTION</b><br><?php echo e($f['name']); ?> (<?php echo e($f['code']); ?>)<?php echo $branchName !== '' ? '<br>Branch: ' . e($branchName) : ''; ?><br>Account: <?php echo e($acc['acct_no']); ?></div>
  <div style="flex:1"><b>BENEFICIARY</b><br><?php echo e($s['patient_name']); ?> (<?php echo e($s['member_no']); ?>)<?php echo $m && $m['inst_member_no'] !== '' ? '<br>Inst no: ' . e($m['inst_member_no']) : ''; ?>
   <?php if ($s['plan_name'] !== ''): ?><br>Cover: <?php echo e($s['plan_name']); ?><?php endif; ?></div>
 </div>
 <table class="data" style="margin:10px 0;width:100%"><tr><th>Service</th><th>Category</th><th style="text-align:right">Unit price</th><th style="text-align:right">Qty</th><th style="text-align:right">Amount</th><th>Funded by</th></tr>
 <?php foreach ($items as $it): ?><tr><td><?php echo e($it['item_name']); ?></td><td><?php echo e($it['category']); ?></td>
  <td style="text-align:right"><?php echo num($it['unit_price']); ?></td><td style="text-align:right"><?php echo num($it['qty']); ?></td>
  <td style="text-align:right"><?php echo num($it['line_total']); ?></td>
  <td><?php echo e($it['funded_by']); ?></td></tr><?php endforeach; ?>
 </table>
 <?php if ((float)$s['discount_percent'] > 0): ?><div style="font-size:12.5px;color:#5b7c99">Discount applied: <?php echo num($s['discount_percent']); ?>%</div><?php endif; ?>
 <table style="width:100%;margin-top:8px;font-size:14px;border-top:1px solid #bcd6ea">
  <tr><td style="padding-top:6px"><b>Total bill</b></td><td style="text-align:right;padding-top:6px"><b>UGX <?php echo num($s['total_bill']); ?></b></td></tr>
  <tr><td style="color:#20603a">Paid from institutional deposit float (retired desk)</td><td style="text-align:right;color:#20603a"><?php echo num($s['wallet_paid']); ?></td></tr>
  <tr><td style="color:#155e9e">Covered by insurance (claim submitted)</td><td style="text-align:right;color:#155e9e"><?php echo num($s['insurance_claimed']); ?></td></tr>
  <tr><td style="color:#8a6410">Beneficiary co-pay / outstanding</td><td style="text-align:right;color:#8a6410"><?php echo num($s['member_copay']); ?></td></tr>
  <tr><td style="border-top:1px solid #12476f;padding-top:4px"><b>Institutional float after settlement</b></td><td style="border-top:1px solid #12476f;padding-top:4px;text-align:right"><b><?php echo ugx($acc['balance']); ?></b></td></tr>
 </table>
 <div style="margin-top:14px;font-size:11px;color:#5b7c99;border-top:1px dashed #bcd6ea;padding-top:8px">
  Settled by <?php echo e($s['settled_by']); ?> &middot; funding mix: <?php echo e($s['funding']); ?> &middot; ledger refs: float debit (IAC <?php echo e($acc['acct_no']); ?>)<?php echo $claim ? ' + claim ' . e($claim['claim_no']) . ' accrual' : ''; ?>.
  Deposited funds settle instantly; insurance portion is payable after central adjudication. This receipt is a record &mdash; corrections are made by authorized reversal entries only.
  <?php if ($s['reversed']): ?><br><b style="color:#8c2424">REVERSED: <?php echo e($s['reverse_reason']); ?></b><?php endif; ?>
 </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
