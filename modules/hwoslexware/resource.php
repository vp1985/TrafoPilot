<?php
require_once __DIR__.'/lib/bootstrap.php';
$r = $lxStore->one('resource', GETPOSTINT('id')); if (!$r) { accessforbidden(); }
$notice = '';
try {
    if (GETPOST('action','aZ09') === 'resolve') {
        lxPost();
        (new LexwareProjection($lxStore))->resolve((int) $r['rowid'], GETPOST('choice','alpha'), GETPOST('checksum','aZ09'), $user, GETPOSTINT('issue'));
        $r = $lxStore->one('resource', (int) $r['rowid']);
        $notice = 'Konfliktentscheidung gespeichert.';
    }
    if (GETPOST('action','aZ09') === 'map') {
        lxPost();
        if (GETPOST('confirm','alpha') !== 'yes') { throw new DomainException('CONFIRMATION_REQUIRED'); }
        (new LexwareProjection($lxStore))->approve((int) $r['rowid'],'thirdparty',GETPOSTINT('object'),$user,true);
        $notice = 'Zuordnung geändert; vorhandene Kontaktfelder bleiben erhalten.';
    }
    if (GETPOST('action','aZ09') === 'refresh') {
        lxPost(); LexwareAccess::require($user,'sync');
        $run = (new LexwareSync($lxStore, LexwareClient::fromEnvironment()))->start($user, 'refresh', 'web', $r);
        header('Location: index.php?run='.$run); exit;
    }
    if (GETPOST('action','aZ09') === 'project') {
        lxPost(); LexwareAccess::require($user,'retry');
        $notice = (new LexwareProjection($lxStore))->project($r, $user);
    }
} catch (Throwable $e) { $notice = lxSafeError($e); }
$p = json_decode($r['payload_json'], true);
llxHeader('', 'TrafoPilot – Lexware-Ressource'); print load_fiche_titre('TrafoPilot – Lexware-Ressource');
print '<style>.hwoslexware-resource {min-width:0;overflow-wrap:anywhere;} .hwoslexware-resource pre {white-space:pre-wrap;overflow-wrap:anywhere;max-width:100%;} .hwoslexware-resource details {overflow-wrap:anywhere;}</style><div class="hwoslexware-resource">';
print '<p>'.lxEscape($notice).'</p><p>'.lxEscape($r['resource_type'].' · '.$r['remote_id']).'</p>';
print '<p>Nummer: '.lxEscape($p['voucherNumber'] ?? $p['articleNumber'] ?? '').' · Status: '.lxEscape($p['voucherStatus'] ?? '').' · Version: '.lxEscape($r['revision']).' · Abgleich: '.lxEscape($r['date_sync']).'</p>';
lxProjectionStatus($r, $p);
print '<p>Archiviert: '.(int) $r['archived'].' · Nicht mehr vorhanden: '.(int) $r['missing'].' · '.lxEscape($r['last_error']).'</p>';
print '<form method="POST"><input type="hidden" name="token" value="'.lxEscape(newToken()).'"><input type="hidden" name="id" value="'.(int) $r['rowid'].'">';
if (LexwareAccess::allowed($user, 'sync')) { print '<button name="action" value="refresh">Jetzt aus Lexware neu laden</button>'; }
if (LexwareAccess::allowed($user, 'retry')) { print '<button name="action" value="project">Projektion erneut verarbeiten</button>'; }
print '</form><h2>Dokumentkette</h2>';
if ($r['resource_type'] === 'contacts' && LexwareAccess::allowed($user, 'mapping')) {
    $mapping = $lxStore->mapping((int) $r['rowid']);
    print '<form method="POST"><input type="hidden" name="token" value="'.lxEscape(newToken()).'"><input type="hidden" name="id" value="'.(int) $r['rowid'].'"><input type="hidden" name="action" value="map"><label>Dolibarr-Dritter-ID <input type="number" name="object" min="1" value="'.(int) ($mapping['object_id'] ?? 0).'" required></label><label><input type="checkbox" name="confirm" value="yes" required> Zuordnung bewusst ändern; bestehende Felder erhalten</label><button>Zuordnung speichern</button></form>';
}
foreach ($lxStore->rows('SELECT rowid,related_id,related_type FROM '.$lxStore->table('relation').' WHERE entity='.$lxStore->entity.' AND fk_resource='.(int) $r['rowid']) as $rel) {
    $target = $lxStore->find($rel['related_type'], $rel['related_id']);
    print '<p>'.($target ? '<a href="resource.php?id='.(int) $target['rowid'].'">' : '').lxEscape($rel['related_type'].' '.$rel['related_id'].' · '.$lxStore->relationState((int) $rel['rowid'])).($target ? '</a>' : '').'</p>';
}
$contact = $p['address']['contactId'] ?? $p['contactId'] ?? null;
if ($contact && ($c = $lxStore->find('contacts',$contact))) { print '<p>Kunde: <a href="resource.php?id='.(int) $c['rowid'].'">'.lxEscape($contact).'</a></p>'; }
if ($pay = $lxStore->find('payments',$r['remote_id'])) { print '<h2>Zahlungsinformationen (Lexware-Spiegel)</h2><pre>'.lxEscape(json_encode(json_decode($pay['payload_json']), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>'; }
print '<h2>Originaldateien</h2>';
foreach ($lxStore->rows('SELECT rowid,representation,mime_type,content_hash FROM '.$lxStore->table('file').' WHERE entity='.$lxStore->entity.' AND fk_resource='.(int) $r['rowid']) as $f) {
    print '<p><a href="file.php?id='.(int) $f['rowid'].'">'.lxEscape($f['representation'].' · '.$f['mime_type']).'</a> · '.lxEscape($f['content_hash']).'</p>';
}
print '<h2>Payload-Versionen</h2>';
foreach ($lxStore->rows('SELECT * FROM '.$lxStore->table('payload_version').' WHERE entity='.$lxStore->entity.' AND fk_resource='.(int) $r['rowid'].' ORDER BY rowid DESC') as $version) {
    print '<details><summary>'.lxEscape($version['revision'].' · '.$version['checksum'].' · Lauf '.$version['fk_run'].' · '.$version['date_creation']).'</summary><a href="payload.php?version=1&amp;id='.(int) $version['rowid'].'">JSON herunterladen</a><pre>'.lxEscape($version['payload_json']).'</pre></details>';
}
print '<h2>Dateiversionen</h2>';
foreach ($lxStore->rows('SELECT rowid,remote_id,representation,content_hash,date_sync FROM '.$lxStore->table('file_version').' WHERE entity='.$lxStore->entity.' AND fk_resource='.(int) $r['rowid'].' ORDER BY rowid DESC') as $version) {
    print '<p><a href="file.php?version=1&amp;id='.(int) $version['rowid'].'">'.lxEscape($version['remote_id'].' · '.$version['representation']).'</a> · '.lxEscape($version['content_hash'].' · '.$version['date_sync']).'</p>';
}
print '<h2>Statushistorie</h2>';
foreach ($lxStore->rows('SELECT * FROM '.$lxStore->table('state_event').' WHERE entity='.$lxStore->entity.' AND fk_resource='.(int) $r['rowid'].' ORDER BY rowid DESC') as $event) {
    print '<p>'.lxEscape($event['state'].' · Beziehung '.$event['fk_relation'].' · Lauf '.$event['fk_run'].' · '.$event['date_creation']).'</p>';
}
print '<h2 id="conflicts">Konfliktlösungen</h2><p><a href="issues.php">Offene Konflikte</a></p>';
if ($lxStore->rows('SELECT rowid FROM '.$lxStore->table('issue').' WHERE entity='.$lxStore->entity.' AND fk_resource='.(int) $r['rowid']." AND issue_type='conflict' AND status='open'")) { lxConflictForm($lxStore, $r, $user); }
foreach ($lxStore->rows('SELECT * FROM '.$lxStore->table('resolution').' WHERE entity='.$lxStore->entity.' AND fk_resource='.(int) $r['rowid'].' ORDER BY rowid DESC') as $resolution) {
    print '<details><summary>'.lxEscape($resolution['choice'].' · Version '.$resolution['revision'].' · '.$resolution['source_checksum'].' · Benutzer '.$resolution['fk_user'].' · '.$resolution['date_creation']).'</summary><p>Unveränderlicher vorheriger Dolibarr-Zustand</p><pre>'.lxEscape($resolution['prior_native_json']).'</pre></details>';
}
print '<h2>Auditprotokoll</h2>';
foreach ($lxStore->rows('SELECT event_type,date_event,payload_json FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$lxStore->entity." AND event_type LIKE 'lexware.%' AND object_type=".$lxStore->q($r['resource_type']).' AND object_id='.$lxStore->q($r['remote_id']).' ORDER BY rowid DESC') as $log) {
    print '<details><summary>'.lxEscape($log['event_type'].' · '.$log['date_event']).'</summary><pre>'.lxEscape($log['payload_json']).'</pre></details>';
}
print '<details><summary>Geschützter vollständiger API-Payload</summary><pre>'.lxEscape($r['payload_json']).'</pre></details></div>'; llxFooter();
