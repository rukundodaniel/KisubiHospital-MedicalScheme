<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_auth.php';
require_once __DIR__ . '/includes/momo.php';

$m = portal_require();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = post('op');
    if ($op === 'deposit' && momo_tables_ready()) {
        try {
            $txnId = momo_start_deposit($m, post('amount'), post('note'));
            log_action('Portal deposit initiated (' . ugx(post('amount')) . ') ' . $m['member_no'], 'momo_transactions', $txnId);
            redirect('portal-payment.php?id=' . intval($txnId));
        } catch (Exception $ex) {
            $err = $ex->getMessage();
        }
    }
    if ($op === 'demo_approve') {
        try { momo_demo_approve(intval(post('txn')), intval($m['id'])); flash('Demo payment approved — your deposit was credited.', 'ok'); redirect('portal.php'); }
        catch (Exception $ex) { $err = $ex->getMessage(); }
    }
    if ($op === 'cash_request') {
        $amt = (float)str_replace(',', '', post('amount'));
        $note = trim((string)post('note'));
        if ($amt < 1000) $err = 'Minimum cash declaration is UGX 1,000.';
        elseif ($amt > 20000000) $err = 'A single cash declaration cannot exceed UGX 20,000,000 — split larger amounts or pay at the cashier desk.';
        else {
            try {
                $st = db()->prepare('INSERT INTO cash_deposits (member_id, amount, status, note, created_at) VALUES (?,?,?,?,?)');
                $st->execute(array($m['id'], $amt, 'Pending', mb_substr($note, 0, 240), date('Y-m-d H:i:s')));
                $cid = intval(db()->lastInsertId());
                log_action('Portal cash declaration UGX ' . number_format($amt, 0) . ' (' . $m['member_no'] . ')', 'cash_deposits', $cid);
                flash('Declaration #' . $cid . ' recorded for UGX ' . ugx($amt) . '. Bring the cash to the cashier desk with your member number — your balance updates the moment it is counted and confirmed. You will receive an SMS.');
                redirect('portal.php');
            } catch (Exception $ex) { $err = 'Cash declarations are not available yet on this install (' . $ex->getMessage() . ').'; }
        }
    }
    if ($op === 'check') {
        $txnId = intval(post('txn'));
        $st = db()->prepare('SELECT id FROM momo_transactions WHERE id=? AND member_id=?');
        $st->execute(array($txnId, $m['id']));
        if ($row = $st->fetch()) { momo_finalize(intval($row['id'])); redirect('portal-payment.php?id=' . $txnId); }
    }
}

$c = momo_cfg();
$bal = member_balance($m['id']);
$deps = db()->prepare('SELECT * FROM deposits WHERE member_id=? ORDER BY deposit_date DESC, id DESC LIMIT 8');
$deps->execute(array($m['id'])); $recent = $deps->fetchAll();
$pend = db()->prepare("SELECT * FROM momo_transactions WHERE member_id=? AND status='Pending' ORDER BY id DESC");
$pend->execute(array($m['id'])); $pending = $pend->fetchAll();
$successCount = 0; $totalDeposited = 0.0;
$tc = db()->prepare('SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM deposits WHERE member_id=?');
$tc->execute(array($m['id'])); $tot = $tc->fetch();
$totalDeposited = floatval($tot['s']); $successCount = intval($tot['c']);

$p_title = 'My Account';
include __DIR__ . '/includes/portal_header.php';
?>
<div class="deposit-hero">
  <table style="width:100%"><tr>
    <td>
      <div class="bal-label">Available scheme balance</div>
      <div class="bal-value"><?php echo ugx($bal); ?></div>
      <div class="bal-sub">Member since <?php echo e(date('M Y', strtotime($m['registration_date']))); ?> &middot; <?php echo num($successCount); ?> deposits totalling <?php echo ugx($totalDeposited); ?></div>
    </td>
    <td style="text-align:right;vertical-align:middle">
      <?php if ($c['mode'] === 'demo'): ?><span class="badge amber" title="No real money moves until MoMo credentials are set and mode=live">DEMO MODE</span><?php else: ?><span class="badge green">MTN MoMo live</span><?php endif; ?>
    </td>
  </tr></table>
</div>

<?php if ($err): ?><div class="loginerr"><?php echo e($err); ?></div><?php endif; ?>

