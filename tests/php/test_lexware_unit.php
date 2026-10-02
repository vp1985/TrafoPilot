<?php
declare(strict_types=1);
$root = getenv('HWOS_MODULE_ROOT') ?: dirname(__DIR__, 2).'/modules';
require_once $root.'/hwoslexware/class/LexwareClient.php';
function lxAssert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$calls = []; $now = 0.0; $waits = [];
$client = new LexwareClient('fixture-secret', function ($path, $accept) use (&$calls) {
    $calls[] = $path;
    return count($calls) === 1 ? [429, '', ['retry-after'=>'1']] : [200, '{"organizationId":"a4545756-abd0-4ab9-965b-ecd42c41fc7c","companyName":"Holger Testzentrum"}', []];
}, function () use (&$now) { return $now; }, function ($delay) use (&$now, &$waits) { $now += $delay; $waits[] = $delay; });
lxAssert($client->profile()['companyName'] === 'Holger Testzentrum', 'profile retry');
lxAssert($client->verifiedProfilePayload() === '{"organizationId":"a4545756-abd0-4ab9-965b-ecd42c41fc7c","companyName":"Holger Testzentrum"}', 'original verified profile retained');
lxAssert(count($calls) === 2 && $now >= 1, '429 backoff');
foreach (['/v1/invoices/a/document', 'https://evil.invalid', '/v1/contacts/../../profile'] as $path) {
    try { $client->get($path); throw new RuntimeException('unsafe path accepted'); } catch (InvalidArgumentException $e) {}
}
$client->get('/v1/countries');
lxAssert($now >= 1.6, 'rate limit safety margin');
$bad = new LexwareClient('fixture-secret', fn()=>[200, '{"organizationId":"wrong","companyName":"Holger Testzentrum"}', []]);
try { $bad->profile(); throw new RuntimeException('wrong organization accepted'); } catch (DomainException $e) {}
try { $bad->verifiedProfilePayload(); throw new RuntimeException('unverified profile retained'); } catch (DomainException $e) {}
echo "PASS: Lexware GET allowlist, profile, rate limit and retry\n";
$attempt = 0;
$network = new LexwareClient('fixture-secret', function () use (&$attempt) {
    $attempt++;
    if ($attempt === 1) { throw new RuntimeException('fixture network error containing fixture-secret'); }
    return $attempt === 2 ? [504,'fixture-secret',[]] : [200,'[]',[]];
}, fn()=>10000.0, fn($s)=>null);
lxAssert($network->json('/v1/countries') === [] && $attempt === 3, 'network and 504 retry');
$forbidden = new LexwareClient('fixture-secret', fn()=>[403,'fixture-secret',[]], fn()=>10000.0, fn($s)=>null);
try { $forbidden->get('/v1/profile'); throw new DomainException('403 accepted'); }
catch (RuntimeException $e) { lxAssert($e->getMessage() === 'LEXWARE_HTTP_403', 'error redaction'); }
$failures = 0;
$exhaust = new LexwareClient('fixture-secret', function () use (&$failures) { $failures++; return [503,'sensitive fixture',[]]; }, fn()=>10000.0, fn($s)=>null);
try { $exhaust->get('/v1/profile'); throw new DomainException('503 accepted'); }
catch (RuntimeException $e) { lxAssert($failures === 5 && $e->getMessage() === 'LEXWARE_RETRY_EXHAUSTED_503', 'bounded retries'); }
echo "PASS: network/5xx/504 retries, bounded failures and error redaction\n";
