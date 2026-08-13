<?php
declare(strict_types=1);

/**
 * 유일한 진입점(프론트 컨트롤러).
 * 웹서버는 이 폴더(public/)로만 요청을 보낸다. storage/ 는 웹 루트 밖에 있다.
 */

require dirname(__DIR__) . '/src/App.php';

use Health\App;
use Health\Auth;
use Health\Csrf;
use Health\Db;
use Health\Http;
use Health\Router;
use Health\View;

App::boot();
Http::securityHeaders();
header('Content-Type: text/html; charset=UTF-8');
Auth::startSession();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = Router::pathFromRequest(
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    (string) App::conf('base_path', '')
);

App::setPath($path);

$r = new Router();

/**
 * 웹서버 사용자에게 storage/ 쓰기 권한이 없으면 DB 가 읽기 전용으로 열린다.
 * 그래도 열람은 되도록 두되, 저장하는 동작은 여기서 미리 막고 이유를 알려준다.
 * (로그인 · 로그아웃은 세션만 쓰므로 통과시킨다)
 */
if ($method === 'POST' && !in_array($path, ['/login', '/logout'], true) && Db::isReadOnly()) {
    Http::status(503);
    View::render('error', [
        'title'   => '지금은 읽기 전용입니다',
        'message' => '웹서버가 storage 폴더에 쓸 수 없어 기록을 저장할 수 없습니다. '
            . 'NAS 에서 storage 와 public/media 에 웹서버 사용자(http) 읽기·쓰기 권한을 주세요.',
    ]);
    exit;
}

/* 인증 ---------------------------------------------------------- */

$r->get('/login', function (): void {
    if (Auth::check()) {
        Http::redirect('/');
    }
    $error = isset($_SESSION['login_error']) ? (string) $_SESSION['login_error'] : null;
    unset($_SESSION['login_error']);
    View::render('login', [
        'title'      => '로그인',
        'configured' => Auth::configured(),
        'username'   => (string) App::conf('username', 'bs2'),
        'error'      => $error,
        'nav'        => false,
    ]);
});

$r->post('/login', function (): void {
    Csrf::check();
    if (!Auth::attempt(Http::post('username'), Http::post('password'))) {
        $_SESSION['login_error'] = '아이디 또는 비밀번호가 맞지 않습니다.';
        Http::redirect('/login');
    }
    $to = (string) ($_SESSION['after_login'] ?? '/');
    unset($_SESSION['after_login'], $_SESSION['login_error']);
    Http::redirect($to === '/login' ? '/' : $to);
});

$r->post('/logout', function (): void {
    Csrf::check();
    Auth::logout();
    Http::redirect('/login');
});

/* 여기부터는 로그인 필수 ---------------------------------------- */

$r->get('/', function (): void {
    Auth::require();
    View::render('home', ['ddayLine' => \Health\Settings::ddayLine()]);
});

/* D-DAY 설정 -------------------------------------------------- */

$r->get('/dday', function (): void {
    Auth::require();
    $saved = !empty($_SESSION['dday_saved']);
    unset($_SESSION['dday_saved']);
    View::render('dday', [
        'title' => 'D-DAY 설정',
        'back'  => '/',
        'dday'  => \Health\Settings::dday(),
        'saved' => $saved,
    ]);
});

$r->post('/dday', function (): void {
    Auth::require();
    Csrf::check();
    $title = trim(Http::post('title'));
    $date  = trim(Http::post('date'));
    if ($title !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
        \Health\Settings::set(\Health\Settings::DDAY_TITLE, mb_substr($title, 0, 30));
        \Health\Settings::set(\Health\Settings::DDAY_DATE, $date);
        $_SESSION['dday_saved'] = true;
    }
    Http::redirect('/dday');
});

/* PT 메시지 — 수업에서 들은 말을 그날 날짜로 적어 둔다 ---------- */

$r->get('/pt-message', function (): void {
    Auth::require();
    $saved = !empty($_SESSION['pt_note_saved']);
    unset($_SESSION['pt_note_saved']);

    // 만드는 중이면 화면이 스스로 다시 열려 본다. 다 되면 알림이 뜬다(JavaScript 없이).
    $waiting = \Health\Coach::isBuilding() && \Health\Coach::unseen() === null;

    View::render('pt_message', [
        'title'   => 'PT 메시지 추가',
        'back'    => '/',
        'day'     => date('Y-m-d'),
        'note'    => \Health\PtNote::forDay(),
        'saved'   => $saved,
        'waiting' => $waiting,
        'failed'  => !$waiting && \Health\Coach::lastError() !== '',
        'refresh' => $waiting ? ['secs' => 4, 'to' => '/pt-message'] : null,
    ]);
});

