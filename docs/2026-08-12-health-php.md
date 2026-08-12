# 인계 — 운동 기록(health)을 PHP 8로 분리

작성 2026-08-12. 대상 저장소 `baegsongi/health` (private).

## 0. 이 문서에 대해

LinkBox에 붙여둔 `/bs2/health` 보관 문서를 **독립된 PHP 8 서비스**로 다시 만든다.
LinkBox는 link-in-bio 서비스이고 운동 기록은 그 안에 얹힌 정적 문서일 뿐이라, 기록을 **쌓는**
기능(오늘의 운동)이 들어오는 순간 성격이 달라진다. 그래서 떼어낸다.

**이 문서는 health 저장소의 `docs/`로 그대로 옮겨간다.** §12에 구현 프롬프트가 있다.

LinkBox 쪽 참고 구현(옮겨갈 때 읽을 필요는 없지만, 애매하면 여기 답이 있다):
`scripts/lib/notion-source.mjs`(수집) · `scripts/archive-craft-notion.mjs`(파싱 · 렌더링).

---

## 1. 만들 것

| | |
|---|---|
| 보관 | Notion 링크를 붙여넣으면 그 페이지를 통째로 가져와 화면을 만든다. 이미지 · 동영상 **파일까지 내려받아** 우리 서버에 둔다 |
| 열람 | 일자별 · 운동부위별 · 전체 세 가지로 본다 |
| 기록 | "오늘의 운동" — 등록된 운동을 골라 시작하면 횟수 · 세트를 기록한다 |
| UI | 휴대폰에서 **큰 버튼만 눌러** 되는 화면. 넷플릭스 결(어두운 배경 · 흰 글씨 · 빨강 강조) |

**안 만드는 것** — 다중 사용자, 회원가입, 소셜 공유, 통계 대시보드, 운동 추천.
쓰는 사람은 한 명이다.

---

## 2. 이미 확인된 사실 (다시 조사하지 말 것)

전부 2026-08-12에 실제 요청을 보내 확인했다. 그대로 쓰면 된다.

### 2.1 Craft 공유 문서

공개 Craft 페이지는 클라이언트 렌더링이라 HTML을 받아도 본문이 없다. **API가 따로 있다.**

```
GET https://<space>.craft.me/api/share/<shareId>
→ { blocks: [ { id, type, content, rawProperties, … } ], … }
```

- 첫 블록이 `type: "text"` — 문서 제목
- 나머지가 `type: "url"` — 각 링크
- ⚠ **`content`가 빈 url 블록이 실제로 있다.** 스마트 링크 동기화가 덜 된 상태로 보인다.
  그 경우 주소는 `rawProperties` 안에만 있다. 거기서 꺼낼 땐 미리보기 이미지 주소가 같이 잡히므로
  `/image/`가 든 것은 버리고 32자리 hex(페이지 id)가 있는 것만 고른다. 이걸 놓쳐서 13개 중
  1개(1-3 회차)가 통째로 빠졌었다.

### 2.2 Notion 비공식 API

공개 페이지는 인증 없이 읽힌다. `User-Agent`는 브라우저 것으로 넣어야 한다.

```
POST https://www.notion.so/api/v3/loadPageChunk
Content-Type: application/json
{ "pageId": "<uuid>", "limit": 300, "cursor": {"stack": []},
  "chunkNumber": 0, "verticalColumns": false }
→ { recordMap: { block: { "<id>": { value: { value: { …블록… } } } } } }
```

⚠ **`loadPageChunk`는 토글 안쪽 자식을 내려주지 않는다.** 못 받은 자식 id를 모아 반복 요청해야 한다.

```
POST https://www.notion.so/api/v3/syncRecordValues
{ "requests": [ { "pointer": {"table":"block","id":"<id>","spaceId":""}, "version": -1 } ] }
```

수집 절차 — 페이지마다 `loadPageChunk` → 모든 블록의 `content[]` 중 아직 없는 id를 모아
`syncRecordValues`(50개씩) → 더 없을 때까지 반복. 실측 3라운드에서 끝났다.

**페이지 id**: URL의 32자리 hex를 `8-4-4-4-12`로 하이픈을 넣어 UUID로 만든다.

