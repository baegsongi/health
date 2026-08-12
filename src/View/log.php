<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var array{sessions:int,exercises:int,videos:int,images:int} $totals */
?>
<h1 class="h1">기록 보기</h1>

<div class="stack">
  <a class="btn btn-xl" href="<?= url('/log/dates') ?>">일자별</a>
  <a class="btn btn-xl" href="<?= url('/log/parts') ?>">운동부위별</a>
  <a class="btn btn-xl" href="<?= url('/log/all') ?>">전체</a>
</div>

<p class="muted stat">
  회차 <?= e($totals['sessions']) ?> · 운동 <?= e($totals['exercises']) ?> ·
  영상 <?= e($totals['videos']) ?> · 이미지 <?= e($totals['images']) ?>
</p>
