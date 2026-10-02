<?php
// Standard external-module bootstrap; no NOLOGIN/NOCSRFCHECK for web requests.
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
