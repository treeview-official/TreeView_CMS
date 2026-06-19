<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

$basePath = defined('BASE_PATH') ? trim((string) BASE_PATH) : '';
$basePath = $basePath === '/' ? '' : '/' . trim($basePath, '/');
$basePath = $basePath === '' ? '' : $basePath;

echo "User-agent: *\n";
echo "Allow: {$basePath}/\n";
echo "Disallow: {$basePath}/admin.php\n";
echo "Disallow: {$basePath}/install.php\n\n";
echo "Sitemap: {$basePath}/sitemap.php\n";
