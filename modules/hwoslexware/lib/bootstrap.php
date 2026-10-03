<?php
// Standard external-module bootstrap; no NOLOGIN/NOCSRFCHECK for web requests.
if (!defined('CSRFCHECK_WITH_TOKEN')) { define('CSRFCHECK_WITH_TOKEN', 1); }
$loaded = false;
foreach ([__DIR__.'/../../../main.inc.php', __DIR__.'/../../../../main.inc.php'] as $main) {
    if (is_file($main)) { require_once $main; $loaded = true; break; }
}
if (!$loaded) { http_response_code(500); exit('Dolibarr bootstrap unavailable'); }
require_once __DIR__.'/../class/LexwareSync.php';
if (!isModEnabled('hwoslexware')) { accessforbidden(); }
try { LexwareAccess::require($user, 'read'); } catch (Throwable $e) { accessforbidden(); }
$lxStore = new LexwareStore($db, (int) $conf->entity);
function lxEscape($v): string { return dol_escape_htmltag((string) $v); }
function lxSafeError(Throwable $e): string
{
    return preg_match('/^[A-Z_0-9]+$/', $e->getMessage()) ? $e->getMessage() : 'LEXWARE_OPERATION_FAILED';
}
function lxPost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { accessforbidden(); }
    // main.inc.php performs Dolibarr's CSRF validation before any action handler.
}
function lxConflictForm(LexwareStore $store, array $r, $user): void
{
    if (!LexwareAccess::allowed($user, 'mapping') || !$store->mapping((int) $r['rowid'])) { return; }
    $issue = $store->rows('SELECT * FROM '.$store->table('issue').' WHERE entity='.$store->entity.' AND fk_resource='.(int) $r['rowid']." AND issue_type='conflict' AND status='open'")[0] ?? null;
    if (!$issue || (json_decode($issue['details_json'], true)['source_checksum'] ?? null) !== $r['checksum']) { return; }
    print '<form method="POST"><input type="hidden" name="token" value="'.lxEscape(newToken()).'"><input type="hidden" name="action" value="resolve"><input type="hidden" name="resource" value="'.(int) $r['rowid'].'"><input type="hidden" name="id" value="'.(int) $r['rowid'].'"><input type="hidden" name="checksum" value="'.lxEscape($r['checksum']).'"><input type="hidden" name="issue" value="'.(int) $issue['rowid'].'">';
    print '<p>Einzelfallentscheidung für Lexware-Version '.lxEscape($r['revision']).' · '.lxEscape($r['checksum']).'</p><button name="choice" value="Lexware">Lexware übernehmen (vorherigen Dolibarr-Zustand sichern)</button> <button name="choice" value="Dolibarr">Dolibarr beibehalten (diese Lexware-Version akzeptieren)</button></form>';
}

function lxProjectionStatus(array $r, array $p): void
{
    print '<p>Workflow: '.lxEscape($p['voucherStatus'] ?? '').' · Projektion: '.lxEscape($r['projection_status']).'</p>';
    global $lxStore;
    $openConflict = $lxStore->rows('SELECT rowid FROM '.$lxStore->table('issue').' WHERE entity='.$lxStore->entity.' AND fk_resource='.(int) $r['rowid']." AND issue_type='conflict' AND status='open' LIMIT 1");
    if ($r['projection_status'] === 'conflict' || $openConflict) {
        print '<p role="alert" class="warning">Lexware-Konflikt: <a href="resource.php?id='.(int) $r['rowid'].'#conflicts">Konflikt prüfen</a></p>';
    }
}
