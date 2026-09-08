<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\Member;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Api\V2010\Account\MessageList;

/**
 * Sends real SMS via Twilio.
 *
 * Depends on the account's MessageList resource directly (`$client->messages`)
 * rather than on Twilio\Rest\Client itself — the SDK resolves `->messages`
 * through magic __get/__call proxying, which is awkward to mock in tests.
 * MessageList::create() is a plain method on a non-final class, so it mocks
 * cleanly. See AppServiceProvider for how the singleton Client resolves this.
 */
class TwilioSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly MessageList $messages,
        private readonly ?string $defaultFromNumber,
    ) {}

    public function send(Member $member, string $body): SmsSendResult
    {
        // Not $member->organization?->twilio_phone_number ?? ... on one line:
        // that specific nullsafe-into-coalesce shape trips a Larastan false
        // positive (see the identical issue worked through in
        // app/Mail/OrganizationInvitationMail.php).
        $organization = $member->organization;
        $from = $organization !== null && $organization->twilio_phone_number !== null
            ? $organization->twilio_phone_number
            : $this->defaultFromNumber;

        if ($from === null) {
            return new SmsSendResult(successful: false, errorCode: 'missing_from_number');
        }

        try {
            $message = $this->messages->create($member->phone_number, [
                'from' => $from,
                'body' => $body,
            ]);

            return new SmsSendResult(successful: true, providerMessageId: $message->sid);
        } catch (TwilioException $e) {
            return new SmsSendResult(successful: false, errorCode: (string) $e->getCode());
        }
    }
}
