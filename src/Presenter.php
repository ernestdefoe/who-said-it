<?php

namespace ErnestDefoe\WhoSaidIt;

use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;

/** People and links in the compact shapes the page reads. */
class Presenter
{
    public function __construct(
        private readonly SlugManager $slugs,
        private readonly UrlGenerator $url,
    ) {
    }

    /**
     * @param array<int|null> $ids
     * @return list<array{id: int, username: string, displayName: string, slug: string, avatarUrl: string|null}>
     */
    public function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return [];
        }

        $slugger = $this->slugs->forResource(User::class);

        return User::query()->whereIn('id', $ids)->get()->map(fn (User $user) => [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'slug' => $slugger->toSlug($user),
            'avatarUrl' => $user->avatar_url,
        ])->values()->all();
    }

    /**
     * Where each answer's post is: its discussion's title and a link to the post.
     *
     * @param list<Question> $questions
     * @return array<int, array{url: string, title: string}>
     */
    public function posts(array $questions): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (Question $q) => $q->discussion_id, $questions))));
        if (! $ids) {
            return [];
        }

        $slugger = $this->slugs->forResource(Discussion::class);
        $numbers = Discussion::query()->whereIn('discussions.id', $ids)->get(['id', 'title', 'slug'])->keyBy('id');
        $postNumbers = \Flarum\Post\Post::query()->whereIn('id', array_filter(array_map(fn (Question $q) => $q->post_id, $questions)))->pluck('number', 'id');

        $out = [];
        foreach ($questions as $q) {
            $discussion = $numbers->get($q->discussion_id);
            if (! $discussion || ! $q->post_id) {
                continue;
            }
            $out[$q->id] = [
                'url' => $this->url->to('forum')->route('discussion', [
                    'id' => $slugger->toSlug($discussion),
                    'near' => (int) ($postNumbers[$q->post_id] ?? 1),
                ]),
                'title' => (string) $discussion->title,
            ];
        }

        return $out;
    }
}
