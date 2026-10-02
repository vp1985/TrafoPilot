<?php
declare(strict_types=1);
require_once __DIR__.'/LexwareClient.php';
require_once __DIR__.'/LexwareResources.php';
final class LexwareStore
{
    public function __construct(public $db, public int $entity) {}
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
        while ($v = $this->db->fetch_array($r)) { $a[] = $v; }
        return $a;
    }
    public function one(string $table, int $id): ?array
    {
        return $this->rows('SELECT * FROM '.$this->table($table).' WHERE entity='.$this->entity.' AND rowid='.$id)[0] ?? null;
    }
    public function find(string $type, string $id): ?array
    {
        return $this->rows('SELECT * FROM '.$this->table('resource').' WHERE entity='.$this->entity.
            ' AND organization_id='.$this->q(LexwareClient::ORGANIZATION).' AND resource_type='.$this->q($type).' AND remote_id='.$this->q($id))[0] ?? null;
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
        $hash = LexwareResources::checksum($raw); $old = $this->find($type, $id);
        $this->db->begin();
        try {
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
            $this->audit('mirror', $run, $resource, ['previous'=>$old['checksum'] ?? null, 'current'=>$hash, 'result'=>$old ? 'update' : 'create'], $user);
            $this->db->commit(); return $resource;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function status(int $id, string $status, ?string $error = null): void
    {
        $this->query('UPDATE '.$this->table('resource').' SET projection_status='.$this->q($status).',last_error='.
            ($error === null ? 'NULL' : $this->q($error)).' WHERE entity='.$this->entity.' AND rowid='.$id);
    }
    public function issue(int $id, string $type, array $details): void
    {
        $this->query('INSERT INTO '.$this->table('issue').' (entity,fk_resource,issue_type,details_json,date_creation) VALUES ('.
            $this->entity.','.$id.','.$this->q($type).','.$this->q(json_encode($details, JSON_THROW_ON_ERROR)).',NOW()) ON DUPLICATE KEY UPDATE details_json=VALUES(details_json),status=\'open\'');
    }
    public function mapping(int $id): ?array
    {
        return $this->rows('SELECT * FROM '.$this->table('mapping').' WHERE entity='.$this->entity.' AND fk_resource='.$id)[0] ?? null;
    }
    public function mapped(string $type, string $id): ?array
    {
        $r = $this->find($type, $id); return $r ? $this->mapping((int) $r['rowid']) : null;
    }
    public function map(array $r, string $type, int $id, string $snapshot, string $policy = 'owned'): void
    {
        $this->query('INSERT INTO '.$this->table('mapping').' (entity,fk_resource,object_type,object_id,snapshot_json,snapshot_checksum,remote_checksum,projection_policy,date_creation) VALUES ('.
            $this->entity.','.(int) $r['rowid'].','.$this->q($type).','.$id.','.$this->q($snapshot).','.$this->q(LexwareResources::checksum($snapshot)).','.$this->q($r['checksum']).','.$this->q($policy).',NOW()) ON DUPLICATE KEY UPDATE snapshot_json=VALUES(snapshot_json),snapshot_checksum=VALUES(snapshot_checksum),remote_checksum=VALUES(remote_checksum)');
    }
}
