<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var array<int,array<string,mixed>> $sessions */
?>
<h1 class="h1">일자별 PT 기록 <span class="muted small"><?= e(count($sessions)) ?>회차</span></h1>

<ul class="plain">
<?php foreach ($sessions as $s): ?>
  <li>
    <a class="card card-link" href="<?= url('/log/session/' . (int) $s['id']) ?>">
      <div class="card-top">
        <span class="chip"><?= e($s['code'] ?? '-') ?></span>
        <span class="card-date"><?= e($s['date'] ?? '') ?><?= ($s['weekday'] ?? '') !== '' ? '(' . e($s['weekday']) . ')' : '' ?></span>
        <?php if (($s['time'] ?? '') !== ''): ?><span class="muted small"><?= e($s['time']) ?></span><?php endif; ?>
      </div>
    </a>
  </li>
<?php endforeach; ?>
</ul>
