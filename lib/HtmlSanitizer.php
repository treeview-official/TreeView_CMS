<?php
declare(strict_types=1);

final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'mark', 'small',
        'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'a', 'img', 'figure', 'figcaption',
        'div', 'span',
    ];

    private const GLOBAL_ATTRS = ['class', 'title'];

    private const TAG_ATTRS = [
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
        'code' => ['class'],
        'pre' => ['class'],
    ];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (!class_exists('DOMDocument')) {
            return self::fallbackClean($html);
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET;
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>', $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root__');
        if (!$root) {
            return '';
        }

        self::sanitizeNode($root);
        return self::innerHtml($root);
    }

    private static function sanitizeNode(DOMNode $node): void
    {
        for ($child = $node->firstChild; $child !== null;) {
            $next = $child->nextSibling;

            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                $child = $next;
                continue;
            }

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    self::unwrapNode($child);
                    $child = $next;
                    continue;
                }

                self::sanitizeAttributes($child, $tag);
                self::sanitizeNode($child);
            }

            $child = $next;
        }
    }

    private static function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRS, self::TAG_ATTRS[$tag] ?? []);
        $remove = [];

        foreach ($element->attributes as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);
            if (!in_array($name, $allowed, true) || strpos($name, 'on') === 0 || $name === 'style') {
                $remove[] = $attr->name;
                continue;
            }

            if (in_array($name, ['href', 'src'], true) && !self::isSafeUrl($value)) {
                $remove[] = $attr->name;
                continue;
            }

            if ($name === 'target' && !in_array($value, ['_blank', '_self'], true)) {
                $remove[] = $attr->name;
                continue;
            }

            if ($name === 'class' && !preg_match('/\A[-_ a-zA-Z0-9]+\z/', $value)) {
                $remove[] = $attr->name;
            }
        }

        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }

        if ($tag === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
        if ($tag === 'img' && !$element->hasAttribute('loading')) {
            $element->setAttribute('loading', 'lazy');
        }
    }

    private static function isSafeUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }
        if (preg_match('/^(https?:|mailto:|tel:|\/|#)/i', $url)) {
            return true;
        }
        return !preg_match('/^[a-z][a-z0-9+.-]*:/i', $url);
    }

    private static function unwrapNode(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (!$parent) {
            return;
        }
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    private static function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument ? $node->ownerDocument->saveHTML($child) : '';
        }
        return trim($html);
    }

    private static function fallbackClean(string $html): string
    {
        $allowed = '<' . implode('><', self::ALLOWED_TAGS) . '>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '', $html) ?? $html;
        return trim($html);
    }
}
