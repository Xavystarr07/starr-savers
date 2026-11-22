<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

start_session();
$admin = require_admin();
$pdo = db();

function cnum(mixed $v): ?float
{
    $s = str_replace([' ', 'R', ','], ['', '', '.'], trim((string) $v));
    return $s !== '' && is_numeric($s) ? (float) $s : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = '/admin/coupons.php';
    if (!csrf_verify()) { flash_set('error', 'Your session expired. Please try again.'); header('Location: ' . $back); exit; }
    try {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);

        if ($action === 'toggle') {
            $pdo->prepare('UPDATE coupons SET is_active = 1 - is_active WHERE id = :id')->execute([':id' => $id]);
            flash_set('success', 'Code switched.');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM coupons WHERE id = :id')->execute([':id' => $id]);
            flash_set('success', 'Code deleted.');
        } elseif ($action === 'save') {
            $code  = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $type  = ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
            $value = cnum($_POST['value'] ?? null);
            $min   = cnum($_POST['min_subtotal'] ?? '0') ?? 0.0;
            $maxU  = trim((string) ($_POST['max_uses'] ?? '')) === '' ? null : max(1, (int) $_POST['max_uses']);
            $dt    = static fn (string $k): ?string => ($v = trim((string) ($_POST[$k] ?? ''))) === '' ? null : date('Y-m-d H:i:s', (int) strtotime(str_replace('T', ' ', $v)));
            $starts = $dt('starts_at'); $ends = $dt('expires_at');

            $errors = [];
            if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) { $errors[] = 'The code must be 3 to 40 letters, numbers, dashes or underscores.'; }
            if ($value === null || $value <= 0)               { $errors[] = 'Enter a discount value above 0.'; }
            if ($type === 'percent' && $value !== null && $value > 100) { $errors[] = 'A percentage cannot be more than 100.'; }
            if ($min < 0)                                      { $errors[] = 'Minimum spend cannot be negative.'; }
            if ($starts && $ends && $ends <= $starts)          { $errors[] = 'The end date must be after the start date.'; }
            $dup = $pdo->prepare('SELECT id FROM coupons WHERE code = :c AND id <> :id LIMIT 1');
            $dup->execute([':c' => $code, ':id' => $id]);
            if ($dup->fetch()) { $errors[] = 'Another code already uses that name.'; }
            if ($errors) { throw new RuntimeException(implode(' ', $errors)); }

            $row = [':c' => $code, ':t' => $type, ':v' => number_format((float) $value, 2, '.', ''), ':m' => number_format($min, 2, '.', ''),
                    ':mu' => $maxU, ':s' => $starts, ':e' => $ends, ':a' => !empty($_POST['is_active']) ? 1 : 0];
            if ($id) {
                $pdo->prepare('UPDATE coupons SET code=:c, type=:t, value=:v, min_subtotal=:m, max_uses=:mu, starts_at=:s, expires_at=:e, is_active=:a WHERE id=:id')->execute($row + [':id' => $id]);
                flash_set('success', 'Code saved.');
            } else {
                $pdo->prepare('INSERT INTO coupons (code, type, value, min_subtotal, max_uses, starts_at, expires_at, is_active) VALUES (:c,:t,:v,:m,:mu,:s,:e,:a)')->execute($row);
                flash_set('success', 'Code created.');
            }
        }
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('[StarrSavers][admin-coupons] ' . $e->getMessage());
        flash_set('error', 'Something went wrong. Nothing was saved.');
    }
    header('Location: ' . $back);
    exit;
}

$adminNav = 'coupons';
$adminTitle = 'Discounts';
require __DIR__ . '/_header.php';

$editId = (int) ($_GET['edit'] ?? 0);
$c = ['id' => 0, 'code' => '', 'type' => 'percent', 'value' => '', 'min_subtotal' => '0', 'max_uses' => '', 'starts_at' => null, 'expires_at' => null, 'is_active' => 1];
if ($editId) {
    $s = $pdo->prepare('SELECT * FROM coupons WHERE id = :id');
    $s->execute([':id' => $editId]);
    if ($f = $s->fetch()) { $c = $f; }
}
$local = static fn (?string $d): string => $d ? date('Y-m-d\TH:i', (int) strtotime($d)) : '';
$list = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM coupon_redemptions cr JOIN orders o ON o.id = cr.order_id WHERE cr.coupon_id = c.id AND o.status NOT IN ('cancelled','refunded')) AS used
                       FROM coupons c ORDER BY c.is_active DESC, c.id DESC")->fetchAll();
