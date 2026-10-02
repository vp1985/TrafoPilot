<?php
declare(strict_types=1);
final class LexwareAccess
{
    public static function require($user, string $right): void
    {
        if (!in_array($right, ['read','sync','mapping','retry','admin'], true) || !$user->hasRight('hwoslexware', $right)) {
            throw new DomainException('LEXWARE_PERMISSION_DENIED');
        }
    }
}
