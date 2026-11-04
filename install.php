<?php
/**
 * install.php - MedSavu PHP installer.
 * Creates the MySQL (or SQLite) schema, seeds the Super Admin account
 * and settings, then writes config.php.  Delete this file after install.
 */
define('IN_APP', true);
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$done = false;
$err  = '';

$driver = isset($_POST['driver']) ? $_POST['driver'] : 'mysql';
$dbhost = isset($_POST['dbhost']) ? trim($_POST['dbhost']) : 'localhost';
$dbname = isset($_POST['dbname']) ? trim($_POST['dbname']) : 'MeduSavuDb';
$dbuser = isset($_POST['dbuser']) ? trim($_POST['dbuser']) : '';
$dbpass = isset($_POST['dbpass']) ? $_POST['dbpass'] : '';
$admuser = isset($_POST['adminuser']) ? trim($_POST['adminuser']) : 'daniel';
$admpass = (isset($_POST['adminpass']) && $_POST['adminpass'] !== '') ? $_POST['adminpass'] : 'daniel@2026';
$admname = isset($_POST['adminname']) ? trim($_POST['adminname']) : 'Daniel - Super Administrator';

function schema_sql($driver) {
    $auto = ($driver === 'mysql') ? 'INT AUTO_INCREMENT' : 'INTEGER';
    $eng  = ($driver === 'mysql') ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $ts   = ($driver === 'mysql') ? 'DATETIME' : 'TEXT';
    $dt   = ($driver === 'mysql') ? 'DATE' : 'TEXT';
    $dec  = ($driver === 'mysql') ? 'DECIMAL(14,2)' : 'REAL';
    $blob = ($driver === 'mysql') ? 'MEDIUMBLOB' : 'BLOB';
    $tmt = ($driver === 'mysql') ? 'TIME' : 'TEXT';
    $T = array();
    $T['users'] = "CREATE TABLE IF NOT EXISTS users (
        id $auto PRIMARY KEY, username VARCHAR(60) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(120) NOT NULL DEFAULT '', previledge VARCHAR(40) NOT NULL DEFAULT 'User',
        phone VARCHAR(30) DEFAULT '', active TINYINT NOT NULL DEFAULT 1, facility_id INT NOT NULL DEFAULT 0, job_title VARCHAR(60) DEFAULT '', last_login $ts NULL,
        created_at $ts NOT NULL)$eng";
    $T['members'] = "CREATE TABLE IF NOT EXISTS members (
        id $auto PRIMARY KEY, member_no VARCHAR(20) NOT NULL UNIQUE, full_names VARCHAR(140) NOT NULL,
        national_id VARCHAR(40) DEFAULT NULL, gender VARCHAR(10) DEFAULT 'Male', phone_no VARCHAR(30) NOT NULL,
        alt_phone VARCHAR(30) DEFAULT '', address VARCHAR(200) DEFAULT '', email_address VARCHAR(150) DEFAULT '',
        airtel_no VARCHAR(30) DEFAULT '', mtn_no VARCHAR(30) DEFAULT '', lc1_village VARCHAR(100) DEFAULT '',
        district VARCHAR(100) DEFAULT '', card_no VARCHAR(200) DEFAULT '', marital_status VARCHAR(30) DEFAULT '',
        dob $dt NULL, next_of_kin VARCHAR(140) DEFAULT '', next_of_kin_phone VARCHAR(30) DEFAULT '',
        registration_date $dt NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Active', note TEXT,
        reg_by VARCHAR(100) DEFAULT '', deposit_amount $dec NOT NULL DEFAULT 0, photo $blob NULL, portal_username VARCHAR(60) NULL, portal_pass_hash VARCHAR(255) NULL, portal_active TINYINT NOT NULL DEFAULT 0, portal_created_at $ts NULL)$eng";
    $T['audit_trail'] = "CREATE TABLE IF NOT EXISTS audit_trail (
        id $auto PRIMARY KEY, username VARCHAR(100) DEFAULT '', computer VARCHAR(200) DEFAULT '',
        ip VARCHAR(50) DEFAULT '', login_time $tmt, login_date $dt NOT NULL, status VARCHAR(30) DEFAULT '',
        activity VARCHAR(100) DEFAULT '', member_no VARCHAR(50) DEFAULT '', full_names VARCHAR(150) DEFAULT '',
        amount $dec NULL, entry_date $ts NOT NULL)$eng";
    $T['deposits'] = "CREATE TABLE IF NOT EXISTS deposits (
        id $auto PRIMARY KEY, member_id INT NOT NULL, deposit_date $dt NOT NULL, amount $dec NOT NULL DEFAULT 0,
        payment_method VARCHAR(40) DEFAULT 'Cash', reference VARCHAR(80) DEFAULT '', note VARCHAR(255) DEFAULT '',
        entered_by VARCHAR(60) DEFAULT '', created_at $ts NOT NULL)$eng";
    $T['withdrawals'] = "CREATE TABLE IF NOT EXISTS withdrawals (
        id $auto PRIMARY KEY, member_id INT NOT NULL, withdraw_date $dt NOT NULL, amount $dec NOT NULL DEFAULT 0,
        purpose VARCHAR(200) DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'Pending', processed_by VARCHAR(60) DEFAULT '',
        paid_date $dt NULL, note VARCHAR(255) DEFAULT '', created_at $ts NOT NULL,
        patient_no VARCHAR(100) DEFAULT NULL, patient_name VARCHAR(200) DEFAULT NULL,
        invoice_no VARCHAR(100) DEFAULT NULL, hospital_invoice_id INT DEFAULT NULL,
        visit_type VARCHAR(100) DEFAULT NULL, bill_amount $dec DEFAULT NULL, discount_percent DECIMAL(6,2) DEFAULT NULL,
        hospital_days INT DEFAULT NULL, ecard_uid VARCHAR(100) DEFAULT NULL, auth_method VARCHAR(30) DEFAULT NULL,
        auth_reference VARCHAR(100) DEFAULT NULL, auth_at $ts NULL, prev_balance $dec DEFAULT NULL, new_balance $dec DEFAULT NULL)$eng";
    $T['medical_bills'] = "CREATE TABLE IF NOT EXISTS medical_bills (
        id $auto PRIMARY KEY, member_id INT NOT NULL, provider VARCHAR(140) DEFAULT '', bill_date $dt NOT NULL,
        description VARCHAR(255) DEFAULT '', amount $dec NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        approved_by VARCHAR(60) DEFAULT '', paid_date $dt NULL, note VARCHAR(255) DEFAULT '', created_at $ts NOT NULL)$eng";
    $T['beneficiaries'] = "CREATE TABLE IF NOT EXISTS beneficiaries (
        id $auto PRIMARY KEY, member_id INT NOT NULL, full_names VARCHAR(140) NOT NULL, relationship VARCHAR(60) DEFAULT '',
        national_id VARCHAR(40) DEFAULT '', phone_no VARCHAR(30) DEFAULT '', residence VARCHAR(200) DEFAULT '', gender VARCHAR(10) DEFAULT '',
        age VARCHAR(20) DEFAULT '', photo $blob NULL, created_at $ts NOT NULL)$eng";
    $T['cash_deposits'] = "CREATE TABLE IF NOT EXISTS cash_deposits (
        id $auto PRIMARY KEY, member_id INT NOT NULL, amount $dec NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT 'Pending', note VARCHAR(255) DEFAULT '',
        created_at $ts NOT NULL, confirmed_by VARCHAR(60) DEFAULT '', confirmed_at $ts NULL,
        deposit_id INT DEFAULT NULL, reject_reason VARCHAR(255) DEFAULT '')$eng";
    $T['facilities'] = "CREATE TABLE IF NOT EXISTS facilities (
        id $auto PRIMARY KEY, name VARCHAR(140) NOT NULL, code VARCHAR(20) NOT NULL UNIQUE,
        ftype VARCHAR(20) NOT NULL DEFAULT 'Clinic', parent_id INT NOT NULL DEFAULT 0,
        address VARCHAR(200) DEFAULT '', phone VARCHAR(30) DEFAULT '', email VARCHAR(120) DEFAULT '',
        contact_person VARCHAR(120) DEFAULT '', status VARCHAR(16) NOT NULL DEFAULT 'Active',
        features VARCHAR(255) DEFAULT 'claims,pricelist,users,bills,sms', hmis_token VARCHAR(64) DEFAULT '',
        logo VARCHAR(200) DEFAULT '', license_expires $dt NULL, max_users INT NOT NULL DEFAULT 0,
        created_at $ts NOT NULL, created_by VARCHAR(60) DEFAULT '')$eng";
    $T['facility_prices'] = "CREATE TABLE IF NOT EXISTS facility_prices (
        id $auto PRIMARY KEY, facility_id INT NOT NULL, category VARCHAR(60) DEFAULT 'General',
        item_name VARCHAR(140) NOT NULL, unit VARCHAR(30) DEFAULT 'each', price $dec NOT NULL DEFAULT 0,
        is_base INT NOT NULL DEFAULT 0, effective_date $dt NULL, created_at $ts NOT NULL, created_by VARCHAR(60) DEFAULT '')$eng";
    $T['facility_claims'] = "CREATE TABLE IF NOT EXISTS facility_claims (
        id $auto PRIMARY KEY, claim_no VARCHAR(30) NOT NULL UNIQUE, facility_id INT NOT NULL,
        facility_name VARCHAR(140) DEFAULT '', contact VARCHAR(120) DEFAULT '', member_id INT DEFAULT 0,
        patient_name VARCHAR(140) DEFAULT '', visit_date $dt NULL, period_label VARCHAR(40) DEFAULT '',
        amount_billed $dec NOT NULL DEFAULT 0, amount_approved $dec NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'Draft', items_json TEXT, note VARCHAR(255) DEFAULT '',
        invoice_no VARCHAR(60) DEFAULT '', invoice_date $dt NULL, invoice_amount $dec NULL,
        category VARCHAR(40) DEFAULT '', service_date $dt NULL, amount_paid $dec NOT NULL DEFAULT 0,
        submitted_by VARCHAR(60) DEFAULT '', submitted_at $ts NULL, decided_by VARCHAR(60) DEFAULT '',
        decided_at $ts NULL, reject_reason VARCHAR(255) DEFAULT '', paid_at $ts NULL,
        paid_by VARCHAR(60) DEFAULT '', pay_method VARCHAR(30) DEFAULT '', pay_ref VARCHAR(80) DEFAULT '',
        created_at $ts NOT NULL, created_by VARCHAR(60) DEFAULT '')$eng";
    $T['facility_txns'] = "CREATE TABLE IF NOT EXISTS facility_txns (
        id $auto PRIMARY KEY, facility_id INT NOT NULL, facility_name VARCHAR(140) DEFAULT '',
        member_id INT DEFAULT 0, member_no VARCHAR(20) DEFAULT '', patient_name VARCHAR(140) DEFAULT '',
        txn_date $dt NOT NULL, amount $dec NOT NULL DEFAULT 0, lines_json TEXT,
        status VARCHAR(16) NOT NULL DEFAULT 'Unbilled', claim_id INT NOT NULL DEFAULT 0,
        settled INT NOT NULL DEFAULT 0, source VARCHAR(20) DEFAULT 'desk',
        created_by VARCHAR(60) DEFAULT '', created_at $ts NOT NULL, note VARCHAR(255) DEFAULT '')$eng";
    $T['accounts'] = "CREATE TABLE IF NOT EXISTS accounts (
        id $auto PRIMARY KEY, acc_code VARCHAR(20) NOT NULL UNIQUE, acc_name VARCHAR(120) NOT NULL,
        acc_type VARCHAR(20) NOT NULL DEFAULT 'Asset', description VARCHAR(255) DEFAULT '',
        is_system INT NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1, created_at $ts NOT NULL)$eng";
    $T['gl_entries'] = "CREATE TABLE IF NOT EXISTS gl_entries (
        id $auto PRIMARY KEY, entry_date $dt NOT NULL, acc_code VARCHAR(20) NOT NULL,
        debit $dec NOT NULL DEFAULT 0, credit $dec NOT NULL DEFAULT 0, memo VARCHAR(255) DEFAULT '',
        source VARCHAR(30) NOT NULL DEFAULT 'manual', source_id INT NOT NULL DEFAULT 0,
        facility_id INT NOT NULL DEFAULT 0, created_by VARCHAR(60) DEFAULT '', created_at $ts NOT NULL)$eng";
    $T['bank_statements'] = "CREATE TABLE IF NOT EXISTS bank_statements (
        id $auto PRIMARY KEY, acct_code VARCHAR(20) NOT NULL, stmt_date $dt NOT NULL,
        description VARCHAR(200) DEFAULT '', amount $dec NOT NULL DEFAULT 0,
        matched_gl INT NOT NULL DEFAULT 0, matched_at $ts NULL, created_by VARCHAR(60) DEFAULT '', created_at $ts NOT NULL)$eng";
    $T['disbursements'] = "CREATE TABLE IF NOT EXISTS disbursements (
        id $auto PRIMARY KEY, claim_id INT NOT NULL, claim_no VARCHAR(30) DEFAULT '', facility_id INT NOT NULL,
        facility_name VARCHAR(140) DEFAULT '', amount $dec NOT NULL DEFAULT 0, method VARCHAR(30) DEFAULT '',
        reference VARCHAR(80) DEFAULT '', paid_by VARCHAR(60) DEFAULT '', paid_at $ts NOT NULL,
        note VARCHAR(255) DEFAULT '', batch_id INT NOT NULL DEFAULT 0, voucher_no VARCHAR(30) DEFAULT '',
        reversed INT NOT NULL DEFAULT 0, reverse_reason VARCHAR(200) DEFAULT '', created_at $ts NOT NULL)$eng";
    $T['sms_log'] = "CREATE TABLE IF NOT EXISTS sms_log (
        id $auto PRIMARY KEY, member_id INT NULL, phone_no VARCHAR(30) NOT NULL, message TEXT,
        channel VARCHAR(20) NOT NULL DEFAULT 'SMS', status VARCHAR(20) NOT NULL DEFAULT 'Queued',
        response TEXT, sent_by VARCHAR(60) DEFAULT '', sent_at $ts NULL, created_at $ts NOT NULL)$eng";
    $T['activity_log'] = "CREATE TABLE IF NOT EXISTS activity_log (
        id $auto PRIMARY KEY, user_id INT DEFAULT 0, username VARCHAR(60) DEFAULT '', action VARCHAR(255) DEFAULT '',
        record_table VARCHAR(60) DEFAULT '', record_id INT DEFAULT 0, ip VARCHAR(45) DEFAULT '', created_at $ts NOT NULL)$eng";
    $T['facility_modules'] = "CREATE TABLE IF NOT EXISTS facility_modules (
        id $auto PRIMARY KEY, facility_id INT NOT NULL, module_key VARCHAR(30) NOT NULL,
        enabled INT NOT NULL DEFAULT 1, UNIQUE (facility_id, module_key))$eng";
    $T['patients'] = "CREATE TABLE IF NOT EXISTS patients (
        id $auto PRIMARY KEY, facility_id INT NOT NULL, patient_no VARCHAR(30) NOT NULL UNIQUE,
        full_names VARCHAR(140) NOT NULL, gender VARCHAR(8) DEFAULT 'Female', age_years INT NOT NULL DEFAULT 0,
        phone VARCHAR(30) DEFAULT '', national_id VARCHAR(40) DEFAULT '', address VARCHAR(200) DEFAULT '',
        next_of_kin VARCHAR(140) DEFAULT '', member_id INT NOT NULL DEFAULT 0, status VARCHAR(12) NOT NULL DEFAULT 'Active',
        created_at $ts NOT NULL, created_by VARCHAR(60) DEFAULT '')$eng";
    $T['encounters'] = "CREATE TABLE IF NOT EXISTS encounters (
        id $auto PRIMARY KEY, facility_id INT NOT NULL, enc_no VARCHAR(30) NOT NULL UNIQUE, patient_id INT NOT NULL,
        enc_type VARCHAR(12) NOT NULL DEFAULT 'OPD', department VARCHAR(60) DEFAULT '', clinician VARCHAR(80) DEFAULT '',
        room_bed VARCHAR(40) DEFAULT '', visit_date $dt NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'Open',
        started_at $ts NOT NULL, discharged_at $ts NULL, notes VARCHAR(255) DEFAULT '', claim_id INT NOT NULL DEFAULT 0,
        created_by VARCHAR(60) DEFAULT '', created_at $ts NOT NULL)$eng";
    $T['clinical_orders'] = "CREATE TABLE IF NOT EXISTS clinical_orders (
        id $auto PRIMARY KEY, encounter_id INT NOT NULL, facility_id INT NOT NULL, patient_id INT NOT NULL,
        category VARCHAR(40) DEFAULT 'Other', item_name VARCHAR(140) NOT NULL, unit VARCHAR(30) DEFAULT 'each',
        qty DECIMAL(10,2) NOT NULL DEFAULT 1, price $dec NOT NULL DEFAULT 0, status VARCHAR(12) NOT NULL DEFAULT 'Requested',
        result_text TEXT, ordered_by VARCHAR(60) DEFAULT '', ordered_at $ts NOT NULL, completed_by VARCHAR(60) DEFAULT '',
        completed_at $ts NULL, billable INT NOT NULL DEFAULT 1, claim_id INT NOT NULL DEFAULT 0)$eng";
    $T['claim_docs'] = "CREATE TABLE IF NOT EXISTS claim_docs (
        id $auto PRIMARY KEY, claim_id INT NOT NULL, kind VARCHAR(30) DEFAULT 'Other', orig_name VARCHAR(200) DEFAULT '',
        stored_name VARCHAR(120) NOT NULL, mime VARCHAR(80) DEFAULT '', size_bytes INT NOT NULL DEFAULT 0,
        uploaded_by VARCHAR(60) DEFAULT '', uploaded_at $ts NOT NULL)$eng";
    $T['claim_events'] = "CREATE TABLE IF NOT EXISTS claim_events (
        id $auto PRIMARY KEY, claim_id INT NOT NULL, actor VARCHAR(60) DEFAULT '', action VARCHAR(80) DEFAULT '',
        note VARCHAR(255) DEFAULT '', at $ts NOT NULL)$eng";
    $T['claim_item_reviews'] = "CREATE TABLE IF NOT EXISTS claim_item_reviews (
        id $auto PRIMARY KEY, claim_id INT NOT NULL, line_no INT NOT NULL, approved_amount $dec NOT NULL DEFAULT 0,
        reason VARCHAR(200) DEFAULT '', reviewed_by VARCHAR(60) DEFAULT '', reviewed_at $ts NOT NULL, UNIQUE (claim_id, line_no))$eng";
    $T['disb_batches'] = "CREATE TABLE IF NOT EXISTS disb_batches (
        id $auto PRIMARY KEY, title VARCHAR(120) DEFAULT '', batch_date $dt NOT NULL, method VARCHAR(30) DEFAULT '',
        reference VARCHAR(80) DEFAULT '', note VARCHAR(255) DEFAULT '', status VARCHAR(12) NOT NULL DEFAULT 'Open',
        created_by VARCHAR(60) DEFAULT '', created_at $ts NOT NULL)$eng";
    $T['price_history'] = "CREATE TABLE IF NOT EXISTS price_history (
        id $auto PRIMARY KEY, facility_id INT NOT NULL, action VARCHAR(12) DEFAULT 'EDIT', item_name VARCHAR(140) DEFAULT '',
        category VARCHAR(60) DEFAULT '', unit VARCHAR(30) DEFAULT '', old_price $dec NULL, new_price $dec NULL,
        effective_date $dt NULL, changed_by VARCHAR(60) DEFAULT '', changed_at $ts NOT NULL)$eng";
    $T['login_log'] = "CREATE TABLE IF NOT EXISTS login_log (
        id $auto PRIMARY KEY, username VARCHAR(60) DEFAULT '', outcome VARCHAR(8) DEFAULT 'OK', via VARCHAR(16) DEFAULT 'web',
        ip VARCHAR(45) DEFAULT '', at $ts NOT NULL)$eng";
    $T['settings'] = "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(60) PRIMARY KEY, v TEXT)$eng";
    return $T;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($driver === 'sqlite') {
            $path = __DIR__ . '/data/' . preg_replace('/[^A-Za-z0-9_]/', '', $dbname) . '.sqlite';
            if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0775, true);
            $pdo = new PDO('sqlite:' . $path);
            $dbname = $path;
        } else {
            $pdo = new PDO('mysql:host=' . $dbhost . ';dbname=' . $dbname . ';charset=utf8mb4', $dbuser, $dbpass);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach (schema_sql($driver) as $sql) { $pdo->exec($sql); }
        require_once __DIR__ . '/includes/schema50.php';
        foreach (cl50_ddl_map($driver) as $sql) { try { $pdo->exec($sql); } catch (Throwable $e50i) {} }
        require_once __DIR__ . '/includes/schema60.php';
        foreach (cl60_ddl_map($driver) as $sql) { try { $pdo->exec($sql); } catch (Throwable $e60i) {} }
        foreach (array('CREATE INDEX idx_mb_fac ON member_bills (facility_id, status)', 'CREATE INDEX idx_mbi_bill ON member_bill_items (bill_id)', 'CREATE INDEX idx_ds_claim ON disb_schedules (claim_id, status)') as $ix60) { try { $pdo->exec($ix60); } catch (Throwable $e6x) {} }
        // v4.1 MoMo table (fresh installs must not need migrate.php)
        try {
            if ($driver === 'mysql') { $pdo->exec("CREATE TABLE IF NOT EXISTS momo_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, external_id VARCHAR(40) NOT NULL UNIQUE,
                reference_id VARCHAR(64) NULL, phone VARCHAR(20) NOT NULL, amount DECIMAL(14,2) NOT NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'UGX', status VARCHAR(16) NOT NULL DEFAULT 'Pending',
                momo_status VARCHAR(64) DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, request_json TEXT, callback_json TEXT,
                deposit_id INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
                KEY idx_mm_status (status), KEY idx_mm_member (member_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }
            else { $pdo->exec("CREATE TABLE IF NOT EXISTS momo_transactions (
                id INTEGER PRIMARY KEY, member_id INTEGER NOT NULL, external_id TEXT NOT NULL UNIQUE, reference_id TEXT,
                phone TEXT NOT NULL, amount REAL NOT NULL, currency TEXT NOT NULL DEFAULT 'UGX', status TEXT NOT NULL DEFAULT 'Pending',
                momo_status TEXT, reason TEXT, request_json TEXT, callback_json TEXT, deposit_id INTEGER,
                created_at TEXT NOT NULL, updated_at TEXT)"); }
        } catch (Throwable $mmErr) {}

        // v5.0 spec 5: NIN uniqueness is now PER-INSTITUTION (multi-institution beneficiaries) — global index removed.
        try {
            if ($driver === 'mysql') { $pdo->exec('ALTER TABLE members DROP INDEX uniq_nin'); }
            else { $pdo->exec('DROP INDEX IF EXISTS uniq_nin'); }
        } catch (Exception $idxErr) {}

        // seed super admin
        $hash = password_hash($admpass, PASSWORD_DEFAULT);
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username=?');
        $st->execute(array($admuser));
        if (!$st->fetchColumn()) {
            $st = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, previledge, active, created_at) VALUES (?,?,?,\'Super Admin\',1,?)');
            $st->execute(array($admuser, $hash, $admname, date('Y-m-d H:i:s')));
        } else {
            $st = $pdo->prepare("UPDATE users SET password_hash=?, previledge='Super Admin', full_name=? WHERE username=?");
            $st->execute(array($hash, $admname, $admuser));
        }

        // v3.9 seed: HQ facility + its standard additive price list
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM facilities'); $st->execute();
            if (!$st->fetchColumn()) {
                $pdo->prepare("INSERT INTO facilities (name, code, ftype, parent_id, address, phone, email, contact_person, status, features, created_at, created_by) VALUES ('Kisubi Hospital (HQ)','KH-001','Hospital',0,'Kisubi, Wakiso','0392177008','','Scheme Office','Active','claims,pricelist,users,bills,sms',?,'install')")
                    ->execute(array(date('Y-m-d H:i:s')));
                $hqid = intval($pdo->lastInsertId());
                $insp = $pdo->prepare('INSERT INTO facility_prices (facility_id, category, item_name, unit, price, is_base, created_at, created_by) VALUES (?,?,?,?,?,1,?,?)');
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
            ) as $it) { $insp->execute(array($hqid, $it[0], $it[1], $it[2], $it[3], date('Y-m-d H:i:s'), 'install')); }
            }
            /* v4.0: seed chart of accounts */
            $st = $pdo->prepare('SELECT COUNT(*) FROM accounts'); $st->execute();
            if (!$st->fetchColumn()) {
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
                $ains = $pdo->prepare('INSERT INTO accounts (acc_code, acc_name, acc_type, description, is_system, active, created_at) VALUES (?,?,?,?,1,1,?)');
                foreach ($COA as $a) { $ains->execute(array($a[0], $a[1], $a[2], $a[3], date('Y-m-d H:i:s'))); }
            }
        } catch (Exception $facErr) { /* facilities are additive - never block install */ }

        // seed settings
        $seeds = array(
            'hospital_name'    => 'Kisubi Hospital',
            'scheme_name'      => 'Kisubi Hospital Medical Saving Scheme (MedSavu)',
            'currency'         => 'UGX',
            'sms_client'       => '256700541666',
            'sms_username'     => '256700541666',
            'sms_password'     => 'kisubi',
            'sms_sender_id'    => 'KISUBI',
            'sms_api_url'      => 'https://www.smsdual.info/bulksms-api.php',
            'sms_send_mode'    => 'live',
            'whatsapp_phone_id'=> '1375166175674980',
            'smtp_host'        => 'smtp.gmail.com:587',
            'smtp_user'        => 'kisubihospital@gmail.com',
        );
        $setSql = ($driver === 'mysql')
    ? 'INSERT IGNORE INTO settings (k, v) VALUES (?,?)'
    : 'INSERT OR IGNORE INTO settings (k, v) VALUES (?,?)';
