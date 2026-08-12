<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var string|null $error */
/** @var array<string,mixed>|null $result */
/** @var array<int,array<string,mixed>> $sources */
/** @var array{pending:int,done:int,failed:int} $media */
?>
<h1 class="h1">노션 가져오기</h1>

<?php if (!empty($error)): ?>
  <p class="error"><?= e($error) ?></p>
<?php endif; ?>

<?php if (!empty($result)): ?>
  <div class="notice">
    <b>가져왔습니다.</b> (<?= e($result['secs']) ?>초)<br>
    <?= e($result['title']) ?><br>
    페이지 <?= e($result['pages']) ?> · 블록 <?= e($result['blocks']) ?><br>
    회차 <b><?= e($result['sessions']) ?></b> ·
    운동 <b><?= e($result['exercises']) ?></b> ·
    미디어 <b><?= e($result['media_total']) ?></b>
    (새로 <?= e($result['media_new']) ?>)
  </div>
<?php endif; ?>

<form method="post" action="<?= url('/import') ?>" class="stack">
  <?= \Health\Csrf::field() ?>
  <label class="label" for="u">Craft 공유 주소 또는 Notion 페이지 주소</label>
  <input class="input" type="url" id="u" name="url" inputmode="url"
         placeholder="https://….craft.me/…" required>
  <button class="btn btn-primary btn-xl" type="submit">가져오기</button>
  <p class="muted small">글은 바로 저장됩니다. 동영상과 이미지 파일은 다음 단계에서 따로 받습니다.</p>
</form>

<h2 class="h2">미디어</h2>
<div class="notice">
  받음 <b><?= e($media['done']) ?></b> ·
  남음 <b><?= e($media['pending']) ?></b>
  <?php if ($media['failed'] > 0): ?> · 실패 <b><?= e($media['failed']) ?></b><?php endif; ?>
</div>
<?php if ($media['pending'] + $media['failed'] > 0): ?>
  <a class="btn btn-xl" href="<?= url('/import/media') ?>">미디어 내려받기</a>
<?php endif; ?>

<?php if ($sources !== []): ?>
  <h2 class="h2">가져온 출처</h2>
  <ul class="plain">
    <?php foreach ($sources as $s): ?>
      <li class="card">
        <div><?= e($s['title']) ?></div>
        <div class="muted small"><?= e($s['kind']) ?> · <?= e(mb_substr((string) $s['imported_at'], 0, 16)) ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
