<?php
/** v3.9 Price lists — one per facility, ADDITIVE by design: every facility starts from the
 *  hospital base kit (stamped STANDARD) and adds its own priced lines (ADD-ON) beneath. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facilities.php';
require_once __DIR__ . '/includes/facility70.php'; f70_ensure();
require_once __DIR__ . '/includes/clinical-core.php';
require_role(array('Super Admin', 'Administrator', 'Finance', 'Facility Admin', 'Reception', 'User'));

$fid = intval(get('facility'));
$locked = fac_scope_fid();
if ($locked > 0) $fid = $locked;
if ($fid > 0) fac_lock($fid);
if (!$fid) {
    $first = fac_list();
    if (!$first) { $page_title = 'Price Lists'; include __DIR__ . '/includes/header.php';
        echo '<div class="panel"><h2>No facilities yet</h2><p><a href="facilities.php?a=new">Create a facility</a> first — each one gets its own additive price list automatically.</p></div>';
        include __DIR__ . '/includes/footer.php'; exit; }
    redirect('pricelist.php?facility=' . intval($first[0]['id']));
}
$f = fac_of($fid);
if (!$f) { flash('Facility not found.', 'err'); redirect($locked > 0 ? 'facility-claims.php' : 'facilities.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'add') {
        $nm = trim(post('item_name')); $pr = (float)str_replace(',', '', post('price'));
        if ($nm === '' || $pr <= 0) { flash('Item name and a price above zero are required.', 'err'); redirect('pricelist.php?facility=' . $fid); }
        require_once __DIR__ . '/includes/facility70.php'; f70_ensure();
        $pendAdd = f70_price_approval() && !fac_is_owner();
        db()->prepare('INSERT INTO facility_prices (facility_id, category, item_name, unit, price, is_base, created_at, created_by' . ($pendAdd ? ', active, approval' : '') . ') VALUES (?,?,?,?,?,0,?,?' . ($pendAdd ? ',0,\'Pending\'' : '') . ')')
           ->execute(array($fid, trim(post('category')) !== '' ? post('category') : 'Add-on', $nm, trim(post('unit')) !== '' ? post('unit') : 'each', $pr, date('Y-m-d H:i:s'), $_SESSION['uname']));
        if ($pendAdd) { flash('"' . $nm . '" submitted as a PENDING line — Kisubi central approval is enabled for price changes, so it stays inactive until approved.', 'info'); redirect('pricelist.php?facility=' . $fid); }
        log_action('Price ADD-ON "' . $nm . '" (' . ugx($pr) . ') on ' . $f['code'] . ' list', 'facility_prices', intval(db()->lastInsertId()));
        $npid = intval(db()->lastInsertId());
        try { if (cl_has('facility_prices', 'effective_date')) db()->prepare('UPDATE facility_prices SET effective_date=? WHERE id=?')->execute(array(today(), $npid)); } catch (Exception $e) {}
        cl_price_log($fid, 'ADDED', $nm, null, $pr, post('unit'), post('category'));
        flash('"' . $nm . '" added to the ' . $f['name'] . ' list — additive lines price on top of the base kit.', 'ok');
        redirect('pricelist.php?facility=' . $fid);
    }
    if ($op === 'import') {
        require_once __DIR__ . '/includes/facility70.php';
        if (f70_price_approval() && !fac_is_owner()) { flash('Bulk import is paused while central price approval is active — submit lines one at a time so each gets reviewed.', 'err'); redirect('pricelist.php?facility=' . $fid); }
        require_once __DIR__ . '/includes/wallet-core.php';
        $text = post('csv_text');
        if (isset($_FILES['csv']) && $_FILES['csv']['error'] === 0) { $raw = file_get_contents($_FILES['csv']['tmp_name']); if (strlen((string)$raw) < 400000) $text .= ($text !== '' ? "\n" : '') . $raw; }
        $lines = preg_split('/\r\n|\r|\n/', trim(str_replace("\xEF\xBB\xBF", '', (string)$text)));
        $added = 0; $updated = 0; $skipped = array();
        $by = $_SESSION['uname'];
        foreach ($lines as $li => $ln) {
            $ln = trim($ln); if ($ln === '') continue;
            $c = array_map(function ($x) { return trim((string)$x); }, str_getcsv($ln));
            if ($li === 0 && in_array(mb_strtolower($c[0]), array('category', 'cat'), true) && mb_strpos(mb_strtolower($c[1] ?? ''), 'item') !== false) continue; // header
            $cat = $c[0] !== '' ? $c[0] : 'Add-on'; $nm = $c[1] ?? ''; $unit = (string)($c[2] ?? '') !== '' ? (string)$c[2] : 'each'; $pr = (float)str_replace(',', '', (string)($c[3] ?? ''));
            if ($nm === '' || $pr <= 0) { $skipped[] = 'line ' . ($li + 1) . ' (need item + price>0)'; continue; }
            $st = db()->prepare('SELECT id, price FROM facility_prices WHERE facility_id=? AND item_name=?'); $st->execute(array($fid, $nm)); $ex = $st->fetch();
            if ($ex) {
                if (abs((float)$ex['price'] - $pr) < 0.005) { $skipped[] = 'line ' . ($li + 1) . ' (unchanged)'; continue; }
                db()->prepare('UPDATE facility_prices SET price=?, category=?, unit=?, effective_date=? WHERE id=?')->execute(array($pr, $cat, $unit, today(), intval($ex['id'])));
                try { cl_price_log($fid, 'PRICE CHANGE', $nm, (float)$ex['price'], $pr, $unit, $cat); } catch (Exception $e) {}
                $updated++;
            } else {
                db()->prepare('INSERT INTO facility_prices (facility_id, category, item_name, unit, price, is_base, created_at, created_by, effective_date) VALUES (?,?,?,?,?,0,?,?,?)')
                   ->execute(array($fid, $cat, $nm, $unit, $pr, date('Y-m-d H:i:s'), $by, today()));
                try { cl_price_log($fid, 'ADDED', $nm, null, $pr, $unit, $cat); } catch (Exception $e) {}
                $added++;
            }
        }
        log_action('Price CSV import on ' . $f['code'] . ': ' . $added . ' added, ' . $updated . ' repriced', 'facility_prices', 0);
        flash('Import — <b>' . $added . ' new</b> lines, <b>' . $updated . ' re-priced</b>' . ($skipped ? ', ' . count($skipped) . ' skipped (' . e(implode('; ', array_slice($skipped, 0, 4))) . (count($skipped) > 4 ? ' …' : '') . ')' : '') . '. Existing items were never deleted.', 'ok');
        redirect('pricelist.php?facility=' . $fid);
    }
    if ($op === 'edit') {
        $pid = intval(post('pid')); $pr = (float)str_replace(',', '', post('price'));
        $eff = post('effective_date') !== '' ? post('effective_date') : today();
        $st2 = db()->prepare('SELECT * FROM facility_prices WHERE id=? AND facility_id=?'); $st2->execute(array($pid, $fid)); $p = $st2->fetch();
        if (!$p) { flash('Line not found.', 'err'); redirect('pricelist.php?facility=' . $fid); }
        if ($pr <= 0) { flash('A price above zero is required — retire a line instead of zeroing it.', 'err'); redirect('pricelist.php?facility=' . $fid); }
        if (abs($pr - (float)$p['price']) < 0.005) { flash('The price is unchanged.', 'info'); redirect('pricelist.php?facility=' . $fid); }
        if (intval($p['is_base']) === 1) {
            $dup = db()->prepare('SELECT id FROM facility_prices WHERE facility_id=? AND LOWER(item_name)=LOWER(?) AND is_base=0'); $dup->execute(array($fid, $p['item_name']));
            if ($dup->fetchColumn()) { flash('This item already has an override line on this facility — edit that line instead.', 'err'); redirect('pricelist.php?facility=' . $fid); }
            db()->prepare('INSERT INTO facility_prices (facility_id, category, item_name, unit, price, is_base, effective_date, created_at, created_by) VALUES (?,?,?,?,?,0,?,?,?)')
               ->execute(array($fid, $p['category'], $p['item_name'], $p['unit'], $pr, $eff, date('Y-m-d H:i:s'), $_SESSION['uname']));
            cl_price_log($fid, 'OVERRIDE', $p['item_name'], (float)$p['price'], $pr, $p['unit'], $p['category']);
            log_action('Price OVERRIDE for base item "' . $p['item_name'] . '" on ' . $f['code'] . ': ' . ugx((float)$p['price']) . ' -> ' . ugx($pr) . ' eff ' . $eff, 'facility_prices', intval(db()->lastInsertId()));
            flash('Facility-specific price saved as an override — the network standard stays intact and history is kept.', 'ok');
        } else {
            require_once __DIR__ . '/includes/facility70.php'; f70_ensure();
            if (f70_price_approval() && !fac_is_owner()) {
                db()->prepare("UPDATE facility_prices SET price_pending=?, approval='Pending' WHERE id=?")->execute(array($pr, $pid));
                log_action('Price request PENDING central approval "' . $p['item_name'] . '": ' . ugx((float)$p['price']) . ' -> ' . ugx($pr) . ' on ' . $f['code'], 'facility_prices', $pid);
                flash('Re-price submitted to Kisubi central for approval — the line keeps its current price until approved.', 'info');
                redirect('pricelist.php?facility=' . $fid);
            }
            if (cl_has('facility_prices', 'effective_date')) db()->prepare('UPDATE facility_prices SET price=?, effective_date=?, approval=\'Approved\', price_pending=NULL WHERE id=?')->execute(array($pr, $eff, $pid));
            else db()->prepare('UPDATE facility_prices SET price=? WHERE id=?')->execute(array($pr, $pid));
            cl_price_log($fid, 'EDIT', $p['item_name'], (float)$p['price'], $pr, $p['unit'], $p['category']);
            log_action('Price EDIT "' . $p['item_name'] . '" on ' . $f['code'] . ': ' . ugx((float)$p['price']) . ' -> ' . ugx($pr) . ' eff ' . $eff, 'facility_prices', $pid);
            flash('Price updated with history preserved — old claims keep the prices they were billed at.', 'ok');
        }
        redirect('pricelist.php?facility=' . $fid);
    }
    if ($op === 'toggle') { /* v7.0 §5 activate / deactivate — never delete history */
        f70_ensure();
        $st = db()->prepare('SELECT * FROM facility_prices WHERE id=? AND facility_id=?'); $st->execute(array(intval(post('pid')), $fid)); $p = $st->fetch();
        if ($p) {
            $newv = intval($p['active']) === 0 ? 1 : 0;
            db()->prepare('UPDATE facility_prices SET active=? WHERE id=?')->execute(array($newv, intval($p['id'])));
            cl_price_log($fid, $newv ? 'ACTIVATED' : 'DEACTIVATED', $p['item_name'], (float)$p['price'], (float)$p['price'], $p['unit'], $p['category']);
            log_action('Price line "' . $p['item_name'] . '" ' . ($newv ? 'activated' : 'deactivated') . ' on ' . $f['code'], 'facility_prices', intval($p['id']));
            flash($newv ? 'Line activated — billable again.' : 'Line deactivated — hidden from new bills; billed history and past prices are untouched.', 'ok');
        }
        redirect('pricelist.php?facility=' . $fid);
    }
    if ($op === 'approve_price' || $op === 'reject_price') { /* v7.0 §5 rule 8 — central price approval */
        if (!fac_is_owner()) { flash('Only Kisubi central can action price approvals.', 'err'); redirect('pricelist.php?facility=' . $fid); }
        f70_ensure();
        $st = db()->prepare("SELECT * FROM facility_prices WHERE id=? AND facility_id=? AND approval='Pending'"); $st->execute(array(intval(post('pid')), $fid)); $p = $st->fetch();
        if (!$p) { flash('No pending request on that line.', 'err'); redirect('pricelist.php?facility=' . $fid); }
        if ($op === 'approve_price') {
            if ($p['price_pending'] !== null && abs((float)$p['price_pending'] - (float)$p['price']) >= 0.005) {
                cl_price_log($fid, 'APPROVED RE-PRICE', $p['item_name'], (float)$p['price'], (float)$p['price_pending'], $p['unit'], $p['category']);
                db()->prepare("UPDATE facility_prices SET price=price_pending, price_pending=NULL, approval='Approved', active=1 WHERE id=?")->execute(array(intval($p['id'])));
            } else {
                cl_price_log($fid, 'APPROVED NEW LINE', $p['item_name'], null, (float)$p['price'], $p['unit'], $p['category']);
                db()->prepare("UPDATE facility_prices SET approval='Approved', active=1, price_pending=NULL WHERE id=?")->execute(array(intval($p['id'])));
            }
            log_action('Central APPROVED price request "' . $p['item_name'] . '" for ' . $f['code'], 'facility_prices', intval($p['id']));
            flash('Price request approved — the line is live on the facility list.', 'ok');
        } else {
            if ($p['price_pending'] !== null) db()->prepare("UPDATE facility_prices SET approval='Approved', price_pending=NULL WHERE id=?")->execute(array(intval($p['id']))); // revert: keep old price, close request
            else db()->prepare("UPDATE facility_prices SET approval='Rejected', active=0 WHERE id=?")->execute(array(intval($p['id']))); // new line never applied
            log_action('Central REJECTED price request "' . $p['item_name'] . '" for ' . $f['code'], 'facility_prices', intval($p['id']));
            flash('Price request rejected — the facility keeps its previous pricing.', 'info');
        }
        redirect('pricelist.php?facility=' . $fid);
    }
    if ($op === 'del') {
        $st = db()->prepare('SELECT * FROM facility_prices WHERE id=? AND facility_id=?'); $st->execute(array(intval(post('pid')), $fid)); $p = $st->fetch();
        if (!$p) redirect('pricelist.php?facility=' . $fid);
        if (intval($p['is_base']) === 1) { flash('Base-kit items are standard network-wide and cannot be deleted here — override them with an add-on line instead.', 'err'); redirect('pricelist.php?facility=' . $fid); }
        db()->prepare('DELETE FROM facility_prices WHERE id=?')->execute(array(intval($p['id'])));
        cl_price_log($fid, 'RETIRED', $p['item_name'], (float)$p['price'], null, $p['unit'], $p['category']);
        log_action('Removed add-on price "' . $p['item_name'] . '" from ' . $f['code'], 'facility_prices', intval($p['id']));
        flash('Add-on removed.', 'info'); redirect('pricelist.php?facility=' . $fid);
    }
    if ($op === 'copy') {
        $from = intval(post('from_facility'));
        if ($locked > 0) { flash('Price lists are facility-specific — cross-facility copy is a central rollout action.', 'err'); redirect('pricelist.php?facility=' . $fid); }
        if ($from === $fid || !fac_of($from)) { flash('Pick another facility to copy from.', 'err'); redirect('pricelist.php?facility=' . $fid); }
        $st = db()->prepare('SELECT * FROM facility_prices WHERE facility_id=?'); $st->execute(array($from)); $src = $st->fetchAll();
        $mine = array(); foreach (fac_price_items($fid) as $p) $mine[mb_strtolower($p['item_name'])] = 1;
        $n = 0; $ins = db()->prepare('INSERT INTO facility_prices (facility_id, category, item_name, unit, price, is_base, created_at, created_by) VALUES (?,?,?,?,?,0,?,?)');
        foreach ($src as $p) { if (isset($mine[mb_strtolower($p['item_name'])])) continue; $ins->execute(array($fid, $p['category'], $p['item_name'], $p['unit'], $p['price'], date('Y-m-d H:i:s'), $_SESSION['uname'])); $n++; }
        log_action('Copied ' . $n . ' price lines from ' . fac_name($from) . ' into ' . $f['code'], 'facility_prices', $fid);
        flash($n . ' missing line(s) copied from ' . fac_name($from) . ' as add-ons.', 'ok'); redirect('pricelist.php?facility=' . $fid);
    }
}

