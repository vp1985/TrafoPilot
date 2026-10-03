<?php
declare(strict_types=1);
$root = getenv('HWOS_MODULE_ROOT') ?: dirname(__DIR__, 2).'/modules';
require_once $root.'/hwoslexware/class/LexwareResources.php';
function contract(bool $ok, string $m): void { if (!$ok) { throw new RuntimeException($m); } }
contract(LexwareResources::checksum('{"z":1,"a":{"b":2}}') === LexwareResources::checksum('{"a":{"b":2},"z":1}'), 'object order');
contract(LexwareResources::checksum('{"a":[1,2]}') !== LexwareResources::checksum('{"a":[2,1]}'), 'list order');
contract(LexwareResources::checksum('{"a":{}}') !== LexwareResources::checksum('{"a":[]}'), 'empty object vs list');
contract(LexwareResources::checksum('{"a":1}') === LexwareResources::checksum('{"a":1.0}'), 'semantic numbers');
contract(LexwareResources::checksum('{"n":9223372036854775808123}') !== LexwareResources::checksum('{"n":"9223372036854775808123"}'), 'large number/string source checksum distinction');
contract(LexwareResources::checksum('{"z":1,"n":9223372036854775808123}') === LexwareResources::checksum('{"n":9223372036854775808123,"z":1.0}'), 'large-number checksum preserves object order and semantic small numbers');
$referenceRaw = <<<'JSON'
{"content":[{"id":"fixture","empty":{},"large":9223372036854775808123,"text":"comma, bracket ] and escaped \" quote"}],"last":true}
JSON;
$referenceItems = LexwareResources::referencePayloads($referenceRaw);
contract(count($referenceItems) === 1 && str_contains($referenceItems[0], '9223372036854775808123') && str_contains($referenceItems[0], '"empty":{}'), 'reference raw semantics preserved');
contract(LexwareResources::referencePayloads(' [ {"nested":[{},[]]}, {"id":2} ] ') === ['{"nested":[{},[]]}', '{"id":2}'], 'raw reference list boundaries');
contract(LexwareResources::referencePayloads('[]') === [], 'empty reference list');
contract(LexwareResources::referencePayloads('{"meta": {"content": []} , "content" : [ {"id":1} , {"id":2} ] }') === ['{"id":1}', '{"id":2}'], 'top-level content with whitespace');
contract(LexwareResources::endpoint('purchaseinvoice') === 'vouchers', 'bookkeeping routing');
contract(LexwareResources::endpoint('deliverynote') === 'delivery-notes', 'shipment routing');
contract(LexwareResources::endpoint('unknown') === null, 'unknown retained');
contract(LexwareResources::chooseContact([], [1,2])['status'] === 'ambiguous', 'VAT duplicate');
contract(LexwareResources::chooseContact([8], [1,2])['id'] === 8, 'UUID first');
contract(LexwareResources::chooseContact([], [9])['status'] === 'review', 'existing VAT requires approval');
contract(LexwareResources::chooseContact([], [])['status'] === 'new', 'new contact');
echo "PASS: lossless normalization, routing and duplicate contracts\n";
