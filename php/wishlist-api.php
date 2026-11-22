<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/wishlist-api.php
 *   GET  ?action=load      Saved ids of the logged-in customer
 *   POST action=save       Replace the saved ids (logged-in only)   {ids:[..]}
 *   POST action=products   Product cards HTML for ids (guests too)  {ids:[..]}
 */
require_once __DIR__ . '/../includes/helpers.php';

function wish_ids(): array
{
    $ids = array_map('intval', (array) (request_data()['ids'] ?? []));
    return array_slice(array_values(array_unique(array_filter($ids, static fn ($i) => $i > 0))), 0, 200);
}

api_run(function (): void {
    start_session();
    $action = (string) ($_GET['action'] ?? request_data()['action'] ?? '');
    $pdo = db();

    if ($action === 'load') {
        api_guard('GET');
        $u = current_user();
        $ids = [];
        if ($u) {
            $s = $pdo->prepare('SELECT product_id FROM wishlist_items WHERE user_id = :u ORDER BY id');
            $s->execute([':u' => $u['id']]);
            $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        }
        json_out(['ok' => true, 'ids' => $ids]);
    }

    if ($action === 'save') {
        api_guard('POST');
        $u = require_login();
        $ids = wish_ids();
        $valid = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("SELECT id FROM products WHERE id IN ($in)");
            $s->execute($ids);
            $valid = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM wishlist_items WHERE user_id = :u')->execute([':u' => $u['id']]);
            $ins = $pdo->prepare('INSERT INTO wishlist_items (user_id, product_id) VALUES (:u, :p)');
            foreach ($valid as $pid) { $ins->execute([':u' => $u['id'], ':p' => $pid]); }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        json_out(['ok' => true, 'saved' => count($valid)]);
    }

    if ($action === 'products') {
        api_guard('POST');
        $ids = wish_ids();
        $html = '';
        $found = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare(
                "SELECT p.*, c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon
                   FROM products p JOIN categories c ON c.id = p.category_id
                  WHERE p.is_active = 1 AND c.is_active = 1 AND p.id IN ($in)"
            );
            $s->execute($ids);
            $rows = [];
            foreach ($s->fetchAll() as $r) { $rows[(int) $r['id']] = $r; }
            foreach ($ids as $id) {
                if (isset($rows[$id])) { $html .= render_product_card($rows[$id]) . "\n"; $found[] = $id; }
            }
        }
        json_out(['ok' => true, 'html' => $html, 'ids' => $found]);
    }

    fail('Unknown action.', 404);
});
