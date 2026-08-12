<?php
declare(strict_types=1);

namespace Health\Notion;

/**
 * 블록 트리 → 회차 · 운동 · HTML. 인계 문서 §2.5.
 *
 * 미디어는 여기서 최종 태그로 굳히지 않고 `[[MEDIA:<attachmentId>]]` 자리표시자만 남긴다.
 * 실제 <video>/<img> 인지 "받는 중" 인지는 화면을 그릴 때 media.status 를 보고 정한다
 * (Media::expand). 그래야 파일을 나중에 받아도 다시 가져오기를 하지 않아도 된다.
 */
final class Parser
{
    /** @param array<string,array<string,mixed>> $blocks blockId => block */
    public function __construct(private readonly array $blocks)
    {
    }

    /**
     * @param  array<int,string> $pageIds 문서에 실린 순서
     * @return array<int,array<string,mixed>> 회차 목록
     */
    public function parseSessions(array $pageIds): array
    {
        $out = [];
        foreach ($pageIds as $i => $pageId) {
            $page = $this->blocks[$pageId] ?? null;
            if ($page === null) {
                continue;
            }
            $out[] = $this->parsePage($pageId, $page, $i);
        }
        return $out;
    }

    /**
     * @param  array<string,mixed> $page
     * @return array<string,mixed>
     */
    private function parsePage(string $pageId, array $page, int $position): array
    {
        $title = $this->plainText($page['properties']['title'] ?? null);
        $meta  = Title::parse($title);

        $children  = array_values(array_filter(
            (array) ($page['content'] ?? []),
            fn ($id) => isset($this->blocks[(string) $id])
        ));

        // 첫 토글 앞 = 그날 안내, 토글 = 운동, 토글 뒤의 나머지 = 총평
        $firstToggle = null;
        foreach ($children as $i => $id) {
            if (($this->blocks[(string) $id]['type'] ?? '') === 'toggle') {
                $firstToggle = $i;
                break;
            }
        }

        $introIds = $firstToggle === null ? $children : array_slice($children, 0, $firstToggle);
        $restIds  = $firstToggle === null ? [] : array_slice($children, $firstToggle);

        $pageMedia = [];
        $introHtml = $this->renderBlocks($introIds, $pageMedia);

        $exercises = [];
        $notesIds  = [];
        $order     = 0;
        foreach ($restIds as $id) {
            $block = $this->blocks[(string) $id];
            if (($block['type'] ?? '') !== 'toggle') {
                $notesIds[] = $id;
                continue;
            }
            $exercises[] = $this->parseToggle((string) $id, $block, $order++);
        }
        $notesHtml = $this->renderBlocks($notesIds, $pageMedia);

        return [
            'notion_page_id' => $pageId,
            'title'          => $title,
            'code'           => $meta['code'],
            'date'           => $meta['date'],
            'weekday'        => $meta['weekday'],
            'time'           => $meta['time'],
            'position'       => $position,
            'intro_html'     => $introHtml,
            'notes_html'     => $notesHtml,
            'exercises'      => $exercises,
            'media'          => $pageMedia,   // 토글 밖(안내 · 총평)에 붙은 첨부
        ];
    }

    /**
     * @param  array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function parseToggle(string $id, array $block, int $position): array
    {
        $raw   = $this->plainText($block['properties']['title'] ?? null);
        $split = Title::exercise($raw);

        $media = [];
        $body  = $this->renderBlocks((array) ($block['content'] ?? []), $media);

        return [
            'name'      => $split['name'],
            'meta'      => $split['meta'],
            'position'  => $position,
            'body_html' => $body,
            'media'     => $media,
            'block_id'  => $id,
        ];
    }

    /* 렌더링 --------------------------------------------------- */

    /**
     * @param array<int,mixed>              $ids
     * @param array<string,Attachment>      $media 수집용(참조로 채운다)
     */
    private function renderBlocks(array $ids, array &$media): string
    {
        $html = '';
        $listBuffer = [];
        $listType   = null;

        $flush = static function () use (&$html, &$listBuffer, &$listType): void {
            if ($listBuffer === []) {
                return;
            }
            $tag  = $listType === 'numbered_list' ? 'ol' : 'ul';
            $html .= "<$tag>" . implode('', $listBuffer) . "</$tag>";
            $listBuffer = [];
            $listType   = null;
        };

        foreach ($ids as $rawId) {
            $id    = (string) $rawId;
            $block = $this->blocks[$id] ?? null;
            if ($block === null) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');

            if ($type === 'numbered_list' || $type === 'bulleted_list') {
                if ($listType !== null && $listType !== $type) {
                    $flush();
                }
                $listType = $type;
                $inner    = $this->richText($block['properties']['title'] ?? null);
                $nested   = $this->renderBlocks((array) ($block['content'] ?? []), $media);
                $listBuffer[] = '<li>' . $inner . $nested . '</li>';
                continue;
            }
            $flush();
            $html .= $this->renderBlock($id, $block, $media);
        }
        $flush();

        return $html;
    }