$r->post('/pt-message', function (): void {
    Auth::require();
    Csrf::check();
    $text = trim(Http::post('message'));
    if ($text !== '') {
        \Health\PtNote::save($text);
        $_SESSION['pt_note_saved'] = true;
        // 만드는 데 15초쯤 걸린다. 화면은 바로 돌려주고, 만들기는 응답을 다 보낸 뒤에 한다.
        // 다 되면 어느 화면에 있든 알림이 뜬다(Coach::unseen).
        \Health\Coach::markBuilding();
        Http::afterResponse(static function (): void {
            set_time_limit(180);
            try {
                \Health\Coach::refresh();
            } catch (\Throwable $e) {
                App::log('PT 메시지 반영 실패: ' . $e->getMessage());
            }
        });
    }
    Http::redirect('/pt-message');
});

/** 알림의 "확인". 오늘 읽은 것으로 표시하고 오늘의 운동으로 보낸다. */
$r->post('/coach/seen', function (): void {
    Auth::require();
    Csrf::check();
    \Health\Coach::markSeen(Http::postInt('id'));
    Http::redirect('/today');
});

/**
 * 인바디 앱 열기.
 *
 * apps.apple.com 링크로는 앱이 바로 열리지 않는다 — App Store 상세 화면이 뜰 뿐이다.
 * 앱을 직접 띄우려면 커스텀 스킴이 필요하므로, User-Agent 를 보고 갈라준다.
 * (JavaScript 없이 서버에서 판단한다)
 */
$r->get('/inbody', function (): void {
    Auth::require();

    $scheme = 'InBodyRefac://';
    $store  = 'https://apps.apple.com/kr/app/inbody/id884923678';
    $ua     = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $isIos  = preg_match('/iPhone|iPad|iPod/i', $ua) === 1
        // 아이패드 사파리는 데스크톱으로 위장한다. 터치 가능한 Mac 은 아이패드로 본다.
        || (str_contains($ua, 'Macintosh') && str_contains($ua, 'Mobile'));

    if (!$isIos) {
        header('Location: ' . $store, true, 302);
        exit;
    }

    // iOS 는 앱 설치 여부를 물어볼 방법이 없다. 스킴으로 넘겨보고 화면이 그대로 살아 있으면
    // App Store 로 보내는 판별을 assets/inbody.js 가 한다.
    // JavaScript 가 꺼져 있어도 화면의 버튼 두 개로 직접 갈 수 있다.
    View::render('inbody', [
        'title'  => '인바디',
        'back'   => '/',
        'scheme' => $scheme,
        'store'  => $store,
    ]);
});

/* 가져오기 ------------------------------------------------------ */

$importScreen = function (?string $error = null, ?array $result = null): void {
    View::render('import', [
        'title'   => '노션 가져오기',
        'back'    => '/',
        'error'   => $error,
        'result'  => $result,
        'sources' => Db::all('SELECT kind, title, imported_at FROM sources ORDER BY imported_at ASC'),
        'media'   => [
            'pending' => (int) Db::value('SELECT COUNT(*) FROM media WHERE status = ?', ['pending']),
            'done'    => (int) Db::value('SELECT COUNT(*) FROM media WHERE status = ?', ['done']),
            'failed'  => (int) Db::value('SELECT COUNT(*) FROM media WHERE status = ?', ['failed']),
        ],
    ]);
};

$r->get('/import', function () use ($importScreen): void {
    Auth::require();
    $result = isset($_SESSION['import_result']) ? (array) $_SESSION['import_result'] : null;
    $error  = isset($_SESSION['import_error']) ? (string) $_SESSION['import_error'] : null;
    unset($_SESSION['import_result'], $_SESSION['import_error']);
    $importScreen($error, $result);
});

$r->post('/import', function (): void {
    Auth::require();
    Csrf::check();
    // 13개 페이지를 모으는 데 10초쯤 걸린다. 미디어는 여기서 받지 않는다.
    set_time_limit(300);
    try {
        $_SESSION['import_result'] = \Health\Notion\Import::run(Http::post('url'));
    } catch (\Throwable $e) {
        App::log('가져오기 실패: ' . $e->getMessage());
        $_SESSION['import_error'] = $e->getMessage();
    }
    Http::redirect('/import');
});

/* 미디어 내려받기 2단계 ------------------------------------------ */

