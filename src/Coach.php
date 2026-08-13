<?php
declare(strict_types=1);

namespace Health;

use Health\Llm\Deepseek;
use Health\Repo\Workout;

/**
 * "오늘의 메시지" — DB에 쌓인 기록을 보고 오늘 뭘 하면 좋을지 짧게 말해준다.
 *
 * 말은 두 줄로 줄이고, 실제로 할 것은 표(운동 / 시간 및 횟수)로 내보낸다.
 * 그래서 LLM 에게 글이 아니라 JSON 을 받아 온다 —
 *   {"greeting":"…","lead":"…","goals":[{"name":"유산소","target":"20분"}]}
 *
 * 화면(todayMessage)은 절대 API 를 부르지 않는다. 미리 만들어 둔 것을 읽기만 한다 —
 * "오늘의 운동" 을 열 때마다 15초씩 기다리게 되기 때문이다. 만드는 것은 refresh() 하나뿐이고,
 * 그것을 부르는 자리는 셋이다.
 *   · PT 메시지를 적어 저장했을 때            POST /pt-message
 *   · 하루에 한 번, 미리                      bin/coach.php (cron)
 *   · 그날 첫 화면을 다 보낸 뒤, 아직 없으면   buildIfMissing()
 * 아직 오늘 것이 없으면 있는 사실만으로 규칙 문장을 만들어 보여준다 — 화면은 늘 즉시 열린다.
 */
final class Coach
{
    /** 표에 넣을 줄 수. 이보다 많으면 잘라낸다. */
    private const MAX_GOALS = 6;

    /**
     * 만들기 진행 표시. 시작 · 끝 · 마지막 오류를 따로 둔다 —
     * 끝을 기록하지 않으면 실패했을 때 화면이 5분 내내 "쓰고 있어요" 로 남는다.
     */
    private const BUILD_AT    = 'coach_build_at';
    private const BUILD_DONE  = 'coach_build_done';
    private const BUILD_ERROR = 'coach_build_error';

    /** 이 시간이 넘도록 끝나지 않으면 죽은 것으로 본다(그리고 다시 시도할 수 있게 한다). */
    private const CLAIM_SECS = 300;

    /**
     * 추론 모델(deepseek-v4-pro)은 생각하는 데 토큰을 아주 많이 쓴다. 실제로 겪은 것 —
     *   1500 → 생각만 하다 끝나 빈 답        4000 → JSON 을 쓰다 중간에서 잘림
     * 답 자체는 200 토큰이면 되니 나머지는 전부 생각할 자리다. 쓴 만큼만 값을 치르므로 넉넉히 준다.
     */
    private const MAX_TOKENS = 8000;

    /** 마지막으로 "확인" 을 누른 메시지가 만들어진 시각. 이보다 나중 것이 있으면 알림이 뜬다. */
    private const SEEN = 'coach_seen_at';

    /**
     * 화면에 보여줄 오늘의 메시지. 질의 한 번이고, 네트워크를 타지 않는다.
     *
     * @return array{greeting:string,lead:string,goals:array<int,array{name:string,target:string}>,from:string}
     *         from: 'ready'(미리 만들어 둔 것) | 'rule'(아직 없어서 규칙으로 만든 것)
     */
    public static function todayMessage(): array
    {
        $ready = self::stored();
        if ($ready !== null) {
            return $ready + ['from' => 'ready'];
        }
        return self::fallback(self::facts()) + ['from' => 'rule'];
    }

    /** 오늘 것이 이미 만들어져 있는가. */
    public static function hasToday(): bool
    {
        return self::stored() !== null;
    }

    /** 지금 만들기 시작했다고 표시해 둔다. PT 메시지를 저장한 그 요청에서 미리 걸어 둔다. */
    public static function markBuilding(): void
    {
        if (!Db::isReadOnly()) {
            Settings::set(self::BUILD_AT, date('c'));
        }
    }

