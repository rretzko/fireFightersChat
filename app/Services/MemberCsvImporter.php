<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\MemberImportResult;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Parses a roster CSV (header row required: first_name, last_name, phone_number)
 * and upserts Members within the given organization. An existing member with the
 * same normalized phone number is updated rather than duplicated, so a department
 * can re-upload its whole roster to fix names without hand-editing each row.
 *
 * Takes $organization explicitly rather than relying on the ambient tenant
 * context, so it works from console/queued contexts too — that means every
 * lookup here must bypass OrganizationScope (which fails closed with no
 * tenant bound) and filter by $organization->id explicitly instead.
 */
class MemberCsvImporter
{
    public function import(Organization $organization, string $csvContents): MemberImportResult
    {
        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $csvContents) ?: [],
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return new MemberImportResult(created: 0, updated: 0, errors: ['The file is empty.']);
        }

        $header = array_map(
            fn (?string $column): string => strtolower(trim($column ?? '')),
            str_getcsv(array_shift($lines)),
        );

        $firstNameIndex = array_search('first_name', $header, true);
        $lastNameIndex = array_search('last_name', $header, true);
        $phoneIndex = array_search('phone_number', $header, true);

        if ($firstNameIndex === false || $phoneIndex === false) {
            return new MemberImportResult(
                created: 0,
                updated: 0,
                errors: ['The file must have "first_name" and "phone_number" columns.'],
            );
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        DB::transaction(function () use ($lines, $firstNameIndex, $lastNameIndex, $phoneIndex, $organization, &$created, &$updated, &$errors): void {
            foreach ($lines as $index => $line) {
                $row = str_getcsv($line);
                $rowNumber = $index + 2; // +1 for the header row, +1 for 1-indexing

                $firstName = trim($row[$firstNameIndex] ?? '');
                $lastName = $lastNameIndex !== false ? trim($row[$lastNameIndex] ?? '') : '';
                $rawPhone = trim($row[$phoneIndex] ?? '');

                if ($firstName === '') {
                    $errors[] = "Row {$rowNumber}: missing first name.";

                    continue;
                }

                $phone = PhoneNumberNormalizer::toE164($rawPhone);

                if ($phone === null) {
                    $errors[] = "Row {$rowNumber}: \"{$rawPhone}\" is not a valid US phone number.";

                    continue;
                }

                $member = Member::withoutGlobalScope(OrganizationScope::class)
                    ->where('organization_id', $organization->id)
                    ->where('phone_number', $phone)
                    ->first();

                if ($member !== null) {
                    // Not $member->update(...): save()/update() on an
                    // already-loaded model go through newModelQuery(), which
                    // RE-APPLIES OrganizationScope even though the SELECT
                    // above bypassed it — with no tenant bound (this
                    // importer's whole point), that write would silently
                    // match zero rows and no-op. The query-builder update
                    // below bypasses the scope for the write too.
                    Member::withoutGlobalScope(OrganizationScope::class)
                        ->whereKey($member->id)
                        ->update([
                            'first_name' => $firstName,
                            'last_name' => $lastName !== '' ? $lastName : null,
                        ]);
                    $updated++;
                } else {
                    Member::create([
                        'organization_id' => $organization->id,
                        'first_name' => $firstName,
                        'last_name' => $lastName !== '' ? $lastName : null,
                        'phone_number' => $phone,
                        'status' => MemberStatus::Active,
                    ]);
                    $created++;
                }
            }
        });

        return new MemberImportResult($created, $updated, $errors);
    }
}
