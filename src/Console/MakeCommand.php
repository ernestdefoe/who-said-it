<?php

namespace ErnestDefoe\WhoSaidIt\Console;

use ErnestDefoe\WhoSaidIt\Generator;
use ErnestDefoe\WhoSaidIt\Quiz;
use ErnestDefoe\WhoSaidIt\Reminders;
use Illuminate\Console\Command;

/**
 * Makes today's quiz if it isn't made yet, sends the reminders, and finishes
 * members' unfinished past days (Quiz::settle). Runs
 * every five minutes on the scheduler and is safe to repeat: after the
 * first run of the day it's one query.
 */
class MakeCommand extends Command
{
    protected $signature = 'who-said-it:make';

    protected $description = "Make today's Who Said It? quiz (if it isn't made yet) and send the reminders.";

    public function handle(Generator $generator, Reminders $reminders, Quiz $quiz): int
    {
        // Yesterday's unfinished days count, and keep their streaks.
        $quiz->settle();

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
