<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Sender = 'sender';

    /**
     * Roles allowed to manage organization settings and the member roster.
     *
     * @return array<int, self>
     */
    public static function administrative(): array
    {
        return [self::Owner, self::Admin];
    }

    public function isAdministrative(): bool
    {
        return in_array($this, self::administrative(), strict: true);
    }
}
