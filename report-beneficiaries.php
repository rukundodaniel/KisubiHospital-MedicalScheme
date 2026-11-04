<?php
/**
 * BENEFICIARY REPORT — web equivalent of the VB Crystal report Beneficially.rpt.
 * Same band layout: PageHeader / GroupHeader (per member) / Details / GroupFooter
 * / ReportFooter, plus Print + Export-to-PDF via the browser print dialog.
 * Optional filter: ?member_id=N
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$has_ext = false;
try { db()->query('SELECT residence FROM beneficiaries LIMIT 1'); $has_ext = true; } catch (Exception $e) {}

$fmid = intval(get('member_id'));
$where = '1=1'; $args = array();
if ($fmid > 0) { $where .= ' AND b.member_id=?'; $args[] = $fmid; }
$sel = 'b.id, b.member_id, b.full_names, b.relationship, b.national_id, b.phone_no'
     . ($has_ext ? ', b.residence, b.gender, b.age, (b.photo IS NOT NULL) AS has_photo' : '');
$rows = array();
try {
    $st = db()->prepare("SELECT $sel, m.member_no, m.full_names AS member_name, m.lc1_village, m.district, m.status AS member_status
                         FROM beneficiaries b JOIN members m ON m.id = b.member_id
                         WHERE $where ORDER BY m.full_names, b.id");
    $st->execute($args); $rows = $st->fetchAll();
} catch (Exception $ex) {
    // pre-migration schema (either table can lack columns)
    $has_ext = false;
    $st = db()->prepare("SELECT b.id, b.member_id, b.full_names, b.relationship, b.national_id, b.phone_no, m.member_no, m.full_names AS member_name, m.status AS member_status
                         FROM beneficiaries b JOIN members m ON m.id = b.member_id
                         WHERE $where ORDER BY m.full_names, b.id");
    $st->execute($args); $rows = $st->fetchAll();
}

$byMember = array();
foreach ($rows as $r) { $byMember[$r['member_id']][] = $r; }
$totalBen = count($rows); $totalMem = count($byMember);
$printed = date('l, d F Y H:i');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<title>Beneficiary Report — MedSavu</title>
<style>
 body{margin:0;background:#e9eef3;font:13px/1.45 Georgia,'Times New Roman',serif;color:#15304a}
 .bar{position:sticky;top:0;background:#12476f;color:#fff;padding:10px 18px;display:flex;gap:10px;align-items:center;font:bold 13px "Segoe UI",Arial}
 .bar a{color:#fff;background:#1B6BB3;border:0;padding:8px 16px;border-radius:3px;text-decoration:none;cursor:pointer;font:bold 13px "Segoe UI",Arial}
 .bar .x{margin-left:auto;color:#bcd8ef;font-weight:normal}
 .page{width:800px;max-width:96%;margin:20px auto;background:#fff;padding:38px 44px;box-shadow:0 4px 18px rgba(0,0,0,.15)}
 .ph{text-align:center;border-bottom:3px double #12476f;padding-bottom:10px;margin-bottom:4px}
 .ph h1{margin:0;font-size:19px;letter-spacing:2px;color:#12476f}
 .ph p{margin:2px 0;font-size:11.5px;color:#4c6b86;font-family:Arial}
 .rt{display:flex;justify-content:space-between;font:bold 11.5px Arial;color:#12476f;margin:8px 0 14px}
 .grp{border:1px solid #b9d3ea;border-radius:6px 6px 0 0;background:linear-gradient(90deg,#E3EFFA,#F7FBFE);margin-top:16px;padding:8px 12px;display:flex;align-items:center;gap:12px}
 .grp img{width:52px;height:62px;object-fit:cover;border:1px solid #12476f;border-radius:4px}
 .grp .gm{font-size:11px;color:#5b7c99}
 .grp .gt{font:bold 14px Georgia;color:#12476f}
 .grp .gn{margin-left:auto;text-align:right;font:bold 11px Arial;color:#12476f;background:#12476f;color:#ffd76e;padding:3px 10px;border-radius:3px;letter-spacing:1px}
 table{width:100%;border-collapse:collapse;font-size:12px;font-family:Arial}
 th{background:#1B6BB3;color:#fff;padding:6px 8px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px}
 td{padding:6px 8px;border-bottom:1px solid #dce9f5;vertical-align:top}
 tr:nth-child(even) td{background:#F4F9FE}
 .pic{width:40px;height:50px;object-fit:cover;border:1px solid #b9d3ea;border-radius:3px}
 .gft{text-align:right;font:bold 11.5px Arial;color:#12476f;border:1px solid #b9d3ea;border-top:0;border-radius:0 0 6px 6px;padding:5px 12px;background:#F4F9FE}
 .rf{margin-top:26px;border-top:3px double #12476f;padding-top:10px;display:flex;justify-content:space-between;font:11px Arial;color:#4c6b86}
 .rf b{color:#12476f}
 .none{padding:40px;text-align:center;color:#5b7c99;font:italic 14px Georgia;border:1px dashed #b9d3ea;border-radius:8px;margin-top:18px}
 @media print{ body{background:#fff} .bar{display:none} .page{box-shadow:none;width:auto;margin:0;padding:8mm 10mm}
   .grp,.gft{break-inside:avoid} tr{break-inside:avoid} @page{size:A4 portrait;margin:10mm} }
</style></head><body>
<div class="bar noprint">
 <a onclick="window.print()">&#128424; Print / Save as PDF</a>
 <a href="beneficiaries.php" style="background:#35668e">&laquo; Beneficiaries</a>
 <span class="x">Beneficially.rpt equivalent &middot; MedSavu web</span>
</div>
<div class="page">
  <div class="ph">
    <h1>KISUBI HOSPITAL — MEDICAL SAVING SCHEME</h1>
    <p>BENEFICIARY (BENEFICIALLY) REPORT</p>
    <p><?php echo $fmid ? 'Member-specific report' : 'All members, grouped by member'; ?> &middot; Compiled <?php echo e($printed); ?></p>
  </div>
  <div class="rt"><span>Report: Beneficially</span><span>Members: <?php echo $totalMem; ?> &middot; Beneficiaries: <?php echo $totalBen; ?></span></div>

  <?php if (!$totalBen): ?><div class="none">No beneficiaries registered<?php echo $fmid ? ' for this member' : ''; ?>.</div><?php endif; ?>

  <?php $g = 0; foreach ($byMember as $mid => $bens): $g++; $f0 = $bens[0]; ?>
  <div class="grp">
    <img src="memberpic.php?id=<?php echo e($mid); ?>" onerror="this.style.display='none'">
    <div><span class="gt"><?php echo e($f0['member_name']); ?></span>
      <div class="gm"><?php echo $has_ext && !empty($f0['lc1_village']) ? e($f0['lc1_village'] . ' / ' . $f0['district']) : ''; ?>
      &middot; status: <?php echo e($f0['member_status']); ?></div></div>
    <div class="gn"><?php echo e($f0['member_no']); ?></div>
  </div>
  <table>
    <tr><th style="width:26px">#</th><?php if ($has_ext): ?><th>Photo</th><?php endif; ?><th>Beneficiary name</th><th>Relationship</th>
        <?php if ($has_ext): ?><th>Gender</th><th>Age</th><th>Residence</th><?php endif; ?>
        <th>Patient No / NIN</th><th>Phone</th></tr>
    <?php $i = 0; foreach ($bens as $b): $i++; ?>
    <tr><td><?php echo $i; ?></td>
      <?php if ($has_ext): ?><td><?php echo !empty($b['has_photo']) ? '<img class="pic" src="memberpic.php?id=' . e($b['id']) . '&t=ben">' : '—'; ?></td><?php endif; ?>
      <td><b><?php echo e($b['full_names']); ?></b></td>
      <td><?php echo e($b['relationship']); ?></td>
      <?php if ($has_ext): ?><td><?php echo e($b['gender']); ?></td><td><?php echo e($b['age']); ?></td><td><?php echo e($b['residence']); ?></td><?php endif; ?>
      <td style="font-family:Courier New"><?php echo e($b['national_id']); ?></td>
      <td><?php echo e($b['phone_no']); ?></td></tr>
    <?php endforeach; ?>
  </table>
  <div class="gft">Total beneficiaries for <?php echo e($f0['member_name']); ?>: <?php echo count($bens); ?></div>
  <?php endforeach; ?>

  <div class="rf">
    <span>Prepared by: <b><?php echo e($_SESSION['fname']); ?></b></span>
    <span>Grand total beneficiaries: <b><?php echo $totalBen; ?></b> across <b><?php echo $totalMem; ?></b> members</span>
    <span>Kisubi Hospital &middot; MedSavu</span>
  </div>
</div>
</body></html>
