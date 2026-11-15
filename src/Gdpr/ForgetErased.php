<?php

namespace ErnestDefoe\WhoSaidIt\Gdpr;

use ErnestDefoe\WhoSaidIt\Forget;
use Flarum\Gdpr\Events\Erased;

/**
 * A GDPR erasure (deletion or anonymization) removes the member's days,
 * answers and record and withdraws their quotes. Done here rather than in
 * the data type: flarum/gdpr builds data types with `new`, so they can't
 * have Forget injected, and a listener can.
 */
class ForgetErased
{
    public function __construct(private readonly Forget $forget)
    {
    }

    public function handle(Erased $event): void
    {
        ($this->forget)($event->user->id);
    }
}
