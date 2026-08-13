<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var string $content */
/** @var string|null $pageTitle */
$authed = \Health\Auth::check();
$nav    = $nav ?? true;
$back   = $back ?? null;      // 좌측 상단 뒤로가기 링크. 없으면 안 보인다.
$here   = $here ?? '/';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#141414">
<?php if (!empty($refresh)): ?>
<meta http-equiv="refresh" content="<?= e($refresh['secs']) ?>; url=<?= empty($refresh['absolute']) ? url($refresh['to']) : e($refresh['to']) ?>">
<?php endif; ?>
<title><?= $pageTitle ? e($pageTitle) . ' · 송이의 GYMFLIX' : '송이의 GYMFLIX' ?></title>
<?php
$ogTitle = $pageTitle ? $pageTitle . ' · 송이의 GYMFLIX' : '송이의 GYMFLIX';
$ogDesc  = '송이의 운동기록 🏃‍♀️';
?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="송이의 GYMFLIX">
<meta property="og:title" content="<?= e($ogTitle) ?>">
<meta property="og:description" content="<?= e($ogDesc) ?>">
<meta property="og:url" content="<?= e(\Health\App::absoluteUrl($here)) ?>">
<meta property="og:image" content="<?= e(\Health\App::absoluteUrl('/assets/og.png')) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="송이의 GYMFLIX">
<meta property="og:locale" content="ko_KR">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($ogTitle) ?>">
<meta name="twitter:description" content="<?= e($ogDesc) ?>">
<meta name="twitter:image" content="<?= e(\Health\App::absoluteUrl('/assets/og.png')) ?>">
<meta name="description" content="<?= e($ogDesc) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= url('/assets/favicon-32.png') ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?= url('/assets/favicon-16.png') ?>">
<link rel="apple-touch-icon" href="<?= url('/assets/apple-touch-icon.png') ?>">
<link rel="stylesheet" href="<?= url('/assets/app.css') ?>">
</head>
<body>
<header class="top">
  <?php if ($back !== null): ?>
    <a class="backbtn" href="<?= url($back) ?>" aria-label="뒤로">
      <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M14.6 5.4 8 12l6.6 6.6" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round"></path>
      </svg>
    </a>
  <?php endif; ?>
  <a class="logo" href="<?= url('/') ?>">
    <span class="logo-ko">송이의</span>
    <span class="logo-en">GYMFLIX</span>
  </a>
</header>

<main class="wrap" id="top">
<?php if ($authed && \Health\Db::isReadOnly()): ?>
  <p class="error">지금은 읽기 전용입니다. storage 폴더에 쓰기 권한이 없어 기록과 가져오기가 저장되지 않습니다.</p>
<?php endif; ?>
<?= $content ?>
</main>

<footer class="site-foot">
  <a href="https://github.com/baegsongi/health" target="_blank" rel="noreferrer noopener">
    <span>baegsongi</span>
    <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
      <path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38
        0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01
        1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95
        0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04
        2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48
        0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/>
    </svg>
  </a>
</footer>

<?php if ($authed && $nav): ?>
  <?= \Health\View::capture('nav', ['here' => $here]) ?>
<?php endif; ?>

<?php
/**
 * 새 메시지가 도착했다는 알림. 어느 화면에 있든 뜬다.
 * "확인" 을 누르면 읽은 것으로 표시하고 오늘의 운동으로 간다 — 누르기 전까지 계속 뜬다.
 * JavaScript 없이 폼 하나로 끝낸다.
 */
$coachNew = $authed ? \Health\Coach::unseen() : null;
?>
<?php if ($coachNew !== null): ?>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="coach-new">
    <div class="modal-card">
      <p class="modal-emoji" aria-hidden="true">💌</p>
      <p class="modal-h" id="coach-new">AI PT쌤의 메시지가 도착했어요</p>
      <form method="post" action="<?= url('/coach/seen') ?>">
        <?= \Health\Csrf::field() ?>
        <input type="hidden" name="id" value="<?= e($coachNew) ?>">
        <button class="btn btn-primary btn-xl" type="submit">확인</button>
      </form>
    </div>
  </div>
<?php endif; ?>
</body>
</html>
