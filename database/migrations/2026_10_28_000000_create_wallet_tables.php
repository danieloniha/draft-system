<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Whole naira. A cache of the ledger: only WalletService ever changes it.
            $table->unsignedBigInteger('balance')->default(0);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // funding, escrow, prize, refund, withdrawal, withdrawal_reversal
            $table->string('type');
            // Signed: credits are positive, debits negative.
            $table->bigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->foreignId('draft_id')->nullable()->constrained()->nullOnDelete();
            // Makes every ledger write idempotent: replaying one is a no-op.
            $table->string('reference')->unique();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });

        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('amount');
            $table->string('status')->default('pending'); // pending, succeeded, failed
            $table->string('bachs_checkout_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestamps();
        });

        Schema::create('payout_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('bank_code');
            $table->string('bank_name')->nullable();
            $table->string('account_number');
            $table->string('account_name');
            $table->string('bachs_destination_id')->nullable();
            $table->timestamps();
        });

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_destination_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status')->default('pending'); // pending, completed, failed
            $table->string('bachs_payout_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('payout_destinations');
        Schema::dropIfExists('payment_intents');
        Schema::dropIfExists('wallet_transactions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance');
        });
    }
};
