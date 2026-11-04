═══════════════════════════════════════════════════════════════════════════
 MedSavu v3.0 - KISUBI HOSPITAL MEDICAL SAVING SCHEME (PHP + MySQL)
 INSTALLATION GUIDE FOR cPanel  (www.kisubihospital.co.ug)
═══════════════════════════════════════════════════════════════════════════

WHAT IS THIS?
  Full web conversion of the MedSavu desktop app. Runs on your cPanel hosting:
  PHP 7.4 - 8.3 + MySQL. Light-blue classic theme. Modules:
  • Dashboard          live KPIs + 12-month charts (income/expenditure/registrations)
  • Members            register, edit, search, delete, per-member FILE (ledger)
  • Deposits           record collections (Cash / MoMo / Airtel / Bank / Cheque)
  • Withdrawals        request → Approve → Pay (or Reject) workflow
  • Medical Bills      provider bills with the same approval pipeline
  • Beneficiaries      dependants (beneficially) per member
  • Finance Centre     Financial Report Centre: income vs expenditure by period,
                       deposits-by-method analysis, provider analysis, outstanding
                       liabilities, member statement ledger, print + CSV export
  • Reports & Analytics  advanced module: cumulative-balance & net-movement
                       charts, top-10 member chart, claims pipeline, 12-month
                       statistics table with growth %, KPI cards (engagement,
                       average deposit, claim turnaround days)
  • SMS Centre         SmsDual (256700541666 / kisubi / sender KISUBI) + WhatsApp
                       number-ID; queue or live sending; outbox log with retry
  • Users & Roles      previledge-based access (Super Admin / Administrator /
                       Finance / Reception / User)
  • Settings           hospital + SMS/WhatsApp/SMTP configuration, activity log

  LOGIN (created by the installer):  daniel / daniel@2026   (Super Admin)

───────────────────────────────────────────────────────────────────────────

STEP 2 - UPLOAD THE APP  (cPanel → "File Manager")
───────────────────────────────────────────────────────────────────────────
  1. File Manager → go to  public_html
  2. Create a new folder named  scheme      (public_html/scheme)
  3. Upload  MedSavu_PHP_v3.zip  into that folder, then right-click → Extract.
     Make sure the .php files sit directly inside public_html/scheme/
     (scheme/login.php, scheme/install.php, scheme/includes/..., etc.)
  4. Enable "Show hidden files" (Settings, top-right of File Manager) so the
     .htaccess files are kept when you upload.

───────────────────────────────────────────────────────────────────────────
STEP 3 - RUN THE INSTALLER  (once, in your browser)


  Tables created: users, members, deposits, withdrawals, medical_bills,
  beneficiaries, sms_log, activity_log, settings.  Passwords stored hashed
  (bcrypt). Logins, money edits and approvals are all recorded in the audit log.

───────────────────────────────────────────────────────────────────────────
STEP 4 - LOCK DOWN (2 minutes, important!)
───────────────────────────────────────────────────────────────────────────
  1. Log in at https://www.kisubihospital.co.ug/scheme/  with daniel / daniel@2026
  2. Users & Roles → edit Daniel → set a NEW private password.
  3. Add your staff (reception, finance...) with the right Previledge.
  4. cPanel → File Manager → DELETE install.php from public_html/scheme.
  5. cPanel → change your cPanel account password too (it was shared in chat).
  6. Settings → SMS: set Send mode = Live once you confirm the SmsDual API
     endpoint, or leave Queue (messages are logged and can be retried).

───────────────────────────────────────────────────────────────────────────
TROUBLE & NOTES
───────────────────────────────────────────────────────────────────────────
 • "Database connection failed" on install → the cPanel DB name MUST include
   the prefix (kisubihospital_...) and the user MUST be added to the DB with
   ALL PRIVILEGES.
 • Charts: they load Chart.js from a CDN. On the hospital LAN they render;
   offline they simply hide, tables still work.
 • Backups: cPanel → "Backup Downloads" / AutoBackup keeps MySQL + files.
 • The old desktop EXE (.NET 4.0) and its source remain in the workspace;
   this PHP system talks to the SAME tables conceptually but uses MySQL now.
 • HTTPS is already active on your domain, so the login is encrypted.

═══════════════════════════════════════════════════════════════════════════
  v3.1 - MEMBER PORTAL WITH MTN MoMo SELF-DEPOSITS (now LIVE)
