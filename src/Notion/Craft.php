<?php
declare(strict_types=1);

namespace Health\Notion;

use Health\App;

/**
 * Craft 공유 문서. 공개 페이지는 클라이언트 렌더링이라 HTML 로는 본문이 안 나온다.
 * API 가 따로 있다 — GET https://<space>.craft.me/api/share/<shareId> (인계 문서 §2.1).
 */
final class Craft
{
    public function __construct(private readonly string $userAgent = '')
    {
    }

    public static function isCraftUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        return $host !== '' && str_contains($host, 'craft.me');
    }

    /**
     * 공유 문서를 읽어 제목과 그 안의 Notion 페이지 id 를 문서 순서대로 돌려준다.
     * @return array{title:string,host:string,pageIds:array<int,string>}
     */
    public function read(string $shareUrl): array
    {
        $parts = parse_url($shareUrl);
        if (!isset($parts['host'], $parts['path'])) {
            throw new \RuntimeException('Craft 주소가 아니다: ' . $shareUrl);
        }
        $shareId = basename(rtrim($parts['path'], '/'));
        if ($shareId === '') {
            throw new \RuntimeException('Craft shareId 를 찾을 수 없다: ' . $shareUrl);
        }
        $api = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . '/api/share/' . rawurlencode($shareId);

        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => $this->userAgent !== '' ? $this->userAgent : (string) App::conf('user_agent'),
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            throw new \RuntimeException("craft share API 실패 (HTTP $code) $err");
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('craft share API 가 JSON 을 주지 않았다');
        }

        return self::parseShare($data, (string) $parts['host']);
    }

    /**
     * 응답 파싱만 따로 뺀다(테스트용).
     * @param  array<string,mixed> $data
     * @return array{title:string,host:string,pageIds:array<int,string>}
     */
    public static function parseShare(array $data, string $host = ''): array
    {
        $blocks = is_array($data['blocks'] ?? null) ? $data['blocks'] : [];

        $pageIds = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'url') {
                continue;
            }
            $url = self::notionUrlOf($block);
            if ($url === null) {
                continue;
            }
            $uuid = Ids::toUuid($url);
            if ($uuid !== null && !in_array($uuid, $pageIds, true)) {
                $pageIds[] = $uuid;
            }
        }

        return [
            'title'   => (string) ($blocks[0]['content'] ?? '보관 문서'),
            'host'    => $host,
            'pageIds' => $pageIds,
        ];
    }

    /**
     * url 블록에서 Notion 페이지 주소를 꺼낸다.
     * ⚠ `content` 가 빈 블록이 실제로 있다. 그 경우 주소는 `rawProperties` 안에만 있고,
     *   거기엔 미리보기 이미지 주소도 같이 잡힌다 — `/image/` 가 든 것은 버리고
     *   32자리 hex(페이지 id)가 있는 것만 고른다. 이걸 놓쳐서 13개 중 1개가 통째로 빠졌었다.
     *
     * @param array<string,mixed> $block
     */
    public static function notionUrlOf(array $block): ?string
    {
        $content = (string) ($block['content'] ?? '');
        if ($content !== '' && str_contains($content, 'notion')) {
            return $content;
        }

        $raw = (string) ($block['rawProperties'] ?? '');
        if ($raw === '') {
            return null;
        }
        // JSON 안에 escape 된 슬래시(\/)를 되돌린다.
        $raw = preg_replace('#\\\\+/#', '/', $raw) ?? $raw;

        if (!preg_match_all('#https?://[^"\s\\\\]*notion[^"\s\\\\]*#i', $raw, $m)) {
            return null;
        }
        foreach ($m[0] as $url) {
            if (!str_contains($url, '/image/') && preg_match('/[0-9a-f]{32}/i', $url)) {
                return $url;
            }
        }
        return null;
    }
}
