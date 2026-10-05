<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained()->cascadeOnDelete();
            // Inclusive rank range this tier covers — e.g. rank_from=1, rank_to=5 pays every
            // one of the top 5 participants the same amount. A single rank is rank_from = rank_to.
            $table->unsignedInteger('rank_from');
            $table->unsignedInteger('rank_to');
            // Paid to EACH participant in the range, not split across them.
            $table->unsignedInteger('amount');
            $table->timestamps();

            $table->index('draft_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_tiers');
    }
};
