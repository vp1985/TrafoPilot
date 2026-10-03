<?php
require_once __DIR__.'/lib/bootstrap.php';
llxHeader('', 'TrafoPilot – Lexware-Spiegel'); print load_fiche_titre('TrafoPilot – Lexware-Spiegel');
print '<p><a href="index.php">Synchronisation</a></p><h2>Statistiken</h2><table class="noborder centpercent">';
foreach ($lxStore->rows('SELECT resource_type,projection_status,missing,archived,COUNT(*) AS amount FROM '.$lxStore->table('resource').' WHERE entity='.$lxStore->entity.' GROUP BY resource_type,projection_status,missing,archived ORDER BY resource_type') as $r) {
    print '<tr><td>'.lxEscape($r['resource_type']).'</td><td>'.lxEscape($r['projection_status'].((int) $r['missing'] ? ' · entfernt/inaktiv' : '').((int) $r['archived'] ? ' · archiviert' : '')).'</td><td>'.(int) $r['amount'].'</td></tr>';
}
print '</table><h2>Ressourcen</h2>';
$page = max(0, GETPOSTINT('page')); $offset = $page * 50;
print '<table class="noborder centpercent"><tr><th>Typ</th><th>Lexware-ID</th><th>Status</th><th>Version</th><th>Abgleich</th></tr>';
foreach ($lxStore->rows('SELECT rowid,resource_type,remote_id,projection_status,revision,date_sync,missing,archived FROM '.$lxStore->table('resource').' WHERE entity='.$lxStore->entity.' ORDER BY rowid DESC LIMIT 50 OFFSET '.$offset) as $r) {
    print '<tr><td>'.lxEscape($r['resource_type']).'</td><td><a href="resource.php?id='.(int) $r['rowid'].'">'.lxEscape($r['remote_id']).'</a></td><td>'.lxEscape($r['projection_status'].((int) $r['missing'] ? ' · entfernt/inaktiv' : '').((int) $r['archived'] ? ' · archiviert' : '')).'</td><td>'.lxEscape($r['revision']).'</td><td>'.lxEscape($r['date_sync']).'</td></tr>';
}
print '</table><p><a href="?page='.max(0,$page-1).'">Zurück</a> · <a href="?page='.($page+1).'">Weiter</a></p>'; llxFooter();
