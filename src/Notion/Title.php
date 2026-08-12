<?php
declare(strict_types=1);

namespace Health\Notion;

/**
 * 회차 제목 뜯기. 인계 문서 §2.5.
 *
 * 두 가지 형식을 모두 잡는다.
 *   (1-10)백송이님 26.07.23(목)20:00
 *   (OT)백송이 회원님/26.06.10(수) [21:30]
 */
final class Title
{
    /**
     * @return array{code:?string,date:?string,weekday:?string,time:?string}
     */
    public static function parse(string $title): array
    {
        $code = null;
        if (preg_match('/^\s*\(([^)]{1,12})\)/u', $title, $m)) {
            $code = trim($m[1]);
        }

        $date = $weekday = null;
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{2})\s*\(?\s*([월화수목금토일])?\s*\)?/u', $title, $m)) {
            $date    = $m[1] . '.' . $m[2] . '.' . $m[3];
            $weekday = ($m[4] ?? '') !== '' ? $m[4] : null;
        }

        $time = null;
        if (preg_match('/(\d{1,2}):(\d{2})/', $title, $m)) {
            $time = str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
        }

        return ['code' => $code, 'date' => $date, 'weekday' => $weekday, 'time' => $time];
    }

    /**
     * 'YY.MM.DD' 를 정렬 · 표시용 'YYYY-MM-DD' 로. 못 읽으면 null.
     * 두 자리 연도는 2000년대로 본다.
     */
    public static function isoDate(?string $date): ?string
    {
        if ($date === null || !preg_match('/^(\d{2})\.(\d{2})\.(\d{2})$/', $date, $m)) {
            return null;
        }
        return '20' . $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    /**
     * 운동 토글의 제목에서 이름과 세트 메모를 가른다.
     * 첫 줄이 이름(`[n]` 접두사 제거), 나머지 줄이 메모.
     *
     * @return array{name:string,meta:?string}
     */
    public static function exercise(string $toggleTitle): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($toggleTitle)) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn ($l) => $l !== ''));

        $first = $lines[0] ?? '';
        // '[1] 스쿼트+런지' → '스쿼트+런지'
        $name = trim((string) preg_replace('/^\[\s*\d+\s*\]\s*/u', '', $first));
        if ($name === '') {
            $name = $first;
        }

        $meta = count($lines) > 1 ? implode("\n", array_slice($lines, 1)) : null;
        return ['name' => $name, 'meta' => $meta];
    }
}
