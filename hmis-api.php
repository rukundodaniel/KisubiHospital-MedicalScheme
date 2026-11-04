<?php
/** v4.0 HMIS integration gateway — token-authenticated JSON API so an existing hospital
 *  management system can look up members, read deposit balances, pull the facility price list,
 *  record/settle transactions and even bundle its claim — all scoped to the token's facility. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facility-desk-core.php';
require_once __DIR__ . '/includes/clinical-core.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function hj($arr, $code = 200) { http_response_code($code); echo json_encode($arr); exit; }

$raw = file_get_contents('php://input');
$in = json_decode((string)$raw, true);
if (!is_array($in)) { $in = array(); }
$tok = trim((string)(isset($in['token']) ? $in['token'] : post('token')));
$action = trim((string)(isset($in['action']) ? $in['action'] : post('action')));
if ($tok === '' || !fac_has_tbl()) hj(array('ok' => false, 'error' => 'missing token / facilities module'), 401);
try { $st = db()->prepare("SELECT * FROM facilities WHERE hmis_token=? AND status='Active'"); $st->execute(array($tok)); $f = $st->fetch(); }
catch (Exception $e) { hj(array('ok' => false, 'error' => 'hmis_token column missing — run migrate.php'), 500); }
if (!$f) hj(array('ok' => false, 'error' => 'invalid token or suspended facility'), 401);
if (!function_exists('phone_norm9')) { function phone_norm9($v) { $d = preg_replace('/\D/', '', (string)$v); if ($d === '') return ''; if (strpos($d, '256') === 0) return $d; if ($d[0] === '0') return '256' . substr($d, 1); if ($d[0] === '7') return '256' . $d; return $d; } }

try {
    if ($action === 'ping') hj(array('ok' => true, 'facility' => $f['name'], 'code' => $f['code'], 'server' => date('c')));
    if ($action === 'price_list') {
        $rows = array(); foreach (fac_price_items(intval($f['id'])) as $p) $rows[] = array('item' => $p['item_name'], 'category' => $p['category'], 'unit' => $p['unit'], 'price' => (float)$p['price'], 'base' => intval($p['is_base']) === 1);
        hj(array('ok' => true, 'count' => count($rows), 'prices' => $rows));
    }
    if ($action === 'member_search') {
        $q = trim((string)(isset($in['q']) ? $in['q'] : '')); if ($q === '') hj(array('ok' => false, 'error' => 'q required'), 400);
        $like = '%' . $q . '%';
        $st = db()->prepare("SELECT id, member_no, full_names, phone_no, mtn_no, airtel_no, deposit_amount, status FROM members WHERE full_names LIKE ? OR member_no LIKE ? OR phone_no LIKE ? OR mtn_no LIKE ? OR airtel_no LIKE ? ORDER BY full_names LIMIT 15");
        $st->execute(array($like, $like, '%' . $q . '%', '%' . phone_norm9($q) . '%', '%' . phone_norm9($q) . '%'));
        $rows = array(); foreach ($st->fetchAll() as $m) $rows[] = array('member_no' => $m['member_no'], 'name' => $m['full_names'], 'status' => $m['status'], 'balance' => member_balance(intval($m['id'])));
        hj(array('ok' => true, 'count' => count($rows), 'members' => $rows));
    }
    if ($action === 'balance') {
        $mno = trim((string)(isset($in['member_no']) ? $in['member_no'] : ''));
        $st = db()->prepare('SELECT * FROM members WHERE member_no=? OR id=?'); $st->execute(array($mno, intval($mno))); $m = $st->fetch();
        if (!$m) hj(array('ok' => false, 'error' => 'member not found'), 404);
        $st = db()->prepare("SELECT withdraw_date d, -amount a, 'bill settled' t FROM withdrawals WHERE member_id=? AND status='Paid' ORDER BY id DESC LIMIT 8"); $st->execute(array(intval($m['id'])));
        $moves = array(); foreach ($st->fetchAll() as $x) $moves[] = array('date' => $x['d'], 'type' => $x['t'], 'amount' => (float)$x['a']);
        $st = db()->prepare('SELECT deposit_date d, amount a FROM deposits WHERE member_id=? ORDER BY id DESC LIMIT 8'); $st->execute(array(intval($m['id'])));
        foreach ($st->fetchAll() as $x) $moves[] = array('date' => $x['d'], 'type' => 'deposit', 'amount' => (float)$x['a']);
        hj(array('ok' => true, 'member_no' => $m['member_no'], 'name' => $m['full_names'], 'balance' => member_balance(intval($m['id'])), 'recent' => $moves));
    }
    if ($action === 'create_transaction') {
        $mno = trim((string)(isset($in['member_no']) ? $in['member_no'] : '')); $mid = intval(isset($in['member_id']) ? $in['member_id'] : 0);
        if ($mno !== '' || $mid > 0) { $st = db()->prepare('SELECT id FROM members WHERE member_no=? OR id=?'); $st->execute(array($mno, $mid)); $mm = $st->fetch(); if (!$mm) hj(array('ok' => false, 'error' => 'member not found'), 404); $mid = intval($mm['id']); }
        $settle = !empty($in['settle']);
        $prices = fac_price_items(intval($f['id'])); $byName = array(); foreach ($prices as $p) $byName[mb_strtolower($p['item_name'])] = $p;
        $lines = array();
        foreach ((array)(isset($in['lines']) ? $in['lines'] : array()) as $l) {
            if (!is_array($l)) continue;
            $nm = trim((string)(isset($l['item']) ? $l['item'] : (isset($l['name']) ? $l['name'] : ''))); if ($nm === '') continue;
            $qty = (float)(isset($l['qty']) ? $l['qty'] : 1); $pr = isset($l['price']) ? (float)$l['price'] : null;
            if ($pr === null) { $k = mb_strtolower($nm); if (isset($byName[$k])) { $pr = (float)$byName[$k]['price']; } }
            if ($pr === null) hj(array('ok' => false, 'error' => 'no price for "' . $nm . '" — give a price or add it to the facility price list'), 400);
            $lines[] = array('name' => $nm, 'qty' => $qty > 0 ? $qty : 1, 'price' => $pr, 'cat' => 'HMIS');
        }
        if (!$lines) hj(array('ok' => false, 'error' => 'lines[] required'), 400);
        $res = fd_record_txn(intval($f['id']), $mid, $lines, $settle, 'hmis:' . $f['code'], mb_substr((string)(isset($in['note']) ? $in['note'] : 'via HMIS'), 0, 250), 'hmis', isset($in['date']) ? $in['date'] : null, isset($in['patient_name']) ? $in['patient_name'] : '');
        hj(array('ok' => $res['ok'], 'txn_id' => $res['txn'], 'amount' => $res['total'], 'detail' => $res['msg']), $res['ok'] ? 200 : 400);
    }
    if ($action === 'generate_claim') {
        $res = fd_generate_claim(intval($f['id']), 'hmis:' . $f['code']);
        hj(array('ok' => $res['ok'], 'claim_no' => $res['claim_no'], 'amount' => $res['amount'], 'transactions' => $res['count'], 'detail' => $res['msg']), $res['ok'] ? 200 : 400);
    }
    if ($action === 'claim_status') {
        $cno = trim((string)(isset($in['claim_no']) ? $in['claim_no'] : ''));
        $st = db()->prepare('SELECT claim_no,status,amount_billed,amount_approved,paid_at,pay_method,pay_ref,invoice_no FROM facility_claims WHERE claim_no=? AND facility_id=?'); $st->execute(array($cno, intval($f['id']))); $c = $st->fetch();
        if (!$c) hj(array('ok' => false, 'error' => 'claim not found for this facility'), 404);
        hj(array('ok' => true, 'claim' => $c));
    }
    if ($action === 'open_encounter') {
        $pm = trim((string)(isset($in['patient_no']) ? $in['patient_no'] : (isset($in['member_no']) ? $in['member_no'] : '')));
        $st = db()->prepare('SELECT * FROM patients WHERE facility_id=? AND (patient_no=? OR national_id=? OR CAST(member_id AS CHAR)=?) ORDER BY id DESC LIMIT 1');
        $mid0 = 0; if ($pm !== '') { $stm = db()->prepare('SELECT id FROM members WHERE member_no=?'); $stm->execute(array($pm)); $mm0 = $stm->fetch(); if ($mm0) $mid0 = intval($mm0['id']); }
        $st->execute(array(intval($f['id']), $pm, $pm, (string)$mid0)); $p = $st->fetch();
        if (!$p) {
            $nm = trim((string)(isset($in['full_names']) ? $in['full_names'] : ($pm !== '' ? 'HIS patient ' . $pm : '')));
            if ($nm === '') hj(array('ok' => false, 'error' => 'patient not found — provide full_names to register a new one'), 400);
            db()->prepare('INSERT INTO patients (facility_id, patient_no, full_names, gender, age_years, phone, national_id, address, next_of_kin, member_id, status, created_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute(array(intval($f['id']), cl_pt_no(intval($f['id'])), mb_substr($nm, 0, 140), (isset($in['gender']) && $in['gender'] === 'Male') ? 'Male' : 'Female', intval(isset($in['age_years']) ? $in['age_years'] : 0), mb_substr((string)(isset($in['phone']) ? $in['phone'] : ''), 0, 30), mb_substr((string)(isset($in['national_id']) ? $in['national_id'] : $pm), 0, 40), '', '', $mid0, 'Active', date('Y-m-d H:i:s'), 'hmis:' . $f['code']));
            $p = array('id' => intval(db()->lastInsertId()), 'patient_no' => 'new', 'full_names' => $nm);
        }
        $res = cl_enc_open(intval($f['id']), intval($p['id']), isset($in['type']) ? $in['type'] : 'OPD', isset($in['department']) ? $in['department'] : 'General OPD', isset($in['clinician']) ? $in['clinician'] : 'HIS', isset($in['note']) ? $in['note'] : 'Opened by HIS', '', 'hmis:' . $f['code']);
        if (!$res[0]) hj(array('ok' => false, 'error' => $res[1]), 400);
        $enc = cl_enc_of($res[2]);
        hj(array('ok' => true, 'encounter_id' => intval($res[2]), 'enc_no' => $enc['enc_no'], 'patient' => array('id' => intval($p['id']), 'no' => $p['patient_no'], 'name' => $p['full_names'])));
    }
    if ($action === 'add_order') {
        $enc = cl_enc_of(intval(isset($in['encounter_id']) ? $in['encounter_id'] : 0), intval($f['id']));
        if (!$enc) hj(array('ok' => false, 'error' => 'encounter not found in this facility'), 404);
        $cat = trim((string)(isset($in['category']) ? $in['category'] : 'Other'));
        $item = trim((string)(isset($in['item']) ? $in['item'] : (isset($in['name']) ? $in['name'] : '')));
        $price = isset($in['price']) ? (float)$in['price'] : -1; $qty = isset($in['qty']) ? (float)$in['qty'] : 1; $unit = 'each';
        if ($item === '' && isset($in['price_id'])) { $st = db()->prepare('SELECT * FROM facility_prices WHERE id=? AND facility_id=?'); $st->execute(array(intval($in['price_id']), intval($f['id']))); $pp = $st->fetch(); if ($pp) { $item = $pp['item_name']; $cat = $pp['category']; $unit = $pp['unit']; if ($price < 0) $price = (float)$pp['price']; } }
        if ($item === '') hj(array('ok' => false, 'error' => 'item (or price_id) required'), 400);
        if ($price < 0) { $lp = fac_price_lookup(intval($f['id']), $item); $price = $lp ? (float)$lp['price'] : 0; }
        $res = cl_enc_add_order($enc, $cat, $item, $unit, $qty, $price, 'hmis:' . $f['code']);
        if (!$res[0]) hj(array('ok' => false, 'error' => $res[1]), 400);
        $complete = !isset($in['complete']) || (bool)$in['complete'];
        if ($complete) cl_enc_mark_order(intval($enc['id']), intval($res[2]), 'Completed', isset($in['result']) ? (string)$in['result'] : '', 'hmis:' . $f['code']);
        hj(array('ok' => true, 'order_id' => intval($res[2]), 'encounter_id' => intval($enc['id']), 'status' => $complete ? 'Completed' : 'Requested'));
    }
    if ($action === 'encounter_detail') {
        $enc = cl_enc_of(intval(isset($in['encounter_id']) ? $in['encounter_id'] : 0), intval($f['id']));
        if (!$enc) hj(array('ok' => false, 'error' => 'encounter not found in this facility'), 404);
        hj(array('ok' => true, 'encounter' => array('enc_no' => $enc['enc_no'], 'status' => $enc['status'], 'type' => $enc['enc_type'], 'patient' => $enc['full_names'], 'claim_id' => intval($enc['claim_id'])), 'orders' => cl_enc_orders(intval($enc['id'])), 'totals' => cl_enc_stats(intval($enc['id']))));
    }
    if ($action === 'close_encounter') {
        $enc = cl_enc_of(intval(isset($in['encounter_id']) ? $in['encounter_id'] : 0), intval($f['id']));
        if (!$enc) hj(array('ok' => false, 'error' => 'encounter not found in this facility'), 404);
        $res = cl_enc_discharge(intval($enc['id']), 'hmis:' . $f['code']);
        $claim = null;
        if ($res[0] && !empty($in['make_claim'])) { $cr = cl_enc_make_claim(intval($enc['id']), (isset($in['claim_mode']) && $in['claim_mode'] === 'draft') ? 'draft' : 'submit', 'hmis:' . $f['code']); if ($cr[0]) $claim = array('claim_no' => $cr[3], 'amount' => $cr[1]); }
        hj(array('ok' => true, 'msg' => $res[1], 'claim' => $claim));
    }
    if ($action === 'wallet_balance' || $action === 'bill_settle') { // v6.1: wallet module retired — historical data stays in the DB
        hj(array('ok' => false, 'retired' => true, 'error' => 'The wallet balance/settlement API was retired in v6.1. Read member deposits via "member_search"/"balance", settle beneficiary bills on the facility Member Clearing screen (receipt + auto-claim are generated there), and submit other claims via "generate_claim".'), 410);
    }
    if ($action === 'eligibility') {
        require_once __DIR__ . '/includes/insurance-core.php';
        $mid = intval(isset($in['member_id']) ? $in['member_id'] : 0);
        $tl = array(); foreach ((array)(isset($in['lines']) ? $in['lines'] : array()) as $l) $tl[] = array('name' => (string)(isset($l['item']) ? $l['item'] : 'service'), 'cat' => (string)(isset($l['category']) ? $l['category'] : 'General'), 'qty' => (float)(isset($l['qty']) ? $l['qty'] : 1), 'price' => (float)(isset($l['price']) ? $l['price'] : 0));
        $er = ins_eligibility($mid, intval($f['id']), $tl, isset($in['date']) ? $in['date'] : null);
        hj(array('ok' => true, 'eligible' => $er['ok'], 'covered' => $er['covered'], 'deductible' => $er['deductible'], 'copay' => $er['copay'], 'excluded' => $er['excluded'], 'gross' => $er['gross'], 'annual_remaining' => $er['annual_remaining'], 'reasons' => $er['reasons']));
    }
    hj(array('ok' => false, 'error' => 'unknown action (ping|price_list|member_search|balance|create_transaction|generate_claim|claim_status|open_encounter|add_order|encounter_detail|close_encounter|eligibility)'), 400);
} catch (Exception $e) { hj(array('ok' => false, 'error' => 'server: ' . $e->getMessage()), 500); }
