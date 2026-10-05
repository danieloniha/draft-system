<?php

namespace Tests\Feature;

use App\Models\Interest;
use App\Models\Selection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

class TurnClockMigrationTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    public function test_drafts_that_already_have_picks_are_marked_as_started(): void
    {
        // Go back to the schema as it was before hosts had to start a draft: no turn clock at
        // all. Naming this one migration by path, rather than counting steps from the end of
        // the migrations directory, keeps the test correct as later migrations are added.
        //
        // This has to run before any data exists: dropping a column from `drafts` on SQLite
        // means recreating the table, and with foreign keys on, that cascades onto every row
        // in every table that references a draft (selections, teams, interests) even though
        // none of them are the column being changed.
        $migration = require database_path('migrations/2026_09_20_000000_add_turn_started_at_to_drafts_table.php');
        $migration->down();

        // Legacy data, written the way the old code wrote it (before turn_started_at existed).
        [$running] = $this->buildDraft(2, 2);
        [$idle] = $this->buildDraft(2, 2);
        Selection::create([
            'interest_id' => Interest::where('draft_id', $running->id)->first()->id,
            'team_id' => Team::where('draft_id', $running->id)->first()->id,
            'draft_id' => $running->id,
            'selected' => 'Plot A',
            'is_selected' => true,
        ]);

        // Deploy: apply the migration to that data.
        $migration->up();

        $this->assertNotNull($running->fresh()->turn_started_at, 'a draft with picks keeps running');
        $this->assertNull($idle->fresh()->turn_started_at, 'a draft with no picks still waits for its host');
    }
}
