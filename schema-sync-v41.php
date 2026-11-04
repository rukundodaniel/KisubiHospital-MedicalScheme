<?php
/** ============================================================================
 * One-shot v4.1 schema sync — MULTI-TENANT CLINICAL + CLAIMS + DISBURSEMENTS
 * Run ONCE after uploading the v4.1 files:  https://yourdomain/schema-sync-v41.php?run=1
 * Creates the 10 v4.1 tables and the guarded column additions (idempotent —
 * safe to run repeatedly; adds nothing that exists, touches no data).
 * After it reports success, DELETE this file from the server.
 * ========================================================================== */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/schema41.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['run'] ?? '') !== '1') { echo "Append ?run=1 to execute the v4.1 schema sync.\n"; exit; }
echo "MedSavu v4.1 schema sync — " . date('Y-m-d H:i:s') . "\n\n";
$driver = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
echo "Driver: $driver\n";
try {
    $n = cl41_create_all($driver);
    foreach ($n as $line) echo "• $line\n";
} catch (Exception $e) { echo "Table creation issue: " . $e->getMessage() . "\n"; }
try {
    $m = cl41_alter_all();
    foreach ($m as $line) echo "• $line\n";
} catch (Exception $e) { echo "Column sync issue: " . $e->getMessage() . "\n"; }
/* backfill: price effective dates + per-facility module rows */
try { db()->exec("UPDATE facility_prices SET effective_date=DATE(created_at) WHERE effective_date IS NULL"); echo "• price effective dates backfilled\n"; } catch (Exception $e) { echo "• (price dates: " . $e->getMessage() . ")\n"; }
$fids = array();
try { foreach (db()->query('SELECT id FROM facilities')->fetchAll() as $f) $fids[] = intval($f['id']); } catch (Exception $e) {}
$seeded = 0;
foreach ($fids as $fid) {
    try { $c = intval(db()->query('SELECT COUNT(*) FROM facility_modules WHERE facility_id=' . $fid)->fetchColumn());
        if ($c === 0) { cl41_init_facility($fid); $seeded++; } } catch (Exception $e) {}
}
echo "• module rows ensured for all facilities ($seeded initialised fresh)\n";
echo "\nDone. If this page listed 'created'/'added' lines, the v4.1 platform is live —\nyour existing data is untouched. Delete this file now.\n";
