<?php
declare(strict_types=1);
require_once __DIR__.'/LexwareProjection.php';
/** Persistent GET work queue shared by full scans, incremental scans and manual refresh. */
final class LexwareSync
{
    public function __construct(private LexwareStore $s, private LexwareClient $api) {}
    public function testConnection($user = null): array
    {
        if ($user !== null) { LexwareAccess::require($user, 'admin'); }
        elseif (PHP_SAPI !== 'cli') { throw new DomainException('LEXWARE_PERMISSION_DENIED'); }
        return $this->locked(fn($profile)=>$profile);
    }
    private function locked(callable $work)
    {
        $name = 'trafopilot-lexware-'.LexwareClient::ORGANIZATION;
        $r = $this->s->rows('SELECT GET_LOCK('.$this->s->q($name).',0) AS acquired');
        if ((int) ($r[0]['acquired'] ?? 0) !== 1) { throw new RuntimeException('LEXWARE_RUN_ALREADY_ACTIVE'); }
        try { usleep(600000); $profile = $this->api->profile(); return $work($profile); }
        finally { $this->s->query('SELECT RELEASE_LOCK('.$this->s->q($name).')'); }
    }
    private function task(array &$queue, string $path, string $type, string $id, int $priority, string $kind = 'resource', string $accept = 'application/json', int $owner = 0): void
    {
        $key = hash('sha256', $path.'|'.$accept);
        if (!isset($queue[$key])) { $queue[$key] = compact('path','type','id','priority','kind','accept','owner') + ['state'=>'pending']; }
    }
    public function start($user, string $mode, string $context = 'web', ?array $resource = null): int
    {
        LexwareAccess::require($user, 'sync');
        if (!in_array($mode, ['dry','full','incremental','refresh'], true)) { throw new InvalidArgumentException('INVALID_SYNC_MODE'); }
        return $this->locked(function ($profile) use ($user,$mode,$context,$resource) {
            if (in_array($mode, ['full','incremental'], true) && !$this->s->rows('SELECT rowid FROM '.$this->s->table('run')." WHERE entity=".$this->s->entity." AND organization_id=".$this->s->q(LexwareClient::ORGANIZATION)." AND mode='dry' AND status='complete' LIMIT 1")) { throw new DomainException('SUCCESSFUL_DRY_RUN_REQUIRED'); }
            $queue = [];
            if ($mode === 'refresh') {
                if (!$resource || !in_array($resource['resource_type'], array_merge(['contacts','articles','recurring-templates'], array_values(LexwareResources::ROUTES)), true)) { throw new DomainException('RESOURCE_REFRESH_NOT_SUPPORTED'); }
                $this->task($queue, '/v1/'.$resource['resource_type'].'/'.$resource['remote_id'], $resource['resource_type'], $resource['remote_id'], 10);
            } else {
                foreach (['countries','payment-conditions','posting-categories','print-layouts','event-subscriptions'] as $type) {
                    $this->task($queue, '/v1/'.$type, $type, 'list', 0, 'reference');
                }
                foreach (['contacts'=>10,'articles'=>20,'recurring-templates'=>30] as $type=>$priority) {
                    $this->task($queue, '/v1/'.$type.'?page=0&size=25', $type, 'page-0', $priority, 'page');
                }
                $path = '/v1/voucherlist?voucherType=any&voucherStatus=any&page=0&size=25&sort=updatedDate,ASC';
                if ($mode === 'incremental') {
                    $previous = $this->s->rows('SELECT date_creation FROM '.$this->s->table('run')." WHERE entity=".$this->s->entity." AND mode IN ('full','incremental') AND status='complete' ORDER BY rowid DESC LIMIT 1");
                    if ($previous) { $path .= '&updatedDateFrom='.gmdate('Y-m-d', strtotime($previous[0]['date_creation'])-86400); }
                }
                $this->task($queue, $path, 'voucherlist', 'page-0', 40, 'page');
            }
            $checkpoint = json_encode(['queue'=>$queue,'profile'=>$profile], JSON_THROW_ON_ERROR);
            $this->s->db->begin();
            try {
                $this->s->query('INSERT INTO '.$this->s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.
                    $this->s->entity.','.$this->s->q(LexwareClient::ORGANIZATION).','.$this->s->q($mode).",'pending',".(int) $user->id.','.$this->s->q($context).','.$this->s->q($checkpoint).",'{}',NOW())");
                $id = (int) $this->s->db->last_insert_id($this->s->table('run'));
                $this->s->audit('start', $id, null, ['mode'=>$mode,'context'=>$context], (int) $user->id);
                $this->s->db->commit(); return $id;
            } catch (Throwable $e) { $this->s->db->rollback(); throw $e; }
        });
    }
    public function batch(int $runId, $user, int $limit = 10): array
    {
        LexwareAccess::require($user, 'sync');
        return $this->locked(function ($profile) use ($runId,$user,$limit) {
            $run = $this->s->one('run', $runId);
            if (!$run || $run['organization_id'] !== LexwareClient::ORGANIZATION) { throw new DomainException('RUN_NOT_FOUND'); }
            if ($run['status'] === 'complete') { return $run; }
            $cp = json_decode($run['checkpoint_json'], true, 512, JSON_THROW_ON_ERROR);
            $stats = json_decode($run['stats_json'], true, 512, JSON_THROW_ON_ERROR);
            $queue = $cp['queue'];
            if ($run['mode'] !== 'dry') {
                $profileRow = $this->s->put($runId, 'profile', LexwareClient::ORGANIZATION, $this->api->verifiedProfilePayload(), (int) $user->id);
                $this->s->status((int) $profileRow['rowid'], 'mirror');
            }
            for ($n = 0; $n < max(1, min(25, $limit)); $n++) {
                $pending = array_filter($queue, fn($t)=>$t['state'] === 'pending');
                if (!$pending) { break; }
                uasort($pending, fn($a,$b)=>$a['priority'] <=> $b['priority']);
                $key = array_key_first($pending); $task = $pending[$key];
                $type = $task['type']; $stats[$type] ??= ['found'=>0,'new'=>0,'update'=>0,'unchanged'=>0,'mirror'=>0,'review'=>0,'conflict'=>0,'dependency'=>0,'errors'=>0,'skipped'=>0];
                try {
                    $response = $this->api->get($task['path'], $task['accept']);
                    if ($task['kind'] === 'file') {
                        $stats[$type]['found']++;
                        if ($run['mode'] !== 'dry') { $this->file($task, $response); }
                    } else {
                        $raw = $response['body']; $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                        if ($run['mode'] !== 'dry') {
                            $r = $this->s->put($runId, $task['kind'] === 'page' ? $type.'-pages' : $type, $task['id'], $raw, (int) $user->id);
                        }
                        if ($task['kind'] === 'page') {
                            if ($run['mode'] !== 'dry') { $this->s->status((int) $r['rowid'], 'mirror'); }
                            if ($type === 'voucherlist') { $stats[$type]['found'] += count($payload['content'] ?? []); }
                            $this->page($task, $payload, $queue);
                        }
                        elseif ($task['kind'] === 'reference') {
                            $items = $payload['content'] ?? $payload;
                            $stats[$type]['found'] += count($items);
                            $stats[$type]['mirror'] += count($items);
                            if ($run['mode'] !== 'dry') {
                                $this->s->status((int) $r['rowid'], 'mirror');
                                foreach (LexwareResources::referencePayloads($raw) as $itemRaw) {
                                    $item = json_decode($itemRaw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                                    if (!is_array($item)) { continue; }
                                    $remote = (string) ($item['id'] ?? $item['subscriptionId'] ?? $item['countryCode'] ?? $item['code'] ?? hash('sha256', json_encode($item)));
                                    $reference = $this->s->put($runId, $type, $remote, $itemRaw, (int) $user->id);
                                    $this->s->status((int) $reference['rowid'], 'mirror');
                                }
                            }
                        } else {
                            $stats[$type]['found']++;
                            if ($run['mode'] === 'dry') {
                                $old = $this->s->find($type, $task['id']);
                                $r = $old ?? ['rowid'=>0];
                                $preview = ['rowid'=>$old['rowid'] ?? 0,'resource_type'=>$type,'payload_json'=>$raw,'checksum'=>LexwareResources::checksum($raw)];
                                $plan = (new LexwareProjection($this->s))->plan($preview); $result = $plan['status'];
                                // Future contact dependencies are recorded explicitly in preview.
                                $cp['preview'][$type][$task['id']] = $plan;
                            } else { $result = (new LexwareProjection($this->s))->project($r, $user); }
                            $stats[$type][$result] = ($stats[$type][$result] ?? 0) + 1;
                            $this->children($task, $payload, $queue, (int) ($r['rowid'] ?? 0), $run['mode'] === 'dry');
                        }
                    }
                    $queue[$key]['state'] = 'done';
                } catch (Throwable $e) {
                    $code = $e->getCode();
                    $expected = $task['kind'] === 'file' && in_array($code, [404,406,409], true) || $type === 'payments' && in_array($code, [404,406], true) || $type === 'file-status' && $code === 404;
                    $queue[$key]['state'] = $expected ? 'skipped' : 'error';
                    $queue[$key]['error'] = $code > 0 ? 'LEXWARE_HTTP_'.$code : 'LEXWARE_TASK_FAILED';
                    $stats[$type][$expected ? 'skipped' : 'errors']++;
                    if ($code === 404 && $task['kind'] === 'resource' && $run['mode'] !== 'dry') {
                        $old = $this->s->find($type, $task['id']);
                        if ($old) { $this->s->query('UPDATE '.$this->s->table('resource').' SET missing=1 WHERE entity='.$this->s->entity.' AND rowid='.(int) $old['rowid']); }
                    }
                    $this->s->audit('error', $runId, ['resource_type'=>$type,'remote_id'=>$task['id']], ['class'=>$queue[$key]['error'],'result'=>$queue[$key]['state']], (int) $user->id);
                }
                $cp['queue'] = $queue; $this->checkpoint($runId, $cp, $stats, 'pending');
            }
            $pending = array_filter($queue, fn($t)=>$t['state'] === 'pending');
            $errors = array_filter($queue, fn($t)=>$t['state'] === 'error');
            $status = $pending ? 'pending' : ($errors ? 'errors' : 'complete');
            if ($status === 'complete' && $run['mode'] === 'full') {
                $this->s->query('UPDATE '.$this->s->table('resource').' SET missing=1 WHERE entity='.$this->s->entity.' AND organization_id='.$this->s->q(LexwareClient::ORGANIZATION).
                    " AND resource_type IN ('contacts','articles','recurring-templates','quotations','order-confirmations','invoices','down-payment-invoices','credit-notes','delivery-notes','vouchers') AND fk_run<>".$runId);
            }
            $cp['queue'] = $queue; $this->checkpoint($runId, $cp, $stats, $status);
            return $this->s->one('run', $runId);
        });
    }
    private function checkpoint(int $id, array $cp, array $stats, string $status): void
    {
        $this->s->query('UPDATE '.$this->s->table('run').' SET checkpoint_json='.$this->s->q(json_encode($cp, JSON_THROW_ON_ERROR)).
            ',stats_json='.$this->s->q(json_encode($stats, JSON_THROW_ON_ERROR)).',status='.$this->s->q($status).
            ($status === 'pending' ? '' : ',date_finished=NOW()').' WHERE entity='.$this->s->entity.' AND rowid='.$id);
    }
    private function page(array $t, array $p, array &$queue): void
    {
        if (!isset($p['content']) || !is_array($p['content']) || !isset($p['last'])) { throw new RuntimeException('PAGINATION_CONTRACT_INVALID'); }
        foreach ($p['content'] as $item) {
            $id = $item['id'] ?? ''; if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) { throw new RuntimeException('REMOTE_ID_INVALID'); }
            if ($t['type'] === 'voucherlist') {
                $type = LexwareResources::endpoint($item['voucherType'] ?? '');
                // Preserve each voucherlist item even for future unknown types.
                $this->task($queue, '/v1/'.$type.'/'.$id, $type ?? 'unknown', $id, $type === 'delivery-notes' ? 60 : ($type === 'order-confirmations' ? 45 : 50));
                if (!$type) { $queue[hash('sha256', '/v1//'.$id.'|application/json')]['state'] = 'skipped'; }
            } else { $this->task($queue, '/v1/'.$t['type'].'/'.$id, $t['type'], $id, $t['priority']+1); }
        }
        if (!$p['last']) {
            $number = (int) ($p['number'] ?? (int) substr($t['id'], 5)); $page = $number + 1;
            $path = preg_replace('/([?&])page=\d+/', '${1}page='.$page, $t['path']);
            if ($path === $t['path']) { throw new RuntimeException('PAGINATION_NOT_ADVANCING'); }
            $this->task($queue, $path, $t['type'], 'page-'.$page, $t['priority'], 'page');
        }
    }
    private function children(array $t, array $p, array &$queue, int $owner, bool $dry): void
    {
        $relations = $p['relatedVouchers'] ?? [];
        if (!empty($p['closingInvoiceId'])) { $relations[] = ['id'=>$p['closingInvoiceId'],'voucherType'=>'invoice']; }
        foreach ($relations as $rel) {
            $id = $rel['id'] ?? ''; $type = LexwareResources::endpoint($rel['voucherType'] ?? '');
            if (!$dry && $owner && $id) {
                $this->s->query('INSERT IGNORE INTO '.$this->s->table('relation').' (entity,fk_resource,related_id,related_type) VALUES ('.
                    $this->s->entity.','.$owner.','.$this->s->q($id).','.$this->s->q($type ?? $rel['voucherType'] ?? 'unknown').')');
            }
            if ($type && $id) { $this->task($queue, '/v1/'.$type.'/'.$id, $type, $id, $type === 'order-confirmations' ? 45 : 55); }
        }
        if (isset($p['voucherStatus']) && $p['voucherStatus'] !== 'draft' && in_array($t['type'], ['invoices','credit-notes','down-payment-invoices','vouchers'], true)) {
            $this->task($queue, '/v1/payments/'.$t['id'], 'payments', $t['id'], 70);
        }
        if (in_array($t['type'], ['quotations','order-confirmations','delivery-notes','invoices','down-payment-invoices','credit-notes','dunnings'], true) && ($p['voucherStatus'] ?? 'draft') !== 'draft') {
            $identity = (string) ($p['files']['documentFileId'] ?? $t['id'].'-'.($p['version'] ?? LexwareResources::checksum(json_encode($p))));
            foreach (['application/pdf','application/xml'] as $accept) {
                $this->enqueueFile($queue, '/v1/'.$t['type'].'/'.$t['id'].'/file', $identity, $accept, $owner, $dry);
            }
        }
        if ($t['type'] === 'vouchers') {
            foreach ($p['files'] ?? [] as $f) {
                $id = is_string($f) ? $f : ($f['id'] ?? '');
                if ($id) {
                    foreach (['*/*','application/pdf','application/xml'] as $accept) { $this->enqueueFile($queue, '/v1/files/'.$id, $id, $accept, $owner, $dry); }
                    $this->task($queue, '/v1/files/'.$id.'/status', 'file-status', $id, 90);
                }
            }
        }
    }
    private function enqueueFile(array &$queue, string $path, string $id, string $accept, int $owner, bool $dry): void
    {
        if ($owner && $this->s->rows('SELECT rowid FROM '.$this->s->table('file').' WHERE entity='.$this->s->entity.' AND fk_resource='.$owner.' AND remote_id='.$this->s->q($id).' AND representation='.$this->s->q($accept))) { return; }
        $this->task($queue, $path, 'files', $id, 80, 'file', $accept, $owner);
    }
    private function file(array $t, array $response): void
    {
        $body = $response['body'];
        if (strlen($body) > 32 * 1024 * 1024) { throw new RuntimeException('FILE_TOO_LARGE'); }
        $mime = explode(';', $response['headers']['content-type'] ?? 'application/octet-stream')[0];
        $this->s->query('INSERT INTO '.$this->s->table('file').' (entity,fk_resource,remote_id,representation,content_hash,mime_type,content_blob,date_sync) VALUES ('.
            $this->s->entity.','.(int) $t['owner'].','.$this->s->q($t['id']).','.$this->s->q($t['accept']).','.$this->s->q(hash('sha256', $body)).','.
            $this->s->q($mime).",UNHEX('".bin2hex($body)."'),NOW()) ON DUPLICATE KEY UPDATE content_hash=VALUES(content_hash),mime_type=VALUES(mime_type),content_blob=VALUES(content_blob),date_sync=NOW()");
    }
    public function retry(int $id, $user): void
    {
        LexwareAccess::require($user, 'retry');
        $run = $this->s->one('run', $id); if (!$run) { throw new DomainException('RUN_NOT_FOUND'); }
        $cp = json_decode($run['checkpoint_json'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($cp['queue'] as &$t) { if ($t['state'] === 'error') { $t['state'] = 'pending'; unset($t['error']); } } unset($t);
        $this->checkpoint($id, $cp, json_decode($run['stats_json'], true), 'pending');
        $this->s->audit('retry', $id, null, ['result'=>'queued'], (int) $user->id);
    }
}
