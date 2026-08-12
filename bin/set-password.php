<?php
declare(strict_types=1);

// CLI 전용: php bin/set-password.php '비밀번호'
// 출력된 해시를 config.local.php 의 password_hash 에 붙여넣는다.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI 에서만 실행한다.\n");
}

$pw = $argv[1] ?? '';
if ($pw === '') {
    fwrite(STDERR, "쓰는 법: php bin/set-password.php '비밀번호'\n");
    exit(1);
}
if (mb_strlen($pw) < 8) {
    fwrite(STDERR, "비밀번호는 8자 이상으로 한다.\n");
    exit(1);
}

$hash = password_hash($pw, PASSWORD_DEFAULT);

echo "config.local.php 에 아래를 넣는다:\n\n";
echo "    'password_hash' => " . var_export($hash, true) . ",\n\n";
