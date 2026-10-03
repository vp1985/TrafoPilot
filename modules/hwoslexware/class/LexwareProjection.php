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
        $row = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$table.' WHERE entity='.$this->s->entity.' AND rowid='.$id.' FOR UPDATE')[0] ?? null;
        if (!$row) { throw new DomainException('NATIVE_OBJECT_MISSING'); }
        unset($row['tms'], $row['date_modification']);
        $data = ['row'=>$row];
        if ($type === 'shipping') { $data['origin_links'] = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX."element_element WHERE targettype='shipping' AND fk_target=".$id.' ORDER BY rowid FOR UPDATE'); }
        if ($detail) {
            $data['lines'] = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$detail.' WHERE '.$fk.'='.$id.' ORDER BY rang,rowid FOR UPDATE');
            $extra = $this->s->rows('SELECT e.* FROM '.MAIN_DB_PREFIX.$detail.'_extrafields e JOIN '.MAIN_DB_PREFIX.$detail.' l ON l.rowid=e.fk_object WHERE l.'.$fk.'='.$id.' ORDER BY e.rowid FOR UPDATE');
            if ($extra) { $data['line_extra'] = $extra; }
        }
        if ($type === 'thirdparty') {
            $data['contacts'] = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'socpeople WHERE entity='.$this->s->entity.' AND fk_soc='.$id.' ORDER BY rowid FOR UPDATE');
            $data['contact_extra'] = $this->s->rows('SELECT e.* FROM '.MAIN_DB_PREFIX.'socpeople_extrafields e JOIN '.MAIN_DB_PREFIX.'socpeople p ON p.rowid=e.fk_object WHERE p.entity='.$this->s->entity.' AND p.fk_soc='.$id.' ORDER BY e.rowid FOR UPDATE');
        }
        // Include all extra fields, including local additions, in conflict detection.
        $data['extra'] = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$table.'_extrafields WHERE fk_object='.$id.' ORDER BY rowid FOR UPDATE');
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
    private function matchesBaseline(array $m, string $current): bool
    {
        if (LexwareResources::checksum($current) === $m['snapshot_checksum']) { return true; }
        $old = json_decode($m['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        $now = json_decode($current, true, 512, JSON_THROW_ON_ERROR);
        $normalize = function ($value) use (&$normalize) {
            if (!is_array($value)) { return $value; }
            if (!array_is_list($value)) { $value = array_filter($value, fn($key)=>is_string($key), ARRAY_FILTER_USE_KEY); }
            return array_map($normalize, $value);
        };
        $old = $normalize($old); $now = $normalize($now);
        unset($old['row']['tms'], $old['row']['date_modification']);
        if ($m['object_type'] === 'thirdparty' && !array_key_exists('contact_extra', $old)) {
            if (!empty($now['contact_extra'])) { return false; }
            unset($now['contact_extra']);
        }
        if ($m['object_type'] !== 'shipping' || array_key_exists('origin_links', $old)) { return LexwareResources::checksum(json_encode($old)) === LexwareResources::checksum(json_encode($now)); }
        // Released 0.1 snapshots encode the order through shipment line origins.
        // Only accept the new link field when it agrees with that released state.
        $origins = [];
        foreach ($old['lines'] ?? [] as $line) {
            $origin = $this->s->rows('SELECT fk_commande FROM '.MAIN_DB_PREFIX.'commandedet WHERE rowid='.(int) $line['fk_elementdet'].' FOR UPDATE')[0] ?? null;
            if (!$origin) { return false; }
            $origins[(int) $origin['fk_commande']] = true;
        }
        if (!$origins && empty($old['lines'])) {
            // A released empty shipment has no line origins. Its immutable source
            // version still identifies the unique order used by 0.1 projection.
            $source = $this->s->rows('SELECT payload_json FROM '.$this->s->table('payload_version').' WHERE entity='.$this->s->entity.' AND fk_resource='.(int) $m['fk_resource'].' AND checksum='.$this->s->q($m['remote_checksum']).' LIMIT 1 FOR UPDATE')[0] ?? null;
            $payload = $source ? json_decode($source['payload_json'], true, 512, JSON_THROW_ON_ERROR) : [];
            $orders = array_values(array_filter($payload['relatedVouchers'] ?? [], fn($v)=>($v['voucherType'] ?? '') === 'orderconfirmation'));
            $order = count($orders) === 1 ? $this->s->mapped('order-confirmations', (string) $orders[0]['id']) : null;
            if ($order) { $origins[(int) $order['object_id']] = true; }
        }
        if (count($origins) !== 1 || count($now['origin_links'] ?? []) !== 1) { return false; }
        $link = $now['origin_links'][0];
        if ($link['sourcetype'] !== 'commande' || !isset($origins[(int) $link['fk_source']]) || $link['targettype'] !== 'shipping' || (int) $link['fk_target'] !== (int) $m['object_id']) { return false; }
        unset($now['origin_links']);
        return LexwareResources::checksum(json_encode($now, JSON_THROW_ON_ERROR)) === LexwareResources::checksum(json_encode($old, JSON_THROW_ON_ERROR));
    }
    private function sourceFields(string $type, array $p): array
    {
        if ($type === 'thirdparty') {
            $a = $p['addresses']['billing'][0] ?? [];
            return [
                'name'=>$p['company']['name'] ?? trim(($p['person']['firstName'] ?? '').' '.($p['person']['lastName'] ?? '')),
                'customer'=>isset($p['roles']['customer']), 'supplier'=>isset($p['roles']['vendor']),
                'street'=>$a['street'] ?? '', 'zip'=>$a['zip'] ?? '', 'city'=>$a['city'] ?? '', 'country'=>strtoupper($a['countryCode'] ?? 'DE'),
                'email'=>$p['emailAddresses']['business'][0] ?? $p['emailAddresses']['private'][0] ?? '',
                'phone'=>$p['phoneNumbers']['business'][0] ?? $p['phoneNumbers']['private'][0] ?? '',
                'vat'=>$p['company']['vatRegistrationId'] ?? $p['vatRegistrationId'] ?? '',
                'people'=>array_map(fn($person)=>['last'=>$person['lastName'] ?? '', 'first'=>$person['firstName'] ?? '', 'email'=>$person['emailAddress'] ?? '', 'phone'=>$person['phoneNumber'] ?? ''], $p['company']['contactPersons'] ?? [])];
        }
        if ($type === 'product') {
            return ['ref'=>$p['articleNumber'] ?? '', 'label'=>$p['title'] ?? '', 'description'=>$p['description'] ?? '',
                'service'=>($p['type'] ?? '') === 'SERVICE', 'active'=>empty($p['archived']),
                'price'=>(float) ($p['price']['netPrice'] ?? 0), 'tax'=>(float) ($p['price']['taxRate'] ?? 0)];
        }
        return array_intersect_key($p, array_flip(['address','lineItems','shippingConditions','shippingDate','deliveryDate','relatedVouchers','voucherStatus','voucherDate','createdDate','dueDate','voucherNumber','introduction','remark','taxConditions','totalPrice']));
    }
    public function plan(array $r): array
    {
        $p = json_decode($r['payload_json'], true, 512, JSON_THROW_ON_ERROR); $m = $this->s->mapping((int) $r['rowid']);
        if ($m) {
            $validationError = null;
            try { $this->validateSource($m['object_type'], $p); }
            catch (DomainException $e) { $validationError = $e->getMessage(); }
            $accepted = $this->s->rows('SELECT source_checksum,choice FROM '.$this->s->table('resolution').' WHERE entity='.$this->s->entity.' AND fk_resource='.(int) $r['rowid'].' ORDER BY rowid DESC LIMIT 1')[0] ?? null;
            if ($accepted && $accepted['choice'] === 'Dolibarr') {
                return ['status'=>$accepted['source_checksum'] === $r['checksum'] ? 'unchanged' : 'conflict','reason'=>'ACCEPTED_NATIVE_SOURCE_CHANGED','source_checksum'=>$r['checksum']];
            }
            if ($validationError !== null) { return ['status'=>'conflict','reason'=>$validationError]; }
            $current = $this->snapshot($m['object_type'], (int) $m['object_id']);
            if (!$this->dependencyMatches($m['object_type'], $p, json_decode($current, true))) { return ['status'=>'conflict','reason'=>'NATIVE_DEPENDENCY_CHANGED']; }
            if (!$this->matchesBaseline($m, $current)) { return ['status'=>'conflict']; }
            if (($m['projection_policy'] ?? 'owned') === 'linked' && $m['remote_checksum'] !== $r['checksum']) { return ['status'=>'conflict','reason'=>'EXISTING_CONTACT_REQUIRES_FIELD_APPROVAL']; }
            if ($m['remote_checksum'] !== $r['checksum']) {
                $previous = $this->s->rows('SELECT payload_json FROM '.$this->s->table('payload_version').' WHERE entity='.$this->s->entity.' AND fk_resource='.(int) $r['rowid'].' AND checksum='.$this->s->q($m['remote_checksum']))[0] ?? null;
                $old = $previous ? json_decode($previous['payload_json'], true, 512, JSON_THROW_ON_ERROR) : null;
                if (!$old || LexwareResources::checksum(json_encode($this->sourceFields($m['object_type'], $old))) !== LexwareResources::checksum(json_encode($this->sourceFields($m['object_type'], $p)))) {
                    return ['status'=>'conflict','reason'=>'REMOTE_DOCUMENT_CHANGED','source_checksum'=>$r['checksum'],'revision'=>$r['revision'] ?? ''];
                }
            }
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
        LexwareAccess::require($user, 'retry');
        $this->s->begin(true);
        try {
            $current = $this->s->rows('SELECT * FROM '.$this->s->table('resource').' WHERE entity='.$this->s->entity.' AND rowid='.(int) $r['rowid'].' FOR UPDATE')[0] ?? null;
            if (!$current) { throw new DomainException('RESOURCE_NOT_FOUND'); }
            $result = $this->apply($current, $user, false);
            $this->s->commit(); return $result;
        } catch (Throwable $e) {
            $this->rollbackOwned();
            $code = preg_match('/^[A-Z_]+$/', $e->getMessage()) ? $e->getMessage() : 'NATIVE_PROJECTION_FAILED';
            $this->s->begin();
            try {
                $this->s->status((int) $r['rowid'], 'conflict', $code);
                $this->s->issue((int) $r['rowid'], 'conflict', ['code'=>$code], (int) $user->id);
                $this->s->commit();
            } catch (Throwable $failure) { $this->s->db->rollback(); throw $failure; }
            return 'conflict';
        }
    }
    private function rollbackOwned(): void
    {
        // Native domain methods can leave nested depth after an exception.
        // This entry point owns the outer transaction: force an actual rollback.
        $this->s->query('ROLLBACK');
        $this->s->db->transaction_opened = 0;
    }
    private function apply(array $r, $user, bool $resolution): string
    {
        LexwareAccess::require($user, $resolution ? 'mapping' : 'retry');
        $mapping = $this->s->mapping((int) $r['rowid']);
        if ($mapping) { $this->lockNative($mapping); }
        $this->lockSourceDependencies(json_decode($r['payload_json'], true, 512, JSON_THROW_ON_ERROR));
        $plan = $resolution ? ['status'=>'update','type'=>$mapping['object_type']] : $this->plan($r); $id = (int) $r['rowid'];
        if ($plan['status'] === 'unchanged') {
            $this->s->status($id, 'projected');
            if ($this->s->rows('SELECT rowid FROM '.$this->s->table('issue').' WHERE entity='.$this->s->entity.' AND fk_resource='.$id." AND issue_type='conflict' AND status='open'")) {
                $this->s->query('UPDATE '.$this->s->table('issue')." SET status='resolved' WHERE entity=".$this->s->entity.' AND fk_resource='.$id." AND issue_type='conflict' AND status='open'");
                $this->s->audit('conflict_suppressed', (int) $r['fk_run'], $r, ['source_checksum'=>$r['checksum']], (int) $user->id);
            }
            return 'unchanged';
        }
        if (!in_array($plan['status'], ['new','update'], true)) {
                try {
                $this->s->status($id, $plan['status']);
                if (in_array($plan['status'], ['review','ambiguous','conflict','dependency'], true)) { $this->s->issue($id, $plan['status'], $plan, (int) $user->id); }
            } catch (Throwable $e) { $this->s->db->rollback(); throw $e; }
            return $plan['status'];
        }
        $p = json_decode($r['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $m = $this->s->mapping($id); $type = $plan['type'];
        $this->validateSource($type, $p);
        try {
            if ($m) {
                $this->lockNative($m);
                if (!$resolution && !$this->matchesBaseline($m, $this->snapshot($type, (int) $m['object_id']))) { throw new DomainException('LOCAL_MODIFICATION_CONFLICT'); }
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
                $this->contacts($o, $p, $user, $m !== null, $resolution);
            } elseif ($type === 'product') {
                $o->ref = ($p['articleNumber'] ?? '') ?: 'LX-'.substr($r['remote_id'], 0, 12);
                $o->label = $p['title'] ?? ''; $o->description = $p['description'] ?? '';
                $o->type = ($p['type'] ?? '') === 'SERVICE' ? 1 : 0; $o->status = empty($p['archived']) ? 1 : 0;
                $o->price = $p['price']['netPrice'] ?? 0; $o->price_base_type = 'HT'; $o->tva_tx = $p['price']['taxRate'] ?? 0;
                $o->ref_ext = $r['remote_id'];
                $result = $m ? $o->update($o->id, $user, 1, 'update', true) : $o->create($user, 1);
                if ($result <= 0) { throw new RuntimeException('NATIVE_PRODUCT_WRITE_FAILED'); }
                $priorPriceId = $m ? (int) ($this->s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'product_price WHERE fk_product='.(int) $o->id.' ORDER BY rowid DESC LIMIT 1 FOR UPDATE')[0]['rowid'] ?? 0) : 0;
                if ($m && $o->updatePrice((float) ($p['price']['netPrice'] ?? 0), 'HT', $user, (float) ($p['price']['taxRate'] ?? 0), 0, 0, 0, 0, 1, [], '', '', 1) <= 0) { throw new RuntimeException('NATIVE_PRODUCT_PRICE_FAILED'); }
                // 24.0.0 updatePrice does not propagate _log_price failure.
                // Verify the native price history as well as the default price row.
                if ($m) {
                    $logged = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'product_price WHERE fk_product='.(int) $o->id.' ORDER BY rowid DESC LIMIT 1 FOR UPDATE')[0] ?? null;
                    if (!$logged || (!(getDolGlobalString('PRODUIT_MULTIPRICES') || getDolGlobalString('PRODUIT_CUSTOMER_PRICES_AND_MULTIPRICES')) && (int) $logged['rowid'] <= $priorPriceId) || abs((float) $logged['price_ttc'] - (float) price2num((float) ($p['price']['netPrice'] ?? 0) * (1 + (float) ($p['price']['taxRate'] ?? 0) / 100), 'MU')) > 0.000001 || (int) $logged['recuperableonly'] !== 0 || abs((float) $logged['price'] - (float) ($p['price']['netPrice'] ?? 0)) > 0.000001 || (float) $logged['tva_tx'] !== (float) ($p['price']['taxRate'] ?? 0) || $logged['price_base_type'] !== 'HT') { throw new RuntimeException('NATIVE_PRODUCT_PRICE_READBACK_FAILED'); }
                }
                $persisted = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'product WHERE entity='.$this->s->entity.' AND rowid='.(int) $o->id.' FOR UPDATE')[0] ?? null;
                $net = (float) ($p['price']['netPrice'] ?? 0); $tax = (float) ($p['price']['taxRate'] ?? 0);
                if (!$persisted || abs((float) $persisted['price'] - $net) > 0.000001 || abs((float) $persisted['price_ttc'] - (float) price2num($net * (1 + $tax / 100), 'MU')) > 0.000001 || (float) $persisted['tva_tx'] !== $tax || $persisted['price_base_type'] !== 'HT' || (int) $persisted['recuperableonly'] !== 0 || (int) $persisted['fk_product_type'] !== (int) (($p['type'] ?? '') === 'SERVICE')) { throw new RuntimeException('NATIVE_PRODUCT_READBACK_FAILED'); }
            } else {
                $o->socid = (int) $this->s->mapped('contacts', $p['address']['contactId'])['object_id'];
                $o->date = strtotime($p['voucherDate'] ?? $p['createdDate'] ?? 'now');
                $o->date_lim_reglement = isset($p['dueDate']) ? strtotime($p['dueDate']) : 0;
                $o->ref_ext = $r['remote_id']; $o->ref_client = $p['voucherNumber'] ?? '';
                $o->note_public = ($p['introduction'] ?? '')."\n".($p['remark'] ?? '');
                $o->note_private = 'TrafoPilot: historischer Lexware-Spiegel '.$r['remote_id'];
                if ($type === 'invoice') { $o->type = $r['resource_type'] === 'credit-notes' ? 2 : 0; }
                if ($type === 'shipping') {
                    if ($m && !$resolution) { throw new DomainException('SHIPMENT_REVISION_REQUIRES_REVIEW'); }
                    $this->shipping($o, $p, $user, $m !== null);
                }
                else {
                    $result = $m ? $o->update($user, 1) : $o->create($user, 1);
                    if ($result <= 0) { throw new RuntimeException('NATIVE_DOCUMENT_WRITE_FAILED'); }
                    $this->lines($o, $type, $p, $user, $m !== null, $resolution);
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
            return $plan['status'];
        } catch (Throwable $e) { throw $e; }
    }
    private function lockSourceDependencies(array $p): void
    {
        $dependencies = [];
        if (!empty($p['address']['contactId'])) { $dependencies[] = ['contacts', (string) $p['address']['contactId']]; }
        foreach ($p['lineItems'] ?? [] as $line) { if (isset($line['id'])) { $dependencies[] = ['articles', (string) $line['id']]; } }
        foreach ($p['relatedVouchers'] ?? [] as $rel) { if (($rel['voucherType'] ?? '') === 'orderconfirmation') { $dependencies[] = ['order-confirmations', (string) $rel['id']]; } }
        sort($dependencies);
        foreach ($dependencies as [$type,$id]) {
            $m = $this->s->mapped($type, $id);
            if ($m) {
                $expected = ['contacts'=>'thirdparty','articles'=>'product','order-confirmations'=>'order'][$type];
                if ($m['object_type'] !== $expected) { throw new DomainException('NATIVE_DEPENDENCY_TYPE_MISMATCH'); }
                $this->lockNative($m);
            }
        }
    }
    private function dependencyMatches(string $type, array $p, array $native): bool
    {
        if (!in_array($type, ['propal','order','invoice','shipping'], true)) { return true; }
        $contact = $this->s->mapped('contacts', (string) ($p['address']['contactId'] ?? ''));
        if (!$contact || (int) ($native['row']['fk_soc'] ?? 0) !== (int) $contact['object_id']) { return false; }
        foreach ($p['lineItems'] ?? [] as $index=>$line) {
            $product = isset($line['id']) ? $this->s->mapped('articles', (string) $line['id']) : null;
            if (!isset($native['lines'][$index]) || (int) ($native['lines'][$index]['fk_product'] ?? 0) !== (int) ($product['object_id'] ?? 0)) { return false; }
        }
        if ($type === 'shipping') {
            $orders = array_values(array_filter($p['relatedVouchers'] ?? [], fn($v)=>($v['voucherType'] ?? '') === 'orderconfirmation'));
            $order = count($orders) === 1 ? $this->s->mapped('order-confirmations', (string) $orders[0]['id']) : null;
            if (!$order) { return false; }
            foreach ($native['origin_links'] ?? [] as $link) { if ($link['sourcetype'] === 'commande' && (int) $link['fk_source'] !== (int) $order['object_id']) { return false; } }
            foreach ($native['lines'] ?? [] as $line) {
                $origin = $this->s->rows('SELECT fk_commande,fk_product FROM '.MAIN_DB_PREFIX.'commandedet WHERE rowid='.(int) $line['fk_elementdet'].' FOR UPDATE')[0] ?? null;
                if (!$origin || (int) $origin['fk_commande'] !== (int) $order['object_id'] || (int) $origin['fk_product'] !== (int) $line['fk_product']) { return false; }
            }
        }
        return true;
    }
    private function validateSource(string $type, array $p): void
    {
        if ($type === 'product') {
            $price = $p['price']['netPrice'] ?? 0; $tax = $p['price']['taxRate'] ?? 0;
            if (!is_numeric($price) || !is_finite((float) $price)) { throw new DomainException('NATIVE_PRICE_INVALID'); }
            if (!is_numeric($tax) || !is_finite((float) $tax) || (float) $tax < 0 || (float) $tax > 100) { throw new DomainException('TAX_MAPPING_REQUIRED'); }
            return;
        }

        if (!in_array($type, ['propal','order','invoice','shipping'], true)) { return; }
        $this->lockSourceDependencies($p);
        if ($type !== 'shipping' && !in_array($p['taxConditions']['taxType'] ?? 'net', ['net','gross','vatfree'], true)) { throw new DomainException('TAX_MAPPING_REQUIRED'); }
        if (empty($p['address']['contactId']) || !$this->s->mapped('contacts', (string) $p['address']['contactId'])) { throw new DomainException('NATIVE_CONTACT_DEPENDENCY_REQUIRED'); }
        foreach ($p['lineItems'] ?? [] as $item) {
            $values = [$item['quantity'] ?? 0, $item['discountPercentage'] ?? 0, $item['unitPrice']['netAmount'] ?? $item['unitPrice']['grossAmount'] ?? 0];
            foreach ($values as $value) { if (!is_numeric($value) || !is_finite((float) $value)) { throw new DomainException('NATIVE_LINE_INPUT_INVALID'); } }
            if ($type === 'shipping' && !isset($item['quantity'])) { throw new DomainException('NATIVE_LINE_INPUT_INVALID'); }
            if (isset($item['id']) && !$this->s->mapped('articles', (string) $item['id'])) { throw new DomainException('NATIVE_PRODUCT_DEPENDENCY_REQUIRED'); }
            $tax = $item['unitPrice']['taxRatePercentage'] ?? 0;
            if (!is_numeric($tax) || !is_finite((float) $tax) || (float) $tax < 0 || (float) $tax > 100 || (($p['taxConditions']['taxType'] ?? '') === 'vatfree' && (float) $tax !== 0.0)) { throw new DomainException('TAX_MAPPING_REQUIRED'); }
        }
        if ($type === 'shipping') {
            $orders = array_values(array_filter($p['relatedVouchers'] ?? [], fn($v)=>($v['voucherType'] ?? '') === 'orderconfirmation'));
            if (count($orders) !== 1 || !$this->s->mapped('order-confirmations', (string) ($orders[0]['id'] ?? ''))) { throw new DomainException('NO_UNIQUE_ORDER'); }
        }
    }
    private function country(string $code): int
    {
        return (int) ($this->s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'c_country WHERE code='.$this->s->q($code))[0]['rowid'] ?? 0);
    }
    private function contacts($o, array $p, $user, bool $update = false, bool $resolution = false): void
    {
        require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
        $existing = $update ? $this->s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'socpeople WHERE entity='.$this->s->entity.' AND fk_soc='.(int) $o->id.($resolution ? '' : ' AND statut<>0').' ORDER BY rowid') : [];
        $priorExtras = $update ? $this->s->rows('SELECT e.* FROM '.MAIN_DB_PREFIX.'socpeople_extrafields e JOIN '.MAIN_DB_PREFIX.'socpeople p ON p.rowid=e.fk_object WHERE p.entity='.$this->s->entity.' AND p.fk_soc='.(int) $o->id.' ORDER BY e.rowid FOR UPDATE') : [];
        $people = $p['company']['contactPersons'] ?? [];
        if ($update && !$resolution && count($existing) !== count($people)) { throw new DomainException('CONTACT_PERSON_STRUCTURE_CONFLICT'); }
        foreach ($people as $i=>$person) {
            $c = new Contact($this->s->db); $c->socid = $o->id;
            $hasPerson = $update && isset($existing[$i]);
            if ($hasPerson && $c->fetch((int) $existing[$i]['rowid']) <= 0) { throw new DomainException('CONTACT_PERSON_MISSING'); }
            if ($hasPerson) { $c->fetch_optionals(); }
            $c->statut = $c->status = 1;
            $c->lastname = $person['lastName'] ?? ''; $c->firstname = $person['firstName'] ?? '';
            $c->email = $person['emailAddress'] ?? ''; $c->phone_pro = $person['phoneNumber'] ?? '';
            $result = $hasPerson ? $c->update($c->id, $user, 1, 'update', 1) : $c->create($user, 1);
            if ($result <= 0) { throw new RuntimeException('NATIVE_CONTACT_PERSON_FAILED'); }
        }
        if ($update && $resolution) {
            foreach (array_slice($existing, count($people)) as $old) {
                $c = new Contact($this->s->db);
                if ($c->fetch((int) $old['rowid']) <= 0) { throw new DomainException('CONTACT_PERSON_MISSING'); }
                $c->fetch_optionals();
                $c->statut = $c->status = 0;
                if ($c->update($c->id, $user, 1, 'update', 1) <= 0) { throw new RuntimeException('NATIVE_CONTACT_PERSON_FAILED'); }
            }
        }
        foreach ($priorExtras as $priorExtra) {
            $stored = $this->s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'socpeople_extrafields WHERE rowid='.(int) $priorExtra['rowid'].' FOR UPDATE')[0] ?? [];
            unset($stored['tms'], $priorExtra['tms']);
            if ($stored !== $priorExtra) { throw new RuntimeException('NATIVE_CONTACT_PERSON_EXTRAFIELDS_CHANGED'); }
        }
    }
    private function lines($o, string $type, array $p, $user, bool $update = false, bool $resolution = false): void
    {
        $classes = ['propal'=>['comm/propal/class/propaleligne.class.php','PropaleLigne','fk_propal'],
            'order'=>['commande/class/orderline.class.php','OrderLine','fk_commande'],
            'invoice'=>['compta/facture/class/factureligne.class.php','FactureLigne','fk_facture']];
        [$file,$class,$fk] = $classes[$type]; require_once DOL_DOCUMENT_ROOT.'/'.$file;
        if ($update && !$resolution && count($o->lines) !== count($p['lineItems'] ?? [])) { throw new DomainException('DOCUMENT_LINE_STRUCTURE_CONFLICT'); }
        $rang = 0;
        foreach ($p['lineItems'] ?? [] as $item) {
            $l = new $class($this->s->db);
            $existing = $update && isset($o->lines[$rang]);
            if ($existing && $l->fetch((int) $o->lines[$rang]->id) <= 0) { throw new DomainException('NATIVE_LINE_MISSING'); }
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
            $result = $existing ? ($type === 'propal' ? $l->update(1) : $l->update($user, 1)) : ($type === 'order' ? $l->insert($user, 1) : $l->insert(1));
            if ($result <= 0) { throw new RuntimeException('NATIVE_LINE_CREATE_FAILED'); }
            // Native line update methods omit fk_product. Keep line identity,
            // extras and ordering; use CommonObject's native checked field setter.
            $detail = self::OBJECTS[$type][3];
            $selectedProduct = (int) ($product['object_id'] ?? 0);
            if ($existing && $l->setValueFrom('fk_product', $selectedProduct, $detail, (int) $l->id, 'int', 'rowid', 'none', '', '') <= 0) { throw new RuntimeException('NATIVE_LINE_ASSOCIATION_FAILED'); }
            $stored = $this->s->rows('SELECT fk_product,rang FROM '.MAIN_DB_PREFIX.$detail.' WHERE '.$fk.'='.(int) $o->id.' AND rowid='.(int) $l->id.' FOR UPDATE')[0] ?? null;
            if (!$stored || (int) $stored['fk_product'] !== $selectedProduct || (int) $stored['rang'] !== $rang) { throw new RuntimeException('NATIVE_LINE_ASSOCIATION_READBACK_FAILED'); }
        }
        if ($update && $resolution) {
            foreach (array_slice($o->lines, count($p['lineItems'] ?? [])) as $old) {
                $line = new $class($this->s->db);
                if ($line->fetch((int) $old->id) <= 0 || $line->delete($user, 1) <= 0) { throw new RuntimeException('NATIVE_LINE_DELETE_FAILED'); }
            }
        }
    }
    private function shipping($o, array $p, $user, bool $update = false): void
    {
        $orders = array_values(array_filter($p['relatedVouchers'] ?? [], fn($v)=>($v['voucherType'] ?? '') === 'orderconfirmation'));
        if (count($orders) !== 1) { throw new DomainException('NO_UNIQUE_ORDER'); }
        $mapped = $this->s->mapped('order-confirmations', $orders[0]['id']);
        if (!$mapped) { throw new DomainException('NO_UNIQUE_ORDER'); }
        $order = $this->object('order', (int) $mapped['object_id']);
        $o->origin = 'commande'; $o->origin_id = $order->id; $o->date_shipping = strtotime($p['shippingConditions']['shippingDate'] ?? $p['shippingDate'] ?? $p['voucherDate'] ?? 'now');
        if (($update ? $o->update($user, 1) : $o->create($user, 0)) <= 0) { throw new RuntimeException('NATIVE_SHIPMENT_CREATE_FAILED'); }
        if ($update) {
            if ($o->deleteObjectLinked(null, 'commande', (int) $o->id, 'shipping', 0, $user, 1) < 0 || $o->add_object_linked('commande', (int) $order->id, $user, 1) < 0) { throw new RuntimeException('NATIVE_SHIPMENT_LINK_FAILED'); }
        }
        require_once DOL_DOCUMENT_ROOT.'/expedition/class/expeditionligne.class.php';
        if ($update) {
            foreach ($o->lines as $old) {
                $line = new ExpeditionLigne($this->s->db);
                if ($line->fetch((int) $old->id) <= 0 || $line->delete($user, 1) <= 0) { throw new RuntimeException('NATIVE_SHIPMENT_LINE_FAILED'); }
            }
        }
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
    private function lockNative(array $m): void
    {
        [,,$table,$detail,$fk] = self::OBJECTS[$m['object_type']]; $id = (int) $m['object_id'];
        if (!$this->s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.$table.' WHERE entity='.$this->s->entity.' AND rowid='.$id.' FOR UPDATE')) { throw new DomainException('NATIVE_OBJECT_MISSING'); }
        if ($detail) {
            $this->s->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$detail.' WHERE '.$fk.'='.$id.' FOR UPDATE');
            $this->s->query('SELECT e.rowid FROM '.MAIN_DB_PREFIX.$detail.'_extrafields e JOIN '.MAIN_DB_PREFIX.$detail.' l ON l.rowid=e.fk_object WHERE l.'.$fk.'='.$id.' FOR UPDATE');
        }
        $this->s->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$table.'_extrafields WHERE fk_object='.$id.' FOR UPDATE');
        if ($m['object_type'] === 'shipping') { $this->s->query('SELECT rowid FROM '.MAIN_DB_PREFIX."element_element WHERE targettype='shipping' AND fk_target=".$id.' FOR UPDATE'); }
        if ($m['object_type'] === 'thirdparty') {
            $this->s->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'socpeople WHERE entity='.$this->s->entity.' AND fk_soc='.$id.' FOR UPDATE');
            $this->s->query('SELECT e.rowid FROM '.MAIN_DB_PREFIX.'socpeople_extrafields e JOIN '.MAIN_DB_PREFIX.'socpeople p ON p.rowid=e.fk_object WHERE p.entity='.$this->s->entity.' AND p.fk_soc='.$id.' FOR UPDATE');
        }
    }
    private function requireSafeDependentState(array $m): void
    {
        $id = (int) $m['object_id']; $affected = [];
        if (in_array($m['object_type'], ['propal','order','invoice'], true)) {
            $nativeType = ['propal'=>'propal','order'=>'commande','invoice'=>'facture'][$m['object_type']];
            $links = $this->s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'element_element WHERE (sourcetype='.$this->s->q($nativeType).' AND fk_source='.$id.') OR (targettype='.$this->s->q($nativeType).' AND fk_target='.$id.') FOR UPDATE');
            if ($links) { throw new DomainException('NATIVE_DEPENDENT_ASSOCIATIONS_REQUIRE_REVIEW'); }
            if ($m['object_type'] === 'order' && $this->s->rows('SELECT e.rowid FROM '.MAIN_DB_PREFIX.'expeditiondet e JOIN '.MAIN_DB_PREFIX.'commandedet l ON l.rowid=e.fk_elementdet WHERE l.fk_commande='.$id.' FOR UPDATE')) { throw new DomainException('NATIVE_DEPENDENT_ASSOCIATIONS_REQUIRE_REVIEW'); }
        }
        if ($m['object_type'] === 'shipping') {
            $affected = $this->s->rows('SELECT b.* FROM '.MAIN_DB_PREFIX.'expeditiondet_batch b JOIN '.MAIN_DB_PREFIX.'expeditiondet l ON l.rowid=b.fk_expeditiondet WHERE l.fk_expedition='.$id.' FOR UPDATE');
        } elseif ($m['object_type'] === 'invoice') {
            $affected = $this->s->rows('SELECT d.* FROM '.MAIN_DB_PREFIX.'societe_remise_except d LEFT JOIN '.MAIN_DB_PREFIX.'facturedet l ON l.rowid=d.fk_facture_line WHERE d.fk_facture='.$id.' OR d.fk_facture_source='.$id.' OR l.fk_facture='.$id.' FOR UPDATE');
            $time = $this->s->rows('SELECT t.* FROM '.MAIN_DB_PREFIX.'element_time t LEFT JOIN '.MAIN_DB_PREFIX.'facturedet l ON l.rowid=t.invoice_line_id WHERE t.invoice_id='.$id.' OR l.fk_facture='.$id.' FOR UPDATE');
            $affected = array_merge($affected, $time);
        }
        if ($affected) { throw new DomainException('NATIVE_DEPENDENT_ASSOCIATIONS_REQUIRE_REVIEW'); }
    }
    public function resolve(int $resourceId, string $choice, string $checksum, $user, int $issueId): void
    {
        LexwareAccess::require($user, 'mapping');
        if (!in_array($choice, ['Dolibarr','Lexware'], true)) { throw new DomainException('INVALID_CONFLICT_RESOLUTION'); }
        $this->s->begin(true);
        try {
            $r = $this->s->rows('SELECT * FROM '.$this->s->table('resource').' WHERE entity='.$this->s->entity.' AND rowid='.$resourceId.' FOR UPDATE')[0] ?? null;
            if (!$r || !hash_equals($r['checksum'], $checksum)) { throw new DomainException('CONFLICT_SOURCE_CHANGED'); }
            $issue = $this->s->rows('SELECT * FROM '.$this->s->table('issue').' WHERE entity='.$this->s->entity.' AND fk_resource='.$resourceId." AND issue_type='conflict' AND status='open' FOR UPDATE")[0] ?? null;
            if (!$issue || (int) $issue['rowid'] !== $issueId || (json_decode($issue['details_json'], true)['source_checksum'] ?? null) !== $checksum) { throw new DomainException('CONFLICT_CASE_CHANGED'); }
            $m = $this->s->mapping($resourceId);
            if (!$issue || !$m) { throw new DomainException('OPEN_MAPPED_CONFLICT_REQUIRED'); }
            $this->lockNative($m);
            if ($choice === 'Lexware') { $this->requireSafeDependentState($m); }
            $prior = $this->snapshot($m['object_type'], (int) $m['object_id']);
            $this->s->query('INSERT INTO '.$this->s->table('resolution').' (entity,fk_resource,fk_issue,source_checksum,revision,choice,prior_native_json,fk_user,date_creation) VALUES ('.
                $this->s->entity.','.$resourceId.','.(int) $issue['rowid'].','.$this->s->q($checksum).','.$this->s->q((string) $r['revision']).','.$this->s->q($choice).','.$this->s->q($prior).','.(int) $user->id.',NOW())');
            if ($choice === 'Lexware') { $this->apply($r, $user, true); }
            $this->s->query('UPDATE '.$this->s->table('issue')." SET status='resolved' WHERE entity=".$this->s->entity.' AND rowid='.(int) $issue['rowid']);
            $this->s->status($resourceId, 'projected');
            $this->s->audit('resolution', (int) $r['fk_run'], $r, ['choice'=>$choice,'source_checksum'=>$checksum,'issue'=>(int) $issue['rowid']], (int) $user->id);
            $this->s->commit();
        } catch (Throwable $e) { $this->rollbackOwned(); throw $e; }
    }
    public function approve(int $resourceId, string $type, int $objectId, $user, bool $replace = false): void
    {
        LexwareAccess::require($user, 'mapping');
        $r = $this->s->one('resource', $resourceId);
        if (!$r || $r['resource_type'] !== 'contacts' || $type !== 'thirdparty') { throw new DomainException('ONLY_CONTACT_MAPPING_SUPPORTED'); }
        $this->object($type, $objectId);
        $this->s->begin(true);
        try {
            $r = $this->s->rows('SELECT * FROM '.$this->s->table('resource').' WHERE entity='.$this->s->entity.' AND rowid='.$resourceId.' FOR UPDATE')[0] ?? null;
            if (!$r || $r['resource_type'] !== 'contacts') { throw new DomainException('ONLY_CONTACT_MAPPING_SUPPORTED'); }
            // The existing native target row serializes even absent mapping claims.
            $this->lockNative(['object_type'=>$type,'object_id'=>$objectId]);
            $previous = $this->s->mapping($resourceId);
            if ($previous && !$replace) { throw new DomainException('RESOURCE_ALREADY_MAPPED'); }
            $occupied = $this->s->rows('SELECT fk_resource FROM '.$this->s->table('mapping').' WHERE entity='.$this->s->entity.' AND object_type='.$this->s->q($type).' AND object_id='.$objectId.' FOR UPDATE');
            if ($occupied && (int) $occupied[0]['fk_resource'] !== $resourceId) { throw new DomainException('NATIVE_OBJECT_ALREADY_LINKED'); }
            if ($previous) { $this->s->query('DELETE FROM '.$this->s->table('mapping').' WHERE entity='.$this->s->entity.' AND fk_resource='.$resourceId); }
            $this->s->map($r, $type, $objectId, $this->snapshot($type, $objectId), 'linked');
            $this->s->status($resourceId, 'projected');
            $this->s->query('UPDATE '.$this->s->table('issue')." SET status='resolved' WHERE entity=".$this->s->entity.' AND fk_resource='.$resourceId);
            $this->s->audit('mapping', (int) $r['fk_run'], $r, ['object_type'=>$type,'object_id'=>$objectId,'previous_object_id'=>$previous['object_id'] ?? null,'previous'=>$previous['remote_checksum'] ?? null,'current'=>$r['checksum'],'result'=>'manual_link_preserve_existing'], (int) $user->id);
            $this->s->commit();
        } catch (Throwable $e) { $this->rollbackOwned(); throw $e; }
    }
}
