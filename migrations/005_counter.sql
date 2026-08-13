-- 세트 기록 방식을 바꾼다.
--
-- 예전: workout_sets 의 마지막 줄이 "진행 중인 세트"였고, `세트 완료`를 누르면 0회짜리
--       다음 줄이 열렸다. 몇 회를 하고 있는지와 이미 끝낸 세트가 같은 표에 섞여 있었다.
-- 지금: 세고 있는 값(횟수·무게)은 workout_current 에 따로 두고, `+1 세트`를 눌러야
--       workout_sets 에 한 줄이 쌓인다. 그래서 workout_sets 는 전부 "끝낸 세트"다.
--       12회를 세어 두고 +1세트를 두 번 누르면 12회짜리 세트가 둘 쌓인다.

-- 지금 세고 있는 값. 운동 하나당 한 줄.
CREATE TABLE workout_current (
  workout_id  INTEGER NOT NULL REFERENCES workouts(id) ON DELETE CASCADE,
  exercise_id INTEGER NOT NULL REFERENCES exercises(id),
  reps        INTEGER NOT NULL DEFAULT 0,
  weight      REAL,
  updated_at  TEXT NOT NULL,
  PRIMARY KEY (workout_id, exercise_id)
);

-- 유산소는 횟수가 아니라 시간으로 센다. 초 단위(소수점까지).
ALTER TABLE workout_sets ADD COLUMN secs REAL;

-- 예전 모델이 남긴 0회짜리 "진행 중" 줄은 끝낸 세트가 아니므로 치운다.
DELETE FROM workout_sets WHERE reps = 0 AND secs IS NULL;

-- 유산소 · 전신 운동을 목록에 넣는다. 이름만으로는 부위를 못 맞히는 것이 있어 부위를 박아 둔다.
INSERT INTO exercises (name, part_override, created_at) VALUES
  ('러닝',        '유산소', datetime('now')),
  ('천국의 계단', '유산소', datetime('now')),
  ('사이클',      '유산소', datetime('now')),
  ('준비운동',    '전신',   datetime('now')),
  ('정리운동',    '전신',   datetime('now'))
ON CONFLICT(name) DO UPDATE SET part_override = excluded.part_override;