$items = fac_price_items($fid, true); /* full register incl. retired/pending — badges distinguish; billing only offers active rows */
$pq = strtolower(trim((string) get('q'))); $pfilter = get('filter'); // 'retired' | 'pending' | 'active'
if ($pq !== '' || $pfilter !== '') { $items = array_values(array_filter($items, function ($p) use ($pq, $pfilter) {
    if ($pq !== '' && strpos(strtolower((string)$p['item_name'] . ' ' . (string)$p['category']), $pq) === false) return false;
    if ($pfilter === 'retired' && intval((string)(isset($p['active']) ? $p['active'] : 1)) === 1) return false;
    if ($pfilter === 'active' && (intval((string)(isset($p['active']) ? $p['active'] : 1)) !== 1 || (string)(isset($p['approval']) ? $p['approval'] : 'Approved') === 'Pending')) return false;
    if ($pfilter === 'pending' && (string)(isset($p['approval']) ? $p['approval'] : 'Approved') !== 'Pending') return false;
    return true; })); }
$pendingN = 0; foreach ($items as $p7) if ((string)(isset($p7['approval']) ? $p7['approval'] : 'Approved') === 'Pending') $pendingN++;
$histView = get('history');
$hist70 = array();
if ($histView === '1') { try { $hs = db()->prepare('SELECT * FROM price_history WHERE facility_id=? ORDER BY id DESC LIMIT 200'); $hs->execute(array($fid)); $hist70 = $hs->fetchAll(); } catch (Throwable $e) {} }

