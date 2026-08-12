<?php
declare(strict_types=1);

namespace Health;

/**
 * 전역 부트스트랩. autoload · 설정 · 시간대 · 인코딩을 한 곳에서 잡는다.
 * Composer 를 쓰지 않으므로 autoload 도 여기에 있다.
 */
final class App
{
    private static ?array $config = null;
    private static string $path = '/';

    /** 지금 열린 화면의 경로. 하단 바에서 현재 탭을 표시하는 데 쓴다. */
    public static function setPath(string $path): void
    {
        self::$path = $path;
    }

    public static function path(): string
    {
        return self::$path;
    }

    public static function boot(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        date_default_timezone_set('Asia/Seoul');
        mb_internal_encoding('UTF-8');
        setlocale(LC_ALL, 'C.UTF-8', 'ko_KR.UTF-8', 'en_US.UTF-8');

        self::registerErrorHandling();

        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, 'Health\\')) {
                return;
            }
            $rel  = str_replace('\\', '/', substr($class, strlen('Health\\')));
            $file = self::root() . '/src/' . $rel . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * 오류는 화면에 내보내지 않고 storage/logs/error.log 로만 남긴다.
     * config 의 debug 가 true 면 화면에도 보여준다(로컬 확인용).
     */
    private static function registerErrorHandling(): void
    {
        $dir = self::storage('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        ini_set('log_errors', '1');
        ini_set('error_log', $dir . '/error.log');
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_exception_handler(static function (\Throwable $e): void {
            self::log('예외: ' . $e::class . ' — ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
            }
            if (self::conf('debug', false)) {
                echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
            } else {
                echo '<!doctype html><meta charset="utf-8"><title>오류</title>'
                    . '<p style="font:16px sans-serif;padding:24px">문제가 생겼습니다. '
                    . '잠시 뒤 다시 시도해 주세요.</p>';
            }
        });
    }

    public static function log(string $message): void
    {
        error_log('[' . date('c') . '] ' . $message);
    }

    public static function storage(string $sub = ''): string
    {
        return self::root() . '/storage' . ($sub === '' ? '' : '/' . ltrim($sub, '/'));
    }

    public static function mediaDir(): string
    {
        return self::root() . '/public/media';
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        return self::$config ??= require self::root() . '/config.php';
    }

    public static function conf(string $key, mixed $default = null): mixed
    {
        return self::config()[$key] ?? $default;
    }

    /**
     * 바깥에서 쓸 수 있는 전체 주소(https://호스트/…).
     * 소셜 미리보기(og:image · og:url)는 상대 경로를 읽지 못한다.
     */
    public static function absoluteUrl(string $path = '/'): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return self::url($path);
        }
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $proto = $https || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        return $proto . '://' . $host . self::url($path);
    }

    /** 앱 기준 절대 경로. base_path 접두사를 붙여준다. */
    public static function url(string $path = '/'): string
    {
        $base = rtrim((string) self::conf('base_path', ''), '/');
        return $base . ($path === '' ? '/' : $path);
    }
}
