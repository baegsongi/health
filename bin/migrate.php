<?php
declare(strict_types=1);

// CLI 전용: php bin/migrate.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI 에서만 실행한다.\n");
}

require dirname(__DIR__) . '/src/App.php';

Health\App::boot();
$done = Health\Db::migrate();

if ($done === []) {
    echo "적용할 마이그레이션이 없다. (이미 최신)\n";
} else {
    foreach ($done as $name) {
        echo "적용: $name\n";
    }
}

$tables = Health\Db::all(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
);
echo '테이블 ' . count($tables) . '개: '
    . implode(', ', array_column($tables, 'name')) . "\n";
echo 'DB: ' . Health\App::storage('health.sqlite') . "\n";
