<?php
declare(strict_types=1);
require_once __DIR__.'/LexwareClient.php';
require_once __DIR__.'/LexwareResources.php';
final class LexwareStore
{
    private const ABSENCE_TYPES = ['contacts','articles','recurring-templates','quotations','order-confirmations','invoices','down-payment-invoices','credit-notes','delivery-notes','vouchers'];
    public function __construct(public $db, public int $entity) {}
    public function migrateHistory(): void
    {
        $rawColumn = $this->rows('SHOW COLUMNS FROM '.$this->table('payload_version')." LIKE 'raw_checksum'")[0];
        if ($rawColumn['Default'] !== null) { $this->query('ALTER TABLE '.$this->table('payload_version').' ALTER COLUMN raw_checksum DROP DEFAULT'); }
        // Additive backfill of the current released-schema rows; Duplicate-key no-ops keep
        // original bytes and provenance untouched across repeated upgrades.
        $this->immutableInsert('INSERT INTO '.$this->table('payload_version').' (entity,fk_resource,checksum,raw_checksum,revision,payload_json,fk_run,date_creation) SELECT entity,rowid,checksum,SHA2(payload_json,256),COALESCE(revision,\'\'),payload_json,fk_run,date_sync FROM '.$this->table('resource').' WHERE entity='.$this->entity);
        $columns = 'entity,fk_resource,remote_id,representation,content_hash,mime_type,content_blob,date_sync';
        $this->immutableInsert('INSERT INTO '.$this->table('file_version').' ('.$columns.') SELECT '.$columns.' FROM '.$this->table('file').' WHERE entity='.$this->entity);
    }
    public function begin(bool $owner = false): void
    {
        if ($owner) {
            if ((int) $this->db->transaction_opened !== 0) { throw new RuntimeException('LEXWARE_OUTER_TRANSACTION_REQUIRED'); }
            // Keep range locks for dependent association checks. Projection locks
            // native/dependency rows before opening any ordinary read view.
            $this->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        if ($this->db->begin() <= 0) { throw new RuntimeException('LEXWARE_TRANSACTION_FAILED'); }
    }
    public function commit(): void
    {
        if ($this->db->commit() <= 0) { throw new RuntimeException('LEXWARE_TRANSACTION_FAILED'); }
    }
    public function immutableInsert(string $sql): void
    {
        if (!preg_match('/^INSERT INTO ([a-zA-Z0-9_]+)/', $sql, $match) || !in_array($match[1], [$this->table('payload_version'),$this->table('file_version')], true)) { throw new LogicException('INVALID_IMMUTABLE_INSERT'); }
        // Only the expected unique-key duplicate is a no-op. All other SQL errors
        // (including truncation and missing required data) propagate through query.
        $this->query($sql.' ON DUPLICATE KEY UPDATE rowid='.$match[1].'.rowid');
    }
    public function q(string $v): string { return "'".$this->db->escape($v)."'"; }
    public function table(string $name): string { return MAIN_DB_PREFIX.'hwoslexware_'.$name; }
    public function query(string $sql)
    {
        $r = $this->db->query($sql);
        if (!$r) { throw new RuntimeException('LEXWARE_DATABASE_ERROR'); }
        return $r;
    }
    public function rows(string $sql): array
    {
        $r = $this->query($sql); $a = [];
        while ($v = $this->db->fetch_array($r)) { $a[] = array_filter($v, fn($key)=>is_string($key), ARRAY_FILTER_USE_KEY); }
        return $a;
    }
    public function one(string $table, int $id): ?array
    {
        return $this->rows('SELECT * FROM '.$this->table($table).' WHERE entity='.$this->entity.' AND rowid='.$id)[0] ?? null;
    }
    public function find(string $type, string $id, bool $lock = false): ?array
    {
        return $this->rows('SELECT * FROM '.$this->table('resource').' WHERE entity='.$this->entity.
            ' AND organization_id='.$this->q(LexwareClient::ORGANIZATION).' AND resource_type='.$this->q($type).' AND remote_id='.$this->q($id).($lock ? ' FOR UPDATE' : ''))[0] ?? null;
    }
    public function audit(string $event, int $run, ?array $resource, array $safe, int $user): void
    {
        $safe['run'] = $run;
        $this->query('INSERT INTO '.MAIN_DB_PREFIX.'hwoscore_audit_event (entity,event_uuid,event_type,object_type,object_id,fk_user_author,date_event,payload_json,date_creation) VALUES ('.
            $this->entity.','.$this->q(bin2hex(random_bytes(16))).','.$this->q('lexware.'.$event).','.
            $this->q($resource['resource_type'] ?? 'run').','.$this->q((string) ($resource['remote_id'] ?? $run)).','.
            ($user > 0 ? $user : 'NULL').',NOW(),'.$this->q(json_encode($safe, JSON_THROW_ON_ERROR)).',NOW())');
    }
    public function put(int $run, string $type, string $id, string $raw, int $user): array
    {
        $p = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (isset($p['organizationId']) && $p['organizationId'] !== LexwareClient::ORGANIZATION) { throw new DomainException('RESOURCE_ORGANIZATION_MISMATCH'); }
        $hash = LexwareResources::checksum($raw);
        $this->begin();
        try {
            $old = $this->find($type, $id, true);
            if ($old && (int) $old['fk_run'] > $run) { $this->commit(); return $old; }
            if ($old) { $this->payloadVersion($old); }
            $fields = 'payload_json='.$this->q($raw).',checksum='.$this->q($hash).',revision='.$this->q((string) ($p['version'] ?? '')).
                ',remote_created='.$this->q((string) ($p['createdDate'] ?? '')).',remote_updated='.$this->q((string) ($p['updatedDate'] ?? '')).
                ',archived='.(int) ($p['archived'] ?? false).',missing=0,date_sync=NOW(),fk_run='.$run;
            if ($old) {
                if ($old['checksum'] !== $hash) { $fields .= ",projection_status='pending',last_error=NULL"; }
                $this->query('UPDATE '.$this->table('resource').' SET '.$fields.' WHERE entity='.$this->entity.' AND rowid='.(int) $old['rowid']);
            } else {
                $this->query('INSERT INTO '.$this->table('resource').' SET entity='.$this->entity.',organization_id='.$this->q(LexwareClient::ORGANIZATION).
                    ',resource_type='.$this->q($type).',remote_id='.$this->q($id).','.$fields);
            }
            $resource = $this->find($type, $id);
            $this->payloadVersion($resource);
            if ($old && (int) $old['missing']) { $this->stateEvent($resource, $run, 'reappeared', $user); }
            $this->audit('mirror', $run, $resource, ['previous'=>$old['checksum'] ?? null, 'current'=>$hash, 'result'=>$old ? 'update' : 'create'], $user);
            $this->commit(); return $resource;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    private function payloadVersion(array $r): void
    {
        $this->immutableInsert('INSERT INTO '.$this->table('payload_version').' (entity,fk_resource,checksum,raw_checksum,revision,payload_json,fk_run,date_creation) VALUES ('.
            $this->entity.','.(int) $r['rowid'].','.$this->q($r['checksum']).','.$this->q(hash('sha256', $r['payload_json'])).','.$this->q((string) $r['revision']).','.$this->q($r['payload_json']).','.(int) $r['fk_run'].','.$this->q($r['date_sync']).')');
    }
    public function stateEvent(array $r, int $run, string $state, int $user, int $relation = 0): void
    {
        $this->query('INSERT INTO '.$this->table('state_event').' (entity,fk_resource,fk_relation,state,fk_run,date_creation) VALUES ('.
            $this->entity.','.(int) $r['rowid'].','.$relation.','.$this->q($state).','.$run.',NOW())');
        $this->audit($state, $run, $r, ['relation'=>$relation], $user);
    }
    public function observeRelation(int $owner, string $id, string $type, int $run, int $user): void
    {
        $this->begin();
        try {
            $r = $this->rows('SELECT * FROM '.$this->table('resource').' WHERE entity='.$this->entity.' AND rowid='.$owner.' FOR UPDATE')[0] ?? null;
            if (!$r || (int) $r['fk_run'] > $run) { $this->commit(); return; }
            $this->query('INSERT INTO '.$this->table('relation').' (entity,fk_resource,related_id,related_type) VALUES ('.$this->entity.','.$owner.','.$this->q($id).','.$this->q($type).') ON DUPLICATE KEY UPDATE rowid=rowid');
            $rel = $this->rows('SELECT rowid FROM '.$this->table('relation').' WHERE entity='.$this->entity.' AND fk_resource='.$owner.' AND related_id='.$this->q($id).' AND related_type='.$this->q($type).' FOR UPDATE')[0];
            if ($this->relationState((int) $rel['rowid']) === 'removed') { $this->stateEvent($r, $run, 'reappeared', $user, (int) $rel['rowid']); }
            $this->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function relationState(int $id): string
    {
        return $this->rows('SELECT state FROM '.$this->table('state_event').' WHERE entity='.$this->entity.' AND fk_relation='.$id.' ORDER BY rowid DESC LIMIT 1')[0]['state'] ?? 'active';
    }
    public function reconcile(int $runId, int $user): void
    {
        $run = $this->one('run', $runId);
        if (!$run || $run['mode'] !== 'full' || $run['status'] !== 'complete' || $run['organization_id'] !== LexwareClient::ORGANIZATION) { return; }
        if ($this->rows('SELECT rowid FROM '.$this->table('run').' WHERE entity='.$this->entity.' AND organization_id='.$this->q(LexwareClient::ORGANIZATION)." AND mode<>'dry' AND rowid>".$runId.' LIMIT 1 FOR UPDATE')) { return; }
        $cp = json_decode($run['checkpoint_json'], true, 512, JSON_THROW_ON_ERROR);
        // Legacy/incomplete checkpoints are never evidence of authoritative absence.
        if (($cp['authoritative'] ?? false) !== true || !isset($cp['seen'], $cp['queue'])) { return; }
        foreach ($cp['queue'] as $task) { if (!in_array($task['state'], ['done','skipped'], true)) { return; } }
        $this->begin();
        try {
            foreach ($this->rows('SELECT * FROM '.$this->table('resource').' WHERE entity='.$this->entity.' AND organization_id='.$this->q(LexwareClient::ORGANIZATION).
                " AND resource_type IN ('contacts','articles','recurring-templates','quotations','order-confirmations','invoices','down-payment-invoices','credit-notes','delivery-notes','vouchers') AND missing=0 FOR UPDATE") as $r) {
                if (($cp['coverage'][$r['resource_type']] ?? false) !== true) { continue; }
                if (!isset($cp['seen'][$r['resource_type']][$r['remote_id']])) {
                    $this->query('UPDATE '.$this->table('resource').' SET missing=1 WHERE entity='.$this->entity.' AND rowid='.(int) $r['rowid']);
                    $this->stateEvent($r, $runId, 'removed', $user);
                }
            }
            foreach ($this->rows('SELECT rel.* FROM '.$this->table('relation').' rel JOIN '.$this->table('resource').' r ON r.rowid=rel.fk_resource AND r.entity=rel.entity WHERE rel.entity='.$this->entity.' AND r.organization_id='.$this->q(LexwareClient::ORGANIZATION)) as $rel) {
                $r = $this->one('resource', (int) $rel['fk_resource']);
                if (!(int) $r['missing'] && !isset($cp['relations'][$r['rowid']])) { continue; }
                $present = !(int) $r['missing'] && isset($cp['relations'][$r['rowid']][$rel['related_type'].'|'.$rel['related_id']]);
                if (!$present && (!in_array($r['resource_type'], self::ABSENCE_TYPES, true) || ($cp['coverage'][$r['resource_type']] ?? false) !== true)) { continue; }
                $last = $this->relationState((int) $rel['rowid']);
                if (($last === 'removed') === $present) { $this->stateEvent($r, $runId, $present ? 'reappeared' : 'removed', $user, (int) $rel['rowid']); }
            }
            $this->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function status(int $id, string $status, ?string $error = null): void
    {
        $this->query('UPDATE '.$this->table('resource').' SET projection_status='.$this->q($status).',last_error='.
            ($error === null ? 'NULL' : $this->q($error)).' WHERE entity='.$this->entity.' AND rowid='.$id);
    }
    public function issue(int $id, string $type, array $details, int $user = 0): void
    {
        $this->begin();
        try {
            $r = $this->one('resource', $id);
            $details['source_checksum'] = $r['checksum'] ?? '';
            $details['revision'] = $r['revision'] ?? '';
            $raw = json_encode($details, JSON_THROW_ON_ERROR);
            $old = $this->rows('SELECT * FROM '.$this->table('issue').' WHERE entity='.$this->entity.' AND fk_resource='.$id.' AND issue_type='.$this->q($type).' FOR UPDATE')[0] ?? null;
            if (!$old || $old['status'] !== 'open' || LexwareResources::checksum($old['details_json']) !== LexwareResources::checksum($raw)) {
                // Keep the closed case identity for its immutable resolution records.
                if ($old && $type === 'conflict') {
                    $this->query('UPDATE '.$this->table('issue').' SET status=' .$this->q('resolved').',issue_type='.$this->q('conflict-'.$old['rowid']).' WHERE entity='.$this->entity.' AND rowid='.(int) $old['rowid']);
                }
                $this->query('INSERT INTO '.$this->table('issue').' (entity,fk_resource,issue_type,details_json,date_creation) VALUES ('.
                    $this->entity.','.$id.','.$this->q($type).','.$this->q($raw).',NOW()) ON DUPLICATE KEY UPDATE details_json=VALUES(details_json),status=\'open\'');
                $this->audit($type, (int) ($r['fk_run'] ?? 0), $r, ['source_checksum'=>$details['source_checksum'],'result'=>$old ? 'reopened' : 'opened'], $user);
            }
            $this->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function mapping(int $id): ?array
    {
        return $this->rows('SELECT * FROM '.$this->table('mapping').' WHERE entity='.$this->entity.' AND fk_resource='.$id.' FOR UPDATE')[0] ?? null;
    }
    public function mapped(string $type, string $id): ?array
    {
        $r = $this->find($type, $id, true); return $r ? $this->mapping((int) $r['rowid']) : null;
    }
    public function map(array $r, string $type, int $id, string $snapshot, string $policy = 'owned'): void
    {
        $owner = $this->rows('SELECT * FROM '.$this->table('mapping').' WHERE entity='.$this->entity.' AND object_type='.$this->q($type).' AND object_id='.$id.' FOR UPDATE')[0] ?? null;
        $existing = $this->mapping((int) $r['rowid']);
        if (($owner && (int) $owner['fk_resource'] !== (int) $r['rowid']) ||
            ($existing && ($existing['object_type'] !== $type || (int) $existing['object_id'] !== $id))) {
            throw new DomainException('MAPPING_OWNERSHIP_CONFLICT');
        }
        $checksum = LexwareResources::checksum($snapshot);
        if ($existing) {
            $this->query('UPDATE '.$this->table('mapping').' SET snapshot_json='.$this->q($snapshot).',snapshot_checksum='.$this->q($checksum).',remote_checksum='.$this->q($r['checksum']).
                ' WHERE entity='.$this->entity.' AND fk_resource='.(int) $r['rowid'].' AND object_type='.$this->q($type).' AND object_id='.$id);
        } else {
            // A unique-key collision (including an absent-key insert race) is an
            // error, never permission to update a different resource's baseline.
            $this->query('INSERT INTO '.$this->table('mapping').' (entity,fk_resource,object_type,object_id,snapshot_json,snapshot_checksum,remote_checksum,projection_policy,date_creation) VALUES ('.
                $this->entity.','.(int) $r['rowid'].','.$this->q($type).','.$id.','.$this->q($snapshot).','.$this->q($checksum).','.$this->q($r['checksum']).','.$this->q($policy).',NOW())');
        }
        $actual = $this->mapping((int) $r['rowid']);
        if (!$actual || $actual['object_type'] !== $type || (int) $actual['object_id'] !== $id ||
            $actual['snapshot_json'] !== $snapshot || $actual['snapshot_checksum'] !== $checksum || $actual['remote_checksum'] !== $r['checksum'] ||
            $actual['projection_policy'] !== ($existing['projection_policy'] ?? $policy)) {
            throw new DomainException('MAPPING_OWNERSHIP_CONFLICT');
        }
    }
}
