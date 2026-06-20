<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

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

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function require_csrf()
{
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('보안 토큰이 올바르지 않습니다. 다시 시도해주세요.');
    }
}

function admin_normalize_category_path(string $path): string
{
    $parts = preg_split('/[>\/]+/u', Markdown::normalize($path)) ?: [];
    $parts = array_map(static function (string $part): string { return trim($part); }, $parts);
    $parts = array_values(array_filter($parts, static function (string $part): bool { return $part !== ''; }));
    return implode('/', $parts);
}

function admin_normalize_category_paths(string $value): array
{
    $rows = preg_split('/\r\n|\r|\n|\|/u', Markdown::normalize($value)) ?: [];
    $paths = [];
    foreach ($rows as $row) {
        $path = admin_normalize_category_path($row);
        if ($path !== '') {
            $paths[] = $path;
        }
    }
    return array_values(array_unique($paths));
}

function admin_apply_category_paths(string $body, string $title, array $categoryPaths): string
{
    $body = Markdown::normalize($body);
    $categoryPaths = array_values(array_unique(array_filter(array_map('admin_normalize_category_path', $categoryPaths))));
    $categoryValue = implode(' | ', $categoryPaths);

    if (!preg_match('/\A---\R(.*?)\R---\R?/s', $body, $match)) {
        return "---\ntitle: {$title}\ncategory_paths: {$categoryValue}\ntags: []\n---\n\n" . ltrim($body);
    }

    $front = (string) $match[1];
    $rest = substr($body, strlen($match[0]));
    if (preg_match('/^category_paths\s*:/mi', $front)) {
        $front = preg_replace('/^category_paths\s*:.*$/mi', 'category_paths: ' . $categoryValue, $front) ?? $front;
    } else {
        $front .= "\ncategory_paths: " . $categoryValue;
    }

    return "---\n" . trim($front) . "\n---\n\n" . ltrim((string) $rest);
}

function admin_file_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes) . ' B';
}