$r->get('/import/media', function (): void {
    Auth::require();

    // 받기는 세션에 '진행 중' 표시가 있을 때만 한다. 남의 사이트에서 GET 하나로
    // 내려받기가 시작되지 않게 막는 장치다. 시작은 POST(+CSRF)로만 한다.
    $running  = !empty($_SESSION['media_run']);
    $justDone = [];

    if ($running && Http::param('run') === '1') {
        set_time_limit(600);
        (new \Health\MediaFetcher())->fetchBatch(
            \Health\MediaFetcher::BATCH,
            function (string $file, string $how, int $bytes) use (&$justDone): void {
                $justDone[] = match ($how) {
                    'ok'   => "받음 $file · " . \Health\MediaFetcher::humanBytes($bytes),
                    'skip' => "이미 있음 $file",
                    default => "실패 $file",
                };
            }
        );
    } else {
        unset($_SESSION['media_run']);
        $running = false;
    }

    $stat = \Health\MediaFetcher::stats();
    View::render('import_media', [
        'title'    => '미디어 내려받기',
        'back'     => '/import',
        'stat'     => $stat,
        'justDone' => $justDone,
        'running'  => $running,
        // 남은 게 있으면 자기 자신을 다시 부른다. JavaScript 없이 이어진다.
        'refresh'  => $running && $stat['pending'] + $stat['failed'] > 0
            ? ['secs' => 1, 'to' => '/import/media?run=1']
            : null,
    ]);
});

$r->post('/import/media', function (): void {
    Auth::require();
    Csrf::check();
    $_SESSION['media_run'] = true;
    Http::redirect('/import/media?run=1');
});

/* 열람 ---------------------------------------------------------- */

use Health\Repo\Log;

$r->get('/log', function (): void {
    Auth::require();
    View::render('log', ['title' => '기록 보기', 'back' => '/', 'totals' => Log::totals()]);
});

$r->get('/log/dates', function (): void {
    Auth::require();
    View::render('log_dates', [
        'title'    => '일자별 PT 기록',
        'back'     => '/',
        'sessions' => Log::sessions(),
    ]);
});

$r->get('/log/session/{id}', function (string $id): void {
    Auth::require();
    $session = Log::session((int) $id);
    if ($session === null) {
        Http::notFound('그런 회차가 없습니다.');
    }
    View::render('log_session', [
        'title'     => (string) $session['title'],
        'back'      => '/log/dates',
        'session'   => $session,
        'exercises' => Log::exercisesOf((int) $session['id']),
        'mediaMap'  => \Health\Media::forSessions([(int) $session['id']]),
    ]);
});

$r->get('/log/parts', function (): void {
    Auth::require();
    View::render('log_parts', ['title' => '운동부위별 PT 기록', 'back' => '/', 'parts' => Log::parts()]);
});

$r->get('/log/part/{name}', function (string $name): void {
    Auth::require();
    $items = Log::part($name);
    if ($items === []) {
        Http::notFound('그 부위에 해당하는 운동이 없습니다.');
    }
    View::render('log_part', [
        'title'    => $name,
        'back'     => '/log/parts',
        'part'     => $name,
        'items'    => $items,
        'mediaMap' => \Health\Media::forSessions(
            array_values(array_unique(array_map(static fn ($i) => (int) $i['session_id'], $items)))
        ),
    ]);
});

$r->get('/log/all', function (): void {
    Auth::require();
    $sessions = Log::sessions();
    $groups   = [];
    foreach ($sessions as $s) {
        $groups[] = ['session' => $s, 'exercises' => Log::exercisesOf((int) $s['id'])];
    }
    View::render('log_all', [
        'title'    => '전체 PT 기록',
        'back'     => '/',
        'groups'   => $groups,
        'mediaMap' => \Health\Media::forSessions(array_map(static fn ($s) => (int) $s['id'], $sessions)),
    ]);
});

/* 오늘의 운동 ---------------------------------------------------- */

use Health\Parts;
use Health\Repo\Workout;