═══════════════════════════════════════════════════════════════════════════
LIVE URLS:
  Staff:        https://www.kisubihospital.co.ug/scheme/login.php
  Member Portal: https://www.kisubihospital.co.ug/scheme/portal-login.php

HOW IT WORKS:
  1. Staff grants portal access (Members > Edit > "Member portal access")
     OR the member self-registers at portal-register.php by verifying
     Member No + full names + phone + National ID against the records.
  2. Member signs in, taps an amount chip (10K/20K/50K/100K/200K/custom)
     and presses "Pay with MTN Mobile Money".
  3. The system sends an MTN MoMo Collections *RequestToPay* to the
     member's phone. Member dials *165*6# / accepts the prompt and enters
     their MoMo PIN.
  4. On success (via MTN callback or status polling fallback) the deposit
     is credited EXACTLY ONCE, linked to the MoMo transaction, and an SMS
     receipt is queued. The member gets a printable receipt page.
  5. Staff watch everything under "MoMo Payments" (re-check / expire /
     CSV export).

GOING LIVE WITH REAL MONEY:
  A. Register an MTN MoMo Collection product on the MoMo Developer
     Partner Portal (production). You receive: API User (UUID), API Key,
     Subscription Key.
  B. In MedSavu Settings > "MTN MoMo Collections":
       Mode: Live   Enabled: true
       Base URL: https://momo-mtnougprod.momodeveloper.mtn.com (as given by MTN)
       Target environment: mtnngmomozamprod1 (as given by MTN)
       Currency: UGX   Callback URL: https://www.kisubihospital.co.ug/scheme/momo-callback.php
  C. Press "Test gateway connection" - green = credentials valid.
  D. Min/max limits per request are enforced (default 1,000 - 2,000,000 UGX).

  Until then the gateway sits in DEMO MODE: the full flow works with a
  "Simulate approval" button and money never moves - labelled clearly.

=====================================================================
 v3.2 — REGISTRATION MODULE: FULL VISUAL-BASIC PARITY (live 30 Oct 2026)
=====================================================================
The Members page (members.php) now mirrors every feature of the old
VB.NET AddRegistrationForm, adapted for the web:

 1. LIVE CAMERA PHOTO — "Open camera" uses the webcam (getUserMedia),
    live preview, Snap / Retake. The picture is auto-cropped to the
    centre square, resized to 512 px and saved as a real JPEG.
 2. FILE UPLOAD — "Upload file" button as an alternative to the camera;
    it is converted the same way. Only valid JPEG bytes are stored
    (anything else is rejected).
 3. PHOTO IN THE DATABASE — stored as a binary BLOB (members.photo,
    MEDIUMBLOB) exactly like the VB Image column. Shown as a thumbnail
    in the member list, re-shown in the edit form, in the member file
    and on the ID card (served by memberpic.php?id=N).
 4. NFC / SMART-CARD READER — "Scan card" uses the Web NFC API
    (Chrome/Android) and writes the card UID into Card No.; on desktop
    or unsupported browsers type/scan the card number manually — the
    field accepts both.
 5. MEMBER-NO GENERATOR — new registrations are pre-filled with a fresh
    random KH-ABC-1234 number (server CSPRNG; "New" button re-rolls).
    Leaving the field blank falls back to the KMH-00NN sequence.
 6. ALL VB FIELDS PRESENT — Full names, Place of residence, Email,
    AirtelNo, MtnNo, LC1 village, District, NIN, Card No, Gender,
    Marital status, Date of birth, Registered-by (current user,
    automatic), Registration date (today, automatic), Deposit amount,
    Status. Initial Deposit > 0 is auto-posted to the deposit ledger
    as reference REG-<MemberNo>.
 7. PHONE HANDLING EXACTLY LIKE VB — numbers are STORED as 2567XX… and
    DISPLAYED as 07XX… whatever you type.
 8. COMBINED SEARCH on the member list: by Member No, by names, by NIN
    and by mobile (either network, 0-prefix or 256-prefix).
 9. SAVE guard: "Member Number or National ID already registered"
    (both columns now UNIQUE in MySQL; blank NINs are allowed).
10. DELETE selected member with a confirmation prompt (Super Admin /
    Admin only; transactions are kept for audit).
