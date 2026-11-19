<?php

namespace App\Thumbnails\Providers;

use App\Contracts\Repositories\TemplateLayoutAttachmentRepositoryInterface;
use App\Helpers\InlineMediaPolicy;
use App\Models\TemplateLayoutAttachment;
use App\Thumbnails\Contracts\ThumbnailSourceProvider;
use App\Thumbnails\DTO\ThumbnailSource;

/**
 * 레이아웃 첨부(`/api/templates/{id}/layout-attachments/{id}/file`) 썸네일 원본 제공자 — 완전 공개.
 *
 * 레이아웃 편집기에서 올린 이미지로, 공개 화면 어디에서나 인증 없이 보인다.
 */
class LayoutAttachmentSourceProvider implements ThumbnailSourceProvider
{
    /** 저장 카테고리 (TemplateLayoutAttachmentService 와 같다) */
    public const CATEGORY = 'template-layout-attachments';

    /**
     * LayoutAttachmentSourceProvider 생성자
     *
     * @param  TemplateLayoutAttachmentRepositoryInterface  $repository  레이아웃 첨부 리포지토리
     */
    public function __construct(
        private readonly TemplateLayoutAttachmentRepositoryInterface $repository
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'core.layout_attachment';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'thumbnail.sources.layout_attachment';
    }

    /**
     * {@inheritDoc}
     */
    public function gated(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function matches(string $disk, string $path): bool
    {
        return str_starts_with(ltrim($path, '/'), self::CATEGORY.'/');
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

        return [
            'items' => $rows->map(fn (TemplateLayoutAttachment $row) => self::sourceFor($row))->filter()->values()->all(),
            'next' => $rows->count() < $limit ? null : (string) $rows->last()?->id,
        ];
    }

    /**
     * 레이아웃 첨부 행의 썸네일 원본
     *
     * @param  TemplateLayoutAttachment  $attachment  레이아웃 첨부 행
     * @return ThumbnailSource|null 원본 (래스터가 아니면 null)
     */
    public static function sourceFor(TemplateLayoutAttachment $attachment): ?ThumbnailSource
    {
        if (! InlineMediaPolicy::isInlineImage($attachment->mime_type)) {
            return null;
        }

        return new ThumbnailSource(
            disk: (string) ($attachment->disk ?: config('attachment.disk', 'attachments')),
            path: self::CATEGORY.'/'.ltrim((string) $attachment->path, '/'),
            mime: (string) $attachment->mime_type,
            gated: false,
        );
    }
}
