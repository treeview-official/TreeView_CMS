<?php
declare(strict_types=1);

final class Markdown
{
    public static function normalize(string $text): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    }

    public static function render(string $markdown, bool $skipFirstH1 = false): string
    {
        $markdown = self::publicBody($markdown);
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $html = [];
        $inCode = false;
        $code = [];
        $codeLang = '';
        $h1Skipped = false;
        $listType = null;

        $closeList = static function () use (&$html, &$listType) {
            if ($listType !== null) {
                $html[] = '</' . $listType . '>';
                $listType = null;
            }
        };

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (preg_match('/^\s*```\s*([A-Za-z0-9_+.-]+)?\s*$/u', $line, $matches)) {
                if ($inCode) {
                    $langAttr = $codeLang !== '' ? ' data-lang="' . self::escape($codeLang) . '"' : '';
                    $html[] = '<pre class="code-block"' . $langAttr . '><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                    $code = [];
                    $codeLang = '';
                    $inCode = false;
                } else {
                    $closeList();
                    $inCode = true;
                    $codeLang = trim((string) ($matches[1] ?? ''));
                }
                continue;
            }

            if ($inCode) {
                $code[] = $line;
                continue;
            }

            if (trim($line) === '') {
                $closeList();
                continue;
            }

            if (preg_match('/^\s*---+\s*$/u', $line)) {
                $closeList();
                $html[] = '<hr>';
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $matches)) {
                $closeList();
                $level = strlen($matches[1]);
                if ($skipFirstH1 && !$h1Skipped && $level === 1) {
                    $h1Skipped = true;
                    continue;
                }
                $html[] = sprintf('<h%d>%s</h%d>', $level, self::inline($matches[2]), $level);
                continue;
            }

            if (self::isTableStart($lines, $i)) {
                $closeList();
                $rows = [$lines[$i]];
                $i += 2;
                while ($i < count($lines) && self::isTableRow($lines[$i])) {
                    $rows[] = $lines[$i];
                    $i++;
                }
                $i--;
                $html[] = self::table($rows);
                continue;
            }

            if (preg_match('/^\s*[-*]\s+(.+)$/u', $line, $matches)) {
                if ($listType !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $listType = 'ul';
                }
                $html[] = '<li>' . self::inline($matches[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\s*\d+[.)]\s+(.+)$/u', $line, $matches)) {
                if ($listType !== 'ol') {
                    $closeList();
                    $html[] = '<ol>';
                    $listType = 'ol';
                }
                $html[] = '<li>' . self::inline($matches[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\s*>\s?(.+)$/u', $line, $matches)) {
                $closeList();
                $html[] = '<blockquote>' . self::inline($matches[1]) . '</blockquote>';
                continue;
            }

            $closeList();
            $html[] = '<p>' . self::inline($line) . '</p>';
        }

        if ($inCode) {
            $langAttr = $codeLang !== '' ? ' data-lang="' . self::escape($codeLang) . '"' : '';
            $html[] = '<pre class="code-block"' . $langAttr . '><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
        }
        $closeList();

        return implode("\n", $html);
    }

    public static function metadata(string $markdown): array
    {
        $markdown = self::normalize($markdown);
        $meta = [];

        if (!preg_match('/^---(?:\r\n|\r|\n)(.*?)(?:\r\n|\r|\n)---(?:\r\n|\r|\n)/s', $markdown, $matches)) {
            return $meta;
        }

        foreach (preg_split('/\r\n|\r|\n/u', trim($matches[1])) ?: [] as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            list($key, $value) = array_map('trim', explode(':', $line, 2));
            if ($key === '') {
                continue;
            }
            if (substr($value, 0, 1) === '[' && substr($value, -1) === ']') {
                $items = array_map('trim', explode(',', trim($value, '[]')));
                $meta[$key] = array_values(array_filter($items, static function ($item): bool {
                    return $item !== '';
                }));
            } else {
                $meta[$key] = trim($value, "\"'");
            }
        }

        return $meta;
    }

    public static function stripFrontMatter(string $markdown): string
    {
        $markdown = self::normalize($markdown);
        return preg_replace('/^---(?:\r\n|\r|\n).*?(?:\r\n|\r|\n)---(?:\r\n|\r|\n)/s', '', $markdown) ?? $markdown;
    }

    public static function stripMemoSection(string $markdown): string
    {
        $markdown = self::normalize($markdown);
        return preg_replace('/(?:\r\n|\r|\n)?[ \t]*---+[ \t]*(?:\r\n|\r|\n)\s*##[ \t]*메모[ \t]*(?:(?:\r\n|\r|\n)|$).*$/s', '', $markdown) ?? $markdown;
    }

    public static function publicBody(string $markdown): string
    {
        return self::stripMemoSection(self::stripFrontMatter(self::normalize($markdown)));
    }

    public static function wikiLinks(string $markdown): array
    {
        preg_match_all('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u', self::publicBody($markdown), $matches, PREG_SET_ORDER);
        $links = [];
        foreach ($matches as $match) {
            $title = trim($match[1]);
            if ($title !== '') {
                $links[] = $title;
            }
        }
        return array_values(array_unique($links));
    }

    public static function tags(string $markdown): array
    {
        $markdown = self::normalize($markdown);
        $meta = self::metadata($markdown);
        $tags = [];

        if (isset($meta['tags']) && is_array($meta['tags'])) {
            $tags = $meta['tags'];
        }

        preg_match_all('/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_-]+)/u', self::publicBody($markdown), $matches);
        foreach ($matches[1] ?? [] as $tag) {
            $tags[] = $tag;
        }

        $tags = array_map(static function ($tag): string {
            return mb_strtolower(ltrim(trim((string) $tag), '#'), 'UTF-8');
        }, $tags);
        return array_values(array_unique(array_filter($tags)));
    }

    public static function slug(string $title): string
    {
        $slug = mb_strtolower(trim(self::normalize($title)), 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'note-' . date('YmdHis');
    }

    private static function inline(string $text): string
    {
        $text = self::escape(html_entity_decode(self::normalize($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u', static function (array $match): string {
            $target = trim($match[1]);
            $label = $match[2] ?? $target;
            return '<a class="wiki-link" href="?note=' . rawurlencode(Markdown::slug($target)) . '">' . Markdown::escape($label) . '</a>';
        }, $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/u', '<a href="$2" target="_blank" rel="noreferrer">$1</a>', $text) ?? $text;
        $text = preg_replace('/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_-]+)/u', '<a class="tag" href="?tag=$1">#$1</a>', $text) ?? $text;
        return $text;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function isTableStart(array $lines, int $index): bool
    {
        return isset($lines[$index + 1])
            && self::isTableRow($lines[$index])
            && self::isTableDivider($lines[$index + 1]);
    }

    private static function isTableRow(string $line): bool
    {
        return strpos(trim($line), '|') !== false && preg_match('/^\s*\|?.+\|.+\|?\s*$/u', $line) === 1;
    }

    private static function isTableDivider(string $line): bool
    {
        return preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/u', $line) === 1;
    }

    private static function table(array $rows): string
    {
        $head = self::tableCells($rows[0] ?? '');
        $bodyRows = array_slice($rows, 1);
        $html = ['<div class="table-wrap"><table><thead><tr>'];

        foreach ($head as $cell) {
            $html[] = '<th>' . self::inline($cell) . '</th>';
        }

        $html[] = '</tr></thead><tbody>';
        foreach ($bodyRows as $row) {
            $html[] = '<tr>';
            foreach (self::tableCells($row) as $cell) {
                $html[] = '<td>' . self::inline($cell) . '</td>';
            }
            $html[] = '</tr>';
        }
        $html[] = '</tbody></table></div>';

        return implode('', $html);
    }

    private static function tableCells(string $row): array
    {
        return array_map('trim', explode('|', trim(trim($row), '|')));
    }
}
