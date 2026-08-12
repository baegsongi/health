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
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/migrations/001_init.sql'));
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
})($tmp);

$ex = Workout::exerciseByName('스쿼트');
T::true($ex > 0, '운동 마스터를 만든다');
T::is(Workout::exerciseByName('스쿼트'), $ex, '같은 이름이면 재사용한다');

$w = Workout::ensure();
T::is(Workout::ensure(), $w, '오늘 기록은 하나만 열린다');

// 1세트 12회
for ($i = 0; $i < 12; $i++) {
    Workout::addRep($w, $ex);
}
T::is((int) Workout::currentSet($w, $ex)['reps'], 12, '+1 을 12번 → 12회');
T::is((int) Workout::currentSet($w, $ex)['set_no'], 1, '아직 1세트');

Workout::completeSet($w, $ex);
T::is((int) Workout::currentSet($w, $ex)['set_no'], 2, '세트 완료 → 2세트가 열린다');
T::is((int) Workout::currentSet($w, $ex)['reps'], 0, '새 세트는 0회로 시작');

// 빈 세트는 만들지 않는다
Workout::completeSet($w, $ex);
T::is((int) Workout::currentSet($w, $ex)['set_no'], 2, '0회짜리에서 세트 완료를 눌러도 늘지 않는다');

// 2세트 10회에서 -1
for ($i = 0; $i < 10; $i++) {
    Workout::addRep($w, $ex);
}
Workout::addRep($w, $ex, -1);
T::is((int) Workout::currentSet($w, $ex)['reps'], 9, '-1 이 먹는다');

Workout::addRep($w, $ex, -100);
T::is((int) Workout::currentSet($w, $ex)['reps'], 0, '0 아래로는 안 내려간다');

Workout::addRep($w, $ex, 9);
Workout::completeSet($w, $ex);

// 3세트 8회 + 무게
for ($i = 0; $i < 8; $i++) {
    Workout::addRep($w, $ex);
}
Workout::setWeight($w, $ex, 15.0);

$sum = Workout::summary($w);
T::is(count($sum), 1, '운동 한 종류');
T::is($sum[0]['sets'], 3, '3세트');
T::is($sum[0]['reps'], 29, '12 + 9 + 8 = 29회');
T::is($sum[0]['top_weight'], 15.0, '최고 무게 15kg');

// 세트 줄 고치기
$sets = Workout::sets($w, $ex);
Workout::editSet((int) $sets[0]['id'], $w, 20, null);
T::is(Workout::summary($w)[0]['reps'], 37, '1세트를 12 → 20 으로 고치면 합계도 바뀐다');

// 끝내기
Workout::finish($w, '테스트');
T::true(Workout::open() === null, '끝내면 열린 기록이 없다');
T::is((int) Db::value('SELECT COUNT(*) FROM workout_sets WHERE reps = 0'), 0, '0회짜리 세트는 정리된다');

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
