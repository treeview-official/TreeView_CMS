<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/Markdown.php';
require __DIR__ . '/lib/SettingsRepository.php';
header('Content-Type: text/html; charset=utf-8');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render_setting_text(string $value): string
{
    $paragraphs = preg_split('/\R{2,}/', trim($value)) ?: [];
    return implode('', array_map(static fn ($text) => '<p>' . h(trim($text)) . '</p>', array_filter($paragraphs, static fn ($text) => trim($text) !== '')));
}

$settings = new SettingsRepository();
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy - <?= h(APP_NAME) ?></title>
    <meta name="description" content="Privacy policy for this website.">
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1439761268779551" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="policy-layout">
<main class="policy-page">
    <a class="button" href="index.php">Home</a>
    <h1>개인정보처리방침</h1>
    <?= render_setting_text($settings->get('privacy_body')) ?>
    <p>Last updated: 2026-06-18</p>
</main>
<footer class="site-footer policy-footer">
    <div class="site-footer-main">
        <div>
            <strong><?= h(APP_NAME) ?></strong>
            <p><?= h($settings->get('site_summary')) ?></p>
        </div>
        <nav class="site-footer-links" aria-label="Footer navigation">
            <a href="index.php?page=about">About</a>
            <a href="index.php?page=terms">이용방침</a>
            <a href="index.php?page=privacy">개인정보처리방침</a>
            <a href="index.php?page=contact">Contact</a>
            <a href="https://github.com/treeview-official/TreeView_CMS" target="_blank" rel="noopener">GitHub</a>
        </nav>
    </div>
</footer>
</body>
</html>