    /**
     * @param array<string,mixed>     $block
     * @param array<string,Attachment> $media
     */
    private function renderBlock(string $id, array $block, array &$media): string
    {
        $type  = (string) ($block['type'] ?? '');
        $title = $block['properties']['title'] ?? null;

        return match ($type) {
            'text'        => $this->paragraph($title),
            'header', 'sub_header', 'sub_sub_header'
                          => '<h3 class="b-h">' . $this->richText($title) . '</h3>',
            'callout'     => '<div class="b-callout">' . $this->richText($title)
                             . $this->renderBlocks((array) ($block['content'] ?? []), $media) . '</div>',
            'quote'       => '<blockquote class="b-quote">' . $this->richText($title) . '</blockquote>',
            'divider'     => '<hr class="b-div">',
            'column_list' => '<div class="b-cols">'
                             . $this->renderBlocks((array) ($block['content'] ?? []), $media) . '</div>',
            'column'      => '<div class="b-col">'
                             . $this->renderBlocks((array) ($block['content'] ?? []), $media) . '</div>',
            'toggle'      => '<details class="b-toggle"><summary>' . $this->richText($title) . '</summary>'
                             . $this->renderBlocks((array) ($block['content'] ?? []), $media) . '</details>',
            'image', 'video', 'file', 'pdf', 'audio' => $this->media($id, $block, $media),
            'page'        => '',
            default       => $this->paragraph($title),
        };
    }

    private function paragraph(mixed $title): string
    {
        $inner = $this->richText($title);
        return $inner === '' ? '' : '<p class="b-p">' . $inner . '</p>';
    }

    /**
     * 첨부는 자리표시자만 남긴다. 외부 영상(유튜브)은 파일을 받을 수 없으므로 링크로만 남긴다.
     * @param array<string,mixed>     $block
     * @param array<string,Attachment> $media
     */
    private function media(string $id, array $block, array &$media): string
    {
        $source = (string) ($block['properties']['source'][0][0] ?? '');
        if ($source === '') {
            return '';
        }

        $att = Attachment::parse(
            $source,
            $id,
            (string) ($block['space_id'] ?? ''),
            (string) ($block['type'] ?? 'image')
        );
        if ($att === null) {
            // 외부 주소. 받아둘 수 없다.
            $safe = htmlspecialchars($source, ENT_QUOTES, 'UTF-8');
            return '<p class="b-extlink"><a href="' . $safe . '" rel="noreferrer noopener" target="_blank">'
                . '외부 영상 열기</a></p>';
        }

        // 같은 첨부가 여러 블록에 쓰인다 → attachmentId 로 중복을 없앤다.
        $media[$att->id] ??= $att;

        return '[[MEDIA:' . $att->id . ']]';
    }

    /* rich text ------------------------------------------------ */

    /**
     * `[[텍스트, [["b"], ["a","https://…"], ["h","red"]]], …]` 를 HTML 로.
     * 텍스트는 반드시 escape 한다(인계 문서 §9).
     */
    public function richText(mixed $rt): string
    {
        if (!is_array($rt)) {
            return '';
        }
        $out = '';
        foreach ($rt as $seg) {
            if (!is_array($seg)) {
                continue;
            }
            $text = (string) ($seg[0] ?? '');
            if ($text === '') {
                continue;
            }
            $html  = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false);
            $decos = is_array($seg[1] ?? null) ? $seg[1] : [];

            foreach ($decos as $deco) {
                if (!is_array($deco)) {
                    continue;
                }
                $kind = (string) ($deco[0] ?? '');
                $arg  = isset($deco[1]) && is_string($deco[1]) ? $deco[1] : '';
                $html = match ($kind) {
                    'b'  => '<strong>' . $html . '</strong>',
                    'i'  => '<em>' . $html . '</em>',
                    's'  => '<s>' . $html . '</s>',
                    '_'  => '<u>' . $html . '</u>',
                    'c'  => '<code>' . $html . '</code>',
                    'a'  => $this->link($arg, $html),
                    'h'  => '<span class="hl hl-' . $this->safeColor($arg) . '">' . $html . '</span>',
                    default => $html,
                };
            }
            $out .= $html;
        }
        return $out;
    }

    private function link(string $href, string $inner): string
    {
        if (!preg_match('#^(https?:)?//#i', $href) && !str_starts_with($href, '/')) {
            return $inner;
        }
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
            . '" rel="noreferrer noopener" target="_blank">' . $inner . '</a>';
    }

    private function safeColor(string $color): string
    {
        return (string) preg_replace('/[^a-z_]/', '', strtolower($color)) ?: 'default';
    }

    /** rich text 를 그냥 글자로. 줄바꿈은 살린다. */
    public function plainText(mixed $rt): string
    {
        if (!is_array($rt)) {
            return '';
        }
        $out = '';
        foreach ($rt as $seg) {
            if (is_array($seg)) {
                $out .= (string) ($seg[0] ?? '');
            }
        }
        return trim($out);
    }
}
