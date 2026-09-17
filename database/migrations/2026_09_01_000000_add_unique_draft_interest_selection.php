<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('selections', function (Blueprint $table) {
            $table->unique(['draft_id', 'interest_id'], 'selections_draft_interest_unique');
        });
    }

    public function down(): void
    {
        Schema::table('selections', function (Blueprint $table) {
            $table->dropUnique('selections_draft_interest_unique');
        });
    }
};
