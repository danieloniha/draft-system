<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Up Payout Tiers</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>

<div class="form-container">
    <h2 class="form-title">Set Up Payout Tiers</h2>

    <p>Define the payout for each rank. Every participant in a range is paid that range's amount each &mdash; it is not split between them.</p>

    <form method="POST" action="{{ route('store.payout-tiers', ['draft_id' => $draft->id]) }}" id="payout-tiers-form">
        @csrf

        @error('payout_budget')
            <p class="notice notice-error" role="alert">{{ $message }}</p>
        @enderror
        @error('rank_to')
            <p class="notice notice-error" role="alert">{{ $message }}</p>
        @enderror
        @error('amount')
            <p class="notice notice-error" role="alert">{{ $message }}</p>
        @enderror

        <div class="form-group">
            <label for="payout_budget">Total Amount to Give Away</label>
            <input type="number" id="payout_budget" name="payout_budget" min="1" value="{{ old('payout_budget', $draft->payout_budget) }}" required>
            <p class="hint" id="budget-progress">The tiers below can't add up to more than this.</p>
        </div>

        <div id="tiers-form">
            <div class="tier-row">
                <div class="tier-field">
                    <label>Rank from</label>
                    <input type="number" name="rank_from[]" min="1" value="1" required>
                </div>
                <div class="tier-field">
                    <label>Rank to</label>
                    <input type="number" name="rank_to[]" min="1" value="1" required>
                </div>
                <div class="tier-field">
                    <label>Amount (each)</label>
                    <input type="number" name="amount[]" min="1" required>
                </div>
                <button type="button" class="add-tier-row" title="Add another tier">+</button>
                <button type="button" class="remove-tier-row" title="Remove this tier">&times;</button>
            </div>
        </div>

        <div class="form-group">
            <button type="submit" class="btn" id="next-button">Save Payout Tiers</button>
        </div>
    </form>
</div>

<template id="tier-row-template">
    <div class="tier-row">
        <div class="tier-field">
            <label>Rank from</label>
            <input type="number" name="rank_from[]" min="1" required>
        </div>
        <div class="tier-field">
            <label>Rank to</label>
            <input type="number" name="rank_to[]" min="1" required>
        </div>
        <div class="tier-field">
            <label>Amount (each)</label>
            <input type="number" name="amount[]" min="1" required>
        </div>
        <button type="button" class="add-tier-row" title="Add another tier">+</button>
        <button type="button" class="remove-tier-row" title="Remove this tier">&times;</button>
    </div>
</template>

<script>
    const tiersForm = document.getElementById('tiers-form');
    const rowTemplate = document.getElementById('tier-row-template');
    const budgetInput = document.getElementById('payout_budget');
    const budgetProgress = document.getElementById('budget-progress');

    function attachRowHandlers(row) {
        row.querySelector('.remove-tier-row').addEventListener('click', function() {
            // Always leave at least one row, so the form never submits empty.
            if (tiersForm.querySelectorAll('.tier-row').length > 1) {
                row.remove();
                updateBudgetProgress();
            }
        });

        row.querySelector('.add-tier-row').addEventListener('click', function() {
            const newRow = rowTemplate.content.firstElementChild.cloneNode(true);
            row.after(newRow);
            attachRowHandlers(newRow);
        });

        row.querySelectorAll('input[name="rank_from[]"], input[name="rank_to[]"], input[name="amount[]"]')
            .forEach((input) => input.addEventListener('input', updateBudgetProgress));
    }

    // A live, client-side estimate only — the server is the real check. Each row pays its
    // amount to every rank in its range, not once total.
    function updateBudgetProgress() {
        const budget = parseInt(budgetInput.value, 10);
        let allocated = 0;

        tiersForm.querySelectorAll('.tier-row').forEach((row) => {
            const from = parseInt(row.querySelector('input[name="rank_from[]"]').value, 10);
            const to = parseInt(row.querySelector('input[name="rank_to[]"]').value, 10);
            const amount = parseInt(row.querySelector('input[name="amount[]"]').value, 10);
            if (Number.isFinite(from) && Number.isFinite(to) && to >= from && Number.isFinite(amount)) {
                allocated += (to - from + 1) * amount;
            }
        });

        if (!Number.isFinite(budget)) {
            budgetProgress.textContent = "The tiers below can't add up to more than this.";
            return;
        }

        budgetProgress.textContent = `Allocated so far: ${allocated.toLocaleString()} of ${budget.toLocaleString()}`;
        budgetProgress.classList.toggle('notice-error', allocated > budget);
    }

    tiersForm.querySelectorAll('.tier-row').forEach(attachRowHandlers);
    budgetInput.addEventListener('input', updateBudgetProgress);
    updateBudgetProgress();
</script>

</body>
</html>
