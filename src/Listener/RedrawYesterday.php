<?php

namespace ErnestDefoe\WhoSaidIt\Listener;

use ErnestDefoe\WhoSaidIt\Board;
use ErnestDefoe\WhoSaidIt\Settings;
use Flarum\Settings\Event\Saved;

/** A change to the never-quote groups redraws yesterday's answers (today's are checked on every view). */
class RedrawYesterday
{
    public function __construct(private readonly Board $board)
    {
    }

    public function handle(Saved $event): void
    {
        if (array_key_exists(Settings::GROUPS, $event->settings)) {
            $this->board->forgetYesterday();
        }
    }
}
