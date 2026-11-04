<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(array('Super Admin', 'Administrator', 'Finance'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$from = get('from', date('Y-m-01')); $to = get('to', date('Y-m-t'));
$prevEnd = date('Y-m-t', strtotime($from . ' -1 month')); $prevStart = date('Y-m-01', strtotime($from . ' -1 month'));

// ---- Income: deposits ----
$depTotal = sum_between('deposits', 'deposit_date', $from, $to);
$depPrev  = sum_between('deposits', 'deposit_date', $prevStart, $prevEnd);
// by method
$st = db()->prepare("SELECT payment_method, COALESCE(SUM(amount),0) a, COUNT(*) c FROM deposits WHERE deposit_date BETWEEN ? AND ? GROUP BY payment_method ORDER BY a DESC");
$st->execute(array($from, $to)); $byMethod = $st->fetchAll();

// ---- Expenditure ----
$wdPaid   = sum_between('withdrawals', 'withdraw_date', $from, $to, "status='Paid'");
$billPaid = sum_between('medical_bills', 'bill_date', $from, $to, "status='Paid'");
$outTotal = $wdPaid + $billPaid;
$outPrev  = sum_between('withdrawals', 'withdraw_date', $prevStart, $prevEnd, "status='Paid'")
          + sum_between('medical_bills', 'bill_date', $prevStart, $prevEnd, "status='Paid'");

// provider summary
$st = db()->prepare("SELECT provider, COALESCE(SUM(amount),0) a, COUNT(*) c FROM medical_bills WHERE bill_date BETWEEN ? AND ? AND status='Paid' GROUP BY provider ORDER BY a DESC LIMIT 12");
$st->execute(array($from, $to)); $byProvider = $st->fetchAll();

// liabilities (pending)
$pendW = db()->query("SELECT COALESCE(SUM(amount),0) a, COUNT(*) c FROM withdrawals WHERE status IN ('Pending','Approved')")->fetch();
$pendB = db()->query("SELECT COALESCE(SUM(amount),0) a, COUNT(*) c FROM medical_bills WHERE status='Pending'")->fetch();

// all-time
$allDep = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM deposits')->fetchColumn();
$allOut = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status='Paid'")->fetchColumn()
        + (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM medical_bills WHERE status='Paid'")->fetchColumn();

$net = $depTotal - $outTotal;
$growth = $depPrev > 0 ? round((($depTotal - $depPrev) / $depPrev) * 100, 1) : 0;

// member statement
$stm_id = intval(get('member'));
$ledger = array(); $runBal = 0; $stmMember = null;
$driver = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
if ($stm_id > 0) {
    $st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($stm_id)); $stmMember = $st->fetch();
    if ($driver === 'mysql') {
        $sql = "SELECT deposit_date AS dt, 'Deposit' AS item, reference AS ref, amount AS in_amt, 0 AS out_amt FROM deposits WHERE member_id=? AND deposit_date<=?
                UNION ALL SELECT withdraw_date, CONCAT('Withdrawal #', id), '', 0, amount FROM withdrawals WHERE member_id=? AND status='Paid' AND withdraw_date<=?
                UNION ALL SELECT bill_date, CONCAT('Bill: ', provider), description, 0, amount FROM medical_bills WHERE member_id=? AND status='Paid' AND bill_date<=?
                ORDER BY dt ASC";
    } else {
        $sql = "SELECT deposit_date AS dt, 'Deposit' AS item, reference AS ref, amount AS in_amt, 0 AS out_amt FROM deposits WHERE member_id=? AND deposit_date<=?
                UNION ALL SELECT withdraw_date, 'Withdrawal #' || id, '', 0, amount FROM withdrawals WHERE member_id=? AND status='Paid' AND withdraw_date<=?
                UNION ALL SELECT bill_date, 'Bill: ' || provider, description, 0, amount FROM medical_bills WHERE member_id=? AND status='Paid' AND bill_date<=?
                ORDER BY dt ASC";
    }
    $st = db()->prepare($sql);
    $asof = $to; $st->execute(array($stm_id, $asof, $stm_id, $asof, $stm_id, $asof));
    foreach ($st->fetchAll() as $r) { $runBal += floatval($r['in_amt']) - floatval($r['out_amt']); $r['balance'] = $runBal; $ledger[] = $r; }
}
$allMembers = db()->query('SELECT id, member_no, full_names FROM members ORDER BY full_names')->fetchAll();

$pg_title = 'Financial Report Centre'; include __DIR__ . '/includes/header.php'; ?>
<div class="page-head">
  <div><h1>💷 Financial Report Centre</h1>
  <span class="sub">Period: <b><?php echo e(date('d M Y', strtotime($from))); ?></b> to <b><?php echo e(date('d M Y', strtotime($to))); ?></b></span></div>
  <div class="noprint">
    <a class="btn plain" href="#" onclick="window.print();return false">🖨 Print report</a>
    <a class="btn plain" href="export.php?table=finance&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>">Export CSV</a>
  </div>
</div>

<div class="panel noprint"><form class="inline" method="get">
  <div><label class="f">From</label><input class="f" type="date" name="from" value="<?php echo e($from); ?>"></div>
  <div><label class="f">To</label><input class="f" type="date" name="to" value="<?php echo e($to); ?>"></div>
  <button class="btn primary" type="submit">Apply period</button>
  <span style="padding-bottom:9px;color:#5b7c99;font-size:12px">quick:</span>
  <a class="btn small plain" href="?from=<?php echo date('Y-m-01'); ?>&to=<?php echo date('Y-m-t'); ?>">This month</a>
  <a class="btn small plain" href="?from=<?php echo date('Y-m-01', strtotime('first day of last month')); ?>&to=<?php echo date('Y-m-t', strtotime('first day of last month')); ?>">Last month</a>
  <a class="btn small plain" href="?from=<?php echo date('Y-01-01'); ?>&to=<?php echo date('Y-m-t'); ?>">This year</a>
</form></div>

<div class="grid grid-4" style="margin-bottom:14px">
  <div class="kpi green"><div class="k-label">Income (deposits)</div><div class="k-value"><?php echo ugx($depTotal); ?></div><div class="k-sub"><?php echo $growth >= 0 ? '▲' : '▼'; ?> <?php echo abs($growth); ?>% vs previous period</div></div>
  <div class="kpi red"><div class="k-label">Expenditure (paid)</div><div class="k-value"><?php echo ugx($outTotal); ?></div><div class="k-sub">Withdrawals <?php echo ugx($wdPaid); ?> · Bills <?php echo ugx($billPaid); ?></div></div>
  <div class="kpi"><div class="k-label">Net Movement</div><div class="k-value" style="color:<?php echo $net<0?'#B23A3A':'#20603a'; ?>"><?php echo ugx($net); ?></div><div class="k-sub">Previous period net: <?php echo ugx($depPrev - $outPrev); ?></div></div>
  <div class="kpi teal"><div class="k-label">Scheme Balance (all-time)</div><div class="k-value"><?php echo ugx($allDep - $allOut); ?></div><div class="k-sub">In <?php echo ugx($allDep); ?> · Out <?php echo ugx($allOut); ?></div></div>
</div>

<div class="grid grid-2">
  <div class="panel"><h2>Income analysis — deposits by method</h2>
    <div class="twrap" style="border:0"><table class="data">
      <tr><th>Method</th><th class="num">Count</th><th class="num">Amount</th><th class="num">Share</th></tr>
      <?php if (!$byMethod): ?><tr><td colspan="4" style="color:#5b7c99">No deposits in this period.</td></tr><?php endif; ?>
      <?php foreach ($byMethod as $r): $share = $depTotal>0 ? round($r['a']/$depTotal*100,1) : 0; ?>
      <tr><td><?php echo e($r['payment_method']); ?></td><td class="num"><?php echo num($r['c']); ?></td>
      <td class="num"><?php echo ugx($r['a']); ?></td>
      <td class="num"><div style="background:#E3EFFA;border-radius:3px;height:12px;width:120px;display:inline-block;vertical-align:middle"><div style="background:#2E7D4F;height:12px;width:<?php echo $share; ?>%;border-radius:3px"></div></div> <?php echo $share; ?>%</td></tr>
      <?php endforeach; ?>
    </table></div>
    <div class="table-footnote">Mobile money &amp; cash collections reconciled against the cashier's books.</div>
  </div>

  <div class="panel"><h2>Expenditure analysis — medical providers paid</h2>
    <div class="twrap" style="border:0"><table class="data">
      <tr><th>Provider</th><th class="num">Bills</th><th class="num">Paid</th></tr>
      <?php if (!$byProvider): ?><tr><td colspan="3" style="color:#5b7c99">No bills paid in this period.</td></tr><?php endif; ?>
      <?php foreach ($byProvider as $r): ?><tr><td><?php echo e($r['provider']); ?></td><td class="num"><?php echo num($r['c']); ?></td><td class="num"><?php echo ugx($r['a']); ?></td></tr><?php endforeach; ?>
    </table></div>
    <div class="table-footnote">Scheme pays providers directly on approved bills.</div>
  </div>
</div>

<div class="panel"><h2>Outstanding liabilities (money committed, not yet paid)</h2>
<div class="grid grid-3">
  <div class="kpi gold"><div class="k-label">Pending / approved withdrawals</div><div class="k-value"><?php echo ugx($pendW['a']); ?></div><div class="k-sub"><?php echo num($pendW['c']); ?> claim(s)</div></div>
  <div class="kpi gold"><div class="k-label">Unpaid medical bills</div><div class="k-value"><?php echo ugx($pendB['a']); ?></div><div class="k-sub"><?php echo num($pendB['c']); ?> bill(s)</div></div>
  <div class="kpi red"><div class="k-label">Total committed</div><div class="k-value"><?php echo ugx($pendW['a'] + $pendB['a']); ?></div><div class="k-sub">Reserve coverage: <?php echo $allDep - $allOut > 0 ? round(($allDep-$allOut-$pendW['a']-$pendB['a']) / max(1,($pendW['a']+$pendB['a'])), 2) : 0; ?>x</div></div>
</div></div>

<div class="panel"><h2>Member statement &amp; ledger</h2>
<form class="inline noprint" method="get" style="margin-bottom:10px">
  <input type="hidden" name="from" value="<?php echo e($from); ?>"><input type="hidden" name="to" value="<?php echo e($to); ?>">
  <div style="min-width:320px"><label class="f">Select member</label><select class="f" name="member" onchange="this.form.submit()">
    <option value="0">-- choose member to view statement up to <?php echo e(date('d M Y', strtotime($to))); ?> --</option>
    <?php foreach ($allMembers as $mm): ?><option value="<?php echo e($mm['id']); ?>" <?php echo $stm_id==intval($mm['id'])?'selected':''; ?>><?php echo e($mm['member_no'].' - '.$mm['full_names']); ?></option><?php endforeach; ?>
  </select></div>
</form>
<?php if ($stm_id > 0 && $stmMember): ?>
  <p style="margin:4px 0 10px"><b><?php echo e($stmMember['full_names']); ?></b> (<?php echo e($stmMember['member_no']); ?>) &middot; phone <?php echo e($stmMember['phone_no']); ?> &middot; statement as at <?php echo e(date('d M Y', strtotime($to))); ?></p>
  <div class="twrap" style="border:0"><table class="data">
    <tr><th>Date</th><th>Item</th><th>Reference</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th></tr>
    <?php if (!$ledger): ?><tr><td colspan="6" style="color:#5b7c99">No transactions to date.</td></tr><?php endif; ?>
    <?php foreach ($ledger as $r): ?>
    <tr><td><?php echo e(date('d M Y', strtotime($r['dt']))); ?></td><td><?php echo e($r['item']); ?></td><td><?php echo e($r['ref']); ?></td>
    <td class="num" style="color:#20603a"><?php echo $r['in_amt']>0?ugx($r['in_amt']):''; ?></td>
    <td class="num" style="color:#8c2424"><?php echo $r['out_amt']>0?ugx($r['out_amt']):''; ?></td>
    <td class="num"><b><?php echo ugx($r['balance']); ?></b></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php if ($ledger): $last = end($ledger); ?>
  <div class="table-footnote">Closing balance: <b><?php echo ugx($last['balance']); ?></b> — statement generated by MedSavu, <?php echo date('d M Y'); ?></div>
  <?php endif; ?>
<?php elseif ($stm_id > 0): ?><p style="color:#8c2424">Member not found.</p><?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
