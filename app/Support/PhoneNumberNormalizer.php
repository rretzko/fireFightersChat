<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalizes phone numbers to E.164 before they ever reach Twilio.
 *
 * Deliberately US/Canada (NANP) only, matching this prototype's target market
 * (US volunteer fire departments) — a full international library (e.g.
 * libphonenumber) would be the right call once/if the product expands beyond
 * that, but is unwarranted complexity for the prototype today.
 */
class PhoneNumberNormalizer
{
    /**
     * Returns the number formatted as +1XXXXXXXXXX, or null if it can't be
     * confidently normalized as a 10-digit NANP number.
     */
    public static function toE164(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        // NANP area codes and exchange codes can't start with 0 or 1.
        if (in_array($digits[0], ['0', '1'], true) || in_array($digits[3], ['0', '1'], true)) {
            return null;
        }

        return '+1'.$digits;
    }

    /**
     * Formats a stored E.164 number for display: "+1 (508) 555-0100". Never
     * used for anything sent to Twilio or stored — display only.
     *
     * Falls back to returning the input unchanged if it isn't a NANP E.164
     * number, rather than throwing — this is a display helper, not a
     * validator, and every stored phone_number should already be valid.
     */
    public static function format(string $e164): string
    {
        if (! preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $matches)) {
            return $e164;
        }

        return sprintf('+1 (%s) %s-%s', $matches[1], $matches[2], $matches[3]);
    }
}
