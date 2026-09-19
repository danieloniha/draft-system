<!-- resources/views/draft_edit.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Session</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    @php
        // What would stop the host starting right now (the same rules as starting).
        $blockers = collect([
            $draft->interests->isEmpty() ? 'Add at least one item.' : null,
            $teams->isEmpty() ? 'Invite at least one participant.' : null,
            $teams->whereNull('user_id')->count() > 0 ? $teams->whereNull('user_id')->count().' invited participant(s) have not joined yet.' : null,
            $teams->whereNull('selection_no')->count() > 0 ? $teams->whereNull('selection_no')->count().' participant(s) still need a place in the selection order.' : null,
        ])->filter();
    @endphp

    <div class="draft-details-container">
        <h2 class="form-title">Edit Session</h2>
        <p>You can change anything here until you start the draft.
            <a href="{{ route('draft.details', ['draft_id' => $draft->id]) }}">Back to session details</a>, where you start it.</p>

        @if (session('status'))
            <p class="notice">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="notice notice-error" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif
        @if ($blockers->isNotEmpty())
            <div class="notice">
                <strong>Before you can start:</strong>
                @foreach ($blockers as $blocker)
                    <div>{{ $blocker }}</div>
                @endforeach
            </div>
        @endif

        <!-- Settings -->
        <section class="edit-section">
            <h3>Settings</h3>
            <form method="POST" action="{{ route('draft.update', ['draft_id' => $draft->id]) }}">
                @csrf
                @method('PATCH')

                <div class="form-group">
                    <label for="name">Session name</label>
                    <input type="text" id="name" name="name" value="{{ old('name', $draft->name) }}" maxlength="255" required>
                </div>

                <div class="form-group">
                    <label for="title">Description</label>
                    <input type="text" id="title" name="title" value="{{ old('title', $draft->title) }}" maxlength="255" required>
                </div>

                <div class="form-group">
                    <label for="timer">Turn timer (seconds)</label>
                    <input type="number" id="timer" name="timer" min="1" value="{{ old('timer', $draft->selection_time_limit) }}" required>
                </div>

                <div class="form-group">
                    <label for="start_date">Scheduled start (your local time)</label>
                    {{-- Shown in UTC without JavaScript. With it, converted to the viewer's own time and sent with their timezone. --}}
                    <input type="datetime-local" id="start_date" name="start_date"
                        value="{{ old('start_date', $draft->start_date->format('Y-m-d\TH:i')) }}"
                        data-utc="{{ $draft->start_date->toIso8601String() }}"
                        data-rejected="{{ old('start_date') !== null ? '1' : '0' }}" required>
                    <input type="hidden" id="timezone" name="timezone">
                </div>

                <div class="form-group">
                    <button type="submit" class="btn">Save settings</button>
                </div>
            </form>
        </section>

        <!-- Items -->
        <section class="edit-section">
            <h3>Items ({{ $draft->interests->count() }})</h3>

            @forelse ($draft->interests as $interest)
                <div class="edit-row">
                    <form method="POST" action="{{ route('draft.items.update', ['draft_id' => $draft->id, 'interest_id' => $interest->id]) }}"
                        enctype="multipart/form-data" class="edit-fields">
                        @csrf
                        @method('PATCH')
                        @if ($interest->image_path)
                            <img class="thumb" src="{{ asset('storage/' . $interest->image_path) }}" alt="{{ $interest->name }}">
                        @endif
                        <input type="text" name="name" value="{{ $interest->name }}" maxlength="255" aria-label="Item name" required>
                        <input type="file" name="image" accept="image/*" aria-label="New photo">
                        @if ($interest->image_path)
                            <label class="inline"><input type="checkbox" name="remove_image" value="1"> Remove photo</label>
                        @endif
                        <button type="submit" class="btn">Save</button>
                    </form>
                    <form method="POST" action="{{ route('draft.items.destroy', ['draft_id' => $draft->id, 'interest_id' => $interest->id]) }}"
                        onsubmit="return confirm('Remove this item?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-logout">Remove</button>
                    </form>
                </div>
            @empty
                <p class="pick-status">No items yet.</p>
            @endforelse

            <h4>Add an item</h4>
            <form method="POST" action="{{ route('store.interests', ['draft_id' => $draft->id]) }}" enctype="multipart/form-data" class="edit-fields">
                @csrf
                <input type="hidden" name="return_to" value="edit">
                <input type="text" name="items[]" placeholder="e.g. Vintage lamp" maxlength="255" aria-label="New item name" required>
                <input type="file" name="item_images[]" accept="image/*" aria-label="Photo (optional)">
                <button type="submit" class="btn">Add item</button>
            </form>
        </section>

        <!-- Participants -->
        <section class="edit-section">
            <h3>Participants ({{ $teams->count() }})</h3>

            @forelse ($teams as $team)
                <div class="edit-row">
                    <span class="edit-name">{{ $team->user?->username ?? $team->email }}</span>
                    <span class="pick-status">{{ $team->user ? 'Joined' : 'Invited, not joined yet' }}</span>
                    <form method="POST" action="{{ route('draft.participants.destroy', ['draft_id' => $draft->id, 'team_id' => $team->id]) }}"
                        onsubmit="return confirm('Remove this participant?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-logout">Remove</button>
                    </form>
                </div>
            @empty
                <p class="pick-status">Nobody yet.</p>
            @endforelse

            <h4>Invite someone</h4>
            <form method="POST" action="{{ route('invite.teams', ['draft_id' => $draft->id]) }}" class="edit-fields">
                @csrf
                <input type="hidden" name="return_to" value="edit">
                <input type="email" name="emails[]" placeholder="name@example.com" aria-label="Email address" required>
                <button type="submit" class="btn">Invite</button>
            </form>
            <p><a href="{{ route('invitations.sent', ['draft_id' => $draft->id]) }}">Invitation links</a> to share with them</p>
        </section>

        <!-- Selection order -->
        @if ($teams->isNotEmpty())
            <section class="edit-section">
                <h3>Selection order</h3>
                <form method="POST" action="{{ route('store.selection.order', ['draft_id' => $draft->id]) }}">
                    @csrf
                    <input type="hidden" name="return_to" value="edit">

                    @foreach ($teams as $team)
                        @php($current = (int) old('selection_numbers.'.$team->id, $team->selection_no ?? ($suggested[$team->id] ?? 0)))
                        <div class="edit-row">
                            <label for="selection_no_{{ $team->id }}">{{ $team->user?->username ?? $team->email }}</label>
                            <select name="selection_numbers[{{ $team->id }}]" id="selection_no_{{ $team->id }}" required>
                                @for ($i = 1; $i <= $teams->count(); $i++)
                                    <option value="{{ $i }}" @selected($current === $i)>{{ $i }}</option>
                                @endfor
                            </select>
                        </div>
                    @endforeach

                    <div class="form-group" style="margin-top: 18px">
                        <button type="submit" class="btn">Save order</button>
                    </div>
                </form>
            </section>
        @endif
    </div>

    <script>
        // Send the viewer's timezone with the form, and show the scheduled start in their own time
        // (unless this is a rejected submission, which already holds what they typed).
        document.getElementById('timezone').value = Intl.DateTimeFormat().resolvedOptions().timeZone;

        const start = document.getElementById('start_date');
        if (start.dataset.rejected === '0') {
            const d = new Date(start.dataset.utc);
            const pad = (n) => String(n).padStart(2, '0');
            start.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }
    </script>
</body>
</html>
