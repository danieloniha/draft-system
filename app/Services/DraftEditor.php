<?php

namespace App\Services;

use App\Models\Draft;
use App\Models\Interest;
use App\Models\Team;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Everything the host can change about a draft before it starts: its settings,
 * its items, its participants and their order. The wizard that creates a draft
 * and the edit page both go through here, so the rules live in one place.
 *
 * Who may edit is decided by DraftPolicy::configure. Here every change also
 * takes the draft's lock and checks again that the draft has not started, so an
 * edit and the host pressing Start can never both apply.
 */
class DraftEditor
{
    public function __construct(private DraftPickService $picks)
    {
    }

    /**
     * @param  array<string, mixed>  $attributes  name, title, selection_time_limit and/or start_date
     */
    public function updateSettings(Draft $draft, array $attributes): void
    {
        $this->edit($draft, fn (Draft $draft) => $draft->update($attributes));
    }

    /**
     * @param  list<array{name: string, image: ?UploadedFile}>  $items
     */
    public function addItems(Draft $draft, array $items): void
    {
        $stored = [];

        try {
            $this->edit($draft, function (Draft $draft) use ($items, &$stored) {
                foreach ($items as $item) {
                    $path = ($item['image'] ?? null)?->store('items', 'public');
                    if ($path !== null) {
                        $stored[] = $path;
                    }

                    Interest::create(['draft_id' => $draft->id, 'name' => $item['name'], 'image_path' => $path]);
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
        });

        $this->deleteImage($path);
    }

    /**
     * Invite people by email. An invitation may go out before the person has an account.
     *
     * @param  list<string>  $emails
     */
    public function addParticipants(Draft $draft, array $emails): void
    {
        $this->edit($draft, function (Draft $draft) use ($emails) {
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
     * Run a change on the locked draft, then tell anyone already on the picking
     * page, whose board is now out of date.
     */
    private function edit(Draft $draft, Closure $change): void
    {
        DB::transaction(function () use ($draft, $change) {
            $locked = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            abort_if($locked->hasStarted(), 403, 'The draft has started, so it can no longer be changed.');

            $change($locked);
        });

        $this->picks->announce($draft);
    }

    private function deleteImage(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }
}