$byCat = array(); $baseN = 0; $addN = 0; $sumBase = 0.0; $sumAdd = 0.0;
foreach ($items as $p) { $byCat[$p['category']][] = $p; if (intval($p['is_base']) === 1) { $baseN++; $sumBase += (float)$p['price']; } else { $addN++; $sumAdd += (float)$p['price']; } }

// v5.0 CSV export — before any HTML so headers stay clean
if (get('a') === 'csv'):
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="pricelist-' . preg_replace('/\W/', '', $f['code']) . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, array('Category', 'Item', 'Unit', 'Price UGX', 'Type', 'Effective'));
    foreach (fac_price_items($fid) as $p) fputcsv($o, array($p['category'], $p['item_name'], $p['unit'], $p['price'], intval($p['is_base']) ? 'STANDARD' : 'add-on', (string)(isset($p['effective_date']) ? $p['effective_date'] : '')));
    fclose($o); exit;
endif;

$page_title = 'Price list — ' . $f['name'];
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1><?php echo fac_icon($f['ftype']); ?> Price list — <?php echo e($f['name']); ?>
   <span style="font:normal 12px Arial;color:#5b7c99"><?php echo e($f['code']); ?> &middot; additive list: base kit + house extras</span></h1>
 <span class="noprint">
 <?php if (fac_scope_fid() === 0): ?><a class="btn plain" href="pricelist.php<?php echo ''; ?>">&#8635; choose facility</a><?php endif; ?>
 <a class="btn primary" href="facility-claims.php?facility=<?php echo e($f['id']); ?>">Claims of this facility</a>
 <button class="btn" type="button" onclick="window.print()">&#128424; Print list</button></span></div>

