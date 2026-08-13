<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
use Health\Repo\Workout;
/** @var array<string,mixed> $exercise */
/** @var array<int,array<string,mixed>> $sets   쌓인 세트 */
/** @var array{reps:int,weight:?float} $current 지금 세고 있는 값 */
/** @var string $tab   'time' | 'count' */
/** @var int $totalSets */
/** @var int $totalReps */
/** @var float $totalSecs */
$id = (int) $exercise['id'];

/** 무게를 12.5 는 그대로, 20.0 은 20 으로 보여준다. */
$kg = static fn (?float $w): string => $w === null ? '' : rtrim(rtrim(number_format($w, 1), '0'), '.');

/** 화면 스크립트가 "마지막으로 성한 모습"으로 삼을 첫 상태. 실패하면 여기로 되돌아온다. */
$state = [
    'sets' => array_map(static fn (array $s): array => [
        'id'     => (int) $s['id'],
        'set_no' => (int) $s['set_no'],
        'reps'   => (int) $s['reps'],
        'weight' => $s['weight'] !== null ? (float) $s['weight'] : null,
        'secs'   => $s['secs'] !== null ? (float) $s['secs'] : null,
    ], $sets),
    'totalSets' => $totalSets,
    'totalReps' => $totalReps,
    'totalSecs' => $totalSecs,
];
?>
<h1 class="counter-name"><?= e($exercise['name']) ?></h1>

<?php /* 화면 전체가 fetch 로 움직인다. 주소·토큰은 여기 한 곳에만 둔다. */ ?>
<div class="counter" id="counter"
     data-id="<?= e($id) ?>"
     data-base="<?= url('/today/' . $id) ?>"
     data-csrf="<?= e(\Health\Csrf::token()) ?>"
     data-reps="<?= e($current['reps']) ?>"
     data-state="<?= e(json_encode($state, JSON_UNESCAPED_UNICODE)) ?>">

  <nav class="segs" role="tablist">
    <button class="seg <?= $tab === 'time' ? 'seg-on' : '' ?>" type="button" data-pane="time">시간</button>
    <button class="seg <?= $tab === 'count' ? 'seg-on' : '' ?>" type="button" data-pane="count">카운트</button>
  </nav>

  <!-- 시간 ------------------------------------------------------ -->
  <section class="pane <?= $tab === 'time' ? '' : 'pane-off' ?>" data-pane="time">
    <p class="watch" id="watch">00:00.00</p>
    <div class="counter-pad">
      <button class="btn cell" type="button" id="watch-reset">재설정</button>
      <button class="btn btn-primary cell" type="button" id="watch-toggle">시작</button>
    </div>
    <button class="btn btn-xl watch-save" type="button" id="watch-save" disabled>이 시간으로 기록</button>
    <noscript><p class="notice">시간 재기는 자바스크립트가 있어야 합니다.</p></noscript>
  </section>

  <!-- 카운트 ---------------------------------------------------- -->
  <section class="pane <?= $tab === 'count' ? '' : 'pane-off' ?>" data-pane="count">
    <div class="tally">
      <button class="tally-btn" type="button" id="rep-minus" aria-label="한 번 빼기">−</button>
      <div class="tally-mid">
        <b class="tally-n" id="rep-n"><?= e($current['reps']) ?></b>
        <span class="tally-unit">회</span>
      </div>
      <button class="tally-btn" type="button" id="rep-plus" aria-label="한 번 더하기">+</button>
    </div>

    <div class="addrow">
      <input class="input" type="number" id="weight" name="weight" step="0.5" min="0" inputmode="decimal"
             placeholder="무게 kg" value="<?= e($kg($current['weight'])) ?>">
      <span class="muted small">세트에 같이 쌓입니다</span>
    </div>

    <button class="btn btn-primary btn-rep" type="button" id="commit">+1 세트</button>

    <noscript>
      <form method="post" action="<?= url('/today/' . $id . '/rep') ?>" class="counter-pad">
        <?= \Health\Csrf::field() ?>
        <button class="btn cell" type="submit">+1 회</button>
      </form>
      <form method="post" action="<?= url('/today/' . $id . '/set') ?>">
        <?= \Health\Csrf::field() ?>
        <button class="btn btn-xl" type="submit">+1 세트</button>
      </form>
    </noscript>
  </section>

  <!-- 쌓인 기록 -------------------------------------------------- -->
  <?php
  $totals = [];
  if ($totalSecs > 0) {
      $totals[] = '<b>' . e(Workout::humanSecs($totalSecs)) . '</b>';
  }
  $totals[] = '<b>' . e($totalSets) . '</b>세트';
  if ($totalReps > 0) {
      $totals[] = '<b>' . e($totalReps) . '</b>회';
  }
  ?>
  <p class="counter-total" id="totals"><?= implode(' · ', $totals) ?></p>

  <ul class="setlist" id="setlist">
    <?php foreach ($sets as $s): ?>
      <li class="setrow" data-set="<?= e($s['id']) ?>">
        <span class="setno"><?= e($s['set_no']) ?>세트</span>
        <?php if ($s['secs'] !== null): ?>
          <span class="setval"><?= e(Workout::humanSecs((float) $s['secs'])) ?></span>
        <?php else: ?>
          <span class="setval">
            <?= e($s['reps']) ?>회<?php if ($s['weight'] !== null): ?>
              · <?= e($kg((float) $s['weight'])) ?>kg<?php endif; ?>
          </span>
        <?php endif; ?>
        <button class="setdel" type="button" data-del="<?= e($s['id']) ?>" aria-label="이 세트 지우기">✕</button>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="counter-pad">
  <a class="btn cell" href="<?= url('/today') ?>">다른 운동</a>
  <form method="post" action="<?= url('/today/finish') ?>" class="cell">
    <?= \Health\Csrf::field() ?>
    <button class="btn btn-xl" type="submit">오늘 끝내기</button>
  </form>
</div>

<script src="<?= url('/assets/counter.js') ?>" defer></script>
