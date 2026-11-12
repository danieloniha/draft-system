<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adds money to a host's wallet: a Bachs checkout is opened for an amount, and the wallet is
 * credited when Bachs' webhook says the payment succeeded (never on the browser redirect).
 */
class FundingService
{
    public function __construct(private BachsClient $bachs, private WalletService $wallet)
    {
    }

    public function start(User $user, int $amount): PaymentIntent
    {
        $intent = PaymentIntent::create([
            'user_id' => $user->id,
            'reference' => 'fund_'.Str::ulid(),
            'amount' => $amount,
        ]);

        $checkout = $this->bachs->createCheckout(
            $intent->reference,
            $amount,
            $user->email,
            $user->username,
            $this->returnUrl(),
        );

        $intent->update([
            'bachs_checkout_id' => $checkout['checkout_id'],
            'checkout_url' => $checkout['checkout_url'],
        ]);

        return $intent;
    }

    /**
     * Where Bachs sends the customer after paying. Bachs refuses local addresses, so in
     * development BACHS_RETURN_URL is a public tunnel URL; given without a path it means that
     * site's wallet page.
     */
    public function returnUrl(): string
    {
        $configured = config('services.bachs.return_url');
        if (! $configured) {
            return route('wallet.show');
        }

        $path = parse_url($configured, PHP_URL_PATH);

        return in_array($path, [null, '', '/'], true)
            ? rtrim($configured, '/').parse_url(route('wallet.show'), PHP_URL_PATH)
            : $configured;
    }

    /**
     * Credit any of the user's payments that Bachs has completed but whose webhook hasn't
     * arrived (a tunnel that was down, a misconfigured endpoint). Bachs is asked directly, so
     * this never trusts the browser; crediting goes through the same idempotent path as the
     * webhook, so doing both can't pay twice.
     */
    public function reconcile(User $user): void
    {
        $pending = PaymentIntent::where('user_id', $user->id)
            ->where('status', 'pending')
            ->whereNotNull('bachs_checkout_id')
            ->where('created_at', '>=', now()->subDays(2))
            ->get();

        foreach ($pending as $intent) {
            try {
                $session = $this->bachs->checkout($intent->bachs_checkout_id);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if (($session['status'] ?? null) === 'completed' && data_get($session, 'charge.status') === 'succeeded') {
                $this->handleEvent('collection.succeeded', $intent->reference, data_get($session, 'charge.amount'));
            }
        }
    }

    /**
     * Apply a Bachs event. Unknown references and event types are ignored (a 200 still goes back
     * so Bachs stops retrying); a repeated success credits only once. When Bachs says how much
     * was paid, it must match what the payment was opened for.
     */
    public function handleEvent(string $type, ?string $reference, ?string $paidAmount = null): void
    {
        if (! $reference || ! str_starts_with($type, 'collection.')) {
            return;
        }

        DB::transaction(function () use ($type, $reference, $paidAmount) {
            $intent = PaymentIntent::where('reference', $reference)->lockForUpdate()->first();
            if (! $intent || $intent->status === 'succeeded') {
                return;
            }

            if ($type === 'collection.succeeded' && $paidAmount !== null && abs((float) $paidAmount - $intent->amount) > 0.005) {
                Log::warning('Bachs payment amount does not match the payment opened; not credited.', [
                    'reference' => $reference, 'expected' => $intent->amount, 'paid' => $paidAmount,
                ]);

                return;
            }

            if ($type === 'collection.succeeded') {
                $this->wallet->credit(User::findOrFail($intent->user_id), $intent->amount, 'funding', 'funding:'.$intent->reference);
                $intent->update(['status' => 'succeeded']);
            } elseif ($type === 'collection.failed') {
                $intent->update(['status' => 'failed']);
            }
        });
    }
}
