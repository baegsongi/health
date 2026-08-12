<?php
declare(strict_types=1);

/**
 * 가져오기 1단계(텍스트)를 CLI 로. 미디어는 bin/fetch-media.php 가 받는다.
 *   php bin/import.php https://<space>.craft.me/<shareId>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI 에서만 실행한다.\n");
}

require dirname(__DIR__) . '/src/App.php';

use Health\App;
use Health\Db;
use Health\Notion\Import;

App::boot();
Db::migrate();

$url = $argv[1] ?? '';
if ($url === '') {
    fwrite(STDERR, "쓰는 법: php bin/import.php <craft 또는 notion URL>\n");
    exit(1);
}

$r = Import::run($url, static function (string $what, string $key, int $n): void {
    echo "  [$what $key] 누적 블록 $n\n";
});

echo "\n--- 가져오기 완료 ({$r['secs']}초) ---\n";
echo "출처   {$r['kind']} · {$r['title']}\n";
echo "페이지 {$r['pages']} · 블록 {$r['blocks']}\n";
echo "회차   {$r['sessions']}\n";
echo "운동   {$r['exercises']}\n";
echo "미디어 {$r['media_total']} (새로 {$r['media_new']})\n";
echo "\n미디어는 아직 안 받았다. php bin/fetch-media.php 로 받는다.\n";
