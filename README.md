# 송이의 GYMFLIX

Notion에 있는 개인 PT 기록을 우리 서버로 가져와 보관하고, 앞으로의 운동을 직접 기록하는
1인용 웹 서비스입니다.

- **보관** — Craft/Notion 링크를 붙여넣으면 페이지를 통째로 가져오고, 이미지·동영상 파일까지
  내려받아 우리 서버에 둡니다
- **열람** — 일자별 · 운동부위별 · 전체 세 가지 보기
- **기록** — "오늘의 운동"에서 운동을 골라 시작하면 횟수·세트를 기록합니다

설계 배경과 이미 확인된 API 형식·함정은 [`docs/2026-08-12-health-php.md`](docs/2026-08-12-health-php.md)에
정리돼 있습니다.

---

## 요구 환경

| | |
|---|---|
| PHP | **8.2 이상** (8.3 권장) |
| 확장 | `pdo_sqlite` · `curl` · `mbstring` · `json` |
| 웹서버 | Apache 2.4 + `mod_rewrite` (`AllowOverride All`) |
| 그 외 | **Composer 없음 · 프레임워크 없음 · JavaScript 없이 모든 기능 동작** |

JavaScript는 인바디 앱 열기 한 곳에만 쓰이는 덤입니다. 꺼도 기록·열람은 전부 됩니다.

---

## 설치

### 1. 파일 두기

저장소를 웹서버가 보는 디렉터리에 둡니다.

**문서 루트를 `public/`으로 지정할 수 있다면** 그렇게 하는 것이 가장 좋습니다.
지정할 수 없다면(저장소 루트가 그대로 문서 루트가 되는 경우) 루트의 `.htaccess`가

- 모든 요청을 `public/index.php`로 돌리고
- `storage` · `src` · `bin` · `migrations` · `tests` · 설정 파일을 403으로 막습니다

`.htaccess`가 무시되는 환경(nginx 등)에서는 **`storage/health.sqlite`가 그대로 다운로드됩니다.**
반드시 Apache + `AllowOverride All`인지 확인하세요.

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

### 3. 비밀번호 설정

```bash
php bin/set-password.php '원하는비밀번호'
```

출력된 해시를 `config.local.php`의 `password_hash`에 붙여넣습니다.
비밀번호가 비어 있으면 아무도 로그인할 수 없습니다.

### 4. 권한

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

### 5. 마이그레이션

```bash
php bin/migrate.php
```

`migrations/*.sql`을 파일명 순서대로 적용하고, 이미 적용한 것은 건너뜁니다.

---

## 쓰는 법

### 노션 가져오기

화면에서 `Import Notion` → Craft 공유 주소나 Notion 페이지 주소를 붙여넣습니다.

**글과 미디어는 분리해서 가져옵니다.** 첨부가 1.7GB나 되기 때문에 한 요청에서 다 받으면
죽습니다.

1. **1단계(즉시)** — 블록 수집 · 파싱 · DB 저장. 10초쯤 걸립니다. 화면은 이 시점에 이미
   열립니다(영상 자리는 "받는 중"으로 표시)
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

운동 이름의 키워드로 나눕니다. **규칙은 DB가 아니라 `src/Parts.php`의 상수 표에 있습니다** —
가져오기를 다시 돌리면 재분류됩니다. 한 운동이 두 부위에 걸치면 양쪽에 들어갑니다.

직접 추가한 운동은 이름만으로 못 맞히는 경우가 있어서, 추가할 때 부위를 고를 수 있습니다.
고른 값이 있으면 그것을 우선합니다(`exercises.part_override`).

---

## 백업

**SQLite 파일 하나와 미디어 폴더가 전부입니다.**

```bash
# 기록 (작습니다. 자주 하세요)
sqlite3 storage/health.sqlite ".backup '/backup/health-$(date +%Y%m%d).sqlite'"

# sqlite3 명령이 없다면 — 쓰는 중이 아닐 때 복사하면 됩니다
cp storage/health.sqlite /backup/health-$(date +%Y%m%d).sqlite

# 미디어 (1.6GB. 바뀔 때만 하세요)
rsync -a public/media/ /backup/media/
```

되돌릴 때는 파일을 제자리에 다시 놓기만 하면 됩니다.

미디어를 잃어버려도 노션 원본이 남아 있다면 `php bin/fetch-media.php`로 다시 받을 수 있습니다.
DB의 `media` 행에 원본 주소(`attachment:…`)가 그대로 저장돼 있습니다.

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
  assets/         css · 폰트 · 파비콘
  media/          내려받은 이미지 · 동영상 (git 제외)
src/
  App.php         부트스트랩 · autoload · 설정 · 오류 로깅
  Db.php          PDO 래퍼 + 마이그레이션 (쓰기 불가면 읽기 전용으로 폴백)
  Router.php  Auth.php  Csrf.php  Http.php  View.php
  Parts.php       운동부위 분류 표
  Media.php       body_html 의 [[MEDIA:…]] 자리표시자 → 실제 태그
  MediaFetcher.php  나눠 받기 · 재개 · 이미 받은 파일 가져오기
  Notion/         Client · Craft · Parser · Importer · Attachment · Ids · Title
  Repo/           Log(열람) · Workout(오늘의 운동)
  View/           순수 PHP 템플릿
storage/          health.sqlite · sessions · logs (웹 루트 밖, git 제외)
bin/              migrate · set-password · import · fetch-media · probe
migrations/       *.sql
tests/            run.php + *_test.php
docs/             인계 문서
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
  40MB 영상을 PHP로 흘리는 비용이 크기 때문입니다(문서 §10). 인증까지 걸어야 한다면
  PHP를 경유하되 Range(206)를 반드시 구현해야 합니다
