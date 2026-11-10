<?php

namespace ErnestDefoe\WhoSaidIt\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * "Today's Who Said It? is ready." The subject is the member being told
 * (Users have an API resource; a subject without one breaks every
 * notification list); the day rides in the data.
 */
class ReminderBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(
        public readonly User $user,
        public readonly string $day,
        public readonly int $count,
    ) {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->user;
    }

    public function getData(): mixed
    {
        return ['day' => $this->day, 'count' => $this->count];
    }

    public static function getType(): string
    {
        return 'whoSaidItReminder';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
