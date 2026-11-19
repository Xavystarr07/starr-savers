<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

start_session();
require_admin();

$pdo = db();

/** Settings that may be created even if the row is missing from the database. */
$extraSettings = [
    'road_distance_factor' => ['delivery', 'Road distance factor (straight-line km × this = road km)', '1.25'],
];
$boolKeys    = ['maintenance_mode', 'paystack_enabled', 'eft_enabled'];
$numericKeys = ['vat_rate', 'returns_window_days', 'free_delivery_threshold', 'min_order_value', 'bike_max_weight_kg', 'eft_hold_hours', 'road_distance_factor'];
$emailKeys   = ['store_email', 'legal_information_email'];
$textareaKeys = ['legal_physical_address'];
$groupNames  = ['general' => 'Store', 'tax' => 'Tax', 'delivery' => 'Delivery rules', 'payments' => 'Payments', 'legal' => 'Legal (shown on legal.php and the footer)'];

/** "12,5" or "R 1 200.50" → 12.5 / 1200.5 (null if not a number). */
function num(mixed $v): ?float
{
    $s = str_replace([' ', 'R', ','], ['', '', '.'], trim((string) $v));
    return $s !== '' && is_numeric($s) ? (float) $s : null;
}

// ===========================================================================
// POST ACTIONS
// ===========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $back = '/admin/settings.php' . (isset($_POST['tab']) ? '?tab=' . rawurlencode((string) $_POST['tab']) : '');
    if (!csrf_verify()) { flash_set('error', 'Your session expired. Please try again.'); header('Location: ' . $back); exit; }

    try {
        // ---------------------------------------------------------- site settings
        if ($action === 'save_settings') {
            $known = $pdo->query('SELECT setting_key FROM site_settings')->fetchAll(PDO::FETCH_COLUMN);
            $allowed = array_merge($known, array_keys($extraSettings));
            $incoming = is_array($_POST['s'] ?? null) ? $_POST['s'] : [];
            $errors = [];
            $up = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value, setting_group, label) VALUES (:k, :v, :g, :l)
                                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $pdo->beginTransaction();
            foreach ($incoming as $key => $value) {
                if (!in_array($key, $allowed, true)) { continue; }
                $value = mb_substr(trim((string) $value), 0, 2000);
                if (in_array($key, $boolKeys, true)) { $value = $value === '1' ? '1' : '0'; }
                elseif (in_array($key, $numericKeys, true)) {
                    $n = num($value);
                    if ($n === null || $n < 0) { $errors[] = '“' . $key . '” must be a number.'; continue; }
                    $value = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
                }
                elseif (in_array($key, $emailKeys, true) && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) { $errors[] = '“' . $key . '” must be a valid email.'; continue; }
                $up->execute([':k' => $key, ':v' => $value, ':g' => $extraSettings[$key][0] ?? 'general', ':l' => $extraSettings[$key][1] ?? $key]);
            }
            $pdo->commit();
            flash_set($errors ? 'error' : 'success', $errors ? implode(' ', $errors) . ' The other settings were saved.' : 'Settings saved.');
            header('Location: ' . $back); exit;
        }

        // ---------------------------------------------------------- delivery zones
        if ($action === 'save_zones') {
            $pdo->beginTransaction();
            foreach ((array) ($_POST['z'] ?? []) as $id => $z) {
                $id = (int) $id;
                if (!empty($z['delete'])) { $pdo->prepare('DELETE FROM delivery_zones WHERE id = :id')->execute([':id' => $id]); continue; }
                $min = num($z['min_km'] ?? null); $max = num($z['max_km'] ?? null); $car = num($z['car_fee'] ?? null);
                $bike = trim((string) ($z['bike_fee'] ?? '')) === '' ? null : num($z['bike_fee']);
                if ($min === null || $max === null || $max <= $min || $car === null) { throw new RuntimeException('Each zone needs a valid distance range (max above min) and a car fee.'); }
                $pdo->prepare('UPDATE delivery_zones SET zone_name=:n, min_km=:a, max_km=:b, bike_fee=:bk, car_fee=:c, sort_order=:s, is_active=:act WHERE id=:id')
                    ->execute([':n' => mb_substr(trim((string) $z['zone_name']), 0, 60) ?: 'Zone', ':a' => $min, ':b' => $max, ':bk' => $bike, ':c' => $car, ':s' => (int) ($z['sort_order'] ?? 0), ':act' => !empty($z['is_active']) ? 1 : 0, ':id' => $id]);
            }
            $n = (array) ($_POST['new_zone'] ?? []);
            if (trim((string) ($n['zone_name'] ?? '')) !== '' || trim((string) ($n['max_km'] ?? '')) !== '') {
                $min = num($n['min_km'] ?? null); $max = num($n['max_km'] ?? null); $car = num($n['car_fee'] ?? null);
                $bike = trim((string) ($n['bike_fee'] ?? '')) === '' ? null : num($n['bike_fee']);
                if ($min === null || $max === null || $max <= $min || $car === null) { throw new RuntimeException('The new zone needs a valid distance range and a car fee.'); }
                $pdo->prepare('INSERT INTO delivery_zones (zone_name, min_km, max_km, bike_fee, car_fee, sort_order) VALUES (:n,:a,:b,:bk,:c,99)')
                    ->execute([':n' => mb_substr(trim((string) $n['zone_name']), 0, 60) ?: 'New zone', ':a' => $min, ':b' => $max, ':bk' => $bike, ':c' => $car]);
            }
            $pdo->commit();
            flash_set('success', 'Delivery zones saved.');
            header('Location: ' . $back); exit;
        }

        // ---------------------------------------------------------- weight tiers
        if ($action === 'save_tiers') {
            $pdo->beginTransaction();
            foreach ((array) ($_POST['t'] ?? []) as $id => $t) {
                $id = (int) $id;
                if (!empty($t['delete'])) { $pdo->prepare('DELETE FROM delivery_weight_tiers WHERE id = :id')->execute([':id' => $id]); continue; }
                $min = num($t['min_kg'] ?? null); $max = num($t['max_kg'] ?? null); $sur = num($t['surcharge'] ?? 0) ?? 0.0;
                $veh = ($t['vehicle'] ?? '') === 'car' ? 'car' : 'bike';
                if ($min === null || $max === null || $max <= $min) { throw new RuntimeException('Each weight tier needs a valid range (max above min).'); }
                $pdo->prepare('UPDATE delivery_weight_tiers SET tier_name=:n, min_kg=:a, max_kg=:b, vehicle=:v, surcharge=:s WHERE id=:id')
                    ->execute([':n' => mb_substr(trim((string) $t['tier_name']), 0, 60) ?: 'Tier', ':a' => $min, ':b' => $max, ':v' => $veh, ':s' => $sur, ':id' => $id]);
            }
            $n = (array) ($_POST['new_tier'] ?? []);
            if (trim((string) ($n['tier_name'] ?? '')) !== '' || trim((string) ($n['max_kg'] ?? '')) !== '') {
                $min = num($n['min_kg'] ?? null); $max = num($n['max_kg'] ?? null);
                if ($min === null || $max === null || $max <= $min) { throw new RuntimeException('The new weight tier needs a valid range.'); }
                $pdo->prepare('INSERT INTO delivery_weight_tiers (tier_name, min_kg, max_kg, vehicle, surcharge, sort_order) VALUES (:n,:a,:b,:v,:s,99)')
                    ->execute([':n' => mb_substr(trim((string) $n['tier_name']), 0, 60) ?: 'New tier', ':a' => $min, ':b' => $max, ':v' => ($n['vehicle'] ?? '') === 'car' ? 'car' : 'bike', ':s' => num($n['surcharge'] ?? 0) ?? 0.0]);
            }
            $pdo->commit();
            flash_set('success', 'Weight tiers saved.');
            header('Location: ' . $back); exit;
        }

        // ---------------------------------------------------------- branches
        if ($action === 'save_branches') {
            $pdo->beginTransaction();
            $branchSql = 'name=:name, address_line1=:a1, suburb=:sub, city=:city, province=:prov, postal_code=:pc, latitude=:lat, longitude=:lng,
                          phone=:ph, email=:em, opening_hours=:oh, bike_max_km=:bk, car_max_km=:ck, offers_collection=:oc, is_active=:act';
            $collect = static function (array $b): array {
                $lat = num($b['latitude'] ?? null); $lng = num($b['longitude'] ?? null);
                if (trim((string) ($b['name'] ?? '')) === '' || trim((string) ($b['address_line1'] ?? '')) === '') { throw new RuntimeException('Each branch needs a name and a street address.'); }
                if ($lat === null || $lng === null || $lat < -35 || $lat > -22 || $lng < 16 || $lng > 33) { throw new RuntimeException('Branch coordinates must be inside South Africa (e.g. -29.8586 and 31.0218).'); }
                return [':name' => mb_substr(trim((string) $b['name']), 0, 120), ':a1' => mb_substr(trim((string) $b['address_line1']), 0, 190),
                        ':sub' => mb_substr(trim((string) ($b['suburb'] ?? '')), 0, 100), ':city' => mb_substr(trim((string) ($b['city'] ?? '')), 0, 100),
                        ':prov' => mb_substr(trim((string) ($b['province'] ?? '')), 0, 60), ':pc' => mb_substr(trim((string) ($b['postal_code'] ?? '')), 0, 10) ?: null,
                        ':lat' => $lat, ':lng' => $lng, ':ph' => mb_substr(trim((string) ($b['phone'] ?? '')), 0, 25) ?: null,
                        ':em' => mb_substr(trim((string) ($b['email'] ?? '')), 0, 190) ?: null, ':oh' => mb_substr(trim((string) ($b['opening_hours'] ?? '')), 0, 190) ?: 'Mon-Sun 08:00 - 20:00',
                        ':bk' => num($b['bike_max_km'] ?? 10) ?? 10.0, ':ck' => num($b['car_max_km'] ?? 20) ?? 20.0,
                        ':oc' => !empty($b['offers_collection']) ? 1 : 0, ':act' => !empty($b['is_active']) ? 1 : 0];
            };
            foreach ((array) ($_POST['b'] ?? []) as $id => $b) {
                $pdo->prepare("UPDATE branches SET $branchSql WHERE id = :id")->execute($collect($b) + [':id' => (int) $id]);
            }
            $n = (array) ($_POST['new_branch'] ?? []);
            if (trim((string) ($n['name'] ?? '')) !== '') {
                $vals = $collect($n + ['is_active' => 1]);
                $slugBase = slugify((string) $n['name']); $slug = $slugBase; $i = 2;
                while (true) {
                    $c = $pdo->prepare('SELECT 1 FROM branches WHERE slug = :s'); $c->execute([':s' => $slug]);
                    if (!$c->fetch()) { break; }
                    $slug = $slugBase . '-' . $i++;
                }
                $pdo->prepare("INSERT INTO branches (name, slug, address_line1, suburb, city, province, postal_code, latitude, longitude, phone, email, opening_hours, bike_max_km, car_max_km, offers_collection, is_active)
                               VALUES (:name, :slug, :a1, :sub, :city, :prov, :pc, :lat, :lng, :ph, :em, :oh, :bk, :ck, :oc, :act)")->execute($vals + [':slug' => $slug]);
            }
            $pdo->commit();
            flash_set('success', 'Branches saved.');
            header('Location: ' . $back); exit;
        }

        throw new RuntimeException('Unknown action.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash_set('error', $e->getMessage());
        header('Location: ' . $back); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[StarrSavers][admin-settings] ' . $e->getMessage());
        flash_set('error', 'Something went wrong. Nothing was saved.');
        header('Location: ' . $back); exit;
    }
}

