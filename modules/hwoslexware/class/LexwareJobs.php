<?php
declare(strict_types=1);
require_once __DIR__.'/LexwareSync.php';
final class LexwareJobs
{
    public string $error = '';
    public string $output = '';
    private bool $selectionSuperseded = false;
    public function __construct(private $db, private ?LexwareClient $api = null) {}
    public function pendingRun(LexwareStore $s, $user): ?int
    {
        LexwareAccess::require($user, 'sync');
        $this->selectionSuperseded = false;
        $s->begin(true);
        $marked = false;
        try {
            $runs = $s->rows('SELECT * FROM '.$s->table('run').' WHERE entity='.$s->entity.' AND organization_id='.$s->q(LexwareClient::ORGANIZATION)." AND mode<>'dry' ORDER BY rowid DESC FOR UPDATE");
            $latest = (int) ($runs[0]['rowid'] ?? 0);
            foreach ($runs as $run) {
                if ($run['status'] !== 'pending' || (int) $run['rowid'] === $latest) { continue; }
                $s->query('UPDATE '.$s->table('run')." SET status='superseded',last_error='LEXWARE_RUN_SUPERSEDED',date_finished=NOW() WHERE entity=".$s->entity.' AND rowid='.(int) $run['rowid']." AND status='pending'");
                $marked = true;
                $s->audit('run_superseded', (int) $run['rowid'], null, ['result'=>'superseded','newer_run'=>$latest], (int) $user->id);
            }
            $s->commit();
            $this->selectionSuperseded = $marked;
            return $runs && $runs[0]['status'] === 'pending' ? $latest : null;
        } catch (Throwable $e) { $s->db->rollback(); throw $e; }
    }
    public function run(): int
    {
        global $conf, $user;
        try {
            LexwareAccess::require($user,'sync');
            $s = new LexwareStore($this->db,(int) $conf->entity);
            $this->error = ''; $this->output = '';
            $id = $this->pendingRun($s, $user);
            $superseded = $this->selectionSuperseded;
            // Bound retries if new runs appear between selection and batch.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                if ($id === null && $superseded) { $this->output='LEXWARE_RUN_SELECTION_DEFERRED'; return 0; }
                $sync = new LexwareSync($s,$this->api ?? LexwareClient::fromEnvironment());
                if ($id === null) {
                    $last=$s->rows('SELECT date_creation FROM '.$s->table('run')." WHERE entity=".$s->entity." AND mode='full' AND status='complete' ORDER BY rowid DESC LIMIT 1");
                    $mode=!$last || strtotime($last[0]['date_creation']) < time()-7*86400 ? 'full' : 'incremental';
                    $id=$sync->start($user,$mode,'cron');
                }
                try { $run=$sync->batch($id,$user,5); }
                catch (DomainException $e) {
                    if ($e->getMessage() !== 'LEXWARE_RUN_SUPERSEDED') { throw $e; }
                    $superseded = true;
                    $id = $this->pendingRun($s, $user);
                    continue;
                }
                $this->output='TrafoPilot Lexware Lauf '.$id.': '.$run['status']; return 0;
            }
            $this->output='LEXWARE_RUN_SELECTION_DEFERRED'; return 0;
        } catch (Throwable $e) {
            $this->error=preg_match('/^[A-Z_0-9]+$/',$e->getMessage()) ? $e->getMessage() : 'LEXWARE_CRON_FAILED'; return -1;
        }
    }
}
