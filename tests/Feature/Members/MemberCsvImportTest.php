<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Organization;
use App\Services\MemberCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberCsvImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_creates_new_members(): void
    {
        $organization = Organization::factory()->create();

        $csv = "first_name,last_name,phone_number\nJane,Doe,508-555-0100\nJohn,Smith,(508) 555-0101\n";

        $result = app(MemberCsvImporter::class)->import($organization, $csv);

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame([], $result->errors);

        $this->assertDatabaseHas('members', [
            'organization_id' => $organization->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone_number' => '+15085550100',
            'status' => MemberStatus::Active->value,
        ]);
    }

    public function test_import_updates_existing_members_matched_by_phone_number(): void
    {
        $organization = Organization::factory()->create();
        $existing = Member::factory()->for($organization, 'organization')->create([
            'first_name' => 'Old Name',
            'phone_number' => '+15085550100',
        ]);

        $csv = "first_name,last_name,phone_number\nNew Name,Doe,508-555-0100\n";

        $result = app(MemberCsvImporter::class)->import($organization, $csv);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);

        $this->assertSame('New Name', $existing->fresh()->first_name);
        $this->assertSame(1, Member::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
    }

    public function test_import_reports_errors_for_invalid_rows_without_failing_the_whole_batch(): void
    {
        $organization = Organization::factory()->create();

        $csv = "first_name,last_name,phone_number\nJane,Doe,508-555-0100\n,Missing,508-555-0101\nBad,Number,123\n";

        $result = app(MemberCsvImporter::class)->import($organization, $csv);

        $this->assertSame(1, $result->created);
        $this->assertCount(2, $result->errors);
        $this->assertStringContainsString('Row 3', $result->errors[0]);
        $this->assertStringContainsString('Row 4', $result->errors[1]);
    }

    public function test_import_rejects_a_file_missing_required_columns(): void
    {
        $organization = Organization::factory()->create();

        $csv = "name,phone\nJane,508-555-0100\n";

        $result = app(MemberCsvImporter::class)->import($organization, $csv);

        $this->assertSame(0, $result->created);
        $this->assertTrue($result->hasErrors());
    }

    public function test_import_never_mixes_members_across_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        Member::factory()->for($orgB, 'organization')->create(['phone_number' => '+15085550100']);

        $csv = "first_name,last_name,phone_number\nJane,Doe,508-555-0100\n";

        $result = app(MemberCsvImporter::class)->import($orgA, $csv);

        $this->assertSame(1, $result->created);
        $this->assertSame(2, Member::withoutGlobalScopes()->count());
    }
}