### 2.3 첨부 파일 내려받기

블록의 `properties.source[0][0]` 값이 `attachment:<attachmentId>:<파일명>` 형식이다.

```
GET https://www.notion.so/signed/<urlencode(source 전체)>?table=block&id=<blockId>&spaceId=<block.space_id>
→ 302 후 실제 파일 (video/mp4, image/gif …)
```

- `<파일명>`에 `:`가 들어갈 수 있다. `attachment:` 다음 첫 `:`까지만 id로 자른다
- 같은 첨부가 여러 블록에 쓰인다 → `attachmentId`로 중복을 없앤다
- 저장 파일명은 `<attachmentId>.<확장자>`로 통일했다(원본 이름을 쓰지 않는다)

### 2.4 실측 규모

| | |
|---|---|
| Notion 페이지(회차) | 13 |
| 블록 | 493 |
| 고유 첨부 | 100 (동영상 81 · 이미지 20) |
| 용량 | **1.7GB** (동영상 1.59GB · 이미지 108MB) |
| 동영상 크기 | 최소 7MB · 중앙값 18MB · 최대 42MB |
| 토글(=운동 종목) | 69 |

**이 용량이 설계를 지배한다.** §7 · §11 참고.

### 2.5 데이터 형태

블록 타입 — `page` `text` `callout` `toggle` `numbered_list` `bulleted_list`
`column_list` `column` `image` `video` `divider`.

rich text는 `[[텍스트, [["b"], ["a", "https://…"], ["h", "red"]]], …]` 형식.
`b` 굵게 · `i` 기울임 · `s` 취소선 · `_` 밑줄 · `c` 코드 · `a` 링크 · `h` 색.

**한 회차의 구조**

```
page                        제목 = "(1-10)백송이님 26.07.23(목)20:00"
├ callout                   그날 수업 요약
├ text                      안내
├ toggle  "[1] 스쿼트+런지\n→ 8회 3세트"     ← 운동 한 종목
│  ├ numbered_list …        설명
│  ├ column_list > column   동영상 · 이미지
│  └ …
├ toggle  "[2] …"
└ text                      "📋 오늘 잘한 점" / "📋 개선하면 좋을 점"
```

- **토글 하나 = 운동 한 종목.** 제목 첫 줄이 이름(`[n]` 접두사 제거), 나머지 줄이 세트 메모
- 첫 토글 **앞**의 블록은 그날 안내, **뒤**의 블록은 총평
- 회차 제목 형식 두 가지 — `(1-10)백송이님 26.07.23(목)20:00`,
  `(OT)백송이 회원님/26.06.10(수) [21:30]`. `(\d{2})\.(\d{2})\.(\d{2})\s*\(?(.)?\)?` 와
  `(\d{1,2}:\d{2})` 로 둘 다 잡힌다
- 외부 영상(유튜브)이 1건 있다. 파일을 받을 수 없으므로 링크로만 남긴다

### 2.6 운동부위 분류

운동 이름의 키워드로 나눈다. **한 운동이 두 부위에 걸치면 양쪽에 넣는다**
(`레그 프레스 + 버피` → 하체 · 전신). 어느 규칙에도 안 걸리면 **묶지 말고 이름 그대로 하나의
항목**으로 목록 맨 위에 둔다(목표 · 식단 계획 · 운동 계획이 여기 해당).

| 부위 | 키워드 |
|---|---|
| 하체 | 스쿼트, 런지, 레그 프레스, 레그 익스텐션, 레그 컬, 글루터, 힙 어브덕션, 힙 어덕션, 힙 쓰러스트, 스텝박스, 데드리프트, 케틀벨 |
| 등 | 렛 풀 다운, 바벨 로우, 밴트오버, 하이 로우, 풀업, 친업 |
| 가슴 | 벤치 프레스, 체스트 프레스, 푸시업 |
| 어깨 | 숄더 프레스, 스내치, 클린, 저크, 레터럴 |
| 팔 | 트라이셉스, 킥 백, 이두, 삼두 |
| 코어 | 크런치, 레그 레이즈, 시저스, 싯 업, 러시안 트위스트, 플랭크, 마운틴 클라이머, 업도미널, 복근, 코어 |
| 전신 | 버피, 인터벌, 웨이브, 스내치 |

