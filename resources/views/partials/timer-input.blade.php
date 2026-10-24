{{-- Minutes and seconds for a turn/bid timer. Sent as timer_minutes + timer_seconds; the server adds them up. --}}
@php
    $total = $total ?? null;
    $minutesValue = old('timer_minutes', $total !== null ? intdiv((int) $total, 60) : '');
    $secondsValue = old('timer_seconds', $total !== null ? (int) $total % 60 : '');
@endphp
<div class="form-group" id="timer-group">
    <label for="timer_minutes">{{ $label }}</label>
    <div class="timer-parts">
        <label class="inline">
            <input type="number" id="timer_minutes" name="timer_minutes" min="0" max="1440" placeholder="0" value="{{ $minutesValue }}">
            min
        </label>
        <label class="inline">
            <input type="number" id="timer_seconds" name="timer_seconds" min="0" max="59" placeholder="30" value="{{ $secondsValue }}">
            sec
        </label>
    </div>
    <p class="hint">{{ $hint }} At least 1 second in total.</p>
    @error('timer')
        <p>{{ $message }}</p>
    @enderror
</div>
