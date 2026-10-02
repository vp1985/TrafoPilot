<?php
declare(strict_types=1);
require_once __DIR__.'/LexwareSync.php';
final class LexwareJobs
{
    public string $error = '';
    public string $output = '';
    public function __construct(private $db) {}
    public function run(): int
    {
        global $conf, $user;
        try {
            LexwareAccess::require($user,'sync');
            $s = new LexwareStore($this->db,(int) $conf->entity);
            $sync = new LexwareSync($s,LexwareClient::fromEnvironment());
            $pending=$s->rows('SELECT rowid FROM '.$s->table('run')." WHERE entity=".$s->entity." AND status='pending' AND mode<>'dry' ORDER BY rowid LIMIT 1");
            if ($pending) { $id=(int) $pending[0]['rowid']; }
            else {
                $last=$s->rows('SELECT date_creation FROM '.$s->table('run')." WHERE entity=".$s->entity." AND mode='full' AND status='complete' ORDER BY rowid DESC LIMIT 1");
                $mode=!$last || strtotime($last[0]['date_creation']) < time()-7*86400 ? 'full' : 'incremental';
                $id=$sync->start($user,$mode,'cron');
            }
            $run=$sync->batch($id,$user,5);
            $this->output='TrafoPilot Lexware Lauf '.$id.': '.$run['status']; return 0;
        } catch (Throwable $e) {
            $this->error=preg_match('/^[A-Z_0-9]+$/',$e->getMessage()) ? $e->getMessage() : 'LEXWARE_CRON_FAILED'; return -1;
        }
    }
}
