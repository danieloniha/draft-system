<?php

namespace App\Http\Controllers;

use App\Services\Payments\FundingService;
use App\Services\Payments\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BachsWebhookController extends Controller
{
    /** Bachs rejects deliveries more than this many seconds from now, and so do we (replays). */
    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request, FundingService $funding, WithdrawalService $withdrawals)
    {
        if (! $this->signatureIsValid($request)) {
            // Which headers arrived (never their values) is what you need to see a bad secret,
            // a missing endpoint secret or a stripped header at a glance.
            Log::warning('Bachs webhook rejected: invalid signature.', [
                'has_secret' => (bool) config('services.bachs.webhook_secret'),
                'has_v2' => $request->hasHeader('X-Bachs-Signature-V2'),
                'has_v1' => $request->hasHeader('X-Bachs-Signature'),
                'has_timestamp' => $request->hasHeader('X-Bachs-Timestamp'),
            ]);

            // 400, not 401: Bachs does not retry other 4xx responses, which is right for a forgery.
            abort(400, 'Invalid signature.');
        }

        $type = (string) $request->input('type', '');
        $reference = $request->input('data.reference');

        // Delivery is at-least-once and out of order is possible; both services only ever act on
        // a pending record, so a replayed or late event changes nothing. Anything that throws
        // here becomes a 500, which makes Bachs retry.
        $funding->handleEvent($type, $reference, $request->input('data.amount'));
        $withdrawals->handleEvent($type, $reference);

        return response()->noContent();
    }

    /**
     * Bachs signs `{timestamp}.{raw body}` with the endpoint's signing secret (HMAC-SHA256, hex).
     * It sends it as X-Bachs-Signature-V2 ("t=<timestamp>,v1=<hex>", possibly several v1= values
     * while a secret is being rotated), and as X-Bachs-Signature plus X-Bachs-Timestamp.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = config('services.bachs.webhook_secret');
        if (! $secret) {
            return false;
        }

        $timestamp = null;
        $candidates = [];

        if ($v2 = (string) $request->header('X-Bachs-Signature-V2', '')) {
            foreach (explode(',', $v2) as $part) {
                [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
                if ($key === 't') {
                    $timestamp = $value;
                } elseif ($key === 'v1') {
                    $candidates[] = $value;
                }
            }
        } else {
            $timestamp = (string) $request->header('X-Bachs-Timestamp', '');
            $candidates[] = (string) $request->header('X-Bachs-Signature', '');
        }

        if (! ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        // The raw body: re-encoded JSON would hash differently.
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
