<?php

declare(strict_types=1);

namespace App\Services\Sms;

final class SmsSendResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
    ) {}
}