11. AUDIT TRAIL — every register/update/delete writes an audit_trail
    row: UserName, Computer/Host, IP, LoginTime, LoginDate, Status,
    Activity (MemberRegistration/MemberUpdate/MemberDelete), MemberNo,
    FullNames, EntryDate.
12. ID CARD — new button on the member form/file opens idcard.php?id=N
    — a printable member ID card with photo, member no, names, village/
    district, NIN, card UID and validity line (Print button in page).

NOTHING WAS REMOVED: the portal-access panel (grant / reset password /
revoke for member self-service MoMo deposits) is still on the same
edit form; all other modules, the light-blue theme and MoMo payments
are untouched.

SERVER SETUP DONE: migrate.php was run once on the production database
on 30 Oct 2026 (extended member columns, members.photo BLOB, unique
member_no/national_id, audit_trail table) and then deleted from the
server, together with install.php. The app works even BEFORE migration
(legacy fallback) — fresh installs via install.php already contain
everything.

=====================================================================
 v3.3 — BENEFICIARY (BENEFICIALLY) MODULE: VB PARITY + CRYSTAL REPORT
=====================================================================
 • Beneficiaries page rebuilt to match AddBeneficiallyForm: live webcam
   photo (Snap/Retake), file upload, Gender, Age, Residence, Patient
   No/NIN, Relationship, phone — photo stored in the database and shown
   in the list, the member's file and the report.
 • New PRINTABLE REPORT  report-beneficiaries.php  — the web twin of
   Crystal report Beneficially.rpt: PageHeader (hospital + date), group
   per member (member photo + Member No + village/district), detail
   lines for each beneficiary (with their photo), group footer "Total
   beneficiaries for <member>", report footer with grand totals and
   Prepared-by. Print / Save as PDF button uses the browser print
   dialog (A4 portrait, print-optimized CSS). Filter one member via
   the member dropdown or the "Print report" button on a member file.
 • memberpic.php now serves beneficiary photos too (?t=ben). Portal
   members can only see photos of their OWN beneficiaries.
 • Database: beneficiaries gained residence, gender, age, photo
   (migrate.php ran live 30 Oct 2026; install.php contains it for
   fresh installs). Works in legacy fallback before migration.
 • The MSSQL->MySQL exporters (MSSQL_TO_MYSQL folder) now migrate
   BeneficiallyTable with residence/gender/age and — in the PowerShell
   version — the BeneficiaryPhoto images too.

 v3.3b — BENEFICIALLY WORKBENCH (matches AddBeneficiallyForm layout):
 searchable member list (click = select, shows member photo card like
 showneo), that member's own beneficiary list under the form, photo
 REQUIRED before first save ("Please capture beneficiary photo first."),
 update requires Patient No + name and keeps the stored photo unless a
 new one is captured, Save clears the form and stays on the screen for
 batch entry, Clear form button. Deployed live 30 Oct 2026.

== v3.4 DEPOSITS DESK (RecordDepositForm + ManageDepositsForm parity) ==
Upload these 4 files into public_html/scheme/ (overwrite existing):
  deposits.php            (rewritten: cashier workbench + deposit register)
  receipt-deposit.php     (NEW - printable deposit receipt)
  deposit-statement.php   (NEW - member statement, print/PDF, email, WhatsApp)
Then, ONCE, upload + run the one-shot that adds the audit Amount column:
  deposit-desk-sync.php   -> open https://www.kisubihospital.co.ug/scheme/deposit-desk-sync.php?run=1
  It should print "audit_trail.amount added" (or "... exists" if already run).
  DELETE deposit-desk-sync.php right after (LiteSpeed caches the 404 of old names - keep fresh names for future one-shots).
install.php / migrate.php in the package carry the same schema update for fresh installs.

Features: workbench member search (Member No/names/Telephone/NIN), click-to-select,
photo card + REGISTERED status gate, registration-fee capture (UGX 20,000, tick-to-accept,
exact-amount validation, member auto-marked REGISTERED, ledger + audit + SMS fan-out),
live Previous -> Deposit -> New total calculator, confirm-before-save dialog, transaction-ID
success panel with print-receipt link, clear-and-stay. Register: Previous/Deposit/Total columns,
date range + five-way search, TOTAL DEPOSITS strip, pager, CSV export, delete (Super Admin),
row-click detail: receipt, statement, email statement to member (host mail(), no stored password),
WhatsApp share, refund request -> pending withdrawal with two-level approval in Withdrawals.
Every deposit/fee/refund request writes an audit_trail row with the Amount, and queues the
receipt SMS into the SMS module (never blocks the money).

