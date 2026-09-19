<?php

use App\Models\Draft;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Only the host and the participants of a draft may listen to it (see DraftPolicy::view).
Broadcast::channel('draft.{draftId}', function ($user, $draftId) {
    $draft = Draft::find($draftId);

    return $draft !== null && $user->can('view', $draft);
});