// ===========================================================================
// PAGE
// ===========================================================================
$tab = (string) ($_GET['tab'] ?? 'store');
if (!in_array($tab, ['store', 'branches', 'zones'], true)) { $tab = 'store'; }
$adminNav = 'settings';
$adminTitle = 'Settings';
require __DIR__ . '/_header.php';

$kg = static fn ($n): string => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.') ?: '0';
?>
      <h1 style="margin-bottom:16px">Settings</h1>
      <div class="tabs" style="margin-bottom:20px">
        <a class="tab<?= $tab === 'store' ? ' is-active' : '' ?>" href="/admin/settings.php">Store &amp; payments</a>
        <a class="tab<?= $tab === 'branches' ? ' is-active' : '' ?>" href="/admin/settings.php?tab=branches">Branches</a>
        <a class="tab<?= $tab === 'zones' ? ' is-active' : '' ?>" href="/admin/settings.php?tab=zones">Delivery fees</a>
      </div>

<?php
// ------------------------------------------------------------------ STORE
if ($tab === 'store'):
    $rows = $pdo->query('SELECT setting_key, setting_value, setting_group, label FROM site_settings ORDER BY FIELD(setting_group, "general","tax","delivery","payments","legal"), setting_key')->fetchAll();
    $have = array_column($rows, 'setting_key');
    foreach ($extraSettings as $k => [$g, $label, $default]) {
        if (!in_array($k, $have, true)) { $rows[] = ['setting_key' => $k, 'setting_value' => $default, 'setting_group' => $g, 'label' => $label]; }
    }
    $byGroup = [];
    foreach ($rows as $r) { $byGroup[$r['setting_group']][] = $r; }
