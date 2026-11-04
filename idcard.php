<?php
/** Printable member ID card (the IdCard form equivalent). */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = intval(get('id'));
$st = db()->prepare('SELECT * FROM members WHERE id=?'); $st->execute(array($id));
$m = $st->fetch();
if (!$m) { die('Member not found.'); }
$has_ext = true; $dob = ''; $marital = ''; $village = ''; $district = ''; $card = ''; $airtel = '';
try { $t = db()->prepare('SELECT dob, marital_status, lc1_village, district, card_no, airtel_no FROM members WHERE id=?'); $t->execute(array($id)); $x = $t->fetch();
    $dob = $x['dob']; $marital = $x['marital_status']; $village = $x['lc1_village']; $district = $x['district']; $card = $x['card_no']; $airtel = $x['airtel_no'];
} catch (Exception $e) { $has_ext = false; }
$since = date('M Y', strtotime($m['registration_date']));
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<title>ID Card — <?php echo e($m['member_no']); ?></title>
<style>
 body{margin:0;background:#3b556b;font-family:Georgia,'Times New Roman',serif;padding:26px}
 .bar{text-align:center;margin-bottom:18px}
 .bar a,.bar button{font:bold 13px "Segoe UI",Arial;color:#fff;background:#1B6BB3;border:0;padding:9px 18px;border-radius:3px;text-decoration:none;margin:0 4px;cursor:pointer}
 .card{width:640px;max-width:100%;margin:0 auto;background:linear-gradient(180deg,#fff 0%,#F4F9FE 100%);border:2px solid #12476f;border-radius:10px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.4)}
 .hd{background:linear-gradient(120deg,#1B6BB3,#12476f);color:#fff;padding:14px 20px;display:flex;align-items:center;gap:14px}
 .crest{width:52px;height:52px;background:#fff;color:#12476f;border-radius:8px;display:flex;align-items:center;justify-content:center;font:bold 30px Georgia;flex:0 0 auto}
 .hd h1{margin:0;font-size:17px;letter-spacing:.6px}
 .hd p{margin:2px 0 0;font-size:11px;color:#cfe4f7;font-family:Arial}
 .bd{display:flex;gap:18px;padding:18px 22px 8px}
 .pic{width:150px;height:170px;border:2px solid #12476f;border-radius:6px;background:#E3EFFA;object-fit:cover;flex:0 0 auto}
 .pic.none{display:flex;align-items:center;justify-content:center;color:#7a94aa;font:bold 12px Arial;text-align:center}
 .info{flex:1}
 .name{font:bold 21px Georgia;color:#12476f;line-height:1.15}
 .no{display:inline-block;margin:8px 0 4px;background:#12476f;color:#ffd76e;font:bold 15px "Courier New";letter-spacing:1.5px;padding:5px 14px;border-radius:4px}
 table{width:100%;border-collapse:collapse;margin-top:8px;font-size:12.5px;font-family:Arial}
 td{padding:4px 2px;border-bottom:1px dotted #b9d3ea}
 td.k{color:#5b7c99;width:34%;font-size:11px;text-transform:uppercase;letter-spacing:.4px}
 .ft{margin:10px 22px 16px;border-top:2px solid #1B6BB3;padding-top:8px;display:flex;justify-content:space-between;font:bold 11px Arial;color:#12476f}
 .sig{text-align:right;color:#5b7c99;font:normal 10.5px Arial}
 .valid{color:#20603a}
 @media print{ body{background:#fff;padding:0} .bar{display:none} .card{box-shadow:none;margin:0 auto} }
</style></head>
<body>
<div class="bar"><button onclick="window.print()">🖨 Print card</button>
 <a href="members.php?a=edit&id=<?php echo e($m['id']); ?>">Edit member</a></div>
<div class="card">
  <div class="hd"><div class="crest">K</div>
    <div><h1>KISUBI HOSPITAL — MEDICAL SAVING SCHEME</h1>
    <p>MedSavu &middot; Kisubi, Kampala, Uganda &middot; www.kisubihospital.co.ug</p></div></div>
  <div class="bd">
    <?php if ($has_ext): ?><img class="pic" src="memberpic.php?id=<?php echo e($m['id']); ?>" onerror="this.outerHTML='<div class=&quot;pic none&quot;>NO PHOTO<br>ON FILE</div>'"><?php else: ?><div class="pic none">NO PHOTO<br>ON FILE</div><?php endif; ?>
    <div class="info">
      <div class="name"><?php echo e($m['full_names']); ?></div>
      <div class="no"><?php echo e($m['member_no']); ?></div>
      <table>
        <tr><td class="k">Gender / Marital</td><td><?php echo e($m['gender']); ?><?php echo $marital!==''?' · '.e($marital):''; ?></td></tr>
        <?php if ($dob): ?><tr><td class="k">Date of birth</td><td><?php echo e(date('d M Y', strtotime($dob))); ?></td></tr><?php endif; ?>
        <tr><td class="k">MTN / Airtel</td><td><?php echo e(isset($m['mtn_no'])?$m['mtn_no']:(isset($m['phone_no'])?$m['phone_no']:'')); ?><?php echo $airtel?' · '.e($airtel):''; ?></td></tr>
        <?php if ($village||$district): ?><tr><td class="k">LC1 / District</td><td><?php echo e(trim($village.' / '.$district,' /')); ?></td></tr><?php endif; ?>
        <tr><td class="k">NIN</td><td><?php echo e($m['national_id']); ?></td></tr>
        <?php if ($card): ?><tr><td class="k">Card / NFC UID</td><td style="font-family:Courier New"><?php echo e($card); ?></td></tr><?php endif; ?>
        <tr><td class="k">Member since</td><td><?php echo e($since); ?> &middot; <?php echo e($m['status']); ?></td></tr>
      </table>
    </div>
  </div>
  <div class="ft">
    <span>Card valid while membership is <span class="valid">active</span></span>
    <span class="sig">Executive Director<br>______________________</span>
  </div>
</div>
</body></html>
