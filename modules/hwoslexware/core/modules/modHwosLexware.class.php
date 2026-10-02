<?php
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';
class modHwosLexware extends DolibarrModules
{
    public function __construct($db)
    {
        $this->db = $db; $this->numero = 700200; $this->rights_class = 'hwoslexware';
        $this->family = 'interface'; $this->name = 'HwosLexware'; $this->description = 'TrafoPilot Lexware Office Spiegel';
        $this->version = '0.1.0'; $this->const_name = 'MAIN_MODULE_HWOSLEXWARE'; $this->picto = 'exchange';
        $this->editor_name = 'HT-VOLTEQ GmbH'; $this->editor_url = '';
        $this->depends = ['modHwosCore']; $this->requiredby = []; $this->conflictwith = [];
        $this->phpmin = [8,1]; $this->need_dolibarr_version = [24,0];
        $this->module_parts = ['triggers'=>1]; $this->dirs = []; $this->hidden = false;
        $this->config_page_url = ['index.php@hwoslexware']; $this->langfiles = ['hwoslexware@hwoslexware'];
        $this->const = [['HWOSLEXWARE_SCHEMA_VERSION','chaine','0.1.0','TrafoPilot Lexware schema',0,'current',0]];
        $this->rights_admin_allowed = 1; $this->rights = [];
        foreach (['read','sync','mapping','retry','admin'] as $i=>$right) {
            $this->rights[$i] = [0=>700201+$i, 1=>'LexwarePermission'.ucfirst($right), 3=>0, 4=>$right];
        }
        $this->tabs = [];
        foreach (['thirdparty','product','propal','order','invoice','shipping'] as $type) {
            $this->tabs[] = $type.':+hwoslexware:Lexware:hwoslexware@hwoslexware:$user->hasRight("hwoslexware", "read"):/hwoslexware/object.php?type='.$type.'&id=__ID__';
        }
        $this->menu = [[ 'fk_menu'=>0, 'type'=>'top', 'titre'=>'TrafoPilotLexware', 'mainmenu'=>'hwoslexware',
            'leftmenu'=>'', 'url'=>'/hwoslexware/index.php', 'langs'=>'hwoslexware@hwoslexware',
            'position'=>100, 'enabled'=>'isModEnabled("hwoslexware")',
            'perms'=>'$user->hasRight("hwoslexware", "read")', 'target'=>'', 'user'=>2 ]];
        $this->cronjobs = [['label'=>'TrafoPilot Lexware Abgleich', 'jobtype'=>'method',
            'class'=>'/hwoslexware/class/LexwareJobs.php','objectname'=>'LexwareJobs','method'=>'run',
            'parameters'=>'','comment'=>'GET-only; wöchentlicher Vollabgleich, sonst inkrementell',
            'frequency'=>5,'unitfrequency'=>60,'priority'=>50,'status'=>0,'test'=>'isModEnabled("hwoslexware")']];
        $this->boxes = []; $this->dictionaries = [];
    }
    public function init($options = '')
    {
        if ($this->_load_tables('/hwoslexware/sql/') < 0) { return -1; }
        $table = MAIN_DB_PREFIX.'hwoslexware_mapping';
        $existing = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE 'projection_policy'");
        if (!$existing) { return -1; }
        if (!$this->db->num_rows($existing) && !$this->db->query("ALTER TABLE ".$table." ADD projection_policy VARCHAR(24) NOT NULL DEFAULT 'owned' AFTER remote_checksum")) { return -1; }
        global $conf;
        require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
        $extra = new ExtraFields($this->db);
        foreach (['societe','product','propal','commande','facture','expedition'] as $element) {
            $extra->fetch_name_optionals_label($element);
            if (!isset($extra->attributes[$element]['type']['hwoslexware_uuid'])) {
                $result = $extra->addExtraField('hwoslexware_uuid','Lexware UUID','varchar',100,36,$element,0,0,'','',0,
                    '$user->hasRight("hwoslexware", "read")','-1','TrafoPilot Lexware Original-ID','',$conf->entity,'hwoslexware@hwoslexware','isModEnabled("hwoslexware")',0,0,[], '',1);
                if ($result < 0) { $this->error = 'Lexware UUID extrafield migration failed'; return -1; }
            }
        }
        $this->db->begin();
        if ($this->delete_menus() !== 0 || $this->delete_tabs() !== 0 || $this->delete_module_parts() !== 0) { $this->db->rollback(); return -1; }
        $result = $this->_init([], $options);
        if ($result === 1) { $this->db->commit(); } else { $this->db->rollback(); }
        return $result;
    }
    public function remove($options = '') { return $this->_remove([], $options); }
}