?>
      <form method="post" style="display:grid;gap:16px;max-width:860px">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
<?php foreach ($groupNames as $g => $title): if (empty($byGroup[$g])) { continue; } ?>
        <section class="card">
          <h2 style="font-size:var(--fs-lg);margin-bottom:16px"><?= e($title) ?></h2>
          <div class="form-grid form-grid--2">
<?php foreach ($byGroup[$g] as $r): $k = $r['setting_key']; $v = (string) $r['setting_value']; $id = 's_' . $k; ?>
            <div class="field<?= in_array($k, $textareaKeys, true) ? ' span-all' : '' ?>">
              <label for="<?= e($id) ?>"><?= e($r['label'] ?: $k) ?></label>
<?php if (in_array($k, $boolKeys, true)): ?>
              <select class="select" id="<?= e($id) ?>" name="s[<?= e($k) ?>]"><option value="1"<?= $v === '1' ? ' selected' : '' ?>>On</option><option value="0"<?= $v !== '1' ? ' selected' : '' ?>>Off</option></select>
<?php if ($k === 'maintenance_mode'): ?><span class="field__hint">On = shoppers see a “back soon” page. You can still use the admin.</span><?php endif; ?>
<?php elseif (in_array($k, $textareaKeys, true)): ?>
              <textarea class="textarea" id="<?= e($id) ?>" name="s[<?= e($k) ?>]" rows="2" style="min-height:70px"><?= e($v) ?></textarea>
