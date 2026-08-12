<?php
declare(strict_types=1);

namespace Health;

use Health\Llm\Deepseek;
use Health\Repo\Workout;

/**
 * "오늘의 메시지" — DB에 쌓인 기록을 보고 오늘 뭘 하면 좋을지 친근하게 말해준다.
 *
 * 화면을 여는 데 API 응답을 기다리게 하지 않으려고 하루 단위로 캐시한다.
 * 호출이 실패해도 화면은 그대로 열린다(그날의 목표만 규칙으로 계산해 보여준다).
 */
final class Coach
{
    /**
     * @return array{text:string,from:string} from: 'ai' | 'rule' | 'cache'
     */
    public static function todayMessage(): array
    {
        $facts = self::facts();
        $day   = date('Y-m-d');
        $print = self::fingerprint($facts);

        $cached = Db::value(
            'SELECT message FROM ai_messages WHERE day = ? AND fingerprint = ?',
            [$day, $print]
        );
        if (is_string($cached) && $cached !== '') {
            return ['text' => $cached, 'from' => 'cache'];
        }

        $llm = new Deepseek();
        if (!$llm->isReady() || Db::isReadOnly()) {
            return ['text' => self::fallback($facts), 'from' => 'rule'];
        }

        try {
            $text = $llm->chat([
                ['role' => 'system', 'content' => self::systemPrompt()],
                ['role' => 'user',   'content' => self::userPrompt($facts)],
            ]);
        } catch (\Throwable $e) {
            App::log('오늘의 메시지 실패: ' . $e->getMessage());
            return ['text' => self::fallback($facts), 'from' => 'rule'];
        }

        // 같은 날 같은 상황이면 다시 부르지 않는다.
        Db::run(
            'INSERT INTO ai_messages (day, fingerprint, message, model, created_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(day, fingerprint) DO UPDATE SET message = excluded.message',
            [$day, $print, $text, App::env('LLM_MODEL'), date('c')]
        );

        return ['text' => $text, 'from' => 'ai'];
    }

    /**
     * 메시지를 만드는 데 쓰는 사실들. 전부 DB에서 온다.
     *
     * @return array<string,mixed>
     */
    public static function facts(): array
    {
        $dday = Settings::dday();

        $open      = Workout::open();
        $todaySets = $open === null ? [] : Workout::summary((int) $open['id']);
        $todayReps = array_sum(array_column($todaySets, 'reps'));
        $todaySetCount = array_sum(array_column($todaySets, 'sets'));

        // 가장 최근 PT 회차 — 선생님이 시킨 내용이 여기 들어 있다.
        $last = Db::one(
            'SELECT id, title, date, intro_html, notes_html FROM sessions
              ORDER BY COALESCE(date, "") DESC, position DESC LIMIT 1'
        );

        $lastExercises = $last === null ? [] : array_column(
            Db::all(
                'SELECT e.name, sx.meta FROM session_exercises sx
                   JOIN exercises e ON e.id = sx.exercise_id
                  WHERE sx.session_id = ? ORDER BY sx.position',
                [(int) $last['id']]
            ),
            null
        );

        // 최근 2주 내 스스로 기록한 것
        $recent = Db::all(
            "SELECT date(started_at) AS d,
                    (SELECT COUNT(*) FROM workout_sets ws WHERE ws.workout_id = w.id AND ws.reps > 0) AS sets
               FROM workouts w
              WHERE date(started_at) >= date('now', '-14 day')
              ORDER BY started_at"
        );

        return [
            'today'         => date('Y-m-d(D)'),
            'dday'          => $dday,
            'todaySets'     => $todaySets,
            'todaySetCount' => (int) $todaySetCount,
            'todayReps'     => (int) $todayReps,
            'lastSession'   => $last,
            'lastExercises' => $lastExercises,
            'recent'        => $recent,
            'totalSessions' => (int) Db::value('SELECT COUNT(*) FROM sessions'),
        ];
    }

    /**
     * 캐시 열쇠. 굵게 잡는다 —
     * 세트 하나 누를 때마다 다시 부르면 화면이 느려지고 호출비도 는다.
     * 하루에 많아야 두 번(시작 전 · 시작 후) 부른다.
     */
    private static function fingerprint(array $f): string
    {
        return md5(json_encode([
            $f['dday']['days'] ?? null,
            $f['todaySetCount'] > 0 ? 'started' : 'none',
            $f['lastSession']['id'] ?? null,
        ], JSON_UNESCAPED_UNICODE) ?: '');
    }

