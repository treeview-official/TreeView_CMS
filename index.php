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

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set(APP_TIMEZONE);

function h(?string $value): string
{
    return htmlspecialchars(html_entity_decode(Markdown::normalize((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_admin(): bool
{
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
            throw new RuntimeException('보안 토큰이 올바르지 않습니다. 다시 시도해주세요.');
    }
}

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = defined('BASE_PATH') ? trim((string) BASE_PATH) : '';
    $basePath = $basePath === '/' ? '' : '/' . trim($basePath, '/');
    return $scheme . '://' . $host . $basePath;
}

function note_url(string $slug): string
{
    return base_url() . '/index.php?note=' . rawurlencode($slug);
}

function url_with(array $changes): string
{
    $params = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    $query = http_build_query($params);
    return 'index.php' . ($query !== '' ? '?' . $query : '');
}

function description_from(?array $note): string
{
    if (!$note) {
        return '문서, 태그, 백링크, 검색, 3D 그래프로 연결되는 지식 베이스입니다.';
    }
    $text = trim(strip_tags(Markdown::publicBody((string) $note['body'])));
    return mb_substr($text !== '' ? $text : (string) $note['title'], 0, 155, 'UTF-8');
}

function note_excerpt(array $note): string
{
    if (isset($note['body'])) {
        $text = trim(strip_tags(Markdown::publicBody((string) $note['body'])));
        if ($text !== '') {
            return mb_substr($text, 0, 220, 'UTF-8');
        }
    }
    return (string) ($note['excerpt'] ?? '');
}

function sidebar_tags(array $tags, int $limit = 14): array
{
    usort($tags, static function (array $a, array $b): int {
        $count = (int) $b['count'] <=> (int) $a['count'];
        return $count !== 0 ? $count : strcmp((string) $a['name'], (string) $b['name']);
    });
    return array_slice($tags, 0, $limit);
}

function skill_tags(array $currentTags, array $allTags, int $limit = 8): array
{
    $skip = ['대범주', '분류', '카테고리', 'category', 'categories'];
    $skills = [];

    foreach ($currentTags as $name) {
        if (!in_array(mb_strtolower((string) $name, 'UTF-8'), $skip, true)) {
            $skills[] = ['name' => (string) $name, 'count' => null];
        }
    }

    if ($skills === []) {
        foreach (sidebar_tags($allTags, $limit) as $tag) {
            if (!in_array(mb_strtolower((string) $tag['name'], 'UTF-8'), $skip, true)) {
                $skills[] = $tag;
            }
        }
    }

    return array_slice($skills, 0, $limit);
}

function normalize_category_path(string $path): string
{
    $parts = preg_split('/[>\/]+/u', Markdown::normalize($path)) ?: [];
    $parts = array_map(static function (string $part): string {
        return trim($part);
    }, $parts);
    $parts = array_values(array_filter($parts, static function (string $part): bool {
        return $part !== '';
    }));
    return implode('/', $parts);
}

function normalize_category_paths(string $value): array
{
    $rows = preg_split('/\r\n|\r|\n|\|/u', Markdown::normalize($value)) ?: [];
    $paths = [];
    foreach ($rows as $row) {
        $path = normalize_category_path($row);
        if ($path !== '') {
            $paths[] = $path;
        }
    }
    return array_values(array_unique($paths));
}

function note_category_paths(array $note): array
{
    $meta = Markdown::metadata((string) ($note['body'] ?? ''));
    $paths = [];
    if (isset($meta['category_paths'])) {
        $paths = normalize_category_paths((string) $meta['category_paths']);
    }
    if ($paths === [] && isset($meta['category_path'])) {
        $paths = normalize_category_paths((string) $meta['category_path']);
    }
    if ($paths === [] && isset($meta['category'])) {
        $paths = normalize_category_paths((string) $meta['category']);
    }
    return $paths;
}

function note_category_path(array $note): string
{
    return note_category_paths($note)[0] ?? '';
}

function apply_category_paths(string $body, string $title, array $categoryPaths): string
{
    $body = Markdown::normalize($body);
    $categoryPaths = array_values(array_unique(array_filter(array_map('normalize_category_path', $categoryPaths))));
    $categoryValue = implode(' | ', $categoryPaths);

    if (!preg_match('/^---(?:\r\n|\r|\n)(.*?)(?:\r\n|\r|\n)---(?:\r\n|\r|\n)?/s', $body)) {
        $front = "---\ntitle: {$title}\n";
        if ($categoryValue !== '') {
            $front .= "category_paths: {$categoryValue}\n";
        }
        $front .= "tags: []\n---\n\n";
        return $front . ltrim($body);
    }

    return preg_replace_callback('/^---(?:\r\n|\r|\n)(.*?)(?:\r\n|\r|\n)---(?:\r\n|\r|\n)?/s', static function (array $matches) use ($categoryValue): string {
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $matches[1])) ?: [];
        $next = [];
        $hasCategory = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*category_paths?\s*:/i', $line)) {
                if (!$hasCategory && $categoryValue !== '') {
                    $next[] = 'category_paths: ' . $categoryValue;
                }
                $hasCategory = true;
                continue;
            }
            $next[] = $line;
        }

        if (!$hasCategory && $categoryValue !== '') {
            array_splice($next, 1, 0, 'category_paths: ' . $categoryValue);
        }

        return "---\n" . implode("\n", $next) . "\n---\n\n";
    }, $body, 1) ?? $body;
}

