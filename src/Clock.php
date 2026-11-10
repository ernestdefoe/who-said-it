<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;

/** Quiz days, in the time zone the admin chose (UTC by default). */
class Clock
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function today(): string
    {
        return Carbon::now($this->settings->timezone())->toDateString();
    }

    public function yesterday(): string
    {
        return Carbon::now($this->settings->timezone())->subDay()->toDateString();
    }

    /** When the next quiz day starts, in UTC. */
    public function nextAt(): Carbon
    {
        return Carbon::now($this->settings->timezone())->addDay()->startOfDay()->utc();
    }
}
