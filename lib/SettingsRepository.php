<?php
declare(strict_types=1);

final class SettingsRepository
{
    private static $schemaReady = false;

    const DEFAULTS = [
        'site_summary' => '문서, 태그, 백링크, 검색, 3D 그래프로 지식을 연결하는 TreeView CMS입니다.',
        'footer_note' => '콘텐츠 오류, 삭제 요청, 개인정보 문의는 Contact 페이지를 통해 접수합니다.',
        'about_body' => "TreeView CMS는 공개 정보, 참고 자료, 개인 노트, 관련 주제를 연결해 정리하는 지식 베이스입니다.\n\n문서는 태그, 백링크, 관련 문서, 출처 링크를 중심으로 관리되며 필요한 경우 수정과 검토를 거쳐 업데이트됩니다.\n\n운영 목적은 흩어진 정보를 쉽게 탐색할 수 있는 구조로 보관하고, 사용자가 맥락을 따라 이동하며 자료를 확인할 수 있게 하는 것입니다.",
        'terms_body' => "TreeView CMS는 공개 정보, 참고 자료, 운영자가 작성한 노트를 구조적으로 정리하는 지식 아카이브입니다.\n\n사용자는 사이트의 콘텐츠를 정보 확인과 개인 참고 목적으로 이용할 수 있으며, 인용 또는 재사용 시 원문 출처와 관련 법령을 함께 확인해야 합니다.\n\n허위 정보 게시, 서비스 방해, 자동화된 과도한 요청, 타인의 권리 침해, 비인가 접근 시도는 허용되지 않습니다.\n\n사이트의 문서는 정확성을 위해 수정될 수 있으며, 오류 제보나 삭제 요청은 Contact 페이지를 통해 접수합니다.",
        'privacy_body' => "이 웹사이트는 보안, 장애 대응, 방문 통계 확인을 위해 IP 주소, 브라우저 정보, 접속 시간, 유입 경로와 같은 기본 서버 로그를 처리할 수 있습니다.\n\n광고 또는 분석 도구가 추가되는 경우 제3자 서비스가 쿠키나 유사 기술을 사용할 수 있으며, 해당 서비스의 정책이 함께 적용됩니다.\n\n수집된 정보는 사이트 운영과 보안을 위한 범위에서 사용되며, 법령상 필요한 경우를 제외하고 개인정보를 판매하지 않습니다.\n\n삭제, 정정, 문의 요청은 Contact 페이지를 통해 사이트 운영자에게 전달할 수 있습니다.",
        'contact_intro' => '문서 오류, 출처 업데이트, 콘텐츠 요청, 삭제 요청, 개인정보 문의는 아래 양식 또는 이메일로 사이트 운영자에게 연락해 주세요.',
        'contact_email' => 'contact@example.com',
        'show_sidebar_visitors' => '1',
        'show_top_dashboard' => '1',
    ];

    public function __construct()
    {
        $this->ensureSchema();
        $this->seedDefaults();
        $this->upgradeLegacyDefaults();
    }

    public function get(string $key): string
    {
        $stmt = Database::pdo()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $key]);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : (self::DEFAULTS[$key] ?? '');
    }

    public function all(): array
    {
        $settings = self::DEFAULTS;
        $stmt = Database::pdo()->query('SELECT setting_key, setting_value FROM site_settings');
        foreach ($stmt->fetchAll() as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $settings;
    }

    public function update(array $values)
    {
        $stmt = Database::pdo()->prepare('
            INSERT INTO site_settings (setting_key, setting_value, updated_at)
            VALUES (:setting_key, :setting_value, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ');

        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = Markdown::normalize(trim((string) $values[$key]));
            if ($key === 'contact_email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('올바른 Contact 이메일을 입력해주세요.');
            }
            if (in_array($key, ['show_sidebar_visitors', 'show_top_dashboard'], true)) {
                $value = $value === '1' ? '1' : '0';
            }
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $value,
            ]);
        }
    }

    public function saveContactMessage(string $name, string $email, string $subject, string $message)
    {
        $name = trim(Markdown::normalize($name));
        $email = mb_strtolower(trim($email), 'UTF-8');
        $subject = trim(Markdown::normalize($subject));
        $message = trim(Markdown::normalize($message));

        if ($name === '' || mb_strlen($name, 'UTF-8') > 80) {
            throw new RuntimeException('이름을 입력해주세요.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('올바른 이메일을 입력해주세요.');
        }
        if ($subject === '' || mb_strlen($subject, 'UTF-8') > 160) {
            throw new RuntimeException('문의 제목을 입력해주세요.');
        }
        if ($message === '') {
            throw new RuntimeException('문의 내용을 입력해주세요.');
        }

        $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ADMIN_PASSWORD);
        $rateStmt = Database::pdo()->prepare('
            SELECT COUNT(*)
            FROM contact_messages
            WHERE ip_hash = :ip_hash AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ');
        $rateStmt->execute(['ip_hash' => $ipHash]);
        if ((int) $rateStmt->fetchColumn() >= 3) {
            throw new RuntimeException('문의가 너무 자주 접수되었습니다. 잠시 후 다시 시도해주세요.');
        }

        $stmt = Database::pdo()->prepare('
            INSERT INTO contact_messages (name, email, subject, message, ip_hash, created_at)
            VALUES (:name, :email, :subject, :message, :ip_hash, NOW())
        ');
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'ip_hash' => $ipHash,
        ]);
    }

    public function contactMessages(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = Database::pdo()->prepare('SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function seedDefaults()
    {
        $stmt = Database::pdo()->prepare('
            INSERT IGNORE INTO site_settings (setting_key, setting_value, updated_at)
            VALUES (:setting_key, :setting_value, NOW())
        ');
        foreach (self::DEFAULTS as $key => $value) {
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $value,
            ]);
        }
    }

    private function upgradeLegacyDefaults()
    {
        $oldName = 'Red' . ' Notes';
        $legacy = [
            'site_summary' => '공개 지식과 참고 자료를 연결해 정리하는 아카이브입니다.',
            'about_body' => str_replace('TreeView CMS', $oldName, self::DEFAULTS['about_body']),
            'terms_body' => str_replace('TreeView CMS', $oldName, self::DEFAULTS['terms_body']),
        ];

        $stmt = Database::pdo()->prepare('
            UPDATE site_settings
            SET setting_value = :new_value, updated_at = NOW()
            WHERE setting_key = :setting_key AND setting_value = :old_value
        ');

        foreach ($legacy as $key => $oldValue) {
            $stmt->execute([
                'setting_key' => $key,
                'old_value' => $oldValue,
                'new_value' => self::DEFAULTS[$key],
            ]);
        }
    }

    private function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::pdo()->exec('
            CREATE TABLE IF NOT EXISTS site_settings (
                setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
                setting_value MEDIUMTEXT NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        Database::pdo()->exec('
            CREATE TABLE IF NOT EXISTS contact_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                email VARCHAR(190) NOT NULL,
                subject VARCHAR(160) NOT NULL,
                message TEXT NOT NULL,
                ip_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_contact_messages_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        self::$schemaReady = true;
    }
}
