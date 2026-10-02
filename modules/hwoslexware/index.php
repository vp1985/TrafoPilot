<?php
require_once __DIR__.'/lib/bootstrap.php';
$action = GETPOST('action', 'aZ09'); $runId = GETPOSTINT('run'); $notice = '';
try {
    if ($action) {
        lxPost();
        // Reject unauthorized actions before loading configuration or creating a client.
        $required = ['test'=>'admin','dry'=>'sync','full'=>'sync','incremental'=>'sync','batch'=>'sync','known'=>'sync','retry'=>'retry'];
        if (isset($required[$action])) { LexwareAccess::require($user,$required[$action]); }
        if ($action === 'test') {
            (new LexwareSync($lxStore, LexwareClient::fromEnvironment()))->testConnection($user);
            $notice = 'GET-Verbindung bestätigt: Holger Testzentrum';
        } elseif (in_array($action, ['dry','full','incremental'], true)) {
            if ($action !== 'dry' && GETPOST('confirm', 'alpha') !== 'yes') { throw new DomainException('CONFIRMATION_REQUIRED'); }
            if ($action !== 'dry') {
                $preview = $lxStore->rows('SELECT rowid FROM '.$lxStore->table('run')." WHERE entity=".$lxStore->entity." AND mode='dry' AND status='complete' ORDER BY rowid DESC LIMIT 1");
                if (!$preview) { throw new DomainException('SUCCESSFUL_DRY_RUN_REQUIRED'); }
            }
            $runId = (new LexwareSync($lxStore, LexwareClient::fromEnvironment()))->start($user, $action);
        } elseif ($action === 'batch') {
            (new LexwareSync($lxStore, LexwareClient::fromEnvironment()))->batch($runId, $user, 5);
        } elseif ($action === 'retry') {
            (new LexwareSync($lxStore, LexwareClient::fromEnvironment()))->retry($runId, $user);
        } elseif ($action === 'known') {
            $type = GETPOST('resource_type', 'alphanohtml');
            $remote = GETPOST('remote_id', 'alphanohtml');
            if (!preg_match('/^[a-f0-9-]{36}$/i', $remote)) { throw new DomainException('REMOTE_ID_INVALID'); }
            $runId = (new LexwareSync($lxStore, LexwareClient::fromEnvironment()))->start($user, 'refresh', 'web', ['resource_type'=>$type,'remote_id'=>$remote]);
        }
    }
} catch (Throwable $e) { $notice = lxSafeError($e); }
llxHeader('', 'TrafoPilot – Lexware Office');
print load_fiche_titre('TrafoPilot – Lexware Office');
print '<p>Lexware Office → Dolibarr · ausschließlich lesend</p>';
if ($notice) { print '<p>'.lxEscape($notice).'</p>'; }
if ($user->hasRight('hwoslexware', 'admin')) {
    print '<p>Read-only-Secret: '.(getenv('LEXWARE_TEST_API_KEY_READ_ONLY') ? 'injiziert' : 'nicht injiziert').'</p>';
    print '<p>Organisation: Holger Testzentrum · '.lxEscape(LexwareClient::ORGANIZATION).'</p>';
}
print '<form method="POST"><input type="hidden" name="token" value="'.lxEscape(newToken()).'">';
if ($user->hasRight('hwoslexware', 'admin')) { print '<button name="action" value="test">Verbindung testen</button> '; }
if ($user->hasRight('hwoslexware', 'sync')) {
    print '<button name="action" value="dry">Vorschau / Dry Run</button> ';
    print '<label><input type="checkbox" name="confirm" value="yes"> Größeren Lauf bestätigen</label> ';
    print '<button name="action" value="full">Aus Lexware aktualisieren (vollständig)</button> ';
    print '<button name="action" value="incremental">Änderungen abrufen</button>';
}
print '</form><p><a href="resources.php">Spiegel und Statistiken</a> · <a href="issues.php">Fehler, Konflikte und Zuordnungen</a></p>';
if ($user->hasRight('hwoslexware','sync')) {
    print '<details><summary>Bekannte Lexware-ID abrufen (z. B. Mahnung)</summary><form method="POST"><input type="hidden" name="token" value="'.lxEscape(newToken()).'"><input type="hidden" name="action" value="known"><select name="resource_type">';
    foreach (array_unique(array_merge(['contacts','articles','recurring-templates'],array_values(LexwareResources::ROUTES))) as $type) { print '<option value="'.lxEscape($type).'">'.lxEscape($type).'</option>'; }
    print '</select><input name="remote_id" maxlength="36" required placeholder="Lexware-UUID"><button>Lesend abrufen</button></form></details>';
}
if ($runId && ($run = $lxStore->one('run', $runId))) {
    print '<h2>Lauf '.(int) $run['rowid'].' · '.lxEscape($run['status']).'</h2>';
    $cp = json_decode($run['checkpoint_json'], true); $queue = $cp['queue'];
    $done = count(array_filter($queue, fn($t)=>in_array($t['state'], ['done','skipped'], true)));
    print '<p>Fortschritt: '.$done.' / '.count($queue).' Aufgaben</p><progress value="'.$done.'" max="'.max(1,count($queue)).'"></progress>';
    print '<pre>'.lxEscape(json_encode(json_decode($run['stats_json']), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
    print '<form method="POST"><input type="hidden" name="token" value="'.lxEscape(newToken()).'"><input type="hidden" name="run" value="'.$runId.'">';
    if ($user->hasRight('hwoslexware','sync') && $run['status'] === 'pending') { print '<button name="action" value="batch">Nächsten Batch verarbeiten</button>'; }
    if ($user->hasRight('hwoslexware','retry') && $run['status'] === 'errors') { print '<button name="action" value="retry">Fehler erneut einreihen</button>'; }
    print '</form>';
    foreach ($queue as $t) { if ($t['state'] === 'error') { print '<p>'.lxEscape($t['type'].' '.$t['id'].' '.$t['error']).'</p>'; } }
    if ($run['mode'] === 'dry') { print '<details><summary>Geplante Zuordnungen und Änderungen</summary><pre>'.lxEscape(json_encode($cp['preview'] ?? [], JSON_PRETTY_PRINT)).'</pre></details>'; }
}
print '<h2>Importlauf-Historie</h2><table class="noborder centpercent"><tr><th>Lauf</th><th>Modus</th><th>Status</th><th>Beginn</th><th>Benutzer/Kontext</th></tr>';
foreach ($lxStore->rows('SELECT rowid,mode,status,date_creation,fk_user,context FROM '.$lxStore->table('run').' WHERE entity='.$lxStore->entity.' ORDER BY rowid DESC LIMIT 100') as $r) {
    print '<tr><td><a href="?run='.(int) $r['rowid'].'">'.(int) $r['rowid'].'</a></td><td>'.lxEscape($r['mode']).'</td><td>'.lxEscape($r['status']).'</td><td>'.lxEscape($r['date_creation']).'</td><td>'.lxEscape($r['fk_user'].' / '.$r['context']).'</td></tr>';
}
print '</table>'; llxFooter();
