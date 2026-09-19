<?php

namespace App\Policies;

use App\Models\Draft;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DraftPolicy
{
    /**
     * The owner and the participants may see a draft. Nobody else.
     */
    public function view(User $user, Draft $draft): Response
    {
        if ($this->owns($user, $draft) || $draft->teams()->where('user_id', $user->id)->exists()) {
            return Response::allow();
        }

        return Response::deny('You are not part of this draft.');
    }

    /**
     * Only the owner may see how the draft was set up (participant emails and invitation links).
     */
    public function manage(User $user, Draft $draft): Response
    {
        return $this->owns($user, $draft)
            ? Response::allow()
            : Response::deny('Only the draft owner can do that.');
    }

    /**
     * Only the host (the owner) may start the draft. Nobody can select anything until they do.
     */
    public function start(User $user, Draft $draft): Response
    {
        return $this->owns($user, $draft)
            ? Response::allow()
            : Response::deny('Only the host can start this draft.');
    }

    /**
     * Only the owner may change who takes part, in what order, or what can be
     * selected, and only until the draft has started.
     */
    public function configure(User $user, Draft $draft): Response
    {
        if (! $this->owns($user, $draft)) {
            return Response::deny('Only the draft owner can change this draft.');
        }

        if ($draft->hasStarted()) {
            return Response::deny('The draft has started, so it can no longer be changed.');
        }

        return Response::allow();
    }

    private function owns(User $user, Draft $draft): bool
    {
        return $draft->user_id !== null && (int) $draft->user_id === (int) $user->id;
    }
}
