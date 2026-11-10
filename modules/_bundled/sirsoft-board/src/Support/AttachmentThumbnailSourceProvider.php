<?php

namespace Modules\Sirsoft\Board\Support;

use App\Helpers\InlineMediaPolicy;
use App\Thumbnails\Contracts\ThumbnailSourceProvider;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Services\AttachmentService;

/**
 * 게시판 첨부(`/api/modules/sirsoft-board/boards/{slug}/attachment/{hash}/preview`) 썸네일 원본 제공자 — 게이트.
 *
 * 게시판 첨부는 비밀글·삭제글·블라인드 글 게이트를 지나는 원본이라 파생본은 비공개 썸네일 디스크에 둔다.
 * 저장 경로는 모듈 드라이버 기준 `sirsoft-board/attachments/{slug}/{Y/m/d}/{파일}` 이고, 임시 첨부
 * (`{slug}/temp/{key}/…`)는 글에 이어질 때 본경로로 옮겨지므로 제외한다.
 *
 * 디스크는 고정하지 않는다 — 디스크를 전환한 사이트에서는 행마다 기록된 디스크가 다르고, 옛 디스크
 * 원본의 파생본도 이 제공자 분류(권한 쪽)로 세고 지워야 한다. 경로 첫 조각이 모듈 식별자라 다른
 * 원본과 겹치지 않는다.
 */
class AttachmentThumbnailSourceProvider implements ThumbnailSourceProvider
{
    /** 저장 경로 접두 (모듈 식별자/카테고리) */
    private const PATH_PREFIX = 'sirsoft-board/attachments/';

    /**
     * AttachmentThumbnailSourceProvider 생성자
     *
     * @param  AttachmentRepositoryInterface  $repository  첨부 리포지토리
     * @param  AttachmentService  $attachmentService  첨부 서비스 (행 disk 기준 원본 정보)
     */
    public function __construct(
        private readonly AttachmentRepositoryInterface $repository,
        private readonly AttachmentService $attachmentService
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'sirsoft-board.attachment';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'sirsoft-board::messages.attachment.thumbnail_source';
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

        // {slug}/{나머지} — 두 번째 조각이 temp 면 임시 첨부
        $segments = explode('/', substr($path, strlen(self::PATH_PREFIX)));

        return count($segments) >= 2
            && $segments[0] !== ''
            && $segments[1] !== 'temp'
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
            ->map(fn (Attachment $row) => $this->attachmentService->thumbnailSourceFor($row))
            ->filter()
            ->values()
            ->all();

        return [
            'items' => $items,
            'next' => $rows->count() < $limit ? null : (string) $rows->last()?->id,
        ];
    }
}
