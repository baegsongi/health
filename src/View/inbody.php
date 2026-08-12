<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var string $scheme */
/** @var string $store */
?>
<h1 class="h1">인바디</h1>

<p class="notice" id="inbody"
   data-scheme="<?= e($scheme) ?>"
   data-store="<?= e($store) ?>"
   data-wait="1200">인바디 앱을 여는 중입니다.</p>

<div class="stack">
  <a class="btn btn-primary btn-xl" href="<?= e($scheme) ?>">인바디 앱 열기</a>
  <a class="btn btn-xl" href="<?= e($store) ?>" target="_blank" rel="noreferrer noopener">앱이 없으면 App Store</a>
</div>

<script src="<?= url('/assets/inbody.js') ?>" defer></script>
