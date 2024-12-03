<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Selection Numbers</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>

<body>

    <div class="form-container">
        <h2 class="form-title">Assign Selection Numbers</h2>

        <!-- Form to submit selection numbers -->
        <form method="POST" action="{{ route('store.selection.order', ['draft_id' => $draft_id]) }}">
            @csrf

            @php
                // Count how many teams there are (N)
                $teamCount = $teams->count();
            @endphp

            @foreach ($teams as $team)
                <div class="form-group">
                    <label for="selection_no_{{ $team->id }}">
                        Selection Number for {{ $team->user->username }}
                    </label>

                    <!-- Dropdown for selecting the number (1 to N) -->
                    <div class="form-group">
                        <select name="selection_numbers[{{ $team->id }}]" id="selection_no_{{ $team->id }}"
                            required>
                            <option value="" disabled selected>Select a number</option>
                            @for ($i = 1; $i <= $teamCount; $i++)
                                <option value="{{ $i }}">{{ $i }}</option>
                            @endfor
                        </select>
                    </div>
                </div>
            @endforeach

            <div class="form-group">
                <button type="submit" class="btn">Submit Selection Order</button>
            </div>
        </form>
    </div>

</body>

</html>
