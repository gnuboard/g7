<?php

namespace App\Thumbnails\Providers;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Helpers\InlineMediaPolicy;
use App\Models\Attachment;
use App\Support\PrivateAttachmentDisk;
use App\Thumbnails\Contracts\ThumbnailSourceProvider;
use App\Thumbnails\DTO\ThumbnailSource;

/**
 * 코어 첨부(`/api/attachment/{hash}`) 썸네일 원본 제공자 — 게이트.
 *
 * 코어 첨부는 권한 훅(`core.attachment.download`)을 지나는 게이트 원본이다(설계 결정: 코어 첨부는 CDN 처리 금지).
 * 저장 경로는 첨부 디스크 루트의 `Y/m/d/{uuid}.{ext}` 이다.
 */
class CoreAttachmentSourceProvider implements ThumbnailSourceProvider
{
    /** 코어 첨부 저장 경로 형식 */
    private const PATH_PATTERN = '#^\d{4}/\d{2}/\d{2}/[^/]+$#';

    /**
     * CoreAttachmentSourceProvider 생성자
     *
     * @param  AttachmentRepositoryInterface  $repository  첨부 리포지토리
     */
    public function __construct(
        private readonly AttachmentRepositoryInterface $repository
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'core.attachment';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'thumbnail.sources.core_attachment';
    }

    /**
     * {@inheritDoc}
     */
    public function gated(): bool
    {
        return true;
    }

    /**
     * 디스크 위 경로가 코어 첨부 원본인지 판정합니다.
     *
     * 코어 첨부 디스크는 종전 규칙(`attachment.disk`)과 지금의 업로드 디스크(`PrivateAttachmentDisk::forCore()` —
     * `ATTACHMENT_DISK` env 명시 > 「비공개 첨부 저장소」 선택 > 종전 규칙) 둘이다. 업로드 디스크를 빠뜨리면 선택한 디스크에
     * 올린 새 첨부가 「모르는 원본」이 되어 등록 시 미리 생성이 조용히 빠진다(#916).
     *
     * @param  string  $disk  원본 디스크
     * @param  string  $path  디스크 위 전체 경로
     * @return bool 코어 첨부 원본이면 true
     */
    public function matches(string $disk, string $path): bool
    {
        $coreDisks = [(string) config('attachment.disk', 'attachments'), PrivateAttachmentDisk::forCore()];

        return in_array($disk, $coreDisks, true)
            && preg_match(self::PATH_PATTERN, ltrim($path, '/')) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return $this->repository->countByMimeTypes(InlineMediaPolicy::RASTER_IMAGE_MIME_TYPES);
    }

    /**
     * {@inheritDoc}
     */
    public function chunk(?string $cursor, int $limit): array
    {
        $rows = $this->repository->chunkByMimeTypes(InlineMediaPolicy::RASTER_IMAGE_MIME_TYPES, (int) $cursor, $limit);

        $items = $rows->map(fn (Attachment $row) => self::sourceFor($row))->filter()->values()->all();

        return [
            'items' => $items,
            'next' => $rows->count() < $limit ? null : (string) $rows->last()?->id,
        ];
    }

    /**
     * 첨부 행의 썸네일 원본
     *
     * @param  Attachment  $attachment  첨부 행
     * @return ThumbnailSource|null 원본 (래스터가 아니면 null)
     */
    public static function sourceFor(Attachment $attachment): ?ThumbnailSource
    {
        if (! InlineMediaPolicy::isInlineImage($attachment->mime_type)) {
            return null;
        }

        $meta = is_array($attachment->meta) ? $attachment->meta : [];

        return new ThumbnailSource(
            disk: (string) ($attachment->disk ?: config('attachment.disk', 'attachments')),
            path: ltrim((string) $attachment->path, '/'),
            mime: (string) $attachment->mime_type,
            gated: true,
            width: isset($meta['width']) ? (int) $meta['width'] : null,
            height: isset($meta['height']) ? (int) $meta['height'] : null,
        );
    }
}