표시 순서 — (미분류 항목들) · 하체 · 등 · 가슴 · 어깨 · 팔 · 코어 · 전신.

실측 분포 — 하체 33 · 코어 13 · 등 6 · 가슴 6 · 어깨 6 · 전신 6 · 팔 4.

**이 규칙은 DB가 아니라 코드의 상수 표에 둔다.** 가져오기를 다시 돌리면 재분류되어야 한다.

---

## 3. 기술 선택

| | 선택 | 이유 |
|---|---|---|
| 언어 | **PHP 8.3** (최소 8.1) | 요청 사항. enum · readonly · `match`를 쓴다 |
| 프레임워크 | **없음** | 화면 예닐곱 개짜리다. `public/index.php` 하나가 라우터 |
| DB | **SQLite (PDO)** | 한 명이 쓴다. 파일 하나라 백업이 복사 한 번 |
| 의존성 | **Composer 없이** | `curl` · `pdo_sqlite` · `mbstring` · `json` 확장만 |
| 배포 | 파일 복사 | CI/CD를 두지 않기로 했다(2026-08-12) |
| 시간대 | `Asia/Seoul` 고정 | |

필요한 PHP 확장 — `pdo_sqlite` · `curl` · `mbstring` · `json`.

### 디렉터리

```
public/                 웹 루트
  index.php             유일한 진입점(프론트 컨트롤러)
  assets/               css · 폰트
  media/                내려받은 이미지 · 동영상 ← 웹서버가 직접 서빙(§10)
src/
  Db.php                PDO 래퍼 + 마이그레이션
  Router.php
  Auth.php
  Notion/Client.php     loadPageChunk · syncRecordValues · 첨부 다운로드
  Notion/Parser.php     블록 트리 → 회차 · 운동 · HTML
  Notion/Importer.php   파싱 결과를 DB에 반영
  Parts.php             §2.6 분류 표
  Repo/…                조회 · 저장
  View/…                템플릿 (순수 PHP)
storage/
  health.sqlite
  logs/
bin/
  migrate.php
  fetch-media.php       미디어 내려받기 (CLI)
docs/
```

`storage/`는 **웹 루트 밖**에 둔다. `public/`만 문서 루트로 지정한다.

---

## 4. 데이터 모델 (SQLite)

```sql
-- 가져온 원본 ------------------------------------------------
CREATE TABLE sources (
  id           INTEGER PRIMARY KEY,
  kind         TEXT NOT NULL,              -- 'craft' | 'notion'
  url          TEXT NOT NULL UNIQUE,
  title        TEXT,
  imported_at  TEXT NOT NULL
);

CREATE TABLE sessions (                    -- 운동 한 회차
  id             INTEGER PRIMARY KEY,
  source_id      INTEGER REFERENCES sources(id) ON DELETE SET NULL,
  notion_page_id TEXT NOT NULL UNIQUE,     -- 다시 가져오면 갱신(upsert)
  code           TEXT,                     -- 'OT' · '1-10'
  title          TEXT NOT NULL,            -- 원본 제목 그대로
  date           TEXT,                     -- '26.07.23'
  weekday        TEXT,                     -- '목'
  time           TEXT,                     -- '20:00'
  position       INTEGER NOT NULL DEFAULT 0,
  intro_html     TEXT,
  notes_html     TEXT,
  updated_at     TEXT NOT NULL
);

CREATE TABLE exercises (                   -- 운동 마스터. "오늘의 운동"이 고르는 목록
  id         INTEGER PRIMARY KEY,
  name       TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL
);

CREATE TABLE session_exercises (           -- 그 회차에서 한 운동
  id           INTEGER PRIMARY KEY,
  session_id   INTEGER NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
  exercise_id  INTEGER NOT NULL REFERENCES exercises(id),
  position     INTEGER NOT NULL,
  meta         TEXT,                       -- '→ 8회 3세트'
  body_html    TEXT NOT NULL               -- 토글 안쪽을 렌더한 HTML
);
CREATE INDEX ON session_exercises(session_id, position);

CREATE TABLE media (
  id                  INTEGER PRIMARY KEY,
  attachment_id       TEXT NOT NULL UNIQUE,
  session_exercise_id INTEGER REFERENCES session_exercises(id) ON DELETE CASCADE,
  kind                TEXT NOT NULL,       -- 'video' | 'image'
  file                TEXT NOT NULL,       -- '<attachmentId>.mp4'
  source              TEXT NOT NULL,       -- 'attachment:…' 원문 (재다운로드용)
  block_id            TEXT NOT NULL,
  space_id            TEXT,
  bytes               INTEGER,
  status              TEXT NOT NULL DEFAULT 'pending'   -- pending | done | failed
);

-- 오늘의 운동 -------------------------------------------------
CREATE TABLE workouts (
  id         INTEGER PRIMARY KEY,
  started_at TEXT NOT NULL,
  ended_at   TEXT,
  memo       TEXT
);

CREATE TABLE workout_sets (
  id          INTEGER PRIMARY KEY,
  workout_id  INTEGER NOT NULL REFERENCES workouts(id) ON DELETE CASCADE,
  exercise_id INTEGER NOT NULL REFERENCES exercises(id),
  set_no      INTEGER NOT NULL,
  reps        INTEGER NOT NULL DEFAULT 0,
  weight      REAL,                        -- 선택. 안 쓰면 NULL
  done_at     TEXT NOT NULL
);
CREATE INDEX ON workout_sets(workout_id, exercise_id, set_no);
```

