<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var array{title:string,date:string,days:int,passed:bool}|null $dday */
/** @var string|null $saved */
?>
<h1 class="h1">D-DAY 설정</h1>

<?php if (!empty($saved)): ?>
  <p class="notice">저장했습니다.</p>
<?php endif; ?>

<?php if ($dday !== null): ?>
  <p class="notice">
    <b><?= e($dday['title']) ?></b> · <?= e($dday['date']) ?><br>
    <?php if ($dday['passed']): ?>
      <span class="muted">지난 날짜입니다. 홈에는 응원 문구만 나옵니다.</span>
    <?php else: ?>
      <span class="muted"><?= e($dday['days']) ?>일 남았습니다.</span>
    <?php endif; ?>
  </p>
<?php endif; ?>

<form method="post" action="<?= url('/dday') ?>" class="stack">
  <?= \Health\Csrf::field() ?>

  <label class="label" for="t">디데이 제목</label>
  <input class="input" type="text" id="t" name="title" maxlength="30"
         placeholder="바디프로필" value="<?= e($dday['title'] ?? '') ?>" required>

  <label class="label" for="d">디데이 날짜</label>
  <input class="input" type="date" id="d" name="date"
         value="<?= e($dday['date'] ?? '') ?>" required>

  <button class="btn btn-primary btn-xl" type="submit">저장</button>
</form>