<?php else: ?>
              <input class="input" id="<?= e($id) ?>" name="s[<?= e($k) ?>]" value="<?= e($v) ?>"<?= in_array($k, $numericKeys, true) ? ' inputmode="decimal"' : '' ?><?= in_array($k, $emailKeys, true) ? ' type="email"' : '' ?>>
<?php endif; ?>
            </div>
<?php endforeach; ?>
          </div>
        </section>
<?php endforeach; ?>
        <div><button class="btn btn--lg" type="submit">Save settings</button></div>
        <p class="text-xs text-muted">Secret keys (Paystack, SMTP) are not stored here for safety. They live in <code>config/app.local.php</code> on the server.</p>
      </form>

<?php
// ------------------------------------------------------------------ BRANCHES
elseif ($tab === 'branches'):
    $branches = $pdo->query('SELECT * FROM branches ORDER BY is_active DESC, name')->fetchAll();
    $branchField = static function (string $prefix, array $b, string $name, string $label, string $extra = '', bool $wide = false): void {
        echo '<div class="field' . ($wide ? ' span-all' : '') . '"><label>' . e($label) . '<input class="input" style="margin-top:6px" name="' . e($prefix) . '[' . e($name) . ']" value="' . e((string) ($b[$name] ?? '')) . '" ' . $extra . '></label></div>';
    };
