<?php
declare(strict_types=1);

namespace Health\Notion;

use Health\App;

/**
 * 가져오기 1단계 — 수집 · 파싱 · 저장까지 한 번에. 인계 문서 §6.
 * 미디어 파일은 여기서 받지 않는다(2단계 /import/media · bin/fetch-media.php).
 */
final class Import
{
    /**
     * @return array{kind:string,title:string,pages:int,sessions:int,exercises:int,
     *               media_new:int,media_total:int,blocks:int,secs:float}
     */
    public static function run(string $url, ?callable $progress = null): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('http(s) 로 시작하는 주소를 넣어 주세요.');
        }

        $t0     = microtime(true);
        $client = new Client();

        if (Craft::isCraftUrl($url)) {
            $doc     = (new Craft())->read($url);
            $kind    = 'craft';
            $title   = $doc['title'];
            $pageIds = $doc['pageIds'];
        } else {
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (!str_contains($host, 'notion.')) {
                throw new \RuntimeException('craft.me 또는 notion 주소만 가져올 수 있습니다.');
            }
            $uuid = Ids::toUuid($url);
            if ($uuid === null) {
                throw new \RuntimeException('주소에서 페이지 id(32자리 hex)를 찾을 수 없습니다.');
            }
            $kind    = 'notion';
            $title   = $url;
            $pageIds = [$uuid];
        }

        if ($pageIds === []) {
            throw new \RuntimeException('가져올 Notion 페이지를 찾지 못했습니다.');
        }

        $blocks   = $client->collectBlocks($pageIds, $progress);
        $sessions = (new Parser($blocks))->parseSessions($pageIds);
        $saved    = (new Importer())->save($sessions, $url, $kind, $title);

        if ($kind === 'notion' && ($sessions[0]['title'] ?? '') !== '') {
            $title = (string) $sessions[0]['title'];
        }

        App::log("가져오기: $url — 회차 {$saved['sessions']} · 운동 {$saved['exercises']}"
            . " · 미디어 {$saved['media_total']}");

        return [
            'kind'        => $kind,
            'title'       => $title,
            'pages'       => count($pageIds),
            'blocks'      => count($blocks),
            'sessions'    => $saved['sessions'],
            'exercises'   => $saved['exercises'],
            'media_new'   => $saved['media_new'],
            'media_total' => $saved['media_total'],
            'secs'        => round(microtime(true) - $t0, 1),
        ];
    }
}
