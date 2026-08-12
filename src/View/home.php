<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
?>
<p class="dday"><?= e($ddayLine) ?></p>

<div class="stack">
  <a class="btn btn-primary btn-xl" href="<?= url('/today') ?>">오늘의 운동</a>
  <a class="btn btn-xl" href="<?= url('/log/dates') ?>">일자별 PT 기록</a>
  <a class="btn btn-xl" href="<?= url('/log/parts') ?>">운동부위별 PT 기록</a>
  <a class="btn btn-xl" href="<?= url('/log/all') ?>">전체 PT 기록</a>
  <a class="btn btn-xl" href="<?= url('/inbody') ?>" target="_blank" rel="noreferrer noopener">인바디 보기</a>
</div>

<div class="home-sub">
  <a class="btn-sm" href="<?= url('/import') ?>">PT 기록 가져오기</a>
  <a class="btn-sm" href="<?= url('/dday') ?>">D-DAY 설정</a>
  <form method="post" action="<?= url('/logout') ?>">
    <?= \Health\Csrf::field() ?>
    <button class="btn-sm" type="submit">로그아웃</button>
  </form>
</div>
