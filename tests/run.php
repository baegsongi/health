<?php
declare(strict_types=1);

/**
 * 아주 작은 테스트 러너. Composer 를 쓰지 않으므로 PHPUnit 도 없다.
 *   php tests/run.php
 * 도메인 로직(부위 분류 · 제목 파싱 · 첨부 URL 조립 · 세트 집계)만 본다.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI 에서만 실행한다.\n");
}

require dirname(__DIR__) . '/src/App.php';
Health\App::boot();

final class T
{
    public static int $pass = 0;
    /** @var array<int,string> */
    public static array $fail = [];
    public static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n$name\n";
    }

    public static function is(mixed $actual, mixed $expected, string $what): void
    {
        if ($actual === $expected) {
            self::$pass++;
            echo "  ok   $what\n";
            return;
        }
        self::$fail[] = self::$group . ' / ' . $what;
        echo "  FAIL $what\n";
        echo '       기대: ' . self::dump($expected) . "\n";
        echo '       실제: ' . self::dump($actual) . "\n";
    }

    public static function true(bool $cond, string $what): void
    {
        self::is($cond, true, $what);
    }

    private static function dump(mixed $v): string
    {
        return is_scalar($v) || $v === null
            ? var_export($v, true)
            : json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    require $file;
}

echo "\n----------------------------------------\n";
echo '통과 ' . T::$pass . ' · 실패 ' . count(T::$fail) . "\n";
foreach (T::$fail as $f) {
    echo "  · $f\n";
}
exit(T::$fail === [] ? 0 : 1);
