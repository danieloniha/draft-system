<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\DraftEditController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/view/draft', [DraftController::class, 'draftPage'])->name('view.draft');
    Route::post('/create/draft', [DraftController::class, 'createDraft'])->name('create.draft');

    Route::get('/draft/{draft_id}/interests/{no_of_interests}', [DraftController::class, 'showInterestForm'])->name('add.interests.form');
    Route::post('/draft/{draft_id}/interests', [DraftController::class, 'storeInterests'])->name('store.interests');

    Route::get('/join-draft', [DraftController::class, 'showJoinDraftForm'])->name('join.draft.form');
    Route::post('/join-draft', [DraftController::class, 'joinDraft'])->name('join.draft');

    Route::get('/draft/{draft_id}/details', [DraftController::class, 'showDraftDetails'])->name('draft.details');
    Route::post('/draft/{draft_id}/start', [DraftController::class, 'startDraft'])->name('start.draft');

    Route::get('/draft/{draft_id}/interests', [DraftController::class, 'showInterests'])->name('show.interests');
    Route::post('/draft/{draft_id}/interests', [DraftController::class, 'storeInterests'])->name('store.interests');
    Route::post('/draft/{draft_id}/interest', [DraftController::class, 'selectInterest'])->name('select.interest');
    Route::get('/draft/{draft_id}/state', [DraftController::class, 'showState'])->middleware('throttle:120,1')->name('draft.state');
});

Route::middleware('auth')->group(function () {
    Route::get('/draft/{draft_id}/teams/{no_of_teams}', [TeamController::class, 'showInviteForm'])->name('add.teams.form');
    Route::post('/draft/{draft_id}/teams', [TeamController::class, 'inviteTeams'])->name('invite.teams');
    Route::get('/draft/{draft_id}/invitations-sent', [TeamController::class, 'showInvitationsSent'])->name('invitations.sent');
    Route::get('/draft/{draft_id}/selection-order', [TeamController::class, 'showSelectionForm'])->name('show.selection.order');
    Route::post('/draft/{draft_id}/store-selection-order', [TeamController::class, 'storeSelectionOrder'])->name('store.selection.order');
    Route::get('/draft/{draft_id}/created', [TeamController::class, 'showDraftCreated'])->name('draft.created');

    // The host changing a draft before it starts.
    Route::get('/draft/{draft_id}/edit', [DraftEditController::class, 'edit'])->name('draft.edit');
    Route::patch('/draft/{draft_id}', [DraftEditController::class, 'update'])->name('draft.update');
    Route::patch('/draft/{draft_id}/items/{interest_id}', [DraftEditController::class, 'updateItem'])->name('draft.items.update');
    Route::delete('/draft/{draft_id}/items/{interest_id}', [DraftEditController::class, 'removeItem'])->name('draft.items.destroy');
    Route::delete('/draft/{draft_id}/participants/{team_id}', [DraftEditController::class, 'removeParticipant'])->name('draft.participants.destroy');
});

require __DIR__.'/auth.php';
