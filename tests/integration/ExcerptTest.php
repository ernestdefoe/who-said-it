<?php

namespace ErnestDefoe\WhoSaidIt\Tests\integration;

use ErnestDefoe\WhoSaidIt\Excerpt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ExcerptTest extends TestCase
{
    #[Test]
    public function only_the_authors_own_words_are_kept()
    {
        $html = '<blockquote><p>Someone else said this</p></blockquote>'
            .'<p>Thanks <a href="/u/x" class="UserMention">@Sam</a>, I agree. See <a href="https://x.test">this link</a> and https://example.com/page.</p>'
            .'<pre><code>echo 1;</code></pre><p>My name is Dana and <details class="spoiler"><summary>s</summary>the butler did it</details>'
            .'I &amp; my team <img src="x.png" alt="pic"> loved it.</p><ul><li>one</li><li>two</li></ul>';

        $text = Excerpt::fromHtml($html, ['dana']);

        $this->assertSame('Thanks , I agree. See and My name is […] and I & my team loved it. one two', $text);
    }

    #[Test]
    public function long_posts_end_at_a_sentence_or_a_word()
    {
        $sentence = str_repeat('word ', 30).'end. '.str_repeat('more ', 40);
        $short = Excerpt::shorten(trim($sentence), 240);
        $this->assertStringEndsWith('end.', $short);

        $noStop = trim(str_repeat('abcdefg ', 60));
        $cut = Excerpt::shorten($noStop, 240);
        $this->assertStringEndsWith('abcdefg…', $cut);
        $this->assertLessThanOrEqual(241, mb_strlen($cut));
    }
}
