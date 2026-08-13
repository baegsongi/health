# 설치 · 운영

README에서 옮겨온 기술 문서입니다. 설계 배경과 이미 확인된 API 형식은
[`2026-08-12-health-php.md`](2026-08-12-health-php.md)에, 만들면서 내린 결정은
[`decisions.md`](decisions.md)에 있습니다.

---

## 요구 환경

| | |
|---|---|
| PHP | **8.2 이상** (8.3 권장) |
| 확장 | `pdo_sqlite` · `curl` · `mbstring` · `json` |
| 웹서버 | Apache 2.4 + `mod_rewrite` (`AllowOverride All`) |
| 그 외 | Composer 없음 · 프레임워크 없음 |

JavaScript는 두 곳에 쓰입니다 — 인바디 앱 열기와, "오늘의 운동" 카운터(스톱워치 ·
버튼을 눌렀을 때 화면을 먼저 고치고 서버에 알리는 부분). 꺼도 열람은 전부 되고,
카운터도 예전처럼 버튼마다 화면이 새로 열리는 방식으로 돌아갑니다(스톱워치만 안 됩니다).

---

## 설치

### 1. 파일 두기

저장소를 웹서버가 보는 디렉터리에 둡니다.

**문서 루트를 `public/`으로 지정할 수 있다면** 그렇게 하는 것이 가장 좋습니다.
지정할 수 없다면(저장소 루트가 그대로 문서 루트가 되는 경우) 루트의 `.htaccess`가

- 모든 요청을 `public/index.php`로 돌리고
- `storage` · `src` · `bin` · `migrations` · `tests` · 설정 파일을 403으로 막습니다

`.htaccess`가 무시되는 환경(nginx 등)에서는 **`storage/health.sqlite`가 그대로
다운로드됩니다.** 반드시 Apache + `AllowOverride All`인지 확인하세요.

### 2. 설정 파일

```bash
cp config.local.php.example config.local.php
```

`config.local.php`는 git에 올라가지 않습니다. 값은 `config.php`의 기본값을 덮어씁니다.

| 키 | 설명 |
|---|---|
| `username` | 로그인 아이디 (기본 `bs2`) |
| `password_hash` | `password_hash()` 결과. 아래 3번 참고 |
| `base_path` | 하위 경로에 얹을 때. 예: `/health` |
| `secure_cookie` | https면 `true`. 로컬 http 확인 중이면 `false` |
| `debug` | `true`면 오류를 화면에도 보여줍니다. **실서비스에서는 끄세요** |

### 3. AI 메시지 (선택)

`.env` 를 만들면 "오늘의 운동" 화면 맨 위에 **오늘의 메시지**가 나옵니다.
DB에 쌓인 기록(지난 PT 회차의 선생님 안내 · 오늘 기록한 세트 · D-DAY 남은 날)을 보고
말해 줍니다.

```bash
cp .env.example .env
```

| 키 | 설명 |
|---|---|
| `LLM_MODEL` | 예: `deepseek-chat`, `deepseek-v4-pro` |
| `DEEPSEEK_KEY` | DeepSeek API 키 |

`.env` 는 git 에 올라가지 않습니다. 키가 없으면 AI 호출을 건너뛰고 규칙으로 만든
문장이 대신 나옵니다 — 화면은 어떤 경우에도 열립니다.

**추론 모델(`deepseek-v4-pro` 등)은 생각하는 데 토큰을 아주 많이 씁니다.** 실제로 겪은 것 —
`max_tokens` 가 1500이면 생각만 하다 끝나 답이 비어서 오고, 4000이면 JSON 을 쓰다 중간에서
잘립니다. 그래서 오늘의 메시지는 **8000**으로 부릅니다(`Coach::MAX_TOKENS`). 답 자체는
200 토큰이면 되고 쓴 만큼만 값을 치르므로, 나머지는 전부 생각할 자리입니다.

**`response_format: json_object` 는 쓰지 않습니다.** 같은 모델에서 재보니 생각한 내용을
그대로 답에 쏟거나(1759자) 시킨 항목을 빼먹었습니다. 프롬프트로 그냥 부탁하면 3~4초에
깔끔하게 옵니다. 답이 잘리면(`finish_reason: length`) `Llm\Deepseek` 이 바로 끊습니다 —
잘린 JSON 을 넘기면 엉뚱한 데서 터지기 때문입니다.

**지금은 `deepseek-chat` 을 씁니다.** 이 일은 생각이 많이 필요한 일이 아닙니다.
같은 프롬프트로 재보면 차이가 큽니다.

