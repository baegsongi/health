<?php
declare(strict_types=1);

/**
 * 미디어 내려받기 (CLI). 한 번에 다 받는다.
 *   php bin/fetch-media.php
 *   php bin/fetch-media.php --batch=5        한 번에 5개씩 (기본: 끝까지)
 *   php bin/fetch-media.php --adopt=<디렉터리>  이미 받아둔 파일을 그대로 가져다 쓴다
 *   php bin/fetch-media.php --adopt=<디렉터리> --move   복사 대신 옮긴다
 *
 * 중간에 끊어도 된다. `.part` 로 받은 뒤 이름을 바꾸므로 다시 돌리면 이미 받은 건 건너뛴다.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI 에서만 실행한다.\n");
}

require dirname(__DIR__) . '/src/App.php';

use Health\App;
use Health\MediaFetcher;

App::boot();

$opts   = getopt('', ['batch::', 'adopt::', 'move']);
$fetcher = new MediaFetcher();

if (isset($opts['adopt']) && $opts['adopt'] !== false) {
    $dir  = (string) $opts['adopt'];
    $move = isset($opts['move']);
    if (!is_dir($dir)) {
        fwrite(STDERR, "디렉터리가 없다: $dir\n");
        exit(1);
    }
    echo ($move ? '옮기는' : '복사하는') . " 중: $dir → " . App::mediaDir() . "\n";
    $r = $fetcher->adoptFrom($dir, $move);
    echo "가져옴 {$r['copied']} · 이미 있음 {$r['skipped']} · 못 찾음 {$r['missing']}"
        . ' · ' . MediaFetcher::humanBytes($r['bytes']) . "\n";
} else {
    $batch = isset($opts['batch']) ? max(1, (int) $opts['batch']) : 0;
    $total = MediaFetcher::stats()['total'];
    $n     = 0;

    while (true) {
        $before = MediaFetcher::stats();
        if ($before['pending'] + $before['failed'] === 0) {
            break;
        }
        $r = $fetcher->fetchBatch($batch > 0 ? $batch : 5, static function (string $f, string $how, int $b): void {
            printf("  %-8s %-42s %s\n", $how, $f, $b > 0 ? MediaFetcher::humanBytes($b) : '');
        });
        $n += $r['done'] + $r['failed'];
        $s  = MediaFetcher::stats();
        echo "  … 받음 {$s['done']}/{$total} · 남음 {$s['pending']} · 실패 {$s['failed']}\n";

        if ($r['done'] + $r['failed'] === 0) {
            break;   // 더 진행되지 않으면 무한 반복을 막는다
        }
        if ($batch > 0) {
            break;   // --batch 를 준 경우엔 한 번만 돈다
        }
    }
}

$s = MediaFetcher::stats();
echo "\n--- 미디어 ---\n";
echo "받음 {$s['done']} · 남음 {$s['pending']} · 실패 {$s['failed']} (전체 {$s['total']})\n";
echo '용량 ' . MediaFetcher::humanBytes($s['bytes']) . "\n";
