<?php
/** v6.0 DISBURSEMENT & CLAIMS REPORTS — facility claim statements, approval / rejection
 *  registers, monthly disbursement schedules, outstanding / partial / fully paid ledgers.
 *  Every report: print button + CSV download, period-filtered, read-only (no data mutation). */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/acc.php';
require_once __DIR__ . '/includes/member-billing-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance'));
if (function_exists('fac_central_guard')) fac_central_guard(); // v7.0 §9

$from = trim((string)get('from')); $to = trim((string)get('to'));
$W = ''; $A = array();
$rep = (string)get('rep', 'statement');
$REPS = array('statement' => 'Facility claim statements', 'approvals' => 'Claims approval register', 'rejections' => 'Claims rejection register',
    'schedule' => 'Monthly disbursement schedule', 'outstanding' => 'Outstanding claims', 'partial' => 'Partially paid claims', 'full' => 'Fully paid claims', 'memberbills' => 'Member bill register');

/** build a period-filtered claim/date query per report; returns array(title, cols, sql, args) */
function cr_query($rep, $from, $to) {
    $w = ''; $a = array();
    if ($from !== '' && $to !== '') { $w = ' AND SUBSTR(%s,1,10) BETWEEN ? AND ?'; $a = array($from, $to); }
    switch ($rep) {
        case 'approvals':
            $sql = "SELECT claim_no, facility_name, period_label, amount_billed, COALESCE(amount_approved, amount_billed) appr, status, decided_by, SUBSTR(COALESCE(decided_at, created_at),1,10) d FROM facility_claims WHERE status IN ('Approved','Partially Approved','Cleared','Scheduled','PartPaid','Paid')" . sprintf($w, "COALESCE(decided_at, created_at)") . ' ORDER BY d DESC LIMIT 500';
            return array('Claims approval register', array('Claim', 'Facility', 'Period', 'Billed', 'Approved', 'State', 'Decided by', 'Decision date'), $sql, $a);
        case 'rejections':
            $sql = "SELECT claim_no, facility_name, amount_billed, status, decided_by, reject_reason, SUBSTR(COALESCE(decided_at, created_at),1,10) d FROM facility_claims WHERE status IN ('Rejected','Returned')" . sprintf($w, "COALESCE(decided_at, created_at)") . ' ORDER BY d DESC LIMIT 500';
            return array('Claims rejection / return register', array('Claim', 'Facility', 'Billed', 'Outcome', 'By', 'Reason on record', 'Date'), $sql, $a);
        case 'schedule':
            $sql = "SELECT s.sched_no, SUBSTR(s.sched_date,1,10) d, f.name fac, s.claim_no, s.amount, s.method, s.reference, s.status, s.voucher_no, s.paid_by FROM disb_schedules s JOIN facilities f ON f.id=s.facility_id WHERE 1=1" . sprintf($w, 's.sched_date') . ' ORDER BY s.sched_date, s.id LIMIT 800';
            return array('Disbursement schedule (monthly view)', array('Schedule', 'Date', 'Facility', 'Claim', 'Amount', 'Method', 'Reference', 'Status', 'Voucher', 'Released by'), $sql, $a);
        case 'outstanding':
            $sql = "SELECT claim_no, facility_name, period_label, amount_billed, COALESCE(amount_approved, amount_billed) appr, amount_paid, status, submitted_at FROM facility_claims WHERE status IN ('Approved','Partially Approved','Cleared','Scheduled','PartPaid')" . sprintf($w, 'submitted_at') . ' ORDER BY appr DESC LIMIT 500';
            return array('Outstanding claim liabilities (approved − paid)', array('Claim', 'Facility', 'Period', 'Billed', 'Approved', 'Paid', 'State', 'Submitted'), $sql, $a);
        case 'partial':
            $sql = "SELECT claim_no, facility_name, amount_billed, amount_approved, amount_paid, pay_method, pay_ref FROM facility_claims WHERE status='PartPaid'" . sprintf($w, 'submitted_at') . ' ORDER BY id DESC LIMIT 500';
            return array('Partially paid claims', array('Claim', 'Facility', 'Billed', 'Approved', 'Paid so far', 'Last method', 'Last reference'), $sql, $a);
        case 'full':
            $sql = "SELECT claim_no, facility_name, amount_approved, amount_paid, SUBSTR(paid_at,1,10) paid_at, pay_method, pay_ref, paid_by FROM facility_claims WHERE status='Paid'" . sprintf($w, 'paid_at') . ' ORDER BY paid_at DESC LIMIT 500';
            return array('Fully paid claims', array('Claim', 'Facility', 'Approved', 'Total paid', 'Final payment date', 'Method', 'Reference', 'Released by'), $sql, $a);
        case 'memberbills':
            $sql = "SELECT b.bill_no, b.bill_date, f.name fac, b.member_no, b.patient_name, b.subtotal, b.total, b.status, b.settled_by, b.receipt_no FROM member_bills b JOIN facilities f ON f.id=b.facility_id WHERE 1=1" . sprintf($w, 'b.bill_date') . ' ORDER BY b.id DESC LIMIT 800';
            return array('Member bill register (all facilities)', array('Bill', 'Date', 'Facility', 'Member no', 'Member', 'Subtotal', 'Total', 'Status', 'Settled by', 'Receipt'), $sql, $a);
        default: // facility statements
            $sql = "SELECT f.name, f.code, COUNT(c.id) claims, COALESCE(SUM(c.amount_billed),0) billed, COALESCE(SUM(CASE WHEN c.status IN ('Approved','Partially Approved','Cleared','Scheduled','PartPaid','Paid') THEN COALESCE(c.amount_approved,c.amount_billed) ELSE 0 END),0) appr FROM facilities f LEFT JOIN facility_claims c ON c.facility_id=f.id WHERE c.id IS NOT NULL" . sprintf($w, 'c.submitted_at') . ' GROUP BY f.id ORDER BY appr DESC';
            return array('Facility claim statements', array('Facility', 'Code', 'Claims', 'Billed', 'Approved', 'Disbursed', 'Outstanding'), $sql, $a);
    }
}