== v3.5 SMS — SmsDual integrated EXACTLY like the VB SmsDualClient (30 Oct 2026, live) ==
- includes/smsdual.php: faithful PHP port — POST https://www.smsdual.info/bulksms-api.php,
  body username/from/recipients/message/password/type=normal/txid in the same order,
  Uri.EscapeDataString-style encoding (%20), 30-char MD5 txid per submission, TLS 1.2,
  30s timeouts, UA MedSavu/1.0; parses the provider's PHP-deprecation noise before the JSON
  (last '{' wins) -> "success":true = SUCCESS; any rejection keeps the raw provider text;
  HTTP errors produce the same REQUEST ERROR block; the four validation messages are verbatim.
- SMS Centre: Live mode sends through the client immediately; Queue mode logs; NEW
  "Send queued now" batch + per-row "Send now"; every result stored in the outbox log.
- Deposits (receipt + registration-fee SMS) and MoMo receipts now fan out through the same
  client — and a failed SMS NEVER undoes a committed deposit (proven in tests).
- Account auto-filled everywhere (Settings -> SmsDual gateway, SMS Centre header, new installs):
  client/username 256700541666, sender KISUBI, endpoint the built-in one (URL field blank = built-in).
  Numbers auto-normalise to 256… (0XXXXXXXXX / 00256… / 9-digit forms).
- Live deploy ran gateway-sync-v35.php once (endpoint+mode fixed in DB, then deleted; 404 verified).
  33/33 local e2e against a mock of the real provider responses; live read-only smoke 18/18.

== v3.6 SETTLEMENT DESK (MakeATransactionForm parity) — live 30 Oct 2026 ==
- bills.php ?a=settle: member accounts grid (MEMBER/NAME/PHONE search, hidden internal cols,
  green/red available-balance plate), beneficiaries grid w/ count + "Member paying for self"
  (CheckBox1 twin: locks grid, patient = member), hospital invoice LOAD from the HIS
  (public.invoice / invoice_line paid-lines / invoice_receipt patient) with the already-settled
  guard incl. existing transaction ID, patient-belongs-to-account verification, discount %
  live-recalc (amount away-from-zero rounded + balance-after colored), visit type & hospital days.
- Authorization REQUIRED before settle, exactly like VB: E-CARD (enrolled-UID check via card_no;
  modal with WebNFC hint) or OTP (6-digit SMS through your SmsDual gateway, 5 min, 3 attempts,
  ref = SMS-<id>). Details changed after authorization => auth voided, re-authorize. Session-side
  revalidation on the server, so the guard works even with the JS disabled.
- Settlement engine: transaction-wrapped, duplicate-invoice recheck, PENDING DEPOSIT REFUNDS are
  RESERVED and reduce available funds (DepositRefundPayoutTable twin), insufficient-funds messages
  verbatim, writes a PAID withdrawal row carrying patient/invoice/HIS-id/visit/discount/days/
  auth/prev/new balances, syncs cached member balance, audit row with Amount, settlement SMS fan-out
  (a gateway failure never blocks money), printable receipt-settlement.php voucher.
- Provision for the hospital integration: Settings -> "Hospital HIS — eafyakisubidb (PostgreSQL)":
  host/port/db/user/pass + Test connection; php-pgsql required (ask host to enable). While Off or
  unreachable, the desk runs in manual invoice entry mode with the SAME guards (invoice numbers are
  still checked for double settlement).
- withdrawals.php = the settlement register: SETTLED chip (invoice/patient/auth/discount),
  Receipt links, Settlements-only view, desk totals. Classic bills workflow + request forms untouched.
- Schema: withdrawals gained 14 settlement columns (live migration ran via one-shot, deleted after;
  install.php/migrate.php carry it for fresh installs).
- 47/47 local e2e incl. permission gate (Reception denied), discount maths, reserved-refund blocking,
  OTP round-trip via the SmsDual twin.

