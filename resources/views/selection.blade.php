<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map Pick</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>

<body>

    <div id="current-player" class="current-player">
        <h2>Waiting for <span id="player-name">Player 1</span> to select an interest...</h2>
    </div>

    <div class="container">
        @foreach ($interests as $interest)
            <div class="box select-interest" id="interest-{{ $interest->id }}" data-id="{{ $interest->id }}"
                data-interest="{{ $interest->name }}">
                {{ $interest->name }}
            </div>
        @endforeach
    </div>

    <div class="player-list">
        <h2>Player Order</h2>
        <ul id="player-list">
            @foreach ($players as $player)
                <li id="player-{{ $player['selection_no'] }}" class="{{ $loop->first ? 'current-turn' : '' }}"
                    data-player-id="{{ $player['id'] }}" data-selection-no="{{ $player['selection_no'] }}">

                    <!-- Only display the following on the screen -->
                    <strong>{{ $player['selection_no'] }}.</strong>
                    {{ $player['username'] }}
                    <span class="current-pick-indicator">Currently Picking</span>
                </li>
            @endforeach
        </ul>
    </div>


    <!-- JavaScript for dynamic updates -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            let players = @json($players); // Assuming you passed the players from the server-side

            let currentSelectionNo = 1; // Initialize with the first player’s selection_no
            let totalPlayers = {{ $totalPlayers }}; // Total number of players

            // Update the player display on page load
            updateCurrentPlayerDisplay(currentSelectionNo);

            // Handle card (interest) selection
            $('.select-interest').on('click', function() {
                let interestId = $(this).data('id');

                // Check if it's the current player's turn
                if (currentSelectionNo == getCurrentSelectionNo()) {
                    // Get the actual player_id corresponding to the currentSelectionNo
                    let currentPlayer = players.find(player => player.selection_no == currentSelectionNo);
                    if (!currentPlayer) {
                        alert("Error: Could not find the current player.");
                        return;
                    }

                    // Send the AJAX request with the correct player_id from the database
                    $.ajax({
                        url: "{{ route('select.interest', ['draft_id' => $draft->id]) }}",
                        method: "POST",
                        data: {
                            interest_id: interestId,
                            player_id: currentPlayer.id, // Use the player_id from the DB
                            _token: "{{ csrf_token() }}"
                        },
                        success: function(response) {
                            if (response.success) {
                                // Update the board visually (blur the picked card)
                                $(`#interest-${interestId}`).addClass('blurred');

                                // Move to the next player
                                updatePlayerTurn();
                            } else {
                                alert("Error: " + response.message);
                            }
                        }
                    });
                } else {
                    alert("Wait for your turn.");
                }
            });

            // Function to get the current player's selection number
            function getCurrentSelectionNo() {
                return currentSelectionNo;
            }

            // Update the player's turn (cycle through players)
            function updatePlayerTurn() {
                currentSelectionNo++;
                if (currentSelectionNo > totalPlayers) {
                    currentSelectionNo = 1;
                }
                $('#player-list li').removeClass('current-turn');
                $('.current-pick-indicator').hide();

                // Get the current player's list item by selection number
                let currentPlayerItem = $(`#player-${currentSelectionNo}`);

                // Add 'current-turn' class and show the indicator for the current player
                currentPlayerItem.addClass('current-turn');
                currentPlayerItem.find('.current-pick-indicator').show();

                updateCurrentPlayerDisplay(currentSelectionNo);
            }

            // Function to update the player name in the UI
            function updateCurrentPlayerDisplay(currentSelectionNo) {
                let currentPlayer = players.find(player => player.selection_no == currentSelectionNo);
                if (currentPlayer) {
                    $('#player-name').text(currentPlayer.username); // Update the displayed player's name
                }
            }
        });
    </script>


</body>

</html>
