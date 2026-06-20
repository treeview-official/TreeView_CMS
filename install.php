<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/Markdown.php';
require __DIR__ . '/lib/HtmlSanitizer.php';
require __DIR__ . '/lib/NoteRepository.php';
require __DIR__ . '/lib/UserRepository.php';
require __DIR__ . '/lib/SettingsRepository.php';
require __DIR__ . '/lib/ImageRepository.php';

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set(APP_TIMEZONE);

$message = null;
$error = null;
$needsAdmin = true;

function run_sql_file(PDO $pdo, string $file)
{
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        throw new RuntimeException($file . ' 파일을 찾을 수 없습니다.');
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException($file . ' 파일을 읽을 수 없습니다.');
    }

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
}

try {
    $pdo = Database::pdo();
    $pdo->query('SELECT 1 FROM users LIMIT 1');
    $needsAdmin = ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) === 0;
} catch (Throwable $e) {
    $needsAdmin = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = Database::pdo();
        run_sql_file($pdo, 'schema.sql');
        run_sql_file($pdo, 'category_tables.sql');

        $count = (int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn();
        if ($count === 0) {
            $repo = new NoteRepository();
            $body = "---\ntitle: 시작하기\ntags: [guide]\n---\n\n# 시작하기\n\n## 요약\nTreeView CMS는 웹에서 관리하는 지식 DB입니다.\n\n## 기본 정보\n- 작성은 웹 폼에서 합니다.\n- DB에는 UTF-8 텍스트로 저장됩니다.\n- [[문서명]] 링크와 #태그를 사용할 수 있습니다.\n\n## 관련 문서\n- [[문서 작성법]]\n";
            $repo->save(null, '시작하기', $body);
            $repo->save(null, '문서 작성법', "---\ntitle: 문서 작성법\ntags: [guide]\n---\n\n# 문서 작성법\n\n## 요약\n관리자 로그인 후 새 문서를 작성하세요.\n\n## 주요 이력\n- 제목, 태그, 요약, 기본 정보 등을 입력합니다.\n- 관련 문서에는 국민의힘 또는 [[국민의힘]]처럼 입력합니다.\n- 저장하면 링크와 태그가 자동으로 연결됩니다.\n\n## 관련 문서\n- [[시작하기]]\n");
        }

        $settings = new SettingsRepository();
        $settingsValues = [];
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        if ($siteName !== '') {
            $settingsValues['site_name'] = $siteName;
        }
        $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
        if ($contactEmail !== '') {
            $settingsValues['contact_email'] = $contactEmail;
        }
        if (!empty($_FILES['favicon']) && (int) ($_FILES['favicon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $images = new ImageRepository();
            $favicon = $images->storeUploaded($_FILES['favicon'], $siteName !== '' ? $siteName . ' favicon' : 'favicon');
            $settingsValues['favicon_path'] = (string) ($favicon['file_url'] ?? '');
        }
        if ($settingsValues !== []) {
            $settings->update($settingsValues);
        }

        $users = new UserRepository();
        if ($users->countUsers() === 0) {
            $users->createAdmin(
                (string) ($_POST['admin_email'] ?? ''),
                (string) ($_POST['admin_name'] ?? ''),
                (string) ($_POST['admin_password'] ?? '')
            );
        }

        $message = '설치가 완료되었습니다. index.php로 이동하세요.';
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
    <title>Install - <?= htmlspecialchars(APP_NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="install-page">
    <main class="install-box">
        <h1><?= htmlspecialchars(APP_NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> 설치</h1>
        <p>MySQL 테이블을 만들고 기본 문서, 사이트 설정<?= $needsAdmin ? ', 관리자 계정' : '' ?>을 추가합니다. 먼저 <code>config.php</code>의 DB 정보를 확인하세요.</p>
        <?php if ($message): ?><div class="notice success"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <div class="account-form install-form">
                <h3>사이트 설정</h3>
                <label><span>사이트 이름</span><input type="text" name="site_name" value="<?= htmlspecialchars(APP_NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="80" required></label>
                <label><span>파비콘 이미지</span><input type="file" name="favicon" accept="image/png,image/jpeg,image/gif,image/webp"></label>
                <?php if ($needsAdmin): ?>
                    <h3>관리자 계정</h3>
                    <label><span>이름</span><input type="text" name="admin_name" maxlength="80" required></label>
                    <label><span>이메일</span><input type="email" name="admin_email" autocomplete="email" required></label>
                    <label><span>비밀번호</span><input type="password" name="admin_password" autocomplete="new-password" minlength="8" required></label>
                <?php else: ?>
                    <div class="notice success">관리자 계정이 이미 있습니다. Contact 설정만 갱신할 수 있습니다.</div>
                <?php endif; ?>
                <h3>Contact</h3>
                <label><span>운영자 이메일</span><input type="email" name="contact_email" value="contact@example.com" required></label>
            </div>
            <button class="button primary" type="submit">설치 실행</button>
            <a class="button" href="index.php">홈으로</a>
        </form>
    </main>
</body>
</html>
