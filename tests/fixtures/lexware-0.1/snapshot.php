<?php
// Snapshot implementation captured verbatim from released HEAD 0.1.0.
final class LexwareReleasedSnapshot {
    public const OBJECTS = LexwareProjection::OBJECTS;
    public function __construct(private LexwareReleasedStore $s) {}
    public function snapshot(string $type, int $id): string
    {
        [,,$table,$detail,$fk] = self::OBJECTS[$type];
        $row = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$table.' WHERE entity='.$this->s->entity.' AND rowid='.$id)[0] ?? null;
        if (!$row) { throw new DomainException('NATIVE_OBJECT_MISSING'); }
        unset($row['tms'], $row['date_modification']);
        $data = ['row'=>$row];
        if ($detail) { $data['lines'] = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$detail.' WHERE '.$fk.'='.$id.' ORDER BY rowid'); }
        if ($type === 'thirdparty') { $data['contacts'] = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'socpeople WHERE entity='.$this->s->entity.' AND fk_soc='.$id.' ORDER BY rowid'); }
        // Include all extra fields, including local additions, in conflict detection.
        $data['extra'] = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$table.'_extrafields WHERE fk_object='.$id.' ORDER BY rowid');
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}

// Released Store result handling, including numeric/associative DB result keys.
final class LexwareReleasedStore {
    public function __construct(public $db, public int $entity) {}
    public function query(string $sql) {
        $r = $this->db->query($sql);
        if (!$r) { throw new RuntimeException('LEXWARE_DATABASE_ERROR'); }
        return $r;
    }
    public function rows(string $sql): array {
        $r = $this->query($sql); $a = [];
        while ($v = $this->db->fetch_array($r)) { $a[] = $v; }
        return $a;
    }
}
