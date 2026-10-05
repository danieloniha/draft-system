<?php

namespace App\Services;

use App\Models\Draft;
use App\Models\Interest;
use App\Models\PayoutTier;
use App\Models\Team;
use App\Services\Contracts\DraftFlowService;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Everything the host can change about a draft before it starts: its settings
 * (including its template, visibility, order mode and participant limit), its
 * items, its participants and their order. The wizard that creates a draft and
 * the edit page both go through here, so the rules live in one place.
 *
 * Who may edit is decided by DraftPolicy::configure. Here every change also
 * takes the draft's lock and checks again that the draft has not started, so an
 * edit and the host pressing Start can never both apply.
 */
class DraftEditor
{
    public function __construct(
        private DraftPickService $picks,
        private DraftBiddingService $bidding,
        private DraftPayoutService $payout,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes  name, title, selection_time_limit, start_date,
     *                                             type, visibility, order_mode and/or participant_limit
     */
    public function updateSettings(Draft $draft, array $attributes): void
    {
        $this->edit($draft, function (Draft $draft) use ($attributes) {
            if (isset($attributes['participant_limit']) && $attributes['participant_limit'] < $draft->teams()->count()) {
                throw ValidationException::withMessages([
                    'participant_limit' => 'The limit cannot be set below the number of participants already here.',
                ]);
            }

            $draft->update($attributes);
            $draft->ensurePublicToken();
        });
    }

    /**
     * @param  list<array{name: string, image: ?UploadedFile}>  $items
     */
    public function addItems(Draft $draft, array $items): void
    {
        $stored = [];

        try {
            $this->edit($draft, function (Draft $draft) use ($items, &$stored) {
                // Items default to add order — Bidding's 'fcfs' item order is exactly this,
                // free; 'host_decided' lets the host rearrange it afterward; 'random' shuffles
                // it once at start. Unused, but harmless, for Giveaway.
                $position = (int) ($draft->interests()->max('position') ?? 0);

                foreach ($items as $item) {
                    $path = ($item['image'] ?? null)?->store('items', 'public');
                    if ($path !== null) {
                        $stored[] = $path;
                    }

                    Interest::create([
                        'draft_id' => $draft->id,
                        'name' => $item['name'],
                        'image_path' => $path,
                        'position' => ++$position,
                    ]);
                }
            });
        } catch (Throwable $e) {
            // Nothing was saved, so do not leave the photos behind.
            array_map($this->deleteImage(...), $stored);

            throw $e;
        }
    }

    public function updateItem(Draft $draft, int $interestId, string $name, ?UploadedFile $image, bool $removeImage): void
    {
        $newPath = null;
        $replacedPath = null;

        try {
            $this->edit($draft, function (Draft $draft) use ($interestId, $name, $image, $removeImage, &$newPath, &$replacedPath) {
                // Only this draft's own items: another draft's id is a 404.
                $interest = $draft->interests()->whereKey($interestId)->firstOrFail();

                $newPath = $image?->store('items', 'public');
                if ($newPath !== null || $removeImage) {
                    $replacedPath = $interest->image_path;
                }

                $interest->update([
                    'name' => $name,
                    'image_path' => $newPath ?? ($removeImage ? null : $interest->image_path),
                ]);
            });
        } catch (Throwable $e) {
            $this->deleteImage($newPath);

            throw $e;
        }

        $this->deleteImage($replacedPath);
    }

    public function removeItem(Draft $draft, int $interestId): void
    {
        $path = null;

        $this->edit($draft, function (Draft $draft) use ($interestId, &$path) {
            $interest = $draft->interests()->whereKey($interestId)->firstOrFail();
            $path = $interest->image_path;
            $interest->delete();

            // Close the gap it leaves, so the auction sequence stays 1, 2, 3...
            $draft->interests()->whereNotNull('position')->orderBy('position')->orderBy('id')->get()
                ->each(fn (Interest $item, int $index) => $item->update(['position' => $index + 1]));
        });

        $this->deleteImage($path);
    }

    /**
     * @param  list<array{rank_from: int, rank_to: int, amount: int}>  $tiers
     * @param  ?int  $budget  When given, sets the draft's total payout budget at the same time
     *                        (the wizard's first submission does this; later additions from the
     *                        edit page leave the existing budget alone — see updatePayoutBudget).
     *
     * @throws ValidationException when a range is invalid, overlaps another tier, or the tiers
     *                              (together with any already there) would add up to more than
     *                              the draft's payout budget
     */
    public function addPayoutTiers(Draft $draft, array $tiers, ?int $budget = null): void
    {
        $this->edit($draft, function (Draft $draft) use ($tiers, $budget) {
            if ($budget !== null) {
                $draft->update(['payout_budget' => $budget]);
            }

            $existing = $draft->payoutTiers()->get(['id', 'rank_from', 'rank_to', 'amount']);
            $allocated = $this->allocatedTotal($existing);

            foreach ($tiers as $tier) {
                $this->assertValidTierRange($tier['rank_from'], $tier['rank_to'], $tier['amount']);
                $this->assertNoTierOverlap($existing, $tier['rank_from'], $tier['rank_to']);

                $allocated += $this->tierAllocation($tier['rank_from'], $tier['rank_to'], $tier['amount']);

                $created = $draft->payoutTiers()->create($tier);
                $existing->push($created);
            }

            $this->assertWithinBudget($draft, $allocated);
        });
    }

    public function updatePayoutTier(Draft $draft, int $tierId, int $rankFrom, int $rankTo, int $amount): void
    {
        $this->assertValidTierRange($rankFrom, $rankTo, $amount);

        $this->edit($draft, function (Draft $draft) use ($tierId, $rankFrom, $rankTo, $amount) {
            // Only this draft's own tier: another draft's id is a 404.
            $tier = $draft->payoutTiers()->whereKey($tierId)->firstOrFail();

            $others = $draft->payoutTiers()->where('id', '!=', $tierId)->get(['id', 'rank_from', 'rank_to', 'amount']);
            $this->assertNoTierOverlap($others, $rankFrom, $rankTo);

            $allocated = $this->allocatedTotal($others) + $this->tierAllocation($rankFrom, $rankTo, $amount);
            $this->assertWithinBudget($draft, $allocated);

            $tier->update(['rank_from' => $rankFrom, 'rank_to' => $rankTo, 'amount' => $amount]);
        });
    }

    public function removePayoutTier(Draft $draft, int $tierId): void
    {
        $this->edit($draft, function (Draft $draft) use ($tierId) {
            $draft->payoutTiers()->whereKey($tierId)->firstOrFail()->delete();
        });
    }

    /**
     * Change only the total budget, leaving the tiers as they are. Cannot be set below what the
     * existing tiers already add up to.
     *
     * @throws ValidationException when the budget is below the tiers' current total
     */
    public function updatePayoutBudget(Draft $draft, int $budget): void
    {
        $this->edit($draft, function (Draft $draft) use ($budget) {
            $allocated = $this->allocatedTotal($draft->payoutTiers()->get(['rank_from', 'rank_to', 'amount']));

            if ($allocated > $budget) {
                throw ValidationException::withMessages([
                    'payout_budget' => "The payout tiers already add up to {$allocated}, so the budget cannot be set below that.",
                ]);
            }

            $draft->update(['payout_budget' => $budget]);
        });
    }

    /**
     * Invite people by email. An invitation may go out before the person has an account.
     *
     * @param  list<string>  $emails
     */
    public function addParticipants(Draft $draft, array $emails): void
    {
        $this->edit($draft, function (Draft $draft) use ($emails) {
            // The limit applies to every way of joining — invited or self-joined — combined.
            if ($draft->teams()->count() + count($emails) > $draft->participant_limit) {
                throw ValidationException::withMessages([
                    'emails' => "That would bring this draft over its limit of {$draft->participant_limit} participants.",
                ]);
            }

            foreach ($emails as $email) {
                Team::create([
                    'user_id' => null,
                    'email' => $email,
                    'draft_id' => $draft->id,
                    'selection_no' => null,
                    'token' => Str::random(32),
                ]);
            }
        });
    }

    public function removeParticipant(Draft $draft, int $teamId): void
    {
        $this->edit($draft, function (Draft $draft) use ($teamId) {
            $draft->teams()->whereKey($teamId)->firstOrFail()->delete();

            // Close the gap they leave, so the order stays 1, 2, 3...
            $draft->teams()->whereNotNull('selection_no')->orderBy('selection_no')->orderBy('id')->get()
                ->each(fn (Team $team, int $position) => $team->update(['selection_no' => $position + 1]));
        });
    }

    /**
     * @param  array<int|string, int|string>  $selectionNumbers  selection number by team id
     *
     * @throws ValidationException when a number repeats or a participant is missing or foreign
     */
    public function setOrder(Draft $draft, array $selectionNumbers): void
    {
        // Compare as integers so "1" and "01" cannot slip through as different numbers.
        $numbers = array_map('intval', $selectionNumbers);

        if (count($numbers) !== count(array_unique($numbers))) {
            throw ValidationException::withMessages(['selection_numbers' => 'Selection numbers must be unique.']);
        }

        $this->edit($draft, function (Draft $draft) use ($numbers) {
            // Every participant of this draft, and nobody else, must be given a place.
            $draftTeamIds = $draft->teams()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $submittedTeamIds = collect(array_keys($numbers))->map(fn ($id) => (int) $id)->sort()->values()->all();

            if ($draftTeamIds !== $submittedTeamIds) {
                throw ValidationException::withMessages([
                    'selection_numbers' => 'Selection numbers must be provided for every participant in this draft.',
                ]);
            }

            foreach ($numbers as $teamId => $selectionNo) {
                $draft->teams()->whereKey($teamId)->update(['selection_no' => $selectionNo]);
            }
        });
    }

    /**
     * Bidding's equivalent of setOrder(): the host arranges the auction
     * sequence directly, item by item, rather than a participant order.
     *
     * @param  array<int|string, int|string>  $positions  auction position by item id
     *
     * @throws ValidationException when a number repeats or an item is missing or foreign
     */
    public function setItemOrder(Draft $draft, array $positions): void
    {
        $numbers = array_map('intval', $positions);

        if (count($numbers) !== count(array_unique($numbers))) {
            throw ValidationException::withMessages(['positions' => 'Item order numbers must be unique.']);
        }

        $this->edit($draft, function (Draft $draft) use ($numbers) {
            // Every item of this draft, and nobody else's, must be given a place.
            $draftItemIds = $draft->interests()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $submittedItemIds = collect(array_keys($numbers))->map(fn ($id) => (int) $id)->sort()->values()->all();

            if ($draftItemIds !== $submittedItemIds) {
                throw ValidationException::withMessages([
                    'positions' => 'An order must be provided for every item in this draft.',
                ]);
            }

            foreach ($numbers as $itemId => $position) {
                $draft->interests()->whereKey($itemId)->update(['position' => $position]);
            }
        });
    }

    /**
     * Run a change on the locked draft, then tell anyone already on the
     * picking/bidding page, whose board is now out of date.
     */
    private function edit(Draft $draft, Closure $change): void
    {
        DB::transaction(function () use ($draft, $change) {
            $locked = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            abort_if($locked->hasStarted(), 403, 'The draft has started, so it can no longer be changed.');

            $change($locked);
        });

        // Re-read: the edit may have just changed which flow service this draft uses (its type).
        $fresh = $draft->fresh();
        $this->flowService($fresh)->announce($fresh);
    }

    private function flowService(Draft $draft): DraftFlowService
    {
        return match (true) {
            $draft->isMoneyMode() => $this->payout,
            $draft->isBidding() => $this->bidding,
            default => $this->picks,
        };
    }

    private function deleteImage(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }

    private function assertValidTierRange(int $rankFrom, int $rankTo, int $amount): void
    {
        if ($rankFrom < 1 || $rankTo < $rankFrom) {
            throw ValidationException::withMessages([
                'rank_to' => 'The rank range must start at 1 or later, and end no earlier than it starts.',
            ]);
        }

        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'The payout amount must be at least 1.']);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PayoutTier>  $others
     */
    private function assertNoTierOverlap(Collection $others, int $rankFrom, int $rankTo): void
    {
        $overlaps = $others->contains(
            fn (PayoutTier $tier) => $rankFrom <= $tier->rank_to && $rankTo >= $tier->rank_from
        );

        if ($overlaps) {
            throw ValidationException::withMessages([
                'rank_to' => 'This rank range overlaps a tier that already exists.',
            ]);
        }
    }

    /**
     * What one tier pays out in total: its amount, paid to every rank in its range (not split
     * between them) — see PayoutTier.
     */
    private function tierAllocation(int $rankFrom, int $rankTo, int $amount): int
    {
        return ($rankTo - $rankFrom + 1) * $amount;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PayoutTier>  $tiers
     */
    private function allocatedTotal(Collection $tiers): int
    {
        return $tiers->sum(fn (PayoutTier $tier) => $this->tierAllocation($tier->rank_from, $tier->rank_to, $tier->amount));
    }

    /**
     * A draft with no budget set has nothing to check against (money mode always sets one at
     * creation; this is only a safety net for anything created another way).
     *
     * @throws ValidationException when the total exceeds the draft's payout budget
     */
    private function assertWithinBudget(Draft $draft, int $allocated): void
    {
        if ($draft->payout_budget === null || $allocated <= $draft->payout_budget) {
            return;
        }

        throw ValidationException::withMessages([
            'payout_budget' => "These payout tiers add up to {$allocated}, which is more than the {$draft->payout_budget} you set aside to give away.",
        ]);
    }
}