function category_items(array $notes, string $prefix = '', int $limit = 8): array
{
    $prefix = normalize_category_path($prefix);
    $prefixParts = $prefix === '' ? [] : explode('/', $prefix);
    $items = [];

    foreach ($notes as $note) {
        $path = note_category_path($note);
        if ($path === '') {
            continue;
        }
        $parts = explode('/', $path);
        if ($prefixParts !== array_slice($parts, 0, count($prefixParts))) {
            continue;
        }
        $next = $parts[count($prefixParts)] ?? null;
        if ($next === null || $next === '') {
            continue;
        }
        $itemPath = implode('/', array_merge($prefixParts, [$next]));
        if (!isset($items[$itemPath])) {
            $items[$itemPath] = ['name' => $next, 'path' => $itemPath, 'count' => 0];
        }
        $items[$itemPath]['count']++;
    }

    usort($items, static function (array $a, array $b): int {
        $count = (int) $b['count'] <=> (int) $a['count'];
        return $count !== 0 ? $count : strcmp((string) $a['name'], (string) $b['name']);
    });

    return array_slice($items, 0, $limit);
}

function notes_in_category(array $notes, string $categoryPath): array
{
    $categoryPath = normalize_category_path($categoryPath);
    if ($categoryPath === '') {
        return $notes;
    }
    return array_values(array_filter($notes, static function (array $note) use ($categoryPath): bool {
        $path = note_category_path($note);
        return $path === $categoryPath || strpos($path, $categoryPath . '/') === 0;
    }));
}

function daily_visitor_hash(): string
{
    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $ip = trim(explode(',', $forwarded)[0] ?: (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return hash('sha256', date('Y-m-d') . '|' . $ip . '|' . $agent . '|' . ADMIN_PASSWORD);
}

function render_setting_text(string $value): string
{
    $paragraphs = preg_split('/\R{2,}/u', trim($value)) ?: [];
    $html = '';
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph !== '') {
            $html .= '<p>' . h($paragraph) . '</p>';
        }
    }
    return $html;
}

$repo = new NoteRepository();
$settings = null;
$users = null;
$currentUser = null;
$error = null;
$message = null;
$allowRegistration = false;

