<?php
require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
/** Dolibarr 24 Expedition::create incorrectly rolls back when notrigger=1.
 * A local import subclass suppresses its trigger dispatcher instead; core is unchanged.
 */
final class LexwareImportExpedition extends Expedition
{
    public function call_trigger($triggerName, $user) { return 1; }
}