$title = ''; $cols = array(); $rows = array();
if (isset($REPS[$rep])) {
    list($title, $cols, $sql, $aR) = cr_query($rep, $from, $to);
    $st = db()->prepare($sql); $st->execute($aR); $rows = $st->fetchAll();
    if ($rep === 'statement') { // enrich per facility: paid & outstanding
        $enr = array();
        foreach (db()->query('SELECT id FROM facilities')->fetchAll(PDO::FETCH_COLUMN) as $fid) {
            $paid = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM disbursements WHERE facility_id=' . intval($fid) . (cl_has('disbursements', 'reversed') ? ' AND reversed=0' : ''))->fetchColumn();
            $enr[intval($fid)] = $paid;
        }
        $facById = array(); foreach (fac_list() as $ff) $facById[(string)$ff['name']] = intval($ff['id']);
        foreach ($rows as $i => $r) { $paid = isset($facById[$r['name']]) ? $enr[$facById[$r['name']]] : 0.0; $rows[$i]['paid'] = $paid; $rows[$i]['owing'] = max(0, (float)$r['appr'] - $paid); }
    }
}

/* ---- CSV export (same filters, read-only) ---- */
if (get('csv') === '1' && $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $rep . '-report-' . date('Ymd-His') . '.csv"');
    $o = fopen('php://output', 'w'); fputcsv($o, array($title, $from !== '' ? 'from ' . $from . ' to ' . $to : 'all time')); fputcsv($o, $cols);
    foreach ($rows as $r) fputcsv($o, array_values(array_map(function ($v) { return is_numeric($v) ? round((float)$v, 2) : (string)$v; }, $r)));
    fclose($o); exit;
}