    /**
     * 지금 만드는 중인가. "곧 도착합니다" 를 보여주고 화면을 다시 열어 볼지 판단하는 데 쓴다.
     * 시작한 뒤에 끝났다는 표시가 있으면(성공이든 실패든) 더는 기다리지 않는다.
     */
    public static function isBuilding(): bool
    {
        $at = Settings::get(self::BUILD_AT);
        if ($at === '') {
            return false;
        }
        $started = (int) strtotime($at);
        if (time() - $started >= self::CLAIM_SECS) {
            return false;                       // 5분이 넘도록 감감무소식이면 죽은 것으로 본다
        }
        $done = Settings::get(self::BUILD_DONE);
        return $done === '' || (int) strtotime($done) < $started;
    }

    /** 마지막 만들기가 실패했으면 그 이유. 성공했거나 만든 적이 없으면 빈 문자열. */
    public static function lastError(): string
    {
        $done = Settings::get(self::BUILD_DONE);
        $at   = Settings::get(self::BUILD_AT);
        if ($done === '' || ($at !== '' && (int) strtotime($done) < (int) strtotime($at))) {
            return '';                          // 아직 안 끝났다 — 실패로 치지 않는다
        }
        return Settings::get(self::BUILD_ERROR);
    }

    private static function markBuilt(string $error = ''): void
    {
        if (Db::isReadOnly()) {
            return;
        }
        Settings::set(self::BUILD_DONE, date('c'));
        Settings::set(self::BUILD_ERROR, $error);
    }

    /**
     * 아직 "확인" 을 누르지 않은 오늘의 메시지. 있으면 그 줄의 id — 모든 화면에서 알림을 띄운다.
     * PT 메시지를 적어 새로 만들면 id 가 바뀌므로 하루에 두 번도 뜬다.
     */
    public static function unseen(): ?int
    {
        $row = self::latest();
        if ($row === null || self::parse((string) $row['message']) === null) {
            return null;
        }
        // id 가 아니라 만든 시각으로 견준다 — 같은 줄을 덮어써도(같은 fingerprint) 새것으로 알아본다.
        $seen = Settings::get(self::SEEN);
        if ($seen !== '' && strtotime((string) $row['created_at']) <= (int) strtotime($seen)) {
            return null;
        }
        return (int) $row['id'];
    }

    /**
     * 알림의 "확인" 을 눌렀다. 그 메시지까지만 읽은 것으로 치므로,
     * 누르는 사이에 새로 도착한 것이 있으면 그건 다시 뜬다.
     */
    public static function markSeen(int $id): void
    {
        if (Db::isReadOnly()) {
            return;
        }
        $at = Db::value('SELECT created_at FROM ai_messages WHERE id = ?', [$id]);
        Settings::set(self::SEEN, is_string($at) && $at !== '' ? $at : date('c'));
    }

    /**
     * 오늘 것이 없으면 조용히 만들어 둔다. **화면을 다 보낸 뒤에만** 부른다
     * (Http::afterResponse). 그날 처음 연 사람은 규칙 문장을 보고, 그 다음부터 AI 문장이 나온다.
     * cron(bin/coach.php)을 걸어 두면 여기까지 올 일이 거의 없다.
     */
    public static function buildIfMissing(): void
    {
        if (Db::isReadOnly() || !(new Deepseek())->isReady() || self::hasToday() || !self::claim()) {
            return;
        }
        try {
            self::refresh();
        } catch (\Throwable $e) {
            // 실패한 채로 두면 표시가 5분 남아 있어, 여는 족족 다시 부르지 않는다.
            App::log('오늘의 메시지 미리 만들기 실패: ' . $e->getMessage());
        }
    }

