-- 인계 문서 §4 의 데이터 모델.
-- 문서의 `CREATE INDEX ON t(...)` 는 인덱스 이름이 빠져 SQLite 가 거부하므로 이름을 붙였다.

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
CREATE INDEX idx_sessions_position ON sessions(position);

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
CREATE INDEX idx_sx_session ON session_exercises(session_id, position);
CREATE INDEX idx_sx_exercise ON session_exercises(exercise_id);

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
CREATE INDEX idx_media_status ON media(status);
CREATE INDEX idx_media_sx ON media(session_exercise_id);

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
CREATE INDEX idx_sets_workout ON workout_sets(workout_id, exercise_id, set_no);
