<?php
declare(strict_types=1);

namespace Health\Repo;

use Health\Db;

/**
 * "오늘의 운동". 인계 문서 §7.
 *
 * 세고 있는 값(횟수 · 무게)은 workout_current 에 두고, `+1 세트`를 눌러야
 * workout_sets 에 한 줄이 쌓인다. 그래서 workout_sets 는 전부 "끝낸 세트"다.
 * 12회를 세어 두고 +1세트를 두 번 누르면 12회짜리 세트가 둘 쌓이고,
 * 15로 고쳐 한 번 더 누르면 3세트는 15회로 쌓인다.
 *
 * 유산소는 횟수 대신 시간을 잰다 — 스톱워치를 멈추고 기록하면 secs 가 채워진 줄이 쌓인다.
 *
 * 화면은 fetch 로 이 동작들을 부르고 JSON 을 받는다(전체 새로고침 없이). 자바스크립트가
 * 없으면 같은 주소로 그냥 POST 되어 예전처럼 리다이렉트로 돌아간다.
 */
final class Workout
{
    /** 오늘 아직 끝내지 않은 기록. 없으면 null. */
    public static function open(): ?array
    {
        return Db::one(
            'SELECT * FROM workouts WHERE ended_at IS NULL AND date(started_at) = date(?)
             ORDER BY id DESC LIMIT 1',
            [date('c')]
        );
    }

    /**
     * 오늘 기록을 연다. 이미 열려 있으면 그것을 쓴다.
     * 끝내지 않고 하루가 지나면 다음 기록에서 새 workouts 를 연다(문서 §7).
     */
    public static function ensure(): int
    {
        $open = self::open();
        if ($open !== null) {
            return (int) $open['id'];
        }
        return Db::insert('INSERT INTO workouts (started_at) VALUES (?)', [date('c')]);
    }

    /** 한 운동의 끝낸 세트 목록. */
    public static function sets(int $workoutId, int $exerciseId): array
    {
        return Db::all(
            'SELECT * FROM workout_sets WHERE workout_id = ? AND exercise_id = ? ORDER BY set_no',
            [$workoutId, $exerciseId]
        );
    }

    /**
     * 지금 세고 있는 값. 없으면 0회 · 무게 없음.
     * @return array{reps:int,weight:?float}
     */
    public static function current(int $workoutId, int $exerciseId): array
    {
        $row = Db::one(
            'SELECT reps, weight FROM workout_current WHERE workout_id = ? AND exercise_id = ?',
            [$workoutId, $exerciseId]
        );
        return [
            'reps'   => $row === null ? 0 : (int) $row['reps'],
            'weight' => $row === null || $row['weight'] === null ? null : (float) $row['weight'],
        ];
    }

    /** 세고 있는 값을 통째로 덮어쓴다. */
    private static function putCurrent(int $workoutId, int $exerciseId, int $reps, ?float $weight): void
    {
        Db::run(
            'INSERT INTO workout_current (workout_id, exercise_id, reps, weight, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(workout_id, exercise_id) DO UPDATE SET
                reps = excluded.reps, weight = excluded.weight, updated_at = excluded.updated_at',
            [$workoutId, $exerciseId, max(0, $reps), $weight, date('c')]
        );
    }

    /** 세고 있는 횟수를 더하거나 뺀다. 0 아래로는 안 내려간다. */
    public static function addRep(int $workoutId, int $exerciseId, int $delta = 1): void
    {
        $cur = self::current($workoutId, $exerciseId);
        self::putCurrent($workoutId, $exerciseId, $cur['reps'] + $delta, $cur['weight']);
    }

    /** 세고 있는 횟수를 그 값으로 맞춘다(화면에서 여러 번 누른 결과를 한 번에 보낼 때). */
    public static function setReps(int $workoutId, int $exerciseId, int $reps): void
    {
        $cur = self::current($workoutId, $exerciseId);
        self::putCurrent($workoutId, $exerciseId, $reps, $cur['weight']);
    }

    /** 세고 있는 무게를 바꾼다. */
    public static function setWeight(int $workoutId, int $exerciseId, ?float $weight): void
    {
        $cur = self::current($workoutId, $exerciseId);
        self::putCurrent($workoutId, $exerciseId, $cur['reps'], $weight);
    }

