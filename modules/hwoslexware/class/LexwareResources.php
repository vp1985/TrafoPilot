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
    public static function checksum(string $raw): string
    {
        $value = json_decode($raw, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
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
