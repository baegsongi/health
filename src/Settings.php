<?php
declare(strict_types=1);

namespace Health;

/**
 * 화면에서 고칠 수 있는 설정. key/value 한 테이블에 담는다.
 */
final class Settings
{
    public const DDAY_TITLE = 'dday_title';
    public const DDAY_DATE  = 'dday_date';

    public static function get(string $key, string $default = ''): string
    {
        $v = Db::value('SELECT value FROM settings WHERE key = ?', [$key]);
        return $v === null ? $default : (string) $v;
    }

    public static function set(string $key, string $value): void
    {
        Db::run(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [$key, $value, date('c')]
        );
    }

    /**
     * D-DAY. 날짜가 없으면 null.
     *
     * @return array{title:string,date:string,days:int,passed:bool}|null
     *         days 는 남은 날 수. 지났으면 0.
     */
    public static function dday(): ?array
    {
        $date = self::get(self::DDAY_DATE);
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $today  = new \DateTimeImmutable(date('Y-m-d'));
        $target = new \DateTimeImmutable($date);
        $diff   = (int) $today->diff($target)->format('%r%a');

        return [
            'title'  => self::get(self::DDAY_TITLE, 'D-DAY'),
            'date'   => $date,
            'days'   => max(0, $diff),
            'passed' => $diff < 0,
        ];
    }

    /**
     * 홈에 띄울 한 줄.
     * 날짜가 지났으면 남은 날을 말하지 않고 응원만 한다.
     */
    public static function ddayLine(): string
    {
        $d = self::dday();
        if ($d === null || $d['passed']) {
            return '송이님 화이팅!';
        }
        return $d['title'] . '이 ' . $d['days'] . '일 남았습니다. 송이님 화이팅!';
    }
}
