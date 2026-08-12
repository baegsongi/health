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