**`exercise_parts` 테이블을 두지 않는다.** 부위는 이름에서 계산되는 값이라 DB에 굳히면
규칙을 고쳤을 때 어긋난다. 조회 시점에 `Parts::of($name)`으로 구한다.

---

## 5. 화면

전부 모바일 1칼럼. 버튼은 최소 높이 **56px**, 주요 동작은 **64px 이상**.

| 경로 | 화면 |
|---|---|
| `/` | 홈 — 큰 버튼 3개: **오늘의 운동 시작** · 기록 보기 · 노션 가져오기 |
| `/log` | 보기 방식 3개: 일자별 · 운동부위별 · 전체 |
| `/log/dates` | 회차 카드 목록 |
| `/log/session/{id}` | 그 회차 상세(운동 카드 + 동영상) |
| `/log/parts` | 부위 목록 (미분류 항목이 맨 위) |
| `/log/part/{name}` | 그 부위 운동 모음. 각 항목에 회차 · 날짜 표시 |
| `/log/all` | 전체보기 (섹션 나눠 스크롤) |
| `/import` | Notion · Craft URL 붙여넣기 |
| `/import/media` | 미디어 내려받기 진행 화면 |
| `/today` | 운동 고르기 (부위 탭 + 큰 버튼 그리드) |
| `/today/{exerciseId}` | 카운터 화면 |
| `/today/finish` | 오늘 요약 |
| `/workouts` | 지난 기록 목록 |
| `/media/{file}` | (웹서버 직접 서빙 — §10) |

---

## 6. 노션 가져오기

```
[URL 붙여넣기]
      │
      ├ craft.me 주소면 → share API → 그 안의 Notion 링크 전부
      └ notion.site / notion.com 주소면 → 그 페이지 하나
      ↓
loadPageChunk + syncRecordValues 로 블록 전부 수집
      ↓
파싱 → sessions · exercises · session_exercises · media(status=pending) 저장
      ↓
화면은 여기서 이미 열린다 (텍스트는 다 있다)
      ↓
[미디어 내려받기] 버튼 → 몇 개씩 나눠서 받으며 진행률 표시
```

**⚠ 가장 중요한 설계 결정 — 텍스트와 미디어를 분리한다.**

첨부가 1.7GB다. 한 요청에서 다 받으면 `max_execution_time`에 걸려 죽는다. 그래서

1. **1단계(즉시)** — 블록 수집 · 파싱 · DB 저장. 미디어는 `status='pending'` 행만 만든다.
   여기까지는 몇 초면 끝난다. 화면은 이 시점에 이미 볼 수 있다(영상 자리는 "받는 중" 표시).
2. **2단계(나눠서)** — `/import/media`가 한 요청에 **3~5개씩** 받고
   `<meta http-equiv="refresh">`로 자기 자신을 다시 부른다. 남은 개수 · 용량을 보여준다.
   또는 CLI로 한 번에: `php bin/fetch-media.php`

