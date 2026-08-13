<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
use Health\Parts;
/** @var array<int,array<string,mixed>> $exercises */
/** @var array<int,string> $lastNotes */
/** @var string $tab */
/** @var array<int,string> $tabs */
/** @var array<string,mixed>|null $openWorkout */
/** @var array{greeting:string,lead:string,goals:array<int,array{name:string,target:string}>} $coach */
?>
<div class="page-head">
  <h1 class="h1">오늘의 운동</h1>
  <a class="btn-sm" href="<?= url('/workouts') ?>">지난 기록</a>
</div>

<section class="coach">
  <h2 class="coach-h">AI PT쌤의 메시지</h2>
  <p class="coach-body">
    <?= e($coach['greeting']) ?>
    <?php if ($coach['lead'] !== ''): ?><br><?= e($coach['lead']) ?><?php endif; ?>
  </p>
  <?php if ($coach['goals'] !== []): ?>
    <p class="coach-goal">오늘의 목표</p>
    <table class="coach-table">
      <thead><tr><th>운동</th><th>시간 및 횟수</th></tr></thead>
      <tbody>
        <?php foreach ($coach['goals'] as $g): ?>
          <tr><td><?= e($g['name']) ?></td><td><?= e($g['target']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php if ($openWorkout !== null): ?>
  <p class="notice">
    오늘 기록이 진행 중입니다.
    <a class="chip chip-link" href="<?= url('/today/finish') ?>">요약 보기</a>
  </p>
<?php endif; ?>

<form method="post" action="<?= url('/today/add') ?>" class="addrow">
  <?= \Health\Csrf::field() ?>
  <select class="select" name="part" aria-label="운동부위">
    <option value="">운동부위</option>
    <?php foreach (Parts::names() as $p): ?>
      <option value="<?= e($p) ?>"><?= e($p) ?></option>
    <?php endforeach; ?>
  </select>
  <input class="input" type="text" name="name" placeholder="운동 이름" required>
  <button class="btn btn-add" type="submit">추가</button>
</form>

<nav class="tabs">
  <?php foreach ($tabs as $t): ?>
    <a class="tab <?= $t === $tab ? 'tab-on' : '' ?>"
       href="<?= url('/today?part=' . rawurlencode($t)) ?>"><?= e($t) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($exercises === []): ?>
  <p class="notice">이 부위에 등록된 운동이 없습니다.</p>
<?php else: ?>
  <div class="grid">
    <?php foreach ($exercises as $ex): ?>
      <?php $parts = Parts::ofExercise((string) $ex['name'], $ex['part_override'] ?? null); ?>
      <a class="pick" href="<?= url('/today/' . (int) $ex['id']) ?>">
        <?php if ($parts !== []): ?>
          <span class="badges">
            <?php foreach ($parts as $p): ?><span class="badge"><?= e($p) ?></span><?php endforeach; ?>
          </span>
        <?php endif; ?>
        <span class="pick-name"><?= e($ex['name']) ?></span>
        <?php if (isset($lastNotes[(int) $ex['id']])): ?>
          <span class="pick-last">마지막: <?= e($lastNotes[(int) $ex['id']]) ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
