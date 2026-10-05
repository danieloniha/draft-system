<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interests', function (Blueprint $table) {
            // Bidding only: the item's place in the auction sequence. Unused for Giveaway.
            $table->unsignedInteger('position')->nullable()->after('image_path');
            // When this item's bidding window ended. Set at most once; null means still upcoming or open.
            $table->dateTime('closed_at')->nullable()->after('position');
            $table->foreignId('winning_team_id')->nullable()->after('closed_at')->constrained('teams')->nullOnDelete();
            $table->unsignedInteger('winning_amount')->nullable()->after('winning_team_id');
        });
    }

    public function down(): void
    {
        // Two calls: SQLite refuses more than one dropColumn-type command per modification,
        // and dropConstrainedForeignId's own dropColumn would collide with a second one here.
        Schema::table('interests', function (Blueprint $table) {
            $table->dropForeign(['winning_team_id']);
        });

        Schema::table('interests', function (Blueprint $table) {
            $table->dropColumn(['position', 'closed_at', 'winning_team_id', 'winning_amount']);
        });
    }
};
