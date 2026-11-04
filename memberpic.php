<?php
/** Streams a stored photograph (BLOB): member (default) or beneficiary (?t=ben).
 *  Access: any logged-in staff; a portal member may view their own photo and
 *  their own beneficiaries' photos. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_auth.php';

$id  = intval(get('id'));
$tbl = 'members';
$pm  = portal_member();

if (get('t') === 'ben') {
    $tbl = 'beneficiaries';
    if (!is_logged_in()) {
        if (!$pm) { http_response_code(403); exit; }
        $chk = db()->prepare('SELECT member_id FROM beneficiaries WHERE id=?');
        $chk->execute(array($id));
        $owner = $chk->fetchColumn();
        if ($owner === false || intval($owner) !== intval($pm['id'])) { http_response_code(403); exit; }
    }
} else {
    $allowed = is_logged_in();
    if (!$allowed && $pm && intval($pm['id']) === $id) { $allowed = true; }
    if (!$allowed) { http_response_code(403); exit; }
}

try {
    $st = db()->prepare("SELECT photo FROM $tbl WHERE id=?");
    $st->execute(array($id));
    $blob = $st->fetchColumn();
} catch (Exception $ex) { $blob = false; }
if (!$blob) {
    // 1x1 placeholder so missing photos degrade quietly in <img> tags
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . strlen($blob));
echo $blob;
