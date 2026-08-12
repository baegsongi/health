<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
use Health\Media;
/** @var string $part */
/** @var array<int,array<string,mixed>> $items */
/** @var array<string,array{kind:string,file:string,status:string}> $mediaMap */
?>
<h1 class="h1"><?= e($part) ?> <span class="muted small"><?= e(count($items)) ?>개</span></h1>

<?php /* 목차 — 누르면 그 운동으로 바로 갑니다. */ ?>
<nav class="toc">
  <div class="toc-h">목차</div>
  <ol class="toc-list">
    <?php foreach ($items as $it): ?>
      <li>
        <a href="#x<?= (int) $it['id'] ?>">
          <span class="toc-name"><?= e($it['name']) ?></span>
          <span class="muted small"><?= e($it['date'] ?? '') ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>

<div class="divided">
<?php foreach ($items as $it): ?>
  <section class="ex" id="x<?= (int) $it['id'] ?>">
    <h2 class="ex-name"><?= e($it['name']) ?></h2>
    <div class="ex-tags">
      <a class="chip chip-link" href="<?= url('/log/session/' . (int) $it['session_id']) ?>">
        <?= e($it['code'] ?? '') ?> · <?= e($it['date'] ?? '') ?><?= ($it['weekday'] ?? '') !== '' ? '(' . e($it['weekday']) . ')' : '' ?>
      </a>
      <?php if (($it['meta'] ?? '') !== ''): ?><span class="ex-meta"><?= e($it['meta']) ?></span><?php endif; ?>
      <a class="sec-top" href="#top">↑ 목차</a>
    </div>
    <div class="body"><?= Media::expand((string) $it['body_html'], $mediaMap) ?></div>
  </section>
<?php endforeach; ?>
</div>
