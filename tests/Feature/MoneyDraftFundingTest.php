<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

/**
 * A cash giveaway can't go further than its tier step until the host's wallet holds the budget,
 * and a payment Bachs has completed is credited even when its webhook never arrives.
 */
class MoneyDraftFundingTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.bachs.key' => 'sk_test', 'services.bachs.base_url' => 'https://sandbox-api.bachs.io']);
    }

    private function tierPayload(int $budget): array
    {
        return ['payout_budget' => $budget, 'rank_from' => [1], 'rank_to' => [2], 'amount' => [1000]];
    }

    // ------------------------------------------------------------------ the funding gate

    public function test_saving_the_tiers_sends_an_underfunded_host_to_their_wallet(): void
    {
        [$draft] = $this->buildMoneyDraft(0, [], ['visibility' => 'public']);
        $draft->creator->update(['balance' => 500]);

        $this->actingAs($draft->creator)
            ->post(route('store.payout-tiers', $draft->id), $this->tierPayload(5000))
            ->assertRedirect(route('wallet.show'));

        $this->actingAs($draft->creator)->get(route('wallet.show'))
            ->assertOk()
            ->assertSee('Your giveaway needs ₦5,000')
            ->assertSee('₦4,500 short')
            ->assertSee('Add ₦4,500 with Bachs')
            ->assertDontSee('Continue setting up')
            // One message, not two: no flash status stacked on the banner, and nothing styled as an error.
            ->assertDontSee('notice-error')
            ->assertSessionMissing('status');
    }

    public function test_a_funded_host_carries_straight_on_to_the_next_step(): void
    {
        [$draft] = $this->buildMoneyDraft(0, [], ['visibility' => 'public']);
        $draft->creator->update(['balance' => 5000]);

        $this->actingAs($draft->creator)
            ->post(route('store.payout-tiers', $draft->id), $this->tierPayload(5000))
            ->assertRedirect(route('draft.created', $draft->id));
    }

    public function test_continuing_is_refused_until_the_wallet_covers_the_budget(): void
    {
        [$draft] = $this->buildMoneyDraft(0, [], ['visibility' => 'public', 'payout_budget' => 5000]);
        $draft->creator->update(['balance' => 100]);

        $this->actingAs($draft->creator)->get(route('draft.continue-setup', $draft->id))
            ->assertRedirect(route('wallet.show'));

        $draft->creator->update(['balance' => 5000]);

        $this->actingAs($draft->creator)->get(route('draft.continue-setup', $draft->id))
            ->assertRedirect(route('draft.created', $draft->id));
    }

    public function test_only_the_host_can_continue_a_sessions_setup(): void
    {
        [$draft] = $this->buildMoneyDraft(0, [], ['payout_budget' => 5000]);

        $this->actingAs(User::factory()->create())->get(route('draft.continue-setup', $draft->id))->assertForbidden();
    }

    public function test_the_session_details_page_warns_a_short_host_and_links_to_funding(): void
    {
        [$draft] = $this->buildMoneyDraft(2, null, ['payout_budget' => 5000]);
        $draft->creator->update(['balance' => 1000]);

        $this->actingAs($draft->creator)->get(route('draft.details', $draft->id))
            ->assertOk()
            ->assertSee('₦4,000 short')
            ->assertSee(route('draft.continue-setup', $draft->id), false);
    }

    // ------------------------------------------------------------------ crediting without the webhook

    private function intent(User $user, int $amount = 12000): PaymentIntent
    {
        return PaymentIntent::create([
            'user_id' => $user->id, 'reference' => 'fund_1', 'amount' => $amount, 'bachs_checkout_id' => 'chk_1',
        ]);
    }

    private function fakeCheckout(string $status, string $chargeStatus, string $amount = '12000.00'): void
    {
        Http::fake(['*/v1/checkout-sessions/chk_1' => Http::response([
            'checkout_id' => 'chk_1', 'status' => $status, 'reference' => 'fund_1',
            'charge' => ['status' => $chargeStatus, 'amount' => $amount],
        ])]);
    }

    public function test_opening_the_wallet_credits_a_payment_bachs_completed_even_without_a_webhook(): void
    {
        $host = User::factory()->create();
        $intent = $this->intent($host);
        $this->fakeCheckout('completed', 'succeeded');

        $this->actingAs($host)->get(route('wallet.show'))->assertOk()->assertSee('Balance: ₦12,000');

        $this->assertSame(12000, (int) $host->fresh()->balance);
        $this->assertSame('succeeded', $intent->fresh()->status);

        // Opening it again changes nothing.
        $this->actingAs($host)->get(route('wallet.show'))->assertOk();
        $this->assertSame(12000, (int) $host->fresh()->balance);
        $this->assertSame(1, $host->walletTransactions()->count());
    }

    public function test_an_unpaid_checkout_credits_nothing(): void
    {
        $host = User::factory()->create();
        $this->intent($host);
        $this->fakeCheckout('open', 'pending');

        $this->actingAs($host)->get(route('wallet.show'))->assertOk();

        $this->assertSame(0, (int) $host->fresh()->balance);
    }

    public function test_a_payment_for_a_different_amount_is_not_credited(): void
    {
        $host = User::factory()->create();
        $this->intent($host, 12000);
        $this->fakeCheckout('completed', 'succeeded', '100.00');

        $this->actingAs($host)->get(route('wallet.show'))->assertOk();

        $this->assertSame(0, (int) $host->fresh()->balance);
    }

    public function test_the_wallet_still_loads_when_bachs_can_not_be_reached(): void
    {
        $host = User::factory()->create();
        $this->intent($host);
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        $this->actingAs($host)->get(route('wallet.show'))->assertOk();

        $this->assertSame(0, (int) $host->fresh()->balance);
    }

    // ------------------------------------------------------------------ return url

    public function test_a_tunnel_url_without_a_path_returns_to_the_wallet_page(): void
    {
        config(['services.bachs.return_url' => 'https://abc.ngrok-free.app']);
        $this->assertSame('https://abc.ngrok-free.app/wallet', app(\App\Services\Payments\FundingService::class)->returnUrl());

        config(['services.bachs.return_url' => 'https://abc.ngrok-free.app/wallet']);
        $this->assertSame('https://abc.ngrok-free.app/wallet', app(\App\Services\Payments\FundingService::class)->returnUrl());
    }

    // ------------------------------------------------------------------ winners are told

    public function test_the_results_say_who_has_had_their_prize_added_to_their_wallet(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(3, [['rank_from' => 1, 'rank_to' => 2, 'amount' => 700]]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));

        $results = collect($this->actingAs($players[0])->getJson(route('draft.state', $draft->id))->assertOk()->json('results'));

        $this->assertTrue($results[0]['credited']);
        $this->assertTrue($results[1]['credited']);
        // Rank 3 is paid nothing, so there is nothing to have been credited.
        $this->assertFalse($results[2]['credited']);
        $this->assertSame(700, (int) $players[0]->fresh()->balance);
    }

    public function test_the_results_page_has_a_wallet_button_for_winners(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(2);

        $this->actingAs($players[0])->get(route('show.interests', $draft->id))
            ->assertOk()
            ->assertSee('id="prize-banner"', false)
            ->assertSee('View my wallet')
            ->assertSee(route('wallet.show'), false);
    }

    public function test_nothing_is_marked_credited_before_the_draft_starts(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(2);

        $this->actingAs($players[0])->getJson(route('draft.state', $draft->id))
            ->assertOk()
            ->assertJsonPath('results', []);
    }

    // ------------------------------------------------------------------ the order prompt

    public function test_starting_asks_for_the_order_then_saves_it_and_starts_in_one_step(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(2, [['rank_from' => 1, 'rank_to' => 2, 'amount' => 500]], ['order_mode' => 'host_decided', 'visibility' => 'public']);
        $teams = $draft->teams()->orderBy('id')->get();
        $draft->teams()->update(['selection_no' => null]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))
            ->assertRedirect(route('show.selection.order', ['draft_id' => $draft->id, 'start' => 1]));
        $this->assertNull($draft->fresh()->turn_started_at);

        // The order page, reached from Start Draft, posts back to start rather than just saving.
        $this->actingAs($draft->creator)->get(route('show.selection.order', ['draft_id' => $draft->id, 'start' => 1]))
            ->assertOk()
            ->assertSee('Save order and start draft')
            ->assertSee('action="'.route('start.draft', $draft->id).'"', false);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id), [
            'selection_numbers' => [$teams[0]->id => 2, $teams[1]->id => 1],
        ])->assertRedirect(route('show.interests', $draft->id));

        $this->assertNotNull($draft->fresh()->turn_started_at);
        $this->assertSame([2, 1], $teams->map(fn ($team) => $team->fresh()->selection_no)->all());
        $this->assertSame(500, (int) $players[0]->fresh()->balance);
    }

    public function test_a_bad_order_keeps_the_draft_unstarted_and_comes_back_to_the_order_page(): void
    {
        [$draft] = $this->buildMoneyDraft(2, null, ['order_mode' => 'host_decided']);
        $teams = $draft->teams()->orderBy('id')->get();
        $draft->teams()->update(['selection_no' => null]);

        $this->actingAs($draft->creator)->from(route('show.selection.order', ['draft_id' => $draft->id, 'start' => 1]))
            ->post(route('start.draft', $draft->id), ['selection_numbers' => [$teams[0]->id => 1, $teams[1]->id => 1]])
            ->assertRedirect(route('show.selection.order', ['draft_id' => $draft->id, 'start' => 1]))
            ->assertSessionHasErrors('selection_numbers');

        $this->assertNull($draft->fresh()->turn_started_at);
    }

    public function test_someone_who_has_not_joined_is_reported_before_asking_for_the_order(): void
    {
        [$draft] = $this->buildMoneyDraft(2, null, ['order_mode' => 'host_decided']);
        $draft->teams()->update(['selection_no' => null]);
        $draft->teams()->first()->update(['user_id' => null]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))
            ->assertRedirect(route('draft.details', $draft->id))
            ->assertSessionHasErrors('start');
    }

    public function test_only_the_host_can_start_with_an_order(): void
    {
        [$draft] = $this->buildMoneyDraft(2, null, ['order_mode' => 'host_decided']);
        $draft->teams()->update(['selection_no' => null]);

        $this->actingAs(User::factory()->create())->post(route('start.draft', $draft->id), ['selection_numbers' => [1 => 1]])
            ->assertForbidden();
    }

    public function test_a_start_blocked_for_a_wallet_shortfall_does_not_point_to_the_order(): void
    {
        [$draft] = $this->buildMoneyDraft(2, [['rank_from' => 1, 'rank_to' => 2, 'amount' => 500]]);
        $draft->creator->update(['balance' => 0]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))->assertSessionHasErrors('start');

        $this->actingAs($draft->creator)->get(route('draft.details', $draft->id))
            ->assertSee('short')
            ->assertDontSee('Set the order');
    }

    public function test_no_order_prompt_once_everyone_has_a_number_or_the_order_is_automatic(): void
    {
        [$decided] = $this->buildMoneyDraft(2, null, ['order_mode' => 'host_decided']);
        [$automatic] = $this->buildMoneyDraft(2, null, ['order_mode' => 'fcfs']);
        $automatic->teams()->update(['selection_no' => null]);

        $this->actingAs($decided->creator)->get(route('draft.details', $decided->id))->assertDontSee('Set the order');
        $this->actingAs($automatic->creator)->get(route('draft.details', $automatic->id))->assertDontSee('Set the order');
    }

    public function test_the_session_created_page_prompts_a_private_host_to_set_the_order(): void
    {
        [$draft] = $this->buildMoneyDraft(2, null, ['order_mode' => 'host_decided', 'visibility' => 'private']);
        $draft->teams()->first()->update(['selection_no' => null]);

        $this->actingAs($draft->creator)->get(route('draft.created', $draft->id))
            ->assertOk()
            ->assertSee('Set the order now')
            ->assertSee(route('show.selection.order', $draft->id), false);
    }

    // ------------------------------------------------------------------ navigation and labels

    public function test_signed_in_pages_carry_home_and_wallet_links(): void
    {
        [$draft] = $this->buildMoneyDraft(2);

        foreach ([route('draft.details', $draft->id), route('view.draft'), route('wallet.show')] as $url) {
            $this->actingAs($draft->creator)->get($url)
                ->assertOk()
                ->assertSee('class="app-nav"', false)
                ->assertSee(route('dashboard'), false);
        }
    }

    public function test_the_live_page_links_back_to_the_session_details(): void
    {
        [$draft] = $this->buildMoneyDraft(2);

        $this->actingAs($draft->creator)->get(route('show.interests', $draft->id))
            ->assertOk()
            ->assertSee(route('draft.details', $draft->id), false)
            ->assertSee('Start Draft');
    }

    public function test_the_host_is_offered_the_live_page_only_after_the_draft_has_started(): void
    {
        [$draft] = $this->buildMoneyDraft(2);

        $this->actingAs($draft->creator)->get(route('draft.details', $draft->id))
            ->assertDontSee('Go to results page')
            ->assertDontSee('Watch the draft');

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));

        $this->actingAs($draft->creator)->get(route('draft.details', $draft->id))
            ->assertSee('Go to results page')
            ->assertDontSee('Watch the draft');
    }
}
