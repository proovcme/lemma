<?php

declare(strict_types=1);

namespace App\Services;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

final class KnowledgeHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'h2', 'h3', 'strong', 'em', 'ul', 'ol', 'li', 'blockquote',
        'a', 'br', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'code',
    ];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (!class_exists(DOMDocument::class)) {
            return self::fallback($html);
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div data-knowledge-root="1">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return self::fallback($html);
        }

        $root = null;
        foreach ($dom->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('data-knowledge-root') === '1') {
                $root = $div;
                break;
            }
        }
        if (!$root instanceof DOMElement) {
            return self::fallback($html);
        }

        self::cleanChildren($root);

        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= (string) $dom->saveHTML($child);
        }

        return trim($result);
    }

    public static function plainText(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMComment) {
                $parent->removeChild($node);
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'], true)) {
                $parent->removeChild($node);
                continue;
            }

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                self::cleanChildren($node);
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            self::cleanAttributes($node, $tag);
            self::cleanChildren($node);
        }
    }

    private static function cleanAttributes(DOMElement $element, string $tag): void
    {
        $originalHref = $tag === 'a' ? $element->getAttribute('href') : '';
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $element->removeAttribute($attribute->name);
        }

        if ($tag === 'blockquote') {
            $element->setAttribute('class', 'knowledge-callout');
        }

        if ($tag !== 'a') {
            return;
        }

        if ($originalHref === '') {
            return;
        }

        $href = self::safeHref($originalHref);
        if ($href === '') {
            return;
        }
        $element->setAttribute('href', $href);
        if (preg_match('#^https?://#i', $href)) {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function safeHref(string $href): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || preg_match('/[\x00-\x1F\x7F]/', $href)) {
            return '';
        }
        if (str_starts_with($href, '#') || (str_starts_with($href, '/') && !str_starts_with($href, '//'))) {
            return mb_substr($href, 0, 2000);
        }
        if (preg_match('#^https?://#i', $href)) {
            return mb_substr($href, 0, 2000);
        }
        return '';
    }

    private static function fallback(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|svg|math)\b[^>]*>.*?</\1>#isu', '', $html) ?? '';
        $html = strip_tags($html, '<p><h2><h3><strong><em><ul><ol><li><blockquote><a><br><hr><table><thead><tbody><tr><th><td><code>');
        $html = preg_replace_callback('/<([a-z0-9]+)\b([^>]*)>/iu', static function (array $matches): string {
            $tag = strtolower($matches[1]);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                return '';
            }
            if ($tag === 'blockquote') {
                return '<blockquote class="knowledge-callout">';
            }
            if ($tag !== 'a') {
                return '<' . $tag . '>';
            }
            $href = '';
            if (preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/iu', $matches[2], $hrefMatch)) {
                $href = self::safeHref((string) ($hrefMatch[1] ?: ($hrefMatch[2] ?: ($hrefMatch[3] ?? ''))));
            }
            if ($href === '') {
                return '<a>';
            }
            $escaped = htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rel = preg_match('#^https?://#i', $href) ? ' rel="noopener noreferrer"' : '';
            return '<a href="' . $escaped . '"' . $rel . '>';
        }, $html) ?? '';
        return trim($html);
    }
}
