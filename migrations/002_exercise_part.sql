-- 직접 추가한 운동은 이름만으로 부위를 못 맞히는 경우가 있어서, 고를 수 있게 한다.
-- 규칙 표(Parts::TABLE)는 그대로 두고, 이 값이 있을 때만 그것을 우선한다.
-- 노션에서 가져온 운동은 이 값이 비어 있어 예전처럼 이름에서 계산된다.
ALTER TABLE exercises ADD COLUMN part_override TEXT;
