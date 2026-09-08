<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PhoneNumberNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNumberNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function numbers(): array
    {
        return [
            '10 digits' => ['5085550100', '+15085550100'],
            '11 digits with leading 1' => ['15085550100', '+15085550100'],
            'formatted with parens/dashes/spaces' => ['(508) 555-0100', '+15085550100'],
            'formatted with dots' => ['508.555.0100', '+15085550100'],
            'already E.164' => ['+15085550100', '+15085550100'],
            'too short' => ['555010', null],
            'too long' => ['150855501000', null],
            'area code starts with 0' => ['0085550100', null],
            'area code starts with 1' => ['1085550100', null],
            'exchange code starts with 0' => ['5080550100', null],
            'exchange code starts with 1' => ['5081550100', null],
            'non-numeric junk' => ['not-a-number', null],
            'empty string' => ['', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_normalizes_or_rejects_numbers(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumberNormalizer::toE164($raw));
    }

    public function test_formats_an_e164_number_for_display(): void
    {
        $this->assertSame('+1 (508) 555-0100', PhoneNumberNormalizer::format('+15085550100'));
    }

    public function test_format_falls_back_to_the_input_unchanged_for_non_e164_values(): void
    {
        $this->assertSame('not-a-number', PhoneNumberNormalizer::format('not-a-number'));
        $this->assertSame('+445085550100', PhoneNumberNormalizer::format('+445085550100'));
    }
}
