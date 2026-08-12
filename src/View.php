<?php
declare(strict_types=1);

namespace Health;

/**
 * 순수 PHP 템플릿. layout.php 안에 본문을 끼운다.
 */
final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = [], bool $bare = false): void
    {
        $content = self::capture($template, $data);
        if ($bare) {
            echo $content;
            return;
        }
        echo self::capture('layout', $data + [
            'content'   => $content,
            'pageTitle' => $data['title'] ?? null,
            'here'      => App::path(),
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function capture(string $template, array $data = []): string
    {
        $file = App::root() . '/src/View/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("템플릿이 없다: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    /** 출력 escape. 화면에 나가는 모든 값은 이걸 거친다. */
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

/** 템플릿 안에서 짧게 쓰는 별칭. */
function e(mixed $v): string
{
    return View::e($v);
}

function url(string $path = '/'): string
{
    return View::e(App::url($path));
}
