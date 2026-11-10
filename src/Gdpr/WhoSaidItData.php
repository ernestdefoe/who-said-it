<?php

namespace ErnestDefoe\WhoSaidIt\Gdpr;

use ErnestDefoe\WhoSaidIt\Answer;
use ErnestDefoe\WhoSaidIt\Player;
use ErnestDefoe\WhoSaidIt\Question;
use ErnestDefoe\WhoSaidIt\Score;
use Flarum\Gdpr\Data\Type;

/**
 * A member's export: their record and choices, their days and every answer.
 * Erasing removes all of that, and withdraws any quote of theirs (the words
 * are cleared; past quizzes show it as withdrawn).
 */
class WhoSaidItData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-who-said-it.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-who-said-it.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $rows = [
            'record' => Player::query()->whereKey($this->user->id)->first()?->toArray(),
            'days' => Score::query()->where('who_said_it_scores.user_id', $this->user->id)
                ->join('who_said_it_days', 'who_said_it_days.id', '=', 'who_said_it_scores.day_id')
                ->get(['who_said_it_days.day', 'score', 'correct', 'answered', 'grid', 'started_at', 'finished_at'])->toArray(),
            'answers' => Answer::query()->where('user_id', $this->user->id)->whereNotNull('answered_at')
                ->get(['question_id', 'answered_at', 'choice_id', 'correct', 'points', 'ms'])->toArray(),
        ];

        return [['who-said-it/who-said-it.json' => $this->encodeForExport($rows)]];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        self::forget($this->user->id);
    }

    public static function forget(int $userId): void
    {
        Answer::query()->where('user_id', $userId)->delete();
        Score::query()->where('user_id', $userId)->delete();
        Player::query()->whereKey($userId)->delete();
        Question::query()->where('author_id', $userId)->update(['excerpt' => '', 'author_id' => null, 'post_id' => null]);
    }
}
