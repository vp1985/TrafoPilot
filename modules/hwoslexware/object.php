<?php
require __DIR__.'/lib/bootstrap.php';
$type = GETPOST('type','aZ09'); $id = GETPOSTINT('id');
if (!isset(LexwareProjection::OBJECTS[$type])) { accessforbidden(); }
(new LexwareProjection($lxStore))->object($type,$id);
llxHeader('', 'TrafoPilot – Lexware'); print load_fiche_titre('TrafoPilot – Lexware');
$rows = $lxStore->rows('SELECT r.* FROM '.$lxStore->table('resource').' r JOIN '.$lxStore->table('mapping').' m ON m.fk_resource=r.rowid AND m.entity=r.entity WHERE r.entity='.$lxStore->entity.' AND m.object_type='.$lxStore->q($type).' AND m.object_id='.$id);
foreach ($rows as $r) {
    $p = json_decode($r['payload_json'],true);
    print '<p><a href="resource.php?id='.(int) $r['rowid'].'">'.lxEscape($r['remote_id']).'</a> · '.lxEscape($p['voucherNumber'] ?? '').' · '.lxEscape($p['voucherStatus'] ?? $r['projection_status']).' · Version '.lxEscape($r['revision']).' · '.lxEscape($r['date_sync']).'</p>';
    if ($pay = $lxStore->find('payments',$r['remote_id'])) { print '<pre>'.lxEscape($pay['payload_json']).'</pre>'; }
    if ($type === 'thirdparty') {
        foreach ($lxStore->rows('SELECT rowid,payload_json FROM '.$lxStore->table('resource').' WHERE entity='.$lxStore->entity." AND resource_type NOT LIKE '%-pages'") as $doc) {
            $p = json_decode($doc['payload_json'],true);
            if (($p['address']['contactId'] ?? $p['contactId'] ?? '') === $r['remote_id']) { print '<p><a href="resource.php?id='.(int) $doc['rowid'].'">'.lxEscape($p['voucherNumber'] ?? 'Lexware-Dokument').'</a></p>'; }
        }
    }
}
if (!$rows) { print '<p>Keine Lexware-Zuordnung.</p>'; } llxFooter();