다운로드는 반드시 **스트리밍**으로 한다. 40MB를 메모리에 올리지 않는다.

```php
$out = fopen($path . '.part', 'wb');
curl_setopt_array($ch, [
    CURLOPT_FILE => $out,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => $ua,
    CURLOPT_TIMEOUT => 300,
]);
curl_exec($ch);
fclose($out);
rename($path . '.part', $path);   // 다 받은 것만 최종 이름으로
```

`.part`로 받고 다 받으면 이름을 바꾸는 이유 — 중간에 끊겨도 다음 실행이 "이미 있음"으로
착각하지 않는다. **다시 돌리면 이미 받은 건 건너뛴다.**

**다시 가져오기** — `notion_page_id`로 upsert한다. 회차의 운동 목록은 지우고 다시 넣되,
`exercises`(마스터)는 이름으로 재사용한다. 그래야 "오늘의 운동" 기록이 끊기지 않는다.

---

## 7. 오늘의 운동 기록

**운동 고르기** (`/today`)

- 부위 탭(전체 · 하체 · 등 …) → 그 부위 운동이 **큰 버튼 그리드**(2열)로
- 버튼에는 이름과 "마지막: 26.07.23 · 15kg 12회" 같은 직전 기록 한 줄
- 맨 위에 "직접 추가" — 목록에 없는 운동을 즉석에서 만든다

**카운터** (`/today/{exerciseId}`)

화면에 이것만 있으면 된다.

```
        스쿼트
    ─────────────────
      3세트 · 24회
    ─────────────────
   [        +1 회        ]   ← 화면 폭 전체, 높이 96px
   [  -1  ]   [ 세트 완료 ]
   ─────────────────
   1세트  12회
   2세트  12회
   3세트   0회 (진행 중)
   ─────────────────
   [ 다른 운동 ]  [ 오늘 끝내기 ]
```

- **`+1 회`가 가장 크고 가장 아래쪽**에 온다. 엄지가 닿는 자리다
- `세트 완료`를 누르면 현재 세트를 확정하고 다음 세트를 0회로 시작
- 무게는 선택이다. 입력칸을 기본으로 보여주지 않고 "무게 기록" 토글로 연다
- 실수 대비 `-1`만 두고 그 이상은 되돌리지 않는다. 세트 줄을 눌러 수정한다

**동작 원리** — 모든 버튼이 **POST → 리다이렉트 → GET**이다. JavaScript 없이 동작해야 한다.
빠른 반응이 필요하면 나중에 최소한의 JS를 얹되, JS가 없어도 기록은 되게 둔다.

`workouts` 행은 그날 첫 기록에서 만들고, `오늘 끝내기`로 `ended_at`을 채운다.
끝내지 않고 하루가 지나면 다음 기록에서 새 `workouts`를 연다.

---

## 8. 디자인

LinkBox `/bs2/health`에서 쓰던 결을 그대로 가져간다.

```css
--bg:#141414;  --panel:#1b1b1d;  --panel2:#232326;  --line:#2f2f35;
--ink:#fff;    --muted:#9d9da4;  --red:#e50914;
```

- 본문 **Pretendard**, 로고의 라틴 글자 **Bebas Neue**
- 로고는 **가운데 정렬** — `송이의`(Pretendard 800, 20px) + `GYMFLIX`(Bebas Neue, 빨강, 38px).
  560px 이상에서 23px / 44px
- ⚠ 넷플릭스 워드마크의 **Netflix Sans는 독점 폰트라 쓸 수 없다.** Bebas Neue(SIL OFL)가
  형태가 가장 가깝다. 라틴 서브셋만 8.6KB. 한글은 Bebas에 글리프가 없어 Pretendard가 맡는다
- 폰트는 **자체 호스팅**한다(`public/assets/fonts/`). CDN을 쓰지 않는다
- 본문 14px · 좌우 여백 12px · 카드 패딩 12px. 카드 그리드는 2열, 560px↑에서 3열
- 동영상은 `<video controls preload="none" playsinline>`. 한 화면에 수십 개가 있어서
  **누를 때만 받게 한다**

