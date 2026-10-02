<?php
declare(strict_types=1);

/** GET-only transport: fixed host, explicit paths, no redirects or remote error bodies. */
final class LexwareClient
{
    public const ORGANIZATION = 'a4545756-abd0-4ab9-965b-ecd42c41fc7c';
    private float $last = -100.0;
    private ?string $verifiedProfilePayload = null;
    private $transport;
    private $clock;
    private $sleep;
    public function __construct(private string $secret, ?callable $transport = null, ?callable $clock = null, ?callable $sleep = null)
    {
        if ($secret === '') { throw new RuntimeException('READ_ONLY_SECRET_MISSING'); }
        $this->clock = $clock ?? fn()=>microtime(true);
        $this->sleep = $sleep ?? fn($s)=>usleep((int) ceil($s * 1000000));
        $this->transport = $transport ?? function ($path, $accept) {
            $headers = [];
            $ch = curl_init('https://api.lexware.io'.$path);
            curl_setopt_array($ch, [CURLOPT_HTTPGET=>true, CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>35,
                CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->secret, 'Accept: '.$accept],
                CURLOPT_HEADERFUNCTION=>function ($ch, $line) use (&$headers) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) { $headers[strtolower(trim($parts[0]))] = trim($parts[1]); }
                    return strlen($line);
                }]);
            $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return [$body === false ? 0 : $status, $body === false ? '' : $body, $headers];
        };
    }
    public static function fromEnvironment(): self
    {
        return new self((string) getenv('LEXWARE_TEST_API_KEY_READ_ONLY'));
    }
    public function get(string $path, string $accept = 'application/json'): array
    {
        if (!preg_match('~^/v1/(?:profile|countries|payment-conditions|posting-categories|print-layouts|voucherlist|contacts|articles|recurring-templates|event-subscriptions)(?:/[a-fA-F0-9-]{36})?(?:\?[^\r\n#]*)?$|^/v1/(?:quotations|order-confirmations|delivery-notes|invoices|down-payment-invoices|credit-notes|dunnings|vouchers|payments|files)/[a-fA-F0-9-]{36}(?:/(?:file|status))?$|^/v1/vouchers\?[^\r\n#]*$~D', $path)) {
            throw new InvalidArgumentException('ENDPOINT_NOT_ALLOWED');
        }
        if (!in_array($accept, ['application/json', 'application/pdf', 'application/xml', '*/*'], true)) {
            throw new InvalidArgumentException('REPRESENTATION_NOT_ALLOWED');
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $delay = 0.6 - (($this->clock)() - $this->last);
            if ($delay > 0) { ($this->sleep)($delay); }
            $this->last = ($this->clock)();
            try { [$status, $body, $headers] = ($this->transport)($path, $accept); }
            catch (Throwable $e) { $status = 0; $body = ''; $headers = []; }
            if ($status >= 200 && $status < 300) { return ['body'=>$body, 'headers'=>$headers, 'status'=>$status]; }
            if ($status !== 0 && $status !== 429 && $status < 500) { throw new RuntimeException('LEXWARE_HTTP_'.$status, $status); }
            if ($attempt < 4) { ($this->sleep)(max(2 ** $attempt, min(60, (float) ($headers['retry-after'] ?? 0)))); }
        }
        throw new RuntimeException('LEXWARE_RETRY_EXHAUSTED_'.$status, $status);
    }
    public function json(string $path): array
    {
        $result = json_decode($this->get($path)['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) { throw new RuntimeException('LEXWARE_INVALID_JSON'); }
        return $result;
    }
    public function profile(): array
    {
        $this->verifiedProfilePayload = null;
        $raw = $this->get('/v1/profile')['body'];
        $p = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (($p['organizationId'] ?? '') !== self::ORGANIZATION || ($p['companyName'] ?? '') !== 'Holger Testzentrum') {
            throw new DomainException('LEXWARE_ORGANIZATION_MISMATCH');
        }
        $this->verifiedProfilePayload = $raw;
        return $p;
    }
    public function verifiedProfilePayload(): string
    {
        if ($this->verifiedProfilePayload === null) { throw new DomainException('PROFILE_NOT_VERIFIED'); }
        return $this->verifiedProfilePayload;
    }
}
