<?php
declare(strict_types=1);

final class NoteRepository
{
    private static bool $schemaReady = false;

    public function __construct()
    {
        $this->ensureSchema();
    }

    public function all(?string $query = null, ?string $tag = null, ?int $limit = null, int $offset = 0): array
    {
        $params = [];
        $where = [];
        $join = '';

        if ($query !== null && trim($query) !== '') {
            $terms = preg_split('/\s+/u', trim($query)) ?: [];
            $termWhere = [];
            foreach (array_values(array_filter($terms)) as $index => $term) {
                $term = ltrim(trim($term), '#');
                if ($term === '') {
                    continue;
                }
                $titleKey = 'qt' . $index;
                $bodyKey = 'qb' . $index;
                $excerptKey = 'qe' . $index;
                $tagKey = 'qtag' . $index;
                $termWhere[] = "(LOWER(n.title) LIKE :{$titleKey} OR LOWER(n.body) LIKE :{$bodyKey} OR LOWER(n.excerpt) LIKE :{$excerptKey} OR EXISTS (
                    SELECT 1 FROM note_tags nts
                    WHERE nts.note_id = n.id AND LOWER(TRIM(LEADING '#' FROM nts.name)) LIKE :{$tagKey}
                ))";
                $like = '%' . mb_strtolower($term, 'UTF-8') . '%';
                $params[$titleKey] = $like;
                $params[$bodyKey] = $like;
                $params[$excerptKey] = $like;
                $params[$tagKey] = '%' . mb_strtolower($term, 'UTF-8') . '%';
            }
            if ($termWhere !== []) {
                $where[] = '(' . implode(' AND ', $termWhere) . ')';
            }
        }

        if ($tag !== null && trim($tag) !== '') {
            $join = ' INNER JOIN note_tags nt ON nt.note_id = n.id ';
            $where[] = "LOWER(TRIM(LEADING '#' FROM nt.name)) = :tag";
            $params['tag'] = mb_strtolower(ltrim(trim($tag), '#'), 'UTF-8');
        }

        $sql = 'SELECT DISTINCT n.* FROM notes n' . $join;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY n.updated_at DESC, n.title ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
            $params['limit'] = $limit;
            $params['offset'] = max(0, $offset);
        }

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = in_array($key, ['limit', 'offset'], true) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $type);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countAll(?string $query = null, ?string $tag = null): int
    {
        $params = [];
        $where = [];
        $join = '';

        if ($query !== null && trim($query) !== '') {
            $terms = preg_split('/\s+/u', trim($query)) ?: [];
            $termWhere = [];
            foreach (array_values(array_filter($terms)) as $index => $term) {
                $term = ltrim(trim($term), '#');
                if ($term === '') {
                    continue;
                }
                $titleKey = 'qt' . $index;
                $bodyKey = 'qb' . $index;
                $excerptKey = 'qe' . $index;
                $tagKey = 'qtag' . $index;
                $termWhere[] = "(LOWER(n.title) LIKE :{$titleKey} OR LOWER(n.body) LIKE :{$bodyKey} OR LOWER(n.excerpt) LIKE :{$excerptKey} OR EXISTS (
                    SELECT 1 FROM note_tags nts
                    WHERE nts.note_id = n.id AND LOWER(TRIM(LEADING '#' FROM nts.name)) LIKE :{$tagKey}
                ))";
                $like = '%' . mb_strtolower($term, 'UTF-8') . '%';
                $params[$titleKey] = $like;
                $params[$bodyKey] = $like;
                $params[$excerptKey] = $like;
                $params[$tagKey] = '%' . mb_strtolower($term, 'UTF-8') . '%';
            }
            if ($termWhere !== []) {
                $where[] = '(' . implode(' AND ', $termWhere) . ')';
            }
        }

        if ($tag !== null && trim($tag) !== '') {
            $join = ' INNER JOIN note_tags nt ON nt.note_id = n.id ';
            $where[] = "LOWER(TRIM(LEADING '#' FROM nt.name)) = :tag";
            $params['tag'] = mb_strtolower(ltrim(trim($tag), '#'), 'UTF-8');
        }

        $sql = 'SELECT COUNT(DISTINCT n.id) FROM notes n' . $join;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM notes WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $note = $stmt->fetch();
        return $note ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM notes WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $note = $stmt->fetch();
        return $note ?: null;
    }

    public function incrementViews(int $id): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('UPDATE notes SET views = views + 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $stmt = $pdo->prepare('SELECT views FROM notes WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    public function recordVisit(?int $noteId, string $path, string $visitorHash): void
    {
        $stmt = Database::pdo()->prepare('
            INSERT INTO note_visits (note_id, path, visitor_hash, visit_date, created_at)
            VALUES (:note_id, :path, :visitor_hash, CURDATE(), NOW())
        ');
        $stmt->bindValue(':note_id', $noteId, $noteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':path', mb_substr($path, 0, 500, 'UTF-8'), PDO::PARAM_STR);
        $stmt->bindValue(':visitor_hash', $visitorHash, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function recordSearch(string $query, string $tag, int $resultCount): void
    {
        $query = ltrim(trim(Markdown::normalize($query)), '#');
        $tag = ltrim(trim(Markdown::normalize($tag)), '#');
        $keyword = $tag !== '' ? $tag : $query;
        if ($keyword === '') {
            return;
        }

        $stmt = Database::pdo()->prepare('
            INSERT INTO search_logs (search_type, keyword, result_count, created_at)
            VALUES (:search_type, :keyword, :result_count, NOW())
        ');
        $stmt->execute([
            'search_type' => $tag !== '' ? 'tag' : 'query',
            'keyword' => mb_strtolower(mb_substr($keyword, 0, 190, 'UTF-8'), 'UTF-8'),
            'result_count' => max(0, $resultCount),
        ]);
    }

    public function save(?int $id, string $title, string $body, ?array $categoryPaths = null, string $contentType = 'markdown'): array
    {
        $title = trim(Markdown::normalize($title));
        $body = Markdown::normalize($body);
        $contentType = $contentType === 'html' ? 'html' : 'markdown';
        if ($title === '') {
            throw new RuntimeException('제목을 입력해주세요.');
        }
        if ($contentType === 'html') {
            $body = HtmlSanitizer::clean($body);
        }

        $slug = Markdown::slug($title);
        $excerptSource = $contentType === 'html' ? $body : Markdown::publicBody($body);
        $excerpt = mb_substr(trim(strip_tags($excerptSource)), 0, 220, 'UTF-8');
        $pdo = Database::pdo();

        if ($id === null) {
            $slug = $this->uniqueSlug($slug);
            $stmt = $pdo->prepare('INSERT INTO notes (slug, title, body, excerpt, content_type, created_at, updated_at) VALUES (:slug, :title, :body, :excerpt, :contentType, NOW(), NOW())');
            $stmt->execute(compact('slug', 'title', 'body', 'excerpt', 'contentType'));
            $id = (int) $pdo->lastInsertId();
        } else {
            $current = $this->findById($id);
            if ($current === null) {
                throw new RuntimeException('문서를 찾을 수 없습니다.');
            }
            $slug = $current['title'] === $title ? $current['slug'] : $this->uniqueSlug($slug, $id);
            $stmt = $pdo->prepare('UPDATE notes SET slug = :slug, title = :title, body = :body, excerpt = :excerpt, content_type = :contentType, updated_at = NOW() WHERE id = :id');
            $stmt->execute(compact('slug', 'title', 'body', 'excerpt', 'contentType', 'id'));
        }

        $this->refreshRelations($id, $body);
        $this->refreshCategory($id, $body, $categoryPaths);
        return $this->findById($id) ?? [];
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM notes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function tags(): array
    {
        return Database::pdo()->query('SELECT name, COUNT(*) AS count FROM note_tags GROUP BY name ORDER BY name')->fetchAll();
    }

    public function noteTags(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT name FROM note_tags WHERE note_id = :id ORDER BY name');
        $stmt->execute(['id' => $id]);
        return array_column($stmt->fetchAll(), 'name');
    }

    public function backlinks(string $slug): array
    {
        $stmt = Database::pdo()->prepare('
            SELECT n.* FROM note_links l
            INNER JOIN notes n ON n.id = l.from_note_id
            WHERE l.target_slug = :slug
            ORDER BY n.updated_at DESC
        ');
        $stmt->execute(['slug' => $slug]);
        return $stmt->fetchAll();
    }

    public function graph(): array
    {
        $notes = Database::pdo()->query('SELECT id, slug, title FROM notes ORDER BY title')->fetchAll();
        $links = Database::pdo()->query('
            SELECT l.from_note_id, l.target_slug, l.target_title, t.id AS target_note_id
            FROM note_links l
            LEFT JOIN notes t ON t.slug = l.target_slug
        ')->fetchAll();
        return ['notes' => $notes, 'links' => $links];
    }

    public function random(int $limit = 10): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = Database::pdo()->prepare('SELECT * FROM notes ORDER BY RAND() LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function categoryNotes(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = Database::pdo()->prepare("
            SELECT DISTINCT n.*
            FROM notes n
            INNER JOIN note_tags nt ON nt.note_id = n.id
            WHERE LOWER(nt.name) IN ('대범주', '분류', '카테고리', 'category', 'categories')
            ORDER BY n.views DESC, n.updated_at DESC, n.title ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $notes = $stmt->fetchAll();

        if ($notes !== []) {
            return $notes;
        }

        $stmt = Database::pdo()->prepare('
            SELECT *
            FROM notes
            ORDER BY views DESC, updated_at DESC, title ASC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function categoryChildren(?string $parentPath = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $parentId = null;
        if ($parentPath !== null && trim($parentPath) !== '') {
            $parent = $this->findCategoryByPath($parentPath);
            if (!$parent) {
                return [];
            }
            $parentId = (int) $parent['id'];
        }

        $sql = '
            SELECT c.*, (
                SELECT COUNT(DISTINCT nc.note_id)
                FROM note_categories nc
                INNER JOIN categories d ON d.id = nc.category_id
                WHERE d.path = c.path OR d.path LIKE CONCAT(c.path, \'/%\')
            ) AS note_count
            FROM categories c
            WHERE ' . ($parentId === null ? 'c.parent_id IS NULL' : 'c.parent_id = :parent_id') . '
            HAVING note_count > 0
            ORDER BY c.sort_order ASC, note_count DESC, c.name ASC
            LIMIT :limit
        ';
        $stmt = Database::pdo()->prepare($sql);
        if ($parentId !== null) {
            $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function notesByCategoryPath(string $path, int $limit, int $offset = 0): array
    {
        $category = $this->findCategoryByPath($path);
        if (!$category) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $stmt = Database::pdo()->prepare('
            SELECT DISTINCT n.*
            FROM notes n
            INNER JOIN note_categories nc ON nc.note_id = n.id
            INNER JOIN categories c ON c.id = nc.category_id
            WHERE c.path = :path OR c.path LIKE :child_path
            ORDER BY n.updated_at DESC, n.title ASC
            LIMIT :limit OFFSET :offset
        ');
        $stmt->bindValue(':path', $category['path'], PDO::PARAM_STR);
        $stmt->bindValue(':child_path', $category['path'] . '/%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countByCategoryPath(string $path): int
    {
        $category = $this->findCategoryByPath($path);
        if (!$category) {
            return 0;
        }
        $stmt = Database::pdo()->prepare('
            SELECT COUNT(DISTINCT nc.note_id)
            FROM note_categories nc
            INNER JOIN categories c ON c.id = nc.category_id
            WHERE c.path = :path OR c.path LIKE :child_path
        ');
        $stmt->execute([
            'path' => $category['path'],
            'child_path' => $category['path'] . '/%',
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function noteCategoryPath(int $noteId): string
    {
        $stmt = Database::pdo()->prepare('
            SELECT c.path
            FROM note_categories nc
            INNER JOIN categories c ON c.id = nc.category_id
            WHERE nc.note_id = :note_id
            ORDER BY c.depth DESC, c.name ASC
            LIMIT 1
        ');
        $stmt->execute(['note_id' => $noteId]);
        $path = $stmt->fetchColumn();
        return is_string($path) ? $path : '';
    }

    public function noteCategoryPaths(int $noteId): array
    {
        $stmt = Database::pdo()->prepare('
            SELECT c.path
            FROM note_categories nc
            INNER JOIN categories c ON c.id = nc.category_id
            WHERE nc.note_id = :note_id
            ORDER BY c.depth ASC, c.name ASC
        ');
        $stmt->execute(['note_id' => $noteId]);
        return array_map('strval', array_column($stmt->fetchAll(), 'path'));
    }

    public function resolveCategoryPaths(array $paths): array
    {
        $resolved = [];
        foreach ($paths as $path) {
            $next = $this->resolveCategoryPath((string) $path);
            if ($next !== '') {
                $resolved[] = $next;
            }
        }
        return array_values(array_unique($resolved));
    }

    public function categories(int $limit = 500): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = Database::pdo()->prepare('
            SELECT c.*, COUNT(DISTINCT nc.note_id) AS note_count
            FROM categories c
            LEFT JOIN note_categories nc ON nc.category_id = c.id
            GROUP BY c.id
            ORDER BY c.path ASC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function saveCategoryPath(string $path, int $sortOrder = 0): array
    {
        $path = $this->normalizeCategoryPath($path);
        if ($path === '') {
            throw new RuntimeException('카테고리 경로를 입력해주세요.');
        }
        $categoryId = $this->createCategoryPath($path);
        if ($categoryId === null) {
            throw new RuntimeException('카테고리를 저장하지 못했습니다.');
        }

        $stmt = Database::pdo()->prepare('UPDATE categories SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $categoryId,
            'sort_order' => $sortOrder,
        ]);

        $category = $this->findCategoryByPath($path);
        return $category ?: [];
    }

    public function deleteCategory(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function findCategoryByPath(string $path): ?array
    {
        $path = $this->normalizeCategoryPath($path);
        if ($path === '') {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM categories WHERE path = :path LIMIT 1');
        $stmt->execute(['path' => $path]);
        $category = $stmt->fetch();
        return $category ?: null;
    }

    private function createCategoryPath(string $path): ?int
    {
        $path = $this->resolveCategoryPath($path);
        if ($path === '') {
            return null;
        }
        if (mb_strlen($path, 'UTF-8') > 190) {
            throw new RuntimeException('범주 경로는 190자 이하로 입력해주세요.');
        }

        $parentId = null;
        $currentPath = '';
        $categoryId = null;
        foreach (explode('/', $path) as $depth => $name) {
            $currentPath = $currentPath === '' ? $name : $currentPath . '/' . $name;
            $existing = $this->findCategoryByPath($currentPath);
            if ($existing) {
                $parentId = (int) $existing['id'];
                $categoryId = $parentId;
                continue;
            }

            $stmt = Database::pdo()->prepare('
                INSERT INTO categories (parent_id, name, slug, path, depth, sort_order, created_at, updated_at)
                VALUES (:parent_id, :name, :slug, :path, :depth, 0, NOW(), NOW())
            ');
            $stmt->execute([
                'parent_id' => $parentId,
                'name' => $name,
                'slug' => Markdown::slug($name),
                'path' => $currentPath,
                'depth' => $depth,
            ]);
            $categoryId = (int) Database::pdo()->lastInsertId();
            $parentId = $categoryId;
        }

        return $categoryId;
    }

    private function resolveCategoryPath(string $path): string
    {
        $path = $this->normalizeCategoryPath($path);
        if ($path === '') {
            return '';
        }

        if ($this->findCategoryByPath($path)) {
            return $path;
        }

        $parts = explode('/', $path);
        $first = $parts[0] ?? '';
        if ($first === '') {
            return $path;
        }

        $stmt = Database::pdo()->prepare('SELECT path FROM categories WHERE name = :name ORDER BY depth DESC, path ASC');
        $stmt->execute(['name' => $first]);
        $matches = array_map('strval', array_column($stmt->fetchAll(), 'path'));

        if (count($matches) === 1) {
            $tail = array_slice($parts, 1);
            return $tail === [] ? $matches[0] : $matches[0] . '/' . implode('/', $tail);
        }

        if (count($matches) > 1) {
            throw new RuntimeException($first . ' 범주가 여러 곳에 있습니다. 전체 경로로 입력해주세요.');
        }

        return $path;
    }

    private function normalizeCategoryPath(string $path): string
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

    private function normalizeCategoryPaths(string $value): array
    {
        $rows = preg_split('/\r\n|\r|\n|\|/u', Markdown::normalize($value)) ?: [];
        $paths = [];
        foreach ($rows as $row) {
            $path = $this->resolveCategoryPath($row);
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        return array_values(array_unique($paths));
    }

    public function dashboard(): array
    {
        $pdo = Database::pdo();
        $summary = $pdo->query('
            SELECT
                COUNT(*) AS documents,
                COALESCE(SUM(views), 0) AS views,
                COALESCE(MAX(views), 0) AS max_views,
                COALESCE(AVG(views), 0) AS avg_views
            FROM notes
        ')->fetch() ?: ['documents' => 0, 'views' => 0, 'max_views' => 0, 'avg_views' => 0];

        $tagCount = (int) $pdo->query('SELECT COUNT(DISTINCT name) FROM note_tags')->fetchColumn();
        $linkCount = (int) $pdo->query('SELECT COUNT(*) FROM note_links')->fetchColumn();

        $topViewed = $pdo->query('
            SELECT title, slug, excerpt, views, updated_at
            FROM notes
            ORDER BY views DESC, updated_at DESC
            LIMIT 10
        ')->fetchAll();

        $recent = $pdo->query('
            SELECT title, slug, excerpt, views, updated_at
            FROM notes
            ORDER BY updated_at DESC
            LIMIT 8
        ')->fetchAll();

        $topTags = $pdo->query('
            SELECT name, COUNT(*) AS count
            FROM note_tags
            GROUP BY name
            ORDER BY count DESC, name ASC
            LIMIT 12
        ')->fetchAll();

        $dailyVisits = $pdo->query('
            SELECT visit_date, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors
            FROM note_visits
            WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY visit_date
            ORDER BY visit_date DESC
        ')->fetchAll();

        $today = $pdo->query('
            SELECT COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors
            FROM note_visits
            WHERE visit_date = CURDATE()
        ')->fetch() ?: ['pageviews' => 0, 'visitors' => 0];

        $topSearches = $pdo->query('
            SELECT keyword, search_type, COUNT(*) AS count, MAX(created_at) AS last_searched_at
            FROM search_logs
            GROUP BY keyword, search_type
            ORDER BY count DESC, last_searched_at DESC
            LIMIT 10
        ')->fetchAll();

        $recentSearches = $pdo->query('
            SELECT keyword, search_type, result_count, created_at
            FROM search_logs
            ORDER BY created_at DESC
            LIMIT 10
        ')->fetchAll();

        return [
            'summary' => [
                'documents' => (int) $summary['documents'],
                'views' => (int) $summary['views'],
                'max_views' => (int) $summary['max_views'],
                'avg_views' => round((float) $summary['avg_views'], 1),
                'tags' => $tagCount,
                'links' => $linkCount,
                'today_pageviews' => (int) $today['pageviews'],
                'today_visitors' => (int) $today['visitors'],
            ],
            'topViewed' => $topViewed,
            'recent' => $recent,
            'topTags' => $topTags,
            'dailyVisits' => $dailyVisits,
            'topSearches' => $topSearches,
            'recentSearches' => $recentSearches,
        ];
    }

    public function visitorSummary(): array
    {
        $pdo = Database::pdo();
        $today = $pdo->query('
            SELECT COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors
            FROM note_visits
            WHERE visit_date = CURDATE()
        ')->fetch() ?: ['pageviews' => 0, 'visitors' => 0];

        $total = $pdo->query('
            SELECT COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors
            FROM note_visits
        ')->fetch() ?: ['pageviews' => 0, 'visitors' => 0];

        return [
            'today_visitors' => (int) $today['visitors'],
            'today_pageviews' => (int) $today['pageviews'],
            'total_visitors' => (int) $total['visitors'],
            'total_pageviews' => (int) $total['pageviews'],
        ];
    }

    public function noteDailyVisits(int $noteId, int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $stmt = Database::pdo()->prepare('
            SELECT visit_date, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors
            FROM note_visits
            WHERE note_id = :note_id AND visit_date >= DATE_SUB(CURDATE(), INTERVAL ' . ($days - 1) . ' DAY)
            GROUP BY visit_date
            ORDER BY visit_date DESC
        ');
        $stmt->execute(['note_id' => $noteId]);
        return $stmt->fetchAll();
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $pdo = Database::pdo();
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM notes LIKE 'views'");
        } catch (Throwable $e) {
            return;
        }

        if (!$stmt->fetch()) {
            $pdo->exec('ALTER TABLE notes ADD COLUMN views INT UNSIGNED NOT NULL DEFAULT 0 AFTER excerpt');
            $pdo->exec('ALTER TABLE notes ADD INDEX idx_notes_views (views)');
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM notes LIKE 'content_type'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE notes ADD COLUMN content_type VARCHAR(20) NOT NULL DEFAULT 'markdown' AFTER excerpt");
            $pdo->exec('ALTER TABLE notes ADD INDEX idx_notes_content_type (content_type)');
        }

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS note_visits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                note_id INT UNSIGNED NULL,
                path VARCHAR(500) NOT NULL,
                visitor_hash CHAR(64) NOT NULL,
                visit_date DATE NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_note_visits_date (visit_date),
                INDEX idx_note_visits_note_id (note_id),
                INDEX idx_note_visits_visitor (visitor_hash, visit_date),
                CONSTRAINT fk_note_visits_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_id INT UNSIGNED NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(140) NOT NULL,
                path VARCHAR(190) NOT NULL UNIQUE,
                depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_categories_parent (parent_id, sort_order, name),
                INDEX idx_categories_path (path),
                CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS note_categories (
                note_id INT UNSIGNED NOT NULL,
                category_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (note_id, category_id),
                INDEX idx_note_categories_category (category_id),
                CONSTRAINT fk_note_categories_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
                CONSTRAINT fk_note_categories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->syncExistingCategories();

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS search_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                search_type VARCHAR(20) NOT NULL,
                keyword VARCHAR(190) NOT NULL,
                result_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX idx_search_logs_keyword (keyword),
                INDEX idx_search_logs_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        self::$schemaReady = true;
    }

    private function refreshRelations(int $id, string $body): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM note_tags WHERE note_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM note_links WHERE from_note_id = :id')->execute(['id' => $id]);

        $tagStmt = $pdo->prepare('INSERT INTO note_tags (note_id, name) VALUES (:note_id, :name)');
        foreach (Markdown::tags($body) as $tag) {
            $tagStmt->execute(['note_id' => $id, 'name' => $tag]);
        }

        $linkStmt = $pdo->prepare('INSERT INTO note_links (from_note_id, target_slug, target_title) VALUES (:from_note_id, :target_slug, :target_title)');
        foreach (Markdown::wikiLinks($body) as $title) {
            $linkStmt->execute([
                'from_note_id' => $id,
                'target_slug' => Markdown::slug($title),
                'target_title' => $title,
            ]);
        }
    }

    private function refreshCategory(int $id, string $body, ?array $categoryPaths = null): void
    {
        $meta = Markdown::metadata($body);
        if ($categoryPaths === null) {
            $categoryPaths = [];
            if (isset($meta['category_paths'])) {
                $categoryPaths = $this->normalizeCategoryPaths((string) $meta['category_paths']);
            }
            if ($categoryPaths === [] && isset($meta['category_path'])) {
                $categoryPaths = $this->normalizeCategoryPaths((string) $meta['category_path']);
            }
            if ($categoryPaths === [] && isset($meta['category'])) {
                $categoryPaths = $this->normalizeCategoryPaths((string) $meta['category']);
            }
        }

        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM note_categories WHERE note_id = :note_id')->execute(['note_id' => $id]);
        $stmt = $pdo->prepare('INSERT IGNORE INTO note_categories (note_id, category_id) VALUES (:note_id, :category_id)');
        foreach ($categoryPaths as $path) {
            $categoryId = $this->createCategoryPath((string) $path);
            if ($categoryId === null) {
                continue;
            }
            $stmt->execute([
                'note_id' => $id,
                'category_id' => $categoryId,
            ]);
        }
    }

    private function syncExistingCategories(): void
    {
        $stmt = Database::pdo()->query('
            SELECT n.id, n.body
            FROM notes n
            LEFT JOIN note_categories nc ON nc.note_id = n.id
            WHERE nc.note_id IS NULL
            LIMIT 500
        ');

        foreach ($stmt->fetchAll() as $note) {
            try {
                $this->refreshCategory((int) $note['id'], (string) $note['body'], null);
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $base = $slug;
        $i = 2;

        while (true) {
            $sql = 'SELECT id FROM notes WHERE slug = :slug';
            $params = ['slug' => $slug];
            if ($ignoreId !== null) {
                $sql .= ' AND id <> :id';
                $params['id'] = $ignoreId;
            }
            $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if (!$stmt->fetch()) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }
}
