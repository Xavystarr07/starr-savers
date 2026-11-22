<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/php/mail-helper.php';

start_session();
$user = current_user();
$topics = ['order' => 'My order', 'delivery' => 'Delivery', 'returns' => 'Returns & refunds', 'product' => 'A product', 'account' => 'My account', 'other' => 'Something else'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!csrf_verify()) { throw new RuntimeException('Your session expired. Please try again.'); }
        if (trim((string) ($_POST['website'] ?? '')) !== '') {            // honeypot: bots fill this in
            flash_set('success', 'Thanks! We will get back to you soon.');
            header('Location: /contact.php'); exit;
        }
        if (!rate_limit('contact', 4, 3600)) { throw new RuntimeException('You have sent several messages already. Please try again later.'); }

        $name  = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $tp    = is_string($_POST['topic'] ?? null) ? (string) $_POST['topic'] : '';
        $topic = isset($topics[$tp]) ? $tp : 'other';
        $order = mb_substr(trim((string) ($_POST['order_number'] ?? '')), 0, 30);
        $msg   = trim((string) ($_POST['message'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120)  { throw new RuntimeException('Please enter your name.'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))      { throw new RuntimeException('Please enter a valid email address.'); }
        if (mb_strlen($msg) < 10 || mb_strlen($msg) > 3000)  { throw new RuntimeException('Please write a message of at least 10 characters.'); }

        db()->prepare('INSERT INTO contact_messages (name, email, topic, order_number, message) VALUES (:n,:e,:t,:o,:m)')
            ->execute([':n' => $name, ':e' => $email, ':t' => $topic, ':o' => $order !== '' ? $order : null, ':m' => $msg]);

        $body = '<p><strong>' . e($name) . '</strong> (' . e($email) . ') wrote about <strong>' . e($topics[$topic]) . '</strong>'
              . ($order !== '' ? ' &middot; order ' . e($order) : '') . ':</p><p>' . nl2br(e($msg)) . '</p>';
        send_mail((string) ADMIN_NOTIFY_EMAIL, 'Starr Savers', 'Website message: ' . $topics[$topic], mail_layout('New message', $body, 'Open messages', rtrim((string) APP_URL, '/') . '/admin/messages.php'), $email);
        flash_set('success', 'Thanks! We have your message and will reply by email.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('[StarrSavers][contact] ' . $e->getMessage());
        flash_set('error', 'Something went wrong. Please email us instead.');
    }
    header('Location: /contact.php');
    exit;
}

$pageTitle = 'Contact us';
$metaDesc  = 'Questions about an order, delivery or returns? Send us a message and we will reply by email.';
$nav = '';
require __DIR__ . '/includes/header.php';
?>
<h1 style="margin-bottom:8px">Contact us</h1>
<p class="text-muted" style="margin-bottom:20px">Check our <a href="/faq.php">FAQ</a> for quick answers, or send us a message.</p>
<div class="split split--checkout">
  <form method="post" class="card" style="display:grid;gap:14px">
    <?= csrf_field() ?>
    <div class="form-grid form-grid--2">
      <div class="field"><label class="req" for="cn">Your name</label><input class="input" id="cn" name="name" autocomplete="name" value="<?= e($user ? (string) $user['full_name'] : '') ?>" required></div>
      <div class="field"><label class="req" for="ce">Email</label><input class="input" id="ce" name="email" type="email" autocomplete="email" value="<?= e($user ? (string) $user['email'] : '') ?>" required></div>
      <div class="field"><label for="ct">Topic</label><select class="select" id="ct" name="topic"><?php foreach ($topics as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="co">Order number <span class="text-faint">(optional)</span></label><input class="input" id="co" name="order_number" maxlength="30" placeholder="SS-20261120-0001"></div>
    </div>
    <div class="field"><label class="req" for="cm">Message</label><textarea class="textarea" id="cm" name="message" rows="6" maxlength="3000" required></textarea></div>
    <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <button class="btn btn--lg" type="submit">Send message</button>
  </form>
  <aside class="card">
    <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Other ways to reach us</h2>
    <p class="text-sm"><strong>Email</strong><br><a href="mailto:<?= e((string) setting('store_email')) ?>"><?= e((string) setting('store_email')) ?></a></p>
    <p class="text-sm" style="margin-top:12px"><strong>Phone</strong><br><?= e((string) setting('store_phone')) ?></p>
    <p class="text-sm" style="margin-top:12px"><strong>Delivery hours</strong><br><?= e((string) setting('delivery_hours')) ?></p>
    <p class="text-sm" style="margin-top:12px"><strong>Address</strong><br><?= e((string) setting('legal_physical_address')) ?></p>
    <p class="text-xs text-muted" style="margin-top:16px">Returning something? Start it from <a href="/returns.php">Returns</a>.</p>
  </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