try {
    Database::pdo()->query('SELECT 1 FROM notes LIMIT 1');
    $settings = new SettingsRepository();
    $users = new UserRepository();
    if (isset($_SESSION['user_id'])) {
        $currentUser = $users->findById((int) $_SESSION['user_id']);
        if (!$currentUser) {
            session_destroy();
            session_start();
        } else {
            $_SESSION['user_role'] = $currentUser['role'];
            $_SESSION['user_name'] = $currentUser['display_name'];
        }
    }
    $allowRegistration = $users->countUsers() === 0;
} catch (Throwable $e) {
    header('Location: install.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        require_csrf();
        if ($action === 'contact') {
            if (!$settings) {
                throw new RuntimeException('사이트 설정을 불러오지 못했습니다.');
            }
            $settings->saveContactMessage(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['subject'] ?? ''),
                (string) ($_POST['message'] ?? '')
            );
            $message = '문의가 접수되었습니다.';
        }
        if ($action === 'login') {
            $user = $users->login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['display_name'];
            header('Location: index.php');
            exit;
        }
        if ($action === 'register') {
            if (!$users || $users->countUsers() > 0) {
                throw new RuntimeException('회원가입은 첫 관리자 계정 생성 시에만 사용할 수 있습니다.');
            }
            $user = $users->register((string) ($_POST['email'] ?? ''), (string) ($_POST['display_name'] ?? ''), (string) ($_POST['password'] ?? ''));
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['display_name'];
            header('Location: index.php');
            exit;
        }
        if ($action === 'logout') {
            session_destroy();
            header('Location: index.php');
            exit;
        }
        if (!is_admin() && in_array($action, ['save', 'delete'], true)) {
            throw new RuntimeException('관리자 계정이 필요합니다.');
        }
        if ($action === 'save') {
            $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
            $title = trim(Markdown::normalize((string) ($_POST['title'] ?? '')));
            $categoryPaths = normalize_category_paths((string) ($_POST['category_paths'] ?? ''));
            $body = Markdown::normalize((string) ($_POST['body'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('제목을 입력해주세요.');
            }
            if (trim($body) === '') {
                $body = "---\ntitle: {$title}\ntags: []\n---\n\n# {$title}\n";
            }
            $body = apply_category_paths($body, $title, $categoryPaths);
            $saved = $repo->save($id, $title, $body, $categoryPaths);
            header('Location: index.php?note=' . rawurlencode($saved['slug']));
            exit;
        }
        if ($action === 'delete') {
            $repo->delete((int) ($_POST['id'] ?? 0));
            header('Location: index.php');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$query = trim(Markdown::normalize((string) ($_GET['q'] ?? '')));
$tag = trim(Markdown::normalize((string) ($_GET['tag'] ?? '')));
$categoryFilter = normalize_category_path((string) ($_GET['cat'] ?? ''));
$mode = (string) ($_GET['mode'] ?? 'view');
$isDashboard = isset($_GET['dashboard']);
$policyPage = (string) ($_GET['page'] ?? '');
$policyPages = [
    'about' => ['title' => 'About', 'setting' => 'about_body', 'description' => '사이트 소개와 운영 목적입니다.'],
    'terms' => ['title' => '이용방침', 'setting' => 'terms_body', 'description' => '웹사이트의 이용방침과 콘텐츠 운영 기준입니다.'],
    'privacy' => ['title' => '개인정보처리방침', 'setting' => 'privacy_body', 'description' => '개인정보 처리와 보안 로그 운영 기준입니다.'],
    'contact' => ['title' => 'Contact', 'setting' => 'contact_intro', 'description' => '문서 오류, 삭제 요청, 개인정보 문의를 접수합니다.'],
];
if (!isset($policyPages[$policyPage])) {
    $policyPage = '';
}
$isPolicyPage = $policyPage !== '';
$slug = (string) ($_GET['note'] ?? '');
$defaultShow = $categoryFilter !== '' ? 12 : 12;
$showStep = $categoryFilter !== '' ? 12 : 12;
$show = max($defaultShow, min(200, (int) ($_GET['show'] ?? $defaultShow)));

if ($categoryFilter !== '') {
    $notes = $repo->notesByCategoryPath($categoryFilter, $show, 0);
    $totalNotes = $repo->countByCategoryPath($categoryFilter);
} else {
    $notes = ($query === '' && $tag === '') ? $repo->random($show) : $repo->all($query ?: null, $tag ?: null, $show, 0);
    $totalNotes = $repo->countAll($query ?: null, $tag ?: null);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !is_admin() && !$isDashboard && ($query !== '' || $tag !== '')) {
    $repo->recordSearch($query, $tag, $totalNotes);
}
$recentNotes = $repo->all(null, null, 6, 0);
$tags = $repo->tags();
$current = (!$isDashboard && !$isPolicyPage && $slug !== '') ? $repo->findBySlug($slug) : null;
if ($current && $mode !== 'edit' && $mode !== 'new') {
    $current['views'] = $repo->incrementViews((int) $current['id']);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !is_admin() && !$isDashboard && $mode !== 'edit' && $mode !== 'new') {
    $repo->recordVisit($current ? (int) $current['id'] : null, (string) ($_SERVER['REQUEST_URI'] ?? ''), daily_visitor_hash());
}
$backlinks = $current ? $repo->backlinks($current['slug']) : [];
$currentTags = $current ? Markdown::tags((string) $current['body']) : [];
$currentCategoryPath = $current ? ($repo->noteCategoryPath((int) $current['id']) ?: note_category_path($current)) : $categoryFilter;
$categoryNotes = $repo->categoryChildren(null, 500);
$skillCategoryPrefix = $currentCategoryPath !== '' ? $currentCategoryPath : (string) ($categoryNotes[0]['path'] ?? '');
$categorySkillItems = $repo->categoryChildren($skillCategoryPrefix, 8);
if ($categorySkillItems === [] && $currentCategoryPath !== '') {
    $parts = explode('/', $currentCategoryPath);
    array_pop($parts);
    $categorySkillItems = $repo->categoryChildren(implode('/', $parts), 8);
}
$sidebarRandomNotes = $repo->random(5);
$sidebarTags = sidebar_tags($tags, 14);
$graph = $repo->graph();
$dashboard = $isDashboard ? $repo->dashboard() : null;
$siteSettings = $settings ? $settings->all() : [];
$showSidebarVisitors = ($siteSettings['show_sidebar_visitors'] ?? '1') === '1';
$showTopDashboard = ($siteSettings['show_top_dashboard'] ?? '1') === '1';
$visitorSummary = $showSidebarVisitors ? $repo->visitorSummary() : null;
$isCategoryPage = !$isDashboard && !$isPolicyPage && $slug === '' && $mode !== 'new' && $categoryFilter !== '';
$isSearchPage = !$isDashboard && !$isPolicyPage && $slug === '' && $mode !== 'new' && !$isCategoryPage && $query !== '';
$isTagPage = !$isDashboard && !$isPolicyPage && $slug === '' && $mode !== 'new' && !$isCategoryPage && $tag !== '';
$isHome = !$isDashboard && !$isPolicyPage && $slug === '' && $mode !== 'new' && $query === '' && $tag === '' && $categoryFilter === '';

if ($mode === 'new') {
    $current = [
        'id' => null,
        'slug' => '',
        'title' => '',
        'body' => "---\ntitle: \ncategory_paths: \ntags: []\n---\n\n# \n\n## Basic Info\n- \n\n## Summary\n\n## Related\n- [[ ]]\n",
        'views' => 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

$editorCategoryPaths = $current ? ($repo->noteCategoryPaths((int) $current['id']) ?: note_category_paths($current)) : [];
$editorCategoryValue = implode("\n", $editorCategoryPaths);

$isEditing = is_admin() && ($mode === 'edit' || $mode === 'new');
$pageTitle = ($current['title'] ?? APP_NAME) . ' - ' . APP_NAME;
$description = description_from($current);
$canonical = $current ? note_url($current['slug']) : base_url() . '/';

if ($isPolicyPage) {
    $pageTitle = $policyPages[$policyPage]['title'] . ' - ' . APP_NAME;
    $description = $policyPages[$policyPage]['description'];
    $canonical = base_url() . '/index.php?page=' . rawurlencode($policyPage);
} elseif ($isDashboard) {
    $pageTitle = '대시보드 - ' . APP_NAME;
    $canonical = base_url() . '/index.php?dashboard=1';
} elseif ($isCategoryPage) {
    $pageTitle = $categoryFilter . ' 범주 문서 - ' . APP_NAME;
    $description = $categoryFilter . ' 범주에 포함된 문서 목록입니다.';
    $canonical = base_url() . '/index.php?cat=' . rawurlencode($categoryFilter);
} elseif ($isSearchPage) {
    $pageTitle = $query . ' 검색 결과 - ' . APP_NAME;
    $description = $query . ' 검색어와 관련된 문서 목록입니다.';
    $canonical = base_url() . '/index.php?q=' . rawurlencode($query);
} elseif ($isTagPage) {
    $pageTitle = '#' . $tag . ' 관련 문서 - ' . APP_NAME;
    $description = '#' . $tag . ' 태그와 관련된 문서 목록입니다.';
    $canonical = base_url() . '/index.php?tag=' . rawurlencode($tag);
}
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <meta name="description" content="<?= h($description) ?>">
    <link rel="canonical" href="<?= h($canonical) ?>">
    <meta property="og:title" content="<?= h($pageTitle) ?>">
    <meta property="og:description" content="<?= h($description) ?>">
    <meta property="og:url" content="<?= h($canonical) ?>">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="app-shell">
        <div class="mobile-scrim" data-sidebar-close></div>
        <aside class="sidebar" id="siteSidebar" aria-hidden="false">
            <div class="brand">
                <a href="index.php"><?= h(APP_NAME) ?></a>
                <?php if (is_admin()): ?><a class="icon-button" href="admin.php" title="관리자">+</a><?php endif; ?>
                <button class="icon-button mobile-only" type="button" data-sidebar-close title="메뉴 닫기">x</button>
            </div>
            <form class="search" method="get" action="index.php">
                <input type="search" name="q" value="<?= h($query) ?>" placeholder="문서 검색">
                <?php if ($tag): ?><input type="hidden" name="tag" value="<?= h($tag) ?>"><?php endif; ?>
                <?php if ($categoryFilter): ?><input type="hidden" name="cat" value="<?= h($categoryFilter) ?>"><?php endif; ?>
            </form>
            <?php if ($query || $tag || $categoryFilter): ?>
                <div class="active-filters">
                    <?php if ($query): ?><span>검색: <?= h($query) ?></span><?php endif; ?>
                    <?php if ($tag): ?><span>#<?= h($tag) ?></span><?php endif; ?>
                    <?php if ($categoryFilter): ?><span>범주: <?= h($categoryFilter) ?></span><?php endif; ?>
                    <a href="index.php">초기화</a>
                </div>
            <?php endif; ?>
            <?php if ($showSidebarVisitors && $visitorSummary): ?>
                <section class="sidebar-section visitor-widget">
                    <div class="sidebar-section-title">
                        <span>방문자</span>
                        <em>Live</em>
                    </div>
                    <div class="visitor-stat-grid">
                        <div>
                            <span>오늘 방문자</span>
                            <strong><?= number_format((int) $visitorSummary['today_visitors']) ?></strong>
                        </div>
                        <div>
                            <span>전체 방문자</span>
                            <strong><?= number_format((int) $visitorSummary['total_visitors']) ?></strong>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
            <section class="sidebar-section">
                <div class="sidebar-section-title">
                    <span>카테고리</span>
                    <em><?= count($categoryNotes) ?></em>
                </div>
                <nav class="category-list">
                    <?php foreach ($categoryNotes as $item): ?>
                        <a class="<?= $categoryFilter === $item['path'] ? 'active' : '' ?>" href="?cat=<?= rawurlencode($item['path']) ?>">
                            <strong><?= h($item['name']) ?></strong>
                            <span><?= (int) ($item['note_count'] ?? $item['count'] ?? 0) ?>개 문서</span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($categoryNotes === []): ?><p class="muted">등록된 범주가 없습니다.</p><?php endif; ?>
                </nav>
            </section>

            <section class="sidebar-section">
                <div class="sidebar-section-title">
                    <span>분류</span>
                    <em><?= count($categorySkillItems) ?></em>
                </div>
                <div class="skill-list category-skills">
                    <?php foreach ($categorySkillItems as $item): ?>
                        <a class="<?= $categoryFilter === $item['path'] ? 'active' : '' ?>" href="?cat=<?= rawurlencode($item['path']) ?>">
                            <span><?= h((string) $item['name']) ?></span>
                            <b><?= (int) ($item['note_count'] ?? $item['count'] ?? 0) ?></b>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($categorySkillItems === []): ?><p class="muted">하위 범주가 없습니다.</p><?php endif; ?>
                </div>
            </section>

            <section class="sidebar-section">
                <div class="sidebar-section-title">
                    <span>랜덤 문서</span>
                    <em>5</em>
                </div>
                <nav class="note-list compact random-list">
                    <?php foreach ($sidebarRandomNotes as $note): ?>
                        <a class="<?= ($current && $note['id'] === $current['id']) ? 'active' : '' ?>" href="?note=<?= rawurlencode($note['slug']) ?>">
                            <strong><?= h($note['title']) ?></strong>
                            <span><?= h(note_excerpt($note)) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($sidebarRandomNotes === []): ?><p class="muted">문서가 없습니다.</p><?php endif; ?>
                </nav>
            </section>

            <section class="sidebar-section">
                <div class="sidebar-section-title">
                    <span>태그</span>
                    <em><?= count($sidebarTags) ?></em>
                </div>
                <div class="tag-list compact">
                    <?php foreach ($sidebarTags as $item): ?>
                        <a href="?tag=<?= rawurlencode($item['name']) ?>">#<?= h($item['name']) ?><span><?= (int) $item['count'] ?></span></a>
                    <?php endforeach; ?>
                </div>
            </section>
        </aside>
        <main class="content">
            <header class="topbar">
                <button class="button mobile-only" type="button" data-sidebar-open>메뉴</button>
                <div class="topbar-status">
                    <?php if ($current && !$isEditing): ?>
                        <div class="doc-status">
                            <strong><?= h($current['title']) ?></strong>
                            <span>최종 수정일 <?= h(date('Y-m-d H:i', strtotime($current['updated_at']))) ?></span>
                            <span>조회수 <?= number_format((int) ($current['views'] ?? 0)) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($isDashboard): ?><span>대시보드</span><?php endif; ?>
                    <?php if ($isSearchPage): ?><span><?= h($query) ?> 검색 결과</span><?php endif; ?>
                    <?php if ($isTagPage): ?><span>#<?= h($tag) ?> 관련 문서</span><?php endif; ?>
                    <?php if ($isHome): ?><span>홈</span><?php endif; ?>
                    <?php if ($isPolicyPage): ?><span><?= h($policyPages[$policyPage]['title']) ?></span><?php endif; ?>
                </div>
                <button class="button mobile-only" type="button" data-tools-toggle aria-expanded="false">도구</button>
                <div class="actions" id="topbarTools">
                    <div class="theme-switcher" aria-label="테마">
                        <button class="theme-button" type="button" data-theme-choice="base">기본</button>
                        <button class="theme-button" type="button" data-theme-choice="white">화이트</button>
                        <button class="theme-button" type="button" data-theme-choice="dark">다크</button>
                    </div>
                    <button class="button" type="button" data-panel="graph">그래프</button>
                    <a class="button nav-button <?= $isHome ? 'active' : '' ?>" href="index.php">홈</a>
                    <?php if ($showTopDashboard): ?><a class="button nav-button <?= $isDashboard ? 'active' : '' ?>" href="?dashboard=1">대시보드</a><?php endif; ?>
                    <?php if (is_admin()): ?><a class="button nav-button" href="admin.php">관리자</a><?php endif; ?>
                    <label class="translate-control" title="페이지 번역">
                        <span>언어</span>
                        <select data-translate>
                            <option value="ko">한국어</option>
                            <option value="en">영어</option>
                            <option value="ja">일본어</option>
                            <option value="zh-CN">중국어</option>
                            <option value="es">스페인어</option>
                            <option value="vi">베트남어</option>
                            <option value="th">태국어</option>
                            <option value="id">인도네시아어</option>
                        </select>
                    </label>
                    <?php if ($current && is_admin() && !$isEditing): ?><a class="button" href="admin.php?edit_note=<?= (int) $current['id'] ?>">관리자에서 수정</a><?php endif; ?>
                    <button class="button account-button" type="button" data-account><?= is_logged_in() ? h((string) ($_SESSION['user_name'] ?? '계정')) : '계정' ?></button>
                </div>
            </header>
            <?php if ($error): ?><div class="notice danger"><?= h($error) ?></div><?php endif; ?>
            <?php if ($message): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
            <?php if ($isPolicyPage): ?>
                <section class="policy-section">
                    <div class="policy-card">
                        <p class="eyebrow"><?= h(APP_NAME) ?></p>
                        <h1><?= h($policyPages[$policyPage]['title']) ?></h1>
                        <div class="policy-copy">
                            <?= render_setting_text((string) ($siteSettings[$policyPages[$policyPage]['setting']] ?? '')) ?>
                            <?php if ($policyPage === 'terms' || $policyPage === 'privacy'): ?><p>Last updated: 2026-06-18</p><?php endif; ?>
                        </div>
                        <?php if ($policyPage === 'contact'): ?>
                            <div class="contact-inline">
                                <p>Email: <a href="mailto:<?= h((string) ($siteSettings['contact_email'] ?? 'contact@example.com')) ?>"><?= h((string) ($siteSettings['contact_email'] ?? 'contact@example.com')) ?></a></p>
                                <form class="account-form contact-form" method="post" action="index.php?page=contact">
                                    <input type="hidden" name="action" value="contact">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <label><span>이름</span><input type="text" name="name" maxlength="80" required></label>
                                    <label><span>이메일</span><input type="email" name="email" required></label>
                                    <label><span>제목</span><input type="text" name="subject" maxlength="160" required></label>
                                    <label><span>내용</span><textarea name="message" rows="7" required></textarea></label>
                                    <button class="button primary" type="submit">문의 보내기</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php elseif ($dashboard): ?>
                <section class="dashboard">
                    <div class="dashboard-heading">
                        <div>
                            <p class="eyebrow"><?= h(APP_NAME) ?></p>
                            <h1>페이지 대시보드</h1>
                        </div>
                        <div class="dashboard-hero-metrics">
                            <span><b><?= number_format((int) $dashboard['summary']['today_visitors']) ?></b> 오늘 방문자</span>
                            <span><b><?= number_format((int) $dashboard['summary']['views']) ?></b> 전체 조회수</span>
                        </div>
                    </div>
                    <div class="dashboard-stats">
                        <div class="stat-card primary"><span>문서</span><strong><?= number_format((int) $dashboard['summary']['documents']) ?></strong></div>
                        <div class="stat-card warm"><span>전체 조회수</span><strong><?= number_format((int) $dashboard['summary']['views']) ?></strong></div>
                        <div class="stat-card cool"><span>오늘 방문자</span><strong><?= number_format((int) $dashboard['summary']['today_visitors']) ?></strong></div>
                        <div class="stat-card green"><span>오늘 페이지뷰</span><strong><?= number_format((int) $dashboard['summary']['today_pageviews']) ?></strong></div>
                        <div class="stat-card"><span>태그</span><strong><?= number_format((int) $dashboard['summary']['tags']) ?></strong></div>
                        <div class="stat-card"><span>링크</span><strong><?= number_format((int) $dashboard['summary']['links']) ?></strong></div>
                        <div class="stat-card"><span>최고 조회수</span><strong><?= number_format((int) $dashboard['summary']['max_views']) ?></strong></div>
                        <div class="stat-card"><span>평균 조회수</span><strong><?= h((string) $dashboard['summary']['avg_views']) ?></strong></div>
                    </div>
                    <div class="dashboard-grid">
                        <section class="dashboard-panel">
                            <h2>인기 문서</h2>
                            <div class="rank-list">
                                <?php foreach ($dashboard['topViewed'] as $index => $note): ?>
                                    <a href="?note=<?= rawurlencode($note['slug']) ?>"><b><?= $index + 1 ?></b><span><strong><?= h($note['title']) ?></strong><em>조회수 <?= number_format((int) $note['views']) ?></em></span></a>
                                <?php endforeach; ?>
                                <?php if ($dashboard['topViewed'] === []): ?><p class="muted">아직 문서가 없습니다.</p><?php endif; ?>
                            </div>
                        </section>
                        <section class="dashboard-panel">
                            <h2>최근 수정 문서</h2>
                            <div class="rank-list">
                                <?php foreach ($dashboard['recent'] as $note): ?>
                                    <a href="?note=<?= rawurlencode($note['slug']) ?>"><b><?= h(date('m.d', strtotime($note['updated_at']))) ?></b><span><strong><?= h($note['title']) ?></strong><em>조회수 <?= number_format((int) $note['views']) ?></em></span></a>
                                <?php endforeach; ?>
                                <?php if ($dashboard['recent'] === []): ?><p class="muted">아직 문서가 없습니다.</p><?php endif; ?>
                            </div>
                        </section>
                        <section class="dashboard-panel wide">
                            <h2>날짜별 방문자</h2>
                            <div class="visit-bars">
                                <?php $maxVisit = max(1, ...array_map(static fn ($item) => (int) $item['visitors'], $dashboard['dailyVisits'])); ?>
                                <?php foreach ($dashboard['dailyVisits'] as $item): ?>
                                    <div>
                                        <span><?= h(date('m.d', strtotime($item['visit_date']))) ?></span>
                                        <i style="width: <?= max(6, round(((int) $item['visitors'] / $maxVisit) * 100)) ?>%"></i>
                                        <b>방문자 <?= number_format((int) $item['visitors']) ?></b>
                                        <em>조회 <?= number_format((int) $item['pageviews']) ?></em>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($dashboard['dailyVisits'] === []): ?><p class="muted">아직 방문 데이터가 없습니다.</p><?php endif; ?>
                            </div>
                        </section>
                        <section class="dashboard-panel wide">
                            <h2>인기 태그</h2>
                            <div class="tag-bars">
                                <?php $maxTag = max(1, ...array_map(static fn ($item) => (int) $item['count'], $dashboard['topTags'])); ?>
                                <?php foreach ($dashboard['topTags'] as $item): ?>
                                    <a href="?tag=<?= rawurlencode($item['name']) ?>"><span>#<?= h($item['name']) ?></span><i style="width: <?= max(6, round(((int) $item['count'] / $maxTag) * 100)) ?>%"></i><b><?= (int) $item['count'] ?></b></a>
                                <?php endforeach; ?>
                                <?php if ($dashboard['topTags'] === []): ?><p class="muted">아직 태그가 없습니다.</p><?php endif; ?>
                            </div>
                        </section>
                        <section class="dashboard-panel">
                            <h2>인기 검색어</h2>
                            <div class="rank-list">
                                <?php foreach ($dashboard['topSearches'] as $index => $item): ?>
                                    <a href="<?= $item['search_type'] === 'tag' ? '?tag=' . rawurlencode($item['keyword']) : '?q=' . rawurlencode($item['keyword']) ?>"><b><?= $index + 1 ?></b><span><strong><?= $item['search_type'] === 'tag' ? '#' : '' ?><?= h($item['keyword']) ?></strong><em><?= (int) $item['count'] ?>회 검색</em></span></a>
                                <?php endforeach; ?>
                                <?php if ($dashboard['topSearches'] === []): ?><p class="muted">아직 검색 기록이 없습니다.</p><?php endif; ?>
                            </div>
                        </section>
                        <section class="dashboard-panel">
                            <h2>최근 검색</h2>
                            <div class="rank-list">
                                <?php foreach ($dashboard['recentSearches'] as $item): ?>
                                    <a href="<?= $item['search_type'] === 'tag' ? '?tag=' . rawurlencode($item['keyword']) : '?q=' . rawurlencode($item['keyword']) ?>"><b><?= h(date('H:i', strtotime($item['created_at']))) ?></b><span><strong><?= $item['search_type'] === 'tag' ? '#' : '' ?><?= h($item['keyword']) ?></strong><em>결과 <?= number_format((int) $item['result_count']) ?>개</em></span></a>
                                <?php endforeach; ?>
                                <?php if ($dashboard['recentSearches'] === []): ?><p class="muted">아직 검색 기록이 없습니다.</p><?php endif; ?>
                            </div>
                        </section>
                    </div>
                </section>
            <?php elseif ($isEditing && $current): ?>
                <section class="editor markdown-editor">
                    <form method="post">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= h((string) ($current['id'] ?? '')) ?>">
                        <label><span>제목</span><input class="title-input" name="title" value="<?= h($current['title'] ?? '') ?>" placeholder="문서 제목"></label>
                        <label><span>범주</span><textarea class="category-input" name="category_paths" rows="4" placeholder="예: 개발/프로그래밍언어/PHP&#10;PHP/배열&#10;jQuery/플러그인"><?= h($editorCategoryValue) ?></textarea></label>
                        <label><span>마크다운</span><textarea class="raw-markdown" name="body" spellcheck="false"><?= h($current['body'] ?? '') ?></textarea></label>
                        <div class="editor-actions"><button class="button primary" type="submit">저장</button><a class="button" href="<?= !empty($current['slug']) ? '?note=' . rawurlencode($current['slug']) : 'index.php' ?>">취소</a></div>
                    </form>
                    <?php if (!empty($current['id'])): ?><form method="post" onsubmit="return confirm('이 문서를 삭제할까요?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $current['id'] ?>"><button class="button danger" type="submit">삭제</button></form><?php endif; ?>
                </section>
            <?php elseif ($isCategoryPage): ?>
                <section class="tag-page">
                    <div class="tag-page-heading">
                        <p class="eyebrow">범주 문서</p>
                        <h1><?= h($categoryFilter) ?></h1>
                        <p><?= number_format((int) $totalNotes) ?>개의 문서가 이 범주에 포함되어 있습니다.</p>
                    </div>
                    <div class="tag-result-grid">
                        <?php foreach ($notes as $note): ?>
                            <a href="?note=<?= rawurlencode($note['slug']) ?>">
                                <strong><?= h($note['title']) ?></strong>
                                <span><?= h(note_excerpt($note)) ?></span>
                                <?php $paths = $repo->noteCategoryPaths((int) $note['id']) ?: note_category_paths($note); ?>
                                <?php if ($paths !== []): ?>
                                    <div class="category-badges">
                                        <?php foreach ($paths as $path): ?><em><?= h((string) $path) ?></em><?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($notes === []): ?><p class="muted">이 범주에 포함된 문서가 없습니다.</p><?php endif; ?>
                    </div>
                    <?php if (count($notes) < $totalNotes): ?>
                        <div class="tag-page-more"><a class="button primary" href="<?= h(url_with(['show' => $show + $showStep, 'note' => null])) ?>">더 보기</a></div>
                    <?php endif; ?>
                </section>
            <?php elseif ($isSearchPage): ?>
                <section class="tag-page">
                    <div class="tag-page-heading">
                        <p class="eyebrow">검색 결과</p>
                        <h1><?= h($query) ?></h1>
                        <p><?= number_format((int) $totalNotes) ?>개의 관련 문서를 찾았습니다.</p>
                    </div>
                    <div class="tag-result-grid">
                        <?php foreach ($notes as $note): ?>
                            <a href="?note=<?= rawurlencode($note['slug']) ?>">
                                <strong><?= h($note['title']) ?></strong>
                                <span><?= h(note_excerpt($note)) ?></span>
                                <em>조회수 <?= number_format((int) ($note['views'] ?? 0)) ?></em>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($notes === []): ?><p class="muted">검색어와 연결된 문서가 없습니다.</p><?php endif; ?>
                    </div>
                    <?php if (count($notes) < $totalNotes): ?>
                        <div class="tag-page-more"><a class="button primary" href="<?= h(url_with(['show' => $show + $showStep, 'note' => null])) ?>">더 보기</a></div>
                    <?php endif; ?>
                </section>
            <?php elseif ($isTagPage): ?>
                <section class="tag-page">
                    <div class="tag-page-heading">
                        <p class="eyebrow">태그 검색 결과</p>
                        <h1>#<?= h($tag) ?></h1>
                        <p><?= number_format((int) $totalNotes) ?>개의 관련 문서를 찾았습니다.</p>
                    </div>
                    <div class="tag-result-grid">
                        <?php foreach ($notes as $note): ?>
                            <a href="?note=<?= rawurlencode($note['slug']) ?>">
                                <strong><?= h($note['title']) ?></strong>
                                <span><?= h(note_excerpt($note)) ?></span>
                                <em>조회수 <?= number_format((int) ($note['views'] ?? 0)) ?></em>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($notes === []): ?><p class="muted">이 태그와 연결된 문서가 없습니다.</p><?php endif; ?>
                    </div>
                    <?php if (count($notes) < $totalNotes): ?>
                        <div class="tag-page-more"><a class="button primary" href="<?= h(url_with(['show' => $show + $showStep, 'note' => null])) ?>">더 보기</a></div>
                    <?php endif; ?>
                </section>
            <?php elseif ($isHome): ?>
                <section class="home">
                    <div class="home-heading">
                        <p class="eyebrow"><?= h(APP_NAME) ?></p>
                        <h1>연결형 지식 베이스</h1>
                        <p>문서는 MySQL에 저장되며 <code>[[링크]]</code>, 태그, 백링크, 검색, 3D 그래프로 서로 연결됩니다.</p>
                    </div>
                    <div class="home-actions">
                        <?php if (is_admin()): ?><a class="button primary" href="admin.php">관리자에서 문서 등록</a><?php endif; ?>
                        <?php if ($recentNotes): ?><a class="button" href="?note=<?= rawurlencode($recentNotes[0]['slug']) ?>">최근 문서 열기</a><?php endif; ?>
                        <button class="button" type="button" data-panel="graph">3D 그래프 열기</button>
                    </div>
                    <div class="home-stats">
                        <div><strong><?= (int) $repo->countAll(null, null) ?></strong><span>문서</span></div>
                        <div><strong><?= count($tags) ?></strong><span>태그</span></div>
                        <div><strong><?= count($graph['links'] ?? []) ?></strong><span>링크</span></div>
                    </div>
                    <section class="home-section"><h2>최근 문서</h2><div class="home-note-grid">
                        <?php foreach ($recentNotes as $note): ?><a href="?note=<?= rawurlencode($note['slug']) ?>"><strong><?= h($note['title']) ?></strong><span><?= h(note_excerpt($note)) ?></span></a><?php endforeach; ?>
                        <?php if ($recentNotes === []): ?><p class="muted">아직 문서가 없습니다.</p><?php endif; ?>
                    </div></section>
                </section>
            <?php elseif ($current): ?>
                <article class="note">
                    <h1><?= h($current['title']) ?></h1>
                    <?php if ($currentTags !== []): ?><div class="meta"><?php foreach ($currentTags as $name): ?><a class="tag" href="?tag=<?= rawurlencode($name) ?>">#<?= h($name) ?></a><?php endforeach; ?></div><?php endif; ?>
                    <?php if (($current['content_type'] ?? 'markdown') === 'html'): ?>
                        <div class="markdown html-document"><?= HtmlSanitizer::clean((string) $current['body']) ?></div>
                    <?php else: ?>
                        <div class="markdown"><?= Markdown::render($current['body'], true) ?></div>
                    <?php endif; ?>
                </article>
                <section class="backlinks"><h2>백링크</h2><?php if ($backlinks === []): ?><p class="muted">아직 백링크가 없습니다.</p><?php endif; ?><?php foreach ($backlinks as $link): ?><a href="?note=<?= rawurlencode($link['slug']) ?>"><?= h($link['title']) ?></a><?php endforeach; ?></section>
            <?php else: ?>
                <section class="empty-state"><h1>문서를 찾을 수 없습니다</h1><p>목록에서 다른 문서를 선택하거나 새 문서를 만들어주세요.</p><?php if (is_admin()): ?><a class="button primary" href="admin.php">관리자에서 문서 등록</a><?php endif; ?></section>
            <?php endif; ?>
        </main>
        <aside class="right-panel" id="graphPanel" aria-hidden="true">
            <div class="panel-header"><strong>문서 그래프</strong><button class="icon-button" type="button" data-panel-close title="닫기">x</button></div>
            <input class="graph-search" type="search" placeholder="그래프 검색">
            <div id="graphMount" class="graph-mount"></div>
            <p class="graph-help">드래그로 회전, 휠로 확대/축소, 오른쪽 드래그로 이동할 수 있습니다.</p>
        </aside>
        <dialog id="accountDialog">
            <div class="account-dialog">
                <div class="account-header">
                    <h2>계정</h2>
                    <button class="icon-button" type="button" data-account-close title="닫기">x</button>
                </div>
                <?php if (is_logged_in()): ?>
                    <div class="account-profile">
                        <span class="account-kicker">현재 로그인</span>
                        <strong><?= h((string) ($_SESSION['user_name'] ?? '계정')) ?></strong>
                        <span><?= is_admin() ? '관리자 계정' : '회원 계정' ?></span>
                        <?php if ($currentUser): ?><small><?= h((string) $currentUser['email']) ?></small><?php endif; ?>
                        <?php if (is_admin()): ?><p>문서 작성, 수정, 삭제 권한이 있는 계정입니다.</p><?php endif; ?>
                    </div>
                    <form method="post" class="account-form">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <button class="button primary" type="submit">로그아웃</button>
                    </form>
                <?php else: ?>
                    <div class="account-security">
                        <strong>관리자 계정 안내</strong>
                        <span>첫 번째로 가입한 계정이 관리자입니다. 비밀번호는 암호화되어 저장되므로 화면에 표시할 수 없습니다.</span>
                    </div>
                    <div class="account-grid <?= $allowRegistration ? '' : 'single' ?>">
                        <form method="post" class="account-form">
                            <input type="hidden" name="action" value="login">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <h3>로그인</h3>
                            <label><span>이메일</span><input type="email" name="email" autocomplete="email" required></label>
                            <label><span>비밀번호</span><input type="password" name="password" autocomplete="current-password" required></label>
                            <button class="button primary" type="submit">로그인</button>
                        </form>
                        <?php if ($allowRegistration): ?>
                        <form method="post" class="account-form">
                            <input type="hidden" name="action" value="register">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <h3>회원가입</h3>
                            <label><span>이름</span><input type="text" name="display_name" maxlength="80" autocomplete="name" required></label>
                            <label><span>이메일</span><input type="email" name="email" autocomplete="email" required></label>
                            <label><span>비밀번호</span><input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
                            <p class="account-note">첫 번째 가입 계정은 관리자 계정이 됩니다.</p>
                            <button class="button primary" type="submit">계정 만들기</button>
                        </form>
                        <?php else: ?>
                        <div class="account-form account-note-box">
                            <h3>계정 생성 제한</h3>
                            <p>관리자 계정은 설치 과정에서 생성합니다. 추가 계정이 필요하면 데이터베이스에서 역할을 직접 관리하거나 계정 관리 기능을 별도로 확장하세요.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </dialog>
        <footer class="site-footer">
            <div class="site-footer-main">
                <div>
                    <strong><?= h(APP_NAME) ?></strong>
                    <p><?= h((string) ($siteSettings['site_summary'] ?? '공개 지식과 참고 자료를 연결해 정리하는 아카이브입니다.')) ?></p>
                </div>
                <nav class="site-footer-links" aria-label="Footer navigation">
                    <a href="index.php?page=about">About</a>
                    <a href="index.php?page=terms">이용방침</a>
                    <a href="index.php?page=privacy">개인정보처리방침</a>
                    <a href="index.php?page=contact">Contact</a>
                    <a href="https://github.com/treeview-official/TreeView_CMS" target="_blank" rel="noopener">GitHub</a>
                </nav>
            </div>
            <div class="site-footer-bottom">
                <span>&copy; <?= date('Y') ?> <?= h(APP_NAME) ?>. All rights reserved.</span>
                <span><?= h((string) ($siteSettings['footer_note'] ?? '콘텐츠 오류, 삭제 요청, 개인정보 문의는 Contact 페이지를 통해 접수합니다.')) ?></span>
            </div>
        </footer>
    </div>
    <script>
        window.RED_GRAPH = <?= json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.RED_CURRENT_SLUG = <?= json_encode($current['slug'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
        window.googleTranslateElementInit = function () {
            new google.translate.TranslateElement({
                pageLanguage: 'ko',
                includedLanguages: 'ko,en,ja,zh-CN,zh-TW,es,vi,th,id,fr,de',
                autoDisplay: false
            }, 'google_translate_element');
        };
    </script>
    <div id="google_translate_element" class="google-translate"></div>
    <script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
    <script src="assets/app.js"></script>
</body>
</html>
