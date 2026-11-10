<?php

namespace ErnestDefoe\WhoSaidIt\Api;

use ErnestDefoe\WhoSaidIt\Board;
use ErnestDefoe\WhoSaidIt\Quiz;
use ErnestDefoe\WhoSaidIt\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/who-said-it/next: show the member their next quote and start
 * its clock (or give back the one already showing, clock still running).
 * When none are left, the day is finished and only the summary comes back.
 */
class NextController implements RequestHandlerInterface
{
    public function __construct(private readonly Quiz $quiz, private readonly Board $board)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::PLAY);

        $this->quiz->settle($actor->id);
        $generator = $this->quiz->generator();
        $day = $generator->today();
        if (! $day) {
            return $generator->busy ? Errors::make(503, 'wsi_making') : Errors::make(404, 'wsi_no_quiz');
        }

        $next = $this->quiz->next($day, $actor);
        $current = $next ? $this->quiz->served($next['question'], $next['answer']) : null;

        return new JsonResponse([
            'current' => $current,
            'me' => $this->board->me($actor, $day),
            'users' => $current ? $this->quiz->presenter()->users($current['choices']) : [],
        ]);
    }
}
