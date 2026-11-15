<?php

namespace ErnestDefoe\WhoSaidIt\Api\Resource;

use ErnestDefoe\WhoSaidIt\Board;
use ErnestDefoe\WhoSaidIt\Clock;
use ErnestDefoe\WhoSaidIt\Player;
use ErnestDefoe\WhoSaidIt\Settings;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * The `who-said-it-players` JSON:API type: a member's all-time record (the
 * id is the user's).
 *
 * GET   /api/who-said-it-players          the all-time board: the ten best totals
 * GET   /api/who-said-it-players/{id}     one member's record
 * PATCH /api/who-said-it-players/{id}     the member's own choices: remind, optedOut
 *
 * A record is totals and streaks: it names no quote and carries no answer.
 * The two choices are visible and writable only to the member themself.
 *
 * @extends AbstractDatabaseResource<Player>
 */
class PlayerResource extends AbstractDatabaseResource
{
    public const BOARD = 10;

    public function __construct(
        private readonly Clock $clock,
        private readonly Board $board,
    ) {
    }

    public function type(): string
    {
        return 'who-said-it-players';
    }

    public function model(): string
    {
        return Player::class;
    }

    /** @param Builder<Player> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        if (! $context->getActor()->can(Settings::VIEW)) {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * A member's own record exists before they first play, so their choices can be saved:
     * made on first use. Anyone else's is only found if they have one.
     */
    public function find(string $id, BaseContext $context): ?object
    {
        $actor = $context->getActor();
        if (! $actor->isGuest() && (int) $id === $actor->id) {
            Player::query()->insertOrIgnore(['user_id' => $actor->id]);
        }

        return parent::find($id, $context);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate(self::BOARD, self::BOARD)
                ->defaultInclude(['user'])
                ->eagerLoad(['user.groups'])
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context) => $this->leaders($query, $pagination, $context)),
            Endpoint\Show::make(),
            Endpoint\Update::make()
                ->authenticated()
                ->visible(fn (Player $player, Context $context) => $player->user_id === $context->getActor()->id),
        ];
    }

    public function fields(): array
    {
        $own = fn (Player $player, Context $context) => $player->user_id === $context->getActor()->id;

        return [
            Schema\Integer::make('totalScore')->property('total_score'),
            Schema\Integer::make('quizzes'),
            Schema\Integer::make('correctTotal')->property('correct_total'),
            Schema\Integer::make('bestStreak')->property('best_streak'),
            // Broken once a day is missed, whatever the stored number says.
            Schema\Integer::make('streak')->get(fn (Player $player) => $player->currentStreak($this->clock->today(), $this->clock->yesterday())),
            Schema\Boolean::make('remind')->visible($own)->writable($own),
            Schema\Boolean::make('optedOut')->property('opted_out')->visible($own)->writable($own),
            Schema\Relationship\ToOne::make('user')->includable(),
        ];
    }

    /**
     * Opting out (or back in) takes effect at once: today's quiz checks on every view,
     * and yesterday's answers, which are cached, are drawn again.
     */
    public function update(object $model, BaseContext $context): object
    {
        $changed = $model->isDirty('opted_out');
        $model = parent::update($model, $context);
        if ($changed) {
            $this->board->forgetYesterday();
        }

        return $model;
    }

    /** @param Builder<Player> $query */
    private function leaders(Builder $query, ?Pagination $pagination, Context $context): Context
    {
        $query->whereHas('user')->where('who_said_it_players.quizzes', '>', 0)
            ->orderByDesc('who_said_it_players.total_score')->orderByDesc('who_said_it_players.quizzes');

        // A query closure owns its pagination: core applies it only on its own path.
        if ($pagination && method_exists($pagination, 'apply')) {
            $pagination->apply($query);
        }

        return $context->withQuery($query);
    }

    /** The board shows its ten; nothing shows a total, so nothing counts one. */
    public function count(object $query, BaseContext $context): ?int
    {
        return null;
    }
}
