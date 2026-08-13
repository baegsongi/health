<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var string $note   오늘 이미 적어 둔 말. 없으면 빈 문자열 */
/** @var string $day    '2026-08-12' */
/** @var bool   $saved */
/** @var bool   $waiting  AI 메시지를 만드는 중 */
/** @var bool   $failed   마지막 만들기가 실패했다 */
?>
<h1 class="h1">PT 메시지 추가</h1>

<?php if ($saved): ?>
  <p class="notice">저장되었습니다. AI 메시지가 도착하면 알려드릴게요! 😉</p>
<?php elseif ($waiting): ?>
  <p class="notice">AI PT쌤이 메시지를 쓰고 있어요… 도착하면 알려드릴게요! 😉</p>
<?php elseif ($failed): ?>
  <p class="error">
    AI 메시지를 만들지 못했습니다. 적어 두신 말은 그대로 저장돼 있으니
    잠시 뒤 <b>확인</b>을 한 번 더 눌러 주세요.
  </p>
<?php endif; ?>

<form method="post" action="<?= url('/pt-message') ?>" class="stack">
  <?= \Health\Csrf::field() ?>

  <label class="label" for="m">오늘 PT쌤이 남긴 메시지를 입력하세요</label>
  <textarea class="input textarea" id="m" name="message" rows="7"
            maxlength="<?= e(\Health\PtNote::MAX) ?>"
            placeholder="예) 오늘은 유산소 20분 먼저 하고 하체 4세트 채우세요."><?= e($note) ?></textarea>

  <button class="btn btn-primary btn-xl" type="submit">확인</button>
</form>

<p class="muted small">
  <?= e($day) ?> 날짜로 저장되고, 오늘의 운동 화면의 메시지에 바로 반영됩니다.
</p>

<p><a class="btn" href="<?= url('/today') ?>">오늘의 운동으로</a></p>
