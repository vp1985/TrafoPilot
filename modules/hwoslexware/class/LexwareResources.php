<?php
declare(strict_types=1);
final class LexwareResources
{
    public const ROUTES = ['quotation'=>'quotations', 'orderconfirmation'=>'order-confirmations',
        'deliverynote'=>'delivery-notes', 'invoice'=>'invoices', 'downpaymentinvoice'=>'down-payment-invoices',
        'creditnote'=>'credit-notes', 'dunning'=>'dunnings', 'salesinvoice'=>'vouchers',
        'salescreditnote'=>'vouchers', 'purchaseinvoice'=>'vouchers', 'purchasecreditnote'=>'vouchers'];
    public static function endpoint(string $type): ?string { return self::ROUTES[$type] ?? null; }
    /** Slice validated JSON, retaining number lexemes and object/list distinctions. */
    public static function referencePayloads(string $raw): array
    {
        json_decode($raw, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $raw = trim($raw);
        $value = static function (string $json, int &$pos): string {
            while (isset($json[$pos]) && ctype_space($json[$pos])) { $pos++; }
            $start = $pos; $depth = 0; $quoted = false; $escaped = false;
            for ($length = strlen($json); $pos < $length; $pos++) {
                $char = $json[$pos];
                if ($quoted) {
                    if ($escaped) { $escaped = false; }
                    elseif ($char === '\\') { $escaped = true; }
                    elseif ($char === '"') { $quoted = false; }
                    continue;
                }
                if ($char === '"') { $quoted = true; }
                elseif ($char === '[' || $char === '{') { $depth++; }
                elseif ($char === ']' || $char === '}') {
                    if ($depth === 0) { break; }
                    $depth--;
                    if ($depth === 0) { $pos++; break; }
                }
                elseif ($depth === 0 && ($char === ',' || $char === ':')) { break; }
            }
            return trim(substr($json, $start, $pos - $start));
        };
        if (str_starts_with($raw, '{')) {
            $pos = 1; $list = null;
            while ($pos < strlen($raw) - 1) {
                $key = json_decode($value($raw, $pos), true, 512, JSON_THROW_ON_ERROR);
                $pos++; // colon
                $item = $value($raw, $pos);
                if ($key === 'content') { $list = $item; break; }
                while (isset($raw[$pos]) && ctype_space($raw[$pos])) { $pos++; }
                if (($raw[$pos] ?? '') === ',') { $pos++; } else { break; }
            }
            if ($list === null) { throw new RuntimeException('REFERENCE_LIST_INVALID'); }
            $raw = $list;
        }
        if (!str_starts_with($raw, '[')) { throw new RuntimeException('REFERENCE_LIST_INVALID'); }
        $items = []; $pos = 1;
        while ($pos < strlen($raw) - 1) {
            $item = $value($raw, $pos);
            if ($item !== '') { $items[] = $item; }
            while (isset($raw[$pos]) && ctype_space($raw[$pos])) { $pos++; }
            if (($raw[$pos] ?? '') === ',') { $pos++; } else { break; }
        }
        return $items;
    }
    /** Typed canonical JSON for payloads containing integers beyond PHP's range. */
    private static function typedValue(string $raw, int &$pos, bool &$large): array
    {
        while (isset($raw[$pos]) && ctype_space($raw[$pos])) { $pos++; }
        $char = $raw[$pos];
        if ($char === '"') {
            $start = $pos++;
            while ($raw[$pos] !== '"') { if ($raw[$pos] === '\\') { $pos++; } $pos++; }
            $pos++;
            return ['string', json_decode(substr($raw, $start, $pos-$start), true, 512, JSON_THROW_ON_ERROR)];
        }
        if ($char === '{' || $char === '[') {
            $object = $char === '{'; $end = $object ? '}' : ']'; $pos++; $items = [];
            while (true) {
                while (isset($raw[$pos]) && ctype_space($raw[$pos])) { $pos++; }
                if ($raw[$pos] === $end) { $pos++; break; }
                if ($object) {
                    $key = self::typedValue($raw, $pos, $large)[1];
                    while (ctype_space($raw[$pos])) { $pos++; } $pos++; // colon
                    $items[$key] = self::typedValue($raw, $pos, $large);
                } else { $items[] = self::typedValue($raw, $pos, $large); }
                while (ctype_space($raw[$pos])) { $pos++; }
                if ($raw[$pos] === ',') { $pos++; }
            }
            if ($object) {
                ksort($items, SORT_STRING); $entries = [];
                foreach ($items as $key=>$item) { $entries[] = [(string) $key, $item]; }
                return ['object', $entries];
            }
            return ['array', $items];
        }
        preg_match('/\G(?:true|false|null|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/', $raw, $match, 0, $pos);
        $token = $match[0]; $pos += strlen($token);
        $value = json_decode($token, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (is_string($value)) { $large = true; return ['number', $token]; }
        return [is_bool($value) ? 'boolean' : ($value === null ? 'null' : 'number'), $value];
    }
    public static function checksum(string $raw): string
    {
        $value = json_decode($raw, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $pos = 0; $large = false;
        $typed = self::typedValue($raw, $pos, $large);
        if ($large) { return hash('sha256', json_encode($typed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
        $normalize = function ($v) use (&$normalize) {
            if ($v instanceof stdClass) {
                $a = get_object_vars($v); ksort($a, SORT_STRING);
                foreach ($a as &$item) { $item = $normalize($item); } unset($item);
                return (object) $a;
            }
            if (is_array($v)) { return array_map($normalize, $v); }
            return $v;
        };
        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    public static function chooseContact(array $uuid, array $vat): array
    {
        if (count($uuid) === 1) { return ['status'=>'mapped', 'id'=>$uuid[0]]; }
        if (count($uuid) > 1 || count($vat) > 1) { return ['status'=>'ambiguous', 'candidates'=>$uuid ?: $vat]; }
        if (count($vat) === 1) { return ['status'=>'review', 'candidates'=>$vat]; }
        return ['status'=>'new'];
    }
}
