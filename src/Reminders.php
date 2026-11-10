<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;
use ErnestDefoe\WhoSaidIt\Job\SendReminders;
use Illuminate\Contracts\Queue\Queue;

/** The "today's quiz is up" alert, once per day, to members who asked for it. */
class Reminders
{
    public function __construct(private readonly Queue $queue)
    {
    }

    public function queue(Day $day): void
    {
        // One guarded update: whoever flips reminded_at sends; nobody else.
        $claimed = Day::query()->whereKey($day->id)->whereNull('reminded_at')->update(['reminded_at' => Carbon::now()]);
        if ($claimed) {
            $this->queue->push(new SendReminders($day->id));
        }
    }
}
