<?php
declare(strict_types=1);

namespace Health;

/**
 * "오늘 PT쌤이 남긴 메시지".
 * 노션에서 가져오는 회차 기록과 달리, 수업이 끝나고 바로 손으로 적어 두는 한 토막이다.
 * 하루에 하나만 두고, 오늘의 메시지를 만들 때 가장 먼저 본다.
 */
final class PtNote
{
    public const MAX = 2000;

    /** 그날 적어 둔 말. 없으면 빈 문자열. */
    public static function forDay(?string $day = null): string
    {
        $v = Db::value('SELECT note FROM pt_notes WHERE day = ?', [$day ?? date('Y-m-d')]);
        return $v === null ? '' : (string) $v;
    }

    /** 같은 날 다시 적으면 덮어쓴다. */
    public static function save(string $note, ?string $day = null): void
    {
        Db::run(
            'INSERT INTO pt_notes (day, note, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(day) DO UPDATE SET note = excluded.note, updated_at = excluded.updated_at',
            [$day ?? date('Y-m-d'), mb_substr(trim($note), 0, self::MAX), date('c')]
        );
    }
}
