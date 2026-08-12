<?php
declare(strict_types=1);

use Health\Notion\Attachment;
use Health\Notion\Craft;
use Health\Notion\Ids;

T::group('첨부 URL 조립 (문서 §2.3)');

$att = Attachment::parse(
    'attachment:d8d6f1c6-223f-4e7a-bce1-9b35ad6c3b6a:image.png',
    '37c00304-ca7d-8002-9a5a-d672b68dbb69',
    'e4700304-ca7d-815a-94a0-000305a7fd8e',
    'image'
);
T::true($att !== null, 'attachment: 로 시작하면 뜯긴다');
T::is($att?->id, 'd8d6f1c6-223f-4e7a-bce1-9b35ad6c3b6a', 'attachmentId');
T::is($att?->ext, '.png', '확장자');
T::is($att?->fileName(), 'd8d6f1c6-223f-4e7a-bce1-9b35ad6c3b6a.png', '저장 파일명은 <id>.<확장자>');
T::is(
    $att?->url(),
    'https://www.notion.so/signed/attachment%3Ad8d6f1c6-223f-4e7a-bce1-9b35ad6c3b6a%3Aimage.png'
    . '?table=block&id=37c00304-ca7d-8002-9a5a-d672b68dbb69'
    . '&spaceId=e4700304-ca7d-815a-94a0-000305a7fd8e',
    '서명 URL 전체'
);

// ⚠ 파일명에 ':' 가 들어갈 수 있다. attachment: 다음 첫 ':' 까지만 id 로 자른다.
$colon = Attachment::parse('attachment:abc-123:내 영상: 1회차.mp4', 'b1', 's1', 'video');
T::is($colon?->id, 'abc-123', "파일명에 ':' 가 있어도 id 를 바르게 자른다");
T::is($colon?->ext, '.mp4', "':' 가 든 파일명에서도 확장자를 찾는다");

T::is(Attachment::parse('https://youtu.be/xxxx', 'b1'), null, '외부 주소는 첨부가 아니다');
T::is(Attachment::parse('attachment:', 'b1'), null, '조각이 모자라면 null');

T::group('확장자 정하기');
T::is(Attachment::extOf('a.MP4'), '.mp4', '대문자 확장자를 소문자로');
T::is(Attachment::extOf('확장자없음'), '.bin', '확장자가 없으면 .bin');
T::is(Attachment::extOf('a.verylongext'), '.bin', '너무 긴 것은 .bin');

T::group('video / image 판별');
T::is(Attachment::kindOf('video', '.mp4'), 'video', 'video 블록은 video');
T::is(Attachment::kindOf('image', '.gif'), 'image', 'gif 는 image');
T::is(Attachment::kindOf('file', '.mp4'), 'video', 'file 블록이어도 mp4 면 video');
T::is(Attachment::kindOf('image', '.png'), 'image', 'png 는 image');

T::group('페이지 id → UUID (문서 §2.2)');
T::is(
    Ids::toUuid('https://x.notion.site/37c00304ca7d807bbaa5e756dcec4792'),
    '37c00304-ca7d-807b-baa5-e756dcec4792',
    '32자리 hex 를 UUID 로'
);
// ⚠ 하이픈을 먼저 지우면 제목 슬러그의 숫자가 붙어 가짜 32자리가 만들어진다.
T::is(
    Ids::toUuid('https://x.notion.site/26-06-10-21-30-37c00304ca7d807bbaa5e756dcec4792'),
    '37c00304-ca7d-807b-baa5-e756dcec4792',
    '슬러그에 숫자가 섞여도 진짜 페이지 id 를 고른다'
);
T::is(Ids::toUuid('숫자가 없다'), null, '못 찾으면 null');

T::group('Craft url 블록 (문서 §2.1)');
T::is(
    Craft::notionUrlOf(['content' => 'https://x.notion.site/abc37c00304ca7d807bbaa5e756dcec4792']),
    'https://x.notion.site/abc37c00304ca7d807bbaa5e756dcec4792',
    'content 가 있으면 그대로 쓴다'
);
// ⚠ content 가 빈 블록이 실제로 있다. rawProperties 로 폴백해야 한 회차가 통째로 빠지지 않는다.
$raw = json_encode([
    'preview' => 'https://www.notion.so/image/37c00304ca7d807bbaa5e756dcec4792.png',
    'url'     => 'https:\/\/x.notion.site\/37c00304ca7d807bbaa5e756dcec4792',
]);
T::is(
    Craft::notionUrlOf(['content' => '', 'rawProperties' => (string) $raw]),
    'https://x.notion.site/37c00304ca7d807bbaa5e756dcec4792',
    'content 가 비면 rawProperties 에서 찾고 /image/ 는 버린다'
);
T::is(Craft::notionUrlOf(['content' => '', 'rawProperties' => '']), null, '아무것도 없으면 null');

T::group('Craft 공유 문서 파싱');
$share = Craft::parseShare([
    'blocks' => [
        ['type' => 'text', 'content' => '운동 기록 💪'],
        ['type' => 'url', 'content' => 'https://x.notion.site/37c00304ca7d807bbaa5e756dcec4792'],
        ['type' => 'url', 'content' => '', 'rawProperties' => 'https://x.notion.site/37e00304ca7d804d92ceea38875cfd7f'],
        ['type' => 'url', 'content' => 'https://example.com/그냥링크'],
        ['type' => 'url', 'content' => 'https://x.notion.site/37c00304ca7d807bbaa5e756dcec4792'],
    ],
], 'x.craft.me');
T::is($share['title'], '운동 기록 💪', '첫 블록이 문서 제목');
T::is(count($share['pageIds']), 2, '노션이 아닌 링크는 빼고 중복도 없앤다');
T::is($share['pageIds'][1], '37e00304-ca7d-804d-92ce-ea38875cfd7f', 'rawProperties 폴백으로 찾은 것도 들어간다');
