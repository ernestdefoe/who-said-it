<?php

namespace ErnestDefoe\WhoSaidIt\Listener;

use ErnestDefoe\WhoSaidIt\Forget;
use Flarum\User\Event\Deleting;

/** Before the user row goes: their answers go with it (foreign keys), but their quoted words must too. */
class ForgetDeleted
{
    public function __construct(private readonly Forget $forget)
    {
    }

    public function handle(Deleting $event): void
    {
        ($this->forget)($event->user->id);
    }
}
