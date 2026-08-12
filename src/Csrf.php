<?php
declare(strict_types=1);

namespace Health;

final class Csrf
{
    public const FIELD = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::FIELD])) {
            $_SESSION[self::FIELD] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::FIELD];
    }

    /** 폼에 넣을 hidden input. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function valid(string $given): bool
    {
        $have = (string) ($_SESSION[self::FIELD] ?? '');
        return $have !== '' && hash_equals($have, $given);
    }

    /** POST 처리 첫 줄에서 부른다. 틀리면 419 로 끊는다. */
    public static function check(): void
    {
        if (!self::valid(Http::post(self::FIELD))) {
            Http::status(419);
            View::render('error', [
                'title'   => '요청이 만료됐습니다',
                'message' => '보안 토큰이 맞지 않습니다. 화면을 새로 열고 다시 시도해 주세요.',
            ]);
            exit;
        }
    }
}
