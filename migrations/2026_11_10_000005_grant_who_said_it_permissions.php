<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Anyone may see the leaderboards and yesterday's answers; members play.
return Migration::addPermissions([
    'whoSaidIt.view' => Group::GUEST_ID,
    'whoSaidIt.play' => Group::MEMBER_ID,
]);
