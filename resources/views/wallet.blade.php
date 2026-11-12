<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wallet</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    @include('partials.app-nav', ['back' => $setupDraft ? route('draft.details', ['draft_id' => $setupDraft->id]) : route('dashboard'), 'backLabel' => $setupDraft ? 'Session details' : 'Home'])

    <div class="container">
        <h1 class="user-greeting">Wallet</h1>

        @if (session('status'))
            <p class="notice" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="notice notice-error" role="alert">{{ $errors->first() }}</p>
        @endif

        <h2>Balance: ₦{{ number_format($user->balance) }}</h2>

        {{-- A money giveaway waiting on this wallet: say what it needs and what to do next. --}}
        @if ($setupDraft)
            @php($needed = (int) $setupDraft->payout_budget)
            @php($short = max(0, $needed - (int) $user->balance))
            <div class="notice" role="status">
                @if ($short > 0)
                    <p>Your giveaway needs ₦{{ number_format($needed) }} in your wallet. You are ₦{{ number_format($short) }} short.</p>
                    <form action="{{ route('wallet.fund') }}" method="POST" class="form-group">
                        @csrf
                        <input type="hidden" name="amount" value="{{ max($short, 100) }}">
                        <button type="submit" class="btn start-btn">Add ₦{{ number_format(max($short, 100)) }} with Bachs</button>
                    </form>
                    <p>Paid already? Reload this page in a few seconds and your balance will update.</p>
                @else
                    <p>Your wallet covers the ₦{{ number_format($needed) }} giveaway.</p>
                    <a class="btn start-btn" href="{{ route('draft.continue-setup', ['draft_id' => $setupDraft->id]) }}">Continue setting up your session</a>
                @endif
            </div>
        @endif

        @if ($user->isGuest())
            <p>Create an account to add money or withdraw. Your balance stays on your name for now and carries over when you sign up.</p>
            <a class="btn start-btn" href="{{ route('register') }}">Create an account</a>
        @else
            <h3>Add money</h3>
            <p>Hosts need this covered before they can reveal a cash giveaway.</p>
            <form action="{{ route('wallet.fund') }}" method="POST" class="form-group">
                @csrf
                <label for="amount">Amount (₦)</label>
                <input id="amount" type="number" name="amount" min="100" step="1" required>
                <button type="submit" class="btn start-btn">Pay with Bachs</button>
            </form>

            <h3>Withdraw</h3>
            @if ($destinations->isEmpty())
                <p>Add a bank account first.</p>
            @else
                <form action="{{ route('wallet.withdraw') }}" method="POST" class="form-group">
                    @csrf
                    <label for="destination_id">To</label>
                    <select id="destination_id" name="destination_id" required>
                        @foreach ($destinations as $destination)
                            <option value="{{ $destination->id }}">{{ $destination->bank_name ?? $destination->bank_code }} {{ $destination->maskedAccount() }} ({{ $destination->account_name }})</option>
                        @endforeach
                    </select>
                    <label for="withdraw-amount">Amount (₦)</label>
                    <input id="withdraw-amount" type="number" name="amount" min="100" max="{{ $user->balance }}" step="1" required>
                    <button type="submit" class="btn start-btn">Withdraw</button>
                </form>
            @endif

            <h3>Bank accounts</h3>
            @foreach ($destinations as $destination)
                <form action="{{ route('wallet.destinations.destroy', ['destination_id' => $destination->id]) }}" method="POST">
                    @csrf
                    @method('DELETE')
                    <span>{{ $destination->bank_name ?? $destination->bank_code }} {{ $destination->maskedAccount() }}</span>
                    <button type="submit" class="btn-outline">Remove</button>
                </form>
            @endforeach
            <form action="{{ route('wallet.destinations.store') }}" method="POST" class="form-group">
                @csrf
                <label for="account_name">Account name</label>
                <input id="account_name" type="text" name="account_name" required>
                <label for="account_number">Account number (10 digits)</label>
                <input id="account_number" type="text" name="account_number" inputmode="numeric" maxlength="10" required>
                @if (count($banks))
                    <label for="bank_code">Bank</label>
                    <select id="bank_code" name="bank_code" required>
                        <option value="" disabled @selected(! old('bank_code'))>Choose your bank</option>
                        @foreach ($banks as $bank)
                            <option value="{{ $bank['code'] }}" @selected(old('bank_code') === $bank['code'])>{{ $bank['name'] }}</option>
                        @endforeach
                    </select>
                @else
                    {{-- Bachs' bank list could not be loaded: fall back to typing the code. --}}
                    <label for="bank_code">Bank code</label>
                    <input id="bank_code" type="text" name="bank_code" value="{{ old('bank_code') }}" required>
                @endif
                <button type="submit" class="btn start-btn">Save bank account</button>
            </form>

            @if ($withdrawals->isNotEmpty())
                <h3>Recent withdrawals</h3>
                <ul>
                    @foreach ($withdrawals as $withdrawal)
                        <li>₦{{ number_format($withdrawal->amount) }} &middot; {{ $withdrawal->status }}{{ $withdrawal->failure_reason ? ' ('.$withdrawal->failure_reason.')' : '' }}</li>
                    @endforeach
                </ul>
            @endif
        @endif

        <h3>History</h3>
        @forelse ($transactions as $transaction)
            <p>{{ $transaction->created_at->format('Y-m-d H:i') }} &middot; {{ str_replace('_', ' ', $transaction->type) }} &middot; {{ $transaction->amount < 0 ? '-' : '+' }}₦{{ number_format(abs($transaction->amount)) }}</p>
        @empty
            <p>No activity yet.</p>
        @endforelse
    </div>
</body>
</html>
