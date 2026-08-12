<?php
declare(strict_types=1);

namespace Health\Notion;

use Health\Db;
use Health\Media;

/**
 * 파싱 결과를 DB 에 반영한다. 인계 문서 §6.
 *
 * 다시 가져오기는 `notion_page_id` 로 upsert 한다. 회차의 운동 목록은 지우고 다시 넣되,
 * `exercises`(마스터)는 이름으로 재사용한다 — 그래야 "오늘의 운동" 기록이 끊기지 않는다.
 *
 * ⚠ 여기서는 미디어 파일을 받지 않는다. `status='pending'` 행만 만든다.
 */
final class Importer
{
    /**
     * @param  array<int,array<string,mixed>> $sessions Parser 결과
     * @return array{sessions:int,exercises:int,media_new:int,media_total:int}
     */
    public function save(array $sessions, string $sourceUrl, string $sourceKind, string $sourceTitle): array
    {
        return Db::transaction(function () use ($sessions, $sourceUrl, $sourceKind, $sourceTitle): array {
            $sourceId = $this->upsertSource($sourceUrl, $sourceKind, $sourceTitle);
            $now      = date('c');

            $countEx    = 0;
            $mediaNew   = 0;
            $mediaTotal = 0;

            foreach ($sessions as $s) {
                $sessionId = $this->upsertSession($s, $sourceId, $now);

                // 회차의 운동 목록은 통째로 지우고 다시 넣는다(media 는 CASCADE 로 함께 정리된다).
                Db::run('DELETE FROM session_exercises WHERE session_id = ?', [$sessionId]);

                foreach ($s['exercises'] as $ex) {
                    $exerciseId = $this->exerciseId((string) $ex['name'], $now);
                    $sxId = Db::insert(
                        'INSERT INTO session_exercises (session_id, exercise_id, position, meta, body_html)
                         VALUES (?, ?, ?, ?, ?)',
                        [$sessionId, $exerciseId, (int) $ex['position'], $ex['meta'], (string) $ex['body_html']]
                    );
                    $countEx++;

                    foreach ($ex['media'] as $att) {
                        $mediaTotal++;
                        $mediaNew += $this->upsertMedia($att, $sxId, $ex['block_id'] ?? '');
                    }
                }

                // 토글 밖(안내 · 총평)에 붙은 첨부는 회차에 매달지 않는다.
                foreach ($s['media'] ?? [] as $att) {
                    $mediaTotal++;
                    $mediaNew += $this->upsertMedia($att, null, '');
                }
            }

            return [
                'sessions'    => count($sessions),
                'exercises'   => $countEx,
                'media_new'   => $mediaNew,
                'media_total' => $mediaTotal,
            ];
        });
    }

    private function upsertSource(string $url, string $kind, string $title): int
    {
        Db::run(
            'INSERT INTO sources (kind, url, title, imported_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(url) DO UPDATE SET kind = excluded.kind,
                                            title = excluded.title,
                                            imported_at = excluded.imported_at',
            [$kind, $url, $title, date('c')]
        );
        return (int) Db::value('SELECT id FROM sources WHERE url = ?', [$url]);
    }

    /** @param array<string,mixed> $s */
    private function upsertSession(array $s, int $sourceId, string $now): int
    {
        Db::run(
            'INSERT INTO sessions
                (source_id, notion_page_id, code, title, date, weekday, time,
                 position, intro_html, notes_html, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(notion_page_id) DO UPDATE SET
                source_id  = excluded.source_id,
                code       = excluded.code,
                title      = excluded.title,
                date       = excluded.date,
                weekday    = excluded.weekday,
                time       = excluded.time,
                position   = excluded.position,
                intro_html = excluded.intro_html,
                notes_html = excluded.notes_html,
                updated_at = excluded.updated_at',
            [
                $sourceId, $s['notion_page_id'], $s['code'], $s['title'], $s['date'],
                $s['weekday'], $s['time'], (int) $s['position'],
                $s['intro_html'], $s['notes_html'], $now,
            ]
        );
        return (int) Db::value('SELECT id FROM sessions WHERE notion_page_id = ?', [$s['notion_page_id']]);
    }

    /** 이름으로 재사용한다. 없으면 만든다. */
    private function exerciseId(string $name, string $now): int
    {
        $name = trim($name) !== '' ? trim($name) : '이름 없는 운동';
        Db::run('INSERT INTO exercises (name, created_at) VALUES (?, ?) ON CONFLICT(name) DO NOTHING', [$name, $now]);
        return (int) Db::value('SELECT id FROM exercises WHERE name = ?', [$name]);
    }

    /** @return int 새로 만들어진 행이면 1 */
    private function upsertMedia(Attachment $att, ?int $sxId, string $blockId): int
    {
        $existing = Db::one('SELECT id, status FROM media WHERE attachment_id = ?', [$att->id]);
        $file     = $att->fileName();
        $kind     = $att->kind();

        // 파일이 이미 서버에 있으면 굳이 pending 으로 되돌리지 않는다.
        $status = Media::exists($file) ? 'done' : 'pending';

        if ($existing !== null) {
            Db::run(
                'UPDATE media SET session_exercise_id = ?, kind = ?, file = ?, source = ?,
                                  block_id = ?, space_id = ?,
                                  status = CASE WHEN status = ? THEN ? ELSE status END
                 WHERE attachment_id = ?',
                [$sxId, $kind, $file, $att->source, $att->blockId, $att->spaceId,
                 'pending', $status, $att->id]
            );
            return 0;
        }

        Db::run(
            'INSERT INTO media (attachment_id, session_exercise_id, kind, file, source,
                                block_id, space_id, bytes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$att->id, $sxId, $kind, $file, $att->source, $att->blockId, $att->spaceId, null, $status]
        );
        return 1;
    }
}
