<?php
declare(strict_types=1);

use Health\Db;
use Health\Media;
use Health\MediaFetcher;
use Health\Repo\Workout;

T::group('세트 집계 (문서 §7)');

// 임시 DB 로 갈아끼운다. 진짜 storage 는 건드리지 않는다.
$tmp = sys_get_temp_dir() . '/health-test-' . getmypid() . '.sqlite';
@unlink($tmp);
(function (string $tmp): void {
    $ref = new ReflectionClass(Db::class);
    $pdo = new PDO('sqlite:' . $tmp, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    // 마이그레이션을 전부 순서대로 적용한다 — 스키마가 늘 때마다 여기도 따라와야 한다.
    $files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $f) {
        $pdo->exec((string) file_get_contents($f));
    }
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
})($tmp);

$ex = Workout::exerciseByName('스쿼트');
T::true($ex > 0, '운동 마스터를 만든다');
T::is(Workout::exerciseByName('스쿼트'), $ex, '같은 이름이면 재사용한다');

$w = Workout::ensure();
T::is(Workout::ensure(), $w, '오늘 기록은 하나만 열린다');

// 12회를 센다. 아직 쌓인 세트는 없다.
for ($i = 0; $i < 12; $i++) {
    Workout::addRep($w, $ex);
}
T::is(Workout::current($w, $ex)['reps'], 12, '+1 을 12번 → 12회');
T::is(count(Workout::sets($w, $ex)), 0, '쌓기 전에는 세트가 없다');

// +1 세트 → 12회짜리 한 줄. 세던 값은 그대로 남는다.
Workout::commitSet($w, $ex);
T::is(count(Workout::sets($w, $ex)), 1, '+1 세트 → 한 줄 쌓인다');
T::is(Workout::current($w, $ex)['reps'], 12, '세던 값은 그대로 남는다');

// 또 누르면 같은 12회로 2세트.
Workout::commitSet($w, $ex);
$sets = Workout::sets($w, $ex);
T::is(count($sets), 2, '또 누르면 2세트');
T::is((int) $sets[1]['reps'], 12, '2세트도 12회');
T::is((int) $sets[1]['set_no'], 2, '세트 번호가 늘어난다');

// 15 로 바꾸고 한 번 더 → 3세트는 15회.
Workout::setReps($w, $ex, 15);
Workout::setWeight($w, $ex, 20.0);
Workout::commitSet($w, $ex);
$sets = Workout::sets($w, $ex);
T::is((int) $sets[2]['reps'], 15, '15 로 바꾸고 누르면 3세트는 15회');
T::is((float) $sets[2]['weight'], 20.0, '무게도 같이 쌓인다');

// -1 · 0 아래
Workout::addRep($w, $ex, -1);
T::is(Workout::current($w, $ex)['reps'], 14, '-1 이 먹는다');
Workout::addRep($w, $ex, -100);
T::is(Workout::current($w, $ex)['reps'], 0, '0 아래로는 안 내려간다');

T::true(Workout::commitSet($w, $ex) === null, '0회일 때는 세트가 쌓이지 않는다');
T::is(count(Workout::sets($w, $ex)), 3, '그대로 3세트');

$sum = Workout::summary($w);
T::is(count($sum), 1, '운동 한 종류');
T::is($sum[0]['sets'], 3, '3세트');
T::is($sum[0]['reps'], 39, '12 + 12 + 15 = 39회');
T::is($sum[0]['top_weight'], 20.0, '최고 무게 20kg');

// 유산소 — 시간으로 쌓는다
$run = Workout::exerciseByName('러닝');
Workout::commitTime($w, $run, 1230.5);
T::true(Workout::commitTime($w, $run, 0.4) === null, '1초도 안 되면 쌓지 않는다');
$runSum = array_values(array_filter(Workout::summary($w), static fn ($s) => $s['name'] === '러닝'));
T::is(count($runSum), 1, '유산소도 집계에 잡힌다');
T::is((int) round($runSum[0]['secs']), 1231, '잰 시간이 그대로 들어간다');
T::is(Workout::humanSecs(1230.5), '20분 31초', '초를 사람 말로 바꾼다');
T::is(Workout::humanSecs(1200), '20분', '딱 떨어지면 분만');
T::is(Workout::humanSecs(45), '45초', '1분 미만은 초로');

// 세트 줄 고치기
$sets = Workout::sets($w, $ex);
Workout::editSet((int) $sets[0]['id'], $w, 20, null);
T::is(Workout::summary($w)[0]['reps'], 47, '1세트를 12 → 20 으로 고치면 합계도 바뀐다');

// 줄 지우기 — 가운데를 지우면 번호를 다시 매긴다
Workout::deleteSet((int) $sets[1]['id'], $w);
$left = Workout::sets($w, $ex);
T::is(count($left), 2, '세트 줄을 지운다');
T::is(array_map(static fn ($s) => (int) $s['set_no'], $left), [1, 2], '번호를 1부터 다시 매긴다');

// 끝내기
Workout::finish($w, '테스트');
T::true(Workout::open() === null, '끝내면 열린 기록이 없다');
T::is((int) Db::value('SELECT COUNT(*) FROM workout_current WHERE workout_id = ?', [$w]), 0,
    '세던 값은 기록이 아니므로 치운다');

$next = Workout::ensure();
T::true($next !== $w, '끝낸 뒤 새로 시작하면 새 기록이 열린다');

T::group('미디어 자리표시자 (Media::expand)');

$map = [
    'aaa' => ['kind' => 'video', 'file' => 'aaa.mp4', 'status' => 'done'],
    'bbb' => ['kind' => 'image', 'file' => 'bbb.png', 'status' => 'pending'],
    'ccc' => ['kind' => 'video', 'file' => 'ccc.mp4', 'status' => 'failed'],
];
T::true(str_contains(Media::expand('[[MEDIA:aaa]]', $map), '<video'), '받은 영상은 <video> 로');
T::true(str_contains(Media::expand('[[MEDIA:bbb]]', $map), '받는 중'), '아직 안 받았으면 "받는 중"');
T::true(str_contains(Media::expand('[[MEDIA:ccc]]', $map), '받지 못함'), '실패는 "받지 못함"');
T::is(Media::expand('[[MEDIA:1f0e3dad-9990-4b4b-b0cd-8e2b1f0e3dad]]', $map), '', '모르는 자리표시자는 지운다');
T::is(Media::expand('그냥 글', $map), '그냥 글', '자리표시자가 없으면 그대로');

T::group('용량 표기');
T::is(MediaFetcher::humanBytes(512), '512B', '바이트');
T::is(MediaFetcher::humanBytes(2048), '2KB', '킬로바이트');
T::is(MediaFetcher::humanBytes(20 * 1048576), '20MB', '메가바이트');
T::is(MediaFetcher::humanBytes(1717986918), '1.6GB', '기가바이트');

@unlink($tmp);