== v3.7 MEMBERS PORTAL: bill clearings, analytics, cash+MoMo deposits ==
- Deposit hub (portal.php): members now choose MTN Mobile Money (unchanged prompt flow) OR declare CASH.
  Cash declarations (UGX 1,000-20,000,000, optional note) land as Pending in new cash_deposits table -
  nothing touches the balance until the cashier counts the money and CONFIRMS on Deposits & Refunds
  (gold panel on the register). Confirm = real deposits row (method Cash, ref CASH-PORTAL-<id>), balance
  cache, audit CashDeclaration, SMS receipt with new balance. Reject = reason stored + SMS; no money moves.
- My Bill Clearings (portal-settlements.php, new): member's paid withdrawals incl. v3.6 settlement-desk
  rows with E-CARD/OTP auth badges, invoice/patient, discount line, balance-after; KPI cards.
- Analytics (portal-analytics.php, new): 12-month deposits vs paid-out bars + cumulative line (Chart.js),
  channel doughnut (MoMo/Airtel/Cash/Bank), 6 KPI cards, month table with progress bars. Day-1 month
  anchoring so short months never skip a bucket.
- Portal nav gained 2 links; portal-statement now cross-links. Staff-side flows and permissions untouched.
- Schema: cash_deposits table (install.php + migrate.php; live add via one-shot portal-sync-v37.php).
- 56/56 local e2e (declaration lifecycle, confirm/reject SMS exact-text, security: logged-out + staff
  session cannot browse member pages, classic sweeps green).

== v3.8 LOGIN RULES, MOBILE NUMBERS & LIVE UI POLISH ==
- Password floor: portal AND staff logins refuse anything under 4 characters (server-side check before
  any DB hit; forms carry minlength=4 + hint). Portal account creation still asks for 8+ (stronger).
- Member form: MTN/Airtel renamed Mobile No. 1 / Mobile No. 2. Only ONE of the two is now required
  (server + on-submit guard); both stay fully changeable on the edit form.
- Public portal registration: same two mobile fields, at least one a must - and whatever is given
  UPDATEs the member file (members can correct their own numbers while signing up for the portal).
- UI: gentle classic motion layer - staggered fade-up panels, KPI pop-in + hover lift, buttons with
  press feedback, focus glows, table row hover, auto-fading (hover-to-keep) flash messages, drifting
  top-bar gradient, pulsing selected amount chips, logo pop-in, card shimmer. Fully CSS, respects
  prefers-reduced-motion, zero new files. No schema changes.
- 26/26 new checks + full v3.7 56-check battery re-passed on a clean env; classic module sweeps green.

== v3.9 MULTI-FACILITY: CLAIMS & DISBURSEMENTS NETWORK ==
- NEW prominent module "Claims & Disbursements" (nav, red pending-count badge): clinics/hospitals
  bill the scheme; the SCHEME OWNER (Super Admin) alone approves (may cap), rejects (reason, SMS)
  and CLEARS claims — disbursement recorded in its own ledger + audit + facility SMS + printable
  receipt (claim-receipt.php). Drafts allowed (save now, submit later).
- Hospitals & Clinics registry (facilities.php): create Hospitals / Clinics / Branches; children
  nest under their parent with type icons on the parent page; suspend/activate. Every facility is
  born STANDARD with all features (claims, price list, users, bills, SMS).
- Price lists (pricelist.php): auto-generated per facility — 15-item hospital base kit (STANDARD)
  plus house ADD-ONS (additive); base rows protected; copy-missing-lines from another facility; print.
- Claims carry the originating facility name (denormalized on the row + shown everywhere).
- Facility users & LOCKED logins (users.php): 'Facility Admin' role; assign any user a facility at
  creation; a locked login can ONLY see its own clinic/hospital (claims desk + price list + own users;
  registry pages answer 403). Facility admin creates clinic-level users — they inherit the lock.
- The module runs INTO the super admin account: prominent red-bordered Owner Desk panel on the
  dashboard with one-click Approve/Reject and Open-the-desk link + pending KPIs.
- Schema (install.php + migrate.php + live one-shot facility-sync-v39.php): facilities,
  facility_prices, facility_claims, disbursements tables; users.facility_id. Backward compatible —
  every page degrades gracefully pre-migration.
- 52/52 local e2e (full lifecycle incl. locks, caps, SMS exact text, drafts, second-level users,
  idempotent re-migrate; v3.7 + v3.8 cores + classic module sweeps all re-passed).

