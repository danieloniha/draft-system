<?php

namespace App\Services\Payments;

use App\Models\PayoutDestination;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pays a wallet balance out to a bank account. The balance is debited first, so the money can
 * never be spent twice; if Bachs rejects the payout the debit is reversed.
 */
class WithdrawalService
{
    public function __construct(private BachsClient $bachs, private WalletService $wallet)
    {
    }

    public function addDestination(User $user, string $accountName, string $accountNumber, string $bankCode): PayoutDestination
    {
        $bachsId = $this->bachs->createDestination($accountNumber, $bankCode);

        return $user->payoutDestinations()->create([
            'account_name' => $accountName,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'bank_name' => collect($this->bachs->banks())->firstWhere('code', $bankCode)['name'] ?? null,
            'bachs_destination_id' => $bachsId,
        ]);
    }

    public function withdraw(User $user, PayoutDestination $destination, int $amount): Withdrawal
    {
        // Checked before anything is debited: Bachs may still be approving a new account.
        if (! $this->destinationIsUsable($destination)) {
            throw ValidationException::withMessages(['destination_id' => 'This bank account is not ready to receive money yet. Try again shortly.']);
        }

        $withdrawal = DB::transaction(function () use ($user, $destination, $amount) {
            $withdrawal = Withdrawal::create([
                'user_id' => $user->id,
                'payout_destination_id' => $destination->id,
                'amount' => $amount,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            $this->wallet->debit($user, $amount, 'withdrawal', 'withdrawal:'.$withdrawal->id, null, ['withdrawal_id' => $withdrawal->id]);

            return $withdrawal;
        });

        try {
            $payout = $this->bachs->createPayout(
                $destination->bachs_destination_id,
                $amount,
                'wd_'.$withdrawal->id,
                $withdrawal->idempotency_key,
                // Banks show only the first few characters, so the useful part comes first.
                'Giveaway win',
            );
            $withdrawal->update(['bachs_payout_id' => $payout['id']]);
        } catch (Throwable $e) {
            report($e);
            $this->fail($withdrawal, 'Could not reach the payment provider.');
        }

        return $withdrawal->fresh();
    }

    /**
     * Apply a Bachs payout event: payout.paid completes it, payout.failed reverses the debit.
     */
    public function handleEvent(string $type, ?string $reference, ?string $reason = null): void
    {
        if (! $reference || ! str_starts_with($reference, 'wd_')) {
            return;
        }

        $withdrawal = Withdrawal::find((int) substr($reference, 3));
        if (! $withdrawal || $withdrawal->status !== 'pending') {
            return;
        }

        if ($type === 'payout.paid') {
            $withdrawal->update(['status' => 'completed']);
        } elseif ($type === 'payout.failed') {
            $this->fail($withdrawal, $reason ?? 'The bank rejected the payout.');
        }
    }

    private function destinationIsUsable(PayoutDestination $destination): bool
    {
        try {
            return $this->bachs->destinationIsUsable($destination->bachs_destination_id);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function fail(Withdrawal $withdrawal, string $reason): void
    {
        DB::transaction(function () use ($withdrawal, $reason) {
            $locked = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($locked->status !== 'pending') {
                return;
            }

            $locked->update(['status' => 'failed', 'failure_reason' => $reason]);
            $this->wallet->credit(User::findOrFail($locked->user_id), $locked->amount, 'withdrawal_reversal', 'withdrawal_reversal:'.$locked->id);
        });
    }
}
