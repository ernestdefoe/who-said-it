<?php

namespace ErnestDefoe\WhoSaidIt\Api;

use ErnestDefoe\WhoSaidIt\Board;
use ErnestDefoe\WhoSaidIt\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/who-said-it: the page. Today's quiz is made here if the
 * scheduler hasn't made it yet. Quotes the member hasn't reached are not in
 * it at all; answered ones come with their answers.
 */
class PageController implements RequestHandlerInterface
{
    public function __construct(private readonly Board $board)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        return new JsonResponse($this->board->page($actor));
    }
}
