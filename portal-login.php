<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_auth.php';
require_once __DIR__ . '/includes/momo.php';

if (portal_member()) redirect('portal.php');

$error = ''; $u = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = post('portal_user');
    $p = isset($_POST['portal_pass']) ? $_POST['portal_pass'] : '';
    list($ok, $msg) = portal_login_check($u, $p);
    if ($ok) redirect('portal.php');
    $error = $msg;
}
$p_title = 'Member Portal Login';
include __DIR__ . '/includes/portal_header.php';
?>
<div class="portal-login-wrap">
  <div class="side">
    <h2>Save. Claim. Grow.</h2>
    <ol class="steps">
      <li>Sign in with the portal username the hospital issued to you.</li>
      <li>Choose an amount and press <b>Deposit with MTN MoMo</b>.</li>
      <li>Approve the prompt <b>*165*6#</b> on your phone &mdash; enter your MoMo PIN.</li>
      <li>Your scheme balance updates automatically and you receive an SMS receipt.</li>
    </ol>
  </div>
  <div class="main">
    <h1>Member Portal</h1>
    <div class="sub">Kisubi Hospital Medical Saving Scheme</div>
    <?php if ($error): ?><div class="loginerr"><?php echo e($error); ?></div><?php endif; ?>
    <form method="post">
      <?php echo csrf_field(); ?>
      <label class="f">Portal username</label>
      <input class="f" name="portal_user" value="<?php echo e($u); ?>" autofocus style="margin-bottom:14px">
      <label class="f">Password</label>
      <input class="f" type="password" name="portal_pass" minlength="4" style="margin-bottom:6px">
      <div class="pwdhint" style="margin-bottom:14px">Your portal password &mdash; minimum 4 characters.</div>
      <button class="btn primary" style="width:100%;padding:12px;font-size:15px" type="submit">Sign in to my account</button>
    </form>
    <p style="text-align:center;font-size:12.5px;margin:14px 0 0">
      <a href="portal-register.php">First time here? Register your portal access</a>
    </p>
    <p style="text-align:center;font-size:11.5px;color:#7a94aa;margin-top:10px">Staff sign-in: <a href="login.php">hospital portal</a></p>
  </div>
</div>
<?php include __DIR__ . '/includes/portal_footer.php'; ?>
