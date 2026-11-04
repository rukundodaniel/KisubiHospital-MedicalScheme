<?php
/**
 * Public MTN MoMo collections callback. No session.
 * MTN posts {"X-Reference-Id":"...","body":{"externalId":"...","status":"SUCCESS"...}}
 * Idempotent: credits only once (guarded flip in momo_finalize). Always answers 200.
 */
$cfg = __DIR__ . '/config.php';
if (!file_exists($cfg)) { http_response_code(200); header('Content-Type: application/json'); exit('{"received":true}'); }
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/momo.php';
session_write_close(); // don't hold the session lock during gateway traffic

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) { http_response_code(200); echo '{"received":true,"note":"no json"}'; exit; }

$ref = null; $ext = null; $status = null;
if (isset($data['X-Reference-Id'])) $ref = $data['X-Reference-Id'];
$body = isset($data['body']) && is_array($data['body']) ? $data['body'] : $data;
if (isset($body['externalId'])) $ext = $body['externalId'];
if (isset($body['status'])) $status = $body['status'];

try {
    $pdo = db();
    $txnId = 0;
    if ($ext !== null && $ext !== '') {
        $st = $pdo->prepare('SELECT id FROM momo_transactions WHERE external_id=?');
        $st->execute(array($ext));
        $r = $st->fetch(); if ($r) $txnId = intval($r['id']);
    }
    if (!$txnId && $ref) {
        $st = $pdo->prepare('SELECT id FROM momo_transactions WHERE reference_id=?');
        $st->execute(array($ref));
        $r = $st->fetch(); if ($r) $txnId = intval($r['id']);
    }
    if ($txnId) {
        @file_put_contents(__DIR__ . '/data/momo_callback.log', date('c') . ' ' . $raw . "\n", FILE_APPEND);
        momo_finalize($txnId, $status !== null ? $status : null, $raw);
    }
} catch (Throwable $ex) {
    @file_put_contents(__DIR__ . '/data/momo_callback.log', date('c') . ' ERROR ' . $ex->getMessage() . "\n", FILE_APPEND);
}
http_response_code(200);
header('Content-Type: application/json');
echo '{"received":true}';
