<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Draft extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'start_date' => 'datetime',
        'turn_started_at' => 'datetime',
    ];

    public function creator() {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function interests() {
        return $this->hasMany(Interest::class);
    }

    public function selections() {
        return $this->hasMany(Selection::class);
    }

    public function skips() {
        return $this->hasMany(TurnSkip::class);
    }

    public function teams() {
        return $this->hasMany(Team::class);
    }

    public function bids() {
        return $this->hasMany(Bid::class);
    }

    public function payoutTiers() {
        return $this->hasMany(PayoutTier::class);
    }

    /**
     * Until the host starts it a draft can be edited. After that its items,
     * participants and order are fixed. A draft that already has selections or
     * bids counts as started, whatever its turn clock says.
     */
    public function hasStarted(): bool
    {
        return $this->turn_started_at !== null || $this->selections()->exists() || $this->bids()->exists();
    }

    /**
     * The timer a host typed as minutes and seconds, as total seconds. Falls back to a plain
     * 	imer (seconds) when neither part was sent, so older callers keep working.
     */
    public static function timerSecondsFrom(array $input): mixed
    {
        $hasParts = ($input['timer_minutes'] ?? '') !== '' || ($input['timer_seconds'] ?? '') !== '';
        if (! $hasParts) {
            return $input['timer'] ?? null;
        }

        $minutes = $input['timer_minutes'] ?? 0;
        $seconds = $input['timer_seconds'] ?? 0;
        if (! is_numeric($minutes) || ! is_numeric($seconds) || $minutes < 0 || $seconds < 0) {
            return null;
        }

        return (int) $minutes * 60 + (int) $seconds;
    }

    /**
     * The per-turn / per-item time as people read it: '2 min 30 sec', '45 sec'.
     */
    public function timerLabel(): string
    {
        $total = (int) $this->selection_time_limit;
        $minutes = intdiv($total, 60);
        $seconds = $total % 60;

        return trim(($minutes ? $minutes . ' min ' : '') . ($seconds || ! $minutes ? $seconds . ' sec' : ''));
    }

    public function isBidding(): bool
    {
        return $this->type === 'bidding';
    }

    /**
     * A Giveaway draft where the host defines who gets what money instead of
     * participants claiming items. Starting it is a one-time reveal, not the
     * beginning of a turn-based session.
     */
    public function isMoneyMode(): bool
    {
        return $this->type === 'giveaway' && $this->giveaway_mode === 'money';
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    /**
     * A public draft needs one stable link. Generated once, the first time it
     * becomes public, and kept after that so a link the host already shared
     * never dies just because they edit something else later.
     */
    public function ensurePublicToken(): void
    {
        if ($this->isPublic() && $this->public_token === null) {
            $this->update(['public_token' => Str::random(32)]);
        }
    }

    /**
     * Read a start time typed as a bare local time in the creator's timezone and
     * return it in the app's timezone, which is how it is stored.
     */
    public static function parseStartDate(string $value, ?string $timezone): Carbon
    {
        return Carbon::parse($value, $timezone ?? config('app.timezone'))
            ->setTimezone(config('app.timezone'));
    }
}