$page_title = 'Claims & Disbursement Reports';
include __DIR__ . '/includes/header.php';
$totR = 0.0; $totP = 0.0; $totO = 0.0;
foreach ($rows as $r) { if (isset($r['billed'])) $totR += (float)$r['billed']; if (isset($r['paid'])) $totP += (float)$r['paid']; if (isset($r['owing'])) $totO += (float)$r['owing']; }
?>
<div class="page-head"><h1>&#128196; CLAIMS &amp; DISBURSEMENT REPORTS</h1>
 <span class="sub">Statements, registers and schedules — printable, exportable, and always consistent with the underlying ledgers.</span>
 <span style="float:right;display:flex;gap:8px"><a class="btn small" href="claims-central.php">Central claims &#8599;</a><a class="btn small plain" href="reconcile.php">Financial reconciliation &#8599;</a></span>
</div>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0">
<?php foreach ($REPS as $k => $lbl): ?><a class="btn small <?php echo $rep === $k ? 'primary' : 'plain'; ?>" href="claims-reports.php?rep=<?php echo $k; ?>&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>"><?php echo e($lbl); ?></a><?php endforeach; ?>
</div>
<div class="panel"><h2 style="margin-top:0"><?php echo e($title); ?>
 <span style="float:right;display:flex;gap:6px"><button class="btn small plain" type="button" onclick="window.print()">&#128424; Print</button>
 <a class="btn small plain" href="claims-reports.php?rep=<?php echo e($rep); ?>&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>&csv=1">&#8615; CSV / Excel</a></span></h2>
 <form method="get" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:8px"><input type="hidden" name="rep" value="<?php echo e($rep); ?>">
  <div><label class="f">From</label><input class="f" type="date" name="from" value="<?php echo e($from); ?>" style="margin:0"></div>
  <div><label class="f">To</label><input class="f" type="date" name="to" value="<?php echo e($to); ?>" style="margin:0"></div>
  <button class="btn small" type="submit">Filter period</button>
  <?php if ($from !== '' || $to !== ''): ?><a class="btn small plain" href="claims-reports.php?rep=<?php echo e($rep); ?>">Clear</a><?php endif; ?></form>
 <table class="tbl"><tr><?php foreach ($cols as $c): if (in_array($c, array('Billed', 'Approved', 'Disbursed', 'Outstanding', 'Amount', 'Paid', 'Paid so far', 'Total paid', 'Subtotal', 'Total', 'Claims'), true)) echo '<th style="text-align:right">' . e($c) . '</th>'; else echo '<th>' . e($c) . '</th>'; endforeach; ?></tr>
 <?php if (!$rows) echo '<tr><td colspan="' . count($cols) . '" style="color:#7a94aa">No rows for this period/report.</td></tr>';
 foreach ($rows as $r): $vals = array_values($r); ?>
  <tr><?php foreach ($r as $k => $v): $isNum = in_array($k, array('claims', 'billed', 'appr', 'paid', 'owing', 'amount', 'amount_billed', 'amount_approved', 'amount_paid', 'subtotal', 'total'), true);
      $cell = $isNum ? num((float)$v) : e((string)($v === null ? '—' : $v));
      if ($k === 'status') $cell = e(cl_slabel((string)$v));
      echo $isNum ? '<td style="text-align:right">' . $cell . '</td>' : '<td>' . $cell . '</td>'; endforeach; ?>
   </tr>
 <?php endforeach; ?></table>
<?php if ($rep === 'statement' && $rows): ?><p class="sub">Period totals — billed <?php echo ugx($totR); ?> &middot; disbursed <?php echo ugx($totP); ?> &middot; outstanding liability <?php echo ugx($totO); ?>. Reconcile against GL accounts 2100 / 2200 anytime on the <a href="reconcile.php">reconciliation desk</a>.</p><?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
