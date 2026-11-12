<?php

namespace App\Http\Controllers;

use App\Http\Requests\FundWalletRequest;
use App\Http\Requests\StorePayoutDestinationRequest;
use App\Http\Requests\WithdrawRequest;
use App\Models\Draft;
use App\Models\PayoutDestination;
use App\Services\Payments\BachsClient;
use App\Services\Payments\FundingService;
use App\Services\Payments\WithdrawalService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Throwable;

class WalletController extends Controller
{
    public function __construct(
        private FundingService $funding,
        private WithdrawalService $withdrawals,
        private BachsClient $bachs,
    ) {
    }

    public function show(Request $request)
    {
        $user = $request->user();

        // Coming back from Bachs: credit any payment it has completed even if its webhook hasn't
        // reached us yet, so the balance is right the moment the page loads.
        $this->funding->reconcile($user);
        $user->refresh();

        // A money giveaway being set up that is waiting on this wallet to be funded.
        $setupDraft = Draft::where('user_id', $user->id)
            ->whereKey(session('setup_draft'))
            ->whereNull('turn_started_at')
            ->first();

        return view('wallet', [
            'user' => $user,
            'setupDraft' => $setupDraft?->isMoneyMode() ? $setupDraft : null,
            'transactions' => $user->walletTransactions()->latest('id')->take(50)->get(),
            'destinations' => $user->payoutDestinations()->get(),
            // Guests can't save accounts, so there is no reason to ask Bachs for the list.
            'banks' => $user->isGuest() ? [] : $this->bachs->banks(),
            'withdrawals' => $user->withdrawals()->latest('id')->take(10)->get(),
        ]);
    }

    public function fund(FundWalletRequest $request)
    {
        try {
            $intent = $this->funding->start($request->user(), (int) $request->validated('amount'));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['amount' => $this->failureMessage($e, 'Could not start the payment. Please try again.')]);
        }

        return redirect()->away($intent->checkout_url);
    }

    public function storeDestination(StorePayoutDestinationRequest $request)
    {
        $data = $request->validated();

        try {
            $this->withdrawals->addDestination($request->user(), $data['account_name'], $data['account_number'], $data['bank_code']);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['account_number' => $this->failureMessage($e, 'Could not save this bank account. Check the details and try again.')]);
        }

        return back()->with('status', 'Bank account saved.');
    }

    /**
     * Users get the friendly message; while debugging, the real reason (Bachs' own "detail" for
     * a rejected request, otherwise the exception message) so a failure isn't a guessing game.
     */
    private function failureMessage(Throwable $e, string $friendly): string
    {
        if (! config('app.debug')) {
            return $friendly;
        }

        $detail = $e instanceof RequestException
            ? ($e->response->json('detail') ?? $e->response->body())
            : $e->getMessage();

        return $friendly.' [debug: '.$detail.']';
    }

    public function destroyDestination(Request $request, int $destination_id)
    {
        $request->user()->payoutDestinations()->whereKey($destination_id)->delete();

        return back()->with('status', 'Bank account removed.');
    }

    public function withdraw(WithdrawRequest $request)
    {
        $destination = PayoutDestination::where('user_id', $request->user()->id)->findOrFail($request->validated('destination_id'));

        $withdrawal = $this->withdrawals->withdraw($request->user(), $destination, (int) $request->validated('amount'));

        return $withdrawal->status === 'failed'
            ? back()->withErrors(['amount' => 'The withdrawal could not be started, so your balance was not changed.'])
            : back()->with('status', 'Withdrawal started. It will complete shortly.');
    }
}