<?php if (fac_scope_fid() === 0 && count(fac_list()) > 1): ?>
<div class="panel" style="padding:10px 14px;margin-bottom:12px">
<form method="get" style="display:flex;gap:8px;align-items:center">
<label class="f" style="margin:0">Facility:</label>
<select class="f" name="facility" style="max-width:340px" onchange="this.form.submit()">
<?php foreach (fac_list() as $o): ?><option value="<?php echo e($o['id']); ?>" <?php echo intval($o['id'])===$fid?'selected':''; ?>><?php echo fac_icon($o['ftype']); ?> <?php echo e($o['name']); ?> (<?php echo e($o['code']); ?>)</option><?php endforeach; ?>
</select><noscript><button class="btn small" type="submit">Go</button></noscript>
</form></div>
<?php endif; ?>

<form method="get" action="pricelist.php" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 12px">
 <input type="hidden" name="facility" value="<?php echo $fid; ?>">
 <input class="f" name="q" value="<?php echo e(get('q')); ?>" placeholder="Search items or categories" style="flex:1;min-width:200px;max-width:340px">
 <?php foreach (array('' => 'All lines', 'active' => 'Active', 'retired' => 'Deactivated', 'pending' => 'Pending approval') as $fk => $fl): ?>
 <a href="pricelist.php?facility=<?php echo $fid; ?>&q=<?php echo rawurlencode(get('q')); ?>&filter=<?php echo rawurlencode($fk); ?>" style="text-decoration:none;font:bold 11px Arial;padding:5px 10px;border-radius:4px;<?php echo $pfilter === $fk ? 'background:#1B6BB3;color:#fff' : 'background:#EAF2FA;color:#12476f' ?>"><?php echo $fl; ?></a><?php endforeach; ?>
 <button class="btn small" type="submit">Search</button>
 <span style="font-size:11px;color:#7a94aa"><?php if (f70_price_approval()): ?>&#9878; Central price approval <b>ON</b> — re-prices wait for Kisubi sign-off<?php else: ?>Central price approval off — the facility manages its list directly<?php endif; ?></span>
