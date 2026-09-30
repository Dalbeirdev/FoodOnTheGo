<?php

namespace App\Models\Concerns;

/**
 * Eloquent writes a date using its wall-clock digits and ignores the zone it carries, so 09:30 Asia/Kolkata
 * would be stored as 09:30 UTC. Every model uses this trait: dates are converted to UTC before they are
 * written, and the database session is UTC, so the stored instant is always the intended one.
 */
trait StoresUtcTimestamps
{
    /**
     * @param  mixed  $value
     */
    public function fromDateTime($value): ?string
    {
        return empty($value) ? $value : $this->asDateTime($value)->copy()->utc()->format($this->getDateFormat());
    }
}
