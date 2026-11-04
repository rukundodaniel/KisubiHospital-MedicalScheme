<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) redirect('dashboard.php');

$error = '';
$u = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = post('username');
    $p = isset($_POST['password']) ? $_POST['password'] : '';
    if (strlen((string)$p) < 4) { $error = 'Password must be at least 4 characters.'; }
    else try {
        $st = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
        $st->execute(array($u));
        $row = $st->fetch();
        if ($row && password_verify($p, $row['password_hash'])) {
            $l_fid = intval(isset($row['facility_id']) ? $row['facility_id'] : 0);
            if ($l_fid > 0) {
                $lst = db()->prepare('SELECT status, name FROM facilities WHERE id=?'); $lst->execute(array($l_fid)); $lfx = $lst->fetch();
                if (!$lfx || !in_array($lfx['status'], array('Active'), true)) {
                    try { db()->prepare("INSERT INTO login_log (username, outcome, via, ip, at) VALUES (?,?,?,?,?)")->execute(array($row['username'], 'BLOCKED-' . ($lfx ? $lfx['status'] : 'GONE'), 'web', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', date('Y-m-d H:i:s'))); } catch (Exception $e) {}
                    $error = 'Your facility (' . (isset($lfx['name']) ? $lfx['name'] : 'network site') . ') is currently ' . ($lfx ? $lfx['status'] : 'removed') . ' on the scheme — contact Kisubi HQ.';
                }
            }
            if (!isset($error) || $error === '') {
            session_regenerate_id(true);
            $_SESSION['uid'] = intval($row['id']);
            $_SESSION['uname'] = $row['username'];
            $_SESSION['fname'] = $row['full_name'];
            $_SESSION['role'] = $row['previledge'];
            $_SESSION['fid'] = intval(isset($row['facility_id']) ? $row['facility_id'] : 0);
            $st = db()->prepare('UPDATE users SET last_login=? WHERE id=?');
            $st->execute(array(date('Y-m-d H:i:s'), $row['id']));
            log_action('Signed in');
            $_SESSION['staff_role'] = isset($row['staff_role']) ? (string)$row['staff_role'] : '';
            try { db()->prepare("INSERT INTO login_log (username, outcome, via, ip, at) VALUES (?,?,?,?,?)")->execute(array($row['username'], 'OK', 'web', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', date('Y-m-d H:i:s'))); } catch (Exception $e) {}
            redirect(intval($row['facility_id']) > 0 ? 'institution.php' : 'dashboard.php');
            }
        }
        if (!isset($error) || $error === '') {
            $error = 'Wrong username or password.';
            try { db()->prepare("INSERT INTO login_log (username, outcome, via, ip, at) VALUES (?,?,?,?,?)")->execute(array(mb_substr($u, 0, 60), 'FAIL', 'web', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', date('Y-m-d H:i:s'))); } catch (Exception $e) {}
        }
    } catch (Exception $ex) {
        $error = 'Login failed: ' . $ex->getMessage() . ' - run install.php first?';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; MedSavu - Kisubi Hospital</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="loginpage">
<div class="loginbox">
  <div class="lh">
    <span class="logo">K</span>
    <h1>KISUBI HOSPITAL</h1>
    <p>MEDICAL SAVING SCHEME &middot; MedSavu</p>
  </div>
  <div class="lb">
    <?php if ($error): ?><div class="loginerr"><?php echo e($error); ?></div><?php endif; ?>
    <form method="post" action="login.php">
      <?php echo csrf_field(); ?>
      <label class="f">Staff Username</label>
      <input class="f" name="username" value="<?php echo e($u); ?>" autofocus autocomplete="username">
      <label class="f">Password</label>
      <input class="f" type="password" name="password" autocomplete="current-password" minlength="4">
      <div class="pwdhint">Minimum 4 characters. All logins are recorded.</div>
      <button class="btn primary" type="submit">Sign in to the system</button>
    </form>
    <div class="loginfoot">Staff sign-in only &mdash; <a href="portal-login.php" style="font-weight:bold">Members, go to the Member Portal &raquo;</a><br>All logins are recorded &middot; Kisubi &middot; Kampala, Uganda</div>
  </div>
</div>
</body>
</html>
