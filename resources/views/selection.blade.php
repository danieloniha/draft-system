<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Item Selection</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
    @vite(['resources/js/app.js'])
</head>

<body>

    <div id="current-player" class="current-player">
        <h2 id="turn-message"></h2>
        @unless ($isParticipant)
            <p class="pick-status">You are watching as the host. Only participants can pick.</p>
        @endunless
        <p id="turn-timer" class="pick-status"></p>
        <p id="last-pick" class="pick-status"></p>
        <p id="pick-status" class="pick-status" role="status" aria-live="polite"></p>
    </div>

    <div class="container item-grid">
        @foreach ($interests as $interest)
            <div class="box item-card select-interest {{ in_array($interest->id, $state['selected_interest_ids']) ? 'blurred' : '' }}" id="interest-{{ $interest->id }}" data-id="{{ $interest->id }}"
                data-interest="{{ $interest->name }}">
                @if ($interest->image_path)
                    <img src="{{ asset('storage/' . $interest->image_path) }}" alt="{{ $interest->name }}">
                @endif
                <span>{{ $interest->name }}</span>
            </div>
        @endforeach
    </div>

    <div class="player-list">
        <h2>Player Order</h2>
        <ul id="player-list">
            @foreach ($state['players'] as $player)
                <li id="player-{{ $player['selection_no'] }}"
                    class="{{ $player['id'] === ($state['current_player']['id'] ?? null) ? 'current-turn' : '' }}"
                    data-player-id="{{ $player['id'] }}" data-selection-no="{{ $player['selection_no'] }}">
                    <strong>{{ $player['selection_no'] }}.</strong>
                    {{ $player['username'] }}
                    <span class="current-pick-indicator">Currently Picking</span>
                </li>
            @endforeach
        </ul>
    </div>


    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            const draftId = @json($draft->id);
            const myId = @json(auth()->id());
            const isParticipant = @json($isParticipant);
            const stateUrl = @json(route('draft.state', ['draft_id' => $draft->id]));
            const pickUrl = @json(route('select.interest', ['draft_id' => $draft->id]));
            const csrfToken = @json(csrf_token());

            // The server decides whose turn it is, when time runs out and which items are
            // taken. This page only shows that state, so every update, whether from our own
            // pick, a live event or a poll, goes through applyState().
            let state = @json($state);
            const layout = state.layout; // what this page was built from: the items and the players
            let pending = false;
            let clockOffset = 0; // server clock minus this browser's clock, in ms
            let lastClockCheck = 0;

            function syncClock() {
                clockOffset = Date.parse(state.server_time) - Date.now();
            }

            function serverNow() {
                return Date.now() + clockOffset;
            }

            function isMyTurn() {
                return state.status === 'in_progress' && state.current_player !== null && state.current_player.id === myId;
            }

            function setStatus(message) {
                $('#pick-status').text(message);
            }

            function formatRemaining(ms) {
                const total = Math.max(0, Math.ceil(ms / 1000));
                const hours = Math.floor(total / 3600);
                const minutes = Math.floor((total % 3600) / 60);
                const seconds = String(total % 60).padStart(2, '0');
                return hours > 0 ? hours + ':' + String(minutes).padStart(2, '0') + ':' + seconds : minutes + ':' + seconds;
            }

            function render() {
                if (state.status === 'complete') {
                    $('#turn-message').text('All items have been selected.');
                } else if (state.status === 'scheduled') {
                    $('#turn-message').text('Scheduled for ' + new Date(state.starts_at).toLocaleString() + '. Waiting for the host to start the draft.');
                } else if (state.status === 'waiting') {
                    $('#turn-message').text('Waiting for the host to start the draft.');
                } else if (isMyTurn()) {
                    $('#turn-message').text("It's your turn. Pick an item.");
                } else {
                    $('#turn-message').text('Waiting for ' + state.current_player.username + ' to select an item...');
                }

                if (state.last_skip) {
                    $('#last-pick').text(state.last_skip.player_username + ' ran out of time and was skipped.');
                } else if (state.last_pick) {
                    $('#last-pick').text(state.last_pick.player_username + ' picked ' + state.last_pick.interest_name + '.');
                } else {
                    $('#last-pick').text('');
                }

                $('#player-list li').each(function() {
                    const isCurrent = state.current_player !== null && $(this).data('player-id') === state.current_player.id;
                    $(this).toggleClass('current-turn', isCurrent);
                });

                const taken = new Set(state.selected_interest_ids);
                $('.select-interest').each(function() {
                    $(this).toggleClass('blurred', taken.has($(this).data('id')));
                });

                tick();
            }

            // Counts down against the server's clock. The server alone decides that time is
            // up, so at zero we only ask it to look (throttled) and show whatever it says.
            function tick() {
                let target = null;
                let label = '';
                if (state.status === 'in_progress') {
                    target = Date.parse(state.turn_ends_at);
                    label = 'Time left: ';
                } else if (state.status === 'scheduled') {
                    target = Date.parse(state.starts_at);
                    label = 'Scheduled start in: ';
                }

                if (target === null) {
                    $('#turn-timer').text('');
                    return;
                }

                const remaining = target - serverNow();
                if (remaining > 0) {
                    $('#turn-timer').text(label + formatRemaining(remaining));
                    return;
                }

                // At zero a scheduled draft becomes 'waiting' for the host, which the resync below shows.
                $('#turn-timer').text(state.status === 'scheduled' ? '' : "Time's up");
                if (Date.now() - lastClockCheck >= 1000) {
                    lastClockCheck = Date.now();
                    resync();
                }
            }

            function applyState(next) {
                // Events can arrive out of order; never go back to an older state.
                if (!next || next.version < state.version) {
                    return;
                }
                // The host edited the items or the players: rebuild this page from the server.
                if (next.layout !== layout) {
                    window.location.reload();
                    return;
                }
                state = next;
                syncClock();
                render();
            }

            function resync() {
                return $.getJSON(stateUrl).done(applyState);
            }

            $('.select-interest').on('click', function() {
                if (pending) {
                    return;
                }
                if (state.status === 'complete') {
                    return;
                }
                if (!isParticipant) {
                    setStatus("You're watching as the host. Only participants can pick.");
                    return;
                }
                if (state.status === 'scheduled' || state.status === 'waiting') {
                    setStatus("The host hasn't started the draft yet.");
                    return;
                }
                if (!isMyTurn()) {
                    setStatus('Wait for your turn.');
                    return;
                }

                pending = true;
                setStatus('');
                $.ajax({
                        url: pickUrl,
                        method: 'POST',
                        data: {
                            interest_id: $(this).data('id'),
                            _token: csrfToken
                        }
                    })
                    .done(function(response) {
                        applyState(response.state);
                    })
                    .fail(function(xhr) {
                        setStatus((xhr.responseJSON && xhr.responseJSON.message) || 'That selection could not be made.');
                        // Our view was out of date (someone picked first, or the turn moved on).
                        resync();
                    })
                    .always(function() {
                        pending = false;
                    });
            });

            syncClock();
            render();
            setInterval(tick, 250);

            // Live updates only count once this page is connected AND subscribed to the draft's
            // channel. Without Reverb, with Reverb down, or with the channel refused, we poll.
            function liveUpdatesWorking() {
                if (!window.Echo) {
                    return false;
                }
                const pusher = window.Echo.connector.pusher;
                const channel = pusher.channel('private-draft.' + draftId);
                return pusher.connection.state === 'connected' && !!channel && channel.subscribed;
            }

            if (window.Echo) {
                window.Echo.private('draft.' + draftId).listen('.state.changed', applyState);
                // Changes made while the connection was down are only recovered by asking.
                window.Echo.connector.pusher.connection.bind('connected', resync);
            }

            setInterval(function() {
                if (!document.hidden && !liveUpdatesWorking()) {
                    resync();
                }
            }, 3000);

            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) {
                    resync();
                }
            });
        });
    </script>


</body>

</html>
