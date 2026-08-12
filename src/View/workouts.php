<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var array<int,array<string,mixed>> $workouts */
?>
<h1 class="h1">지난 기록</h1>

<?php if ($workouts === []): ?>
  <p class="notice">아직 기록이 없습니다.</p>
  <a class="btn btn-primary btn-xl" href="<?= url('/today') ?>">오늘의 운동 시작</a>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($workouts as $w): ?>
      <li>
        <a class="card card-link" href="<?= url('/workouts/' . (int) $w['id']) ?>">
          <div class="card-top">
            <span class="card-date"><?= e(date('y.m.d', strtotime((string) $w['started_at']))) ?></span>
            <span class="muted small"><?= e(date('H:i', strtotime((string) $w['started_at']))) ?></span>
            <?php if (empty($w['ended_at'])): ?><span class="chip">진행 중</span><?php endif; ?>
          </div>
          <div class="muted small">
            운동 <?= e($w['n_ex']) ?> · <?= e($w['n_sets']) ?>세트 · <?= e($w['n_reps']) ?>회
          </div>
          <?php if (!empty($w['memo'])): ?><div class="muted small"><?= e($w['memo']) ?></div><?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