---

## 9. 보안

private 저장소지만 **웹에 올라가면 주소를 아는 누구나 본다.** 개인 PT 기록 · 본인 영상이므로
반드시 막는다.

- **비밀번호 하나로 로그인**(단일 사용자). `password_hash()` / `password_verify()`,
  해시는 `.env`나 `config.local.php`에 두고 **저장소에 커밋하지 않는다**
- 세션 쿠키 `HttpOnly` · `Secure` · `SameSite=Lax`
- 상태 변경(POST)에 **CSRF 토큰** — 세션에 넣고 폼 hidden으로 되돌려 받아 `hash_equals()` 비교
- 출력은 전부 `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`.
  단 **Notion에서 만든 `body_html`은 예외**다 — 우리가 만든 HTML이므로 그대로 출력하되,
  만들 때 텍스트를 escape했는지 확인한다
- SQL은 전부 **prepared statement**
- `storage/`는 웹 루트 밖. `public/` 만 문서 루트

---

## 10. 미디어 서빙 — 트레이드오프

동영상 81개, 최대 42MB다. 두 가지 방법이 있고 **장단이 분명하다.**

| | 웹서버 직접 (`public/media/`) | PHP 경유 (`/media/{file}`) |
|---|---|---|
| 속도 | 빠르다. Range · 캐시를 웹서버가 처리 | 느리다. Range를 직접 구현해야 한다 |
| 인증 | **안 걸린다.** 주소를 알면 누구나 | 로그인 뒤로 숨길 수 있다 |

**권장 — 웹서버 직접 서빙.** 파일명이 `<attachmentId>.mp4`(32자리 hex)라 추측이 사실상
불가능하고, 40MB 영상을 PHP로 흘리는 비용이 크다.

인증까지 걸어야 한다면 PHP 경유로 하되 **Range(206)를 반드시 구현한다.** 안 하면 브라우저가
영상 중간으로 이동하지 못하고 처음부터 끝까지 받아야 한다.

```php
// Range: bytes=0-1023  →  206 Partial Content
header('Accept-Ranges: bytes');
header("Content-Range: bytes $start-$end/$size");
header('Content-Length: ' . ($end - $start + 1));
http_response_code(206);
```

---

## 11. 함정 (LinkBox에서 실제로 겪은 것)

- **Craft url 블록의 `content`가 비어 있을 수 있다.** §2.1. 13개 중 1개가 통째로 빠졌었다
- **`loadPageChunk`는 토글 안쪽을 안 준다.** §2.2. 이걸 놓치면 운동 상세가 전부 빈다
- **첨부 파일명에 `:`가 들어간다.** `explode(':', $s, 3)` 로 세 조각까지만 자른다
- **같은 첨부가 여러 블록에 쓰인다.** `attachmentId`로 중복 제거하지 않으면 같은 파일을 두 번 받는다
- **1.7GB를 한 요청에서 받으려 하지 말 것.** §6
- **`grep -c`는 줄 수를 센다.** 한 줄에 여러 개면 하나로 센다. 개수 검증은 `grep -o … | wc -l`
- **비공식 API는 예고 없이 바뀔 수 있다.** 가져오기가 깨져도 이미 받아둔 파일과 DB는 남도록,
  수집과 표시를 분리해 둔다

---

## 12. 구현 프롬프트 (복붙용)