================================================================================
 v5.0 — CORE INSURANCE, CLAIMS, DEPOSITS & SETTLEMENT MANAGEMENT (multi-institution)
================================================================================

WHAT v5.0 ADDS (all DB-backed, permission-gated, audited — no demo stubs):
 * Institutions: facilities now carry a type (Hospital / Clinic / Medical Centre /
   School / Pharmacy / Health Facility / Other) + unique institutional code, and each
   gets its own portal (institution.php): KPIs, monthly settled chart, branches,
   its own users, SMS on/off. Creating a facility auto-initialises: standard price
   kit (15 base items), deposit wallet (IAC-nnnn) and SMS preferences.
 * Price lists (pricelist.php): additive-only Excel-style import/export, reprice with
   full change history (cl_price_log), effective dates, special/add-on lines, print,
   CSV download. Prices are SNAPSHOT onto each bill/claim — later list changes never
   alter stored invoices. Facility accounts (any role) manage their own list; the
   facility is forced server-side (fac_lock) — URL/param switching cannot cross tenants.
 * Beneficiaries (institution-members.php): registration with institution member
   number (CODE-000n), principal/sponsor + relationship, category (Principal/Spouse/
   Child/Dependant/Student/Staff), cover start/expiry, eligibility, photo, CSV import
   with configurable duplicate checks (NIN/phone, per institution). A central switch
   (settings: allow_multi_beneficiaries) decides whether the same NIN may exist at two
   institutions; global NIN uniqueness was replaced by per-institution dedupe in v5.
 * Wallets & deposits (wallets.php): unique deposit references, configurable methods,
   proof upload (stored under data/deposits, never web-served) — a proof NEVER credits
   the wallet; only an authorised owner verification posts the double entry
   (Dr cash/momo account, Cr 2200 Institution Deposit Accounts) + ledger row with
   running balance. Corrections are reversal entries only (with force-reason gate);
   nothing financial is ever deleted.
 * Beneficiary Bill Settlement (bill-settlement.php): the 15-step workflow — member +
   eligibility/expiry checks, institution match, snapshot pricing, funding choice per
   line (Wallet / Insurance / Copay), discount (gated), available-balance & shortfall
   preview, partial settlement only when the institution rule allows (floors wallet at
   zero, remainder → member copay), one-shot form token (replays die), auto receipt
   (settlement-receipt.php: print / CSV-safe resend / owner reversal), SMS notification.
 * Insurance engine (plans.php): plans carry premium, annual/per-visit/per-service
   limits, waiting period, exclusions, co-pay %, deductible, referral/preauth flags,
   dependants, renewal terms; policies issued/renewed per institution (POL-CODE-year);
   enrolment per beneficiary with cover limit; live eligibility tester. Settlements
   route Insurance lines through the engine — covered capped by limits; excess flows to
   copay; claim stores covered/copay/excluded + a human-readable eligibility summary.
 * Claims (facility-claims.php + facility-desk.php): unique CLM numbers, institution
   auto-identified from session, cross-institution submission blocked, attachments,
   statuses Draft→Submitted/Pending→Under Review→Approved/Partially Approved→Cleared
   for Payment→Paid (Fully/Partially) + Returned for Correction→Resubmitted + Rejected
   /Voided; per-item review with partial approval; every decision writes claim_events
   + audit log; disbursement ledger + GL (Dr 2100 Cr 1010 on pay); double-payment guard:
   wallet-settled transactions are stamped Settled so the claim generator can never
   re-bill them, and reconcile.php flags any legacy exception.
 * Reconciliation (reconcile.php): four ledgers (deposits, claims, beneficiary
   settlements, central payments) vs GL 2200/2100 with variance + snapshot save/CSV.
 * SMS (sms-templates.php + queue in sms.php): 14 categories, per-template enable/
   edit with variables; queued (or live via the existing configurable gateway), never
   blocks operations, delivery-status/retry columns preserved; a disabled template
   sends nothing.
 * HMIS API (hmis-api.php): body-token auth per facility; new v5 actions
   wallet_balance, bill_settle (routes through the settlement engine), eligibility.
 * Security: bcrypt hashing, RBAC + per-user perms, institution+branch server-side
   locking, one-shot settlement token, CSRF everywhere, secure storage for proofs,
   immutable financial entries with reversal-only corrections, per-facility login
   history and activation/lock.

