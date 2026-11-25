<?php
declare(strict_types=1);

/**
 * ONE-TIME PATCH PAGE for Phase 2b. Log in as admin, open this page once in your browser,
 * read the result, then DELETE this file. Every changed file is backed up as *.bak-phase2b.
 */
require_once __DIR__ . '/php/bootstrap.php';
start_session();
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$edits = [];
function edit(array &$edits, string $file, array $finds, string $replace, string $done): void { $edits[] = [$file, $finds, $replace, $done]; }

// ---- customer returns page: status cards instead of the plain table ---------
edit($edits, 'returns.php', ['<?php if ($requests): ?>'], <<<'T'
<?php if ($requests): require_once __DIR__ . '/php/returns-helper.php'; foreach ($requests as $rq) { return_status_card($rq); } $requests = []; endif; ?>
{FIND}
T, 'return_status_card');

// ---- refunds tied to a return must follow the workflow ----------------------
edit($edits, 'admin/refunds.php', ["\$reason = mb_substr(trim((string) (\$_POST['reason'] ?? '')), 0, 255);"], <<<'T'
$rid = (int) ($_POST['return_id'] ?? 0); if ($rid) { require_once __DIR__ . '/../php/returns-helper.php'; return_assert_refundable($rid, money_in($_POST['amount'] ?? null) ?? 0.0); }
            {FIND}
T, 'return_assert_refundable');
edit($edits, 'admin/refunds.php', ["\$retId = (int) (\$_GET['return'] ?? 0);"], <<<'T'
{FIND}
        $retCap = null; if ($retId) { require_once __DIR__ . '/../php/returns-helper.php'; $lgx = return_logistics($retId); $retCap = $lgx['refund_amount'] !== null ? (float) $lgx['refund_amount'] : null; }
T, '$retCap');
edit($edits, 'admin/refunds.php', ['value="<?= e(number_format($avail, 2, \'.\', \'\')) ?>" required>'], <<<'T'
value="<?= e(number_format($retCap !== null ? min($retCap, $avail) : $avail, 2, '.', '')) ?>" required>
T, 'min($retCap');
edit($edits, 'admin/refunds.php', ['<?php if ($r[\'processed_at\']): ?><div class="summary__row"><dt>Completed</dt>'], <<<'T'
<?php if ($r['status'] === 'processing'): ?><div class="summary__row"><dt>Expected by</dt><dd><?= e(date('j M Y', strtotime((string) $r['created_at'] . ' +7 days'))) ?></dd></div><?php endif; ?>
{FIND}
T, 'Expected by');

// ---- "up to 7 days" wording --------------------------------------------------
edit($edits, 'php/refund-helper.php', ['Card refunds usually reach your account within 5 to 10 business days.'], 'Card refunds can take up to 7 days to reach your account.', 'can take up to 7 days');
edit($edits, 'account.php', ['Card refunds take 5 to 10 business days to reach your account.'], 'Card refunds can take up to 7 days to reach your account.', 'up to 7 days');
edit($edits, 'faq.php', ['card refunds usually reach your account within 5 to 10 business days'], 'card refunds can take up to 7 days to reach your account', 'up to 7 days');
edit($edits, 'refund-slip.php', ["'This refund has been sent to the card payment provider. Card refunds usually reach your account within 5 to 10 business days.'"], <<<'T'
'This refund has been sent to the card payment provider. Card refunds can take up to 7 days to reach your account (expected by ' . date('j M Y', strtotime((string) $r['created_at'] . ' +7 days')) . ').'
T, '+7 days');

// ---- slip Back button that actually works ------------------------------------
edit($edits, 'refund-slip.php', ['<div class="bar"><a href="javascript:history.back()">Back</a><button onclick="window.print()">Print</button></div>'], <<<'T'
<div class="bar"><a href="<?= e($isAdmin ? '/admin/refunds.php?id=' . (int) $r['id'] : '/account.php?order=' . rawurlencode((string) $r['order_number']) . '&t=' . order_token((string) $r['order_number'])) ?>">Back</a><button onclick="window.print()">Print</button></div>
T, "'/admin/refunds.php?id=' . (int)");

$ok = 0; $bad = 0;
foreach ($edits as [$file, $finds, $replace, $done]) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) { echo "MISSING FILE   $file\n"; $bad++; continue; }
    $src = (string) file_get_contents($path);
    if (str_contains($src, $done)) { echo "already done   $file\n"; continue; }
    $hit = null;
    foreach ($finds as $f) { if (substr_count($src, $f) === 1) { $hit = $f; break; } }
    if ($hit === null) { echo "NOT FOUND      $file  (looking for: " . substr($finds[0], 0, 60) . "...)\n"; $bad++; continue; }
    if (!is_file($path . '.bak-phase2b')) { copy($path, $path . '.bak-phase2b'); }
    file_put_contents($path, str_replace($hit, str_replace('{FIND}', $hit, $replace), $src));
    echo "PATCHED        $file\n"; $ok++;
}
echo "\n$ok patched, $bad problems.\n";
echo $bad === 0 ? "All good. Now DELETE apply-phase2b.php.\n" : "Send me the NOT FOUND / MISSING lines above.\n";
