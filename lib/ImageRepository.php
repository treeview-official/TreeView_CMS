<?php
declare(strict_types=1);

final class ImageRepository
{
    private static $schemaReady = false;

    public function __construct()
    {
        $this->ensureSchema();
    }

    public function all(int $limit = 80): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = Database::pdo()->prepare('SELECT * FROM media_assets ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function find(int $id)
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM media_assets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function storeUploaded(array $file, string $altText = ''): array
    {
        if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('업로드할 이미지를 선택해주세요.');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('이미지 업로드에 실패했습니다.');
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new RuntimeException('올바른 업로드 파일이 아닙니다.');
        }
        if (!function_exists('imagewebp')) {
            throw new RuntimeException('서버 PHP GD WebP 변환 기능이 필요합니다.');
        }

        $tmpPath = (string) $file['tmp_name'];
        $info = @getimagesize($tmpPath);
        if (!$info || empty($info['mime'])) {
            throw new RuntimeException('이미지 파일만 업로드할 수 있습니다.');
        }

        $source = $this->createImageResource($tmpPath, (string) $info['mime']);
        $width = imagesx($source);
        $height = imagesy($source);
        if ($width < 1 || $height < 1) {
            imagedestroy($source);
            throw new RuntimeException('이미지 크기를 확인할 수 없습니다.');
        }

        $dir = 'uploads/images/' . date('Y/m');
        $absoluteDir = __DIR__ . '/../' . $dir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true)) {
            imagedestroy($source);
            throw new RuntimeException('이미지 저장 폴더를 만들 수 없습니다.');
        }

        $baseName = $this->slugFromName((string) ($file['name'] ?? 'image'));
        $filename = $baseName . '-' . bin2hex(random_bytes(5)) . '.webp';
        $relativePath = $dir . '/' . $filename;
        $absolutePath = __DIR__ . '/../' . $relativePath;

        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);
        if (!imagewebp($source, $absolutePath, 86)) {
            imagedestroy($source);
            throw new RuntimeException('WebP 이미지 저장에 실패했습니다.');
        }
        imagedestroy($source);

        $size = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;
        $stmt = Database::pdo()->prepare('
            INSERT INTO media_assets (original_name, alt_text, file_path, file_url, mime_type, size_bytes, width, height, created_at)
            VALUES (:original_name, :alt_text, :file_path, :file_url, :mime_type, :size_bytes, :width, :height, NOW())
        ');
        $stmt->execute([
            'original_name' => mb_substr(Markdown::normalize((string) ($file['name'] ?? $filename)), 0, 190, 'UTF-8'),
            'alt_text' => mb_substr(Markdown::normalize(trim($altText)), 0, 190, 'UTF-8'),
            'file_path' => $relativePath,
            'file_url' => $relativePath,
            'mime_type' => 'image/webp',
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
        ]);

        $id = (int) Database::pdo()->lastInsertId();
        return $this->find($id) ?: [
            'id' => $id,
            'file_url' => $relativePath,
            'file_path' => $relativePath,
        ];
    }

    public function delete(int $id)
    {
        $asset = $this->find($id);
        if (!$asset) {
            return;
        }

        $path = (string) ($asset['file_path'] ?? '');
        if ($path !== '' && strpos($path, '..') === false) {
            $absolute = realpath(__DIR__ . '/../' . $path);
            $uploadsRoot = realpath(__DIR__ . '/../uploads');
            if ($absolute && $uploadsRoot && strpos($absolute, $uploadsRoot) === 0 && is_file($absolute)) {
                @unlink($absolute);
            }
        }

        $stmt = Database::pdo()->prepare('DELETE FROM media_assets WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function createImageResource(string $path, string $mime)
    {
        if ($mime === 'image/jpeg') {
            $image = @imagecreatefromjpeg($path);
        } elseif ($mime === 'image/png') {
            $image = @imagecreatefrompng($path);
        } elseif ($mime === 'image/gif') {
            $image = @imagecreatefromgif($path);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($path);
        } else {
            throw new RuntimeException('jpg, png, gif, webp 이미지만 업로드할 수 있습니다.');
        }

        if (!$image) {
            throw new RuntimeException('이미지를 열 수 없습니다.');
        }
        return $image;
    }

    private function slugFromName(string $name): string
    {
        $name = pathinfo($name, PATHINFO_FILENAME);
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9가-힣_-]+/u', '-', Markdown::normalize($name)) ?? 'image');
        $slug = trim($slug, '-_');
        return $slug !== '' ? mb_substr($slug, 0, 80, 'UTF-8') : 'image';
    }

    private function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::pdo()->exec('
            CREATE TABLE IF NOT EXISTS media_assets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                original_name VARCHAR(190) NOT NULL,
                alt_text VARCHAR(190) NOT NULL DEFAULT \'\',
                file_path VARCHAR(255) NOT NULL,
                file_url VARCHAR(255) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                width INT UNSIGNED NOT NULL DEFAULT 0,
                height INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX idx_media_assets_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        self::$schemaReady = true;
    }
}
