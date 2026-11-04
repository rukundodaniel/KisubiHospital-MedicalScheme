<?php
/** v6.0 member-deposit settlement receipt — printable / downloadable (print dialog),
 *  re-printable for any past settlement, with an optional SMS resend. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_once __DIR__ . '/includes/portal_auth.php';
if (!mb_require_tbl()) { include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/footer.php'; exit; }
$id = intval(get('id'));
$st = db()->prepare("SELECT * FROM member_bills WHERE id=? AND status IN ('Settled','Reversed')"); $st->execute(array($id)); $b = $st->fetch();
if (!$b) { http_response_code(404); die('Settled bill not found.'); }
$portalMode = false;
if (!empty($_SESSION['m_uid']) && empty($_SESSION['uid'])) {   // member portal view: own receipts only
    $pm = portal_member();
    if (!$pm || intval($b['member_id']) !== intval($pm['id'])) { http_response_code(403); die('This receipt belongs to another member account.'); }
    $portalMode = true;
} else {
    require_login();
    fac_lock($b['facility_id']); // facility users: own receipts only
}
$f = fac_of($b['facility_id']);
$st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array(intval($b['member_id']))); $m = $st->fetch();
$items = db()->prepare('SELECT * FROM member_bill_items WHERE bill_id=? ORDER BY id'); $items->execute(array($id)); $items = $items->fetchAll();
$branchName = ''; if (intval($b['branch_id']) > 0) { try { $st = db()->prepare('SELECT name FROM institution_branches WHERE id=?'); $st->execute(array(intval($b['branch_id']))); $branchName = (string)$st->fetchColumn(); } catch (Exception $e) {} }
$txnRef = intval($b['withdrawals_id']) > 0 ? 'TXN-' . intval($b['withdrawals_id']) : '—';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('op') === 'resend' && !$portalMode) {
    csrf_check();
    mb_notify('member_settled', array('name' => $b['patient_name'], 'amount' => ugx($b['total']), 'inst' => $f['name'], 'ref' => $b['bill_no'], 'balance' => ugx((float)$b['balance_after'])),
        intval($b['facility_id']), intval($b['member_id']), 'member_bills', $id);
    flash('Settlement SMS re-queued to ' . ($m ? e($m['phone_no']) : 'the member') . '.', 'ok');
    redirect('receipt-member.php?id=' . $id);
}
$logo = (isset($f['logo']) && $f['logo'] !== '') ? 'fac-logo.php?id=' . intval($b['facility_id']) : '';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Receipt <?php echo e($b['receipt_no'] !== '' ? $b['receipt_no'] : $b['bill_no']); ?></title>
<style>
 body{font-family:Georgia,'Times New Roman',serif;background:#EAF2FA;margin:0;color:#1b3a57}
 .sheet{max-width:720px;margin:24px auto;background:#fff;border:1px solid #cdd;box-shadow:0 4px 18px rgba(20,50,80,.12);padding:30px 36px}
 h1{font-size:21px;margin:0 0 2px} .sub{color:#5c7d97;font-size:12px}
 table{width:100%;border-collapse:collapse;margin:10px 0;font-size:13px}
 th{text-align:left;background:#12476f;color:#fff;padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.4px}
 td{padding:6px 8px;border-bottom:1px solid #e4ecf3} .r{text-align:right}
 .tot{background:#EAF6EE;font-weight:bold} .bar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
 .stamp{border:2px solid #1e7f3c;color:#1e7f3c;font-weight:bold;padding:4px 12px;border-radius:6px;font-size:12px;transform:rotate(-3deg)}
 .stamp.rv{border-color:#a33;color:#a33}
 .grid{display:grid;grid-template-columns:1fr 1fr;gap:2px 22px;font-size:13px}
 .actions{max-width:720px;margin:0 auto 30px;display:flex;gap:8px}
 .actions a,.actions button{font:inherit;background:#1B6BB3;color:#fff;border:0;border-radius:5px;padding:8px 16px;text-decoration:none;cursor:pointer}
 .actions .p{background:#547086}
 @media print{body{background:#fff}.sheet{border:0;box-shadow:none;margin:0;max-width:none}.actions{display:none}}
</style></head><body>
<div class="sheet">
 <div class="bar">
  <div style="display:flex;gap:14px;align-items:center">
   <?php if ($logo !== ''): ?><img src="<?php echo e($logo); ?>" alt="logo" style="height:58px;width:auto;object-fit:contain"><?php endif; ?>
   <div><h1><?php echo e($f['name']); ?></h1>
    <div class="sub"><?php echo e($f['address'] !== '' ? $f['address'] : 'Kisubi, Wakiso'); ?><?php echo $branchName !== '' ? ' &middot; Branch: ' . e($branchName) : ''; ?><br>
    Tel <?php echo e($f['phone']); ?><?php echo $f['email'] !== '' ? ' &middot; ' . e($f['email']) : ''; ?></div></div>
  </div>
  <div style="text-align:right"><div style="font-weight:bold;font-size:15px">MEMBER DEPOSIT PAYMENT RECEIPT</div>
   <div class="sub">Receipt no. <b><?php echo e($b['receipt_no'] !== '' ? $b['receipt_no'] : $b['bill_no']); ?></b><br>
   <span class="stamp <?php echo $b['status']==='Reversed'?'rv':'' ?>"><?php echo $b['status']==='Reversed' ? 'REVERSED — CORRECTION POSTED' : 'PAID — MEMBER DEPOSIT ACCOUNT'; ?></span></div></div>
 </div>
 <div class="grid" style="margin-top:14px">
  <div><b>Member</b> &nbsp;<?php echo e($b['patient_name']); ?></div><div><b>Member number</b> &nbsp;<?php echo e($b['member_no']); ?></div>
  <div><b>Bill number</b> &nbsp;<?php echo e($b['bill_no']); ?></div><div><b>Settlement reference</b> &nbsp;<?php echo e($txnRef); ?></div>
  <div><b>Bill date</b> &nbsp;<?php echo e($b['bill_date']); ?></div><div><b>Date &amp; time paid</b> &nbsp;<?php echo e(substr((string)$b['settled_at'], 0, 16)); ?></div>
  <div><b>Processed by</b> &nbsp;<?php echo e($b['settled_by']); ?></div><div><b>Payment method</b> &nbsp;Member Deposit Account<?php echo $b['payer_type']==='DirectPayer' ? ' (payer: ' . e($b['payer_name'] ?: 'approved payer') . ')' : ''; ?></div>
 </div>
 <table><tr><th>Code</th><th>Item consumed</th><th>Category</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Total (UGX)</th></tr>
 <?php foreach ($items as $l): ?><tr><td><?php echo e($l['item_code']); ?></td><td><b><?php echo e($l['item_name']); ?></b></td><td><?php echo e($l['category']); ?></td>
  <td class="r"><?php echo e($l['qty']); ?></td><td class="r"><?php echo number_format((float)$l['unit_price'], 0); ?></td><td class="r"><?php echo number_format((float)$l['line_total'], 0); ?></td></tr><?php endforeach; ?>
 <tr><td colspan="5" class="r">Subtotal</td><td class="r"><?php echo number_format((float)$b['subtotal'], 0); ?></td></tr>
 <?php if ((float)$b['discount_percent'] > 0): ?><tr><td colspan="5" class="r">Authorized discount (<?php echo e($b['discount_percent']); ?>%)</td><td class="r">−<?php echo number_format((float)$b['subtotal'] - (float)$b['total'], 0); ?></td></tr><?php endif; ?>
 <tr class="tot"><td colspan="5" class="r">TOTAL AMOUNT / DEDUCTED FROM MEMBER</td><td class="r"><?php echo number_format((float)$b['total'], 0); ?></td></tr>
 </table>
 <div class="grid">
  <div>Previous member balance: <b><?php echo ugx((float)$b['balance_before']); ?></b></div>
  <div>Amount deducted: <b><?php echo ugx((float)$b['total']); ?></b></div>
  <div>Remaining member balance: <b style="color:#1e7f3c"><?php echo ugx((float)$b['balance_after']); ?></b></div>
  <div>Funding source: <b>Member's own deposit account</b> (verified before settlement)</div>
 </div>
 <?php if ($b['note'] !== ''): ?><p class="sub"><b>Note:</b> <?php echo e($b['note']); ?></p><?php endif; ?>
 <?php if (intval($b['claim_id']) > 0) { $cl61 = db()->prepare('SELECT claim_no, status FROM facility_claims WHERE id=?'); $cl61->execute(array(intval($b['claim_id']))); $cl61 = $cl61->fetch(); if ($cl61) echo '<p class="sub"><b>Central claim:</b> ' . e($cl61['claim_no']) . ' \u2014 sent to Kisubi Hospital automatically from this settlement (status: ' . e($cl61['status']) . ').</p>'; } ?>
 <?php if ($b['reverse_reason'] !== ''): ?><p class="sub" style="color:#a33"><b>Reversal:</b> <?php echo e($b['reverse_reason']); ?></p><?php endif; ?>
 <p class="sub" style="margin-top:16px">This receipt is system-generated from the audited member deposit ledger (transaction references above). Completed financial records are never deleted — corrections are posted as reversals by authorized officers. <?php echo e(constant('APP_TITLE')); ?></p>
</div>
<div class="actions">
 <a href="#" onclick="window.print();return false">&#128424; Print / Save as PDF</a>
 <a class="p" href="member-bills.php?view=list&facility=<?php echo intval($b['facility_id']); ?>">&larr; Reprint &amp; search receipts</a>
 <?php if ($portalMode): ?><a class="p" href="portal-bills.php">&larr; My bills</a><?php endif; ?>
 <?php if ($b['status'] === 'Settled'): ?>
 <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="resend"><button type="submit">&#128241; Re-send receipt SMS</button></form>
 <?php endif; ?>
</div>
</body></html>
