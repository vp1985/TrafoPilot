<?php
require_once __DIR__.'/lib/bootstrap.php';
$r = $lxStore->one('resource', GETPOSTINT('id')); if (!$r) { accessforbidden(); }
$notice = '';
try {
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
print '<p>'.lxEscape($notice).'</p><p>'.lxEscape($r['resource_type'].' · '.$r['remote_id']).'</p>';
print '<p>Nummer: '.lxEscape($p['voucherNumber'] ?? $p['articleNumber'] ?? '').' · Status: '.lxEscape($p['voucherStatus'] ?? $r['projection_status']).' · Version: '.lxEscape($r['revision']).' · Abgleich: '.lxEscape($r['date_sync']).'</p>';
print '<p>Archiviert: '.(int) $r['archived'].' · Nicht mehr vorhanden: '.(int) $r['missing'].' · '.lxEscape($r['last_error']).'</p>';
print '<form method="POST"><input type="hidden" name="token" value="'.lxEscape(newToken()).'"><input type="hidden" name="id" value="'.(int) $r['rowid'].'">';
if ($user->hasRight('hwoslexware','sync')) { print '<button name="action" value="refresh">Jetzt aus Lexware neu laden</button>'; }
if ($user->hasRight('hwoslexware','retry') && $user->hasRight('hwoslexware','sync')) { print '<button name="action" value="project">Projektion erneut verarbeiten</button>'; }
print '</form><h2>Dokumentkette</h2>';
if ($r['resource_type'] === 'contacts' && $user->hasRight('hwoslexware','mapping')) {
    $mapping = $lxStore->mapping((int) $r['rowid']);
    print '<form method="POST"><input type="hidden" name="token" value="'.lxEscape(newToken()).'"><input type="hidden" name="id" value="'.(int) $r['rowid'].'"><input type="hidden" name="action" value="map"><label>Dolibarr-Dritter-ID <input type="number" name="object" min="1" value="'.(int) ($mapping['object_id'] ?? 0).'" required></label><label><input type="checkbox" name="confirm" value="yes" required> Zuordnung bewusst ändern; bestehende Felder erhalten</label><button>Zuordnung speichern</button></form>';
}
foreach ($lxStore->rows('SELECT related_id,related_type FROM '.$lxStore->table('relation').' WHERE entity='.$lxStore->entity.' AND fk_resource='.(int) $r['rowid']) as $rel) {
    $target = $lxStore->find($rel['related_type'], $rel['related_id']);
    print '<p>'.($target ? '<a href="resource.php?id='.(int) $target['rowid'].'">' : '').lxEscape($rel['related_type'].' '.$rel['related_id']).($target ? '</a>' : '').'</p>';
}
$contact = $p['address']['contactId'] ?? $p['contactId'] ?? null;
if ($contact && ($c = $lxStore->find('contacts',$contact))) { print '<p>Kunde: <a href="resource.php?id='.(int) $c['rowid'].'">'.lxEscape($contact).'</a></p>'; }
if ($pay = $lxStore->find('payments',$r['remote_id'])) { print '<h2>Zahlungsinformationen (Lexware-Spiegel)</h2><pre>'.lxEscape(json_encode(json_decode($pay['payload_json']), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>'; }
print '<h2>Originaldateien</h2>';
foreach ($lxStore->rows('SELECT rowid,representation,mime_type,content_hash FROM '.$lxStore->table('file').' WHERE entity='.$lxStore->entity.' AND fk_resource='.(int) $r['rowid']) as $f) {
    print '<p><a href="file.php?id='.(int) $f['rowid'].'">'.lxEscape($f['representation'].' · '.$f['mime_type']).'</a> · '.lxEscape($f['content_hash']).'</p>';
}
print '<details><summary>Geschützter vollständiger API-Payload</summary><pre>'.lxEscape($r['payload_json']).'</pre></details>'; llxFooter();