<?php if ($pending): ?>
<?php foreach ($pending as $t): ?>
<div class="panel" style="border-left:5px solid #c89211">
  <div class="prompt-box">
    <table style="width:100%"><tr>
      <td>
        <div style="font-size:15px;font-weight:bold;color:#8a6410;margin-bottom:6px"><span class="prompt-dot"></span>Awaiting your approval on <?php echo e($m['phone_no']); ?></div>
        <div style="font-size:13px;color:#1b3a57">MoMo request <b><?php echo ugx($t['amount']); ?></b> &mdash; reference <b><?php echo e($t['external_id']); ?></b><br>
        Open your phone's dialer, call <b>*165*6#</b> (or check the MoMo prompt) and enter your PIN to complete payment.</div>
        <form method="post" style="margin-top:10px;display:inline"><?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="check"><input type="hidden" name="txn" value="<?php echo e($t['id']); ?>">
          <button class="btn small primary" type="submit">I have approved — check now</button>
        </form>
        <?php if ($c['mode'] === 'demo'): ?>
        <form method="post" style="display:inline"><?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="demo_approve"><input type="hidden" name="txn" value="<?php echo e($t['id']); ?>">
          <button class="btn small success" type="submit">Simulate approval (demo)</button>
        </form>
        <?php endif; ?>
      </td>
      <td style="width:130px;text-align:right;vertical-align:top">
        <span class="countdown" id="cd" data-start="<?php echo e(strtotime($t['created_at'])); ?>">checking…</span>
        <script>
        (function(){var s=<?php echo time() - strtotime($t['created_at']); ?>;var el=document.getElementById('cd');
        function t(){var left=Math.max(0,300-s);var mm=Math.floor(left/60),ss=left%60;
        el.textContent=left>0?('expires in '+mm+':'+(ss<10?'0':'')+ss):'expired — start a new request';s++;}
        t();setInterval(t,1000);})();
        </script>
      </td>
    </tr></table>
  </div>
</div>
<?php endforeach; ?>
<?php else: ?>

<div class="grid grid-2">
  <div class="panel">
    <h2>Deposit by MTN Mobile Money <span style="font:11px Arial;color:#5b7c99">(instant &middot; prompt to your phone)</span></h2>
    <p style="font-size:13px;color:#5b7c99;margin:0 0 10px">Choose an amount — we push a payment request to your MTN number. Min <?php echo ugx($c['min']); ?> · Max <?php echo ugx($c['max']); ?> per request.</p>
    <form method="post">
      <?php echo csrf_field(); ?><input type="hidden" name="op" value="deposit">
      <div class="amount-chips" id="chips">
        <button type="button" class="chip" data-amt="10000">10,000</button>
        <button type="button" class="chip" data-amt="20000">20,000</button>
        <button type="button" class="chip sel" data-amt="50000">50,000</button>
        <button type="button" class="chip" data-amt="100000">100,000</button>
        <button type="button" class="chip" data-amt="200000">200,000</button>
        <button type="button" class="chip" data-amt="custom">Other…</button>
      </div>
      <label class="f">Amount (UGX)</label>
      <input class="f" type="number" step="100" min="<?php echo e((int)$c['min']); ?>" max="<?php echo e((int)$c['max']); ?>" name="amount" id="amt" value="50000" required style="font-size:17px;padding:11px">
      <label class="f" style="margin-top:10px">Note for the cashier (optional)</label>
      <input class="f" name="note" placeholder="e.g. October contribution" value="">
      <div style="margin-top:14px">
        <button class="btn success" style="width:100%;padding:12px;font-size:15px" type="submit" <?php echo $c['enabled'] ? '' : 'disabled title="MoMo is disabled in settings"'; ?>>
          Pay with MTN Mobile Money &raquo;
        </button>
      </div>
      <p style="font-size:11.5px;color:#7a94aa;margin:10px 0 0">Charged to <b><?php echo e($m['phone_no']); ?></b> &middot; You approve every payment with your MoMo PIN. Nothing leaves your wallet until you do.</p>
    </form>
    <script>
    document.getElementById('chips').addEventListener('click', function(ev){
      var b = ev.target.closest('.chip'); if(!b) return;
      [].forEach.call(this.querySelectorAll('.chip'), function(c){ c.classList.remove('sel'); });
      b.classList.add('sel');
      var a = b.getAttribute('data-amt');
      var inp = document.getElementById('amt');
      if (a === 'custom') { inp.value=''; inp.focus(); } else inp.value = a;
    });
    </script>
  </div>
  <div class="panel">
    <h2>Latest deposits</h2>
    <div class="twrap" style="border:0"><table class="data">
      <tr><th>Date</th><th>Reference</th><th>Method</th><th class="num">Amount</th></tr>
      <?php if (!$recent): ?><tr><td colspan="4" style="color:#5b7c99">No deposits yet — make your first above.</td></tr><?php endif; ?>
      <?php foreach ($recent as $r): ?>
      <tr><td><?php echo e(date('d M Y', strtotime($r['deposit_date']))); ?></td>
      <td style="font-size:11.5px"><?php echo e($r['reference']); ?></td>
      <td><span class="badge <?php echo strpos((string)$r['payment_method'],'MoMo')!==false?'green':'blue'; ?>"><?php echo e($r['payment_method']); ?></span></td>
      <td class="num"><b><?php echo ugx($r['amount']); ?></b></td></tr>
      <?php endforeach; ?>
    </table></div>
    <p style="margin:10px 0 0;font-size:12.5px"><a href="portal-statement.php">View full statement &raquo;</a></p>
  </div>
</div>
<?php endif; ?>

