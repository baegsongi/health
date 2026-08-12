<?php
declare(strict_types=1);

namespace Health;

/**
 * 운동부위 분류. 인계 문서 §2.6.
 *
 * 규칙은 DB 가 아니라 여기 상수 표에 둔다 — 가져오기를 다시 돌리면 재분류되어야 하고,
 * 규칙을 고쳤을 때 이미 저장된 값과 어긋나면 안 된다.
 */
final class Parts
{
    /** 분류에 걸리지 않은 항목을 가리키는 이름. */
    public const NONE = '기타';

    /**
     * 표시 순서대로. 한 운동이 두 부위에 걸치면 양쪽에 넣는다.
     * @var array<string,array<int,string>>
     */
    public const TABLE = [
        '하체' => ['스쿼트', '런지', '레그 프레스', '레그프레스', '레그 익스텐션', '레그 컬',
                   '글루터', '힙 어브덕션', '힙 어덕션', '힙 쓰러스트', '스텝박스', '스텝 박스',
                   '데드리프트', '데드 리프트', '케틀벨'],
        '등'   => ['렛 풀 다운', '렛풀다운', '바벨 로우', '밴트오버', '밴트 오버', '하이 로우',
                   '풀업', '풀 업', '친업', '친 업'],
        '가슴' => ['벤치 프레스', '벤치프레스', '체스트 프레스', '체스트프레스', '푸시업', '푸쉬업', '푸시 업'],
        '어깨' => ['숄더 프레스', '숄더프레스', '스내치', '클린', '저크', '레터럴'],
        '팔'   => ['트라이셉스', '킥 백', '킥백', '이두', '삼두'],
        '코어' => ['크런치', '레그 레이즈', '시저스', '싯 업', '싯업', '러시안 트위스트',
                   '플랭크', '마운틴 클라이머', '업도미널', '복근', '코어'],
        '전신' => ['버피', '인터벌', '웨이브', '스내치'],
    ];

    /**
     * 운동 이름에서 부위를 구한다. 어느 규칙에도 안 걸리면 빈 배열.
     * @return array<int,string> 표시 순서대로
     */
    public static function of(string $name): array
    {
        $flat  = self::normalize($name);
        $parts = [];
        foreach (self::TABLE as $part => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($flat, self::normalize($kw))) {
                    $parts[] = $part;
                    break;
                }
            }
        }
        return $parts;
    }

    /** 공백 차이를 무시하려고 공백을 다 지우고 비교한다. */
    private static function normalize(string $s): string
    {
        return (string) preg_replace('/\s+/u', '', $s);
    }

    /**
     * 직접 고른 부위가 있으면 그것을 쓰고, 없으면 이름에서 계산한다.
     * @return array<int,string>
     */
    public static function ofExercise(string $name, ?string $override = null): array
    {
        $override = $override !== null ? trim($override) : '';
        if ($override !== '' && self::isKnown($override)) {
            return [$override];
        }
        return self::of($name);
    }

    /** 어느 규칙에도 안 걸리는 이름인가. 목표 · 식단 계획 같은 것들이 여기 해당한다. */
    public static function isUnclassified(string $name): bool
    {
        return self::of($name) === [];
    }

    /** 부위 목록(표시 순서). */
    public static function names(): array
    {
        return array_keys(self::TABLE);
    }

    public static function isKnown(string $part): bool
    {
        return isset(self::TABLE[$part]);
    }

    /**
     * 운동 이름 목록을 부위별로 묶는다.
     * 미분류 항목은 묶지 말고 이름 그대로 하나의 항목으로 목록 맨 위에 둔다.
     *
     * @param  array<int,string> $names
     * @return array<int,array{part:string,unclassified:bool,names:array<int,string>}>
     */
    public static function group(array $names): array
    {
        $buckets = [];
        $loose   = [];

        foreach ($names as $name) {
            $parts = self::of($name);
            if ($parts === []) {
                $loose[] = $name;
                continue;
            }
            foreach ($parts as $part) {
                $buckets[$part][] = $name;
            }
        }

        $out = [];
        foreach ($loose as $name) {
            $out[] = ['part' => $name, 'unclassified' => true, 'names' => [$name]];
        }
        foreach (self::names() as $part) {
            if (!empty($buckets[$part])) {
                $out[] = ['part' => $part, 'unclassified' => false, 'names' => $buckets[$part]];
            }
        }
        return $out;
    }
}
