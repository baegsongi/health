<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;
/** @var string $title */
/** @var string $message */
?>
<h1 class="h1"><?= e($title) ?></h1>
<p class="notice"><?= e($message) ?></p>
<p><a class="btn" href="<?= url('/') ?>">홈으로</a></p>