$r->get('/today', function (): void {
    Auth::require();
    $tab  = Http::param('part', '전체');
    $all  = Workout::exercises();

    $exercises = $tab === '전체' ? $all : array_values(array_filter(
        $all,
        static fn (array $e): bool => in_array(
            $tab,
            Parts::ofExercise((string) $e['name'], $e['part_override'] ?? null),
            true
        )
    ));

    View::render('today', [
        'title'       => '오늘의 운동',
        // 미리 만들어 둔 것을 읽기만 한다(질의 한 번). 여기서 API 를 부르지 않는다.
        'coach'       => \Health\Coach::todayMessage(),
        'back'        => '/',
        'tab'         => $tab,
        'tabs'        => array_merge(['전체'], Parts::names()),
        'exercises'   => $exercises,
        'lastNotes'   => Workout::lastNotes(),
        'openWorkout' => Workout::open(),
    ]);

    // 화면은 이미 다 나갔다. 오늘 것이 아직 없으면 여기서 만들어 둔다 —
    // 그날 처음 연 사람만 규칙 문장을 보고, 그 다음부터는 AI 문장이 바로 나온다.
    Http::afterResponse(static fn () => \Health\Coach::buildIfMissing());
});

$r->post('/today/add', function (): void {
    Auth::require();
    Csrf::check();
    try {
        $part = trim(Http::post('part'));
        $id   = Workout::exerciseByName(Http::post('name'), $part === '' ? null : $part);
    } catch (\Throwable $e) {
        Http::redirect('/today');
    }
    Http::redirect('/today/' . $id);
});

$r->get('/today/finish', function (): void {
    Auth::require();
    $w = Workout::open() ?? Db::one('SELECT * FROM workouts ORDER BY id DESC LIMIT 1');
    View::render('today_finish', [
        'title'   => '오늘 요약',
        'back'    => '/today',
        'workout' => $w,
        'summary' => $w === null ? [] : Workout::summary((int) $w['id']),
        'ended'   => $w !== null && !empty($w['ended_at']),
    ]);
});

$r->post('/today/finish', function (): void {
    Auth::require();
    Csrf::check();
    $w = Workout::open();
    if ($w !== null) {
        $memo = trim(Http::post('memo'));
        Workout::finish((int) $w['id'], $memo === '' ? null : $memo);
    }
    Http::redirect('/today/finish');
});

$r->get('/today/{id}', function (string $id): void {
    Auth::require();
    $exercise = Workout::exercise((int) $id);
    if ($exercise === null) {
        Http::notFound('그런 운동이 없습니다.');
    }
    $w    = Workout::open();
    $sets = $w === null ? [] : Workout::sets((int) $w['id'], (int) $id);
    $cur  = $w === null ? ['reps' => 0, 'weight' => null] : Workout::current((int) $w['id'], (int) $id);

    // 유산소는 시간으로 세는 게 기본이다. 그 밖에는 횟수 탭으로 연다.
    $parts   = \Health\Parts::ofExercise((string) $exercise['name'], $exercise['part_override'] ?? null);
    $default = in_array('유산소', $parts, true) ? 'time' : 'count';

    View::render('today_counter', [
        'title'     => (string) $exercise['name'],
        'back'      => '/today',
        'exercise'  => $exercise,
        'sets'      => $sets,
        'current'   => $cur,
        'tab'       => in_array(Http::param('tab'), ['time', 'count'], true) ? Http::param('tab') : $default,
        'totalSets' => count($sets),
        'totalReps' => array_sum(array_map(static fn ($s) => (int) $s['reps'], $sets)),
        'totalSecs' => array_sum(array_map(static fn ($s) => (float) ($s['secs'] ?? 0), $sets)),
    ]);
});

/**
 * 카운터의 동작들.
 *
 * 화면은 fetch 로 부르고 JSON(지금 값 · 쌓인 세트)을 돌려받는다 — 화면이 넘어가지 않는다.
 * 자바스크립트가 없으면 같은 주소로 그냥 POST 되어 예전처럼 리다이렉트로 돌아간다.
 */
$counterAction = function (string $id, callable $do): void {
    Auth::require();
    Csrf::check();
    $eid = (int) $id;
    if (Workout::exercise($eid) === null) {
        Http::notFound('그런 운동이 없습니다.');
    }
    $wid = Workout::ensure();
    $do($wid, $eid);

    if (!Http::wantsJson()) {
        Http::redirect('/today/' . $eid);
    }

    $sets = Workout::sets($wid, $eid);
    Http::json([
        'current'   => Workout::current($wid, $eid),
        'sets'      => array_map(static fn (array $s): array => [
            'id'     => (int) $s['id'],
            'set_no' => (int) $s['set_no'],
            'reps'   => (int) $s['reps'],
            'weight' => $s['weight'] !== null ? (float) $s['weight'] : null,
            'secs'   => $s['secs'] !== null ? (float) $s['secs'] : null,
        ], $sets),
        'totalSets' => count($sets),
        'totalReps' => array_sum(array_map(static fn ($s) => (int) $s['reps'], $sets)),
        'totalSecs' => array_sum(array_map(static fn ($s) => (float) ($s['secs'] ?? 0), $sets)),
    ]);
};