    private static function systemPrompt(): string
    {
        return <<<'TXT'
        당신은 "송이"의 개인 트레이너입니다. 송이가 오늘 운동을 시작하려고 앱을 열었습니다.

        규칙:
        - 한국어 존댓말로, 친근하고 짧게 말합니다. 3~4문장, 200자 안팎.
        - 목록·불릿·제목·이모지 남발 금지. 말하듯이 이어서 씁니다. 이모지는 써도 하나까지.
        - 주어진 기록에 없는 숫자나 사실을 지어내지 않습니다.
        - PT 선생님이 정해준 목표(예: 근력 20세트, 유산소 40분)가 기록에 있으면 그걸 기준으로 말합니다.
          없으면 지난 회차에서 한 운동을 근거로 오늘 뭘 하면 좋을지 제안합니다.
        - 오늘 이미 기록한 세트가 있으면 그걸 먼저 알아주고, 남은 만큼을 말합니다.
        - D-DAY가 남아 있으면 자연스럽게 한 번만 언급합니다. 조급하게 몰아붙이지 않습니다.
        - "AI", "요약", "분석" 같은 말은 쓰지 않습니다. 사람이 말하듯이 합니다.
        TXT;
    }

    private static function userPrompt(array $f): string
    {
        $lines = ['오늘: ' . $f['today']];

        if ($f['dday'] !== null && !$f['dday']['passed']) {
            $lines[] = "{$f['dday']['title']}까지 {$f['dday']['days']}일 남음 ({$f['dday']['date']})";
        }

        if ($f['todaySetCount'] > 0) {
            $done = [];
            foreach ($f['todaySets'] as $s) {
                $done[] = "{$s['name']} {$s['sets']}세트 {$s['reps']}회";
            }
            $lines[] = '오늘 이미 한 것: ' . implode(', ', $done)
                . " (합계 {$f['todaySetCount']}세트 {$f['todayReps']}회)";
        } else {
            $lines[] = '오늘 아직 기록 없음';
        }

        if ($f['lastSession'] !== null) {
            $lines[] = '가장 최근 PT: ' . (string) $f['lastSession']['title'];
            $intro = self::plain((string) ($f['lastSession']['intro_html'] ?? ''));
            $notes = self::plain((string) ($f['lastSession']['notes_html'] ?? ''));
            if ($intro !== '') {
                $lines[] = '그날 선생님 안내: ' . mb_substr($intro, 0, 500);
            }
            if ($notes !== '') {
                $lines[] = '그날 총평: ' . mb_substr($notes, 0, 500);
            }
            $names = [];
            foreach ($f['lastExercises'] as $ex) {
                $meta = trim((string) ($ex['meta'] ?? ''));
                $names[] = $ex['name'] . ($meta !== '' ? " ({$meta})" : '');
            }
            if ($names !== []) {
                $lines[] = '그날 한 운동: ' . implode(', ', array_slice($names, 0, 12));
            }
        }

        $days = count(array_filter($f['recent'], static fn ($r) => (int) $r['sets'] > 0));
        $lines[] = "최근 2주 스스로 기록한 날: {$days}일";

        return implode("\n", $lines);
    }

    /** API 를 못 부를 때 쓰는 문장. 지어내지 않고 있는 사실만 말한다. */
    private static function fallback(array $f): string
    {
        $bits = [];
        if ($f['dday'] !== null && !$f['dday']['passed']) {
            $bits[] = "{$f['dday']['title']}까지 {$f['dday']['days']}일 남았어요.";
        }
        if ($f['todaySetCount'] > 0) {
            $bits[] = "오늘은 벌써 {$f['todaySetCount']}세트 {$f['todayReps']}회 하셨네요. 이어서 가볼까요?";
        } else {
            $bits[] = '오늘은 아직 기록이 없어요. 가벼운 것부터 한 세트 시작해 볼까요?';
        }
        if ($f['lastSession'] !== null) {
            $bits[] = '지난 수업은 ' . (string) $f['lastSession']['title'] . ' 이었어요.';
        }
        return implode(' ', $bits);
    }

    /** 우리가 만든 HTML 에서 글자만 뽑는다. */
    private static function plain(string $html): string
    {
        $text = strip_tags(str_replace(['</p>', '</li>', '<br>'], "\n", $html));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim((string) preg_replace('/\s*\n\s*/u', ' ', $text));
    }
}
