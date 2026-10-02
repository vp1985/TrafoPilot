<?php
/** Local native copies and follow-up documents do not own the remote identity. */
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once __DIR__.'/../../class/LexwareStore.php';

class InterfaceLocalIdentity extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db; $this->name = 'LocalIdentity'; $this->family = 'interface';
        $this->description = 'TrafoPilot: clear inherited Lexware identity on local documents';
        $this->version = '0.1.0'; $this->picto = 'exchange';
    }
    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        if (!isModEnabled('hwoslexware')) { return 0; }
        $types = ['PROPAL_CREATE'=>['propal','propal'], 'ORDER_CREATE'=>['order','commande'], 'BILL_CREATE'=>['invoice','facture']];
        if (!isset($types[$action])) { return 0; }
        [$type,$right] = $types[$action];
        $uuid = (string) ($object->array_options['options_hwoslexware_uuid'] ?? '');
        $external = (string) ($object->ref_ext ?? '');
        if ($uuid === '' && $external === '') { return 0; }
        try {
            $s = new LexwareStore($this->db,(int) $conf->entity);
            // Existing mapped originals and trigger-free imports are preserved.
            $mapped = $s->rows('SELECT rowid FROM '.$s->table('mapping').' WHERE entity='.$s->entity.' AND object_type='.$s->q($type).' AND object_id='.(int) $object->id);
            if ($mapped) { return 0; }
            $resources = $s->rows('SELECT * FROM '.$s->table('resource').' WHERE entity='.$s->entity.' AND organization_id='.$s->q(LexwareClient::ORGANIZATION).' AND remote_id IN ('.$s->q($uuid).','.$s->q($external).') LIMIT 1');
            if (!$resources) { return 0; }
            if (!$user->hasRight($right,'creer')) { throw new DomainException('LOCAL_DOCUMENT_CREATE_PERMISSION_DENIED'); }
            if ($uuid !== '') {
                $object->array_options['options_hwoslexware_uuid'] = null;
                if ($object->insertExtraFields() < 0) { throw new RuntimeException('LOCAL_IDENTITY_UPDATE_FAILED'); }
            }
            if ($external === $resources[0]['remote_id']) {
                $s->query('UPDATE '.MAIN_DB_PREFIX.$object->table_element.' SET ref_ext=NULL WHERE entity='.$s->entity.' AND rowid='.(int) $object->id);
                $object->ref_ext = '';
            }
            $s->audit('local-copy',(int) $resources[0]['fk_run'],$resources[0],['object_type'=>$type,'object_id'=>(int) $object->id,'result'=>'local_identity_cleared'],(int) $user->id);
            return 1;
        } catch (Throwable $e) {
            $this->error = preg_match('/^[A-Z_0-9]+$/',$e->getMessage()) ? $e->getMessage() : 'LEXWARE_LOCAL_IDENTITY_FAILED';
            dol_syslog($this->error, LOG_ERR);
            return -1;
        }
    }
}
