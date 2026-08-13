-- 그날 PT 선생님이 남긴 말. 화면에서 직접 적어 넣는다.
-- 하루 한 줄만 둔다 — 같은 날 다시 적으면 덮어쓴다.
CREATE TABLE pt_notes (
  day        TEXT PRIMARY KEY,       -- '2026-08-12'
  note       TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
