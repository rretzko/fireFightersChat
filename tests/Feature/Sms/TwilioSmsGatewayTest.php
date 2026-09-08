<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Models\Member;
use App\Models\Organization;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\TwilioSmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Twilio\Rest\Api\V2010\Account\MessageList;
use Twilio\Version;

/**
 * No real Twilio API calls here — MessageList is mocked throughout, so
 * these tests cost nothing and need no network access or real credentials.
 *
 * MessageInstance results are built as real instances (via a mocked Version
 * dependency it never actually calls), not mocked directly — Mockery's
 * shouldReceive('__get') does not intercept PHP's native `$obj->sid`
 * property-access syntax for this class, confirmed empirically; a real
 * instance populated from a payload array is both simpler and correct.
 */
class TwilioSmsGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function messageInstance(string $sid): MessageInstance
    {
        return new MessageInstance(Mockery::mock(Version::class), ['sid' => $sid], 'AC_TEST');
    }

    public function test_it_sends_via_the_configured_default_from_number_when_the_organization_has_none(): void
    {
        $organization = Organization::factory()->create(['twilio_phone_number' => null]);
        $member = Member::factory()->for($organization, 'organization')->create(['phone_number' => '+15085550100']);

        $messageInstance = $this->messageInstance('SM_TEST_123');

        $messages = Mockery::mock(MessageList::class);
        $messages->shouldReceive('create')
            ->once()
            ->with('+15085550100', ['from' => '+18005550199', 'body' => 'Muster tonight'])
            ->andReturn($messageInstance);

        $gateway = new TwilioSmsGateway($messages, '+18005550199');

        $result = $gateway->send($member, 'Muster tonight');

        $this->assertTrue($result->successful);
        $this->assertSame('SM_TEST_123', $result->providerMessageId);
    }

    public function test_it_prefers_the_organizations_own_twilio_number_over_the_default(): void
    {
        $organization = Organization::factory()->create(['twilio_phone_number' => '+18885550100']);
        $member = Member::factory()->for($organization, 'organization')->create(['phone_number' => '+15085550100']);

        $messageInstance = $this->messageInstance('SM_TEST_456');

        $messages = Mockery::mock(MessageList::class);
        $messages->shouldReceive('create')
            ->once()
            ->with('+15085550100', ['from' => '+18885550100', 'body' => 'Test'])
            ->andReturn($messageInstance);

        $gateway = new TwilioSmsGateway($messages, '+18005550199');

        $result = $gateway->send($member, 'Test');

        $this->assertTrue($result->successful);
    }

    public function test_it_fails_without_calling_twilio_when_no_from_number_is_available_anywhere(): void
    {
        $organization = Organization::factory()->create(['twilio_phone_number' => null]);
        $member = Member::factory()->for($organization, 'organization')->create();

        $messages = Mockery::mock(MessageList::class);
        $messages->shouldNotReceive('create');

        $gateway = new TwilioSmsGateway($messages, null);

        $result = $gateway->send($member, 'Test');

        $this->assertFalse($result->successful);
        $this->assertSame('missing_from_number', $result->errorCode);
    }

    public function test_it_reports_failure_when_twilio_throws(): void
    {
        $organization = Organization::factory()->create();
        $member = Member::factory()->for($organization, 'organization')->create();

        $messages = Mockery::mock(MessageList::class);
        $messages->shouldReceive('create')
            ->once()
            ->andThrow(new RestException('Invalid phone number', 21211, 400));

        $gateway = new TwilioSmsGateway($messages, '+18005550199');

        $result = $gateway->send($member, 'Test');

        $this->assertFalse($result->successful);
        $this->assertSame('21211', $result->errorCode);
        $this->assertNull($result->providerMessageId);
    }

    /**
     * Confirms AppServiceProvider's container wiring resolves 'twilio' to
     * the real gateway class. Constructing Twilio\Rest\Client and reading
     * ->messages doesn't make any network request by itself (only an actual
     * ->create() call would), so this is safe to run with no mocking and no
     * real credentials required.
     */
    public function test_the_gateway_binding_resolves_to_twilio_sms_gateway_when_configured(): void
    {
        config(['sms.gateway' => 'twilio']);

        $this->assertInstanceOf(TwilioSmsGateway::class, $this->app->make(SmsGateway::class));
    }
}