    /**
     * 만들기를 시작해도 되는가. 탭 두 개를 동시에 열어도 한 번만 부르고,
     * 방금 실패했으면 5분은 쉬었다 다시 시도한다 — 여는 족족 부르지 않는다.
     */
    private static function claim(): bool
    {
        $at = Settings::get(self::BUILD_AT);
        if ($at !== '' && time() - (int) strtotime($at) < self::CLAIM_SECS) {
            return false;
        }
        self::markBuilding();
        return true;
    }

    /**
     * 오늘의 메시지를 새로 만들어 저장한다. 여기가 유일하게 API 를 부르는 자리다.
     * 15초쯤 걸리므로 화면을 여는 길목에서는 절대 부르지 않는다.
     *
     * @return array{greeting:string,lead:string,goals:array<int,array{name:string,target:string}>}
     * @throws \RuntimeException 키가 없거나 · DB 가 읽기 전용이거나 · 호출이 실패하면
     */
    public static function refresh(): array
    {
        // 화면 뒤에서 도는 일이라 넉넉히 기다려 준다. 추론 모델은 4~15초쯤 걸린다.
        $llm = new Deepseek(timeout: 90);
        if (!$llm->isReady()) {
            throw new \RuntimeException('DEEPSEEK_KEY 가 없습니다. .env 를 확인하세요.');
        }
        if (Db::isReadOnly()) {
            throw new \RuntimeException('DB 가 읽기 전용이라 저장할 수 없습니다.');
        }

        try {
            $facts = self::facts();
            $raw   = $llm->chat(
                [
                    ['role' => 'system', 'content' => self::systemPrompt()],
                    ['role' => 'user',   'content' => self::userPrompt($facts)],
                ],
                // 말투보다 형식이 중요하다. 높이면 가끔 JSON 을 벗어난 글이 온다.
                temperature: 0.6,
                maxTokens: self::MAX_TOKENS
            );

            $message = self::parse($raw);
            if ($message === null) {
                // 200자로 잘라 던지면 나중에 왜 어긋났는지 알 길이 없다. 받은 글은 통째로 남긴다.
                App::log("오늘의 메시지 형식이 어긋났다. 받은 글:\n" . $raw);
                throw new \RuntimeException('메시지 형식이 어긋나 읽지 못했습니다.');
            }
        } catch (\Throwable $e) {
            // 끝났다는 표시를 남겨야 화면이 "쓰고 있어요" 에서 빠져나온다.
            self::markBuilt($e->getMessage());
            throw $e;
        }

        // fingerprint 는 이제 호출을 막는 열쇠가 아니라 "무엇을 보고 만들었는지" 기록이다.
        // 하루에 줄이 여럿 생길 수 있고(아침 cron · PT 메시지 저장), 읽을 때 가장 나중 것을 쓴다.
        Db::run(
            'INSERT INTO ai_messages (day, fingerprint, message, model, created_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(day, fingerprint) DO UPDATE SET
                message    = excluded.message,
                model      = excluded.model,
                created_at = excluded.created_at',
            [
                date('Y-m-d'),
                self::fingerprint($facts),
                (string) json_encode($message, JSON_UNESCAPED_UNICODE),
                App::env('LLM_MODEL'),
                date('c'),
            ]
        );
        self::markBuilt();

        return $message;
    }

    /**
     * 저장해 둔 오늘 것. 없거나 형식이 어긋나면 null.
     * (예전에 글로 저장해 둔 줄은 parse 가 null 을 준다 — 없는 것으로 친다)
     *
     * @return array{greeting:string,lead:string,goals:array<int,array{name:string,target:string}>}|null
     */
    private static function stored(): ?array
    {
        $row = self::latest();
        return $row === null ? null : self::parse((string) $row['message']);
    }

