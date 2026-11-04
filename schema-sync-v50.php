<?php
/** v5.0 ONE-SHOT schema sync — run once on the live server (GET), then DELETE this file.
 *  Adds the multi-institution wallets, insurance engine, bill settlement, branches, SMS
 *  templates + additive columns. Strictly non-destructive; every statement is guarded.
 *  Safe to re-run. */
require_once __DIR__ . '/includes/bootstrap.php';
if (!empty($_GET['run']) && $_GET['run'] !== '1') { die('Add ?run=1 to execute.'); }
if (empty($_GET['run'])) {
    echo '<div style="font-family:Georgia;padding:30px;background:#EAF2FA"><h2 style="color:#12476f">MedSavu v5.0 schema sync</h2>'
       . '<p>This will create the institutional wallets / insurance / settlement tables and add the v5.0 columns <b>without touching any existing row</b>.</p>'
       . '<p><a style="background:#1B6BB3;color:#fff;padding:10px 22px;border-radius:4px;text-decoration:none" href="schema-sync-v50.php?run=1">Run it now</a></p></div>';
    exit;
}
require_once __DIR__ . '/includes/schema50.php';
echo "<pre style='font-family:Consolas,monospace;background:#0f1c28;color:#d7e9f7;padding:24px;border-radius:8px'>";
echo "MedSavu v5.0 schema sync — " . date('Y-m-d H:i:s') . "\n\n";
try {
    $drv = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    foreach (cl50_create_all($drv) as $t) echo "  created/verified table: $t\n";
    foreach (cl50_alter_all() as $l) echo "  column: $l\n";
    foreach (cl50_seed() as $l) echo "  seed: $l\n";
} catch (Exception $e) { echo "\nISSUE: " . $e->getMessage() . "\n"; }
echo "\nDone. Your existing data is untouched — wallets start at 0 and fill via verified deposits.\n";
echo "<b>Delete schema-sync-v50.php from the server now.</b></pre>";