    /**
     * `+1 세트` — 지금 세고 있는 횟수·무게로 한 줄 쌓는다.
     * 세고 있던 값은 그대로 둔다. 같은 횟수로 한 세트 더 하는 일이 대부분이라 그게 편하다.
     */
    public static function commitSet(int $workoutId, int $exerciseId): ?array
    {
        $cur = self::current($workoutId, $exerciseId);
        if ($cur['reps'] <= 0) {
            return null;                        // 0회짜리 세트는 쌓지 않는다
        }
        $id = Db::insert(
            'INSERT INTO workout_sets (workout_id, exercise_id, set_no, reps, weight, done_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$workoutId, $exerciseId, self::nextSetNo($workoutId, $exerciseId),
             $cur['reps'], $cur['weight'], date('c')]
        );
        return Db::one('SELECT * FROM workout_sets WHERE id = ?', [$id]);
    }

    /** 스톱워치를 멈추고 기록 — 걸린 시간으로 한 줄 쌓는다. */
    public static function commitTime(int $workoutId, int $exerciseId, float $secs): ?array
    {
        if ($secs < 1) {
            return null;                        // 잘못 눌렀을 때 1초짜리 줄이 쌓이지 않게
        }
        $id = Db::insert(
            'INSERT INTO workout_sets (workout_id, exercise_id, set_no, reps, secs, done_at)
             VALUES (?, ?, ?, 0, ?, ?)',
            [$workoutId, $exerciseId, self::nextSetNo($workoutId, $exerciseId),
             round($secs, 2), date('c')]
        );
        return Db::one('SELECT * FROM workout_sets WHERE id = ?', [$id]);
    }

    private static function nextSetNo(int $workoutId, int $exerciseId): int
    {
        return 1 + (int) Db::value(
            'SELECT COALESCE(MAX(set_no), 0) FROM workout_sets WHERE workout_id = ? AND exercise_id = ?',
            [$workoutId, $exerciseId]
        );
    }

    /** 쌓인 세트 줄을 고친다. */
    public static function editSet(int $setId, int $workoutId, int $reps, ?float $weight): void
    {
        Db::run(
            'UPDATE workout_sets SET reps = ?, weight = ?, done_at = ?
              WHERE id = ? AND workout_id = ?',
            [max(0, $reps), $weight, date('c'), $setId, $workoutId]
        );
    }

    public static function deleteSet(int $setId, int $workoutId): void
    {
        $row = Db::one(
            'SELECT exercise_id FROM workout_sets WHERE id = ? AND workout_id = ?',
            [$setId, $workoutId]
        );
        if ($row === null) {
            return;
        }
        Db::run('DELETE FROM workout_sets WHERE id = ?', [$setId]);

        // 가운데를 지우면 번호가 1 · 3 · 4 로 비어 보인다. 다시 1부터 매긴다.
        $no = 0;
        foreach (self::sets($workoutId, (int) $row['exercise_id']) as $s) {
            $no++;
            if ((int) $s['set_no'] !== $no) {
                Db::run('UPDATE workout_sets SET set_no = ? WHERE id = ?', [$no, (int) $s['id']]);
            }
        }
    }

    public static function finish(int $workoutId, ?string $memo = null): void
    {
        // 쌓이지 않은 채 세고 있던 값은 기록이 아니다. 치운다.
        Db::run('DELETE FROM workout_current WHERE workout_id = ?', [$workoutId]);
        Db::run('DELETE FROM workout_sets WHERE workout_id = ? AND reps = 0 AND secs IS NULL', [$workoutId]);
        Db::run('UPDATE workouts SET ended_at = ?, memo = ? WHERE id = ?', [date('c'), $memo, $workoutId]);
    }

    /**
     * 한 기록의 운동별 집계. 유산소 줄(secs)도 함께 센다.
     * @return array<int,array{exercise_id:int,name:string,sets:int,reps:int,top_weight:?float,secs:float}>
     */
    public static function summary(int $workoutId): array
    {
        $rows = Db::all(
            'SELECT ws.exercise_id, e.name,
                    COUNT(*) AS sets, SUM(ws.reps) AS reps, MAX(ws.weight) AS top_weight,
                    SUM(COALESCE(ws.secs, 0)) AS secs
               FROM workout_sets ws
               JOIN exercises e ON e.id = ws.exercise_id
              WHERE ws.workout_id = ? AND (ws.reps > 0 OR ws.secs IS NOT NULL)
              GROUP BY ws.exercise_id, e.name
              ORDER BY MIN(ws.id)',
            [$workoutId]
        );
        return array_map(static fn (array $r): array => [
            'exercise_id' => (int) $r['exercise_id'],
            'name'        => (string) $r['name'],
            'sets'        => (int) $r['sets'],
            'reps'        => (int) $r['reps'],
            'top_weight'  => $r['top_weight'] !== null ? (float) $r['top_weight'] : null,
            'secs'        => (float) $r['secs'],
        ], $rows);
    }

    /** 초 → "20분" · "1시간 5분" · "45초". 화면 어디서나 같은 모양으로 쓴다. */
    public static function humanSecs(float $secs): string
    {
        $s = (int) round($secs);
        if ($s < 60) {
            return $s . '초';
        }
        $m = intdiv($s, 60);
        if ($m < 60) {
            return $s % 60 === 0 ? $m . '분' : $m . '분 ' . ($s % 60) . '초';
        }
        return intdiv($m, 60) . '시간' . ($m % 60 === 0 ? '' : ' ' . ($m % 60) . '분');
    }

    /** 지난 기록 목록. */
    public static function history(int $limit = 50): array
    {
        return Db::all(
            'SELECT w.*,
                    (SELECT COUNT(DISTINCT exercise_id) FROM workout_sets
                      WHERE workout_id = w.id AND (reps > 0 OR secs IS NOT NULL)) AS n_ex,
                    (SELECT COUNT(*) FROM workout_sets
                      WHERE workout_id = w.id AND (reps > 0 OR secs IS NOT NULL)) AS n_sets,
                    (SELECT COALESCE(SUM(reps), 0) FROM workout_sets WHERE workout_id = w.id) AS n_reps
               FROM workouts w
              ORDER BY w.started_at ASC
              LIMIT ?',
            [$limit]
        );
    }

    /**
     * 운동 고르기 화면에 띄울 "직전 기록" 한 줄.
     * 내가 기록한 게 있으면 그것을, 없으면 노션 회차의 날짜를 쓴다.
     * @return array<int,string> exercise_id => '26.07.23 · 15kg 12회'
     */
    public static function lastNotes(): array
    {
        $out = [];

        foreach (Db::all(
            "SELECT sx.exercise_id, MAX(s.date) AS d
               FROM session_exercises sx JOIN sessions s ON s.id = sx.session_id
              WHERE s.date IS NOT NULL GROUP BY sx.exercise_id"
        ) as $r) {
            $out[(int) $r['exercise_id']] = (string) $r['d'];
        }

        foreach (Db::all(
            'SELECT ws.exercise_id, ws.reps, ws.weight, ws.secs, ws.done_at
               FROM workout_sets ws
               JOIN (SELECT exercise_id, MAX(done_at) AS m FROM workout_sets
                      WHERE reps > 0 OR secs IS NOT NULL
                      GROUP BY exercise_id) t
                 ON t.exercise_id = ws.exercise_id AND t.m = ws.done_at'
        ) as $r) {
            $bits = [date('y.m.d', strtotime((string) $r['done_at']))];
            if ($r['secs'] !== null) {
                $bits[] = self::humanSecs((float) $r['secs']);
            } else {
                if ($r['weight'] !== null) {
                    $bits[] = rtrim(rtrim(number_format((float) $r['weight'], 1), '0'), '.') . 'kg';
                }
                $bits[] = (int) $r['reps'] . '회';
            }
            $out[(int) $r['exercise_id']] = implode(' · ', $bits);
        }

        return $out;
    }

    /** 운동 마스터 목록. */
    public static function exercises(): array
    {
        return Db::all('SELECT id, name, part_override FROM exercises ORDER BY name');
    }

    /**
     * 이름으로 찾거나 새로 만든다.
     * 부위를 골랐으면 그것을 같이 저장한다 — 이름만으로는 못 맞히는 운동이 있다.
     */
    public static function exerciseByName(string $name, ?string $part = null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('운동 이름을 넣어 주세요.');
        }
        $part = $part !== null && \Health\Parts::isKnown($part) ? $part : null;

        Db::run('INSERT INTO exercises (name, created_at) VALUES (?, ?) ON CONFLICT(name) DO NOTHING',
            [$name, date('c')]);
        if ($part !== null) {
            Db::run('UPDATE exercises SET part_override = ? WHERE name = ?', [$part, $name]);
        }
        return (int) Db::value('SELECT id FROM exercises WHERE name = ?', [$name]);
    }

    public static function exercise(int $id): ?array
    {
        return Db::one('SELECT * FROM exercises WHERE id = ?', [$id]);
    }
}
