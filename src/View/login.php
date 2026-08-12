<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var string|null $error */
/** @var bool $configured */
/** @var string $username */
?>
<div class="gate">

<?php if (!($configured ?? true)): ?>
  <p class="notice">
    비밀번호가 아직 설정되지 않았습니다.<br>
    <code>config.local.php</code> 의 <code>password_hash</code> 를 채워 주세요.
  </p>
<?php else: ?>
  <?php if (!empty($error)): ?>
    <p class="error"><?= e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="<?= url('/login') ?>" class="stack gate-form">
    <?= \Health\Csrf::field() ?>

    <label class="label" for="uid">아이디</label>
    <?php /* disabled 는 값이 전송되지 않는다. readonly + 스타일로 잠긴 것처럼 보이게 한다. */ ?>
    <input class="input input-locked" type="text" id="uid" name="username"
           value="<?= e($username) ?>" readonly tabindex="-1" aria-readonly="true">

    <label class="label" for="pw">비밀번호</label>
    <input class="input" type="password" id="pw" name="password" autocomplete="current-password"
           autocapitalize="off" autocorrect="off" spellcheck="false" required autofocus>

    <button class="btn btn-primary btn-xl" type="submit">입장하기</button>
  </form>
<?php endif; ?>

</div>
