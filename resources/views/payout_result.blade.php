<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Payout Results</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
    @vite(['resources/js/app.js'])
</head>

<body>

    <div id="current-player" class="current-player">
        <h2 id="status-message"></h2>
        @unless ($isParticipant)
            <p class="pick-status">You are watching as the host.</p>
        @endunless
        <p id="reveal-timer" class="pick-status"></p>
    </div>

    <div class="player-list">
        <h2 id="list-title">Participants</h2>
        <table id="results-table" class="results-table" style="display:none;">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Participant</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody id="results-body"></tbody>
        </table>
        <ul id="player-list"></ul>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            const draftId = @json($draft->id);
            const myId = @json(auth()->id());
            const stateUrl = @json(route('draft.state', ['draft_id' => $draft->id]));

            // The server alone decides when the results are revealed. This page only
            // shows the state it is given, whether from the initial load, a live event
            // or a poll — every update goes through applyState().
            let state = @json($state);
            const layout = state.layout; // what this page was built from: the tiers and the players
            let clockOffset = 0; // server clock minus this browser's clock, in ms
            let lastClockCheck = 0;

            function syncClock() {
                clockOffset = Date.parse(state.server_time) - Date.now();
            }

            function serverNow() {
                return Date.now() + clockOffset;
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
                    $('#status-message').text('Results are in!');
                } else if (state.status === 'scheduled') {
                    $('#status-message').text('Scheduled for ' + new Date(state.starts_at).toLocaleString() + '. Waiting for the host to reveal the results.');
                } else {
                    $('#status-message').text('Waiting for the host to reveal the results.');
                }

                if (state.status === 'complete') {
                    $('#list-title').text('Results');
                    $('#player-list').hide();
                    const $body = $('#results-body').empty();
                    state.results.forEach(function(row) {
                        const isMe = row.player_id === myId;
                        $('<tr>' +
                            '<td' + (isMe ? ' class="own-rank"' : '') + '>' + row.rank + '</td>' +
                            '<td' + (isMe ? ' class="own-rank"' : '') + '>' + $('<span>').text(row.player_username).html() + '</td>' +
                            '<td' + (isMe ? ' class="own-rank"' : '') + '>' + row.amount.toLocaleString() + '</td>' +
                            '</tr>').appendTo($body);
                    });
                    $('#results-table').show();
                } else {
                    $('#list-title').text('Participants');
                    $('#results-table').hide();
                    const $list = $('#player-list').empty().show();
                    state.players.forEach(function(player) {
                        $('<li>').text(player.username).appendTo($list);
                    });
                }

                tick();
            }

            // Counts down against the server's clock, only while a start time is still
            // ahead of us. Once the host reveals, the resync below picks that up.
            function tick() {
                if (state.status !== 'scheduled') {
                    $('#reveal-timer').text('');
                    return;
                }

                const remaining = Date.parse(state.starts_at) - serverNow();
                if (remaining > 0) {
                    $('#reveal-timer').text('Scheduled start in: ' + formatRemaining(remaining));
                    return;
                }

                $('#reveal-timer').text('');
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
                // The host edited the tiers or the players: rebuild this page from the server.
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