?>
      <p class="text-muted" style="max-width:720px;margin-bottom:16px">Branches are the pick-and-pack points deliveries leave from. Each order is fulfilled from the branch <strong>nearest to the customer</strong>. To get coordinates: open Google Maps, right-click the branch location and click the numbers that appear (e.g. <em>-29.8586, 31.0218</em>) — the first is latitude, the second is longitude.</p>
      <form method="post" style="display:grid;gap:16px;max-width:960px">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_branches"><input type="hidden" name="tab" value="branches">
<?php foreach ($branches as $b): $p = 'b[' . (int) $b['id'] . ']'; ?>
        <section class="card" style="<?= $b['is_active'] ? '' : 'opacity:.65' ?>">
          <div class="card__title"><h2 style="font-size:var(--fs-lg)"><?= e($b['name']) ?></h2>
            <label class="check"><input type="checkbox" name="<?= e($p) ?>[is_active]" value="1"<?= $b['is_active'] ? ' checked' : '' ?>><span>Active</span></label></div>
          <div class="form-grid form-grid--2">
            <?php $branchField($p, $b, 'name', 'Branch name', 'required'); $branchField($p, $b, 'address_line1', 'Street address', 'required'); ?>
            <?php $branchField($p, $b, 'suburb', 'Suburb'); $branchField($p, $b, 'city', 'City'); $branchField($p, $b, 'province', 'Province'); $branchField($p, $b, 'postal_code', 'Postal code', 'maxlength="4"'); ?>
            <?php $branchField($p, $b, 'latitude', 'Latitude', 'inputmode="decimal" required'); $branchField($p, $b, 'longitude', 'Longitude', 'inputmode="decimal" required'); ?>
            <?php $branchField($p, $b, 'phone', 'Phone'); $branchField($p, $b, 'email', 'Email', 'type="email"'); $branchField($p, $b, 'opening_hours', 'Opening hours', '', true); ?>
            <?php $branchField($p, $b, 'bike_max_km', 'Bike range (km)', 'inputmode="decimal"'); $branchField($p, $b, 'car_max_km', 'Car range (km)', 'inputmode="decimal"'); ?>
            <label class="check span-all"><input type="checkbox" name="<?= e($p) ?>[offers_collection]" value="1"<?= $b['offers_collection'] ? ' checked' : '' ?>><span>Customers can collect orders here</span></label>
          </div>
        </section>
<?php endforeach; ?>

        <section class="card" style="border:2px dashed var(--color-border)">
          <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Add a branch</h2>
          <div class="form-grid form-grid--2">
            <?php $nb = ['province' => 'KwaZulu-Natal', 'bike_max_km' => '10', 'car_max_km' => '20', 'opening_hours' => 'Mon-Sun 08:00 - 20:00']; $p = 'new_branch'; ?>
            <?php $branchField($p, $nb, 'name', 'Branch name'); $branchField($p, $nb, 'address_line1', 'Street address'); ?>
            <?php $branchField($p, $nb, 'suburb', 'Suburb'); $branchField($p, $nb, 'city', 'City'); $branchField($p, $nb, 'province', 'Province'); $branchField($p, $nb, 'postal_code', 'Postal code', 'maxlength="4"'); ?>
            <?php $branchField($p, $nb, 'latitude', 'Latitude', 'inputmode="decimal"'); $branchField($p, $nb, 'longitude', 'Longitude', 'inputmode="decimal"'); ?>
            <?php $branchField($p, $nb, 'phone', 'Phone'); $branchField($p, $nb, 'email', 'Email', 'type="email"'); $branchField($p, $nb, 'opening_hours', 'Opening hours', '', true); ?>
            <?php $branchField($p, $nb, 'bike_max_km', 'Bike range (km)'); $branchField($p, $nb, 'car_max_km', 'Car range (km)'); ?>
            <label class="check span-all"><input type="checkbox" name="new_branch[offers_collection]" value="1" checked><span>Customers can collect orders here</span></label>
          </div>
          <p class="field__hint" style="margin-top:8px">Leave the name empty to skip adding.</p>
        </section>
        <div><button class="btn btn--lg" type="submit">Save branches</button></div>
      </form>

