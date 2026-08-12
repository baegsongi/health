<?php
declare(strict_types=1);

namespace Health;

/**
 * body_html 안의 `[[MEDIA:<attachmentId>]]` 자리표시자를 실제 태그로 바꾼다.
 *
 * 파일을 아직 안 받았으면 "받는 중" 자리를 그린다. 텍스트 저장과 미디어 내려받기를
 * 분리했기 때문에(인계 문서 §6) 화면은 파일이 없어도 먼저 열려야 한다.
 */
final class Media
{
    /**
     * @param array<string,array{kind:string,file:string,status:string}> $byAttachment
     */
    public static function expand(string $html, array $byAttachment): string
    {
        if (!str_contains($html, '[[MEDIA:')) {
            return $html;
        }
        return (string) preg_replace_callback(
            // 실제 attachmentId 는 UUID 지만, 형식이 바뀌어도 미디어가 조용히 사라지지 않게
            // 넉넉히 받는다. 값은 배열 조회 키로만 쓰고 출력은 tag() 가 escape 한다.
            '/\[\[MEDIA:([A-Za-z0-9_-]{1,128})\]\]/',
            static function (array $m) use ($byAttachment): string {
                $row = $byAttachment[strtolower($m[1])] ?? null;
                if ($row === null) {
                    return '';
                }
                return self::tag($row['kind'], $row['file'], $row['status']);
            },
            $html
        );
    }

    public static function tag(string $kind, string $file, string $status): string
    {
        if ($status !== 'done') {
            $label = $status === 'failed' ? '받지 못함' : '받는 중';
            return '<div class="m-pending">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $src = htmlspecialchars(App::url('/media/' . rawurlencode($file)), ENT_QUOTES, 'UTF-8');

        // 한 화면에 수십 개가 있어서 누를 때만 받게 한다(인계 문서 §8).
        return $kind === 'video'
            ? '<video class="m-video" controls preload="none" playsinline src="' . $src . '"></video>'
            : '<img class="m-image" loading="lazy" decoding="async" alt="" src="' . $src . '">';
    }

    /**
     * 회차 하나(또는 여러 개)에 걸린 미디어를 attachment_id 로 찾아볼 수 있게 모은다.
     * @param  array<int,int> $sessionIds
     * @return array<string,array{kind:string,file:string,status:string}>
     */
    public static function forSessions(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($sessionIds), '?'));
        $rows = Db::all(
            "SELECT m.attachment_id, m.kind, m.file, m.status
               FROM media m
               LEFT JOIN session_exercises sx ON sx.id = m.session_exercise_id
              WHERE sx.session_id IN ($in) OR m.session_exercise_id IS NULL",
            array_values($sessionIds)
        );

        $out = [];
        foreach ($rows as $r) {
            $out[strtolower((string) $r['attachment_id'])] = [
                'kind'   => (string) $r['kind'],
                'file'   => (string) $r['file'],
                'status' => (string) $r['status'],
            ];
        }
        return $out;
    }

    /** 저장된 미디어 파일이 실제로 있는지. */
    public static function exists(string $file): bool
    {
        $path = App::mediaDir() . '/' . $file;
        return is_file($path) && filesize($path) > 0;
    }
}
