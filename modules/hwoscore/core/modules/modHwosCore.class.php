<?php

/**
 * HWOS Core module descriptor.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modHwosCore extends DolibarrModules
{
    /**
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;

        $this->numero = 700100;
        $this->rights_class = 'hwoscore';
        $this->family = 'other';
        $this->module_position = '90';
        $this->name = 'HwosCore';
        $this->description = 'Shared foundation for HWOS Dolibarr modules';
        $this->version = '0.1.0';
        $this->const_name = 'MAIN_MODULE_HWOSCORE';
        $this->picto = 'technic';

        $this->editor_name = 'HT-VOLTEQ GmbH';
        $this->editor_url = '';

        $this->module_parts = array();
        $this->dirs = array('/hwoscore/temp');
        $this->config_page_url = array();

        $this->hidden = false;
        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->phpmin = array(8, 1);
        $this->need_dolibarr_version = array(24, 0);
        $this->langfiles = array('hwoscore@hwoscore');

        $this->const = array(
            array(
                'HWOSCORE_SCHEMA_VERSION',
                'chaine',
                '0.1.0',
                'Installed HWOS Core schema version',
                0,
                'current',
                0,
            ),
        );
        $this->tabs = array();
        $this->dictionaries = array();
        $this->boxes = array();
        $this->cronjobs = array();
        $this->menu = array();

        $this->rights = array();
        $this->rights_admin_allowed = 1;

        $this->rights[0][0] = 700101;
        $this->rights[0][1] = 'HwosCorePermissionRead';
        $this->rights[0][3] = 1;
        $this->rights[0][4] = 'read';

        $this->rights[1][0] = 700102;
        $this->rights[1][1] = 'HwosCorePermissionAdmin';
        $this->rights[1][3] = 0;
        $this->rights[1][4] = 'admin';
    }

    /**
     * Enable the module and apply idempotent schema migrations.
     *
     * @param string $options Activation options
     * @return int 1 on success, 0 or -1 on failure
     */
    public function init($options = '')
    {
        $result = $this->_load_tables('/hwoscore/sql/');
        if ($result < 0) {
            return -1;
        }

        return $this->_init(array(), $options);
    }

    /**
     * Disable the module without deleting business or audit data.
     *
     * @param string $options Deactivation options
     * @return int 1 on success, 0 on failure
     */
    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