<?php
// ------------------------------------------------------------------ DELIVERY FEES
else:
    $zones = $pdo->query('SELECT * FROM delivery_zones ORDER BY min_km')->fetchAll();
    $tiers = $pdo->query('SELECT * FROM delivery_weight_tiers ORDER BY min_kg')->fetchAll();
?>
      <p class="text-muted" style="max-width:720px;margin-bottom:16px">A delivery fee = the <strong>distance zone</strong> fee (from the nearest branch) + the <strong>weight tier</strong> surcharge. The weight tier also decides the vehicle: tiers set to <em>Car</em> force car delivery. Leave the bike fee empty where bikes cannot go.</p>

      <form method="post" class="card" style="max-width:960px">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_zones"><input type="hidden" name="tab" value="zones">
        <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Distance zones</h2>
        <div class="table-wrap" style="box-shadow:none;border:1px solid var(--color-border-soft)">
          <table class="table" style="min-width:760px">
            <thead><tr><th>Name</th><th>From km</th><th>To km</th><th>Bike fee (R)</th><th>Car fee (R)</th><th>Active</th><th>Delete</th></tr></thead>
            <tbody>
<?php foreach ($zones as $z): $n = 'z[' . (int) $z['id'] . ']'; ?>
              <tr>
                <td><input class="input" style="min-height:40px" name="<?= $n ?>[zone_name]" value="<?= e($z['zone_name']) ?>" aria-label="Zone name"><input type="hidden" name="<?= $n ?>[sort_order]" value="<?= (int) $z['sort_order'] ?>"></td>
                <td><input class="input" style="min-height:40px;width:80px" name="<?= $n ?>[min_km]" value="<?= e($kg($z['min_km'])) ?>" inputmode="decimal" aria-label="From km"></td>
                <td><input class="input" style="min-height:40px;width:80px" name="<?= $n ?>[max_km]" value="<?= e($kg($z['max_km'])) ?>" inputmode="decimal" aria-label="To km"></td>
                <td><input class="input" style="min-height:40px;width:90px" name="<?= $n ?>[bike_fee]" value="<?= $z['bike_fee'] === null ? '' : e($kg($z['bike_fee'])) ?>" inputmode="decimal" placeholder="none" aria-label="Bike fee"></td>
                <td><input class="input" style="min-height:40px;width:90px" name="<?= $n ?>[car_fee]" value="<?= e($kg($z['car_fee'])) ?>" inputmode="decimal" aria-label="Car fee"></td>
                <td><input type="checkbox" name="<?= $n ?>[is_active]" value="1"<?= $z['is_active'] ? ' checked' : '' ?> aria-label="Active"></td>
                <td><input type="checkbox" name="<?= $n ?>[delete]" value="1" aria-label="Delete zone"></td>
              </tr>
