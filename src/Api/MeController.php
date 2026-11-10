<?php

namespace ErnestDefoe\WhoSaidIt\Api;

use ErnestDefoe\WhoSaidIt\Board;
use ErnestDefoe\WhoSaidIt\Quiz;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/who-said-it/me {remind?, optedOut?}: the member's own choices —
 * the daily reminder, and whether their posts may be quoted (or their face
 * shown as a choice). Anyone signed in may opt out, play or not.
 */
class MeController implements RequestHandlerInterface
{
    public function __construct(private readonly Quiz $quiz, private readonly Board $board)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = (array) $request->getParsedBody();
        $player = $this->quiz->player($actor->id);
        if (Arr::has($body, 'remind')) {
            $player->remind = (bool) Arr::get($body, 'remind');
        }
        if (Arr::has($body, 'optedOut')) {
            $player->opted_out = (bool) Arr::get($body, 'optedOut');
        }
        $player->save();
        if (Arr::has($body, 'optedOut')) {
            // Out (or back in) of today's quiz at once; yesterday's answers are drawn again too.
            $this->board->forgetYesterday();
        }

        return new JsonResponse(['remind' => $player->remind, 'optedOut' => $player->opted_out]);
    }
}