$st = $pdo->prepare($setSql);
        foreach ($seeds as $k => $v) { $st->execute(array($k, $v)); }

        // optional demo data so the dashboards are alive on first login
        if (isset($_POST['seed']) && $_POST['seed'] === '1') {
            $st = $pdo->prepare('SELECT COUNT(*) FROM members'); $st->execute(array());
            if (!intval($st->fetchColumn())) {
                $names = array(
                    array('Nakato Sarah', 'F', 'Female', 'Kireka'), array('Mugisha Peter', 'M', 'Male', 'Nsa'),
                    array('Apio Grace', 'F', 'Female', 'Busaabala'), array('Kato James', 'M', 'Male', 'Kisubi'),
                    array('Namono Ruth', 'F', 'Female', 'Nyanononyi'), array('Otim David', 'M', 'Male', 'Seetae'),
                    array('Achipwi Mary', 'F', 'Female', 'Najjera'), array('Ssebulime Fred', 'M', 'Male', 'Tabo Centenary'),
                    array('Kyomaliwaniso Emilia', 'F', 'Female', 'Gombe'), array('Tumusiime Brian', 'M', 'Male', 'Fulero'),
                    array('Acuto Patricia', 'F', 'Female', 'Luzira'), array('Wasswa Ronald', 'M', 'Male', 'Nsoola'),
                );
                $insM = $pdo->prepare('INSERT INTO members (member_no, full_names, national_id, gender, phone_no, address, registration_date, status) VALUES (?,?,?,?,?,?,?,?)');
                $insD = $pdo->prepare("INSERT INTO deposits (member_id, deposit_date, amount, payment_method, reference, entered_by, created_at) VALUES (?,?,?,?,?, 'daniel', ?)");
                $insW = $pdo->prepare("INSERT INTO withdrawals (member_id, withdraw_date, amount, purpose, status, processed_by, paid_date, created_at) VALUES (?,?,?,?,?,?,?,?)");
                $insB = $pdo->prepare("INSERT INTO medical_bills (member_id, provider, bill_date, description, amount, status, paid_date, created_at) VALUES (?,?,?,?,?,?,?,?)");
                $insBen = $pdo->prepare('INSERT INTO beneficiaries (member_id, full_names, relationship, phone_no, created_at) VALUES (?,?,?,?,?)');
                $methods = array('Cash', 'MTN MoMo', 'Airtel Money', 'Bank');
                $providers = array('Kisubi Hospital', 'Case Hospital', 'Uganda Martyrs Hospital', 'International Medical Centre');
                $n = 1;
                foreach ($names as $i => $d) {
                    $mno = 'KMH-' . str_pad($n++, 4, '0', STR_PAD_LEFT);
                    $reg = date('Y-m-d', strtotime('-' . rand(30, 360) . ' days'));
                    $insM->execute(array($mno, $d[0], 'CM' . rand(10000000, 99999999), $d[2], '077' . rand(1000000, 9999999), $d[3], $reg, 'Active'));
                    $mid = intval($pdo->lastInsertId());
                    for ($k = 0; $k < rand(2, 6); $k++) {
                        $ddate = date('Y-m-d', strtotime('-' . rand(0, 330) . ' days'));
                        $insD->execute(array($mid, $ddate, (rand(1, 10) * 100000) + 50000, $methods[array_rand($methods)], 'RCP' . rand(10000, 99999), $ddate . ' 10:00:00'));
                    }
                    if ($i % 3 === 0) {
                        $wd = date('Y-m-d', strtotime('-' . rand(5, 120) . ' days'));
                        $insW->execute(array($mid, $wd, rand(2, 8) * 100000, 'Medical support', 'Paid', 'daniel', $wd, $wd . ' 09:00:00'));
                    }
                    if ($i % 4 === 1) {
                        $bd = date('Y-m-d', strtotime('-' . rand(5, 90) . ' days'));
                        $insB->execute(array($mid, $providers[array_rand($providers)], $bd, 'OPD treatment + drugs', rand(3, 15) * 50000, 'Paid', $bd, $bd . ' 12:00:00'));
                    }
                    if ($i % 2 === 0) $insBen->execute(array($mid, 'Spouse of ' . $d[0], 'Spouse', '077' . rand(1000000, 9999999), date('Y-m-d H:i:s')));
                }
                for ($j = 0; $j < 5; $j++) {
                    $bd = date('Y-m-d', strtotime('-' . rand(0, 60) . ' days'));
                    $stMid = rand(1, count($names));
                    if ($j % 2) $insW->execute(array($stMid, $bd, rand(1, 5) * 100000, 'Pending claim', 'Pending', '', null, $bd . ' 08:00:00'));
                    else $insB->execute(array($stMid, 'Kisubi Hospital', $bd, 'Lab tests', rand(2, 6) * 25000, 'Pending', null, $bd . ' 08:00:00'));
                }
            }
        }

        // write config
        $cfg = "<?php\nif (!defined('IN_APP')) { http_response_code(403); exit('Direct access denied'); }\n";
        $cfg .= "define('DB_DRIVER', '" . addslashes($driver) . "');\n";
        if ($driver === 'mysql') {
            $cfg .= "define('DB_HOST', '" . addslashes($dbhost) . "');\n";
            $cfg .= "define('DB_NAME', '" . addslashes($dbname) . "');\n";
            $cfg .= "define('DB_USER', '" . addslashes($dbuser) . "');\n";
            $cfg .= "define('DB_PASS', '" . addslashes($dbpass) . "');\n";
        } else {
            $cfg .= "define('DB_NAME', '" . addslashes($dbname) . "');\n";
        }
        $cfg .= "define('DB_CHARSET', 'utf8mb4');\n";
        $cfg .= "define('APP_NAME', 'MedSavu');\ndefine('APP_TITLE', 'Kisubi Hospital Medical Saving Scheme');\ndefine('HOSPITAL', 'Kisubi Hospital');\n";
        file_put_contents(__DIR__ . '/config.php', $cfg);
        @chmod(__DIR__ . '/config.php', 0640);

        // give schema50 helpers a live handle during install (bootstrap is deliberately not loaded here)
        if (!function_exists('db')) { $GLOBALS['install_pdo50'] = $pdo; function db() { return $GLOBALS['install_pdo50']; } }
        try { foreach (cl50_alter_all() as $l50) {} foreach (cl50_seed() as $s50) {} } catch (Throwable $e50s) {}
        try { foreach (cl60_alter_all() as $l60) {} foreach (cl60_seed() as $s60) {} } catch (Throwable $e60s) {}

        // v5.0 demo world: satellite facilities + desk users (makes the acceptance batteries reproducible from install alone)
        try {
            require_once __DIR__ . '/includes/schema50.php';
            foreach (array(array('Nsoora Medical Centre', 'NSO-002', 'Clinic', 'Nsoora Village, Entebbe Road', '0390177002'), array('Katosi Health Centre IV', 'KAT-003', 'Health Facility', 'Katosi Landing Site', '0390177003')) as $df) {
                $ex = db()->prepare('SELECT id FROM facilities WHERE name=?'); $ex->execute(array($df[0])); $row = $ex->fetch();
                if (!$row) {
                    db()->prepare("INSERT INTO facilities (name, code, ftype, parent_id, address, phone, email, contact_person, status, features, created_at, created_by) VALUES (?,?,?,0,?,?,?,'Facility Manager','Active','claims,pricelist,users,bills,sms',?,'install')")
                        ->execute(array($df[0], $df[1], $df[2], $df[3], $df[4], 'contact@' . strtolower($df[1]) . '.kisubi.med.ug', date('Y-m-d H:i:s')));
                    $fid50 = intval(db()->lastInsertId());
                    if (function_exists('cl50_init_institution')) { try { cl50_init_institution($fid50); } catch (Throwable $ki) {} }
                    try { $kQ = $pdo->prepare('SELECT COUNT(*) FROM facility_prices WHERE facility_id=? AND is_base=1'); $kQ->execute(array($fid50));
                        if (!intval($kQ->fetchColumn())) { $pdo->prepare('INSERT INTO facility_prices (facility_id, category, item_name, unit, price, is_base, created_at, created_by) SELECT ?, category, item_name, unit, price, 1, ?, ? FROM facility_prices WHERE facility_id=1 AND is_base=1')->execute(array($fid50, date('Y-m-d H:i:s'), 'install')); }
                    } catch (Throwable $ki2) {}
                }
            }
            foreach (array(array('nurse_s', 'clinic@2026', 'Nurse Sarah', 'Nsoora Medical Centre'), array('recep_k', 'katosi@2026', 'Receptionist Kato', 'Katosi Health Centre IV')) as $du) {
                $ck = db()->prepare('SELECT 1 FROM users WHERE username=?'); $ck->execute(array($du[0]));
                if (!$ck->fetch()) {
                    $fq = db()->prepare('SELECT id FROM facilities WHERE name=?'); $fq->execute(array($du[3])); $fid = intval($fq->fetchColumn());
                    db()->prepare('INSERT INTO users (username, password_hash, full_name, previledge, active, facility_id, created_at) VALUES (?,?,?,?,1,?,?)')
                        ->execute(array($du[0], password_hash($du[1], PASSWORD_DEFAULT), $du[2], 'Reception', $fid, date('Y-m-d H:i:s')));
                }
            }
        try {
            if ($driver === 'sqlite') { $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_portal_username ON members(portal_username)'); }
            else { try { $pdo->exec('ALTER TABLE members ADD UNIQUE KEY uniq_portal_username (portal_username)'); } catch (Throwable $pi) {} }
            $pm = $pdo->prepare("UPDATE members SET portal_username=?, portal_pass_hash=?, portal_active=1, portal_created_at=? WHERE id=1 AND (portal_username IS NULL OR portal_username='')");
            $pm->execute(array('aisha', password_hash('aisha@2026', PASSWORD_DEFAULT), date('Y-m-d H:i:s')));
        } catch (Throwable $poErr) {}
        } catch (Throwable $demoErr) {}
        // keep the members.deposit_amount column in sync with the seeded ledger (fresh-install integrity)
        try {
            $pdo->exec("UPDATE members SET deposit_amount =
                (SELECT COALESCE(SUM(amount),0) FROM deposits WHERE member_id=members.id) -
                (SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE member_id=members.id AND status='Paid') -
                (SELECT COALESCE(SUM(amount),0) FROM medical_bills WHERE member_id=members.id AND status='Paid')");
        } catch (Throwable $balErr) {}        // demo HMIS tokens so every seeded facility can use the API out of the box (rotate from the facility desk)
        try {
            $tks = $pdo->query('SELECT id FROM facilities')->fetchAll(PDO::FETCH_COLUMN);
            $tup = $pdo->prepare("UPDATE facilities SET hmis_token=? WHERE id=? AND (hmis_token IS NULL OR hmis_token='')");
            foreach ($tks as $tkId) { $tup->execute(array(bin2hex(random_bytes(8)), intval($tkId))); }
        } catch (Throwable $tkE) {}
        $done = true;
    } catch (Throwable $ex) {
        $err = get_class($ex) . ': ' . $ex->getMessage() . ' @line ' . $ex->getLine();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MedSavu Installer</title>
<style>
 body{font-family:Georgia,'Times New Roman',serif;background:#EAF2FA;margin:0;color:#1b3a57}
 .wrap{max-width:640px;margin:40px auto;background:#fff;border:1px solid #bcd6ea;padding:0}
 .hd{background:linear-gradient(180deg,#1B6BB3,#12476f);color:#fff;padding:22px 30px}
 .hd h1{margin:0;font-size:22px}.hd p{margin:6px 0 0;color:#cfe4f7;font-size:13px}
 .bd{padding:26px 30px}
 label{display:block;font-size:12px;font-weight:bold;margin:14px 0 4px;color:#12476f}
 input,select{width:100%;padding:9px;border:1px solid #bcd6ea;font-size:14px;font-family:inherit;box-sizing:border-box;background:#fbfdff}
 .btn{display:inline-block;margin-top:22px;background:#1B6BB3;color:#fff;border:0;padding:12px 26px;font-size:15px;cursor:pointer;font-family:inherit}
 .ok{background:#e6f6ea;border:1px solid #9fd8ae;padding:14px;margin:18px 0}
 .bad{background:#fdecec;border:1px solid #e8b4b4;padding:14px;margin:18px 0}
 .note{font-size:12px;color:#5b7c99;margin-top:18px;line-height:1.6}
</style>
</head>
<body>
<div class="wrap">
  <div class="hd"><h1>⚕ MedSavu &mdash; Installation Wizard</h1><p>Kisubi Hospital Medical Saving Scheme &middot; PHP + MySQL</p></div>
  <div class="bd">
  <?php if ($done): ?>
    <div class="ok"><b>Installation complete!</b><br>
      Database created, Super Admin <b><?php echo e($admuser); ?></b> ready.</div>
    <p><a class="btn" style="text-decoration:none" href="login.php">Go to Login &raquo;</a></p>
    <p class="note"><b>Security:</b> delete <code>install.php</code> now, and change the admin password after first login.</p>
  <?php else: ?>
    <?php if ($err): ?><div class="bad"><b>Could not install:</b> <?php echo e($err); ?>
    <br><br><b>On cPanel:</b> first create the database + user in <i>MySQL® Databases</i>
    (e.g. <code><?php echo e($dbname); ?></code> usually becomes <code>cpaneluser_<?php echo e($dbname); ?></code>),
    grant the user ALL PRIVILEGES on it, then use those exact names here.</div><?php endif; ?>
    <form method="post">
      <label>Database engine</label>
      <select name="driver">
        <option value="mysql" <?php echo $driver=='mysql'?'selected':''; ?>>MySQL / MariaDB (recommended)</option>
        <option value="sqlite" <?php echo $driver=='sqlite'?'selected':''; ?>>SQLite (demo / testing only)</option>
      </select>
      <label>Database host</label><input name="dbhost" value="<?php echo e($dbhost); ?>">
      <label>Database name</label><input name="dbname" value="<?php echo e($dbname); ?>">
      <label>Database username</label><input name="dbuser" value="<?php echo e($dbuser); ?>">
      <label>Database password</label><input name="dbpass" type="password" value="">
      <hr style="border:0;border-top:1px solid #e2eefb;margin:24px 0">
      <label>Super Admin full name</label><input name="adminname" value="<?php echo e($admname); ?>">
      <label>Super Admin username</label><input name="adminuser" value="<?php echo e($admuser); ?>">
      <label>Super Admin password</label><input name="adminpass" value="daniel@2026">
      <label style="font-weight:normal;text-transform:none;font-size:13px;margin-top:16px">
        <input type="checkbox" name="seed" value="1" style="width:auto" checked> Seed sample members/transactions so dashboards look alive (you can delete them later)</label>
      <button class="btn" type="submit">Install MedSavu Now</button>
    </form>
    <p class="note">Tables created: <b>users, members, deposits, withdrawals, medical_bills, beneficiaries, sms_log, activity_log, settings</b>.
    The login <b>daniel / daniel@2026</b> becomes Super Admin with every privilege.</p>
  <?php endif; ?>
  </div>
</div>
</body>
</html>
