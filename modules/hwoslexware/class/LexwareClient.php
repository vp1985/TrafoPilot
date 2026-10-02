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
        $secret = (string) getenv('LEXWARE_TEST_API_KEY_READ_ONLY');
        $configuredFile = (string) getenv('LEXWARE_TEST_API_KEY_READ_ONLY_FILE');
        if ($secret !== '' && $configuredFile !== '') { throw new RuntimeException('READ_ONLY_SECRET_CONFIGURATION_AMBIGUOUS'); }
        if ($secret !== '') { return new self($secret); }
        // Fixed Docker-secret path also works in cron, which does not retain container env.
        $file = $configuredFile !== '' ? $configuredFile : '/run/secrets/lexware_test_api_key_read_only';
        $before = self::secretPathInfo($file);
        $handle = @fopen($file, 'rb');
        if (!$handle) { throw new RuntimeException('READ_ONLY_SECRET_FILE_INVALID'); }
        try {
            $opened = fstat($handle);
            $after = self::secretPathInfo($file);
            if (!$opened || ($opened['mode'] & 0170000) !== 0100000
                || ($opened['mode'] & 0027) !== 0 || $opened['size'] > 4096
                || $opened['uid'] !== $before['uid']
                || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
                || $opened['dev'] !== $after['dev'] || $opened['ino'] !== $after['ino']) {
                throw new RuntimeException('READ_ONLY_SECRET_FILE_INVALID');
            }
            // Limit bytes actually read as well as descriptor size (concurrent growth).
            $value = stream_get_contents($handle, 4097);
            if ($value === false || strlen($value) > 4096) { throw new RuntimeException('READ_ONLY_SECRET_FILE_INVALID'); }
        } finally { fclose($handle); }
        $value = trim($value);
        if (preg_match('/[\\x00-\\x20\\x7f]/', $value)) { throw new RuntimeException('READ_ONLY_SECRET_FILE_INVALID'); }
        return new self($value);
    }
    private static function secretPathInfo(string $file): array
    {
        if (!str_starts_with($file, '/') || str_contains($file, "\0")
            || preg_match('~/(?:\.\.?)(?:/|$)|//~', $file)) {
            throw new RuntimeException('READ_ONLY_SECRET_FILE_INVALID');
        }
        $path = $file;
        $leaf = true;
        $result = [];
        do {
            clearstatcache(true, $path);
            $info = @lstat($path);
            $type = $leaf ? 0100000 : 0040000;
            // Root and the current service account are trusted; other owners are not.
            $trustedOwner = $info && ($info['uid'] === 0 || $info['uid'] === posix_geteuid());
            // Root-owned sticky ancestors such as /tmp cannot replace a private child.
            $stickyAncestor = !$leaf && $path !== dirname($file) && $info
                && $info['uid'] === 0 && ($info['mode'] & 01000);
            if (!$info || ($info['mode'] & 0170000) !== $type || !$trustedOwner
                || ($leaf ? (($info['mode'] & 0027) !== 0 || $info['size'] > 4096)
                    : (($info['mode'] & 0022) !== 0 && !$stickyAncestor))) {
                throw new RuntimeException('READ_ONLY_SECRET_FILE_INVALID');
            }
            if ($leaf) { $result = $info; }
            $leaf = false;
            if ($path === '/') { break; }
            $path = dirname($path);
        } while (true);
        return $result;
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