DATABASE MIGRATION (additive, non-destructive):
 * schema50 (includes/schema50.php) creates 11 tables (institution_branches,
   institution_accounts, institution_deposits, institution_ledger, insurance_plans,
   insurance_policies, policy_beneficiaries, bill_settlements, settlement_items,
   sms_templates, recon_snapshots) and adds columns to facilities/members/users/
   facility_txns/facility_claims. Existing records are untouched.
 * Fresh installs: install.php (seed=1) now inlines the whole v5.0 schema, the demo
   world (Nsoora/Katosi facilities with price kits + desk users nurse_s / recep_k,
   portal demo member aisha), GL account 2200, 14 SMS templates and wallets — then
   run schema-sync-v50.php?run=1 ONCE (idempotent; safe) and migrate.php once.
 * Upgrades: run migrate.php (v4.1 additive step) then schema-sync-v50.php?run=1.
   IMPORTANT (v5 semantics): members.national_id is no longer globally UNIQUE
   (uniq_nin dropped) — dedupe is per-institution via dedupe_key + the central
   multi-institution switch. The migration runs automatically inside both one-shots.
 * DELETE install.php, migrate.php and schema-sync-v50.php from the public server
   once done (same rule as v4.1).

TESTED (against a pristine fresh install):
 * t50 battery: 111/111 — institution portals, isolation (cross-facility params
   forced to own institution; foreign receipt/plan access denied), price-list
   independence + import/reprice/history + snapshot-on-bill, beneficiary register/
   import/dedupe + central multi-institution switch, deposits (pending → owner
   verify → GL + ledger; proof never credits; reject; reversal), settlement E2E
   (SET numbers, shortfall block, partial rules, copay split, receipts, SMS queue),
   plan/policy engine (deductible+copay math: 100,000 → 72,000 covered on V5GOLD),
   auto-claim + lifecycle (approve/partial → clear → pay → claimpay GL + disbursement
   ledger + events; return-for-correction; reject), double-payment guards (0 found),
   reconcile variances, template on/off, one-shot token replay rejected, CSRF,
   schema-sync idempotence, wallet statement CSV, hmis bill_settle/eligibility.
 * v4.1 battery: 145/145 · v4.0 battery: 49/49 (no regressions).

DEMO ACCOUNTS (fresh seeded install): daniel/daniel@2026 (owner),
nurse_s/clinic@2026 + recep_k/katosi@2026 (facility desk), member portal aisha/aisha@2026.
Rotate before any real use. Facility-created admin logins land on institution.php.

== v6.1 UPGRADE NOTES (wallet retired — read this if upgrading from v5.0/v6.0) ==
1) Upload this package over the existing app (nothing destructive). Historical wallet tables
   (institution_accounts / institution_deposits / bill_settlements) are intentionally RETAINED
   for reconciliation — they are no longer written to.
2) Delete from the server: wallets.php and bill-settlement.php (the retired UI). They are not
   part of this package; older deployments still have them.
3) No migration file needed: the payment-channel details table (disb_paydetails) creates
   itself on first use; the new Settings → "v6.1 automation" toggles default ON/OFF as
   documented in-app. (v6.0 note still applies: if you skipped v6.0, run schema-sync-v60.php
   once, then delete it.)
4) Flow after upgrade: facility creates member bill → member authorizes (e-Card + OTP or
   portal approve/decline) → facility settles (deposit deducts; receipt; SMS queued) →
   claim auto-generated & sent to Kisubi when the facility is Kisubi-prefunded with the
   auto-claim toggle ON → Kisubi reviews (claim lines show the member deposit balance),
   approves (auto-schedules full net), pays via Bank/Cash/Mobile Money with reference and
   optional proof-of-payment file → facility sees confirmation; reconcile.php reconciles.
5) Deposits & corrections: member deposit balances are central (one ledger per member).
   Facilities VIEW balances for clearing only; recording deposits, adjustments and reversal
   of settlements are central-finance actions. Never hand-edit members.deposit_amount.
6) Self-test: run t61-battery.py (expects "T61 RESULT: PASS 0 / FAIL 0") plus t50/t60 against
   a DEMO database — batteries create records; do not point them at production data.
7) SMS: everything works with the gateway OFF (queue mode). OTP codes go to the SMS Centre
   queue when disabled; the member phone must be enrolled for OTP delivery.


