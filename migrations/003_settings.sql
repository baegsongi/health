-- 화면에서 고칠 수 있는 설정. 지금은 D-DAY 하나뿐이지만 늘어날 수 있다.
CREATE TABLE settings (
  key        TEXT PRIMARY KEY,
  value      TEXT,
  updated_at TEXT NOT NULL
);

-- "오늘의 메시지" 캐시. 같은 날 같은 상황이면 다시 부르지 않는다.
CREATE TABLE ai_messages (
  id         INTEGER PRIMARY KEY,
  day        TEXT NOT NULL,          -- '2026-08-12'
  fingerprint TEXT NOT NULL,         -- 그날의 기록 상태 해시
  message    TEXT NOT NULL,
  model      TEXT,
  created_at TEXT NOT NULL
);
CREATE UNIQUE INDEX idx_ai_day ON ai_messages(day, fingerprint);
