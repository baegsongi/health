<?php
declare(strict_types=1);

/**
 * M1 확인용. 수집만 하고 아무것도 저장하지 않는다.
 *   php bin/probe.php https://<space>.craft.me/<shareId>
 *   php bin/probe.php https://<사용자>.notion.site/<pageId>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI 에서만 실행한다.\n");
}

require dirname(__DIR__) . '/src/App.php';

use Health\App;
use Health\Notion\Attachment;
use Health\Notion\Client;
use Health\Notion\Craft;
use Health\Notion\Ids;

App::boot();

$url = $argv[1] ?? '';
if ($url === '') {
    fwrite(STDERR, "쓰는 법: php bin/probe.php <craft 또는 notion URL>\n");
    exit(1);
}

$client = new Client();

if (Craft::isCraftUrl($url)) {
    $doc = (new Craft())->read($url);
    echo "craft: {$doc['title']}\n";
    echo 'craft: notion 페이지 ' . count($doc['pageIds']) . "개\n";
    $pageIds = $doc['pageIds'];
} else {
    $uuid = Ids::toUuid($url);
    if ($uuid === null) {
        fwrite(STDERR, "주소에서 페이지 id(32자리 hex)를 찾을 수 없다.\n");
        exit(1);
    }
    echo "notion: 페이지 $uuid\n";
    $pageIds = [$uuid];
}

$t0     = microtime(true);
$blocks = $client->collectBlocks($pageIds, static function (string $what, string $key, int $n): void {
    echo "  [$what $key] 누적 블록 $n\n";
});
$secs = round(microtime(true) - $t0, 1);

/* 집계 -------------------------------------------------------- */
$byType = [];
foreach ($blocks as $b) {
    $t = (string) ($b['type'] ?? '?');
    $byType[$t] = ($byType[$t] ?? 0) + 1;
}
ksort($byType);

$attachments = [];
$external    = 0;
foreach ($blocks as $id => $b) {
    if (!in_array($b['type'] ?? '', ['image', 'video', 'file', 'pdf', 'audio'], true)) {
        continue;
    }
    $source = (string) ($b['properties']['source'][0][0] ?? '');
    $att    = Attachment::parse(
        $source,
        (string) $id,
        (string) ($b['space_id'] ?? ''),
        (string) ($b['type'] ?? 'image')
    );
    if ($att === null) {
        if ($source !== '') {
            $external++;
        }
        continue;
    }
    // 같은 첨부가 여러 블록에 쓰인다 → attachmentId 로 중복을 없앤다.
    $attachments[$att->id] ??= $att;
}

$kinds = ['video' => 0, 'image' => 0];
foreach ($attachments as $att) {
    $kinds[$att->kind()]++;
}

$toggles = $byType['toggle'] ?? 0;
$pages   = $byType['page'] ?? 0;

echo "\n--- 결과 (" . $secs . "초) ---\n";
echo '블록 ' . count($blocks) . "개\n";
foreach ($byType as $t => $n) {
    printf("  %-14s %d\n", $t, $n);
}
echo "\n페이지(회차) $pages · 토글(운동 종목) $toggles\n";
echo '고유 첨부 ' . count($attachments)
    . " (동영상 {$kinds['video']} · 이미지 {$kinds['image']})"
    . ($external > 0 ? " · 외부 링크 $external" : '') . "\n";

$first = reset($attachments);
if ($first instanceof Attachment) {
    echo "\n첨부 URL 예시:\n  " . $first->fileName() . "\n  " . $first->url() . "\n";
}
