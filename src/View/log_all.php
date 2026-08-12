<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
use Health\Media;
/** @var array<int,array{session:array<string,mixed>,exercises:array<int,array<string,mixed>>}> $groups */
/** @var array<string,array{kind:string,file:string,status:string}> $mediaMap */
?>
<h1 class="h1">전체 PT 기록 <span class="muted small"><?= e(count($groups)) ?>회차</span></h1>

<?php /* 목차 — 누르면 그 일자로 바로 간다. 같은 화면 안에서 이동한다. */ ?>
<nav class="toc">
  <div class="toc-h">목차</div>
  <ol class="toc-list">
    <?php foreach ($groups as $g): $s = $g['session']; ?>
      <li>
        <a href="#s<?= (int) $s['id'] ?>">
          <span class="toc-code"><?= e($s['code'] ?? '-') ?></span>
          <span class="toc-date"><?= e($s['date'] ?? '') ?><?= ($s['weekday'] ?? '') !== '' ? '(' . e($s['weekday']) . ')' : '' ?></span>
          <span class="muted small"><?= e(count($g['exercises'])) ?>개</span>
        </a>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>

<?php foreach ($groups as $g): $s = $g['session']; ?>
  <section class="sec" id="s<?= (int) $s['id'] ?>">
    <h2 class="sec-h">
      <a href="<?= url('/log/session/' . (int) $s['id']) ?>"><?= e($s['title']) ?></a>
      <a class="sec-top" href="#top">↑ 목차</a>
    </h2>

    <?php if (!empty($s['intro_html'])): ?>
      <div class="body"><?= Media::expand((string) $s['intro_html'], $mediaMap) ?></div>
    <?php endif; ?>

    <?php foreach ($g['exercises'] as $i => $ex): ?>
      <div class="ex">
        <h3 class="ex-name"><span class="ex-no"><?= e($i + 1) ?></span><?= e($ex['name']) ?></h3>
        <?php if (($ex['meta'] ?? '') !== ''): ?><div class="ex-meta"><?= e($ex['meta']) ?></div><?php endif; ?>
        <div class="body"><?= Media::expand((string) $ex['body_html'], $mediaMap) ?></div>
      </div>
    <?php endforeach; ?>

    <?php if (!empty($s['notes_html'])): ?>
      <div class="body"><?= Media::expand((string) $s['notes_html'], $mediaMap) ?></div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
