<?php
declare(strict_types=1);
use function Health\e;
use function Health\url;

/**
 * 모바일 하단 바. nugabox-core 의 rp-mobile-nav 결을 그대로 가져왔다 —
 * 콘텐츠 위에 떠 있는 반투명 유리 알약 바, 아이콘은 "평소 빈 선 / 현재 탭은 꽉 참".
 * 로그아웃은 실수로 눌리기 쉬워서 여기 두지 않고 홈 화면 아래 작은 버튼으로 뺐다.
 *
 * @var string $here 현재 경로
 */
$here = $here ?? '/';

/** 집 — 지붕이 각진 오각형 */
$HOUSE = 'M11.1 2.75 4.35 8.9a1.75 1.75 0 0 0-.57 1.29v8.26A2.55 2.55 0 0 0 6.33 21h11.34'
    . 'a2.55 2.55 0 0 0 2.55-2.55v-8.26c0-.5-.21-.96-.57-1.29L12.9 2.75a1.35 1.35 0 0 0-1.8 0Z';

/** 달력 — 일자별 */
$CAL_BODY = 'M5.2 5.4h13.6a1.6 1.6 0 0 1 1.6 1.6v11.4a1.6 1.6 0 0 1-1.6 1.6H5.2a1.6 1.6 0 0 1-1.6-1.6V7a1.6 1.6 0 0 1 1.6-1.6Z';
$CAL_TOP  = 'M3.6 9.6h16.8';
$CAL_PINS = 'M8 3.4v3.4M16 3.4v3.4';
$CAL_DOTS = 'M7.6 13.2h2v2h-2ZM11 13.2h2v2h-2ZM14.4 13.2h2v2h-2Z';

/** 사람 몸 — 운동부위 */
$BODY_HEAD = 'M12 2.6a2.3 2.3 0 1 1 0 4.6 2.3 2.3 0 0 1 0-4.6Z';
$BODY_TRUNK = 'M12 8.2c-1.5 0-2.6.3-3.6.8L4.9 10.7M12 8.2c1.5 0 2.6.3 3.6.8l3.5 1.7'
    . 'M12 8.2v6.1M9.2 14.3 8.1 21M14.8 14.3 15.9 21M9.2 14.3h5.6';
$BODY_FILL = 'M12 2.6a2.3 2.3 0 1 1 0 4.6 2.3 2.3 0 0 1 0-4.6Zm0 5.6c1.6 0 2.9.3 4 .9l3.3 1.6-.9 1.8-3-1.4'
    . '-.5 4.3 1.2 6-1.9.4-1.3-6.1h-1.8L9.8 21.8l-1.9-.4 1.2-6-.5-4.3-3 1.4-.9-1.8L8 9.1c1.1-.6 2.4-.9 4-.9Z';

/** 덤벨 — 오늘의 운동 */
$BELL_LINE = 'M4.6 9.4v5.2M7.6 7.6v8.8M16.4 7.6v8.8M19.4 9.4v5.2M7.6 12h8.8';
$BELL_FILL = 'M3.4 9.4h2.4v5.2H3.4Zm3.2-1.8h2.6v8.8H6.6Zm8 0h2.6v8.8h-2.6Zm4 1.8h2.4v5.2h-2.4ZM9.4 11h5.2v2H9.4Z';

/** @var array<int,array{0:string,1:string,2:?string,3:string,4:bool}> */
$tabs = [
    // [경로, 이름, 선(비활성) path, 채움(활성) path, 활성일 때 evenodd]
    ['/',           '홈',     $HOUSE,                                  $HOUSE,     false],
    ['/log/dates',  '일자',   $CAL_BODY . $CAL_TOP . $CAL_PINS,        null,       false],
    ['/log/parts',  '운동부위', $BODY_HEAD . $BODY_TRUNK,               $BODY_FILL, true],
    ['/today',      '오늘',   $BELL_LINE,                              $BELL_FILL, true],
];

$isOn = static function (string $href) use ($here): bool {
    return match ($href) {
        '/'          => $here === '/',
        '/log/dates' => str_starts_with($here, '/log/dates') || str_starts_with($here, '/log/session')
                        || str_starts_with($here, '/log/all'),
        '/log/parts' => str_starts_with($here, '/log/parts') || str_starts_with($here, '/log/part/'),
        default      => str_starts_with($here, $href),
    };
};
?>
<nav class="mnav" aria-label="아래 메뉴">
  <div class="mnav-bar">
    <?php foreach ($tabs as [$href, $label, $line, $fill, $evenOdd]): ?>
      <?php $on = $isOn($href); ?>
      <a class="mnav-item<?= $on ? ' is-active' : '' ?>" href="<?= url($href) ?>" aria-label="<?= e($label) ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <?php if ($on && $fill !== null): ?>
            <path d="<?= e($fill) ?>" fill="currentColor"<?= $evenOdd ? ' fill-rule="evenodd"' : '' ?>></path>
          <?php elseif ($on): ?>
            <?php /* 달력은 통째로 채우면 뭉개져서, 몸통만 채우고 격자는 선으로 남긴다 */ ?>
            <path d="<?= e($CAL_BODY) ?>" fill="currentColor"></path>
            <path d="<?= e($CAL_PINS) ?>" fill="none" stroke="currentColor" stroke-width="1.7"
                  stroke-linecap="round"></path>
            <path d="<?= e($CAL_DOTS) ?>" fill="var(--bg)"></path>
          <?php else: ?>
            <path d="<?= e($line) ?>" fill="none" stroke="currentColor" stroke-width="1.7"
                  stroke-linecap="round" stroke-linejoin="round"></path>
          <?php endif; ?>
        </svg>
      </a>
    <?php endforeach; ?>
  </div>
</nav>
