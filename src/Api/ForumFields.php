<?php

namespace ErnestDefoe\WhoSaidIt\Api;

use ErnestDefoe\WhoSaidIt\ForumState;
use ErnestDefoe\WhoSaidIt\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/**
 * The `whoSaidIt` forum attribute behind the sidebar card and the settings
 * switches (see ForumState: no query for guests, one for a member).
 */
class ForumFields
{
    public function __construct(private readonly ForumState $state)
    {
    }

    /** @return list<Schema\Arr> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('whoSaidIt')
                ->visible(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW))
                ->get(fn ($forum, Context $context) => ($this->state)($context->getActor())),
        ];
    }
}
