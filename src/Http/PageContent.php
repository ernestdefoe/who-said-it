<?php

namespace ErnestDefoe\WhoSaidIt\Http;

use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/** /who-said-it as the server sends it: a title, a description and a canonical URL. The quiz itself needs JavaScript. */
class PageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $document->title = (string) $this->translator->trans('ernestdefoe-who-said-it.forum.page.title');
        $document->meta['description'] = (string) $this->translator->trans('ernestdefoe-who-said-it.forum.page.lead');
        $document->canonicalUrl = $this->url->to('forum')->route('who-said-it');

        return $document;
    }
}
