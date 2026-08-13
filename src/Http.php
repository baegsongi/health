<?php
declare(strict_types=1);

namespace Health;

final class Http
{
    public static function status(int $code): void
    {
        http_response_code($code);
    }

    /** POST → 리다이렉트 → GET. 앱 기준 경로를 받는다. */
    public static function redirect(string $path): never
    {
        header('Location: ' . App::url($path), true, 303);
        self::flush();
        exit;
    }

    /**
     * 응답을 지금 다 내보낸다.
     *
     * 이걸 안 하면 브라우저는 PHP 가 뒷정리를 끝낼 때까지 기다린다. 이 NAS 에서는 그
     * 뒷정리(세션 파일 쓰기 · SQLite 연결을 닫으며 도는 WAL 체크포인트)가 1초쯤 걸린다 —
     * 버튼 하나 누를 때마다 붙는 1초다. 뒷정리는 그대로 하되, 사람은 기다리지 않게 한다.
     */
    public static function flush(): void
    {
        if (!function_exists('fastcgi_finish_request')) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        fastcgi_finish_request();
    }

    /** fetch 로 부른 요청인가. 같은 주소를 화면 이동과 JSON 양쪽으로 쓴다. */
    public static function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        self::flush();
        exit;
    }

    public static function param(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public static function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public static function postInt(string $key, int $default = 0): int
    {
        $v = $_POST[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    /**
     * 화면을 다 보낸 뒤에 할 일. 오래 걸려도 사용자는 기다리지 않는다.
     *
     * 종료 처리에 걸어 두므로 redirect() 처럼 exit 하는 자리에서 미리 불러도 된다.
     * PHP-FPM 이 아니면(mod_php 등) 아무것도 하지 않는다 —
     * 연결을 붙잡고 있느니 그냥 안 하는 편이 낫다. 그때는 cron 이 그 몫을 한다.
     */
    public static function afterResponse(callable $fn): void
    {
        if (!function_exists('fastcgi_finish_request')) {
            return;
        }
        register_shutdown_function(static function () use ($fn): void {
            ignore_user_abort(true);
            // 세션 파일 잠금을 먼저 놓는다. 안 놓으면 사용자의 다음 요청이 이 작업만큼 멈춘다.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            fastcgi_finish_request();

            try {
                $fn();
            } catch (\Throwable $e) {
                App::log('응답 뒤 작업 실패: ' . $e->getMessage());
            }
        });
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header(
            "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
            . "media-src 'self'; style-src 'self'; font-src 'self'; script-src 'self'; "
            . "frame-src https://www.youtube.com https://www.youtube-nocookie.com; "
            . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
        );
    }

    public static function notFound(string $message = '없는 화면입니다.'): never
    {
        self::status(404);
        View::render('error', ['title' => '404', 'message' => $message]);
        exit;
    }
}
