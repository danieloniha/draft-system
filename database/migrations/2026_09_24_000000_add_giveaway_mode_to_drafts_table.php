<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            // Only meaningful when type = 'giveaway'. 'items': today's turn-based picking.
            // 'money': the host defines a payout table instead — nobody picks anything.
            $table->enum('giveaway_mode', ['items', 'money'])->default('items')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn('giveaway_mode');
        });
    }
};
