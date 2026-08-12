<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
use Health\Media;
use Health\Parts;
/** @var array<string,mixed> $session */
/** @var array<int,array<string,mixed>> $exercises */
/** @var array<string,array{kind:string,file:string,status:string}> $mediaMap */
?>
<h1 class="h1"><?= e($session['title']) ?></h1>

<?php if (!empty($session['intro_html'])): ?>
  <div class="body"><?= Media::expand((string) $session['intro_html'], $mediaMap) ?></div>
<?php endif; ?>

<div class="divided">
<?php foreach ($exercises as $i => $ex): ?>
  <section class="ex">
    <h2 class="ex-name">
      <span class="ex-no"><?= e($i + 1) ?></span>
      <?= e($ex['name']) ?>
    </h2>
    <div class="ex-tags">
      <?php foreach (Parts::of((string) $ex['name']) as $p): ?>
        <a class="chip chip-link" href="<?= url('/log/part/' . rawurlencode($p)) ?>"><?= e($p) ?></a>
      <?php endforeach; ?>
      <?php if (($ex['meta'] ?? '') !== ''): ?>
        <span class="ex-meta"><?= e($ex['meta']) ?></span>
      <?php endif; ?>
    </div>
    <div class="body"><?= Media::expand((string) $ex['body_html'], $mediaMap) ?></div>
  </section>
<?php endforeach; ?>

</div>

<?php if (!empty($session['notes_html'])): ?>
  <section class="ex">
    <div class="body"><?= Media::expand((string) $session['notes_html'], $mediaMap) ?></div>
  </section>
<?php endif; ?>
