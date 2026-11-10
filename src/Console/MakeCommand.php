<?php

namespace ErnestDefoe\WhoSaidIt\Console;

use ErnestDefoe\WhoSaidIt\Generator;
use ErnestDefoe\WhoSaidIt\Reminders;
use Illuminate\Console\Command;

/**
 * Makes today's quiz if it isn't made yet, and sends the reminders. Runs
 * every five minutes on the scheduler and is safe to repeat: after the
 * first run of the day it's one query.
 */
class MakeCommand extends Command
{
    protected $signature = 'who-said-it:make';

    protected $description = "Make today's Who Said It? quiz (if it isn't made yet) and send the reminders.";

    public function handle(Generator $generator, Reminders $reminders): int
    {
        $day = $generator->today();
        if (! $day) {
            $this->info('No quiz today: not enough eligible quotes yet.');

            return self::SUCCESS;
        }

        $reminders->queue($day);
        $this->info("Today's quiz ({$day->day}): {$day->question_count} quotes.");

        return self::SUCCESS;
    }
}
