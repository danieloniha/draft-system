<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place a user's balance changes. Every change is an append-only ledger row, written
 * under a row lock, and carries a unique reference: replaying the same reference (a webhook
 * delivered twice, a double click) changes nothing and returns the original row.
 */
class WalletService
{
    public function credit(User $user, int $amount, string $type, string $reference, ?int $draftId = null, array $meta = []): WalletTransaction
    {
        return $this->apply($user, abs($amount), $type, $reference, $draftId, $meta);
    }

    /**
     * @throws ValidationException when the balance does not cover the amount
     */
    public function debit(User $user, int $amount, string $type, string $reference, ?int $draftId = null, array $meta = []): WalletTransaction
    {
        return $this->apply($user, -abs($amount), $type, $reference, $draftId, $meta);
    }

    private function apply(User $user, int $signed, string $type, string $reference, ?int $draftId, array $meta): WalletTransaction
    {
        // Joins an outer transaction when there is one (DraftPayoutService::start).
        return DB::transaction(function () use ($user, $signed, $type, $reference, $draftId, $meta) {
            $existing = WalletTransaction::where('reference', $reference)->first();
            if ($existing) {
                return $existing;
            }

            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $balance = (int) $locked->balance + $signed;

            if ($balance < 0) {
                throw ValidationException::withMessages(['amount' => 'Your wallet balance is too low for this.']);
            }

            $locked->update(['balance' => $balance]);
            $user->balance = $balance;

            return WalletTransaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $signed,
                'balance_after' => $balance,
                'draft_id' => $draftId,
                'reference' => $reference,
                'meta' => $meta ?: null,
            ]);
        });
    }
}
