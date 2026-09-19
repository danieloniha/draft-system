<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sent to every participant whenever the draft moves on: it starts, a pick is
 * committed, or a turn is skipped because its timer ran out. The payload is the
 * full draft state (see DraftPickService::state), so a client that misses an
 * event only has to apply the next one.
 *
 * Broadcast immediately rather than queued: the next participant should not
 * wait on a queue worker to learn it is their turn.
 */
class DraftStateChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public array $state)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('draft.'.$this->state['draft_id']);
    }

    public function broadcastAs(): string
    {
        return 'state.changed';
    }

    public function broadcastWith(): array
    {
        return $this->state;
    }
}
