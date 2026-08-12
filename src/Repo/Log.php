<?php
declare(strict_types=1);

namespace Health\Repo;

use Health\Db;
use Health\Notion\Title;
use Health\Parts;

/**
 * 열람 화면(일자별 · 부위별 · 전체)이 쓰는 조회.
 * 부위는 DB 에 없다. 이름에서 계산한다(Parts::of).
 */
final class Log
{
    /**
     * 회차 목록. 오래된 것이 위로 온다 — 처음부터 순서대로 읽는 기록이라 그게 자연스럽다.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function sessions(bool $ascending = true): array
    {
        $rows = Db::all(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM session_exercises sx WHERE sx.session_id = s.id) AS n_ex,
                    (SELECT COUNT(*) FROM media m
                       JOIN session_exercises sx ON sx.id = m.session_exercise_id
                      WHERE sx.session_id = s.id AND m.kind = 'video') AS n_video,
                    (SELECT COUNT(*) FROM media m
                       JOIN session_exercises sx ON sx.id = m.session_exercise_id
                      WHERE sx.session_id = s.id AND m.kind = 'image') AS n_image
               FROM sessions s"
        );

        usort($rows, static function (array $a, array $b) use ($ascending): int {
            $ad = Title::isoDate($a['date'] ?? null) ?? '';
            $bd = Title::isoDate($b['date'] ?? null) ?? '';
            return $ascending
                ? ($ad <=> $bd ?: (int) $a['position'] <=> (int) $b['position'])
                : ($bd <=> $ad ?: (int) $b['position'] <=> (int) $a['position']);
        });

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function session(int $id): ?array
    {
        return Db::one('SELECT * FROM sessions WHERE id = ?', [$id]);
    }

    /**
     * 한 회차의 운동 목록.
     * @return array<int,array<string,mixed>>
     */
    public static function exercisesOf(int $sessionId): array
    {
        return Db::all(
            'SELECT sx.*, e.name
               FROM session_exercises sx
               JOIN exercises e ON e.id = sx.exercise_id
              WHERE sx.session_id = ?
              ORDER BY sx.position',
            [$sessionId]
        );
    }

    /**
     * 부위 목록. 미분류 항목이 맨 위에 온다(인계 문서 §2.6).
     * @return array<int,array{part:string,unclassified:bool,count:int}>
     */
    public static function parts(): array
    {
        $rows = Db::all(
            'SELECT e.name, COUNT(*) AS n
               FROM session_exercises sx
               JOIN exercises e ON e.id = sx.exercise_id
              GROUP BY e.name'
        );

        $counts = [];
        $loose  = [];
        foreach ($rows as $r) {
            $name  = (string) $r['name'];
            $n     = (int) $r['n'];
            $found = Parts::of($name);
            if ($found === []) {
                $loose[$name] = $n;
                continue;
            }
            foreach ($found as $p) {
                $counts[$p] = ($counts[$p] ?? 0) + $n;
            }
        }

        $out = [];
        foreach ($loose as $name => $n) {
            $out[] = ['part' => $name, 'unclassified' => true, 'count' => $n];
        }
        foreach (Parts::names() as $p) {
            if (!empty($counts[$p])) {
                $out[] = ['part' => $p, 'unclassified' => false, 'count' => $counts[$p]];
            }
        }
        return $out;
    }

    /**
     * 한 부위(또는 미분류 이름)에 속하는 운동 항목. 회차 · 날짜를 함께 준다.
     * @return array<int,array<string,mixed>>
     */
    public static function part(string $part): array
    {
        $rows = Db::all(
            'SELECT sx.*, e.name, s.id AS session_id, s.code, s.date, s.weekday, s.title AS session_title,
                    s.position AS session_position
               FROM session_exercises sx
               JOIN exercises e ON e.id = sx.exercise_id
               JOIN sessions s  ON s.id = sx.session_id'
        );

        $known = Parts::isKnown($part);
        $rows  = array_values(array_filter($rows, static function (array $r) use ($part, $known): bool {
            $name = (string) $r['name'];
            return $known ? in_array($part, Parts::of($name), true) : $name === $part;
        }));

        // 부위별 목록도 오래된 것이 위로 온다.
        usort($rows, static function (array $a, array $b): int {
            $ad = Title::isoDate($a['date'] ?? null) ?? '';
            $bd = Title::isoDate($b['date'] ?? null) ?? '';
            return $ad <=> $bd ?: (int) $a['position'] <=> (int) $b['position'];
        });

        return $rows;
    }

    /** 회차 제목에 붙일 짧은 표시. '1-10 · 26.07.23(목)' */
    public static function label(array $session): string
    {
        $bits = [];
        if (($session['code'] ?? '') !== '') {
            $bits[] = (string) $session['code'];
        }
        $d = (string) ($session['date'] ?? '');
        if ($d !== '') {
            $w = (string) ($session['weekday'] ?? '');
            $bits[] = $d . ($w !== '' ? "($w)" : '');
        }
        return implode(' · ', $bits) !== '' ? implode(' · ', $bits) : (string) ($session['title'] ?? '');
    }

    /** @return array{sessions:int,exercises:int,videos:int,images:int} */
    public static function totals(): array
    {
        return [
            'sessions'  => (int) Db::value('SELECT COUNT(*) FROM sessions'),
            'exercises' => (int) Db::value('SELECT COUNT(*) FROM session_exercises'),
            'videos'    => (int) Db::value('SELECT COUNT(*) FROM media WHERE kind = ?', ['video']),
            'images'    => (int) Db::value('SELECT COUNT(*) FROM media WHERE kind = ?', ['image']),
        ];
    }
}
