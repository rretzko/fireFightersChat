<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renamed to disambiguate from members.phone_number (the SMS roster
 * destination number, tenant-scoped) — this column is unrelated contact
 * metadata on the login account (global, like name/email). Same column
 * name on both tables was flagged as a naming smell shortly after adding
 * this field; see docs/implementation-plan.md.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('phone_number', 'contact_phone_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('contact_phone_number', 'phone_number');
        });
    }
};