</form>
<div class="kpi-row">
  <div class="kpi"><div class="k-label">Base kit (STANDARD)</div><div class="k-value"><?php echo num($baseN); ?></div><div class="k-sub">shared hospital rates — <?php echo ugx($sumBase); ?></div></div>
  <div class="kpi gold"><div class="k-label">Add-ons (ADDITIVE)</div><div class="k-value"><?php echo num($addN); ?></div><div class="k-sub">this facility's own lines — <?php echo ugx($sumAdd); ?></div></div>
  <div class="kpi green"><div class="k-label">Full list</div><div class="k-value"><?php echo num(count($items)); ?></div><div class="k-sub">base + add-ons = every billable line</div></div>
</div>

<?php if (!$byCat): ?>
<div class="panel"><h2>Empty list</h2><p>This facility has no priced items yet — normally it is seeded at creation. Add one below.</p></div>
<?php endif; ?>
<?php foreach ($byCat as $cat => $rows): ?>
<div class="panel"><h2 style="margin-top:0"><?php echo e($cat); ?> <span style="font:normal 11px Arial;color:#7a94aa">(<?php echo count($rows); ?> line<?php echo count($rows)>1?'s':''; ?>)</span></h2>
<table class="data" style="margin:0"><tr><th>Item</th><th>Unit</th><th class="num">Price (UGX)</th><th>Origin</th><th class="noprint"></th></tr>
<?php foreach ($rows as $p): $base = intval($p['is_base']) === 1; ?>
<tr><td><b><?php echo e($p['item_name']); ?></b></td><td style="color:#5b7c99"><?php echo e($p['unit']); ?></td>
<td class="num"><b><?php echo number_format((float)$p['price'], 0); ?></b></td>
<td><?php echo $base ? '<span style="font:bold 9.5px Arial;color:#1B6BB3;background:#E3EFFA;border-radius:3px;padding:1px 7px">STANDARD</span>' : '<span style="font:bold 9.5px Arial;color:#8a6410;background:#FFF6E0;border-radius:3px;padding:1px 7px">ADD-ON</span>'; ?>
 <?php $apl70 = (string)(isset($p['approval']) ? $p['approval'] : 'Approved');
 if ($apl70 === 'Pending'): ?><span style="font:bold 9.5px Arial;color:#8a6410;background:#FFF1DC;border:1px solid #e8cfa0;border-radius:3px;padding:1px 7px">PENDING KISUBI APPROVAL</span><?php endif;
 if (intval(isset($p['active']) ? $p['active'] : 1) === 0 && $apl70 !== 'Pending'): ?><span style="font:bold 9.5px Arial;color:#7a94aa;background:#EEF3F8;border-radius:3px;padding:1px 7px">DEACTIVATED</span><?php endif;
 if ($p['price_pending'] !== null && $apl70 === 'Pending'): ?><span style="font:11px Arial;color:#5c7a94"> &rarr; requests <?php echo e(number_format((float)$p['price_pending'], 0)); ?></span><?php endif; ?></td>
