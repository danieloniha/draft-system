<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the current turn's clock started. Null until the host has started the draft.
        Schema::table('drafts', function (Blueprint $table) {
            $table->dateTime('turn_started_at')->nullable();
        });

        // Drafts that already have picks were running before hosts had to start them. Mark
        // them as started so they carry on instead of waiting for a button they never had.
        DB::table('drafts')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('selections')
                ->whereColumn('selections.draft_id', 'drafts.id'))
            ->update(['turn_started_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn('turn_started_at');
        });
    }
};