```
# 작업: 운동 기록 서비스(health)를 PHP 8로 신규 구현

## 0. 먼저 읽을 것
docs/2026-08-12-health-php.md 를 처음부터 끝까지 읽는다. 거기에 이미 확인된 API 형식 ·
데이터 구조 · 실측 규모 · 함정이 전부 정리되어 있다. **§2의 내용은 다시 조사하지 말고 그대로 쓴다.**
그 문서와 이 프롬프트가 어긋나면 문서를 우선하고, 어긋난 지점을 나에게 알린다.

## 1. 만들 것
Notion에 있는 개인 PT 기록을 우리 서버로 가져와 보관하고, 앞으로의 운동을 직접 기록하는
1인용 웹 서비스. PHP 8.3 · SQLite · 프레임워크 없음 · Composer 없음.

핵심 세 가지:
(1) Notion/Craft URL을 붙여넣으면 페이지를 통째로 가져와 화면을 만든다.
    이미지 · 동영상 파일까지 내려받아 우리 서버에 둔다.
(2) 일자별 · 운동부위별 · 전체 세 가지 보기.
(3) "오늘의 운동" — 등록된 운동을 골라 시작하면 횟수 · 세트를 기록한다.

## 2. 제약
- PHP 8.3 (최소 8.1). 확장은 pdo_sqlite · curl · mbstring · json 만 쓴다.
- 프레임워크 · Composer 의존성 없음. public/index.php 하나가 프론트 컨트롤러.
- SQLite 파일 하나. storage/ 는 웹 루트 밖에 둔다.
- 시간대 Asia/Seoul, 인코딩 UTF-8 고정.
- **JavaScript 없이 모든 기능이 동작해야 한다.** 모든 동작은 POST → 리다이렉트 → GET.
  JS는 나중에 얹는 개선일 뿐, 없어도 기록이 되어야 한다.
- 휴대폰에서 큰 버튼만 눌러 쓴다. 주요 버튼 높이 64px 이상.
- private 저장소지만 웹은 공개된다 → 비밀번호 로그인 · CSRF · prepared statement 필수.

## 3. 데이터 모델 · 화면 · 가져오기 흐름 · 기록 화면 · 디자인
문서 §4 · §5 · §6 · §7 · §8 을 그대로 따른다. 임의로 바꾸지 말고, 바꾸는 게 낫다고
판단되면 먼저 이유를 말하고 확인받는다.

## 4. 특히 조심할 것
- 첨부가 1.7GB다. **텍스트 저장과 미디어 내려받기를 반드시 분리한다**(문서 §6).
  한 요청에서 다 받으려 하면 죽는다. 스트리밍으로 받고, `.part`로 받은 뒤 rename한다.
- `loadPageChunk`는 토글 안쪽을 주지 않는다. `syncRecordValues`로 반복 수집한다.
- Craft url 블록의 content가 비어 있을 수 있다. rawProperties 폴백을 반드시 넣는다.
- 운동부위는 DB에 굳히지 말고 이름에서 계산한다(문서 §2.6).

## 5. 진행 방식
아래 순서로 하고, **각 단계가 끝날 때마다 결과를 보고하고 다음으로 갈지 확인**한다.
각 단계는 "무엇을 했는가"가 아니라 "무엇으로 확인했는가"를 함께 보고한다.

M0 뼈대 — 디렉터리 · index.php 라우터 · Db.php · 마이그레이션 · 로그인 · 기본 레이아웃/CSS
     verify: 로그인 없이는 아무 화면도 안 열리고, 로그인하면 빈 홈이 뜬다
M1 Notion 수집 — Notion/Client.php (loadPageChunk · syncRecordValues · 첨부 URL)
     verify: 실제 공개 페이지 하나로 블록 트리를 끝까지 받아 개수를 출력한다
M2 파싱 · 저장 — Parser · Importer · Parts. /import 화면
     verify: Craft URL 하나로 13회차 · 운동 69개가 DB에 들어간다. 미디어는 pending
M3 미디어 — /import/media 진행 화면 + bin/fetch-media.php
     verify: 중간에 끊고 다시 돌려도 이미 받은 건 건너뛴다. 100개 · 1.7GB 완료
M4 열람 — /log 의 일자별 · 부위별 · 전체
     verify: 회차 13개에 영상 81 · 이미지 20이 빠짐없이 나온다(개수를 세어 확인)
M5 오늘의 운동 — /today 고르기 · 카운터 · 끝내기 · /workouts
     verify: JS를 끈 상태에서 3세트 기록이 끝까지 된다
M6 마감 — 디자인 · 보안 헤더 · README · 백업 절차

## 6. 산출물
- 동작하는 전체 소스
- README.md: 요구 환경 · 설치 · 웹서버 설정(문서 루트 public/) · 백업 · 초기 비밀번호 설정
- docs/: 이 인계 문서 + 결정 기록
- 도메인 로직(부위 분류 · 제목 파싱 · 첨부 URL 조립 · 세트 집계)에 테스트

지금 M0부터 시작한다.
```
