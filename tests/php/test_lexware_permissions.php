<?php
declare(strict_types=1);
$root = getenv('HWOS_MODULE_ROOT') ?: dirname(__DIR__, 2).'/modules';
require_once $root.'/hwoslexware/class/LexwareAccess.php';
$u = new class { public function hasRight($module, $right) { return $module === 'hwoslexware' && $right === 'read'; } };
LexwareAccess::require($u, 'read');
foreach (['sync','mapping','retry','admin','unknown'] as $right) {
    try { LexwareAccess::require($u, $right); throw new RuntimeException('permission bypass'); } catch (DomainException $e) {}
}
$syncUser = new class { public int $admin = 0; public function hasRight($module, $right) { return true; } };
try { LexwareAccess::require($syncUser, 'sync'); throw new RuntimeException('non-admin sync right bypass'); } catch (DomainException $e) {}
$administrator = new class { public int $admin = 1; public function hasRight($module, $right) { return false; } };
foreach (['read','sync','mapping','retry','admin'] as $right) { LexwareAccess::require($administrator, $right); }
echo "PASS: server-side Lexware permissions\n";
