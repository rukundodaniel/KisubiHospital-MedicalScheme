<?php
/**
 * MEMBER DEPOSIT STATEMENT — twin of the VB ManageDepositsForm CreateDepositPDF:
 * navy A4 header, DATE | PREVIOUS | DEPOSIT | BALANCE running table, totals,
 * print-to-PDF layout, plus ✉ email-to-member (host mail(), no stored password)
 * and 📱 WhatsApp summary deep link.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$mid = intval(get('member_id'));
$st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($mid)); $m = $st->fetch();
if (!$m) { echo '<p style="font:bold 15px Arial;padding:28px">Member not found. <a href="deposits.php">Back to register</a></p>'; exit; }

$from = get('from'); $to = get('to');
if ($from === '' || $to === '') {
    $st = db()->prepare('SELECT MIN(deposit_date) a, MAX(deposit_date) b FROM deposits WHERE member_id=?');
    $st->execute(array($mid)); $r = $st->fetch();
    if ($from === '') $from = $r['a'] ? $r['a'] : date('Y-01-01');
    if ($to === '') $to = $r['b'] ? $r['b'] : today();
}

// statement rows with running balance — same as-of rules as the register (withdrawals/bills deducted)
$st = db()->prepare('SELECT * FROM deposits WHERE member_id=? AND deposit_date BETWEEN ? AND ? ORDER BY deposit_date, id');
$st->execute(array($mid, $from, $to)); $rows = $st->fetchAll();

// opening balance = everything before the period
$st = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM deposits WHERE member_id=? AND deposit_date < ?');
$st->execute(array($mid, $from)); $openDep = (float)$st->fetchColumn();
$st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE member_id=? AND status='Paid' AND withdraw_date < ?");
$st->execute(array($mid, $from)); $openWd = (float)$st->fetchColumn();
$st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM medical_bills WHERE member_id=? AND status='Paid' AND bill_date < ?");
$st->execute(array($mid, $from)); $openBl = (float)$st->fetchColumn();
$openBal = max(0.0, $openDep - $openWd - $openBl);
$totDep = 0.0; $lines = array();
foreach ($rows as $r) {
    $st = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM deposits WHERE member_id=? AND (deposit_date<? OR (deposit_date=? AND id<?))');
    $st->execute(array($mid, $r['deposit_date'], $r['deposit_date'], $r['id'])); $depB = (float)$st->fetchColumn();
    $st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE member_id=? AND status='Paid' AND withdraw_date<=?");
    $st->execute(array($mid, $r['deposit_date'])); $wdB = (float)$st->fetchColumn();
    $st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM medical_bills WHERE member_id=? AND status='Paid' AND bill_date<=?");
    $st->execute(array($mid, $r['deposit_date'])); $blB = (float)$st->fetchColumn();
    $prev = max(0.0, $depB - $wdB - $blB);
    $totDep += (float)$r['amount'];
    $lines[] = array('date' => $r['deposit_date'], 'prev' => $prev, 'dep' => (float)$r['amount'], 'bal' => $prev + (float)$r['amount'], 'id' => $r['id'], 'method' => $r['payment_method']);
}
$run = $lines ? (float)end($lines)['bal'] : $openBal;
$ext = false; try { db()->query('SELECT mtn_no FROM members LIMIT 1'); $ext = true; } catch (Exception $e) {}

// ---- ✉ EMAIL (server-side, host mail(); VB SMTPClient twin without storing a password) ----
$mailMsg = ''; $mailOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('op') === 'sendmail') {
    csrf_check();
    if (empty($m['email_address'])) { $mailMsg = 'This member has no email address on file.'; }
    else {
        $subject = 'Kisubi Hospital Health Savings — Deposit Statement ' . date('M Y') . ' (' . $m['member_no'] . ')';
        $h = '<div style="font-family:Georgia,serif;max-width:720px;margin:auto;border:1px solid #cfe0ee">'
           . '<div style="background:#19375A;color:#fff;padding:16px 22px"><b style="font-size:17px">KISUBI HOSPITAL — MEDICAL SAVING SCHEME</b>'
           . '<div style="font:11px Arial;letter-spacing:2px;color:#9fc3e8;margin-top:3px">MEMBER DEPOSIT STATEMENT &middot; ' . e(date('d M Y', strtotime($from))) . ' — ' . e(date('d M Y', strtotime($to))) . '</div></div>'
           . '<div style="padding:14px 22px;font:13px Georgia"><b>' . e($m['full_names']) . '</b> &middot; Member No. ' . e($m['member_no'])
           . '<table style="width:100%;border-collapse:collapse;margin-top:12px;font:12px Arial">'
           . '<tr style="background:#19375A;color:#fff"><th style="padding:6px;border:1px solid #9db8d2">DATE</th><th style="padding:6px;border:1px solid #9db8d2">PREVIOUS</th><th style="padding:6px;border:1px solid #9db8d2">DEPOSIT</th><th style="padding:6px;border:1px solid #9db8d2">BALANCE</th></tr>';
        foreach ($lines as $l) {
            $h .= '<tr><td style="padding:5px 6px;border:1px solid #cfe0ee">' . e($l['date']) . '</td>'
                . '<td style="padding:5px 6px;border:1px solid #cfe0ee;text-align:right">' . num($l['prev']) . '</td>'
                . '<td style="padding:5px 6px;border:1px solid #cfe0ee;text-align:right;color:#20603a;font-weight:bold">' . num($l['dep']) . '</td>'
                . '<td style="padding:5px 6px;border:1px solid #cfe0ee;text-align:right;font-weight:bold">' . num($l['bal']) . '</td></tr>';
        }
        $h .= '</table><p style="margin-top:12px"><b>TOTAL DEPOSITS (UGX): ' . num($totDep) . '</b> &middot; ' . count($lines) . ' transactions &middot; Closing balance: <b>' . num($run) . '</b></p>'
           . '<p style="font:11px Arial;color:#5b7c99">Generated ' . date('d M Y H:i') . ' &middot; All amounts in Uganda Shillings &middot; www.kisubihospital.co.ug</p></div></div>';
        $fromMail = 'healthsavings@kisubihospital.co.ug';
        $hdr = 'From: Kisubi Hospital Health Savings <' . $fromMail . ">\r\nReply-To: " . $fromMail . "\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8";
        $mailOk = @mail($m['email_address'], $subject, $h, $hdr);
        $mailMsg = $mailOk
            ? 'Statement emailed to ' . $m['email_address'] . '.'
            : 'The mail server refused to send. Print or save as PDF from this page instead.';
        if ($mailOk) log_action('Emailed deposit statement to ' . $m['member_no'], 'deposits', 0);
    }
}

// WhatsApp summary deep link (number normalised like FormatUgandaWhatsAppNumber)
$waPhone = $ext ? (string)$m['mtn_no'] : (string)$m['phone_no'];
$waDigits = preg_replace('/\D/', '', $waPhone);
if (strpos($waDigits, '00256') === 0) $waDigits = '256' . substr($waDigits, 5);
elseif (strlen($waDigits) === 10 && $waDigits[0] === '0') $waDigits = '256' . substr($waDigits, 1);
elseif (strlen($waDigits) === 9) $waDigits = '256' . $waDigits;
$waOk = (strlen($waDigits) === 12 && strpos($waDigits, '256') === 0);
$waText = rawurlencode("Kisubi Hospital Health Savings — Deposit Statement for " . $m['full_names'] . " (" . $m['member_no'] . ")\nPeriod: $from to $to\nDeposits: " . count($lines) . " totalling UGX " . num($totDep) . "\nClosing balance: UGX " . num($run) . "\nPlease print the statement at: " . (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) : '') . '/deposit-statement.php?member_id=' . $mid);
?><!doctype html>
<html><head><meta charset="utf-8"><title>Deposit Statement — <?php echo e($m['member_no']); ?> — MedSavu</title>
<link rel="stylesheet" href="assets/style.css">
<style>@media print{.noprint{display:none}body{background:#fff}.sheet{border:none!important;box-shadow:none!important;margin:0 auto!important}}
.sheet table td,.sheet table th{page-break-inside:avoid}</style></head>
<body style="background:#EAF3FA;margin:0;font-family:Georgia,serif">
<div class="noprint" style="text-align:right;padding:12px 18px;font-family:Arial">
  <?php if ($mailMsg !== ''): ?><span style="font:13px Arial;padding:4px 10px;border-radius:3px;margin-right:10px;background:<?php echo $mailOk?'#e2f4e6':'#fbe6e4'; ?>;color:<?php echo $mailOk?'#20603a':'#a03028'; ?>"><?php echo e($mailMsg); ?></span><?php endif; ?>
  <button class="btn primary" onclick="window.print()">🖨 Print / Save PDF</button>
  <?php if (!empty($m['email_address'])): ?>
  <form method="post" style="display:inline" onsubmit="return confirm('Email this statement to <?php echo e($m['email_address']); ?>?')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="sendmail">
  <button class="btn success" type="submit">&#9993; Email statement to member</button></form><?php endif; ?>
  <?php if ($waOk): ?><a class="btn plain" target="_blank" href="https://wa.me/<?php echo $waDigits; ?>?text=<?php echo $waText; ?>">📱 Send on WhatsApp</a><?php endif; ?>
  <a class="btn plain" href="deposits.php">&laquo; Register</a>
</div>

<div class="sheet" style="max-width:820px;margin:8px auto 34px;background:#fff;border:1px solid #cfe0ee;box-shadow:0 2px 10px rgba(18,71,111,.14)">
  <div style="background:#19375A;color:#fff;padding:18px 26px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div><b style="font-size:19px;letter-spacing:.5px">KISUBI HOSPITAL</b>
        <div style="font:bold 11px Arial;letter-spacing:3px;color:#9fc3e8">MEDICAL SAVING SCHEME &middot; HEALTH SAVINGS</div></div>
      <div style="text-align:right"><div style="font:bold 15px Georgia">MEMBER DEPOSIT STATEMENT</div>
        <div style="font:11px Arial;color:#cfe4f7"><?php echo e(date('d M Y', strtotime($from))); ?> — <?php echo e(date('d M Y', strtotime($to))); ?></div></div>
    </div>
  </div>
  <div style="padding:18px 26px">
    <table style="width:100%;font:13px Georgia;border-collapse:collapse;margin-bottom:12px">
      <tr><td style="color:#5b7c99;width:110px">Member</td><td><b><?php echo e($m['full_names']); ?></b></td>
          <td style="color:#5b7c99;width:110px">Member No.</td><td><b style="color:#12476F"><?php echo e($m['member_no']); ?></b></td></tr>
      <tr><td style="color:#5b7c99">Telephone</td><td><?php echo e($ext ? $m['airtel_no'] . ' / ' . $m['mtn_no'] : $m['phone_no']); ?></td>
          <td style="color:#5b7c99">Status</td><td><?php echo $m['status'] === 'Active' ? '<span style="color:#20603a;font-weight:bold">REGISTERED</span>' : '<span style="color:#a03028;font-weight:bold">NOT REGISTERED</span>'; ?></td></tr>
      <tr><td style="color:#5b7c99">Residence</td><td><?php echo e($m['address']); ?></td>
          <td style="color:#5b7c99">Email</td><td><?php echo e($m['email_address']); ?></td></tr>
    </table>
    <table style="width:100%;border-collapse:collapse;font:12.5px Arial">
      <tr style="background:#19375A;color:#fff">
        <th style="padding:7px 9px;border:1px solid #9db8d2;text-align:left;width:44px">#</th>
        <th style="padding:7px 9px;border:1px solid #9db8d2;text-align:left">DATE</th>
        <th style="padding:7px 9px;border:1px solid #9db8d2;text-align:right">PREVIOUS (UGX)</th>
        <th style="padding:7px 9px;border:1px solid #9db8d2;text-align:right">DEPOSIT (UGX)</th>
        <th style="padding:7px 9px;border:1px solid #9db8d2;text-align:right">BALANCE (UGX)</th>
        <th style="padding:7px 9px;border:1px solid #9db8d2;text-align:left">METHOD</th></tr>
      <tr style="background:#EAF2FA"><td style="padding:6px 9px;border:1px solid #cfe0ee" colspan="2"><i>Opening balance</i></td>
        <td style="padding:6px 9px;border:1px solid #cfe0ee;text-align:right">—</td>
        <td style="padding:6px 9px;border:1px solid #cfe0ee;text-align:right">—</td>
        <td style="padding:6px 9px;border:1px solid #cfe0ee;text-align:right;font-weight:bold"><?php echo num($openBal); ?></td>
        <td style="padding:6px 9px;border:1px solid #cfe0ee">—</td></tr>
      <?php $i = 0; foreach ($lines as $l): $i++; ?>
      <tr<?php echo $i % 2 ? ' style="background:#F7FBFE"' : ''; ?>>
        <td style="padding:6px 9px;border:1px solid #cfe0ee"><?php echo $i; ?></td>
        <td style="padding:6px 9px;border:1px solid #cfe0ee"><a href="receipt-deposit.php?id=<?php echo $l['id']; ?>" target="_blank" style="color:#12476F;text-decoration:none"><?php echo e($l['date']); ?></a></td>
        <td style="padding:6px 9px;border:1px solid #cfe0ee;text-align:right;color:#5b7c99"><?php echo num($l['prev']); ?></td>
        <td style="padding:6px 9px;border:1px solid #cfe0ee;text-align:right;color:#20603a;font-weight:bold"><?php echo num($l['dep']); ?></td>
        <td style="padding:6px 9px;border:1px solid #cfe0ee;text-align:right;font-weight:bold"><?php echo num($l['bal']); ?></td>
        <td style="padding:6px 9px;border:1px solid #cfe0ee"><?php echo e($l['method']); ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$lines): ?><tr><td colspan="6" style="padding:10px;border:1px solid #cfe0ee;color:#5b7c99">No deposits in this period.</td></tr><?php endif; ?>
      <tr style="background:#19375A;color:#fff;font-weight:bold">
        <td style="padding:8px 9px;border:1px solid #9db8d2" colspan="4">TOTAL DEPOSITS — <?php echo count($lines); ?> TRANSACTION(S)</td>
        <td style="padding:8px 9px;border:1px solid #9db8d2;text-align:right"><?php echo num($totDep); ?></td>
        <td style="padding:8px 9px;border:1px solid #9db8d2">Closing: <?php echo num($run); ?></td></tr>
    </table>
    <div style="display:flex;gap:12px;margin-top:14px;flex-wrap:wrap">
      <div style="flex:1;min-width:180px;border:1px solid #cfe0ee;padding:9px 12px;background:#F7FBFE;font:12px Arial"><b style="color:#12476F">PERIOD DEPOSITS</b><div style="font:bold 16px Georgia"><?php echo num($totDep); ?> UGX</div></div>
      <div style="flex:1;min-width:180px;border:1px solid #cfe0ee;padding:9px 12px;background:#F7FBFE;font:12px Arial"><b style="color:#12476F">CLOSING BALANCE</b><div style="font:bold 16px Georgia;color:#20603a"><?php echo num($run); ?> UGX</div></div>
      <div style="flex:1;min-width:180px;border:1px solid #cfe0ee;padding:9px 12px;background:#F7FBFE;font:12px Arial"><b style="color:#12476F">TRANSACTIONS</b><div style="font:bold 16px Georgia"><?php echo count($lines); ?></div></div>
    </div>
    <form method="get" class="noprint" style="margin-top:14px;font-family:Arial;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="member_id" value="<?php echo $mid; ?>">
      <div><label class="f">From</label><input class="f" type="date" name="from" value="<?php echo e($from); ?>"></div>
      <div><label class="f">To</label><input class="f" type="date" name="to" value="<?php echo e($to); ?>"></div>
      <button class="btn small primary" type="submit">Change period</button>
    </form>
  </div>
  <div style="border-top:2px solid #19375A;padding:10px 26px;font:11px Arial;color:#5b7c99;display:flex;justify-content:space-between;flex-wrap:wrap">
    <span>Generated by <?php echo e($_SESSION['uname']); ?> on <?php echo date('d M Y H:i'); ?> &middot; All amounts in Uganda Shillings.</span>
    <span>Kisubi Hospital Medical Saving Scheme &middot; www.kisubihospital.co.ug</span>
  </div>
</div>
</body></html>
