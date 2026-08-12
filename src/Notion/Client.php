<?php
declare(strict_types=1);

namespace Health\Notion;

use Health\App;

/**
 * Notion 비공식 API. 공개 페이지는 인증 없이 읽힌다(인계 문서 §2.2).
 * User-Agent 를 브라우저 것으로 넣지 않으면 막힌다.
 */
final class Client
{
    public function __construct(
        private readonly string $userAgent = '',
        private readonly int $timeout = 30,
    ) {
    }

    private function ua(): string
    {
        return $this->userAgent !== '' ? $this->userAgent : (string) App::conf('user_agent');
    }

    /** @return array<string,mixed> */
    public function post(string $endpoint, array $body): array
    {
        $ch = curl_init('https://www.notion.so/api/v3/' . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERAGENT      => $this->ua(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("notion $endpoint: 연결 실패 — $err");
        }
        if ($code !== 200) {
            throw new \RuntimeException("notion $endpoint: HTTP $code");
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("notion $endpoint: JSON 이 아니다");
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public function loadPageChunk(string $pageId): array
    {
        return $this->post('loadPageChunk', [
            'pageId'          => $pageId,
            'limit'           => 300,
            'cursor'          => ['stack' => []],
            'chunkNumber'     => 0,
            'verticalColumns' => false,
        ]);
    }

    /**
     * @param array<int,string> $ids
     * @return array<string,mixed>
     */
    public function syncRecordValues(array $ids): array
    {
        return $this->post('syncRecordValues', [
            'requests' => array_map(
                static fn (string $id): array => [
                    'pointer' => ['table' => 'block', 'id' => $id, 'spaceId' => ''],
                    'version' => -1,
                ],
                array_values($ids)
            ),
        ]);
    }

    /**
     * 페이지와 그 아래 모든 자식 블록을 빠짐없이 모은다.
     * ⚠ loadPageChunk 는 토글 안쪽을 내려주지 않는다. 못 받은 자식 id 를 모아 반복 요청한다.
     *
     * @param  array<int,string> $pageIds
     * @return array<string,array<string,mixed>> blockId => block
     */
    public function collectBlocks(array $pageIds, ?callable $progress = null): array
    {
        $blocks = [];
        $absorb = static function (array $recordMap) use (&$blocks): void {
            foreach ($recordMap['block'] ?? [] as $id => $entry) {
                $value = $entry['value']['value'] ?? null;
                if (is_array($value)) {
                    $blocks[(string) $id] = $value;
                }
            }
        };

        foreach ($pageIds as $pageId) {
            $absorb($this->loadPageChunk($pageId)['recordMap'] ?? []);
            if ($progress !== null) {
                $progress('page', $pageId, count($blocks));
            }
        }

        for ($round = 0; $round < 8; $round++) {
            $missing = [];
            foreach ($blocks as $block) {
                foreach ($block['content'] ?? [] as $childId) {
                    if (!isset($blocks[$childId])) {
                        $missing[$childId] = true;
                    }
                }
            }
            if ($missing === []) {
                break;
            }
            $ids = array_keys($missing);
            foreach (array_chunk($ids, 50) as $chunk) {
                $absorb($this->syncRecordValues($chunk)['recordMap'] ?? []);
            }
            if ($progress !== null) {
                $progress('round', (string) ($round + 1), count($blocks));
            }
        }

        return $blocks;
    }

    /**
     * 첨부를 스트리밍으로 내려받는다. 40MB 를 메모리에 올리지 않는다(인계 문서 §6).
     * `.part` 로 받은 뒤 rename 하므로, 중간에 끊겨도 다음 실행이 "이미 있음"으로 착각하지 않는다.
     *
     * @return int 받은 바이트 수
     */
    public function download(Attachment $att, string $destDir): int
    {
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            throw new \RuntimeException("미디어 디렉터리를 만들 수 없다: $destDir");
        }

        $path = rtrim($destDir, '/') . '/' . $att->fileName();
        $part = $path . '.part';

        $out = fopen($part, 'wb');
        if ($out === false) {
            throw new \RuntimeException("파일을 열 수 없다: $part");
        }

        $ch = curl_init($att->url());
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => $this->ua(),
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FAILONERROR    => true,
        ]);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($out);

        if ($ok === false || $code >= 400) {
            @unlink($part);
            throw new \RuntimeException("내려받기 실패 (HTTP $code) {$att->fileName()} — $err");
        }

        $bytes = (int) filesize($part);
        if ($bytes <= 0) {
            @unlink($part);
            throw new \RuntimeException("빈 파일을 받았다: {$att->fileName()}");
        }

        // 다 받은 것만 최종 이름으로 바꾼다.
        if (!rename($part, $path)) {
            @unlink($part);
            throw new \RuntimeException("이름을 바꿀 수 없다: $path");
        }
        return $bytes;
    }
}
