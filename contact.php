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

$settings = new SettingsRepository();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $settings->saveContactMessage(
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['subject'] ?? ''),
            (string) ($_POST['message'] ?? '')
        );
        $message = '문의가 접수되었습니다.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact - <?= h(APP_NAME) ?></title>
    <meta name="description" content="Contact information for corrections, questions, and requests.">
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1439761268779551" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="policy-layout">
<main class="policy-page">
    <a class="button" href="index.php">Home</a>
    <h1>Contact</h1>
    <p><?= h($settings->get('contact_intro')) ?></p>
    <p>Email: <a href="mailto:<?= h($settings->get('contact_email')) ?>"><?= h($settings->get('contact_email')) ?></a></p>
    <?php if ($message): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice danger"><?= h($error) ?></div><?php endif; ?>
    <form class="account-form contact-form" method="post">
        <label><span>이름</span><input type="text" name="name" maxlength="80" required></label>
        <label><span>이메일</span><input type="email" name="email" required></label>
        <label><span>제목</span><input type="text" name="subject" maxlength="160" required></label>
        <label><span>내용</span><textarea name="message" rows="7" required></textarea></label>
        <button class="button primary" type="submit">문의 보내기</button>
    </form>
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