<?php endforeach; ?>
              <tr style="background:#FAFCFF">
                <td><input class="input" style="min-height:40px" name="new_zone[zone_name]" placeholder="New zone name" aria-label="New zone name"></td>
                <td><input class="input" style="min-height:40px;width:80px" name="new_zone[min_km]" inputmode="decimal" placeholder="from" aria-label="New from km"></td>
                <td><input class="input" style="min-height:40px;width:80px" name="new_zone[max_km]" inputmode="decimal" placeholder="to" aria-label="New to km"></td>
                <td><input class="input" style="min-height:40px;width:90px" name="new_zone[bike_fee]" inputmode="decimal" placeholder="none" aria-label="New bike fee"></td>
                <td><input class="input" style="min-height:40px;width:90px" name="new_zone[car_fee]" inputmode="decimal" placeholder="fee" aria-label="New car fee"></td>
                <td colspan="2" class="text-xs text-muted">← add a zone</td>
              </tr>
            </tbody>
          </table>
        </div>
        <button class="btn" style="margin-top:16px" type="submit">Save zones</button>
      </form>

      <form method="post" class="card" style="max-width:960px;margin-top:16px">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_tiers"><input type="hidden" name="tab" value="zones">
        <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Weight tiers</h2>
        <div class="table-wrap" style="box-shadow:none;border:1px solid var(--color-border-soft)">
          <table class="table" style="min-width:700px">
            <thead><tr><th>Name</th><th>From kg</th><th>To kg</th><th>Vehicle</th><th>Surcharge (R)</th><th>Delete</th></tr></thead>
            <tbody>
<?php foreach ($tiers as $t): $n = 't[' . (int) $t['id'] . ']'; ?>
              <tr>
                <td><input class="input" style="min-height:40px" name="<?= $n ?>[tier_name]" value="<?= e($t['tier_name']) ?>" aria-label="Tier name"></td>
                <td><input class="input" style="min-height:40px;width:80px" name="<?= $n ?>[min_kg]" value="<?= e($kg($t['min_kg'])) ?>" inputmode="decimal" aria-label="From kg"></td>
                <td><input class="input" style="min-height:40px;width:80px" name="<?= $n ?>[max_kg]" value="<?= e($kg($t['max_kg'])) ?>" inputmode="decimal" aria-label="To kg"></td>
                <td><select class="select" style="min-height:40px;width:100px" name="<?= $n ?>[vehicle]" aria-label="Vehicle"><option value="bike"<?= $t['vehicle'] === 'bike' ? ' selected' : '' ?>>Bike</option><option value="car"<?= $t['vehicle'] === 'car' ? ' selected' : '' ?>>Car</option></select></td>
                <td><input class="input" style="min-height:40px;width:90px" name="<?= $n ?>[surcharge]" value="<?= e($kg($t['surcharge'])) ?>" inputmode="decimal" aria-label="Surcharge"></td>
                <td><input type="checkbox" name="<?= $n ?>[delete]" value="1" aria-label="Delete tier"></td>
              </tr>
<?php endforeach; ?>
              <tr style="background:#FAFCFF">
                <td><input class="input" style="min-height:40px" name="new_tier[tier_name]" placeholder="New tier name" aria-label="New tier name"></td>
                <td><input class="input" style="min-height:40px;width:80px" name="new_tier[min_kg]" inputmode="decimal" placeholder="from" aria-label="New from kg"></td>
                <td><input class="input" style="min-height:40px;width:80px" name="new_tier[max_kg]" inputmode="decimal" placeholder="to" aria-label="New to kg"></td>
                <td><select class="select" style="min-height:40px;width:100px" name="new_tier[vehicle]" aria-label="New vehicle"><option value="bike">Bike</option><option value="car">Car</option></select></td>
                <td><input class="input" style="min-height:40px;width:90px" name="new_tier[surcharge]" inputmode="decimal" placeholder="0" aria-label="New surcharge"></td>
                <td class="text-xs text-muted">← add a tier</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="field__hint" style="margin-top:8px">Keep tiers continuous (e.g. 0–8, 8.01–15, 15.01–40…) so every order weight is covered. Heavier than the last tier uses the last tier.</p>
        <button class="btn" style="margin-top:16px" type="submit">Save weight tiers</button>
      </form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