<td class="noprint" style="text-align:right"><?php if ($apl70 === 'Pending' && fac_is_owner()): ?>
 <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="approve_price"><input type="hidden" name="pid" value="<?php echo e($p['id']); ?>"><button class="btn small primary" type="submit">&#10003; Approve</button></form>
 <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="reject_price"><input type="hidden" name="pid" value="<?php echo e($p['id']); ?>"><button class="btn small" type="submit">&#10007;</button></form>
<?php else: ?>
 <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="pid" value="<?php echo e($p['id']); ?>"><button class="btn small" type="submit" title="activate / deactivate"><?php echo intval(isset($p['active']) ? $p['active'] : 1) === 0 ? '&#9654;' : '&#9646;&#9646;'; ?></button></form>
 <?php if (!$base): ?><form method="post" style="display:inline" onsubmit="return confirm('Retire this add-on line? Past bills keep their prices.')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="del"><input type="hidden" name="pid" value="<?php echo e($p['id']); ?>"><button class="btn small danger" type="submit">&#10007;</button></form><?php endif; ?>
<?php endif; ?></td></tr>
<?php endforeach; ?></table></div>
<?php endforeach; ?>

<div class="grid-2">
<div class="panel" style="border:1px solid #bcd6ee"><h2 style="margin-top:0">+ Add an additive line</h2>
<form method="post"><?php echo csrf_field(); ?><input type="hidden" name="op" value="add">
<div class="formgrid" style="grid-template-columns:2fr 1fr 1fr 1fr">
 <div><label class="f">Item name *</label><input class="f" name="item_name" placeholder="e.g. Echocardiogram"></div>
 <div><label class="f">Category</label><input class="f" name="category" placeholder="Add-on"></div>
 <div><label class="f">Unit</label><input class="f" name="unit" placeholder="each"></div>
 <div><label class="f">Price UGX *</label><input class="f" name="price" inputmode="numeric"></div>