== v7.0 UPGRADE NOTES (facility portal hardening — read this if upgrading from v6.1) ==
1) FILES: replace the whole scheme/ tree with this package. Nothing was renamed or removed;
   the v7.0 schema self-heals on first page load — do NOT run install.php on a live v6.x DB.
2) SELF-HEAL (includes/facility70.php, f70_ensure(), idempotent, MySQL + SQLite):
   creates member_verifications; adds facility_prices.active / price_pending / approval;
   activity_log.facility_id (+index); per-facility search-rate tracking; upgrades the
   member_settled SMS template to state 'Receipt no: {receipt}'. All additive — a v6.1 app
   still runs on the patched DB (clean rollback path).
3) NEW / ENHANCED FACILITY PAGES (the 13-module portal): facility-dashboard.php (12 metric
   cards + 6 charts + today/week/month/quarter/year/custom filters, every card and chart
   computed live from the DB, cards click through to the filtered records), facility-verify.php
   (Method A e-card, Method B phone + member-held OTP, permitted-details card, masked phone),
   facility-sms.php (own-facility message log, delivery status, safe retry), member-bills.php
   (billing desk from the facility's OWN price list + settlement), pricelist.php (add/edit/
   activate/deactivate/category/search/import/export + change journal + historical prices),
   facility-claims.php (12-status tracking, search/filter/pagination/print), facility-
   disbursements.php (payments seen, never made), facility-reports.php (14 reports, print +
   Excel/CSV), facility-audit.php (own-facility trail), facility-users.php (portal enrolment).
4) STRICT PORTAL BACKEND ENFORCEMENT: 22 central administration pages carry fac_central_guard();
   a facility-portal login typing a central URL is bounced to the portal with an activity_log
   'BLOCKED central' entry (logged, flash shown, no data). Cross-facility GETs re-scope to the
   caller's own facility. users.php is Super Admin/Administrator/Finance only — facility
   administrators enrol/disable/reset staff on facility-users.php (licence max_users enforced
   there too). Facility accounts cannot approve claims, create/edit/confirm disbursements,
   alter balances/deposits, open other facilities' price lists, or see central reports.
5) OTP + SMS SECURITY: 6-digit codes, hashed at rest (never shown to staff), single-use,
   configurable expiry/attempts/resend-cooldown, per-facility hourly lookup budget, invalidated
   after success, full audit. Balance SMS on verification and settlement SMS with new balance +
   receipt number run through the EXISTING queue — all flows work with the gateway OFF; a
   failed SMS never reverses or blocks a settlement; retries are capped (5), logged, and cannot
   duplicate the message to the member ledger.
6) SETTINGS: new panel "Facility portal (v7.0)" — keys f70_otp_expiry_mins (10),
   f70_otp_max_tries (5), f70_otp_resend_secs (60), f70_search_rate_per_hour (150),
   f70_balance_sms (1), f70_price_central_approval (0). Defaults are safe for every facility;
   price central approval, when ON, parks facility price edits as 'Pending re-price' for
   Kisubi until approved — historical prices and billed-time prices are always preserved.
7) VERSION MANAGEMENT UI REMOVED (spec §12): no version banners, footers, menus, cards or
   screens any more; login reads "MEDICAL SAVING SCHEME · MedSavu". Migration history
   (schema*/install machinery), deploy metadata and audit tables are UNTOUCHED by design.
8) DEMO SEED: install.php is only for fresh installs; nothing new to configure — the v7.0
   tables/columns self-heal even then.
9) SELF-TEST (DEMO DB ONLY — batteries create records; never point them at production):
   run tests/run_chain.sh over t40, t41, t50, t60, v61_smoke, t61 and t70. Green =
   t40 "FAILS: 0" · t41 "TOTAL FAILS: 0" · t50 "TOTAL FAILS: 0 (of 81)" ·
   t60 "RESULT: PASS 123 / FAIL 0" · v61_smoke "SMOKE RESULT: ALL PASS" ·
   t61 "T61 RESULT: PASS 0 / FAIL 0" · t70 "T70 TOTAL FAILS: 0" (24 v7.0 acceptance tests).
10) ROLLBACK: restore the previous package folder (e.g. live-backup-v61-pre on the live
    server). The v7.0 DB changes are additive; the old app ignores the new columns/tables.
