<?php

namespace Tests\Feature;

use App\Models\Interest;
use App\Models\Selection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

class TurnClockMigrationTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    public function test_drafts_that_already_have_picks_are_marked_as_started(): void
    {
        // Go back to the schema as it was before hosts had to start a draft: no turn clock at all.
        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

        // Legacy data, written the way the old code wrote it.
        [$running] = $this->buildDraft(2, 2);
        [$idle] = $this->buildDraft(2, 2);
        Selection::create([
            'interest_id' => Interest::where('draft_id', $running->id)->first()->id,
            'team_id' => Team::where('draft_id', $running->id)->first()->id,
            'draft_id' => $running->id,
            'selected' => 'Plot A',
            'is_selected' => true,
        ]);

        // Deploy: apply the new migrations to that data.
        Artisan::call('migrate', ['--force' => true]);

        $this->assertNotNull($running->fresh()->turn_started_at, 'a draft with picks keeps running');
        $this->assertNull($idle->fresh()->turn_started_at, 'a draft with no picks still waits for its host');
    }
}
