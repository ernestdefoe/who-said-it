<?php

namespace ErnestDefoe\WhoSaidIt;

/**
 * A member leaves for good (deleted, or erased by flarum/gdpr): their answers,
 * days and record go, and any quote of theirs is withdrawn (its words blanked).
 * A plain class: deleting a member must work whether or not flarum/gdpr is
 * installed, so nothing here may touch its classes.
 */
class Forget
{
    public function __construct(private readonly Board $board)
    {
    }

    public function __invoke(int $userId): void
    {
        Answer::query()->where('user_id', $userId)->delete();
        Score::query()->where('user_id', $userId)->delete();
        Player::query()->whereKey($userId)->delete();
        Question::query()->where('author_id', $userId)->update(['excerpt' => '', 'author_id' => null, 'post_id' => null]);
        $this->board->forgetYesterday();
    }
}
