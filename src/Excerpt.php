<?php

namespace ErnestDefoe\WhoSaidIt;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Turns a post's rendered HTML into the plain words of the quote: no quotes
 * of other posts, no links or mentions, no code, media or spoilers — only
 * what the author wrote themselves. Rendered HTML rather than the stored
 * XML, so posts from any formatter (Markdown, BBCode, Scribe) clean the same.
 */
final class Excerpt
{
    public const MAX = 240;

    /** Whole elements whose words aren't the author's own, or aren't words. */
    private const DROP = [
        'blockquote', 'pre', 'code', 'img', 'picture', 'figure', 'iframe', 'video', 'audio', 'script',
        'style', 'details', 'summary', 'table', 'svg', 'button', 'form', 'input', 'select', 'textarea',
        'object', 'embed', 'aside', 'sup', 'math', 'noscript', 'template',
    ];

    private const BLOCK = ['p', 'div', 'li', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'hr', 'dd', 'dt', 'section', 'article'];

    /**
     * @param list<string> $names The author's own names, blanked out so the quote can't give itself away.
     * @param string|null $source The post as stored: a paragraph whose words aren't in it was added
     *                            by an extension at render time (a disclosure, a notice) and is dropped.
     */
    public static function fromHtml(string $html, array $names = [], int $max = self::MAX, ?string $source = null): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="wsi-root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $root = $xpath->query('//*[@id="wsi-root"]')->item(0);
        if (! $root) {
            return '';
        }

        $drop = implode(' | ', array_map(fn ($tag) => './/'.$tag, self::DROP));
        $drop .= ' | .//*[contains(concat(" ", normalize-space(@class), " "), " spoiler ")]';
        $drop .= ' | .//*[contains(@class, "Mention")] | .//*[contains(@class, "quote")]';
        foreach (iterator_to_array($xpath->query($drop, $root)) as $node) {
            $node->parentNode?->removeChild($node);
        }

        if ($source !== null) {
            $own = self::squash(html_entity_decode(strip_tags($source), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            foreach (iterator_to_array($xpath->query('.//p | .//li | .//div[not(.//p)]', $root)) as $block) {
                $words = self::squash($block->textContent);
                if ($words !== '' && ! str_contains($own, $words)) {
                    $block->parentNode?->removeChild($block);
                }
            }
        }

        // A link keeps its words ("read the [handout] on…"), unless the words are the address itself.
        foreach (iterator_to_array($xpath->query('.//a', $root)) as $link) {
            $words = trim($link->textContent);
            $keep = $words !== '' && ! preg_match('~^(?:https?://|www\.)|^\S+\.\S+/~iu', $words);
            $link->parentNode?->replaceChild($doc->createTextNode($keep ? $link->textContent : ''), $link);
        }

        $text = self::text($root);
        $text = preg_replace('~(?:https?://|www\.)\S+~iu', '', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = trim($text, " \t\n\r\0\x0B-–—:;,");

        foreach (array_unique(array_filter($names, fn ($n) => mb_strlen($n) >= 2)) as $name) {
            $text = preg_replace('/(?<![\p{L}\p{N}_])@?'.preg_quote($name, '/').'(?![\p{L}\p{N}_])/iu', '[…]', $text) ?? $text;
        }

        // A mention taken from the front leaves "you asked…": start like a sentence.
        $text = mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);

        return self::shorten($text, $max);
    }

    private static function squash(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? '');
    }

    private static function text(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $inner = self::text($child);
                $out .= in_array(strtolower($child->tagName), self::BLOCK, true) ? ' '.$inner.' ' : $inner;
            } elseif ($child->nodeType === XML_TEXT_NODE) {
                $out .= $child->textContent;
            }
        }

        return $out;
    }

    /**
     * Whether a quote's words are all still in the post as stored now, in
     * order. Words, not characters: markup, punctuation, case and whatever
     * the excerpt left out (a mention, a link's address) don't matter, but
     * an edit that took the quoted words out does.
     */
    public static function stillIn(string $excerpt, string $stored): bool
    {
        // Markup markers (**, _, the "](url)" of a link) live in <s>/<e> elements: not the author's words.
        $stored = preg_replace('~<([se])>.*?</\1>~su', ' ', $stored) ?? $stored;
        $source = self::words(html_entity_decode(strip_tags(str_replace('<', ' <', $stored)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $at = 0;
        $count = count($source);
        foreach (self::words(str_replace('[…]', ' ', $excerpt)) as $word) {
            while ($at < $count && $source[$at] !== $word) {
                $at++;
            }
            if ($at++ >= $count) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);

        return $m[0];
    }

    /**
     * Whether the cleaned words still read as a sentence: taking something
     * out mid-sentence leaves "the , and" or "( )", which makes a poor quote.
     */
    public static function reads(string $text): bool
    {
        return ! preg_match('/\s[,.;:!?)]|\(\s*\)|\[\s*\]/u', $text);
    }

    /** At most $max characters, cut after a sentence when one ends late enough, else between words. */
    public static function shorten(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        if (preg_match_all('/[.!?…](?=\s)/u', $cut, $m, PREG_OFFSET_CAPTURE)) {
            $end = end($m[0])[1] + strlen(end($m[0])[0]);
            $sentence = substr($cut, 0, $end);
            if (mb_strlen($sentence) >= $max * 0.55) {
                return $sentence;
            }
        }

        $space = mb_strrpos($cut, ' ');
        $cut = $space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : mb_substr($cut, 0, $max - 1);

        return rtrim($cut, " ,;:-–—").'…';
    }
}