$users = new UserRepository();
$currentUser = isset($_SESSION['user_id']) ? $users->findById((int) $_SESSION['user_id']) : null;
$isAdmin = $currentUser && $currentUser['role'] === 'admin';
$settingsRepo = new SettingsRepository();
$noteRepo = $isAdmin ? new NoteRepository() : null;
$imageRepo = $isAdmin ? new ImageRepository() : null;
$message = null;
$error = null;
$postedAction = '';
$postedTab = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    try {
        require_csrf();
        $action = (string) ($_POST['action'] ?? 'settings');
        $postedAction = $action;
        $postedTab = (string) ($_POST['return_tab'] ?? '');

        if ($action === 'settings') {
            $values = $_POST;
            if (!empty($_FILES['favicon']) && (int) ($_FILES['favicon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (!$imageRepo) {
                    throw new RuntimeException('이미지 저장소를 불러오지 못했습니다.');
                }
                $favicon = $imageRepo->storeUploaded($_FILES['favicon'], 'favicon');
                $values['favicon_path'] = (string) ($favicon['file_url'] ?? '');
            }
            $settingsRepo->update($values);
            $message = '사이트 설정을 저장했습니다.';
        } elseif ($action === 'upload_image') {
            if (!$imageRepo) {
                throw new RuntimeException('이미지 저장소를 불러오지 못했습니다.');
            }
            $imageRepo->storeUploaded($_FILES['image_file'] ?? [], (string) ($_POST['alt_text'] ?? ''));
            $message = '이미지를 WebP로 저장했습니다.';
        } elseif ($action === 'delete_image') {
            if (!$imageRepo) {
                throw new RuntimeException('이미지 저장소를 불러오지 못했습니다.');
            }
            $imageRepo->delete((int) ($_POST['id'] ?? 0));
            $message = '이미지를 삭제했습니다.';
        } elseif ($action === 'save_note') {
            if (!$noteRepo) {
                throw new RuntimeException('문서 저장소를 불러오지 못했습니다.');
            }
            $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
            $title = trim(Markdown::normalize((string) ($_POST['title'] ?? '')));
            $body = Markdown::normalize((string) ($_POST['body'] ?? ''));
            $contentMode = (string) ($_POST['content_mode'] ?? 'markdown');
            $contentType = in_array($contentMode, ['html', 'editor'], true) ? 'html' : 'markdown';
            $categoryPaths = array_merge(
                admin_normalize_category_paths((string) ($_POST['category_paths'] ?? '')),
                array_map('admin_normalize_category_path', (array) ($_POST['category_select'] ?? []))
            );
            $categoryPaths = array_values(array_unique(array_filter($categoryPaths)));
            if ($title === '') {
                throw new RuntimeException('문서 제목을 입력해주세요.');
            }
            if (trim($body) === '') {
                $body = $contentType === 'html' ? '<h2>' . h($title) . '</h2><p></p>' : "---\ntitle: {$title}\ncategory_paths: \ntags: []\n---\n\n# {$title}\n";
            }
            if ($contentType === 'markdown') {
                $body = admin_apply_category_paths($body, $title, $categoryPaths);
            }
            $saved = $noteRepo->save($id, $title, $body, $categoryPaths, $contentType);
            $message = '문서를 저장했습니다: ' . (string) ($saved['title'] ?? $title);
            $_GET['edit_note'] = (string) ($saved['id'] ?? '');
        } elseif ($action === 'delete_note') {
            if (!$noteRepo) {
                throw new RuntimeException('문서 저장소를 불러오지 못했습니다.');
            }
            $noteRepo->delete((int) ($_POST['id'] ?? 0));
            $message = '문서를 삭제했습니다.';
        } elseif ($action === 'save_category') {
            if (!$noteRepo) {
                throw new RuntimeException('카테고리 저장소를 불러오지 못했습니다.');
            }
            $noteRepo->saveCategoryPath((string) ($_POST['category_path'] ?? ''), (int) ($_POST['sort_order'] ?? 0));
            $message = '카테고리를 저장했습니다.';
        } elseif ($action === 'delete_category') {
            if (!$noteRepo) {
                throw new RuntimeException('카테고리 저장소를 불러오지 못했습니다.');
            }
            $noteRepo->deleteCategory((int) ($_POST['id'] ?? 0));
            $message = '카테고리를 삭제했습니다.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$settings = $settingsRepo->all();
$siteName = trim((string) ($settings['site_name'] ?? APP_NAME));
$siteName = $siteName !== '' ? $siteName : APP_NAME;
$faviconPath = trim((string) ($settings['favicon_path'] ?? ''));
$contactMessages = $isAdmin ? $settingsRepo->contactMessages(30) : [];
$mediaAssets = $imageRepo ? $imageRepo->all(80) : [];
$dashboard = $noteRepo ? $noteRepo->dashboard() : null;
$adminNotePage = max(1, (int) ($_GET['note_page'] ?? 1));
$adminNoteLimit = 15;
$adminNoteTotal = $noteRepo ? $noteRepo->countAll(null, null) : 0;
$adminNotePages = max(1, (int) ceil($adminNoteTotal / $adminNoteLimit));
if ($adminNotePage > $adminNotePages) {
    $adminNotePage = $adminNotePages;
}
$adminNotes = $noteRepo ? $noteRepo->all(null, null, $adminNoteLimit, ($adminNotePage - 1) * $adminNoteLimit) : [];
$adminCategories = $noteRepo ? $noteRepo->categories(500) : [];
$editNoteId = isset($_GET['edit_note']) ? (int) $_GET['edit_note'] : 0;
$editNote = $editNoteId > 0 && $noteRepo ? $noteRepo->findById($editNoteId) : null;
$editNoteCategoryPaths = $editNote && $noteRepo ? $noteRepo->noteCategoryPaths((int) $editNote['id']) : [];
$editNoteCategoryValue = implode("\n", $editNoteCategoryPaths);
$editContentType = (string) ($editNote['content_type'] ?? 'markdown');
$editNoteVisits = $editNote && $noteRepo ? $noteRepo->noteDailyVisits((int) $editNote['id'], 30) : [];
$activeAdminTab = 'dashboard';
if ($editNote || $postedAction === 'delete_note' || ($postedAction === 'save_note' && $postedTab === 'documents')) {
    $activeAdminTab = 'documents';
} elseif ($postedAction === 'save_note') {
    $activeAdminTab = 'documents';
} elseif (in_array($postedAction, ['save_category', 'delete_category'], true)) {
    $activeAdminTab = 'categories';
} elseif (in_array($postedAction, ['upload_image', 'delete_image'], true)) {
    $activeAdminTab = 'media';
} elseif ($postedAction === 'settings') {
    $activeAdminTab = $postedTab === 'pages' ? 'pages' : 'general';
} elseif (isset($_GET['note_page'])) {
    $activeAdminTab = 'documents';
}
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>관리자 - <?= h($siteName) ?></title>
    <?php if ($faviconPath !== ''): ?><link rel="icon" href="<?= h($faviconPath) ?>" type="image/webp"><?php endif; ?>
    <script>
        (function () {
            var theme = localStorage.getItem('red-theme') || 'base';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="stylesheet" href="assets/style.css?v=treeview-20260619">
    <link rel="stylesheet" href="assets/admin.css?v=treeview-20260620">
</head>
<body class="admin-layout">
<?php if (!$isAdmin): ?>
    <main class="admin-login-screen">
        <section class="admin-login-card">
            <span class="admin-kicker">TreeView CMS</span>
            <h1>관리자 로그인 필요</h1>
            <p>메인 페이지의 계정 버튼에서 관리자 계정으로 로그인한 뒤 다시 접속하세요.</p>
            <a class="button primary" href="index.php">메인으로 이동</a>
        </section>
    </main>
<?php else: ?>
    <div class="admin-console">
        <input class="admin-tab-input" type="radio" name="admin_tab" id="tab-dashboard" <?= $activeAdminTab === 'dashboard' ? 'checked' : '' ?>>
        <input class="admin-tab-input" type="radio" name="admin_tab" id="tab-create" <?= $activeAdminTab === 'create' ? 'checked' : '' ?>>
        <input class="admin-tab-input" type="radio" name="admin_tab" id="tab-documents" <?= $activeAdminTab === 'documents' ? 'checked' : '' ?>>
        <input class="admin-tab-input" type="radio" name="admin_tab" id="tab-categories" <?= $activeAdminTab === 'categories' ? 'checked' : '' ?>>
        <input class="admin-tab-input" type="radio" name="admin_tab" id="tab-media" <?= $activeAdminTab === 'media' ? 'checked' : '' ?>>
        <input class="admin-tab-input" type="radio" name="admin_tab" id="tab-general" <?= $activeAdminTab === 'general' ? 'checked' : '' ?>>
        <input class="admin-tab-input" type="radio" name="admin_tab" id="tab-pages" <?= $activeAdminTab === 'pages' ? 'checked' : '' ?>>
        <input class="admin-tab-input" type="radio" name="admin_tab" id="tab-inbox" <?= $activeAdminTab === 'inbox' ? 'checked' : '' ?>>

        <aside class="admin-sidebar">
            <a class="admin-brand" href="admin.php">
                <strong><?= h($siteName) ?></strong>
                <span>Admin Console</span>
            </a>
            <nav class="admin-tab-list" aria-label="관리자 메뉴">
                <label for="tab-dashboard">대시보드</label>
                <label for="tab-create">문서 등록</label>
                <label for="tab-documents">문서 관리</label>
                <label for="tab-categories">카테고리 관리</label>
                <label for="tab-media">이미지 관리</label>
                <label for="tab-general">기본 설정</label>
                <label for="tab-pages">페이지 문구</label>
                <label for="tab-inbox">문의함</label>
            </nav>
            <div class="admin-sidebar-card">
                <span>관리자</span>
                <strong><?= h((string) $currentUser['display_name']) ?></strong>
                <em><?= h((string) $settings['contact_email']) ?></em>
            </div>
        </aside>

        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <span>관리자</span>
                    <strong>운영 대시보드</strong>
                </div>
                <nav>
                    <a class="button" href="index.php">홈</a>
                    <a class="button" href="index.php?dashboard=1">공개 대시보드</a>
                    <a class="button" href="index.php?page=contact">Contact</a>
                </nav>
            </header>

            <?php if ($message): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice danger"><?= h($error) ?></div><?php endif; ?>

            <section class="admin-hero">
                <div>
                    <span class="admin-kicker"><?= h($siteName) ?></span>
                    <h1>사이트 관리</h1>
                    <p>문서 현황, 방문 흐름, 정책 문구, Contact 문의를 한 화면에서 관리합니다.</p>
                </div>
                <div class="admin-hero-metrics">
                    <span><b><?= $dashboard ? number_format((int) $dashboard['summary']['documents']) : '0' ?></b> 문서</span>
                    <span><b><?= number_format(count($contactMessages)) ?></b> 문의</span>
                </div>
            </section>

            <section class="admin-tab-panel admin-panel-dashboard">
                <?php if ($dashboard): ?>
                    <div class="admin-dashboard-stats">
                        <div><span>전체 문서</span><strong><?= number_format((int) $dashboard['summary']['documents']) ?></strong><em>등록된 문서</em></div>
                        <div><span>전체 조회수</span><strong><?= number_format((int) $dashboard['summary']['views']) ?></strong><em>누적 조회</em></div>
                        <div><span>오늘 방문자</span><strong><?= number_format((int) $dashboard['summary']['today_visitors']) ?></strong><em>고유 방문</em></div>
                        <div><span>오늘 페이지뷰</span><strong><?= number_format((int) $dashboard['summary']['today_pageviews']) ?></strong><em>방문 로그</em></div>
                        <div><span>태그</span><strong><?= number_format((int) $dashboard['summary']['tags']) ?></strong><em>분류 키워드</em></div>
                        <div><span>문서 링크</span><strong><?= number_format((int) $dashboard['summary']['links']) ?></strong><em>연결 관계</em></div>
                        <div><span>좋아요</span><strong><?= number_format((int) $dashboard['summary']['likes']) ?></strong><em>문서 반응</em></div>
                    </div>

                    <div class="admin-dashboard-grid">
                        <section class="admin-data-card">
                            <div class="admin-card-head"><h2>인기 문서</h2><span>Top <?= count($dashboard['topViewed']) ?></span></div>
                            <div class="admin-table-list">
                                <?php foreach ($dashboard['topViewed'] as $index => $note): ?>
                                    <a href="index.php?note=<?= rawurlencode($note['slug']) ?>">
                                        <b><?= $index + 1 ?></b>
                                        <span><strong><?= h((string) $note['title']) ?></strong><em><?= h(date('Y-m-d', strtotime((string) $note['updated_at']))) ?></em></span>
                                        <i><?= number_format((int) $note['views']) ?> views</i>
                                    </a>
                                <?php endforeach; ?>
                                <?php if ($dashboard['topViewed'] === []): ?><p class="muted">아직 문서가 없습니다.</p><?php endif; ?>
                            </div>
                        </section>

                        <section class="admin-data-card">
                            <div class="admin-card-head"><h2>최근 수정 문서</h2><span>Latest</span></div>
                            <div class="admin-table-list">
                                <?php foreach ($dashboard['recent'] as $note): ?>
                                    <a href="index.php?note=<?= rawurlencode($note['slug']) ?>">
                                        <b><?= h(date('m.d', strtotime((string) $note['updated_at']))) ?></b>
                                        <span><strong><?= h((string) $note['title']) ?></strong><em><?= h((string) ($note['excerpt'] ?? '')) ?></em></span>
                                        <i><?= number_format((int) $note['views']) ?> views</i>
                                    </a>
                                <?php endforeach; ?>
                                <?php if ($dashboard['recent'] === []): ?><p class="muted">아직 문서가 없습니다.</p><?php endif; ?>
                            </div>
                        </section>

                        <section class="admin-data-card wide">
                            <div class="admin-card-head"><h2>최근 30일 방문 추이</h2><span>Visitors / Pageviews</span></div>
                            <div class="admin-visit-chart">
                                <?php
                                $maxVisit = 1;
                                foreach ($dashboard['dailyVisits'] as $visitItem) {
                                    $maxVisit = max($maxVisit, (int) $visitItem['visitors'], (int) $visitItem['pageviews']);
                                }
                                ?>
                                <?php foreach ($dashboard['dailyVisits'] as $item): ?>
                                    <div>
                                        <span><?= h(date('m.d', strtotime((string) $item['visit_date']))) ?></span>
                                        <i style="width: <?= max(4, round(((int) $item['pageviews'] / $maxVisit) * 100)) ?>%"></i>
                                        <b style="width: <?= max(4, round(((int) $item['visitors'] / $maxVisit) * 100)) ?>%"></b>
                                        <em>방문 <?= number_format((int) $item['visitors']) ?> · 조회 <?= number_format((int) $item['pageviews']) ?></em>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($dashboard['dailyVisits'] === []): ?><p class="muted">아직 방문 데이터가 없습니다.</p><?php endif; ?>
                            </div>
                        </section>

                        <section class="admin-data-card">
                            <div class="admin-card-head"><h2>인기 태그</h2><span>Tags</span></div>
                            <div class="admin-tag-cloud">
                                <?php foreach ($dashboard['topTags'] as $item): ?>
                                    <a href="index.php?tag=<?= rawurlencode($item['name']) ?>"><span>#<?= h((string) $item['name']) ?></span><b><?= number_format((int) $item['count']) ?></b></a>
                                <?php endforeach; ?>
                                <?php if ($dashboard['topTags'] === []): ?><p class="muted">아직 태그가 없습니다.</p><?php endif; ?>
                            </div>
                        </section>

                        <section class="admin-data-card">
                            <div class="admin-card-head"><h2>검색 흐름</h2><span>Search</span></div>
                            <div class="admin-search-stack">
                                <strong>인기 검색어</strong>
                                <?php foreach ($dashboard['topSearches'] as $item): ?>
                                    <a href="<?= $item['search_type'] === 'tag' ? 'index.php?tag=' . rawurlencode($item['keyword']) : 'index.php?q=' . rawurlencode($item['keyword']) ?>">
                                        <span><?= $item['search_type'] === 'tag' ? '#' : '' ?><?= h((string) $item['keyword']) ?></span>
                                        <b><?= number_format((int) $item['count']) ?>회</b>
                                    </a>
                                <?php endforeach; ?>
                                <strong>최근 검색</strong>
                                <?php foreach ($dashboard['recentSearches'] as $item): ?>
                                    <a href="<?= $item['search_type'] === 'tag' ? 'index.php?tag=' . rawurlencode($item['keyword']) : 'index.php?q=' . rawurlencode($item['keyword']) ?>">
                                        <span><?= h(date('H:i', strtotime((string) $item['created_at']))) ?> · <?= $item['search_type'] === 'tag' ? '#' : '' ?><?= h((string) $item['keyword']) ?></span>
                                        <b><?= number_format((int) $item['result_count']) ?>개</b>
                                    </a>
                                <?php endforeach; ?>
                                <?php if ($dashboard['topSearches'] === [] && $dashboard['recentSearches'] === []): ?><p class="muted">아직 검색 기록이 없습니다.</p><?php endif; ?>
                            </div>
                        </section>
                    </div>
                <?php endif; ?>
            </section>

            <section class="admin-tab-panel admin-panel-create">
                <div class="admin-section-head">
                    <div><span class="admin-kicker">Create</span><h2>문서 등록</h2></div>
                    <span>등록된 카테고리를 선택하거나 직접 경로를 입력할 수 있습니다.</span>
                </div>
                <form method="post" class="admin-editor-card admin-wide-editor">
                    <input type="hidden" name="action" value="save_note">
                    <input type="hidden" name="return_tab" value="create">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <label><span>문서 제목</span><input type="text" name="title" required></label>
                    <label><span>문서 형식</span><select name="content_mode" data-content-mode><option value="markdown">마크다운</option><option value="html">HTML</option><option value="editor">에디터</option></select></label>
                    <label><span>등록된 카테고리 선택</span><select name="category_select[]" multiple size="8"><?php foreach ($adminCategories as $category): ?><option value="<?= h((string) $category['path']) ?>"><?= h((string) $category['path']) ?></option><?php endforeach; ?></select></label>
                    <label><span>카테고리 직접 입력</span><textarea name="category_paths" rows="3" placeholder="웹개발/백엔드&#10;CMS/문서관리"></textarea></label>
                    <label><span data-editor-label>본문 마크다운</span><textarea class="admin-code-textarea" data-rich-editor name="body" rows="20" spellcheck="false">---
title: 
category_paths: 
tags: []
---

# 

## 요약

</textarea></label>
                    <p class="admin-security-note">HTML과 에디터 문서는 저장 시 허용 태그만 남기고 script, iframe, 이벤트 속성, javascript URL은 제거됩니다.</p>
                    <div class="admin-form-actions"><button class="button primary" type="submit">문서 등록</button></div>
                </form>
            </section>

            <section class="admin-tab-panel admin-panel-documents">
                <div class="admin-section-head">
                    <div><span class="admin-kicker">Documents</span><h2>문서 관리</h2></div>
                    <label class="admin-inline-search"><span>페이지</span><select onchange="location.href='admin.php?note_page=' + this.value"><?php for ($page = 1; $page <= $adminNotePages; $page++): ?><option value="<?= $page ?>" <?= $page === $adminNotePage ? 'selected' : '' ?>><?= $page ?> / <?= $adminNotePages ?></option><?php endfor; ?></select></label>
                </div>
                <div class="admin-split-grid">
                    <section class="admin-data-card">
                        <div class="admin-card-head"><h2>문서 목록</h2><span><?= number_format($adminNoteTotal) ?>개</span></div>
                        <div class="admin-table-list">
                            <?php foreach ($adminNotes as $note): ?>
                                <div class="admin-manage-row">
                                    <a href="admin.php?edit_note=<?= (int) $note['id'] ?>&note_page=<?= $adminNotePage ?>">
                                        <b><?= h(date('m.d', strtotime((string) $note['updated_at']))) ?></b>
                                        <span><strong><?= h((string) $note['title']) ?></strong><em><?= h((string) ($note['excerpt'] ?? '')) ?></em></span>
                                        <i><?= number_format((int) ($note['views'] ?? 0)) ?> views</i>
                                    </a>
                                    <form method="post" onsubmit="return confirm('이 문서를 삭제할까요?');">
                                        <input type="hidden" name="action" value="delete_note">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $note['id'] ?>">
                                        <button class="button danger" type="submit">삭제</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($adminNotes === []): ?><p class="muted">아직 문서가 없습니다.</p><?php endif; ?>
                        </div>
                        <div class="admin-pagination">
                            <?php for ($page = 1; $page <= $adminNotePages; $page++): ?>
                                <a class="<?= $page === $adminNotePage ? 'active' : '' ?>" href="admin.php?note_page=<?= $page ?>"><?= $page ?></a>
                            <?php endfor; ?>
                        </div>
                    </section>

                    <section class="admin-data-card">
                        <?php if ($editNote): ?>
                            <div class="admin-card-head"><h2><?= h((string) $editNote['title']) ?></h2><span>상세 관리</span></div>
                            <div class="admin-note-insight">
                                <div><span>조회수</span><strong><?= number_format((int) ($editNote['views'] ?? 0)) ?></strong></div>
                                <div><span>최종 수정</span><strong><?= h(date('Y-m-d', strtotime((string) $editNote['updated_at']))) ?></strong></div>
                                <div><span>형식</span><strong><?= h($editContentType === 'html' ? 'HTML' : 'Markdown') ?></strong></div>
                            </div>
                            <div class="admin-card-head compact"><h2>문서별 방문 그래프</h2><span>최근 30일</span></div>
                            <div class="admin-visit-chart">
                                <?php $maxNoteVisit = 1; foreach ($editNoteVisits as $visitItem) { $maxNoteVisit = max($maxNoteVisit, (int) $visitItem['visitors'], (int) $visitItem['pageviews']); } ?>
                                <?php foreach ($editNoteVisits as $item): ?>
                                    <div>
                                        <span><?= h(date('m.d', strtotime((string) $item['visit_date']))) ?></span>
                                        <i style="width: <?= max(4, round(((int) $item['pageviews'] / $maxNoteVisit) * 100)) ?>%"></i>
                                        <b style="width: <?= max(4, round(((int) $item['visitors'] / $maxNoteVisit) * 100)) ?>%"></b>
                                        <em>방문 <?= number_format((int) $item['visitors']) ?> · 조회 <?= number_format((int) $item['pageviews']) ?></em>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($editNoteVisits === []): ?><p class="muted">아직 이 문서의 방문 데이터가 없습니다.</p><?php endif; ?>
                            </div>
                            <form method="post" class="admin-editor-card embedded">
                                <input type="hidden" name="action" value="save_note">
                                <input type="hidden" name="return_tab" value="documents">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $editNote['id'] ?>">
                                <label><span>문서 제목</span><input type="text" name="title" value="<?= h((string) $editNote['title']) ?>" required></label>
                                <label><span>문서 형식</span><select name="content_mode" data-content-mode><option value="markdown" <?= $editContentType !== 'html' ? 'selected' : '' ?>>마크다운</option><option value="html" <?= $editContentType === 'html' ? 'selected' : '' ?>>HTML</option><option value="editor">에디터</option></select></label>
                                <label><span>등록된 카테고리 선택</span><select name="category_select[]" multiple size="8"><?php foreach ($adminCategories as $category): ?><option value="<?= h((string) $category['path']) ?>" <?= in_array((string) $category['path'], $editNoteCategoryPaths, true) ? 'selected' : '' ?>><?= h((string) $category['path']) ?></option><?php endforeach; ?></select></label>
                                <label><span>카테고리 직접 입력</span><textarea name="category_paths" rows="3"><?= h($editNoteCategoryValue) ?></textarea></label>
                                <label><span data-editor-label><?= $editContentType === 'html' ? '본문 HTML' : '본문 마크다운' ?></span><textarea class="admin-code-textarea" data-rich-editor name="body" rows="18" spellcheck="false"><?= h((string) $editNote['body']) ?></textarea></label>
                                <p class="admin-security-note">HTML과 에디터 문서는 저장 시 허용 태그만 남기고 script, iframe, 이벤트 속성, javascript URL은 제거됩니다.</p>
                                <div class="admin-form-actions"><button class="button primary" type="submit">내용 수정</button><a class="button" href="index.php?note=<?= rawurlencode((string) $editNote['slug']) ?>">문서 보기</a></div>
                            </form>
                        <?php else: ?>
                            <div class="admin-empty-detail">
                                <h2>문서를 선택해주세요</h2>
                                <p>왼쪽 목록에서 문서를 선택하면 방문 그래프와 수정 화면이 표시됩니다.</p>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </section>

            <section class="admin-tab-panel admin-panel-categories">
                <div class="admin-section-head">
                    <div><span class="admin-kicker">Categories</span><h2>카테고리 관리</h2></div>
                    <span>슬래시(/)로 하위 카테고리를 만듭니다.</span>
                </div>
                <div class="admin-split-grid">
                    <form method="post" class="admin-editor-card">
                        <input type="hidden" name="action" value="save_category">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <label><span>카테고리 경로</span><input type="text" name="category_path" placeholder="웹개발/백엔드/PHP" required></label>
                        <label><span>정렬 순서</span><input type="number" name="sort_order" value="0"></label>
                        <div class="admin-form-actions"><button class="button primary" type="submit">카테고리 저장</button></div>
                    </form>
                    <section class="admin-data-card">
                        <div class="admin-card-head"><h2>카테고리 목록</h2><span><?= number_format(count($adminCategories)) ?>개</span></div>
                        <div class="admin-category-list">
                            <?php foreach ($adminCategories as $category): ?>
                                <div>
                                    <a href="index.php?cat=<?= rawurlencode((string) $category['path']) ?>">
                                        <strong><?= h(str_repeat('· ', (int) $category['depth']) . (string) $category['name']) ?></strong>
                                        <span><?= h((string) $category['path']) ?></span>
                                        <em><?= number_format((int) $category['note_count']) ?> 문서</em>
                                    </a>
                                    <form method="post" onsubmit="return confirm('이 카테고리를 삭제할까요? 연결된 문서의 카테고리 연결도 해제됩니다.');">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                                        <button class="button danger" type="submit">삭제</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($adminCategories === []): ?><p class="muted">아직 카테고리가 없습니다.</p><?php endif; ?>
                        </div>
                    </section>
                </div>
            </section>

            <section class="admin-tab-panel admin-panel-media">
                <div class="admin-section-head">
                    <div><span class="admin-kicker">Media</span><h2>이미지 관리</h2></div>
                    <span>업로드 이미지는 WebP로 변환되어 저장됩니다.</span>
                </div>
                <div class="admin-split-grid">
                    <form method="post" class="admin-editor-card" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_image">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <label><span>이미지 파일</span><input type="file" name="image_file" accept="image/png,image/jpeg,image/gif,image/webp" required></label>
                        <label><span>대체 텍스트</span><input type="text" name="alt_text" maxlength="190" placeholder="이미지 설명"></label>
                        <p class="admin-security-note">jpg, png, gif, webp 파일을 업로드할 수 있습니다. 저장 파일은 문서에서 바로 사용할 수 있도록 WebP로 변환됩니다.</p>
                        <div class="admin-form-actions"><button class="button primary" type="submit">이미지 업로드</button></div>
                    </form>
                    <section class="admin-data-card">
                        <div class="admin-card-head"><h2>이미지 현황</h2><span><?= number_format(count($mediaAssets)) ?>개</span></div>
                        <div class="admin-note-insight">
                            <div><span>최근 이미지</span><strong><?= number_format(count($mediaAssets)) ?></strong></div>
                            <div><span>파비콘</span><strong><?= $faviconPath !== '' ? '설정됨' : '미설정' ?></strong></div>
                            <div><span>저장 방식</span><strong>WebP</strong></div>
                        </div>
                    </section>
                </div>
                <div class="admin-media-grid">
                    <?php foreach ($mediaAssets as $asset): ?>
                        <?php
                        $assetUrl = (string) ($asset['file_url'] ?? '');
                        $assetAlt = (string) ($asset['alt_text'] ?? '');
                        $markdownImage = '![' . $assetAlt . '](' . $assetUrl . ')';
                        ?>
                        <article class="admin-media-card">
                            <img src="<?= h($assetUrl) ?>" alt="<?= h($assetAlt) ?>" loading="lazy">
                            <div>
                                <strong><?= h((string) ($asset['original_name'] ?? 'image.webp')) ?></strong>
                                <span><?= h((string) ($asset['width'] ?? 0)) ?>x<?= h((string) ($asset['height'] ?? 0)) ?> · <?= admin_file_size((int) ($asset['size_bytes'] ?? 0)) ?></span>
                            </div>
                            <label><span>URL</span><input type="text" value="<?= h($assetUrl) ?>" readonly onclick="this.select()"></label>
                            <label><span>Markdown</span><input type="text" value="<?= h($markdownImage) ?>" readonly onclick="this.select()"></label>
                            <form method="post" onsubmit="return confirm('이 이미지를 삭제할까요? 문서에서 사용 중이면 이미지가 보이지 않습니다.');">
                                <input type="hidden" name="action" value="delete_image">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $asset['id'] ?>">
                                <button class="button danger" type="submit">삭제</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($mediaAssets === []): ?><p class="muted">아직 업로드된 이미지가 없습니다.</p><?php endif; ?>
                </div>
            </section>

            <section class="admin-tab-panel admin-panel-general">
                <div class="admin-section-head">
                    <div><span class="admin-kicker">General</span><h2>공통 설정</h2></div>
                    <span>사이트 이름, 파비콘, 푸터와 Contact 기본값</span>
                </div>
                <form method="post" class="admin-field-grid" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="settings">
                    <input type="hidden" name="return_tab" value="general">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="favicon_path" value="<?= h($faviconPath) ?>">
                    <label><span>사이트 이름</span><input type="text" name="site_name" value="<?= h($siteName) ?>" maxlength="80" required></label>
                    <label><span>파비콘 이미지</span><input type="file" name="favicon" accept="image/png,image/jpeg,image/gif,image/webp"></label>
                    <?php if ($faviconPath !== ''): ?><div class="admin-favicon-preview"><img src="<?= h($faviconPath) ?>" alt="favicon"><span><?= h($faviconPath) ?></span></div><?php endif; ?>
                    <label><span>Contact 이메일</span><input type="email" name="contact_email" value="<?= h($settings['contact_email']) ?>" required></label>
                    <label><span>사이드바 방문자 위젯</span><select name="show_sidebar_visitors"><option value="1" <?= ($settings['show_sidebar_visitors'] ?? '1') === '1' ? 'selected' : '' ?>>켜기</option><option value="0" <?= ($settings['show_sidebar_visitors'] ?? '1') === '0' ? 'selected' : '' ?>>끄기</option></select></label>
                    <label><span>상단 대시보드 버튼</span><select name="show_top_dashboard"><option value="1" <?= ($settings['show_top_dashboard'] ?? '1') === '1' ? 'selected' : '' ?>>켜기</option><option value="0" <?= ($settings['show_top_dashboard'] ?? '1') === '0' ? 'selected' : '' ?>>끄기</option></select></label>
                    <label><span>푸터 요약</span><textarea name="site_summary" rows="4" required><?= h($settings['site_summary']) ?></textarea></label>
                    <label class="wide"><span>푸터 하단 안내</span><textarea name="footer_note" rows="4" required><?= h($settings['footer_note']) ?></textarea></label>
                    <div class="admin-form-actions"><button class="button primary" type="submit">설정 저장</button></div>
                </form>
            </section>

            <section class="admin-tab-panel admin-panel-pages">
                <div class="admin-section-head">
                    <div><span class="admin-kicker">Content</span><h2>페이지 문구</h2></div>
                    <span>푸터 정책 페이지</span>
                </div>
                <form method="post" class="admin-page-editors">
                    <input type="hidden" name="action" value="settings">
                    <input type="hidden" name="return_tab" value="pages">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="site_name" value="<?= h($siteName) ?>">
                    <input type="hidden" name="favicon_path" value="<?= h($faviconPath) ?>">
                    <input type="hidden" name="contact_email" value="<?= h($settings['contact_email']) ?>">
                    <input type="hidden" name="show_sidebar_visitors" value="<?= h((string) ($settings['show_sidebar_visitors'] ?? '1')) ?>">
                    <input type="hidden" name="show_top_dashboard" value="<?= h((string) ($settings['show_top_dashboard'] ?? '1')) ?>">
                    <input type="hidden" name="site_summary" value="<?= h($settings['site_summary']) ?>">
                    <input type="hidden" name="footer_note" value="<?= h($settings['footer_note']) ?>">
                    <label><span>About</span><textarea name="about_body" rows="8" required><?= h($settings['about_body']) ?></textarea></label>
                    <label><span>이용방침</span><textarea name="terms_body" rows="10" required><?= h($settings['terms_body']) ?></textarea></label>
                    <label><span>개인정보처리방침</span><textarea name="privacy_body" rows="10" required><?= h($settings['privacy_body']) ?></textarea></label>
                    <label><span>Contact 안내</span><textarea name="contact_intro" rows="6" required><?= h($settings['contact_intro']) ?></textarea></label>
                    <div class="admin-form-actions"><button class="button primary" type="submit">문구 저장</button></div>
                </form>
            </section>

            <section class="admin-tab-panel admin-panel-inbox">
                <div class="admin-section-head">
                    <div><span class="admin-kicker">Inbox</span><h2>최근 문의</h2></div>
                    <a class="button" href="mailto:<?= h($settings['contact_email']) ?>">메일 열기</a>
                </div>
                <div class="admin-inbox-list">
                    <?php if ($contactMessages === []): ?>
                        <p class="muted">아직 접수된 문의가 없습니다.</p>
                    <?php endif; ?>
                    <?php foreach ($contactMessages as $item): ?>
                        <article class="contact-message">
                            <div>
                                <strong><?= h((string) $item['subject']) ?></strong>
                                <span><?= h((string) $item['name']) ?> · <a href="mailto:<?= h((string) $item['email']) ?>"><?= h((string) $item['email']) ?></a> · <?= h(date('Y-m-d H:i', (int) strtotime((string) $item['created_at']))) ?></span>
                            </div>
                            <p><?= nl2br(h((string) $item['message'])) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    (function () {
        function enableEditor(textarea) {
            if (window.tinymce && !tinymce.get(textarea.id)) {
                if (!textarea.id) {
                    textarea.id = 'adminBodyEditor' + Math.random().toString(16).slice(2);
                }
                tinymce.init({
                    selector: '#' + textarea.id,
                    base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
                    suffix: '.min',
                    menubar: false,
                    branding: false,
                    promotion: false,
                    height: 520,
                    plugins: 'lists link image table code codesample autoresize',
                    toolbar: 'undo redo | blocks | bold italic underline | bullist numlist blockquote | link image table | code',
                    valid_elements: 'p,br,hr,strong/b,em/i,u,s,mark,small,h2,h3,h4,h5,h6,ul,ol,li,blockquote,pre,code[class],table,thead,tbody,tr,th[colspan|rowspan],td[colspan|rowspan],a[href|target|rel|title],img[src|alt|width|height|loading],figure,figcaption,div[class|title],span[class|title]',
                    invalid_elements: 'script,iframe,object,embed,form,input,button,textarea,select,style,link,meta',
                    convert_urls: false,
                    setup: function (editor) {
                        editor.on('change keyup', function () {
                            editor.save();
                        });
                    }
                });
            }
        }

        function disableEditor(textarea) {
            if (window.tinymce) {
                var editor = textarea.id ? tinymce.get(textarea.id) : null;
                if (editor) {
                    editor.save();
                    editor.remove();
                }
            }
        }

        document.querySelectorAll('[data-content-mode]').forEach(function (mode) {
            var form = mode.closest('form');
            var textarea = form ? form.querySelector('[data-rich-editor]') : null;
            var label = form ? form.querySelector('[data-editor-label]') : null;
            if (!textarea) {
                return;
            }
            if (!textarea.id) {
                textarea.id = 'adminBodyEditor' + Math.random().toString(16).slice(2);
            }

            function syncMode() {
                if (label) {
                    label.textContent = mode.value === 'markdown' ? '본문 마크다운' : '본문 HTML';
                }
                if (mode.value === 'editor') {
                    enableEditor(textarea);
                } else {
                    disableEditor(textarea);
                }
            }

            mode.addEventListener('change', syncMode);
            syncMode();
        });

        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                if (window.tinymce) {
                    tinymce.triggerSave();
                }
            });
        });
    })();
</script>
</body>
</html>
