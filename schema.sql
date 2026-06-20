CREATE TABLE IF NOT EXISTS notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    excerpt TEXT NULL,
    content_type VARCHAR(20) NOT NULL DEFAULT 'markdown',
    views INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_notes_views (views),
    INDEX idx_notes_content_type (content_type),
    INDEX idx_notes_updated_at (updated_at),
    INDEX idx_notes_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS note_tags (
    note_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (note_id, name),
    INDEX idx_note_tags_name (name),
    CONSTRAINT fk_note_tags_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS note_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_note_id INT UNSIGNED NOT NULL,
    target_slug VARCHAR(190) NOT NULL,
    target_title VARCHAR(255) NOT NULL,
    INDEX idx_note_links_target_slug (target_slug),
    INDEX idx_note_links_from_note_id (from_note_id),
    CONSTRAINT fk_note_links_note FOREIGN KEY (from_note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS note_categories (
    note_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (note_id, category_id),
    INDEX idx_note_categories_category (category_id),
    CONSTRAINT fk_note_categories_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    CONSTRAINT fk_note_categories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS note_likes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id INT UNSIGNED NOT NULL,
    visitor_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_note_likes_visitor (note_id, visitor_hash),
    INDEX idx_note_likes_note_id (note_id),
    CONSTRAINT fk_note_likes_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    display_name VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'member',
    failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    INDEX idx_users_role (role),
    INDEX idx_users_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    search_type VARCHAR(20) NOT NULL,
    keyword VARCHAR(190) NOT NULL,
    result_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_search_logs_keyword (keyword),
    INDEX idx_search_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    subject VARCHAR(160) NOT NULL,
    message TEXT NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_contact_messages_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