    /**
     * 오늘 저장해 둔 것 중 가장 나중 줄. 아침 cron 것과 PT 메시지로 다시 만든 것이 함께 있을 수 있다.
     *
     * @return array{id:int,message:string,created_at:string}|null
     */
    private static function latest(): ?array
    {
        /** @var array{id:int,message:string,created_at:string}|null $row */
        $row = Db::one(
            'SELECT id, message, created_at FROM ai_messages
              WHERE day = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [date('Y-m-d')]
        );
        return $row;
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
            'ptNote'        => PtNote::forDay(),
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
     * 무엇을 보고 만든 메시지인지 남기는 표시.
     * 같은 근거로 다시 만들면(하루 두 번 cron 을 돌린다든가) 그 줄을 덮어쓴다.
     */
    private static function fingerprint(array $f): string
    {
        return md5(json_encode([
            $f['dday']['days'] ?? null,
            $f['todaySetCount'] > 0 ? 'started' : 'none',
            $f['lastSession']['id'] ?? null,
            $f['ptNote'],
        ], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * LLM 이 준 글(또는 캐시에 넣어 둔 글)에서 화면에 쓸 세 조각을 뽑는다.
     * 형식이 어긋나면 null — 부르는 쪽이 규칙 문장으로 넘어간다.
     *
     * @return array{greeting:string,lead:string,goals:array<int,array{name:string,target:string}>}|null
     */
    private static function parse(string $raw): ?array
    {
        $raw = trim($raw);
        // ```json … ``` 로 감싸서 올 때가 있다.
        if (str_starts_with($raw, '```')) {
            $raw = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/iu', '', $raw));
        }
        $data = json_decode($raw, true);
        // 앞뒤에 말을 붙여 올 때가 있다 — 중괄호 덩어리만 떼어 다시 본다.
        if (!is_array($data)) {
            $from = strpos($raw, '{');
            $to   = strrpos($raw, '}');
            if ($from === false || $to === false || $to < $from) {
                return null;
            }
            $data = json_decode(substr($raw, $from, $to - $from + 1), true);
        }
        if (!is_array($data)) {
            return null;
        }

        $greeting = self::line($data['greeting'] ?? '', 120);
        $lead     = self::line($data['lead'] ?? '', 120);
        if ($greeting === '') {
            return null;
        }

        $goals = [];
        foreach (is_array($data['goals'] ?? null) ? $data['goals'] : [] as $g) {
            if (!is_array($g)) {
                continue;
            }
            $name   = self::line($g['name'] ?? '', 24);
            $target = self::line($g['target'] ?? '', 24);
            if ($name === '') {
                continue;
            }
            $goals[] = ['name' => $name, 'target' => $target];
            if (count($goals) >= self::MAX_GOALS) {
                break;
            }
        }

        return ['greeting' => $greeting, 'lead' => $lead, 'goals' => $goals];
    }

    /** 표 한 칸에 들어갈 한 줄로 다듬는다. */
    private static function line(mixed $v, int $max): string
    {
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            return '';
        }
        $s = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        return mb_substr($s, 0, $max);
    }

    private static function systemPrompt(): string
    {
        return <<<'TXT'
        당신은 "송이"의 개인 트레이너입니다. 송이가 오늘 운동을 시작하려고 앱을 열었습니다.

        답은 아래 모양의 JSON 하나로만 합니다. 앞뒤에 다른 말을 붙이지 않습니다.
        {"greeting":"송이님, 오늘로 바디프로필까지 17일 남았네요.",
         "lead":"PT쌤의 메시지를 반영해서 오늘의 운동을 시작해보세요!",
         "goals":[{"name":"유산소","target":"20분"},{"name":"하체","target":"4세트"}]}

        규칙:
        - 한국어 존댓말. 말은 짧게, 할 일은 표(goals)로 보여줍니다.
        - greeting: 한 문장. "송이님," 으로 시작합니다. D-DAY 가 남아 있으면 여기서 한 번만 말합니다.
          오늘 이미 기록한 세트가 있으면 그걸 알아주는 말을 대신 넣습니다. 40자 안팎.
        - lead: 한 문장. 오늘 운동을 시작해보자는 짧은 권유. 30자 안팎.
        - goals: 오늘 할 것 2~5줄. name 은 운동 또는 부위 이름(10자 이내),
          target 은 "20분", "4세트", "12회 3세트" 처럼 시간·횟수만 짧게 씁니다.
        - PT쌤이 오늘 남긴 메시지가 있으면 그 내용을 그대로 goals 에 옮깁니다. 그게 최우선입니다.
          없으면 지난 회차에서 한 운동을 근거로 오늘 할 것을 정합니다.
        - 오늘 이미 한 세트가 있으면 남은 만큼으로 줄여서 적습니다.
        - 주어진 기록에 없는 숫자나 사실을 지어내지 않습니다.
        - 설명·이유·불릿·이모지·마크다운·"AI"·"분석" 같은 말은 쓰지 않습니다.
        TXT;
    }

    private static function userPrompt(array $f): string
    {
        $lines = ['오늘: ' . $f['today']];

        if ($f['dday'] !== null && !$f['dday']['passed']) {
            $lines[] = "{$f['dday']['title']}까지 {$f['dday']['days']}일 남음 ({$f['dday']['date']})";
        }

        if ($f['ptNote'] !== '') {
            $lines[] = '오늘 PT쌤이 남긴 메시지(가장 중요): ' . $f['ptNote'];
        }

        if ($f['todaySetCount'] > 0) {
            $done = [];
            foreach ($f['todaySets'] as $s) {
                // 유산소는 횟수가 아니라 시간으로 잰다.
                $done[] = ($s['secs'] ?? 0) > 0
                    ? "{$s['name']} " . Workout::humanSecs((float) $s['secs'])
                    : "{$s['name']} {$s['sets']}세트 {$s['reps']}회";
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
        $lines[] = '위 내용으로 오늘의 인사와 목표 표를 json 으로 만들어 주세요.';

        return implode("\n", $lines);
    }

    /**
     * API 를 못 부를 때 쓰는 문장. 지어내지 않고 있는 사실만 말한다.
     *
     * @return array{greeting:string,lead:string,goals:array<int,array{name:string,target:string}>}
     */
    private static function fallback(array $f): array
    {
        $d = $f['dday'];
        $greeting = $d !== null && !$d['passed']
            ? "송이님, 오늘로 {$d['title']}까지 {$d['days']}일 남았네요."
            : '송이님, 오늘도 오셨네요.';
        if ($f['todaySetCount'] > 0) {
            $greeting .= " 오늘 벌써 {$f['todaySetCount']}세트 하셨어요.";
        }

        $lead = $f['ptNote'] !== ''
            ? 'PT쌤의 메시지를 반영해서 오늘의 운동을 시작해보세요!'
            : '지난 회차대로 오늘의 운동을 시작해보세요!';

        // PT쌤이 남긴 말이 있으면 그걸 그대로 한 줄 보여준다(요약할 방법이 없으니 옮기기만 한다).
        $goals = [];
        if ($f['ptNote'] !== '') {
            $goals[] = ['name' => 'PT쌤 메시지', 'target' => mb_substr($f['ptNote'], 0, 60)];
        }
        foreach ($f['lastExercises'] as $ex) {
            if (count($goals) >= 5) {
                break;
            }
            $meta     = trim((string) ($ex['meta'] ?? ''));
            $goals[]  = [
                'name'   => mb_substr((string) $ex['name'], 0, 24),
                'target' => $meta === '' ? '지난 회차대로' : mb_substr($meta, 0, 24),
            ];
        }

        return ['greeting' => $greeting, 'lead' => $lead, 'goals' => $goals];
    }

    /** 우리가 만든 HTML 에서 글자만 뽑는다. */
    private static function plain(string $html): string
    {
        $text = strip_tags(str_replace(['</p>', '</li>', '<br>'], "\n", $html));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim((string) preg_replace('/\s*\n\s*/u', ' ', $text));
    }
}
