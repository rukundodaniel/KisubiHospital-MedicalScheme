<?php
/** v4.1 Facility logo delivery — files live in data/ (web-blocked), so logos stream through here. */
require_once __DIR__ . '/includes/bootstrap.php';
$id = intval(get('id'));
if ($id <= 0) die();
try {
    $st = db()->prepare('SELECT logo FROM facilities WHERE id=?'); $st->execute(array($id)); $row = $st->fetch();
} catch (Exception $e) { die(); }
$logo = $row ? trim((string)$row['logo']) : '';
if ($logo === '' || strpos($logo, '..') !== false) die();
$path = __DIR__ . '/data/' . $logo;
if (!is_file($path)) die();
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . ($ext === 'png' ? 'image/png' : 'image/jpeg'));
header('Cache-Control: public, max-age=600');
readfile($path);
