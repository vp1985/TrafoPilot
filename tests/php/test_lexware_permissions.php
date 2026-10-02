<?php
declare(strict_types=1);
$root = getenv('HWOS_MODULE_ROOT') ?: dirname(__DIR__, 2).'/modules';
require_once $root.'/hwoslexware/class/LexwareAccess.php';
$u = new class { public function hasRight($module, $right) { return $module === 'hwoslexware' && $right === 'read'; } };
LexwareAccess::require($u, 'read');
foreach (['sync','mapping','retry','admin','unknown'] as $right) {
    try { LexwareAccess::require($u, $right); throw new RuntimeException('permission bypass'); } catch (DomainException $e) {}
}
echo "PASS: server-side Lexware permissions\n";
