<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var array<int,array{part:string,unclassified:bool,count:int}> $parts */
?>
<h1 class="h1">운동부위별</h1>

<ul class="plain">
<?php foreach ($parts as $p): ?>
  <li>
    <a class="card card-link <?= $p['unclassified'] ? 'card-loose' : '' ?>"
       href="<?= url('/log/part/' . rawurlencode($p['part'])) ?>">
      <div class="card-top">
        <b><?= e($p['part']) ?></b>
        <span class="muted small"><?= e($p['count']) ?>회</span>
      </div>
    </a>
  </li>
<?php endforeach; ?>
</ul>