</div>
<div style="margin-top:8px"><button class="btn primary" type="submit">Add to list</button></div></form></div>

<div class="panel"><h2 style="margin-top:0">&#128228; Excel import / export <span style="font:normal 11px Arial;color:#7a94aa">columns: category, item, unit, price — additive: nothing is deleted, same item = price history update</span></h2>
<form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
<?php echo csrf_field(); ?><input type="hidden" name="op" value="import">
 <div style="flex:1;min-width:220px"><label class="f">Upload .csv (Excel: save sheet as CSV)</label><input class="f" type="file" name="csv" accept=".csv,.txt" style="margin:0"></div>
 <div style="flex:2;min-width:260px"><label class="f">…or paste rows</label><input class="f" name="csv_text" placeholder="OPD,Specialist review,per visit,30000" style="margin:0"></div>
 <button class="btn small gold" type="submit">Import into this list</button>
 <a class="btn small plain" href="pricelist.php?facility=<?php echo $fid; ?>&a=csv">&#128190; Download current list (Excel/CSV)</a>
</form></div>
<?php if ($locked <= 0): ?><div class="panel"><h2 style="margin-top:0">&#128203; Copy from another facility</h2>
<?php $others = array(); foreach (fac_list() as $o) if (intval($o['id']) !== $fid) $others[] = $o; ?>
<?php if (!$others): ?><p style="color:#7a94aa;font-size:12.5px">Create another facility and this becomes available — useful to roll a new branch out with an established clinic's extras.</p><?php else: ?>
<form method="post" style="display:flex;gap:8px;align-items:flex-end"><?php echo csrf_field(); ?><input type="hidden" name="op" value="copy">
 <div style="flex:1"><label class="f">Source facility</label><select class="f" name="from_facility">
 <?php foreach ($others as $o): ?><option value="<?php echo e($o['id']); ?>"><?php echo fac_icon($o['ftype']); ?> <?php echo e($o['name']); ?> (<?php echo e($o['code']); ?>)</option><?php endforeach; ?></select></div>
 <button class="btn" type="submit">Copy missing lines</button></form>
 <p style="font-size:11.5px;color:#7a94aa;margin:8px 0 0">Only lines your facility does not already carry are copied — as ADD-ON rows, so your base kit stays standard.</p>
<?php endif; ?></div><?php endif; ?>
</div>
<div class="panel" style="margin-top:14px"><h2 style="margin-top:0">&#128338; Price change history <?php echo $pendingN ? '<span style=\"font:normal 11px Arial;color:#8a6410\">· ' . $pendingN . ' awaiting approval</span>' : ''; ?>
 <a class=\"btn small plain\" style=\"float:right\" href=\"pricelist.php?facility=<?php echo $fid; ?>&history=<?php echo $histView === '1' ? '0' : '1'; ?>\"><?php echo $histView === '1' ? 'Hide' : 'Show'; ?> journal</a></h2>