| 모델 | 걸린 시간 | 형식 |
|---|---|---|
| `deepseek-v4-pro` | 4~30초 | 자주 어긋남(빈 답 · 중간에서 잘림) |
| `deepseek-chat` | **1.3~1.5초** | 3/3 성공 |

#### 언제 만드는가

**"오늘의 운동" 화면은 절대 API를 부르지 않습니다** — 미리 만들어 둔 것을 읽기만
합니다(질의 한 번). 만드는 자리는 셋뿐입니다.

| 언제 | 어디서 |
|---|---|
| PT 메시지를 적어 저장했을 때 | `POST /pt-message` |
| 하루에 한 번, 미리 | `php bin/coach.php` (cron) |
| 그날 처음 화면을 열었는데 아직 없을 때 | `GET /today` |

**어느 경우든 사람을 기다리게 하지 않습니다.** 화면(리다이렉트 포함)을 먼저 다 보내고
그 뒤에 만듭니다(`Http::afterResponse` → `fastcgi_finish_request`, PHP-FPM 에서만 동작).
다 만들어지면 **어느 화면에 있든 알림이 뜹니다** — "AI PT쌤의 메시지가 도착했어요".
`확인`을 누르면 읽은 것으로 표시하고 오늘의 운동으로 갑니다. 누르기 전까지는 계속 뜨고,
누른 뒤에는 새로 만들어지기 전까지 뜨지 않습니다(`settings.coach_seen_at`).
JavaScript 는 쓰지 않습니다 — 폼 하나와, PT 메시지 화면에서만 도는 meta refresh 로 끝냅니다.

아래 cron 을 걸어 두면 아침에 이미 만들어져 있어 앱을 여는 순간 알림이 떠 있습니다.

```bash
# 매일 새벽 5시
0 5 * * * cd /volume1/web/health && php bin/coach.php >> storage/logs/coach.log 2>&1
```

Synology DSM 이라면 **제어판 → 작업 스케줄러 → 생성 → 예약된 작업 → 사용자 정의 스크립트**
에서 위 명령을 매일 실행하도록 등록합니다(사용자는 `http`).

```bash
php bin/coach.php           # 이미 있으면 건너뛴다
php bin/coach.php --force   # 있어도 다시 만든다
php bin/coach.php --show    # 만들지 않고 지금 보이는 것만 찍는다
```

cron 도 FPM 도 없으면 규칙으로 만든 문장만 나옵니다 — 화면은 어떤 경우에도 열립니다.

### 4. 비밀번호 설정

```bash
php bin/set-password.php '원하는비밀번호'
```

출력된 해시를 `config.local.php`의 `password_hash`에 붙여넣습니다.
비밀번호가 비어 있으면 아무도 로그인할 수 없습니다.

### 5. 권한

웹서버 사용자(Apache는 보통 `www-data`, Synology NAS는 `http`)가 아래 두 곳에
**읽기·쓰기** 권한이 있어야 합니다.

```
storage/        SQLite 파일 · 세션 · 로그
public/media/   내려받은 이미지 · 동영상
```

Synology DSM이라면 File Station → 폴더 우클릭 → 속성 → 권한에서 `http` 사용자에게
읽기/쓰기를 주고 "이 폴더, 하위 폴더 및 파일에 적용"을 체크합니다.

SSH를 쓸 수 있다면:

```bash
sudo chown -R http:http storage public/media
```

권한이 없으면 앱은 죽지 않고 **읽기 전용**으로 열립니다. 열람은 되지만 기록·가져오기는
저장되지 않고, 화면 위에 그 사실이 표시됩니다.

### 6. 마이그레이션

```bash
php bin/migrate.php
```

`migrations/*.sql`을 파일명 순서대로 적용하고, 이미 적용한 것은 건너뜁니다.

---

## 가져오기

화면에서 `Import Notion` → Craft 공유 주소나 Notion 페이지 주소를 붙여넣습니다.

**글과 미디어는 분리해서 가져옵니다.** 첨부가 1.7GB라 한 요청에서 다 받으면 죽습니다.

1. **1단계(즉시)** — 블록 수집 · 파싱 · DB 저장. 10초쯤 걸립니다. 화면은 이 시점에
   이미 열립니다(영상 자리는 "받는 중"으로 표시)
2. **2단계(나눠서)** — `미디어 내려받기` 버튼. 한 요청에 3개씩 받고 화면이 저절로
   다음으로 넘어갑니다. 중간에 나가도 되고, 다시 오면 이미 받은 것은 건너뜁니다

