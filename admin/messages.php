<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

start_session();
$admin = require_admin();
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (csrf_verify()) {
        $pdo->prepare('UPDATE contact_messages SET is_done = 1 - is_done WHERE id = :id')->execute([':id' => (int) ($_POST['id'] ?? 0)]);
    } else { flash_set('error', 'Your session expired. Please try again.'); }
    header('Location: /admin/messages.php' . (($_GET['s'] ?? '') === 'done' ? '?s=done' : ''));
    exit;
}

$adminNav = 'messages';
$adminTitle = 'Messages';
require __DIR__ . '/_header.php';

$tab = ($_GET['s'] ?? '') === 'done' ? 'done' : 'open';
$q = $pdo->prepare('SELECT * FROM contact_messages WHERE is_done = :d ORDER BY created_at DESC LIMIT 100');
$q->execute([':d' => $tab === 'done' ? 1 : 0]);
$rows = $q->fetchAll();
$open = (int) $pdo->query('SELECT COUNT(*) FROM contact_messages WHERE is_done = 0')->fetchColumn();
$topics = ['order' => 'My order', 'delivery' => 'Delivery', 'returns' => 'Returns & refunds', 'product' => 'A product', 'account' => 'My account', 'other' => 'Something else'];
?>
      <h1 style="margin-bottom:16px">Messages</h1>
      <div class="tabs" style="margin-bottom:16px">
        <a class="tab<?= $tab === 'open' ? ' is-active' : '' ?>" href="/admin/messages.php">To answer <span class="badge" style="margin-left:4px"><?= $open ?></span></a>
        <a class="tab<?= $tab === 'done' ? ' is-active' : '' ?>" href="/admin/messages.php?s=done">Answered</a>
      </div>
<?php if (!$rows): ?>
      <div class="empty-state"><?= icon('check') ?><h2>Nothing here</h2></div>
<?php else: foreach ($rows as $m): ?>
      <section class="card" style="margin-bottom:12px">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px">
          <div><strong><?= e($m['name']) ?></strong> <span class="text-muted text-sm">&middot; <?= e($topics[$m['topic']] ?? $m['topic']) ?><?= $m['order_number'] ? ' &middot; ' . e($m['order_number']) : '' ?></span><br><span class="text-xs text-muted"><?= e(fmt_date($m['created_at'])) ?> &middot; <a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a></span></div>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn--sm <?= $tab === 'open' ? '' : 'btn--outline' ?>" type="submit"><?= $tab === 'open' ? 'Mark answered' : 'Reopen' ?></button></form>
        </div>
        <p style="margin-top:10px"><?= nl2br(e((string) $m['message'])) ?></p>
        <p style="margin-top:10px"><a class="btn btn--sm btn--secondary" href="mailto:<?= e($m['email']) ?>?subject=<?= e(rawurlencode('Re: your message to ' . setting('store_name', 'Starr Savers'))) ?>">Reply by email</a></p>
      </section>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
