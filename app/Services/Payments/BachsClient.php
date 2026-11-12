<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper over the Bachs REST API (https://docs.bachs.io). Everything the app knows about
 * the request/response shapes lives here, and amounts are converted between the app's whole
 * naira and Bachs' units in this class only.
 */
class BachsClient
{
    /**
     * Start a hosted checkout for an ad-hoc amount (no product needed).
     *
     * @return array{checkout_id: ?string, checkout_url: string}
     */
    public function createCheckout(string $reference, int $amount, string $email, string $name, string $returnUrl): array
    {
        $response = $this->request()->post('/v1/checkout-sessions', [
            'reference' => $reference,
            'pricing' => [
                'currency' => config('services.bachs.currency'),
                'amount' => $this->toBachs($amount),
            ],
            'customer' => ['email' => $email, 'name' => $name],
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'metadata' => ['reference' => $reference],
        ]);

        return [
            'checkout_id' => $response->json('checkout_id'),
            'checkout_url' => (string) $response->json('checkout_url'),
        ];
    }

    /**
     * A checkout session as Bachs sees it: `status` is "completed" once paid, and `charge`
     * holds the payment (`status` "succeeded", `amount` a decimal string).
     */
    public function checkout(string $checkoutId): array
    {
        return $this->request()->get("/v1/checkout-sessions/{$checkoutId}")->json() ?? [];
    }

    /**
     * The banks Bachs can pay out to, for the account form. The list barely changes, so it is
     * cached; if Bachs can't be reached it comes back empty (and is not cached), and the form
     * falls back to typing the code.
     *
     * @return list<array{name: string, code: string}>
     */
    public function banks(string $country = 'NG'): array
    {
        return Cache::remember("bachs.banks.{$country}", now()->addDay(), function () use ($country) {
            try {
                $banks = $this->request()->get('/v1/reference/banks', ['country' => $country])->json('banks', []);
            } catch (Throwable $e) {
                report($e);

                return null;
            }

            return collect($banks)
                ->map(fn ($bank) => ['name' => (string) $bank['name'], 'code' => (string) $bank['code']])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        }) ?? [];
    }

    /**
     * Save a bank account as a payout destination; returns Bachs' id for it (pd_...).
     */
    public function createDestination(string $accountNumber, string $bankCode): string
    {
        $response = $this->request()->post('/v1/payouts/destinations', [
            'type' => 'bank_account',
            'currency' => config('services.bachs.currency'),
            'bank_code' => $bankCode,
            'account_number' => $accountNumber,
        ]);

        return (string) $response->json('id');
    }

    /**
     * Bachs only pays to a destination once it reports it usable (it may need approval first).
     */
    public function destinationIsUsable(string $destinationId): bool
    {
        return (bool) $this->request()->get("/v1/payouts/destinations/{$destinationId}")->json('is_usable');
    }

    /**
     * @return array{id: ?string, status: ?string}
     */
    public function createPayout(string $destinationId, int $amount, string $reference, string $idempotencyKey, string $narration): array
    {
        $response = $this->request()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('/v1/payouts', [
            'destination' => $destinationId,
            'amount' => $this->toBachs($amount),
            'reference' => $reference,
            'narration' => $narration,
        ]);

        return ['id' => $response->json('id'), 'status' => $response->json('status')];
    }

    /**
     * Whole naira the app uses -> the decimal string Bachs expects ("5000.00"). Bachs never
     * uses minor units.
     */
    public function toBachs(int $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function request(): PendingRequest
    {
        $key = config('services.bachs.key');
        if (! $key) {
            throw new RuntimeException('Bachs is not configured: set BACHS_API_KEY.');
        }

        return Http::withToken($key)
            ->baseUrl(config('services.bachs.base_url'))
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->throw();
    }
}