?>
      <h1 style="margin-bottom:16px">Discount codes</h1>

      <section class="card" style="max-width:860px;margin-bottom:20px">
        <h2 style="font-size:var(--fs-lg);margin-bottom:12px"><?= $c['id'] ? 'Edit code' : 'New code' ?></h2>
        <form method="post" class="form-grid form-grid--2">
          <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <div class="field"><label class="req" for="code">Code</label><input class="input" id="code" name="code" value="<?= e((string) $c['code']) ?>" maxlength="40" placeholder="SAVE10" style="text-transform:uppercase" required></div>
          <div class="field"><label for="type">Type</label><select class="select" id="type" name="type"><option value="percent"<?= $c['type'] === 'percent' ? ' selected' : '' ?>>Percentage off</option><option value="fixed"<?= $c['type'] === 'fixed' ? ' selected' : '' ?>>Rand amount off</option></select></div>
          <div class="field"><label class="req" for="value">Value</label><input class="input" id="value" name="value" inputmode="decimal" value="<?= e((string) $c['value']) ?>" placeholder="10" required><span class="field__hint">10 = 10% or R10, depending on the type.</span></div>
          <div class="field"><label for="min">Minimum spend (R)</label><input class="input" id="min" name="min_subtotal" inputmode="decimal" value="<?= e((string) $c['min_subtotal']) ?>"></div>
          <div class="field"><label for="mu">Maximum uses</label><input class="input" id="mu" name="max_uses" type="number" min="1" value="<?= e((string) ($c['max_uses'] ?? '')) ?>"><span class="field__hint">Empty = unlimited.</span></div>
          <div class="field"><label for="st">Starts</label><input class="input" id="st" name="starts_at" type="datetime-local" value="<?= e($local($c['starts_at'])) ?>"></div>
          <div class="field"><label for="ex">Expires</label><input class="input" id="ex" name="expires_at" type="datetime-local" value="<?= e($local($c['expires_at'])) ?>"></div>
          <label class="check" style="align-self:end"><input type="checkbox" name="is_active" value="1"<?= $c['is_active'] ? ' checked' : '' ?>><span>Active</span></label>
          <div class="span-all" style="display:flex;gap:8px"><button class="btn" type="submit"><?= $c['id'] ? 'Save changes' : 'Create code' ?></button><?php if ($c['id']): ?><a class="btn btn--ghost" href="/admin/coupons.php">Cancel</a><?php endif; ?></div>
        </form>
        <p class="text-xs text-muted" style="margin-top:10px">The discount comes off the items (not delivery) before VAT is worked out. A basket can never go below R1.</p>
      </section>

<?php if (!$list): ?>
      <div class="empty-state"><?= icon('tag') ?><h2>No codes yet</h2></div>
<?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Code</th><th>Discount</th><th class="num">Min spend</th><th>Used</th><th>Valid</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($list as $r): ?>
          <tr style="<?= $r['is_active'] ? '' : 'opacity:.55' ?>">
            <td><strong><?= e($r['code']) ?></strong></td>
            <td><?= $r['type'] === 'percent' ? e(rtrim(rtrim(number_format((float) $r['value'], 2, '.', ''), '0'), '.')) . '%' : e(zar((float) $r['value'])) ?></td>
            <td class="num"><?= (float) $r['min_subtotal'] > 0 ? e(zar((float) $r['min_subtotal'])) : '&mdash;' ?></td>
            <td><?= (int) $r['used'] ?><?= $r['max_uses'] !== null ? ' / ' . (int) $r['max_uses'] : '' ?></td>
            <td class="text-xs"><?= $r['starts_at'] ? e(fmt_date($r['starts_at'])) : 'Now' ?> &rarr; <?= $r['expires_at'] ? e(fmt_date($r['expires_at'])) : 'No end' ?></td>
            <td><span class="pill <?= $r['is_active'] ? 'pill--paid' : 'pill--cancelled' ?>"><?= $r['is_active'] ? 'Active' : 'Off' ?></span></td>
            <td style="white-space:nowrap">
              <a class="btn btn--sm btn--outline" href="/admin/coupons.php?edit=<?= (int) $r['id'] ?>">Edit</a>
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn--sm btn--ghost" type="submit"><?= $r['is_active'] ? 'Turn off' : 'Turn on' ?></button></form>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this code?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn--sm btn--ghost" style="--btn-fg:#B91C1C" type="submit">Delete</button></form>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody></table></div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
