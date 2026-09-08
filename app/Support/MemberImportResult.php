<?php

declare(strict_types=1);

namespace App\Support;

final class MemberImportResult
{
    /**
     * @param  array<int, string>  $errors  Human-readable "Row N: reason" messages.
     */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function total(): int
    {
        return $this->created + $this->updated;
    }
}