CLI로도 됩니다.

```bash
php bin/import.php https://<space>.craft.me/<shareId>   # 1단계
php bin/fetch-media.php                                  # 2단계 (끝까지)
php bin/fetch-media.php --batch=5                        # 5개만
```

이미 다른 곳에 받아둔 파일이 있다면 다시 받지 않고 그대로 가져올 수 있습니다.
파일명이 `<attachmentId>.<확장자>`로 통일돼 있어 그대로 맞춰봅니다.

```bash
php bin/fetch-media.php --adopt=/path/to/media          # 복사
php bin/fetch-media.php --adopt=/path/to/media --move   # 옮기기
```

### 다시 가져오기

`notion_page_id`로 덮어씁니다(upsert). 회차의 운동 목록은 지우고 다시 넣되,
운동 마스터(`exercises`)는 이름으로 재사용하므로 "오늘의 운동" 기록이 끊기지 않습니다.

### 운동부위

운동 이름의 키워드로 나눕니다. **규칙은 DB가 아니라 `src/Parts.php`의 상수 표에
있습니다** — 가져오기를 다시 돌리면 재분류됩니다. 한 운동이 두 부위에 걸치면 양쪽에
들어갑니다.

직접 추가한 운동은 이름만으로 못 맞히는 경우가 있어서, 추가할 때 부위를 고를 수 있습니다.
고른 값이 있으면 그것을 우선합니다(`exercises.part_override`).

---

## 오늘의 운동 — 세트 기록

화면 위에 **시간 / 카운트** 탭이 있습니다. 유산소 운동은 시간 탭으로 열리고, 나머지는
카운트 탭으로 열립니다(`Parts` 분류로 정합니다).

**카운트** — 가운데 숫자가 "지금 몇 회 셌나"입니다. 양옆 `−` `+` 로 세고, `+1 세트`를
누르면 그 횟수와 무게로 아래에 한 줄이 쌓입니다. 세던 값은 그대로 남으므로 같은 횟수로
한 세트 더 하려면 `+1 세트`만 한 번 더 누르면 됩니다. 12회로 두 번 누르고 15로 고쳐
한 번 더 누르면 12 · 12 · 15 세 줄이 됩니다.

**시간** — 스톱워치입니다(1/100초). `이 시간으로 기록`을 누르면 걸린 시간이 한 줄로
쌓입니다.

세는 값은 `workout_current` 에, 쌓인 세트는 `workout_sets` 에 들어갑니다. 그래서
`workout_sets` 는 전부 "끝낸 세트"입니다. `오늘 끝내기`를 누르면 아직 안 쌓인 값은
기록이 아니므로 버립니다.

### 왜 화면이 안 넘어가는가

이 NAS 는 SQLite 쓰기 한 번에 0.5~2초가 걸립니다. 버튼을 누를 때마다 화면을 새로 열면
그 시간이 그대로 "안 눌리는 느낌"이 됩니다. 그래서 카운터는 이렇게 합니다.

| | |
|---|---|
| `−` `+` | 화면만 바꾸고, 손이 멈추면(450ms) "지금 몇 회"를 한 번만 보냅니다 |
| `+1 세트` · 스톱워치 기록 | 목록에 먼저 흐리게 한 줄 붙이고 보냅니다. 답이 오면 서버 것으로 갈아끼웁니다 |
| 실패하면 | 마지막으로 서버가 준 모습으로 되돌리고 까닭을 화면에 적습니다 |

`POST /today/{id}/rep` 은 답을 기다릴 필요가 없어서(화면이 제 숫자를 이미 압니다)
200 을 먼저 주고 쓰기는 `Http::afterResponse` 로 미룹니다. `+1 세트`는 횟수·무게를
스스로 들고 가므로 그게 늦어도 어긋나지 않습니다.

같은 주소들이 `Accept: application/json` 없이 오면 예전처럼 리다이렉트로 돌아갑니다 —
자바스크립트가 없어도 세트 기록은 됩니다.

---

## 백업

**SQLite 파일 하나와 미디어 폴더가 전부입니다.**

```bash
# 기록 (작습니다. 자주 하세요)
sqlite3 storage/health.sqlite ".backup '/backup/health-$(date +%Y%m%d).sqlite'"

# 미디어 (1.6GB. 바뀔 때만 하세요)
rsync -a public/media/ /backup/media/
```

