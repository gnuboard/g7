<?php

namespace Modules\Sirsoft\Page\Support;

use App\Helpers\InlineMediaPolicy;
use App\Thumbnails\Contracts\ThumbnailSourceProvider;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;
use Modules\Sirsoft\Page\Services\PageAttachmentService;

/**
 * 페이지 첨부(`/api/modules/sirsoft-page/pages/attachment/{hash}/preview`) 썸네일 원본 제공자 — 게이트.
 *
 * 미발행 페이지의 첨부는 `sirsoft-page.pages.read` 관리자·서명 요청만 볼 수 있어 파생본은 비공개 썸네일
 * 디스크에 둔다. 저장 경로는 모듈 드라이버 기준 `sirsoft-page/attachments/{Y/m/d}/{파일}` 이고, 임시 첨부
 * (`temp/{key}/…`)는 페이지에 이어질 때 본경로로 옮겨지므로 제외한다.
 *
 * 본문 미디어 이미지(`/api/modules/sirsoft-page/pages/content-media/{hash}`)도 이 제공자가 함께 맡는다. 본문 미디어는 같은 표
 * (`page_attachments` 의 `collection = content_media`)와 같은 카테고리(`content-media/{Y/m/d}/{파일}`)에 있고 같은 발행 게이트를
 * 지나므로, 원본 수(`count`)와 순회(`chunk`)가 페이지에 이어진 첨부 행과 본문 미디어 행을 한 질의로 한 번씩 센다.
 * 본문 미디어 제공자를 따로 두면 같은 행과 같은 파생본을 두 제공자가 센다.
 *
 * 디스크는 고정하지 않는다 — 디스크를 전환한 사이트에서는 행마다 기록된 디스크가 다르고, 옛 디스크
 * 원본의 파생본도 이 제공자 분류(권한 쪽)로 세고 지워야 한다. 경로 첫 조각이 모듈 식별자라 다른
 * 원본과 겹치지 않는다.
 */
class AttachmentThumbnailSourceProvider implements ThumbnailSourceProvider
{
    /** 저장 경로 접두 (모듈 식별자/카테고리) */
    private const PATH_PREFIX = 'sirsoft-page/attachments/';

    /**
     * AttachmentThumbnailSourceProvider 생성자
     *
     * @param  PageAttachmentRepositoryInterface  $repository  첨부 리포지토리
     * @param  PageAttachmentService  $attachmentService  첨부 서비스 (행 disk 기준 원본 정보)
     */
    public function __construct(
        private readonly PageAttachmentRepositoryInterface $repository,
        private readonly PageAttachmentService $attachmentService
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'sirsoft-page.attachment';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'sirsoft-page::messages.attachment.thumbnail_source';
    }

    /**
     * {@inheritDoc}
     */
    public function gated(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function matches(string $disk, string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (! str_starts_with($path, self::PATH_PREFIX)) {
            return false;
        }

        $rest = substr($path, strlen(self::PATH_PREFIX));

        return $rest !== ''
            && ! str_starts_with($rest, 'temp/')
            && config("filesystems.disks.{$disk}") !== null;
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

        $items = $rows
            ->map(fn (PageAttachment $row) => $this->attachmentService->thumbnailSourceFor($row))
            ->filter()
            ->values()
            ->all();

        return [
            'items' => $items,
            'next' => $rows->count() < $limit ? null : (string) $rows->last()?->id,
        ];
    }
}
