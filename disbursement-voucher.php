<?php
/** v4.1 Printable payment vouchers — one disbursement (id) or a whole batch statement (batch). */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/clinical-core.php';
require_role(array('Super Admin'));

$scheme = 'Kisubi Medical Savings Scheme';
$addr = 'Kisubi, Wakiso — Scheme Office';
try { $sc = db()->query("SELECT v FROM settings WHERE k='org_name'")->fetchColumn(); if ($sc) $scheme = (string)$sc; } catch (Exception $e) {}
try { $sa = db()->query("SELECT v FROM settings WHERE k='org_address'")->fetchColumn(); if ($sa) $addr = (string)$sa; } catch (Exception $e) {}

$ids = array(); $batch = null;
if (intval(get('batch')) > 0 && cl_tbl('disb_batches')) {
    $st = db()->prepare('SELECT * FROM disb_batches WHERE id=?'); $st->execute(array(intval(get('batch')))); $batch = $st->fetch();
    if (!$batch) die('Batch not found.');
    $st = db()->prepare('SELECT * FROM disbursements WHERE batch_id=? ORDER BY id'); $st->execute(array(intval($batch['id']))); $rows = $st->fetchAll();
} else {
    $id = intval(get('id')); if ($id <= 0) die('No payment specified.');
    $st = db()->prepare('SELECT * FROM disbursements WHERE id=?'); $st->execute(array($id)); $r = $st->fetch();
    if (!$r) die('Payment not found.');
    $rows = array($r);
}
$ext = cl_has('disbursements','voucher_no') ? ', d.voucher_no' : ''; $ext2 = cl_has('disbursements','reversed') ? ', d.reversed' : ''; $ext3 = cl_has('disbursements','batch_id') ? ', d.batch_id' : '';
$tot = 0.0; $lines = array();
foreach ($rows as $r) {
    $rev = isset($r['reversed']) && intval($r['reversed']) === 1;
    if (!$rev) $tot += (float)$r['amount'];
    $cno = $r['claim_no'];
    $stC = db()->prepare('SELECT id, invoice_no, invoice_date FROM facility_claims WHERE claim_no=?'); $stC->execute(array($cno)); $cl = $stC->fetch();
    $lines[] = array('d' => $r, 'rev' => $rev, 'claim_row' => $cl);
}
$pdMap = array(); try { foreach ($rows as $rP) { $qP = db()->prepare('SELECT * FROM disb_paydetails WHERE disbursement_id=?'); $qP->execute(array(intval($rP['id']))); $rrP = $qP->fetch(); if ($rrP) $pdMap[intval($rP['id'])] = $rrP; } } catch (Exception $e) {}
function amt_words($n) {
    $units = array('', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen');
    $tens = array('', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety');
    $n = (int)$n;
    if ($n === 0) return 'zero';
    $s = ''; $th = array('', ' thousand', ' million', ' billion'); $i = 0;
    while ($n > 0 && $i < 4) { $p = $n % 1000; if ($p) { $t = ''; $t .= $units[intdiv($p, 100)] !== '' ? $units[intdiv($p, 100)] . ' hundred ' : ''; $r = $p % 100;
            if ($r < 20) $t .= $units[$r]; else { $t .= $tens[intdiv($r, 10)]; if ($r % 10) $t .= '-' . $units[$r % 10]; }
            $s = trim($t) . $th[$i] . ($s !== '' ? ' ' . $s : ''); } $n = intdiv($n, 1000); $i++; }
    return trim($s);
}
$page_title = $batch ? 'Batch statement' : 'Payment voucher';
include __DIR__ . '/includes/header.php';
?>
<style>
.voucher{background:#fff;border:2px solid #1B6BB3;border-radius:6px;padding:22px 26px;max-width:820px;margin:0 auto 18px;position:relative}
.voucher .vhead{display:flex;justify-content:space-between;border-bottom:2px solid #1B6BB3;padding-bottom:10px;margin-bottom:14px}
.voucher h2{margin:0;font-size:19px;color:#12476f}
.vtable td,.vtable th{padding:7px 9px;border-bottom:1px solid #dce8f3;font-size:13px}
.stamp{position:absolute;right:26px;top:70px;transform:rotate(8deg);border:3px solid #20603a;color:#20603a;font:bold 15px Arial;padding:5px 14px;border-radius:6px;opacity:.85}
.stamp.rev{border-color:#8c2424;color:#8c2424}
@media print{.noprint{display:none!important}body{background:#fff}.voucher{border-color:#000}}
</style>
<div class="noprint" style="max-width:820px;margin:0 auto 10px;display:flex;justify-content:space-between;align-items:center">
  <a class="btn" href="<?php echo $batch ? 'disbursements-manager.php' : 'claim-view.php?id=' . rawurlencode($lines[0]['claim_row']['id'] ?? ''); ?>">&#8592; Back</a>
  <button class="btn primary" onclick="window.print()">&#128424; Print voucher</button>
</div>
<div class="voucher">
  <div class="vhead">
    <div><h2><?php echo e($scheme); ?></h2><div style="font-size:11.5px;color:#5b7c99"><?php echo e($addr); ?></div></div>
    <div style="text-align:right"><b style="color:#8a1c1c;font-size:16px"><?php echo $batch ? 'PAYMENT BATCH STATEMENT' : 'PAYMENT VOUCHER'; ?></b><br>
      <span style="font-size:12px"><?php echo $batch ? e($batch['title']) . ' · #' . e($batch['id']) : 'No. ' . e(isset($lines[0]['d']['voucher_no']) && $lines[0]['d']['voucher_no'] !== '' ? $lines[0]['d']['voucher_no'] : '#' . $lines[0]['d']['id']); ?></span><br>
      <span style="font-size:12px;color:#5b7c99">Date: <?php echo e(date('d M Y', strtotime($batch ? $batch['batch_date'] : $lines[0]['d']['paid_at']))); ?></span></div>
  </div>
  <table style="width:100%;font-size:13px;margin-bottom:12px"><tr>
    <td style="width:50%"><b>Paid to:</b> <?php echo e($lines[0]['d']['facility_name']); ?><br>
      <span style="color:#5b7c99">Facility code: <?php $f0 = fac_of(intval($lines[0]['d']['facility_id'])); echo e($f0 ? $f0['code'] : '—'); ?><?php echo $f0 && intval($f0['parent_id']) > 0 ? ' · branch of ' . e(fac_name(intval($f0['parent_id']))) : ''; ?></span></td>
    <td><b>Payment method:</b> <?php echo e($batch ? $batch['method'] : $lines[0]['d']['method']); ?><br>
      <span style="color:#5b7c99">Reference: <?php echo e($batch && $batch['reference'] !== '' ? $batch['reference'] : $lines[0]['d']['reference']); ?></span></td></tr></table>
  <table class="vtable" style="width:100%;border-collapse:collapse">
   <tr style="background:#EAF3FB;text-align:left"><th>#</th><th>Claim</th><th>Patient / period</th><th class="num" style="text-align:right">Amount (UGX)</th><th>Method · ref</th><th>Invoice</th></tr>
   <?php $i = 0; foreach ($lines as $ln): $i++; $d = $ln['d']; $cl = $ln['claim_row'];
       $stP = db()->prepare('SELECT patient_name, period_label FROM facility_claims WHERE id=?'); $stP->execute(array(intval($d['claim_id']))); $ci = $stP->fetch(); ?>
   <tr <?php echo $ln['rev'] ? 'style="text-decoration:line-through;opacity:.5"' : ''; ?>>
     <td><?php echo $i; ?></td><td><b><?php echo e($d['claim_no']); ?></b><?php echo $ln['rev'] ? ' <span style="color:#8c2424;font-size:10px">REVERSED</span>' : ''; ?></td>
     <td><?php echo e($ci && $ci['patient_name'] !== '' ? $ci['patient_name'] : 'Facility claim'); ?> <?php echo $ci && $ci['period_label'] !== '' ? '· ' . e($ci['period_label']) : ''; ?></td>
     <td class="num" style="text-align:right"><b><?php echo e(number_format((float)$d['amount'], 0)); ?></b></td>
     <td><?php echo e($d['method']); ?> · <?php echo e($d['reference']); ?></td>
     <td><?php echo $cl && $cl['invoice_no'] !== '' ? e($cl['invoice_no']) : '—'; ?></td></tr>
   <?php endforeach; ?>
   <tr style="background:#FBF7EF"><td colspan="3" style="text-align:right"><b>TOTAL PAYABLE<?php echo $batch ? ' (active lines)' : ''; ?></b></td>
     <td class="num" style="text-align:right"><b style="font-size:15px"><?php echo e(number_format($tot, 0)); ?></b></td><td colspan="2" style="font-size:11.5px;color:#5b7c99"><?php echo strtoupper(amt_words($tot)); ?> UGANDA SHILLINGS</td></tr>
  </table>
  <?php if ($batch && trim((string)$batch['note']) !== ''): ?><p style="font-size:12px;color:#5b7c99">Note: <?php echo e($batch['note']); ?></p><?php endif; ?>
  <?php if (!$batch && trim((string)$lines[0]['d']['note']) !== ''): ?><p style="font-size:12px;color:#5b7c99">Details: <?php echo e($lines[0]['d']['note']); ?></p><?php endif; ?>
  <?php $pd0 = isset($pdMap[intval($lines[0]['d']['id'])]) ? $pdMap[intval($lines[0]['d']['id'])] : null; if (!$batch && $pd0): ?>
  <table style="width:100%;font-size:12px;margin:4px 0 10px;border-top:1px dashed #bcd6ea;padding-top:6px"><tr>
   <?php $chans = array(); if ($pd0['bank_name'] !== '') $chans[] = '<b>Bank:</b> ' . e($pd0['bank_name']) . ' &middot; a/c ' . e($pd0['bank_account']); if ($pd0['mm_provider'] !== '' || $pd0['mm_phone'] !== '') $chans[] = '<b>Mobile money:</b> ' . e($pd0['mm_provider']) . ' ' . e($pd0['mm_phone']); if ($pd0['note'] !== '') $chans[] = '<b>Note:</b> ' . e($pd0['note']); if ($chans) echo '<td style="padding-right:18px">' . implode('<br>', $chans) . '</td>'; ?>
   <?php if ($pd0['rec_name'] !== ''): ?><td><b>Received by:</b> <?php echo e($pd0['rec_name']); ?><?php echo $pd0['rec_id'] !== '' ? ' &middot; ID ' . e($pd0['rec_id']) : ''; ?><br><span style="color:#5b7c99;font-size:11px">Acknowledged on receipt of cash &mdash; signature: ______________________</span></td><?php endif; ?>
   <?php if ($pd0['proof_stored'] !== ''): ?><td style="text-align:right"><a href="claims-disburse.php?proof=<?php echo intval($lines[0]['d']['id']); ?>" style="font-size:11.5px">&#128206; Proof of payment: <?php echo e($pd0['proof_name']); ?></a></td><?php endif; ?>
  </tr></table>
  <?php endif; ?>
  <div style="display:flex;justify-content:space-between;margin-top:34px;font-size:12px">
    <div style="border-top:1px solid #333;padding-top:4px;width:200px;text-align:center">Prepared by — <?php echo e($lines[0]['d']['paid_by']); ?></div>
    <div style="border-top:1px solid #333;padding-top:4px;width:200px;text-align:center">Authorised by — Scheme owner</div>
    <div style="border-top:1px solid #333;padding-top:4px;width:200px;text-align:center">Received by — <?php echo e($lines[0]['d']['facility_name']); ?></div>
  </div>
  <?php $allrev = true; foreach ($lines as $ln) if (!$ln['rev']) $allrev = false; ?>
  <div class="stamp <?php echo $allrev ? 'rev' : ''; ?>"><?php echo $allrev ? 'REVERSED' : 'PAID'; ?></div>
</div>
<p class="noprint" style="text-align:center;font-size:11px;color:#7a94aa">Generated by the scheme platform on <?php echo date('d M Y H:i'); ?> — vouchers are sequential and audit-linked.</p>
<?php include __DIR__ . '/includes/footer.php'; ?>
