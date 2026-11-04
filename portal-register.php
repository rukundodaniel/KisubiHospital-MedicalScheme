<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_auth.php';
require_once __DIR__ . '/includes/momo.php';

function reg_norm($v) {
    $d = preg_replace('/\D/', '', (string)$v);
    if ($d === '') return '';
    if (strpos($d, '256') === 0) return $d;
    if ($d[0] === '0') return '256' . substr($d, 1);
    if ($d[0] === '7') return '256' . $d;
    return $d;
}
$err = ''; $okInfo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mno = post('member_no'); $names = post('full_names'); $phone = post('phone_no'); $nin = post('national_id');
    $un = post('new_user'); $p1 = post('new_pass'); $p2 = post('new_pass2');
    $mob1 = reg_norm(post('mob1')); $mob2 = reg_norm(post('mob2'));
    try {
        if ($mob1 === '' && $mob2 === '') $err = 'Enter at least one mobile number (Mobile No. 1 MTN or No. 2 Airtel).';
        elseif (strlen($p1) < 8) $err = 'Password must be at least 8 characters.';
        elseif ($p1 !== $p2) $err = 'The two passwords do not match.';
        elseif (!preg_match('/^[A-Za-z0-9_.-]{4,30}$/', $un)) $err = 'Username: 4-30 letters/numbers only (no spaces).';
        else {
            $st = db()->prepare('SELECT * FROM members WHERE member_no=?');
            $st->execute(array($mno));
            $m = $st->fetch();
            $matches = $m && strcasecmp(trim($m['full_names']), $names) === 0
                && (preg_replace('/[^0-9]/', '', (string)$m['phone_no']) === preg_replace('/[^0-9]/', '', (string)$phone))
                && strtoupper(trim((string)$m['national_id'])) === strtoupper(trim((string)$nin));
            if (!$matches) {
                $err = 'We could not match you to a member record. Check your Member No., full names, phone and National ID as registered at the hospital.';
            } else {
                $st = db()->prepare('SELECT id FROM members WHERE portal_username=?');
                $st->execute(array($un));
                if ($st->fetch()) $err = 'That username is already taken. Choose another.';
                else {
                    db()->prepare('UPDATE members SET portal_username=?, portal_pass_hash=?, portal_active=1, portal_created_at=? WHERE id=?')
                       ->execute(array($un, password_hash($p1, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), $m['id']));
                    if ($mob1 !== '' || $mob2 !== '') {
                        try {
                            $set = array(); $args = array();
                            if ($mob1 !== '') { $set[] = 'mtn_no=?'; $args[] = $mob1; }
                            if ($mob2 !== '') { $set[] = 'airtel_no=?'; $args[] = $mob2; }
                            $args[] = $m['id'];
                            db()->prepare('UPDATE members SET ' . implode(', ', $set) . ' WHERE id=?')->execute($args);
                        } catch (Exception $pe) { /* extended columns not present on this install - portal access still created */ }
                    }
                    log_action('Member portal registered ' . $m['member_no'], 'members', intval($m['id']));
                    $okInfo = 'Portal access created for ' . $m['full_names'] . ' (' . $m['member_no'] . ').';
                    flash('Welcome! Please sign in.', 'ok');
                    redirect('portal-login.php');
                }
            }
        }
    } catch (Exception $ex) { $err = 'Registration failed: ' . $ex->getMessage(); }
}

$p_title = 'Portal Registration';
include __DIR__ . '/includes/portal_header.php';
?>
<div class="portal-login-wrap">
  <div class="side">
    <h2>Why join the portal?</h2>
    <ol class="steps">
      <li>Deposit from your MTN Mobile Money line any time &mdash; day or night.</li>
      <li>Watch your scheme balance update instantly after approval.</li>
      <li>Keep a full statement of deposits and settlements.</li>
      <li>Get SMS receipts for every payment.</li>
    </ol>
  </div>
  <div class="main">
    <h1>Register for the Member Portal</h1>
    <div class="sub">Verify your identity with the details the hospital holds for you.</div>
    <?php if ($err): ?><div class="loginerr"><?php echo e($err); ?></div><?php endif; ?>
    <form method="post">
      <?php echo csrf_field(); ?>
      <div class="formgrid" style="grid-template-columns:1fr 1fr">
        <div><label class="f">Member No. (e.g. KMH-0001)</label><input class="f" name="member_no" value="<?php echo e(post('member_no')); ?>"></div>
        <div><label class="f">Full names (exactly as registered)</label><input class="f" name="full_names" value="<?php echo e(post('full_names')); ?>"></div>
        <div><label class="f">Phone on record</label><input class="f" name="phone_no" value="<?php echo e(post('phone_no')); ?>"></div>
        <div><label class="f">National ID / NIN</label><input class="f" name="national_id" value="<?php echo e(post('national_id')); ?>"></div>
        <div><label class="f">Mobile No. 1 (MTN)</label><input class="f" name="mob1" value="<?php echo e(post('mob1')); ?>" placeholder="077... / 078..."></div>
        <div><label class="f">Mobile No. 2 (Airtel)</label><input class="f" name="mob2" value="<?php echo e(post('mob2')); ?>" placeholder="075... / 070..."></div>
        <div style="grid-column:1/-1;font-size:11.5px;color:#7a94aa">At least <b>one</b> mobile number is a must. Whatever you give here replaces the numbers on your member file &mdash; keep them current so MoMo prompts and SMS receipts reach you.</div>
        <div><label class="f">Choose portal username</label><input class="f" name="new_user" value="<?php echo e(post('new_user')); ?>"></div>
        <div><label class="f">Choose password (8+ chars)</label><input class="f" type="password" name="new_pass"></div>
        <div><label class="f">Repeat password</label><input class="f" type="password" name="new_pass2"></div>
      </div>
      <div style="margin-top:16px;display:flex;gap:10px">
        <button class="btn success" type="submit">Create my portal account</button>
        <a class="btn plain" href="portal-login.php">Back to sign in</a>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/includes/portal_footer.php'; ?>
