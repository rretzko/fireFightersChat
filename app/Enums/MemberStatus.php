<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberStatus: string
{
    case Active = 'active';
    case OptedOut = 'opted_out';
    case Invalid = 'invalid';
}
