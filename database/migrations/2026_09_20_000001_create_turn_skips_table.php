<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turn_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('turn_number');
            $table->timestamps();

            // A turn can only be skipped once, even if two requests notice the expiry together.
            $table->unique(['draft_id', 'turn_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turn_skips');
    }
};