<?php if ($histView === '1'): ?><?php if (!$hist70): ?><p style="color:#7a94aa;font-size:12.5px">No recorded changes yet.</p><?php else: ?>
<table class="data" style="margin:6px 0 0"><tr><th>When</th><th>Action</th><th>Item</th><th>Old (UGX)</th><th>New (UGX)</th><th>Effective</th><th>By</th></tr>
<?php foreach ($hist70 as $h): ?><tr><td style="white-space:nowrap"><?php echo e(substr((string)$h['changed_at'],0,16)); ?></td><td><b><?php echo e($h['action']); ?></b></td><td><?php echo e($h['item_name']); ?></td><td style="text-align:right"><?php echo $h['old_price'] === null ? '—' : number_format((float)$h['old_price'],0); ?></td><td style="text-align:right"><?php echo $h['new_price'] === null ? '—' : number_format((float)$h['new_price'],0); ?></td><td><?php echo e((string)$h['effective_date']); ?></td><td><?php echo e($h['changed_by']); ?></td></tr><?php endforeach; ?>
</table><?php endif; ?><?php else: ?><p style="font-size:12px;color:#5b7c99;margin:2px 0 0">Every add, re-price, override, approval and deactivation on this list is journaled — historical bills keep the prices they were created with. <?php if ($pendingN): ?><b><?php echo $pendingN; ?> change(s) await Kisubi approval.</b><?php endif; ?></p><?php endif; ?></div>

<div class="panel" style="margin-top:14px">
<h2 style="margin-top:0">&#9881; Adjust a price &mdash; history is always preserved</h2>
<form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap"><?php echo csrf_field(); ?><input type="hidden" name="op" value="edit">
 <div style="min-width:260px;flex:1"><label class="f">Line</label><select class="f" name="pid">
  <?php foreach ($items as $p): ?><option value="<?php echo e($p['id']); ?>"><?php echo e($p['category']); ?>: <?php echo e($p['item_name']); ?> — now <?php echo e(number_format((float)$p['price'],0)); ?><?php echo intval($p['is_base']) ? ' (STANDARD — becomes an override)' : ' (own)'; ?></option><?php endforeach; ?></select></div>
 <div><label class="f">New price (UGX)</label><input class="f" name="price" style="width:130px" inputmode="numeric" required></div>
 <div><label class="f">Effective date</label><input class="f" type="date" name="effective_date" value="<?php echo today(); ?>"></div>
 <button class="btn primary small" type="submit">Apply</button></form>
<?php $hist41 = cl_price_history($fid); if ($hist41): ?>
<h3 style="margin:14px 0 4px">Price change history</h3>
<div class="twrap" style="border:0;max-height:260px;overflow:auto"><table class="data" style="margin:0">
<tr><th>When</th><th>Action</th><th>Item</th><th class="num">From</th><th class="num">To</th><th>Effective</th><th>By</th></tr>
<?php foreach ($hist41 as $h): ?>
<tr><td style="font-size:11px"><?php echo e(date('d M Y H:i', strtotime($h['changed_at']))); ?></td>
<td><span class="badge <?php echo $h['action']==='RETIRED'?'':'grey'; ?>"><?php echo e($h['action']); ?></span></td>
<td><?php echo e($h['item_name']); ?> <span style="color:#7a94aa;font-size:10.5px"><?php echo e($h['category']); ?></span></td>
<td class="num"><?php echo $h['old_price'] !== null ? e(number_format((float)$h['old_price'],0)) : '—'; ?></td>
<td class="num"><b><?php echo $h['new_price'] !== null ? e(number_format((float)$h['new_price'],0)) : '—'; ?></b></td>
<td style="font-size:11px"><?php echo e($h['effective_date']); ?></td><td style="font-size:11px"><?php echo e($h['changed_by']); ?></td></tr>
<?php endforeach; ?></table></div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
