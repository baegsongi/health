<?php
declare(strict_types=1);

/**
 * 오늘의 메시지 미리 만들기 (CLI). 하루에 한 번 cron 으로 돌린다.
 *   php bin/coach.php            이미 만들어져 있으면 건너뛴다
 *   php bin/coach.php --force    있어도 다시 만든다
 *   php bin/coach.php --show     만들지 않고 지금 보이는 것만 찍는다
 *
 * "오늘의 운동" 화면은 여기서 만들어 둔 것을 읽기만 한다 —
 * 화면을 여는 길목에서 API 를 기다리면 15초씩 멈춘다.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI 에서만 실행한다.\n");
}

require dirname(__DIR__) . '/src/App.php';

use Health\App;
use Health\Coach;

App::boot();

$opts = getopt('', ['force', 'show']);

/** 표 두 줄까지만 한 줄로 요약해 찍는다. */
$line = static function (array $m): string {
    $goals = [];
    foreach ($m['goals'] as $g) {
        $goals[] = $g['name'] . ' ' . $g['target'];
    }
    return $m['greeting'] . "\n" . $m['lead']
        . ($goals === [] ? '' : "\n오늘의 목표 — " . implode(' · ', $goals));
};

if (isset($opts['show'])) {
    $m = Coach::todayMessage();
    echo "[{$m['from']}]\n" . $line($m) . "\n";
    exit(0);
}

if (!isset($opts['force']) && Coach::hasToday()) {
    echo date('Y-m-d') . " 것은 이미 만들어져 있다. 건너뛴다 (--force 로 다시 만든다)\n";
    exit(0);
}

try {
    $m = Coach::refresh();
} catch (\Throwable $e) {
    fwrite(STDERR, '오늘의 메시지를 만들지 못했다: ' . $e->getMessage() . "\n");
    App::log('bin/coach.php 실패: ' . $e->getMessage());
    exit(1);
}

echo date('Y-m-d') . " 만들었다.\n" . $line($m) . "\n";
