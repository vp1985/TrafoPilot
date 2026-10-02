<?php
require_once __DIR__.'/lib/bootstrap.php'; $notice = '';
try {
    if (GETPOST('action','aZ09') === 'map') {
        lxPost();
        (new LexwareProjection($lxStore))->approve(GETPOSTINT('resource'), 'thirdparty', GETPOSTINT('object'), $user);
        $notice = 'Zuordnung gespeichert; bestehender Kontakt wurde nicht überschrieben.';
    }
} catch (Throwable $e) { $notice = lxSafeError($e); }
llxHeader('', 'TrafoPilot – Lexware-Konflikte'); print load_fiche_titre('TrafoPilot – Lexware-Konflikte');
print '<p>'.lxEscape($notice).'</p><p><a href="index.php">Synchronisation</a></p>';
$issues = $lxStore->rows('SELECT * FROM '.$lxStore->table('issue')." WHERE entity=".$lxStore->entity." AND status='open' ORDER BY rowid DESC LIMIT 100");
if (!$issues) {
    $latest = $lxStore->rows('SELECT status,date_finished FROM '.$lxStore->table('run')." WHERE entity=".$lxStore->entity." AND status='complete' ORDER BY rowid DESC LIMIT 1");
    $detail = $latest && $latest[0]['status'] === 'complete'
        ? ' Letzter erfolgreicher Abgleich: '.lxEscape((string) $latest[0]['date_finished']).'.'
        : ' Es liegt noch kein erfolgreich abgeschlossener Abgleich vor.';
    print '<div class="info">Keine offenen Lexware-Konflikte.'.$detail.'</div>';
}
foreach ($issues as $i) {
    print '<p><a href="resource.php?id='.(int) $i['fk_resource'].'">Ressource '.(int) $i['fk_resource'].'</a> · '.lxEscape($i['issue_type']).'</p><pre>'.lxEscape($i['details_json']).'</pre>';
    if ($user->hasRight('hwoslexware','mapping') && in_array($i['issue_type'],['review','ambiguous'],true)) {
        print '<form method="POST"><input type="hidden" name="token" value="'.lxEscape(newToken()).'"><input type="hidden" name="action" value="map"><input type="hidden" name="resource" value="'.(int) $i['fk_resource'].'"><label>Dolibarr-Dritter-ID <input type="number" min="1" name="object" required></label><button>Zuordnung bestätigen</button></form>';
    }
} llxFooter();
