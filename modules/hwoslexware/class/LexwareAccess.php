<?php
declare(strict_types=1);
final class LexwareAccess
{
    public static function allowed($user, string $right): bool
    {
        if (!in_array($right, ['read','sync','mapping','retry','admin'], true)) { return false; }
        if (!empty($user->admin)) { return true; }
        return !in_array($right, ['sync','admin'], true) && $user->hasRight('hwoslexware', $right);
    }
    public static function require($user, string $right): void
    {
        if (!in_array($right, ['read','sync','mapping','retry','admin'], true) || !self::allowed($user, $right)) {
            throw new DomainException('LEXWARE_PERMISSION_DENIED');
        }
    }
}
