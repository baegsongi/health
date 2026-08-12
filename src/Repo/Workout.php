<?php
declare(strict_types=1);

namespace Health\Repo;

use Health\Db;

/**
 * "오늘의 운동". 인계 문서 §7.
 *
 * 세트 기록은 workout_sets 행으로 쌓는다. 한 운동의 **마지막 행이 진행 중인 세트**다.
 * `세트 완료`를 누르면 다음 세트를 0회로 새로 연다.
 *
 * 모든 동작은 POST → 리다이렉트 → GET 이다. JavaScript 없이 기록이 되어야 한다.
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

    /** 한 운동의 세트 목록(진행 중인 것 포함). */
    public static function sets(int $workoutId, int $exerciseId): array
    {
        return Db::all(
            'SELECT * FROM workout_sets WHERE workout_id = ? AND exercise_id = ? ORDER BY set_no',
            [$workoutId, $exerciseId]
        );
    }

    /** 진행 중인 세트(= 마지막 행). 없으면 null. */
    public static function currentSet(int $workoutId, int $exerciseId): ?array
    {
        return Db::one(
            'SELECT * FROM workout_sets WHERE workout_id = ? AND exercise_id = ?
             ORDER BY set_no DESC LIMIT 1',
            [$workoutId, $exerciseId]
        );
    }

    /** +1 회. 진행 중인 세트가 없으면 1세트를 연다. */
    public static function addRep(int $workoutId, int $exerciseId, int $delta = 1): void
    {
        $cur = self::currentSet($workoutId, $exerciseId);
        if ($cur === null) {
            Db::run(
                'INSERT INTO workout_sets (workout_id, exercise_id, set_no, reps, done_at)
                 VALUES (?, ?, 1, ?, ?)',
                [$workoutId, $exerciseId, max(0, $delta), date('c')]
            );
            return;
        }
        $reps = max(0, (int) $cur['reps'] + $delta);
        Db::run('UPDATE workout_sets SET reps = ?, done_at = ? WHERE id = ?', [$reps, date('c'), $cur['id']]);
    }

    /** 세트 완료 — 현재 세트를 확정하고 다음 세트를 0회로 시작한다. */
    public static function completeSet(int $workoutId, int $exerciseId): void
    {
        $cur = self::currentSet($workoutId, $exerciseId);
        if ($cur === null || (int) $cur['reps'] <= 0) {
            return;   // 0회짜리 빈 세트를 만들지 않는다
        }
        Db::run(
            'INSERT INTO workout_sets (workout_id, exercise_id, set_no, reps, weight, done_at)
             VALUES (?, ?, ?, 0, ?, ?)',
            [$workoutId, $exerciseId, (int) $cur['set_no'] + 1, $cur['weight'], date('c')]
        );
    }

    /** 세트 줄을 눌러 고친다. 0회로 만들면 그 줄을 지운다. */
    public static function editSet(int $setId, int $workoutId, int $reps, ?float $weight): void
    {
        $row = Db::one('SELECT * FROM workout_sets WHERE id = ? AND workout_id = ?', [$setId, $workoutId]);
        if ($row === null) {
            return;
        }
        Db::run(
            'UPDATE workout_sets SET reps = ?, weight = ?, done_at = ? WHERE id = ?',
            [max(0, $reps), $weight, date('c'), $setId]
        );
    }

    public static function deleteSet(int $setId, int $workoutId): void
    {
        Db::run('DELETE FROM workout_sets WHERE id = ? AND workout_id = ?', [$setId, $workoutId]);
    }

    /** 현재 세트의 무게만 바꾼다. */
    public static function setWeight(int $workoutId, int $exerciseId, ?float $weight): void
    {
        $cur = self::currentSet($workoutId, $exerciseId);
        if ($cur === null) {
            Db::run(
                'INSERT INTO workout_sets (workout_id, exercise_id, set_no, reps, weight, done_at)
                 VALUES (?, ?, 1, 0, ?, ?)',
                [$workoutId, $exerciseId, $weight, date('c')]
            );
            return;
        }
        Db::run('UPDATE workout_sets SET weight = ? WHERE id = ?', [$weight, $cur['id']]);
    }

    public static function finish(int $workoutId, ?string $memo = null): void
    {
        // 0회짜리 진행 중 세트는 치운다.
        Db::run('DELETE FROM workout_sets WHERE workout_id = ? AND reps = 0', [$workoutId]);
        Db::run('UPDATE workouts SET ended_at = ?, memo = ? WHERE id = ?', [date('c'), $memo, $workoutId]);
    }

    /**
     * 한 기록의 운동별 집계.
     * @return array<int,array{exercise_id:int,name:string,sets:int,reps:int,top_weight:?float}>
     */
    public static function summary(int $workoutId): array
    {
        $rows = Db::all(
            'SELECT ws.exercise_id, e.name,
                    COUNT(*) AS sets, SUM(ws.reps) AS reps, MAX(ws.weight) AS top_weight
               FROM workout_sets ws
               JOIN exercises e ON e.id = ws.exercise_id
              WHERE ws.workout_id = ? AND ws.reps > 0
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
        ], $rows);
    }

    /** 지난 기록 목록. */
    public static function history(int $limit = 50): array
    {
        return Db::all(
            'SELECT w.*,
                    (SELECT COUNT(DISTINCT exercise_id) FROM workout_sets WHERE workout_id = w.id AND reps > 0) AS n_ex,
                    (SELECT COUNT(*) FROM workout_sets WHERE workout_id = w.id AND reps > 0) AS n_sets,
                    (SELECT COALESCE(SUM(reps), 0) FROM workout_sets WHERE workout_id = w.id) AS n_reps
               FROM workouts w
              ORDER BY w.started_at DESC
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
            'SELECT ws.exercise_id, ws.reps, ws.weight, ws.done_at
               FROM workout_sets ws
               JOIN (SELECT exercise_id, MAX(done_at) AS m FROM workout_sets WHERE reps > 0
                      GROUP BY exercise_id) t
                 ON t.exercise_id = ws.exercise_id AND t.m = ws.done_at'
        ) as $r) {
            $bits = [date('y.m.d', strtotime((string) $r['done_at']))];
            if ($r['weight'] !== null) {
                $bits[] = rtrim(rtrim(number_format((float) $r['weight'], 1), '0'), '.') . 'kg';
            }
            $bits[] = (int) $r['reps'] . '회';
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
