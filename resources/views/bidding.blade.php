<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Bidding</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
    @vite(['resources/js/app.js'])
</head>

<body>

    <div id="current-player" class="current-player">
        <h2 id="turn-message"></h2>
        @unless ($isParticipant)
            <p class="pick-status">You are watching as the host. Only participants can bid.</p>
        @endunless
        <p id="turn-timer" class="pick-status"></p>
        <p id="last-pick" class="pick-status"></p>
        <p id="pick-status" class="pick-status" role="status" aria-live="polite"></p>
    </div>

    <div class="container">
        <div id="current-item-box" class="current-item-box box item-card">
            <img id="current-item-image" src="" alt="" style="display:none">
            <span id="current-item-name"></span>
        </div>

        <div id="bid-box" class="bid-box form-group">
            <label for="bid-amount">Your bid</label>
            <input type="number" id="bid-amount" min="1" step="1">
            <button type="button" class="btn" id="bid-button">Place bid</button>
        </div>
    </div>

    <div class="player-list">
        <h2>Items</h2>
        <ul id="item-list">
            @foreach ($interests as $interest)
                <li id="item-{{ $interest->id }}" data-item-id="{{ $interest->id }}">
                    {{ $interest->name }}
                    <span class="current-pick-indicator"></span>
                </li>
            @endforeach
        </ul>
    </div>


    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            const draftId = @json($draft->id);
            const isParticipant = @json($isParticipant);
            const stateUrl = @json(route('draft.state', ['draft_id' => $draft->id]));
            const bidUrl = @json(route('place.bid', ['draft_id' => $draft->id]));
            const csrfToken = @json(csrf_token());

            // The server decides which item is open, the current leading bid and when time
            // runs out. This page only shows that state, so every update, whether from our own
            // bid, a live event or a poll, goes through applyState().
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
                    $('#turn-message').text('The auction is over.');
                } else if (state.status === 'scheduled') {
                    $('#turn-message').text('Scheduled for ' + new Date(state.starts_at).toLocaleString() + '. Waiting for the host to start the draft.');
                } else if (state.status === 'waiting') {
                    $('#turn-message').text('Waiting for the host to start the draft.');
                } else if (state.current_item) {
                    $('#turn-message').text('"' + state.current_item.name + '" is open for bidding.');
                }

                if (state.current_item) {
                    $('#current-item-name').text(state.current_item.name);
                    if (state.current_item.image_path) {
                        $('#current-item-image').attr('src', '/storage/' + state.current_item.image_path).show();
                    } else {
                        $('#current-item-image').hide();
                    }
                    $('#current-item-box').show();
                } else {
                    $('#current-item-box').hide();
                }

                if (state.current_bid) {
                    $('#last-pick').text('Current bid: ' + state.current_bid.amount + ' by ' + state.current_bid.player_username);
                } else if (state.last_closed) {
                    $('#last-pick').text(state.last_closed.sold
                        ? state.last_closed.interest_name + ' sold to ' + state.last_closed.winning_username + ' for ' + state.last_closed.winning_amount + '.'
                        : state.last_closed.interest_name + ' went unsold.');
                } else if (state.current_item) {
                    $('#last-pick').text('No bids yet.');
                } else {
                    $('#last-pick').text('');
                }

                $('#bid-box').toggle(state.status === 'in_progress' && isParticipant && !!state.current_item);
                $('#bid-amount').attr('min', state.current_bid ? state.current_bid.amount + 1 : 1);

                $('#item-list li').each(function() {
                    const id = $(this).data('item-id');
                    const item = state.items.find((i) => i.id === id);
                    $(this).removeClass('current-turn blurred');
                    if (!item) {
                        return;
                    }

                    let label = '';
                    if (item.status === 'open') {
                        $(this).addClass('current-turn');
                        label = 'Open now';
                    } else if (item.status === 'sold') {
                        $(this).addClass('blurred');
                        label = 'Sold to ' + item.winning_username + ' for ' + item.winning_amount;
                    } else if (item.status === 'unsold') {
                        $(this).addClass('blurred');
                        label = 'Unsold';
                    }
                    $(this).find('.current-pick-indicator').text(label);
                });

                tick();
            }

            // Counts down against the server's clock. The server alone decides that time is
            // up, so at zero we only ask it to look (throttled) and show whatever it says.
            function tick() {
                let target = null;
                let label = '';
                if (state.status === 'in_progress') {
                    target = Date.parse(state.item_ends_at);
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

            $('#bid-button').on('click', function() {
                if (pending) {
                    return;
                }
                if (!isParticipant) {
                    setStatus("You're watching as the host. Only participants can bid.");
                    return;
                }
                if (state.status !== 'in_progress' || !state.current_item) {
                    setStatus('Nothing is open for bidding right now.');
                    return;
                }
                const amount = parseInt($('#bid-amount').val(), 10);
                if (!amount || amount < 1) {
                    setStatus('Enter a bid amount.');
                    return;
                }

                pending = true;
                setStatus('');
                $.ajax({
                        url: bidUrl,
                        method: 'POST',
                        data: {
                            interest_id: state.current_item.id,
                            amount: amount,
                            _token: csrfToken
                        }
                    })
                    .done(function(response) {
                        applyState(response.state);
                        $('#bid-amount').val('');
                    })
                    .fail(function(xhr) {
                        setStatus((xhr.responseJSON && xhr.responseJSON.message) || 'That bid could not be placed.');
                        // Our view was out of date (someone else bid first, or the item moved on).
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