<?php
$hasCash = false; $cashRows = array();
try { $st = db()->prepare('SELECT * FROM cash_deposits WHERE member_id=? ORDER BY id DESC LIMIT 10'); $st->execute(array($m['id'])); $cashRows = $st->fetchAll(); $hasCash = true; } catch (Exception $e) { $hasCash = false; }
$chips = array(10000, 20000, 50000, 100000, 200000);
?>
<div class="grid grid-2">
  <div class="panel">
    <h2>Deposit by cash <span style="font:11px Arial;color:#5b7c99">(declare here &middot; pay &amp; confirm at the cashier)</span></h2>
    <p style="font-size:13px;color:#5b7c99;margin:0 0 10px">Tell us the amount you will bring, pay UGX at the Kisubi Hospital scheme desk, and the cashier confirms — then the money lands on your balance instantly.</p>
    <?php if ($hasCash): ?>
    <form method="post">
      <?php echo csrf_field(); ?><input type="hidden" name="op" value="cash_request">
      <div class="amount-chips" id="cchips">
        <?php foreach ($chips as $cv): ?><button type="button" class="chip<?php echo $cv==50000?' sel':''; ?>" data-amt="<?php echo $cv; ?>"><?php echo number_format($cv); ?></button><?php endforeach; ?>
        <button type="button" class="chip" data-amt="custom">Other…</button>
      </div>
      <label class="f">Cash amount (UGX)</label>
      <input class="f" type="number" step="500" min="1000" max="20000000" name="amount" id="camt" value="50000" required style="font-size:17px;padding:11px">
      <label class="f" style="margin-top:10px">Note for the cashier (optional)</label>
      <input class="f" name="note" placeholder="e.g. school-term contribution" value="">
      <div style="margin-top:14px"><button class="btn primary" style="width:100%;padding:12px;font-size:15px" type="submit">&#128176; Declare cash deposit &raquo;</button></div>
      <p style="font-size:11.5px;color:#7a94aa;margin:10px 0 0">Declarations stay <b>Pending</b> until counted at the desk — cancelling one? Just tell the cashier.</p>
    </form>
    <script>
    document.getElementById('cchips').addEventListener('click', function(ev){
      var b = ev.target.closest('.chip'); if(!b) return;
      [].forEach.call(this.querySelectorAll('.chip'), function(c){ c.classList.remove('sel'); });
      b.classList.add('sel');
      var a = b.getAttribute('data-amt'); var inp = document.getElementById('camt');
      if (a === 'custom') { inp.value=''; inp.focus(); } else inp.value = a;
    });
    </script>
    <?php else: ?><p style="color:#a0651a;font-size:13px">Cash declarations are being configured by the scheme office — meanwhile use MTN MoMo above or pay at the desk.</p><?php endif; ?>
  </div>
  <div class="panel">
    <h2>My cash declarations</h2>
    <div class="twrap" style="border:0"><table class="data">
      <tr><th>Declared</th><th class="num">Amount</th><th>Status</th><th>Confirmed</th></tr>
      <?php if (!$cashRows): ?><tr><td colspan="4" style="color:#5b7c99">Nothing declared yet.</td></tr><?php endif; ?>
      <?php foreach ($cashRows as $cd): $bc = $cd['status']==='Confirmed'?'green':($cd['status']==='Rejected'?'red':'amber'); ?>
      <tr><td><?php echo e(date('d M Y H:i', strtotime($cd['created_at']))); ?></td>
      <td class="num"><b><?php echo ugx($cd['amount']); ?></b></td>
      <td><span class="badge <?php echo $bc; ?>"><?php echo e($cd['status']); ?></span><?php if ($cd['status']==='Rejected' && trim((string)$cd['reject_reason'])!==''): ?><br><span style="font-size:10.5px;color:#a03028"><?php echo e($cd['reject_reason']); ?></span><?php endif; ?></td>
      <td style="font-size:11.5px;color:#5b7c99"><?php echo $cd['status']==='Confirmed' ? e(trim((string)$cd['confirmed_by']) !== '' ? $cd['confirmed_by'] : 'desk') . ' &middot; ' . e($cd['confirmed_at'] ? date('d M H:i', strtotime($cd['confirmed_at'])) : '') : '—'; ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <p style="margin:10px 0 0;font-size:12.5px"><a href="portal-analytics.php">Deposits, analytics &amp; charts &raquo;</a> &nbsp;&middot;&nbsp; <a href="portal-settlements.php">My bill clearings &raquo;</a></p>
  </div>
</div>

<div class="panel">
  <h2>How mobile money deposits work</h2>
  <ol class="steps">
    <li>Press <b>Pay with MTN Mobile Money</b> with the amount you want.</li>
    <li>A prompt is pushed to your phone — dial <b>*165*6#</b> if it doesn't appear, then enter your MoMo PIN.</li>
    <li>Kisubi Hospital is notified instantly; your balance updates and an SMS receipt arrives.</li>
    <li>Didn't approve in time? The request safely expires — no money moves, and you can start a new one.</li>
  </ol>
</div>
<?php include __DIR__ . '/includes/portal_footer.php'; ?>
