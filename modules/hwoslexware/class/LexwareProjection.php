<?php
declare(strict_types=1);
require_once __DIR__.'/LexwareStore.php';
require_once __DIR__.'/LexwareAccess.php';
final class LexwareProjection
{
    public const OBJECTS = [
        'thirdparty'=>['societe/class/societe.class.php','Societe','societe',null,null],
        'product'=>['product/class/product.class.php','Product','product',null,null],
        'propal'=>['comm/propal/class/propal.class.php','Propal','propal','propaldet','fk_propal'],
        'order'=>['commande/class/commande.class.php','Commande','commande','commandedet','fk_commande'],
        'invoice'=>['compta/facture/class/facture.class.php','Facture','facture','facturedet','fk_facture'],
        'shipping'=>['expedition/class/expedition.class.php','LexwareImportExpedition','expedition','expeditiondet','fk_expedition']];
    public function __construct(private LexwareStore $s) {}
    public function object(string $type, int $id = 0)
    {
        if (!isset(self::OBJECTS[$type])) { throw new DomainException('OBJECT_TYPE_NOT_SUPPORTED'); }
        [$file,$class] = self::OBJECTS[$type]; require_once DOL_DOCUMENT_ROOT.'/'.$file;
        if ($type === 'shipping') { require_once __DIR__.'/LexwareImportExpedition.php'; }
        $o = new $class($this->s->db);
        if ($id && $o->fetch($id) <= 0) { throw new DomainException('NATIVE_OBJECT_MISSING'); }
        if ($id && (int) $o->entity !== $this->s->entity) { throw new DomainException('NATIVE_ENTITY_MISMATCH'); }
        if ($id) { $o->fetch_optionals(); }
        return $o;
    }
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
    public function plan(array $r): array
    {
        $p = json_decode($r['payload_json'], true, 512, JSON_THROW_ON_ERROR); $m = $this->s->mapping((int) $r['rowid']);
        if ($m) {
            $current = $this->snapshot($m['object_type'], (int) $m['object_id']);
            if (LexwareResources::checksum($current) !== $m['snapshot_checksum']) { return ['status'=>'conflict']; }
            if (($m['projection_policy'] ?? 'owned') === 'linked' && $m['remote_checksum'] !== $r['checksum']) { return ['status'=>'conflict','reason'=>'EXISTING_CONTACT_REQUIRES_FIELD_APPROVAL']; }
            return ['status'=>$m['remote_checksum'] === $r['checksum'] ? 'unchanged' : 'update', 'type'=>$m['object_type']];
        }
        $type = ['contacts'=>'thirdparty','articles'=>'product','quotations'=>'propal','order-confirmations'=>'order','invoices'=>'invoice','credit-notes'=>'invoice','delivery-notes'=>'shipping'][$r['resource_type']] ?? null;
        if (!$type) { return ['status'=>'mirror']; }
        if ($type === 'thirdparty') {
            $vat = preg_replace('/\s+/', '', strtoupper((string) ($p['company']['vatRegistrationId'] ?? $p['vatRegistrationId'] ?? '')));
            $matches = $vat === '' ? [] : $this->s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE entity='.$this->s->entity.' AND REPLACE(UPPER(tva_intra),\' \',\'\')='.$this->s->q($vat));
            $decision = LexwareResources::chooseContact([], array_map(fn($v)=>(int) $v['rowid'], $matches));
            // Existing exact email or name is a review candidate, never an automatic merge.
            if ($decision['status'] === 'new') {
                $name = $p['company']['name'] ?? trim(($p['person']['firstName'] ?? '').' '.($p['person']['lastName'] ?? ''));
                $email = $p['emailAddresses']['business'][0] ?? $p['emailAddresses']['private'][0] ?? '';
                $candidates = $this->s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE entity='.$this->s->entity.' AND (nom='.$this->s->q($name).
                    ($email !== '' ? ' OR LOWER(email)='.$this->s->q(strtolower($email)) : '').')');
                if ($candidates) { $decision = ['status'=>'review','candidates'=>array_map(fn($v)=>(int) $v['rowid'], $candidates)]; }
            }
            return $decision + ['type'=>$type];
        }
        if ($type === 'product') { return ['status'=>'new','type'=>$type]; }
        $contact = (string) ($p['address']['contactId'] ?? '');
        if (!$contact) { return ['status'=>'mirror','type'=>$type,'reason'=>'NO_NATIVE_CONTACT_REFERENCE']; }
        if (!$this->s->mapped('contacts', $contact)) { return ['status'=>'dependency','type'=>$type]; }
        if ($type === 'shipping') {
            $orders = array_filter($p['relatedVouchers'] ?? [], fn($v)=>($v['voucherType'] ?? '') === 'orderconfirmation');
            if (count($orders) !== 1 || !$this->s->mapped('order-confirmations', (string) (array_values($orders)[0]['id'] ?? ''))) { return ['status'=>'mirror','reason'=>'NO_UNIQUE_ORDER']; }
        }
        if ($type !== 'shipping' && !in_array($p['taxConditions']['taxType'] ?? 'net', ['net','gross','vatfree'], true)) { return ['status'=>'mirror','reason'=>'TAX_MAPPING_REQUIRED']; }
        return ['status'=>'new','type'=>$type];
    }
    public function project(array $r, $user): string
    {
        LexwareAccess::require($user, 'sync');
        $plan = $this->plan($r); $id = (int) $r['rowid'];
        if ($plan['status'] === 'unchanged') { $this->s->status($id, 'projected'); return 'unchanged'; }
        if (!in_array($plan['status'], ['new','update'], true)) {
            $this->s->status($id, $plan['status']);
            if (in_array($plan['status'], ['review','ambiguous','conflict','dependency'], true)) { $this->s->issue($id, $plan['status'], $plan); }
            return $plan['status'];
        }
        $p = json_decode($r['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $m = $this->s->mapping($id); $type = $plan['type'];
        $this->s->db->begin();
        try {
            if ($m) {
                [,,$table,$detail,$fk] = self::OBJECTS[$type];
                $this->s->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$table.' WHERE entity='.$this->s->entity.' AND rowid='.(int) $m['object_id'].' FOR UPDATE');
                if ($detail) { $this->s->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$detail.' WHERE '.$fk.'='.(int) $m['object_id'].' FOR UPDATE'); }
                $this->s->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$table.'_extrafields WHERE fk_object='.(int) $m['object_id'].' FOR UPDATE');
                if ($type === 'thirdparty') { $this->s->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'socpeople WHERE fk_soc='.(int) $m['object_id'].' FOR UPDATE'); }
                if (LexwareResources::checksum($this->snapshot($type, (int) $m['object_id'])) !== $m['snapshot_checksum']) { throw new DomainException('LOCAL_MODIFICATION_CONFLICT'); }
            }
            $o = $this->object($type, (int) ($m['object_id'] ?? 0));
            $o->array_options['options_hwoslexware_uuid'] = $r['remote_id'];
            if ($type === 'thirdparty') {
                $o->name = $p['company']['name'] ?? trim(($p['person']['firstName'] ?? '').' '.($p['person']['lastName'] ?? ''));
                $o->client = isset($p['roles']['customer']) ? 1 : 0; $o->fournisseur = isset($p['roles']['vendor']) ? 1 : 0;
                $o->code_client = $o->client && !$m ? '-1' : $o->code_client;
                $o->code_fournisseur = $o->fournisseur && !$m ? '-1' : $o->code_fournisseur;
                $a = $p['addresses']['billing'][0] ?? [];
                $o->address = $a['street'] ?? ''; $o->zip = $a['zip'] ?? ''; $o->town = $a['city'] ?? '';
                $o->country_id = $this->country($a['countryCode'] ?? 'DE');
                $o->email = $p['emailAddresses']['business'][0] ?? $p['emailAddresses']['private'][0] ?? '';
                $o->phone = $p['phoneNumbers']['business'][0] ?? $p['phoneNumbers']['private'][0] ?? '';
                $o->tva_intra = $p['company']['vatRegistrationId'] ?? $p['vatRegistrationId'] ?? '';
                $o->ref_ext = $r['remote_id'];
                $result = $m ? $o->update($o->id, $user, 0) : $o->create($user, 1);
                if ($result <= 0) { throw new RuntimeException('NATIVE_CONTACT_WRITE_FAILED'); }
                $this->contacts($o, $p, $user, $m !== null);
            } elseif ($type === 'product') {
                $o->ref = ($p['articleNumber'] ?? '') ?: 'LX-'.substr($r['remote_id'], 0, 12);
                $o->label = $p['title'] ?? ''; $o->description = $p['description'] ?? '';
                $o->type = ($p['type'] ?? '') === 'SERVICE' ? 1 : 0; $o->status = empty($p['archived']) ? 1 : 0;
                $o->price = $p['price']['netPrice'] ?? 0; $o->price_base_type = 'HT'; $o->tva_tx = $p['price']['taxRate'] ?? 0;
                $o->ref_ext = $r['remote_id'];
                $result = $m ? $o->update($o->id, $user, 1) : $o->create($user, 1);
                if ($result <= 0) { throw new RuntimeException('NATIVE_PRODUCT_WRITE_FAILED'); }
            } else {
                $o->socid = (int) $this->s->mapped('contacts', $p['address']['contactId'])['object_id'];
                $o->date = strtotime($p['voucherDate'] ?? $p['createdDate'] ?? 'now');
                $o->date_lim_reglement = isset($p['dueDate']) ? strtotime($p['dueDate']) : 0;
                $o->ref_ext = $r['remote_id']; $o->ref_client = $p['voucherNumber'] ?? '';
                $o->note_public = ($p['introduction'] ?? '')."\n".($p['remark'] ?? '');
                $o->note_private = 'TrafoPilot: historischer Lexware-Spiegel '.$r['remote_id'];
                if ($type === 'invoice') { $o->type = $r['resource_type'] === 'credit-notes' ? 2 : 0; }
                if ($type === 'shipping') {
                    if ($m) { throw new DomainException('SHIPMENT_REVISION_REQUIRES_REVIEW'); }
                    $this->shipping($o, $p, $user);
                }
                else {
                    $result = $m ? $o->update($user, 1) : $o->create($user, 1);
                    if ($result <= 0) { throw new RuntimeException('NATIVE_DOCUMENT_WRITE_FAILED'); }
                    $this->lines($o, $type, $p, $user, $m !== null);
                    if ($o->update_price() < 0) { throw new RuntimeException('NATIVE_TOTALS_FAILED'); }
                    $o->fetch($o->id);
                    $expected = (float) ($p['totalPrice']['totalGrossAmount'] ?? $o->total_ttc);
                    if (abs(abs((float) $o->total_ttc) - abs($expected)) > 0.02) { throw new DomainException('TOTALS_MAPPING_CONFLICT'); }
                }
                $this->historical($o, $type, $p);
            }
            $this->s->map($r, $type, (int) $o->id, $this->snapshot($type, (int) $o->id));
            $this->s->status($id, 'projected');
            $this->s->query('UPDATE '.$this->s->table('issue')." SET status='resolved' WHERE entity=".$this->s->entity.' AND fk_resource='.$id);
            $this->s->audit('projection', (int) $r['fk_run'], $r, ['object_type'=>$type,'object_id'=>$o->id,'previous'=>$m['remote_checksum'] ?? null,'current'=>$r['checksum'],'result'=>$plan['status']], (int) $user->id);
            $this->s->db->commit(); return $plan['status'];
        } catch (Throwable $e) {
            $this->s->db->rollback();
            $code = preg_match('/^[A-Z_]+$/', $e->getMessage()) ? $e->getMessage() : 'NATIVE_PROJECTION_FAILED';
            $this->s->status($id, 'conflict', $code); $this->s->issue($id, 'conflict', ['code'=>$code]);
            return 'conflict';
        }
    }
    private function country(string $code): int
    {
        return (int) ($this->s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'c_country WHERE code='.$this->s->q($code))[0]['rowid'] ?? 0);
    }
    private function contacts($o, array $p, $user, bool $update = false): void
    {
        require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
        $existing = $update ? $this->s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'socpeople WHERE entity='.$this->s->entity.' AND fk_soc='.(int) $o->id.' ORDER BY rowid') : [];
        $people = $p['company']['contactPersons'] ?? [];
        if ($update && count($existing) !== count($people)) { throw new DomainException('CONTACT_PERSON_STRUCTURE_CONFLICT'); }
        foreach ($people as $i=>$person) {
            $c = new Contact($this->s->db); $c->socid = $o->id;
            if ($update && $c->fetch((int) $existing[$i]['rowid']) <= 0) { throw new DomainException('CONTACT_PERSON_MISSING'); }
            $c->lastname = $person['lastName'] ?? ''; $c->firstname = $person['firstName'] ?? '';
            $c->email = $person['emailAddress'] ?? ''; $c->phone_pro = $person['phoneNumber'] ?? '';
            $result = $update ? $c->update($c->id, $user, 1, 'update', 1) : $c->create($user, 1);
            if ($result <= 0) { throw new RuntimeException('NATIVE_CONTACT_PERSON_FAILED'); }
        }
    }
    private function lines($o, string $type, array $p, $user, bool $update = false): void
    {
        $classes = ['propal'=>['comm/propal/class/propaleligne.class.php','PropaleLigne','fk_propal'],
            'order'=>['commande/class/orderline.class.php','OrderLine','fk_commande'],
            'invoice'=>['compta/facture/class/factureligne.class.php','FactureLigne','fk_facture']];
        [$file,$class,$fk] = $classes[$type]; require_once DOL_DOCUMENT_ROOT.'/'.$file;
        if ($update && count($o->lines) !== count($p['lineItems'] ?? [])) { throw new DomainException('DOCUMENT_LINE_STRUCTURE_CONFLICT'); }
        $rang = 0;
        foreach ($p['lineItems'] ?? [] as $item) {
            $l = new $class($this->s->db);
            if ($update && $l->fetch((int) $o->lines[$rang]->id) <= 0) { throw new DomainException('NATIVE_LINE_MISSING'); }
            $l->$fk = $o->id;
            $l->desc = ($item['name'] ?? '')."\n".($item['description'] ?? '');
            $l->qty = (float) ($item['quantity'] ?? 0); $l->rang = ++$rang;
            $price = $item['unitPrice'] ?? []; $tax = (float) ($price['taxRatePercentage'] ?? 0);
            $l->subprice = (float) ($price['netAmount'] ?? ((float) ($price['grossAmount'] ?? 0) / (1 + $tax/100)));
            if ($type === 'invoice' && (int) $o->type === 2) { $l->subprice = -abs($l->subprice); }
            $l->tva_tx = $tax; $l->remise_percent = (float) ($item['discountPercentage'] ?? 0);
            $l->total_ht = round($l->subprice * $l->qty * (1 - $l->remise_percent / 100), 2);
            $l->total_tva = round($l->total_ht * $tax / 100, 2); $l->total_ttc = $l->total_ht + $l->total_tva;
            $l->product_type = ($item['type'] ?? '') === 'text' ? 9 : (($item['type'] ?? '') === 'service' ? 1 : 0);
            $product = isset($item['id']) ? $this->s->mapped('articles', $item['id']) : null;
            $l->fk_product = (int) ($product['object_id'] ?? 0); $l->special_code = 0;
            $result = $update ? ($type === 'propal' ? $l->update(1) : $l->update($user, 1)) : ($type === 'order' ? $l->insert($user, 1) : $l->insert(1));
            if ($result <= 0) { throw new RuntimeException('NATIVE_LINE_CREATE_FAILED'); }
        }
    }
    private function shipping($o, array $p, $user): void
    {
        $orders = array_values(array_filter($p['relatedVouchers'] ?? [], fn($v)=>($v['voucherType'] ?? '') === 'orderconfirmation'));
        $mapped = $this->s->mapped('order-confirmations', $orders[0]['id']);
        $order = $this->object('order', (int) $mapped['object_id']);
        $o->origin = 'commande'; $o->origin_id = $order->id; $o->date_shipping = $o->date;
        if ($o->create($user, 0) <= 0) { throw new RuntimeException('NATIVE_SHIPMENT_CREATE_FAILED'); }
        require_once DOL_DOCUMENT_ROOT.'/expedition/class/expeditionligne.class.php';
        $used = [];
        foreach ($p['lineItems'] ?? [] as $item) {
            $product = isset($item['id']) ? $this->s->mapped('articles', $item['id']) : null;
            $matches = array_values(array_filter($order->lines, fn($v)=>!isset($used[$v->id]) && $product && (int) $v->fk_product === (int) $product['object_id']));
            if (count($matches) !== 1) { throw new DomainException('SHIPMENT_LINE_MAPPING_AMBIGUOUS'); }
            $l = new ExpeditionLigne($this->s->db); $l->fk_expedition = $o->id;
            $l->fk_elementdet = $matches[0]->id; $l->element_type = 'commande'; $l->entrepot_id = 0;
            $l->fk_product = $product['object_id']; $l->qty = $item['quantity']; $l->rang = count($used)+1;
            if ($l->insert($user, 1) <= 0) { throw new RuntimeException('NATIVE_SHIPMENT_LINE_FAILED'); }
            $used[$matches[0]->id] = true;
        }
    }
    private function historical($o, string $type, array $p): void
    {
        [,,$table] = self::OBJECTS[$type]; $number = (string) ($p['voucherNumber'] ?? '');
        if ($number === '') { throw new DomainException('ORIGINAL_NUMBER_MISSING'); }
        $collision = $this->s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.$table.' WHERE entity='.$this->s->entity.' AND ref='.$this->s->q($number).' AND rowid<>'.(int) $o->id);
        if ($collision) { throw new DomainException('ORIGINAL_NUMBER_COLLISION'); }
        $remote = $p['voucherStatus'] ?? 'draft';
        $status = 0;
        if ($remote !== 'draft') {
            $status = $type === 'propal' ? (['accepted'=>2,'rejected'=>3][$remote] ?? 1) : 1;
            if ($type === 'invoice' && $remote === 'voided') { $status = 3; }
        }
        // Deliberately bypass validation: it can move stock. Only historical metadata is restored.
        $this->s->query('UPDATE '.MAIN_DB_PREFIX.$table.' SET ref='.$this->s->q($number).',fk_statut='.$status.' WHERE entity='.$this->s->entity.' AND rowid='.(int) $o->id);
    }
    public function approve(int $resourceId, string $type, int $objectId, $user, bool $replace = false): void
    {
        LexwareAccess::require($user, 'mapping');
        $r = $this->s->one('resource', $resourceId);
        if (!$r || $r['resource_type'] !== 'contacts' || $type !== 'thirdparty') { throw new DomainException('ONLY_CONTACT_MAPPING_SUPPORTED'); }
        $this->object($type, $objectId);
        $this->s->db->begin();
        try {
            $previous = $this->s->mapping($resourceId);
            if ($previous && !$replace) { throw new DomainException('RESOURCE_ALREADY_MAPPED'); }
            $occupied = $this->s->rows('SELECT fk_resource FROM '.$this->s->table('mapping').' WHERE entity='.$this->s->entity.' AND object_type='.$this->s->q($type).' AND object_id='.$objectId.' AND fk_resource<>'.$resourceId);
            if ($occupied) { throw new DomainException('NATIVE_OBJECT_ALREADY_LINKED'); }
            if ($previous) { $this->s->query('DELETE FROM '.$this->s->table('mapping').' WHERE entity='.$this->s->entity.' AND fk_resource='.$resourceId); }
            $this->s->map($r, $type, $objectId, $this->snapshot($type, $objectId), 'linked');
            $this->s->status($resourceId, 'projected');
            $this->s->query('UPDATE '.$this->s->table('issue')." SET status='resolved' WHERE entity=".$this->s->entity.' AND fk_resource='.$resourceId);
            $this->s->audit('mapping', (int) $r['fk_run'], $r, ['object_type'=>$type,'object_id'=>$objectId,'previous_object_id'=>$previous['object_id'] ?? null,'previous'=>$previous['remote_checksum'] ?? null,'current'=>$r['checksum'],'result'=>'manual_link_preserve_existing'], (int) $user->id);
            $this->s->db->commit();
        } catch (Throwable $e) { $this->s->db->rollback(); throw $e; }
    }
}