/**
 * 세고 있는 횟수를 맞춰 둔다.
 *
 * 화면은 이미 제 숫자를 알고 있으므로 답을 기다릴 필요가 없다 — 200 을 먼저 주고
 * 쓰기는 그 뒤에 한다. NAS 에서 쓰기 한 번이 2초쯤 걸려서, 안 그러면 손가락이 멈춘다.
 * `+1 세트`는 횟수를 스스로 들고 가므로 이게 늦어도 어긋나지 않는다.
 */
$r->post('/today/{id}/rep', function (string $id): void {
    Auth::require();
    Csrf::check();
    $eid = (int) $id;
    if (Workout::exercise($eid) === null) {
        Http::notFound('그런 운동이 없습니다.');
    }
    $reps = isset($_POST['reps']) ? Http::postInt('reps') : null;
    $delta = Http::postInt('delta', 1);

    if (!Http::wantsJson()) {                    // 자바스크립트 없이 쓸 때
        $wid = Workout::ensure();
        $reps === null ? Workout::addRep($wid, $eid, $delta) : Workout::setReps($wid, $eid, $reps);
        Http::redirect('/today/' . $eid);
    }

    Http::afterResponse(static function () use ($eid, $reps, $delta): void {
        $wid = Workout::ensure();
        $reps === null ? Workout::addRep($wid, $eid, $delta) : Workout::setReps($wid, $eid, $reps);
    });
    Http::json(['ok' => true], 202);
});

/** 자바스크립트가 없을 때 쓰는 −1. */
$r->post('/today/{id}/minus', function (string $id) use ($counterAction): void {
    $counterAction($id, static fn (int $w, int $e) => Workout::addRep($w, $e, -1));
});

/**
 * `+1 세트` — 한 줄 쌓는다.
 * 횟수·무게를 같이 받는다. 그래야 화면이 요청 한 번으로 끝난다(느린 디스크에서 두 번은 길다).
 */
$r->post('/today/{id}/set', function (string $id) use ($counterAction): void {
    $counterAction($id, static function (int $w, int $e): void {
        if (isset($_POST['reps'])) {
            Workout::setReps($w, $e, Http::postInt('reps'));
        }
        if (isset($_POST['weight'])) {
            $raw = trim(Http::post('weight'));
            Workout::setWeight($w, $e, $raw === '' ? null : (float) $raw);
        }
        Workout::commitSet($w, $e);
    });
});

/** 스톱워치 기록 — 걸린 시간으로 한 줄 쌓는다. */
$r->post('/today/{id}/time', function (string $id) use ($counterAction): void {
    $counterAction($id, static function (int $w, int $e): void {
        Workout::commitTime($w, $e, (float) Http::post('secs'));
    });
});

$r->post('/today/{id}/weight', function (string $id) use ($counterAction): void {
    $counterAction($id, static function (int $w, int $e): void {
        $raw = trim(Http::post('weight'));
        Workout::setWeight($w, $e, $raw === '' ? null : (float) $raw);
    });
});

$r->post('/today/{id}/edit', function (string $id) use ($counterAction): void {
    $counterAction($id, static function (int $w, int $e): void {
        $raw = trim(Http::post('weight'));
        Workout::editSet(Http::postInt('set_id'), $w, Http::postInt('reps'), $raw === '' ? null : (float) $raw);
    });
});

$r->post('/today/{id}/delete', function (string $id) use ($counterAction): void {
    $counterAction($id, static function (int $w, int $e): void {
        Workout::deleteSet(Http::postInt('set_id'), $w);
    });
});

/* 지난 기록 ------------------------------------------------------ */

$r->get('/workouts', function (): void {
    Auth::require();
    View::render('workouts', ['title' => '지난 기록', 'back' => '/today', 'workouts' => Workout::history()]);
});

$r->get('/workouts/{id}', function (string $id): void {
    Auth::require();
    $w = Db::one('SELECT * FROM workouts WHERE id = ?', [(int) $id]);
    if ($w === null) {
        Http::notFound('그런 기록이 없다.');
    }
    View::render('today_finish', [
        'title'   => '기록',
        'back'    => '/workouts',
        'workout' => $w,
        'summary' => Workout::summary((int) $w['id']),
        'ended'   => !empty($w['ended_at']),
    ]);
});

if (!$r->dispatch($method, $path)) {
    Auth::require();
    Http::notFound();
}
