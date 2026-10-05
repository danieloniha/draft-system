<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            // Giveaway: take turns claiming items (today's behavior). Bidding: a real auction.
            $table->enum('type', ['giveaway', 'bidding'])->default('giveaway')->after('title');
            // Private: the host invites specific people by email. Public: anyone with the link can join, up to the limit.
            $table->enum('visibility', ['private', 'public'])->default('private')->after('type');
            // How turn order (Giveaway) or item order (Bidding) is decided.
            $table->enum('order_mode', ['host_decided', 'fcfs', 'random'])->default('host_decided')->after('visibility');
            // Applies to both visibilities: caps how many people can ever be part of the draft.
            $table->unsignedInteger('participant_limit')->default(100)->after('order_mode');
            // Generated once, the first time visibility becomes public, and kept stable after that.
            $table->string('public_token')->nullable()->unique()->after('participant_limit');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn(['type', 'visibility', 'order_mode', 'participant_limit', 'public_token']);
        });
    }
};
