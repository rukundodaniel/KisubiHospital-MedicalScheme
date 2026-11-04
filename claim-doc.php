<?php
/** v4.1 Claim document download — access-scoped: the owner sees all; a facility login may
 *  only fetch documents attached to its own facility's claims. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/clinical-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));
if (!cl_tbl('claim_docs')) die('Document store not installed — run migrate.php once.');

$id = intval(get('id'));
$st = db()->prepare('SELECT d.*, c.facility_id FROM claim_docs d JOIN facility_claims c ON c.id=d.claim_id WHERE d.id=?');
$st->execute(array($id)); $d = $st->fetch();
if (!$d) die('Document not found.');
if (!fac_is_owner()) { $fid = fac_scope_fid(); if ($fid <= 0 || intval($d['facility_id']) !== $fid) die('Not permitted for your facility.'); }
$path = __DIR__ . '/data/claims/' . basename((string)$d['stored_name']);
if (!is_file($path)) die('The stored file is missing.');
$mimes = array('pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'txt' => 'text/plain', 'csv' => 'text/csv', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
$ext = strtolower(pathinfo((string)$d['stored_name'], PATHINFO_EXTENSION));
header('Content-Type: ' . (isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$d['orig_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
