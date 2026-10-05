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

// A guest (someone playing from a link without an account) is logged in too, so they pass
// `auth` everywhere below; `account` is what keeps them out of hosting and the profile page.
Route::middleware(['auth', 'account'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Choosing a template comes first, as its own step — Giveaway and Bidding then get
    // their own dedicated creation forms, not one shared form with a type dropdown.
    Route::get('/view/draft', [DraftController::class, 'chooseType'])->name('view.draft');
    Route::get('/create/draft/giveaway', [DraftController::class, 'giveawayForm'])->name('create.draft.giveaway.form');
    Route::get('/create/draft/bidding', [DraftController::class, 'biddingForm'])->name('create.draft.bidding.form');
    Route::post('/create/draft', [DraftController::class, 'createDraft'])->name('create.draft');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

// A private invitation needs an account (its token is tied to the invited email address), so
// someone opening the link is sent to log in or sign up and then carried on to it. A guest —
// someone who joined a public session with just a name — is turned away with a prompt to sign up.
Route::middleware(['auth', 'account:private'])->group(function () {
    Route::get('/join-draft', [DraftController::class, 'showJoinDraftForm'])->name('join.draft.form');
    Route::post('/join-draft', [DraftController::class, 'joinDraft'])->middleware('throttle:20,1')->name('join.draft');
});

// A public draft's link needs no account: someone who is not logged in just gives a name and
// plays as a guest. That POST creates an account row on the spot, so it is rate limited.
Route::get('/j/{public_token}', [DraftController::class, 'showPublicJoinForm'])->name('public.join.form');
Route::post('/j/{public_token}', [DraftController::class, 'joinPublicDraft'])->middleware('throttle:20,1')->name('public.join');

Route::middleware('auth')->group(function () {
    Route::get('/draft/{draft_id}/interests/{no_of_interests}', [DraftController::class, 'showInterestForm'])->name('add.interests.form');
    Route::post('/draft/{draft_id}/interests', [DraftController::class, 'storeInterests'])->name('store.interests');

    // Giveaway "money" mode only: the host defines a payout table instead of items.
    Route::get('/draft/{draft_id}/payout-tiers', [DraftController::class, 'showPayoutTiersForm'])->name('add.payout-tiers.form');
    Route::post('/draft/{draft_id}/payout-tiers', [DraftController::class, 'storePayoutTiers'])->name('store.payout-tiers');

    Route::get('/draft/{draft_id}/details', [DraftController::class, 'showDraftDetails'])->name('draft.details');
    Route::post('/draft/{draft_id}/start', [DraftController::class, 'startDraft'])->name('start.draft');

    Route::get('/draft/{draft_id}/interests', [DraftController::class, 'showInterests'])->name('show.interests');
    Route::post('/draft/{draft_id}/interests', [DraftController::class, 'storeInterests'])->name('store.interests');
    Route::post('/draft/{draft_id}/interest', [DraftController::class, 'selectInterest'])->name('select.interest');
    Route::post('/draft/{draft_id}/bid', [DraftController::class, 'placeBid'])->name('place.bid');
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
    // Giveaway "money" mode only: editing the payout table after creation.
    Route::patch('/draft/{draft_id}/payout-tiers/{tier_id}', [DraftEditController::class, 'updatePayoutTier'])->name('draft.payout-tiers.update');
    Route::delete('/draft/{draft_id}/payout-tiers/{tier_id}', [DraftEditController::class, 'removePayoutTier'])->name('draft.payout-tiers.destroy');
    Route::patch('/draft/{draft_id}/payout-budget', [DraftEditController::class, 'updatePayoutBudget'])->name('draft.payout-budget.update');
    Route::delete('/draft/{draft_id}/participants/{team_id}', [DraftEditController::class, 'removeParticipant'])->name('draft.participants.destroy');
    // Bidding only: the host arranging the auction sequence (Giveaway's participant
    // order equivalent is store.selection.order, above).
    Route::post('/draft/{draft_id}/item-order', [DraftEditController::class, 'updateItemOrder'])->name('draft.item-order.update');
});

require __DIR__.'/auth.php';
