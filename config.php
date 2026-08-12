<?php
declare(strict_types=1);

/**
 * 기본 설정. 비밀은 여기 두지 않는다.
 * config.local.php(git 제외)가 있으면 그 배열이 이 값을 덮어쓴다.
 */

$config = [
    // 아이디는 하나로 고정한다. 화면에 박아두고 바꿀 수 없게 보여준다.
    'username' => 'bs2',

    // bin/set-password.php 로 만든 password_hash() 값. 비어 있으면 로그인이 불가능하다.
    'password_hash' => '',

    // 세션 쿠키 이름
    'session_name' => 'healthsid',

    // https 로만 서비스하면 true. 로컬 http 확인 중이면 false.
    'secure_cookie' => true,

    // 앱이 서브디렉터리에 얹힐 때의 접두사. 예: '/health'
    'base_path' => '',

    // Notion 비공식 API 에 보낼 User-Agent (브라우저 것이어야 한다)
    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
        . ' (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace($config, require $local);
}

return $config;
