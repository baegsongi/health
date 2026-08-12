<?php
declare(strict_types=1);

namespace Health\Notion;

/**
 * Notion 페이지 id 다루기. 인계 문서 §2.2.
 * URL 의 32자리 hex 를 8-4-4-4-12 로 잘라 UUID 로 만든다.
 */
final class Ids
{
    /**
     * 문자열 어디에 있든 페이지 id 를 찾아 UUID 로 바꿔 돌려준다. 없으면 null.
     *
     * ⚠ 하이픈을 먼저 다 지우면 안 된다. Notion 주소는
     *   `.../26-06-10-21-30-…-<32hex>` 처럼 제목 슬러그에 숫자가 섞여 있어서,
     *   하이픈을 지우면 슬러그의 숫자들이 붙어 가짜 32자리가 만들어진다.
     */
    public static function toUuid(string $raw): ?string
    {
        // 1) 이미 하이픈이 들어간 UUID 형태가 있으면 그것을 쓴다.
        if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $raw, $m)) {
            return strtolower($m[1]);
        }
        // 2) 앞뒤가 hex 가 아닌 32자리 hex 덩어리를 찾는다.
        if (!preg_match('/(?<![0-9a-f])([0-9a-f]{32})(?![0-9a-f])/i', $raw, $m)) {
            return null;
        }
        $h = strtolower($m[1]);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
            . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    public static function isUuid(string $s): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $s);
    }
}
