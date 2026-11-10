<?php

namespace ErnestDefoe\WhoSaidIt\Api;

use ErnestDefoe\WhoSaidIt\Board;
use ErnestDefoe\WhoSaidIt\Question;
use ErnestDefoe\WhoSaidIt\Quiz;
use ErnestDefoe\WhoSaidIt\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/who-said-it/answer {question, choice}: the member's pick, checked
 * here. Only now does the answer (who wrote it, and the post) come back.
 */
class AnswerController implements RequestHandlerInterface
{
    private const STATUS = ['wsi_closed' => 422, 'wsi_bad_choice' => 422, 'wsi_not_shown' => 422, 'wsi_withdrawn' => 422, 'wsi_answered' => 409];

    public function __construct(private readonly Quiz $quiz, private readonly Board $board)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::PLAY);

        $body = (array) $request->getParsedBody();
        $question = Question::query()->find((int) Arr::get($body, 'question'));
        if (! $question) {
            return Errors::make(404, 'wsi_not_found');
        }

        [$error, $answer] = $this->quiz->answer($actor, $question, (int) Arr::get($body, 'choice'));
        if ($error && ! ($error === 'wsi_answered' && $answer)) {
            return Errors::make(self::STATUS[$error], $error);
        }

        $posts = $this->quiz->presenter()->posts([$question]);
        $day = $this->quiz->generator()->today();

        return new JsonResponse([
            'reveal' => $this->quiz->reveal($question, $answer, $posts),
            'me' => $this->board->me($actor, $day),
            'users' => $this->quiz->presenter()->users($question->choiceIds()),
        ]);
    }
}
