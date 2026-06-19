<?php
declare(strict_types=1);

final class UserRepository
{
    private static bool $schemaReady = false;

    public function __construct()
    {
        $this->ensureSchema();
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT id, email, display_name, role, created_at, last_login_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function register(string $email, string $displayName, string $password): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $displayName = trim(Markdown::normalize($displayName));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('올바른 이메일을 입력해주세요.');
        }
        if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 80) {
            throw new RuntimeException('이름을 입력해주세요.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('비밀번호는 8자 이상이어야 합니다.');
        }

        $pdo = Database::pdo();
        $role = $this->countUsers() === 0 ? 'admin' : 'member';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare('
                INSERT INTO users (email, display_name, password_hash, role, created_at)
                VALUES (:email, :display_name, :password_hash, :role, NOW())
            ');
            $stmt->execute([
                'email' => $email,
                'display_name' => $displayName,
                'password_hash' => $hash,
                'role' => $role,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('이미 가입된 이메일입니다.');
        }

        return $this->findById((int) $pdo->lastInsertId()) ?? [];
    }

    public function createAdmin(string $email, string $displayName, string $password): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $displayName = trim(Markdown::normalize($displayName));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('올바른 관리자 이메일을 입력해주세요.');
        }
        if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 80) {
            throw new RuntimeException('관리자 이름을 입력해주세요.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('관리자 비밀번호는 8자 이상이어야 합니다.');
        }

        $pdo = Database::pdo();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare('
                INSERT INTO users (email, display_name, password_hash, role, created_at)
                VALUES (:email, :display_name, :password_hash, \'admin\', NOW())
            ');
            $stmt->execute([
                'email' => $email,
                'display_name' => $displayName,
                'password_hash' => $hash,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('이미 가입된 관리자 이메일입니다.');
        }

        return $this->findById((int) $pdo->lastInsertId()) ?? [];
    }

    public function login(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('로그인 정보가 올바르지 않습니다.');
        }

        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            throw new RuntimeException('로그인 실패가 많습니다. 잠시 후 다시 시도해주세요.');
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->recordFailedLogin((int) $user['id'], (int) $user['failed_logins']);
            throw new RuntimeException('로그인 정보가 올바르지 않습니다.');
        }

        $stmt = Database::pdo()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => (int) $user['id']]);

        return $this->findById((int) $user['id']) ?? [];
    }

    public function countUsers(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    private function recordFailedLogin(int $id, int $failedLogins): void
    {
        $failedLogins++;
        if ($failedLogins >= 5) {
            $stmt = Database::pdo()->prepare('UPDATE users SET failed_logins = :failed, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = :id');
        } else {
            $stmt = Database::pdo()->prepare('UPDATE users SET failed_logins = :failed WHERE id = :id');
        }
        $stmt->execute(['failed' => $failedLogins, 'id' => $id]);
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        Database::pdo()->exec('
            CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL UNIQUE,
                display_name VARCHAR(80) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT \'member\',
                failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
                locked_until DATETIME NULL,
                created_at DATETIME NOT NULL,
                last_login_at DATETIME NULL,
                INDEX idx_users_role (role),
                INDEX idx_users_locked_until (locked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        self::$schemaReady = true;
    }
}
