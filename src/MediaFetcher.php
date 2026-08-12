<?php
declare(strict_types=1);

namespace Health;

use Health\Notion\Attachment;
use Health\Notion\Client;

/**
 * 미디어 내려받기 2단계. 인계 문서 §6.
 *
 * 1.7GB 를 한 요청에서 받으면 죽는다. 그래서 한 번에 몇 개씩만 받고,
 * 화면은 <meta http-equiv="refresh"> 로 자기 자신을 다시 부른다.
 * 다시 돌리면 이미 받은 건 건너뛴다.
 */
final class MediaFetcher
{
    /** 한 요청에서 받을 개수. 1.7GB 를 한 번에 받으면 죽는다(인계 문서 §6). */
    public const BATCH = 3;

    public function __construct(private readonly ?Client $client = null)
    {
    }

    /** @return array{pending:int,done:int,failed:int,total:int,bytes:int} */
    public static function stats(): array
    {
        $rows = Db::all('SELECT status, COUNT(*) n FROM media GROUP BY status');
        $by   = ['pending' => 0, 'done' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $by[(string) $r['status']] = (int) $r['n'];
        }
        return $by + [
            'total' => array_sum($by),
            'bytes' => (int) Db::value('SELECT COALESCE(SUM(bytes), 0) FROM media WHERE status = ?', ['done']),
        ];
    }

    /**
     * 남은 것 중 $limit 개를 받는다.
     * @return array{done:int,failed:int,bytes:int,names:array<int,string>}
     */
    public function fetchBatch(int $limit = 3, ?callable $onEach = null): array
    {
        $rows = Db::all(
            "SELECT * FROM media WHERE status IN ('pending', 'failed') ORDER BY status DESC, id LIMIT ?",
            [max(1, $limit)]
        );

        $client = $this->client ?? new Client();
        $out    = ['done' => 0, 'failed' => 0, 'bytes' => 0, 'names' => []];

        foreach ($rows as $row) {
            $file = (string) $row['file'];
            $out['names'][] = $file;

            // 이미 받아둔 파일이면 건너뛴다. (.part 는 완결된 파일이 아니므로 세지 않는다)
            if (Media::exists($file)) {
                $this->markDone((string) $row['attachment_id'], (int) filesize(App::mediaDir() . '/' . $file));
                $out['done']++;
                if ($onEach !== null) {
                    $onEach($file, 'skip', 0);
                }
                continue;
            }

            $att = Attachment::parse(
                (string) $row['source'],
                (string) $row['block_id'],
                (string) ($row['space_id'] ?? ''),
                (string) $row['kind'],
            );
            if ($att === null) {
                $this->markFailed((string) $row['attachment_id']);
                $out['failed']++;
                if ($onEach !== null) {
                    $onEach($file, 'bad-source', 0);
                }
                continue;
            }

            try {
                $bytes = $client->download($att, App::mediaDir());
                $this->markDone((string) $row['attachment_id'], $bytes);
                $out['done']++;
                $out['bytes'] += $bytes;
                if ($onEach !== null) {
                    $onEach($file, 'ok', $bytes);
                }
            } catch (\Throwable $e) {
                App::log('미디어 실패 ' . $file . ' — ' . $e->getMessage());
                $this->markFailed((string) $row['attachment_id']);
                $out['failed']++;
                if ($onEach !== null) {
                    $onEach($file, 'fail', 0);
                }
            }
        }

        return $out;
    }

    /**
     * 이미 어딘가에 받아둔 파일을 그대로 가져다 쓴다(재다운로드 없이).
     * 파일명이 `<attachmentId>.<확장자>` 로 통일돼 있어 그대로 맞춰볼 수 있다.
     *
     * @return array{copied:int,skipped:int,missing:int,bytes:int}
     */
    public function adoptFrom(string $sourceDir, bool $move = false): array
    {
        $dest = App::mediaDir();
        if (!is_dir($dest) && !mkdir($dest, 0775, true) && !is_dir($dest)) {
            throw new \RuntimeException("미디어 디렉터리를 만들 수 없다: $dest");
        }

        $out = ['copied' => 0, 'skipped' => 0, 'missing' => 0, 'bytes' => 0];
        foreach (Db::all('SELECT attachment_id, file FROM media') as $row) {
            $file = (string) $row['file'];
            if (Media::exists($file)) {
                $this->markDone((string) $row['attachment_id'], (int) filesize("$dest/$file"));
                $out['skipped']++;
                continue;
            }
            $src = rtrim($sourceDir, '/') . '/' . $file;
            if (!is_file($src) || filesize($src) <= 0) {
                $out['missing']++;
                continue;
            }
            $ok = $move ? rename($src, "$dest/$file") : copy($src, "$dest/$file");
            if (!$ok) {
                $out['missing']++;
                continue;
            }
            $bytes = (int) filesize("$dest/$file");
            $this->markDone((string) $row['attachment_id'], $bytes);
            $out['copied']++;
            $out['bytes'] += $bytes;
        }
        return $out;
    }

    private function markDone(string $attachmentId, int $bytes): void
    {
        Db::run('UPDATE media SET status = ?, bytes = ? WHERE attachment_id = ?', ['done', $bytes, $attachmentId]);
    }

    private function markFailed(string $attachmentId): void
    {
        Db::run('UPDATE media SET status = ? WHERE attachment_id = ?', ['failed', $attachmentId]);
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . 'GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . 'KB';
        }
        return $bytes . 'B';
    }
}