**`cp` 로 `health.sqlite` 하나만 복사하면 안 됩니다.** DB 는 WAL 모드라(NAS 디스크에서
기본 모드는 쓰기 한 번에 2.5초가 걸립니다) 방금 쓴 내용이 아직 `health.sqlite-wal` 에
있을 수 있습니다. 위의 `.backup` 을 쓰거나, 굳이 복사한다면 세 파일을 함께 옮기세요.
같은 이유로 **SMB 로 마운트한 사본에서 `sqlite3` 로 DB 를 열지 마세요** — WAL 은
네트워크 파일시스템에서 안전하지 않습니다. NAS 안에서(SSH) 실행하면 됩니다.

되돌릴 때는 파일을 제자리에 다시 놓기만 하면 됩니다.

미디어를 잃어버려도 노션 원본이 남아 있다면 `php bin/fetch-media.php`로 다시 받을 수
있습니다. DB의 `media` 행에 원본 주소(`attachment:…`)가 그대로 저장돼 있습니다.

`storage/`와 `public/media/`는 git에 올리지 않습니다(`.gitignore`).

---

## 테스트

```bash
php tests/run.php
```

도메인 로직만 봅니다 — 부위 분류 · 제목 파싱 · 첨부 URL 조립 · 세트 집계 ·
미디어 자리표시자. 네트워크나 실제 DB를 건드리지 않습니다.

수집이 실제로 되는지 확인하려면(저장은 하지 않습니다):

```bash
php bin/probe.php https://<space>.craft.me/<shareId>
```

---

## 구조

```
public/           웹 루트
  index.php       유일한 진입점(프론트 컨트롤러)
  assets/         css · 폰트 · 파비콘 · inbody.js · counter.js
  media/          내려받은 이미지 · 동영상 (git 제외)
src/
  App.php         부트스트랩 · autoload · 설정 · 오류 로깅
  Db.php          PDO 래퍼 + 마이그레이션 (쓰기 불가면 읽기 전용으로 폴백)
  Router.php  Auth.php  Csrf.php  Http.php  View.php
  Parts.php       운동부위 분류 표
  Settings.php    D-DAY 등 화면에서 고치는 설정
  Coach.php       "오늘의 메시지" — 읽기는 todayMessage, 만들기는 refresh 뿐
  PtNote.php      그날 PT쌤이 남긴 말 (화면에서 직접 적는다)
  Llm/Deepseek.php  DeepSeek chat completions
  Media.php       body_html 의 [[MEDIA:…]] 자리표시자 → 실제 태그
  MediaFetcher.php  나눠 받기 · 재개 · 이미 받은 파일 가져오기
  Notion/         Client · Craft · Parser · Importer · Attachment · Ids · Title
  Repo/           Log(열람) · Workout(오늘의 운동 — 세는 값과 쌓인 세트)
  View/           순수 PHP 템플릿
storage/          health.sqlite · sessions · logs (웹 루트 밖, git 제외)
bin/              migrate · set-password · import · fetch-media · probe · coach
migrations/       *.sql
tests/            run.php + *_test.php
docs/             인계 문서 · 이 문서 · 결정 기록
```

---

## 보안

- 비밀번호 하나로 로그인. 해시는 `config.local.php`에 두고 커밋하지 않습니다
- 세션 쿠키 `HttpOnly` · `Secure` · `SameSite=Lax`
- 상태를 바꾸는 POST에는 CSRF 토큰 (`hash_equals` 비교, 어긋나면 419)
- SQL은 전부 prepared statement
- 출력은 전부 `htmlspecialchars`. Notion에서 만든 `body_html`만 예외이며, 만들 때 이미
  escape합니다
- 보안 헤더 — CSP · `X-Frame-Options: DENY` · `nosniff` · `Referrer-Policy: no-referrer`
- 미디어는 웹서버가 직접 서빙합니다. 파일명이 32자리 hex라 추측이 사실상 불가능하고,
  40MB 영상을 PHP로 흘리는 비용이 크기 때문입니다(인계 문서 §10). 인증까지 걸어야 한다면
  PHP를 경유하되 Range(206)를 반드시 구현해야 합니다

---

## 이 저장소에서 git이 안 될 때

NAS 공유 폴더(SMB)에 올려둔 사본에서는 `git add`가
`fatal: unable to write new index file`로 실패합니다. 오브젝트 쓰기와 잠금 생성은
되는데 인덱스 파일 교체만 막힙니다. 인덱스를 로컬 디스크에 두면 됩니다.

```bash
export GIT_INDEX_FILE=/tmp/health.index
git read-tree HEAD && git add -A && git commit && git push
```
