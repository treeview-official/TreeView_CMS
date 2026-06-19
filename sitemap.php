<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/Markdown.php';
require __DIR__ . '/lib/HtmlSanitizer.php';
require __DIR__ . '/lib/NoteRepository.php';

header('Content-Type: application/xml; charset=utf-8');

function xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function site_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . ($dir === '' ? '' : $dir);
}

$repo = new NoteRepository();
$notes = $repo->all(null, null, 1000, 0);
$base = site_base_url();

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc><?= xml_escape($base . '/') ?></loc></url>
    <url><loc><?= xml_escape($base . '/index.php?page=about') ?></loc></url>
    <url><loc><?= xml_escape($base . '/index.php?page=terms') ?></loc></url>
    <url><loc><?= xml_escape($base . '/index.php?page=contact') ?></loc></url>
    <url><loc><?= xml_escape($base . '/index.php?page=privacy') ?></loc></url>
<?php foreach ($notes as $note): ?>
    <url>
        <loc><?= xml_escape($base . '/index.php?note=' . rawurlencode($note['slug'])) ?></loc>
        <lastmod><?= xml_escape(date('Y-m-d', strtotime($note['updated_at']))) ?></lastmod>
    </url>
<?php endforeach; ?>
</urlset>
