<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var array<string,mixed> $exercise */
/** @var array<int,array<string,mixed>> $sets */
/** @var int $totalSets */
/** @var int $totalReps */
/** @var bool $showWeight */
$id  = (int) $exercise['id'];
$cur = $sets === [] ? null : $sets[count($sets) - 1];
?>
<h1 class="counter-name"><?= e($exercise['name']) ?></h1>

<div class="counter-total">
  <b><?= e($totalSets) ?></b>세트 · <b><?= e($totalReps) ?></b>회
</div>

<div class="counter-pad">
  <form method="post" action="<?= url('/today/' . $id . '/minus') ?>" class="cell">
    <?= \Health\Csrf::field() ?>
    <button class="btn btn-sub" type="submit">−1</button>
  </form>
  <form method="post" action="<?= url('/today/' . $id . '/set') ?>" class="cell">
    <?= \Health\Csrf::field() ?>
    <button class="btn btn-set" type="submit">세트 완료</button>
  </form>
</div>

<?php /* +1 이 가장 크고 가장 아래쪽에 온다. 엄지가 닿는 자리다(문서 §7). */ ?>
<form method="post" action="<?= url('/today/' . $id . '/rep') ?>">
  <?= \Health\Csrf::field() ?>
  <button class="btn btn-primary btn-rep" type="submit">+1 회</button>
</form>

<?php if ($showWeight): ?>
  <form method="post" action="<?= url('/today/' . $id . '/weight') ?>" class="addrow">
    <?= \Health\Csrf::field() ?>
    <input class="input" type="number" name="weight" step="0.5" min="0" inputmode="decimal"
           placeholder="무게 kg" value="<?= $cur && $cur['weight'] !== null ? e($cur['weight']) : '' ?>">
    <button class="btn btn-add" type="submit">기록</button>
  </form>
<?php else: ?>
  <p><a class="linkish-a" href="<?= url('/today/' . $id . '?weight=1') ?>">무게 기록</a></p>
<?php endif; ?>

<ul class="setlist">
  <?php foreach ($sets as $i => $s): $last = $i === count($sets) - 1; ?>
    <li class="setrow <?= $last ? 'setrow-on' : '' ?>">
      <form method="post" action="<?= url('/today/' . $id . '/edit') ?>" class="setform">
        <?= \Health\Csrf::field() ?>
        <input type="hidden" name="set_id" value="<?= e($s['id']) ?>">
        <span class="setno"><?= e($s['set_no']) ?>세트</span>
        <input class="input input-mini" type="number" name="reps" min="0" inputmode="numeric"
               value="<?= e($s['reps']) ?>">
        <input class="input input-mini" type="number" name="weight" step="0.5" min="0" inputmode="decimal"
               placeholder="kg" value="<?= $s['weight'] !== null ? e($s['weight']) : '' ?>">
        <button class="btn btn-mini" type="submit">고침</button>
        <?php if ($last): ?><span class="muted small">진행 중</span><?php endif; ?>
      </form>
    </li>
  <?php endforeach; ?>
</ul>

<div class="counter-pad">
  <a class="btn cell" href="<?= url('/today') ?>">다른 운동</a>
  <form method="post" action="<?= url('/today/finish') ?>" class="cell">
    <?= \Health\Csrf::field() ?>
    <button class="btn btn-xl" type="submit">오늘 끝내기</button>
  </form>
</div>
