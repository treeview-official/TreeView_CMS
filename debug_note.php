<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/config.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/Markdown.php';
require __DIR__ . '/lib/HtmlSanitizer.php';
require __DIR__ . '/lib/NoteRepository.php';

header('Content-Type: text/html; charset=utf-8');

if (($_SESSION['admin'] ?? false) !== true) {
    http_response_code(403);
    echo '관리자 로그인 후 이용할 수 있습니다.';
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bytes(string $value): string
{
    return strtoupper(bin2hex(substr($value, 0, 120)));
}

$slug = (string) ($_GET['note'] ?? '');
$repo = new NoteRepository();
$note = $slug !== '' ? $repo->findBySlug($slug) : null;
$pdo = Database::pdo();
$charset = $pdo->query("SHOW VARIABLES LIKE 'character_set_%'")->fetchAll();
$collation = $pdo->query("SHOW VARIABLES LIKE 'collation_%'")->fetchAll();
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <title>Debug Note</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 24px; line-height: 1.6; }
        pre { white-space: pre-wrap; border: 1px solid #ddd; padding: 12px; overflow: auto; }
        code { background: #f2f2f2; padding: 2px 5px; }
    </style>
</head>
<body>
    <h1>Debug Note</h1>
    <p>slug: <code><?= e($slug) ?></code></p>

    <?php if (!$note): ?>
        <p>노트를 찾지 못했습니다.</p>
    <?php else: ?>
        <h2>Title</h2>
        <pre><?= e((string) $note['title']) ?></pre>

        <h2>DB Body Raw First 1200 Chars</h2>
        <pre><?= e(mb_substr((string) $note['body'], 0, 1200, 'UTF-8')) ?></pre>

        <h2>DB Body First Bytes</h2>
        <pre><?= e(bytes((string) $note['body'])) ?></pre>

        <h2>Rendered HTML First 1200 Chars</h2>
        <pre><?= e(mb_substr(Markdown::render((string) $note['body']), 0, 1200, 'UTF-8')) ?></pre>
    <?php endif; ?>

    <h2>MySQL Character Sets</h2>
    <pre><?php foreach ($charset as $row) echo e($row['Variable_name'] . ': ' . $row['Value']) . "\n"; ?></pre>

    <h2>MySQL Collations</h2>
    <pre><?php foreach ($collation as $row) echo e($row['Variable_name'] . ': ' . $row['Value']) . "\n"; ?></pre>
</body>
</html>
