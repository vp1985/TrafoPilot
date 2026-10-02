<?php
/** CLI-only cleanup of artificial artifacts from interrupted DEV tests. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
define('NOLOGIN',1); define('NOCSRFCHECK',1);
require '/var/www/html/main.inc.php';
require_once '/var/www/html/custom/hwoslexware/class/LexwareProjection.php';
require_once '/var/www/html/custom/hwoslexware/core/modules/modHwosLexware.class.php';
require_once '/var/www/html/custom/hwoscore/core/modules/modHwosCore.class.php';
$count=0;
foreach ([1,970200] as $entity) {
    $s=new LexwareStore($db,$entity);
    $runs=$s->rows('SELECT rowid FROM '.$s->table('run')." WHERE entity=".$entity." AND context='test'");
    if (!$runs) { continue; }
    $ids=implode(',',array_map(fn($r)=>(int) $r['rowid'],$runs));
    $resources=$s->rows('SELECT rowid FROM '.$s->table('resource').' WHERE entity='.$entity.' AND fk_run IN ('.$ids.')');
    $mappings=[];
    foreach ($resources as $r) { if ($m=$s->mapping((int) $r['rowid'])) { $mappings[]=$m; } }
    $order=['shipping'=>0,'invoice'=>1,'order'=>2,'propal'=>3,'product'=>4,'thirdparty'=>5];
    usort($mappings,fn($a,$b)=>$order[$a['object_type']] <=> $order[$b['object_type']]);
    $db->begin();
    try {
        foreach ($mappings as $m) {
            [,,$table,$detail,$fk]=LexwareProjection::OBJECTS[$m['object_type']];
            $id=(int) $m['object_id'];
            $row=$s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$table.' WHERE rowid='.$id.' AND entity='.$entity)[0] ?? null;
            if ($row) {
                $marker=$table==='societe' ? $row['nom'] : $row['ref'];
                if (!str_starts_with($marker,'lx-test-')) { throw new DomainException('REFUSING_NON_FIXTURE_RECORD'); }
                if ($detail) { $s->query('DELETE FROM '.MAIN_DB_PREFIX.$detail.' WHERE '.$fk.'='.$id); }
                if ($table==='societe') {
                    $s->query('DELETE FROM '.MAIN_DB_PREFIX.'socpeople WHERE fk_soc='.$id);
                    $s->query('DELETE FROM '.MAIN_DB_PREFIX.'societe_commerciaux WHERE fk_soc='.$id);
                }
                if ($table==='product') { $s->query('DELETE FROM '.MAIN_DB_PREFIX.'product_price WHERE fk_product='.$id); }
                $element=['shipping'=>'shipping','invoice'=>'facture','order'=>'commande','propal'=>'propal','product'=>'product','thirdparty'=>'societe'][$m['object_type']];
                $s->query('DELETE FROM '.MAIN_DB_PREFIX.'element_element WHERE (fk_source='.$id.' AND sourcetype='.$s->q($element).') OR (fk_target='.$id.' AND targettype='.$s->q($element).')');
                $s->query('DELETE FROM '.MAIN_DB_PREFIX.$table.'_extrafields WHERE fk_object='.$id);
                $s->query('DELETE FROM '.MAIN_DB_PREFIX.$table.' WHERE rowid='.$id.' AND entity='.$entity); $count++;
            }
        }
        foreach ($resources as $r) {
            foreach (['mapping','issue','file','relation'] as $table) { $s->query('DELETE FROM '.$s->table($table).' WHERE entity='.$entity.' AND fk_resource='.(int) $r['rowid']); }
            $s->query('DELETE FROM '.$s->table('resource').' WHERE entity='.$entity.' AND rowid='.(int) $r['rowid']);
        }
        $s->query('DELETE FROM '.$s->table('run').' WHERE entity='.$entity.' AND rowid IN ('.$ids.')');
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
// New module was absent before this task. Restore that baseline; preserve mirrors.
foreach ([1,970200] as $entity) { $conf->entity=$entity; if ((new modHwosLexware($db))->remove()!==1) { throw new RuntimeException('MODULE_STATE_RESTORE_FAILED'); } }
$conf->entity=970200; if ((new modHwosCore($db))->remove()!==1) { throw new RuntimeException('FIXTURE_CORE_STATE_RESTORE_FAILED'); }
echo 'PASS: interrupted artificial fixtures cleaned ('.$count.' objects); new module deactivated; mirror and audit preserved'."\n";
