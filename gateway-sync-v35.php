<?php
/**
 * ONE-SHOT v3.5: auto-fill the SmsDual gateway settings to match SmsDualClient.vb
 * (endpoint, account, sender, live mode). Idempotent; mask secrets in output.
 * Run once via ?go=1, then DELETE this file.
 */
if (!isset($_GET['go'])) { http_response_code(404); exit; }
require __DIR__ . '/includes/bootstrap.php';

function sget($k) { try { $st = db()->prepare('SELECT v FROM settings WHERE k=?'); $st->execute(array($k)); $v = $st->fetchColumn(); return $v === false ? null : $v; } catch (Exception $e) { return null; } }
function sput($k, $v, $force) {
    $cur = sget($k);
    if ($cur === null) { db()->prepare('INSERT INTO settings (k, v) VALUES (?,?)')->execute(array($k, $v)); return 'inserted'; }
    if ($force || trim((string)$cur) === '') { db()->prepare('UPDATE settings SET v=? WHERE k=?')->execute(array($v, $k)); return 'updated'; }
    return 'kept';
}

$rows = array(
    array('sms_api_url',   'https://www.smsdual.info/bulksms-api.php', true),  // force: the old smsdual.com URL is wrong
    array('sms_username',  '256700541666', false),
    array('sms_client',    '256700541666', false),
    array('sms_password',  'kisubi', false),
    array('sms_sender_id', 'KISUBI', false),
    array('sms_send_mode', 'live', true), // VB desktop app sends immediately; keep in sync
);
$lines = array();
foreach ($rows as $r) {
    $act = sput($r[0], $r[1], $r[2]);
    $shown = ($r[0] === 'sms_password') ? '****' : sget($r[0]);
    $lines[] = $r[0] . ' -> ' . $act . ' (' . $shown . ')';
}
header('Content-Type: text/plain');
echo "Gateway auto-fill complete (run once, now delete this file):\n";
foreach ($lines as $l) echo ' - ' . $l . "\n";
