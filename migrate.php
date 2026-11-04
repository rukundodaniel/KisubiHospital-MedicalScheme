<?php
/**
 * One-shot migrations (safe to re-run):
 *  1) member portal fields
 *  2) momo_transactions table (reference_id VARCHAR(64))
 *  3) registration extensions: residence/email/Airtel+MTN/LC1/district/NIN/card/
 *     marital/DOB/reg_by/deposit_amount/photo + audit_trail table + unique NIN
 * Delete this file after running.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$driver = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
$out = array(); $err = '';

function col_exists($table, $col) {
    $drv = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
    try {
        if ($drv === 'mysql') {
            $st = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $st->execute(array($table, $col)); return intval($st->fetchColumn()) > 0;
        }
        foreach (db()->query("PRAGMA table_info($table)") as $r) if (strcasecmp($r['name'], $col) === 0) return true;
        return false;
    } catch (Exception $e) { return false; }
}
function idx_exists($name) {
    $drv = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
    try {
        if ($drv === 'mysql') {
            $st = db()->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND index_name=?");
            $st->execute(array($name)); return intval($st->fetchColumn()) > 0;
        }
        $st = db()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name=?");
        $st->execute(array($name)); return intval($st->fetchColumn()) > 0;
    } catch (Exception $e) { return true; }
}
function addCol($table, $col, $type, $label) {
    global $out;
    if (col_exists($table, $col)) { $out[] = "$table.$col exists"; return; }
    db()->exec("ALTER TABLE $table ADD COLUMN $col $type");
    $out[] = "$table.$col added";
}

try {
    // 1) portal
    addCol('members', 'portal_username',   ($driver==='mysql')?'VARCHAR(60) NULL':'TEXT', 'portal');
    addCol('members', 'portal_pass_hash',  ($driver==='mysql')?'VARCHAR(255) NULL':'TEXT', 'portal');
    addCol('members', 'portal_active',     ($driver==='mysql')?'TINYINT NOT NULL DEFAULT 0':'INTEGER NOT NULL DEFAULT 0', 'portal');
    addCol('members', 'portal_created_at', ($driver==='mysql')?'DATETIME NULL':'TEXT', 'portal');
    if ($driver==='sqlite' && !idx_exists('uniq_portal_username')) db()->exec('CREATE UNIQUE INDEX uniq_portal_username ON members(portal_username)');

    // 2) momo
    if ($driver === 'mysql') {
        db()->exec("CREATE TABLE IF NOT EXISTS momo_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, external_id VARCHAR(40) NOT NULL UNIQUE,
            reference_id VARCHAR(64) NULL, phone VARCHAR(20) NOT NULL, amount DECIMAL(14,2) NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'UGX', status VARCHAR(16) NOT NULL DEFAULT 'Pending',
            momo_status VARCHAR(64) DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, request_json TEXT, callback_json TEXT,
            deposit_id INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
            KEY idx_mm_status (status), KEY idx_mm_member (member_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        db()->exec("CREATE TABLE IF NOT EXISTS momo_transactions (
            id INTEGER PRIMARY KEY, member_id INTEGER NOT NULL, external_id TEXT NOT NULL UNIQUE, reference_id TEXT,
            phone TEXT NOT NULL, amount REAL NOT NULL, currency TEXT NOT NULL DEFAULT 'UGX', status TEXT NOT NULL DEFAULT 'Pending',
            momo_status TEXT, reason TEXT, request_json TEXT, callback_json TEXT, deposit_id INTEGER,
            created_at TEXT NOT NULL, updated_at TEXT)");
    }
    $out[] = 'momo_transactions ready';

    // 3) registration extensions
    $dt = ($driver==='mysql')?'DATE':'TEXT'; $dec = ($driver==='mysql')?'DECIMAL(14,2)':'REAL'; $blob = ($driver==='mysql')?'MEDIUMBLOB':'BLOB'; $tmt = ($driver==='mysql')?'TIME':'TEXT'; $ts = ($driver==='mysql')?'DATETIME':'TEXT';
    addCol('members', 'place_of_residence', 'VARCHAR(200) DEFAULT NULL', 'reg');   // reuse address col? keep both, prefer address
    addCol('members', 'email_address',  'VARCHAR(150) DEFAULT NULL', 'reg');
    addCol('members', 'airtel_no',      'VARCHAR(30) DEFAULT NULL', 'reg');
    addCol('members', 'mtn_no',         'VARCHAR(30) DEFAULT NULL', 'reg');
    addCol('members', 'lc1_village',    'VARCHAR(100) DEFAULT NULL', 'reg');
    addCol('members', 'district',       'VARCHAR(100) DEFAULT NULL', 'reg');
    addCol('members', 'card_no',        'VARCHAR(200) DEFAULT NULL', 'reg');
    addCol('members', 'marital_status', 'VARCHAR(30) DEFAULT NULL', 'reg');
    addCol('members', 'dob',            "$dt NULL", 'reg');
    addCol('members', 'reg_by',         'VARCHAR(100) DEFAULT NULL', 'reg');
    addCol('members', 'deposit_amount', "$dec NOT NULL DEFAULT 0", 'reg');
    addCol('members', 'photo',          "$blob NULL", 'reg');
    // backfill mtn/airtel from legacy columns (normalized to 256...) so the list works right away
    function norm_ph($v) {
        $d = preg_replace('/\\D/', '', (string)$v);
        if ($d === '') return '';
        if (strpos($d, '256') === 0) return $d;
        if ($d[0] === '0') return '256' . substr($d, 1);
        if ($d[0] === '7') return '256' . $d;
        return $d;
    }
    $n1 = db()->prepare("UPDATE members SET mtn_no=? WHERE id=?");
    foreach (db()->query("SELECT id, phone_no FROM members WHERE (mtn_no IS NULL OR mtn_no='') AND phone_no IS NOT NULL AND phone_no<>''") as $r) {
        $v = norm_ph($r['phone_no']); if ($v !== '') $n1->execute(array($v, $r['id']));
    }
    $n2 = db()->prepare("UPDATE members SET airtel_no=? WHERE id=?");
    foreach (db()->query("SELECT id, alt_phone FROM members WHERE (airtel_no IS NULL OR airtel_no='') AND alt_phone IS NOT NULL AND alt_phone<>''") as $r) {
        $v = norm_ph($r['alt_phone']); if ($v !== '') $n2->execute(array($v, $r['id']));
    }
    $out[] = 'phone backfill normalized';
    db()->exec('UPDATE members SET national_id=NULL WHERE national_id=\'\'');

    // beneficiaries: BeneficiallyTable parity (residence/gender/age/photo)
    addCol('beneficiaries', 'residence', 'VARCHAR(200) DEFAULT NULL', 'ben');
    addCol('beneficiaries', 'gender',    'VARCHAR(10) DEFAULT NULL', 'ben');
    addCol('beneficiaries', 'age',       'VARCHAR(20) DEFAULT NULL', 'ben');
    addCol('beneficiaries', 'photo',     "$blob NULL", 'ben');
    $out[] = 'beneficiaries extended';

    if ($driver === 'mysql') {
        db()->exec("CREATE TABLE IF NOT EXISTS audit_trail (
            id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100) DEFAULT '', computer VARCHAR(200) DEFAULT '',
            ip VARCHAR(50) DEFAULT '', login_time TIME, login_date DATE NOT NULL, status VARCHAR(30) DEFAULT '',
            activity VARCHAR(100) DEFAULT '', member_no VARCHAR(50) DEFAULT '', full_names VARCHAR(150) DEFAULT '',
            entry_date DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        db()->exec("CREATE TABLE IF NOT EXISTS audit_trail (
            id INTEGER PRIMARY KEY, username TEXT DEFAULT '', computer TEXT DEFAULT '', ip TEXT DEFAULT '',
            login_time TEXT, login_date TEXT NOT NULL, status TEXT DEFAULT '', activity TEXT DEFAULT '',
            member_no TEXT DEFAULT '', full_names TEXT DEFAULT '', entry_date TEXT NOT NULL)");
    }
    $out[] = 'audit_trail ready';
    addCol('audit_trail', 'amount', (($driver==='mysql')?'DECIMAL(14,2)':'REAL') . ' NULL', 'audit');
    $out[] = 'audit_trail.amount ready';

    // 4) settlement desk (MakeATransactionForm twin) columns on withdrawals
    $vc = ($driver==='mysql')?'VARCHAR':'TEXT'; $int = ($driver==='mysql')?'INT':'INTEGER'; $d2 = ($driver==='mysql')?'DECIMAL(6,2)':'REAL';
    addCol('withdrawals', 'patient_no',          "VARCHAR(100) DEFAULT NULL", 'settle');
    addCol('withdrawals', 'patient_name',        "VARCHAR(200) DEFAULT NULL", 'settle');
    addCol('withdrawals', 'invoice_no',          "VARCHAR(100) DEFAULT NULL", 'settle');
    addCol('withdrawals', 'hospital_invoice_id', "$int NULL", 'settle');
    addCol('withdrawals', 'visit_type',          "VARCHAR(100) DEFAULT NULL", 'settle');
    addCol('withdrawals', 'bill_amount',         "$dec NULL", 'settle');
    addCol('withdrawals', 'discount_percent',    "$d2 NULL", 'settle');
    addCol('withdrawals', 'hospital_days',       "$int NULL", 'settle');
    addCol('withdrawals', 'ecard_uid',           "VARCHAR(100) DEFAULT NULL", 'settle');
    addCol('withdrawals', 'auth_method',         "VARCHAR(30) DEFAULT NULL", 'settle');
    addCol('withdrawals', 'auth_reference',      "VARCHAR(100) DEFAULT NULL", 'settle');
    addCol('withdrawals', 'auth_at',             "$ts NULL", 'settle');
    addCol('withdrawals', 'prev_balance',        "$dec NULL", 'settle');
    addCol('withdrawals', 'new_balance',         "$dec NULL", 'settle');
    $out[] = 'withdrawals settlement columns ready';

    // 5) portal cash declarations
    if ($driver==='mysql') {
        db()->exec("CREATE TABLE IF NOT EXISTS cash_deposits (
            id INT AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'Pending', note VARCHAR(255) DEFAULT '',
            created_at DATETIME NOT NULL, confirmed_by VARCHAR(60) DEFAULT '', confirmed_at DATETIME NULL,
            deposit_id INT DEFAULT NULL, reject_reason VARCHAR(255) DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        db()->exec("CREATE TABLE IF NOT EXISTS cash_deposits (
            id INTEGER PRIMARY KEY, member_id INTEGER NOT NULL, amount REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'Pending', note TEXT DEFAULT '',
            created_at TEXT NOT NULL, confirmed_by TEXT DEFAULT '', confirmed_at TEXT,
            deposit_id INTEGER, reject_reason TEXT)");
    }
    $out[] = 'cash_deposits ready';

    // 6) v3.9 facilities / claims / price lists / disbursements
    $dec9 = ($driver==='mysql') ? 'DECIMAL(14,2)' : 'REAL';
    $ts9  = ($driver==='mysql') ? 'DATETIME' : 'TEXT';
    $dt9  = ($driver==='mysql') ? 'DATE' : 'TEXT';
    $id9  = ($driver==='mysql') ? 'id INT AUTO_INCREMENT PRIMARY KEY' : 'id INTEGER PRIMARY KEY';
    foreach (array(
      "CREATE TABLE IF NOT EXISTS facilities (
        $id9, name VARCHAR(140) NOT NULL, code VARCHAR(20) NOT NULL UNIQUE,
        ftype VARCHAR(20) NOT NULL DEFAULT 'Clinic', parent_id INT NOT NULL DEFAULT 0,
        address VARCHAR(200) DEFAULT '', phone VARCHAR(30) DEFAULT '', email VARCHAR(120) DEFAULT '',
        contact_person VARCHAR(120) DEFAULT '', status VARCHAR(16) NOT NULL DEFAULT 'Active',
        features VARCHAR(255) DEFAULT 'claims,pricelist,users,bills,sms',
        created_at $ts9 NOT NULL, created_by VARCHAR(60) DEFAULT '')" . ($driver==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''),
      "CREATE TABLE IF NOT EXISTS facility_prices (
        $id9, facility_id INT NOT NULL, category VARCHAR(60) DEFAULT 'General',
        item_name VARCHAR(140) NOT NULL, unit VARCHAR(30) DEFAULT 'each', price $dec9 NOT NULL DEFAULT 0,
        is_base INT NOT NULL DEFAULT 0, created_at $ts9 NOT NULL, created_by VARCHAR(60) DEFAULT '')" . ($driver==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''),
      "CREATE TABLE IF NOT EXISTS facility_claims (
        $id9, claim_no VARCHAR(30) NOT NULL UNIQUE, facility_id INT NOT NULL,
        facility_name VARCHAR(140) DEFAULT '', contact VARCHAR(120) DEFAULT '', member_id INT DEFAULT 0,
        patient_name VARCHAR(140) DEFAULT '', visit_date $dt9 NULL, period_label VARCHAR(40) DEFAULT '',
        amount_billed $dec9 NOT NULL DEFAULT 0, amount_approved $dec9 NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'Draft', items_json TEXT, note VARCHAR(255) DEFAULT '',
        submitted_by VARCHAR(60) DEFAULT '', submitted_at $ts9 NULL, decided_by VARCHAR(60) DEFAULT '',
        decided_at $ts9 NULL, reject_reason VARCHAR(255) DEFAULT '', paid_at $ts9 NULL,
        paid_by VARCHAR(60) DEFAULT '', pay_method VARCHAR(30) DEFAULT '', pay_ref VARCHAR(80) DEFAULT '',
        created_at $ts9 NOT NULL, created_by VARCHAR(60) DEFAULT '')" . ($driver==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''),
      "CREATE TABLE IF NOT EXISTS disbursements (
        $id9, claim_id INT NOT NULL, claim_no VARCHAR(30) DEFAULT '', facility_id INT NOT NULL,
        facility_name VARCHAR(140) DEFAULT '', amount $dec9 NOT NULL DEFAULT 0, method VARCHAR(30) DEFAULT '',
        reference VARCHAR(80) DEFAULT '', paid_by VARCHAR(60) DEFAULT '', paid_at $ts9 NOT NULL,
        note VARCHAR(255) DEFAULT '', created_at $ts9 NOT NULL)" . ($driver==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''),
    ) as $sql9) { db()->exec($sql9); }
    $out[] = 'facilities tables ready';
    addCol('users', 'facility_id', ($driver==='mysql') ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0', 'facilities');
    try { db()->exec('UPDATE users SET facility_id=0 WHERE facility_id IS NULL'); } catch (Exception $e) {}
    try {
        if (!db()->query('SELECT COUNT(*) FROM facilities')->fetchColumn()) {
            db()->prepare("INSERT INTO facilities (name, code, ftype, parent_id, address, phone, email, contact_person, status, features, created_at, created_by) VALUES ('Kisubi Hospital (HQ)','KH-001','Hospital',0,'Kisubi, Wakiso','0392177008','','Scheme Office','Active','claims,pricelist,users,bills,sms',?,'migrate')")
               ->execute(array(date('Y-m-d H:i:s')));
            $hqid = intval(db()->lastInsertId());
            $insp = db()->prepare('INSERT INTO facility_prices (facility_id, category, item_name, unit, price, is_base, created_at, created_by) VALUES (?,?,?,?,?,1,?,?)');
            foreach (array(
                array('OPD','Consultation (general)','per visit',10000),
                array('OPD','Fast-track consultation','per visit',20000),
                array('OPD','Nurse triage','per visit',5000),
                array('Laboratory','Full blood count','per test',25000),
                array('Laboratory','Malaria RDT','per test',10000),
                array('Laboratory','Blood group & RDS','per test',15000),
                array('Laboratory','Urinalysis','per test',10000),
                array('Laboratory','Fasting blood sugar','per test',8000),
                array('Radiology','Ultrasound abdomen','per scan',60000),
                array('Radiology','X-ray chest','per film',40000),
                array('Pharmacy','Dispensing fee (generic)','per script',3000),
                array('Admission','Ward bed','per night',40000),
                array('Admission','OT theatre','per hour',150000),
                array('Admission','Maternity package','per case',250000),
                array('Admission','ICU bed','per night',180000),
            ) as $it) { $insp->execute(array($hqid, $it[0], $it[1], $it[2], $it[3], date('Y-m-d H:i:s'), 'migrate')); }
            $out[] = 'HQ facility + base price list seeded';
        } else { $out[] = 'facilities seeded already'; }
    } catch (Exception $e) { $out[] = 'facilities seed skipped: ' . $e->getMessage(); }

    // 7) v4.0 accounting suite + facility desk + HMIS gateway
    foreach (array(
      "CREATE TABLE IF NOT EXISTS facility_txns (
        $id9, facility_id INT NOT NULL, facility_name VARCHAR(140) DEFAULT '',
        member_id INT DEFAULT 0, member_no VARCHAR(20) DEFAULT '', patient_name VARCHAR(140) DEFAULT '',
        txn_date $dt9 NOT NULL, amount $dec9 NOT NULL DEFAULT 0, lines_json TEXT,
        status VARCHAR(16) NOT NULL DEFAULT 'Unbilled', claim_id INT NOT NULL DEFAULT 0,
        settled INT NOT NULL DEFAULT 0, source VARCHAR(20) DEFAULT 'desk',
        created_by VARCHAR(60) DEFAULT '', created_at $ts9 NOT NULL, note VARCHAR(255) DEFAULT '')" . ($driver==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''),
      "CREATE TABLE IF NOT EXISTS accounts (
        $id9, acc_code VARCHAR(20) NOT NULL UNIQUE, acc_name VARCHAR(120) NOT NULL,
        acc_type VARCHAR(20) NOT NULL DEFAULT 'Asset', description VARCHAR(255) DEFAULT '',
        is_system INT NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, created_at $ts9 NOT NULL)" . ($driver==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''),
      "CREATE TABLE IF NOT EXISTS gl_entries (
        $id9, entry_date $dt9 NOT NULL, acc_code VARCHAR(20) NOT NULL,
        debit $dec9 NOT NULL DEFAULT 0, credit $dec9 NOT NULL DEFAULT 0, memo VARCHAR(255) DEFAULT '',
        source VARCHAR(30) NOT NULL DEFAULT 'manual', source_id INT NOT NULL DEFAULT 0,
        facility_id INT NOT NULL DEFAULT 0, created_by VARCHAR(60) DEFAULT '', created_at $ts9 NOT NULL)" . ($driver==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''),
      "CREATE TABLE IF NOT EXISTS bank_statements (
        $id9, acct_code VARCHAR(20) NOT NULL, stmt_date $dt9 NOT NULL,
        description VARCHAR(200) DEFAULT '', amount $dec9 NOT NULL DEFAULT 0,
        matched_gl INT NOT NULL DEFAULT 0, matched_at $ts9 NULL, created_by VARCHAR(60) DEFAULT '', created_at $ts9 NOT NULL)" . ($driver==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''),
    ) as $sql9) { db()->exec($sql9); }
    $out[] = 'accounting & desk tables ready';
    addCol('facilities', 'hmis_token', ($driver==='mysql') ? "VARCHAR(64) DEFAULT ''" : 'TEXT', 'hmis');
    addCol('facility_claims', 'invoice_no',     ($driver==='mysql') ? "VARCHAR(60) DEFAULT ''" : 'TEXT', 'claims');
    addCol('facility_claims', 'invoice_date',   $dt9 . ' NULL', 'claims');
    addCol('facility_claims', 'invoice_amount', $dec9 . ' NULL', 'claims');
    addCol('facility_txns',   'settled',        ($driver==='mysql') ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0', 'claims');
    try {
        if (!db()->query('SELECT COUNT(*) FROM accounts')->fetchColumn()) {
            $COA = array(
                    array('1000','Cash on Hand — Scheme Desk','Asset','Physical cash at the cashier till'),
                    array('1010','Bank Account — Stanbic','Asset','Primary scheme bank account'),
                    array('1020','MTN MoMo Merchant Wallet','Asset','Mobile money collections'),
                    array('1030','Airtel Money Merchant Wallet','Asset','Mobile money collections'),
                    array('1500','Cash & Bank — Clearing','Asset','In-transit collections'),
                    array('2000','Member Deposit Accounts','Liability','What the scheme owes members (their savings)'),
                    array('2100','Unsettled Facility Claims','Liability','Claims approved/awaiting disbursement to hospitals & clinics'),
                    array('2900','Sundry Creditors','Liability','Operating payables'),
                    array('3000','Owner Capital / Scheme Reserve','Equity','Opening & injected capital'),
                    array('3100','Retained Surplus','Equity','Accumulated net surplus'),
                    array('4000','Float & Interest Income','Income','Bank float interest, MoMo tiers'),
                    array('4100','Membership & Admin Fees','Income','Joining fees, statement fees'),
                    array('4900','Lapsed Deposit Forfeitures','Income','Forfeited dormant balances'),
                    array('5000','Benefit Payouts to Facilities','Expense','Member bill settlements (from deposits)'),
                    array('5100','Operating Expenses','Expense','Salaries, printing, data, rent'),
                    array('5200','Bank & MoMo Charges','Expense','Transaction charges'),
                    array('5900','Other Expenses','Expense','Miscellaneous'),
                );
            $ains = db()->prepare('INSERT INTO accounts (acc_code, acc_name, acc_type, description, is_system, active, created_at) VALUES (?,?,?,?,1,1,?)');
            foreach ($COA as $a) { $ains->execute(array($a[0], $a[1], $a[2], $a[3], date('Y-m-d H:i:s'))); }
            $out[] = 'chart of accounts seeded (' . count($COA) . ')';
        } else { $out[] = 'accounts seeded already'; }
    } catch (Exception $e) { $out[] = 'coa seed skipped: ' . $e->getMessage(); }
    try {
        if (!idx_exists('uniq_nin')) {
            db()->exec('CREATE UNIQUE INDEX uniq_nin ON members(national_id)');
        }
        $out[] = 'NIN uniqueness ready';
    } catch (Exception $e) {
        $out[] = 'WARNING: unique NIN index skipped (duplicate/empty NINs exist). Cleanup optional.';
    }

    try {
        require_once __DIR__ . '/includes/schema41.php';
        $drv41 = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (cl41_create_all($drv41) as $t41) $out[] = "v4.1 table $t41 ready";
        foreach (cl41_alter_all() as $l41) $out[] = 'v4.1 ' . $l41;
    } catch (Exception $e41) { $out[] = 'v4.1 schema issue: ' . $e41->getMessage(); }
    try {
        require_once __DIR__ . '/includes/schema50.php';
        $drv50 = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (cl50_create_all($drv50) as $t50) $out[] = "v5.0 table $t50 ready";
        foreach (cl50_alter_all() as $l50) $out[] = 'v5.0 ' . $l50;
        foreach (cl50_seed() as $s50) $out[] = 'v5.0 ' . $s50;
    } catch (Exception $e50) { $out[] = 'v5.0 schema issue: ' . $e50->getMessage(); }
    try {
        require_once __DIR__ . '/includes/schema60.php';
        $drv60 = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (cl60_create_all($drv60) as $t60) $out[] = "v6.0 table $t60 ready";
        foreach (cl60_alter_all() as $l60) $out[] = 'v6.0 ' . $l60;
        foreach (cl60_seed() as $s60) $out[] = 'v6.0 ' . $s60;
    } catch (Exception $e60) { $out[] = 'v6.0 schema issue: ' . $e60->getMessage(); }
    echo "<div style='font-family:Georgia;padding:30px;background:#EAF2FA'><h2 style='color:#12476f'>Migration done ✔</h2><ul>";
    foreach ($out as $l) echo '<li>' . htmlspecialchars($l) . '</li>';
    echo '</ul><p><b>Delete migrate.php from the server now.</b> Then reload the Members page.</p></div>';
} catch (Throwable $ex) {
    // unique index may fail with existing duplicate NINs - not fatal for the rest
    if (strpos($ex->getMessage(), 'uniq_nin') !== false || stripos($ex->getMessage(), 'Duplicate entry') !== false) {
        $out[] = 'WARNING: unique NIN index skipped (duplicate/empty NINs exist). Cleanup optional.';
        echo "<div style='font-family:Georgia;padding:30px;background:#EAF2FA'><h2 style='color:#12476f'>Migration done (with warning) ⚠</h2><ul>";
        foreach ($out as $l) echo '<li>' . htmlspecialchars($l) . '</li></ul><p>Delete migrate.php now.</p></div>';
        exit;
    }
    echo "<div style='font-family:Georgia;padding:30px'><h2 style='color:#B23A3A'>Migration issue</h2><pre>" . htmlspecialchars($ex->getMessage()) . '</pre>';
    foreach ($out as $l) echo '<div>done before error: ' . htmlspecialchars($l) . '</div>';
    echo '</div>';
}
