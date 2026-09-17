<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Items</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>

<div class="form-container">
    <h2 class="form-title">Add Selectable Items</h2>
    
    <form method="POST" action="{{ route('store.interests', ['draft_id' => $draft_id]) }}" enctype="multipart/form-data">
        @csrf

        <p>Add a name for each item and, if useful, a photo. Photos work well for sale, auction, and allocation items.</p>
        <div id="interests-form">
            @for ($i = 1; $i <= $no_of_interests; $i++)
                <div class="form-group">
                    <label for="item_{{ $i }}">Item {{ $i }}</label>
                    <input type="text" id="item_{{ $i }}" name="items[]" value="{{ old('items.' . ($i - 1)) }}" placeholder="e.g. Vintage lamp" required>
                    <label for="item_image_{{ $i }}">Photo (optional)</label>
                    <input type="file" id="item_image_{{ $i }}" name="item_images[]" accept="image/*">
                    @error('items.' . ($i - 1))
                        <p>{{ $message }}</p>
                    @enderror
                    @error('item_images.' . ($i - 1))
                        <p>{{ $message }}</p>
                    @enderror
                </div>
            @endfor
        </div>

        <div class="form-group">
            <button type="submit" class="btn">Save Items</button>
        </div>
    </form>
</div>

</body>
</html>
