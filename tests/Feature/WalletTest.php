<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\PayoutDestination;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Payments\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

/**
 * The money behind a cash giveaway: the host funds a wallet through Bachs, revealing a money
 * draft moves the budget into winners' wallets, and winners withdraw to a bank account. Bachs is
 * always faked.
 */
class WalletTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bachs.key' => 'sk_test',
            'services.bachs.base_url' => 'https://sandbox-api.bachs.io',
            'services.bachs.webhook_secret' => 'whsec_test',
        ]);
    }

    /**
     * A delivery signed the way Bachs signs it: HMAC-SHA256 of "{timestamp}.{raw body}".
     */
    private function webhook(array $payload, ?string $secret = 'whsec_test', ?int $timestamp = null)
    {
        $body = json_encode($payload);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? 'wrong');

        return $this->call('POST', route('webhooks.bachs'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BACHS_SIGNATURE_V2' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    /** Both a destination lookup (is_usable) and a payout response. */
    private function fakePayouts(array $payout = ['id' => 'pay_1', 'status' => 'processing'], int $status = 201): void
    {
        Http::fake([
            '*/v1/payouts/destinations/*' => Http::response(['id' => 'dst_1', 'is_usable' => true]),
            '*/v1/payouts' => Http::response($payout, $status),
        ]);
    }

    // ------------------------------------------------------------------ ledger

    public function test_wallet_credit_and_debit_keep_the_balance_and_ledger_in_step(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);

        $wallet->credit($user, 5000, 'funding', 'a');
        $wallet->debit($user, 1200, 'withdrawal', 'b');

        $this->assertSame(3800, (int) $user->fresh()->balance);
        $this->assertSame([5000, -1200], $user->walletTransactions()->orderBy('id')->pluck('amount')->all());
    }

    public function test_replaying_a_reference_changes_nothing(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);

        $wallet->credit($user, 5000, 'funding', 'same');
        $wallet->credit($user, 5000, 'funding', 'same');

        $this->assertSame(5000, (int) $user->fresh()->balance);
        $this->assertSame(1, $user->walletTransactions()->count());
    }

    public function test_a_balance_can_not_go_negative(): void
    {
        $user = User::factory()->create(['balance' => 100]);

        $this->expectException(ValidationException::class);

        app(WalletService::class)->debit($user, 101, 'withdrawal', 'x');
    }

    // ------------------------------------------------------------------ funding

    public function test_funding_opens_a_bachs_checkout_with_a_decimal_string_amount_and_redirects_to_it(): void
    {
        Http::fake(['*/v1/checkout-sessions' => Http::response(['checkout_id' => 'chk_1', 'checkout_url' => 'https://pay.bachs.io/chk_1'], 201)]);
        $host = User::factory()->create();

        $this->actingAs($host)->post(route('wallet.fund'), ['amount' => 5000])->assertRedirect('https://pay.bachs.io/chk_1');

        Http::assertSent(fn (Request $request) => $request['pricing'] === ['currency' => 'NGN', 'amount' => '5000.00']
            && $request->hasHeader('Authorization', 'Bearer sk_test'));
        $this->assertDatabaseHas('payment_intents', ['user_id' => $host->id, 'amount' => 5000, 'status' => 'pending']);
        $this->assertSame(0, (int) $host->fresh()->balance);
    }

    public function test_a_rejected_checkout_shows_bachs_reason_only_while_debugging(): void
    {
        Http::fake(['*/v1/checkout-sessions' => Http::response(['detail' => 'success_url must be a publicly accessible URL', 'error_code' => 'VALIDATION_ERROR'], 400)]);
        $host = User::factory()->create();

        config(['app.debug' => true]);
        $this->actingAs($host)->post(route('wallet.fund'), ['amount' => 5000])
            ->assertSessionHasErrors(['amount' => 'Could not start the payment. Please try again. [debug: success_url must be a publicly accessible URL]']);

        config(['app.debug' => false]);
        $this->actingAs($host)->post(route('wallet.fund'), ['amount' => 5000])
            ->assertSessionHasErrors(['amount' => 'Could not start the payment. Please try again.']);
    }

    public function test_a_guest_can_not_add_money(): void
    {
        $guest = User::createGuest('visitor');

        $this->actingAs($guest)->post(route('wallet.fund'), ['amount' => 5000])->assertRedirect(route('register'));
    }

    public function test_the_wallet_is_credited_by_the_webhook_exactly_once(): void
    {
        $host = User::factory()->create();
        $intent = PaymentIntent::create(['user_id' => $host->id, 'reference' => 'fund_1', 'amount' => 5000]);
        $event = ['type' => 'collection.succeeded', 'data' => ['reference' => 'fund_1']];

        $this->webhook($event)->assertNoContent();
        $this->webhook($event)->assertNoContent();

        $this->assertSame(5000, (int) $host->fresh()->balance);
        $this->assertSame('succeeded', $intent->fresh()->status);
    }

    public function test_a_failed_collection_credits_nothing(): void
    {
        $host = User::factory()->create();
        $intent = PaymentIntent::create(['user_id' => $host->id, 'reference' => 'fund_1', 'amount' => 5000]);

        $this->webhook(['type' => 'collection.failed', 'data' => ['reference' => 'fund_1']])->assertNoContent();

        $this->assertSame(0, (int) $host->fresh()->balance);
        $this->assertSame('failed', $intent->fresh()->status);
    }

    public function test_a_webhook_with_a_bad_signature_is_rejected(): void
    {
        $host = User::factory()->create();
        PaymentIntent::create(['user_id' => $host->id, 'reference' => 'fund_1', 'amount' => 5000]);

        $this->webhook(['type' => 'collection.succeeded', 'data' => ['reference' => 'fund_1']], null)->assertStatus(400);

        $this->assertSame(0, (int) $host->fresh()->balance);
    }

    public function test_a_validly_signed_but_stale_webhook_is_rejected(): void
    {
        $host = User::factory()->create();
        PaymentIntent::create(['user_id' => $host->id, 'reference' => 'fund_1', 'amount' => 5000]);

        $this->webhook(['type' => 'collection.succeeded', 'data' => ['reference' => 'fund_1']], 'whsec_test', time() - 3600)
            ->assertStatus(400);

        $this->assertSame(0, (int) $host->fresh()->balance);
    }

    public function test_the_v1_signature_may_be_any_of_several_during_a_secret_rotation(): void
    {
        $host = User::factory()->create();
        PaymentIntent::create(['user_id' => $host->id, 'reference' => 'fund_1', 'amount' => 5000]);
        $body = json_encode(['type' => 'collection.succeeded', 'data' => ['reference' => 'fund_1']]);
        $t = time();

        $this->call('POST', route('webhooks.bachs'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BACHS_SIGNATURE_V2' => "t={$t},v1=deadbeef,v1=".hash_hmac('sha256', $t.'.'.$body, 'whsec_test'),
        ], $body)->assertNoContent();

        $this->assertSame(5000, (int) $host->fresh()->balance);
    }

    public function test_the_older_signature_and_timestamp_headers_are_accepted_too(): void
    {
        $host = User::factory()->create();
        PaymentIntent::create(['user_id' => $host->id, 'reference' => 'fund_1', 'amount' => 5000]);
        $body = json_encode(['type' => 'collection.succeeded', 'data' => ['reference' => 'fund_1']]);
        $t = time();

        $this->call('POST', route('webhooks.bachs'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BACHS_TIMESTAMP' => (string) $t,
            'HTTP_X_BACHS_SIGNATURE' => hash_hmac('sha256', $t.'.'.$body, 'whsec_test'),
        ], $body)->assertNoContent();

        $this->assertSame(5000, (int) $host->fresh()->balance);
    }

    // ------------------------------------------------------------------ revealing a money draft

    public function test_revealing_pays_winners_and_refunds_the_unspent_budget(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(3, [
            ['rank_from' => 1, 'rank_to' => 1, 'amount' => 5000],
            ['rank_from' => 2, 'rank_to' => 2, 'amount' => 2000],
        ], ['payout_budget' => 10000]);
        $host = $draft->creator;
        $host->update(['balance' => 12000]);

        $this->actingAs($host)->post(route('start.draft', $draft->id))->assertSessionHasNoErrors();

        $this->assertSame(5000, (int) $players[0]->fresh()->balance);
        $this->assertSame(2000, (int) $players[1]->fresh()->balance);
        $this->assertSame(0, (int) $players[2]->fresh()->balance);
        // 12000 - 10000 held + 3000 not paid out.
        $this->assertSame(5000, (int) $host->fresh()->balance);
    }

    public function test_revealing_is_blocked_while_the_host_wallet_is_short(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(2, [['rank_from' => 1, 'rank_to' => 2, 'amount' => 5000]]);
        $host = $draft->creator;
        $host->update(['balance' => 9999]);

        $this->actingAs($host)->post(route('start.draft', $draft->id))->assertSessionHasErrors('start');

        $this->assertNull($draft->fresh()->turn_started_at);
        $this->assertSame(9999, (int) $host->fresh()->balance);
        $this->assertSame(0, (int) $players[0]->fresh()->balance);
    }

    public function test_revealing_twice_pays_only_once(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(2, [['rank_from' => 1, 'rank_to' => 2, 'amount' => 5000]]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));
        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));

        $this->assertSame(5000, (int) $players[0]->fresh()->balance);
        $this->assertSame(5000, (int) $players[1]->fresh()->balance);
        $this->assertSame(990000, (int) $draft->creator->fresh()->balance);
    }

    public function test_a_guest_winner_is_credited_and_keeps_the_balance_after_signing_up(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(1, [['rank_from' => 1, 'rank_to' => 1, 'amount' => 5000]]);
        $guest = $players[0];
        $guest->update(['is_guest' => true, 'email' => null]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));
        $this->assertSame(5000, (int) $guest->fresh()->balance);

        $this->actingAs($guest->fresh())->get(route('wallet.show'))->assertOk()->assertSee('5,000');
        $this->actingAs($guest->fresh())->post(route('wallet.withdraw'), ['amount' => 1000, 'destination_id' => 1])
            ->assertRedirect(route('register'));
    }

    // ------------------------------------------------------------------ withdrawing

    private function destinationFor(User $user): PayoutDestination
    {
        return $user->payoutDestinations()->create([
            'account_name' => 'Ada Obi', 'account_number' => '0123456789', 'bank_code' => '058', 'bachs_destination_id' => 'dst_1',
        ]);
    }

    public function test_saving_a_bank_account_creates_a_bachs_destination(): void
    {
        Http::fake([
            '*/v1/reference/banks*' => Http::response(['country' => 'NG', 'banks' => [['name' => 'Guaranty Trust Bank', 'code' => '058']]]),
            '*/v1/payouts/destinations' => Http::response(['id' => 'pd_9'], 201),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('wallet.destinations.store'), [
            'account_name' => 'Ada Obi', 'account_number' => '0123456789', 'bank_code' => '058',
        ])->assertSessionHasNoErrors();

        // The bank's name is looked up from its code, not typed by the user.
        $this->assertDatabaseHas('payout_destinations', [
            'user_id' => $user->id, 'bachs_destination_id' => 'pd_9', 'bank_name' => 'Guaranty Trust Bank',
        ]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['type'] === 'bank_account'
            && $request['currency'] === 'NGN'
            && $request['bank_code'] === '058'
            && $request['account_number'] === '0123456789');
    }

    public function test_the_wallet_page_offers_bachs_banks_in_a_dropdown_sorted_by_name(): void
    {
        Http::fake(['*/v1/reference/banks*' => Http::response(['country' => 'NG', 'banks' => [
            ['name' => 'Sterling Bank', 'code' => '232'],
            ['name' => 'Providus Bank', 'code' => '101'],
        ]])]);

        $this->actingAs(User::factory()->create())->get(route('wallet.show'))
            ->assertOk()
            ->assertSeeInOrder(['<option value="101"', 'Providus Bank', '<option value="232"', 'Sterling Bank'], false);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request['country'] === 'NG');
    }

    public function test_the_bank_list_is_fetched_once_then_served_from_cache(): void
    {
        Http::fake(['*/v1/reference/banks*' => Http::response(['country' => 'NG', 'banks' => [['name' => 'Sterling Bank', 'code' => '232']]])]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('wallet.show'))->assertOk();
        $this->actingAs($user)->get(route('wallet.show'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_the_wallet_page_falls_back_to_typing_the_code_when_bachs_banks_are_unavailable(): void
    {
        Http::fake(['*/v1/reference/banks*' => Http::response(['error' => 'down'], 500)]);

        $this->actingAs(User::factory()->create())->get(route('wallet.show'))
            ->assertOk()
            ->assertSee('<input id="bank_code"', false);
    }

    public function test_withdrawing_debits_first_and_sends_the_payout_with_an_idempotency_key(): void
    {
        $this->fakePayouts(['id' => 'pay_1', 'status' => 'processing']);
        $user = User::factory()->create(['balance' => 8000]);
        $destination = $this->destinationFor($user);

        $this->actingAs($user)->post(route('wallet.withdraw'), ['amount' => 3000, 'destination_id' => $destination->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(5000, (int) $user->fresh()->balance);
        $withdrawal = Withdrawal::firstOrFail();
        $this->assertSame('pay_1', $withdrawal->bachs_payout_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['amount'] === '3000.00'
            && $request['destination'] === 'dst_1'
            && $request['reference'] === 'wd_'.$withdrawal->id
            && $request->header('Idempotency-Key') === [$withdrawal->idempotency_key]);
    }

    public function test_a_destination_bachs_does_not_yet_consider_usable_blocks_the_withdrawal_before_any_debit(): void
    {
        Http::fake(['*/v1/payouts/destinations/*' => Http::response(['id' => 'dst_1', 'is_usable' => false])]);
        $user = User::factory()->create(['balance' => 8000]);
        $destination = $this->destinationFor($user);

        $this->actingAs($user)->post(route('wallet.withdraw'), ['amount' => 3000, 'destination_id' => $destination->id])
            ->assertSessionHasErrors('destination_id');

        $this->assertSame(8000, (int) $user->fresh()->balance);
        $this->assertSame(0, Withdrawal::count());
    }

    public function test_a_payout_that_bachs_rejects_gives_the_money_back(): void
    {
        $this->fakePayouts(['error' => 'nope'], 500);
        $user = User::factory()->create(['balance' => 8000]);
        $destination = $this->destinationFor($user);

        $this->actingAs($user)->post(route('wallet.withdraw'), ['amount' => 3000, 'destination_id' => $destination->id])
            ->assertSessionHasErrors('amount');

        $this->assertSame(8000, (int) $user->fresh()->balance);
        $this->assertSame('failed', Withdrawal::firstOrFail()->status);
    }

    public function test_a_payout_webhook_completes_or_reverses_the_withdrawal(): void
    {
        $this->fakePayouts(['id' => 'pay_1']);
        $user = User::factory()->create(['balance' => 8000]);
        $destination = $this->destinationFor($user);
        $this->actingAs($user)->post(route('wallet.withdraw'), ['amount' => 3000, 'destination_id' => $destination->id]);
        $withdrawal = Withdrawal::firstOrFail();

        $this->webhook(['type' => 'payout.failed', 'data' => ['reference' => 'wd_'.$withdrawal->id]])->assertNoContent();
        // A late "paid" for something already reversed must not flip it back.
        $this->webhook(['type' => 'payout.paid', 'data' => ['reference' => 'wd_'.$withdrawal->id]])->assertNoContent();

        $this->assertSame('failed', $withdrawal->fresh()->status);
        $this->assertSame(8000, (int) $user->fresh()->balance);
    }

    public function test_a_payout_paid_webhook_marks_it_completed(): void
    {
        $this->fakePayouts(['id' => 'pay_1']);
        $user = User::factory()->create(['balance' => 8000]);
        $destination = $this->destinationFor($user);
        $this->actingAs($user)->post(route('wallet.withdraw'), ['amount' => 3000, 'destination_id' => $destination->id]);
        $withdrawal = Withdrawal::firstOrFail();

        $this->webhook(['type' => 'payout.paid', 'data' => ['reference' => 'wd_'.$withdrawal->id]])->assertNoContent();

        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertSame(5000, (int) $user->fresh()->balance);
    }

    public function test_a_user_can_not_withdraw_more_than_their_balance_or_to_someone_elses_account(): void
    {
        Http::fake();
        $user = User::factory()->create(['balance' => 1000]);
        $mine = $this->destinationFor($user);
        $theirs = $this->destinationFor(User::factory()->create());

        $this->actingAs($user)->post(route('wallet.withdraw'), ['amount' => 2000, 'destination_id' => $mine->id])
            ->assertSessionHasErrors('amount');
        $this->actingAs($user)->post(route('wallet.withdraw'), ['amount' => 500, 'destination_id' => $theirs->id])
            ->assertSessionHasErrors('destination_id');

        Http::assertNothingSent();
        $this->assertSame(1000, (int) $user->fresh()->balance);
    }
}
