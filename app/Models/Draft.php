<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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

    /**
     * Until the host starts it a draft can be edited. After that its items,
     * participants and order are fixed. A draft that already has selections
     * counts as started, whatever its turn clock says.
     */
    public function hasStarted(): bool
    {
        return $this->turn_started_at !== null || $this->selections()->exists();
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
