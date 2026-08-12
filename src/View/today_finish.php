<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var array<string,mixed>|null $workout */
/** @var array<int,array<string,mixed>> $summary */
/** @var bool $ended */
?>
<h1 class="h1">오늘 요약</h1>

<?php if ($workout === null || $summary === []): ?>
  <p class="notice">아직 기록이 없습니다.</p>
  <a class="btn btn-primary btn-xl" href="<?= url('/today') ?>">운동 고르기</a>
<?php else: ?>
  <p class="muted small">
    <?= e(date('Y.m.d H:i', strtotime((string) $workout['started_at']))) ?>
    <?php if (!empty($workout['ended_at'])): ?>
      – <?= e(date('H:i', strtotime((string) $workout['ended_at']))) ?>
    <?php endif; ?>
  </p>

  <ul class="plain">
    <?php $ts = 0; $tr = 0; foreach ($summary as $s): $ts += $s['sets']; $tr += $s['reps']; ?>
      <li class="card">
        <div class="card-top">
          <b><?= e($s['name']) ?></b>
          <span class="muted small">
            <?= e($s['sets']) ?>세트 · <?= e($s['reps']) ?>회
            <?php if ($s['top_weight'] !== null): ?> · 최고 <?= e($s['top_weight']) ?>kg<?php endif; ?>
          </span>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>

  <p class="notice">합계 <b><?= e($ts) ?></b>세트 · <b><?= e($tr) ?></b>회</p>

  <?php if (!$ended): ?>
    <form method="post" action="<?= url('/today/finish') ?>" class="stack">
      <?= \Health\Csrf::field() ?>
      <input class="input" type="text" name="memo" placeholder="메모 (선택)">
      <button class="btn btn-primary btn-xl" type="submit">오늘 끝내기</button>
    </form>
    <p><a class="btn" href="<?= url('/today') ?>">운동 더 하기</a></p>
  <?php else: ?>
    <p class="notice">끝냈습니다. 수고하셨어요.</p>
    <a class="btn btn-xl" href="<?= url('/workouts') ?>">지난 기록</a>
  <?php endif; ?>
<?php endif; ?>
