<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->timestamps();

            // Bids are only accepted if strictly higher than the current one (see
            // DraftBiddingService::placeBid), so the latest row for an item is always
            // its current leader — no MAX() query needed to find it.
            $table->index('interest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bids');
    }
};
