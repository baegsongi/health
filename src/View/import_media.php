<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
use Health\MediaFetcher;
/** @var array{pending:int,done:int,failed:int,total:int,bytes:int} $stat */
/** @var array<int,string> $justDone */
/** @var bool $running */

$total = max(1, $stat['total']);
$pct   = (int) round($stat['done'] / $total * 100);
?>
<h1 class="h1">미디어 내려받기</h1>

<div class="notice">
  <div class="bar"><span style="width: <?= e($pct) ?>%"></span></div>
  <div>
    받음 <b><?= e($stat['done']) ?></b> / <?= e($stat['total']) ?>
    (<?= e($pct) ?>%) · <?= e(MediaFetcher::humanBytes($stat['bytes'])) ?>
  </div>
  <div class="muted small">
    남음 <?= e($stat['pending']) ?><?php if ($stat['failed'] > 0): ?> · 실패 <?= e($stat['failed']) ?><?php endif; ?>
  </div>
</div>

<?php if ($justDone !== []): ?>
  <ul class="plain small muted">
    <?php foreach ($justDone as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($stat['pending'] + $stat['failed'] > 0): ?>
  <?php if ($running): ?>
    <p class="muted">몇 개씩 나눠서 받고 있습니다. 이 화면을 열어두시면 저절로 이어집니다.</p>
    <form method="post" action="<?= url('/import/media') ?>" class="stack">
      <?= \Health\Csrf::field() ?>
      <button class="btn btn-primary btn-xl" type="submit">계속 받기</button>
    </form>
    <p><a class="btn" href="<?= url('/import') ?>">멈추기</a></p>
  <?php else: ?>
    <form method="post" action="<?= url('/import/media') ?>" class="stack">
      <?= \Health\Csrf::field() ?>
      <button class="btn btn-primary btn-xl" type="submit">내려받기 시작</button>
    </form>
    <p class="muted small">
      한 번에 <?= e(\Health\MediaFetcher::BATCH) ?>개씩 받고 화면이 저절로 다음으로 넘어갑니다.
      중간에 나가셔도 됩니다. 다시 오시면 이미 받은 것은 건너뜁니다.
    </p>
  <?php endif; ?>
<?php else: ?>
  <p class="notice">다 받았습니다.</p>
  <a class="btn btn-primary btn-xl" href="<?= url('/log') ?>">기록 보기</a>
<?php endif; ?>

<p><a class="btn" href="<?= url('/import') ?>">가져오기로</a></p>
