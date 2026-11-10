<?php

namespace ErnestDefoe\WhoSaidIt\Api;

use ErnestDefoe\WhoSaidIt\Board;
use ErnestDefoe\WhoSaidIt\Clock;
use ErnestDefoe\WhoSaidIt\Day;
use ErnestDefoe\WhoSaidIt\Generator;
use ErnestDefoe\WhoSaidIt\Reminders;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/who-said-it/admin: how today's quiz stands and how many posts
 * qualify under the current settings. POST: make today's quiz now (when it
 * isn't made yet; a made day is never changed). Admins only. Never sends
 * the quotes or answers: the admin may want to play too.
 */
class AdminController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Generator $generator,
        private readonly Clock $clock,
        private readonly Board $board,
        private readonly Reminders $reminders,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $date = $this->clock->today();
        if ($request->getMethod() === 'POST') {
            $day = $this->generator->forDate($date, true);
            if (! $day) {
                return Errors::make(422, 'wsi_not_enough');
            }
            $this->reminders->queue($day);
        }

        $day = Day::query()->where('day', $date)->first();
        // Counted up to 1,000: enough to say whether the settings leave room for quizzes.
        $eligible = $this->db->query()->fromSub($this->generator->eligible()->select('posts.id')->limit(1000)->toBase(), 'wsi_eligible')->count();

        return new JsonResponse([
            'date' => $date,
            'count' => $day?->question_count,
            'players' => $day ? $this->board->today($day)['players'] : 0,
            'eligible' => $eligible,
        ]);
    }
}
