<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            // Only meaningful when giveaway_mode = 'money'. The total the host is giving away —
            // payout tiers may not add up to more than this (see PayoutTier / DraftEditor).
            $table->unsignedInteger('payout_budget')->nullable()->after('giveaway_mode');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn('payout_budget');
        });
    }
};
