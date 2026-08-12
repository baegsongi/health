<?php
declare(strict_types=1);

namespace Health\Notion;

/**
 * 블록의 `properties.source[0][0]` 에 들어 있는 `attachment:<id>:<파일명>` 을 다룬다.
 * 인계 문서 §2.3.
 */
final class Attachment
{
    private function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $ext,
        public readonly string $blockId,
        public readonly string $spaceId,
        public readonly string $blockType,
    ) {
    }

    /**
     * source 문자열을 뜯는다. attachment: 로 시작하지 않으면 null.
     * ⚠ 파일명에 ':' 가 들어갈 수 있으므로 세 조각까지만 자른다.
     */
    public static function parse(
        string $source,
        string $blockId,
        string $spaceId = '',
        string $blockType = 'image',
    ): ?self
    {
        if (!str_starts_with($source, 'attachment:')) {
            return null;
        }
        $parts = explode(':', $source, 3);
        if (count($parts) < 3 || $parts[1] === '') {
            return null;
        }
        return new self($parts[1], $source, self::extOf($parts[2]), $blockId, $spaceId, $blockType);
    }

    /** 파일명에서 확장자. 이상하면 .bin. */
    public static function extOf(string $filename): string
    {
        $ext = strtolower((string) strrchr($filename, '.'));
        return preg_match('/^\.[a-z0-9]{1,5}$/', $ext) === 1 ? $ext : '.bin';
    }

    /** 저장 파일명 — 원본 이름을 쓰지 않고 `<attachmentId>.<확장자>` 로 통일한다. */
    public function fileName(): string
    {
        return $this->id . $this->ext;
    }

    /** 내려받을 주소. 302 를 따라가면 실제 파일이 나온다. */
    public function url(): string
    {
        return 'https://www.notion.so/signed/' . rawurlencode($this->source)
            . '?table=block&id=' . rawurlencode($this->blockId)
            . '&spaceId=' . rawurlencode($this->spaceId);
    }

    /** 'video' | 'image' — 이 첨부가 무엇인지. */
    public function kind(): string
    {
        return self::kindOf($this->blockType, $this->ext);
    }

    /** 'video' | 'image' — 블록 타입과 확장자로 정한다. */
    public static function kindOf(string $blockType, string $ext): string
    {
        if ($blockType === 'video') {
            return 'video';
        }
        return in_array($ext, ['.mp4', '.mov', '.m4v', '.webm'], true) ? 'video' : 'image';
    }
}
