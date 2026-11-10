<?php

namespace Plugins\Sirsoft\Ckeditor5\Services;

use App\Helpers\InlineMediaPolicy;
use App\Thumbnails\Contracts\ThumbnailSourceProvider;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;

/**
 * 본문 이미지(`/api/plugins/sirsoft-ckeditor5/images/{hash}`) 썸네일 원본 제공자 — 완전 공개.
 *
 * 편집기로 올린 이미지는 게시판 글·상품 설명·페이지 본문에 박혀 인증 없이 보인다. 그래서 파생본은
 * 공개 썸네일 디스크에 두고, 그 디스크가 공개 자산 디스크(CDN 등)면 302 로 직접 주소를 알려 준다.
 *
 * 원본 정보는 서빙 서비스(`ImageServeService::thumbnailSource`)가 만든다 — 행 disk·고아 disk 폴백을
 * 원본 응답과 같은 규칙으로 따라야 파생본 키가 저장소 계층의 무효화 경로와 어긋나지 않는다.
 */
class ImageThumbnailSourceProvider implements ThumbnailSourceProvider
{
    /** 플러그인 식별자 — 플러그인 스토리지 드라이버가 모든 디스크에서 전체 경로 앞에 붙이는 접두 */
    public const IDENTIFIER = 'sirsoft-ckeditor5';

    /** 본문 이미지를 쓰는 카테고리 — 업로드 서비스가 이 카테고리에만 쓴다 */
    public const CATEGORY = 'images';

    /**
     * ImageThumbnailSourceProvider 생성자
     *
     * @param  ImageUploadRepositoryInterface  $repository  이미지 업로드 리포지토리
     * @param  ImageServeService  $serveService  원본 정보를 만드는 서빙 서비스
     */
    public function __construct(
        private readonly ImageUploadRepositoryInterface $repository,
        private readonly ImageServeService $serveService
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return self::IDENTIFIER.'.image';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'sirsoft-ckeditor5::messages.thumbnail.source_label';
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
     *
     * 디스크 이름으로 거르지 않는다 — 본문 이미지는 기본 디스크(`plugins`)와 운영자가 고른 공개 자산 디스크
     * 어디에나 있을 수 있고, 디스크를 바꾸기 전 행도 옛 디스크에 남는다. 대신 플러그인 스토리지 드라이버가
     * 어느 디스크에 쓰든 붙이는 `sirsoft-ckeditor5/` 접두가 이 플러그인의 파일임을 가른다.
     *
     * 카테고리는 본문 이미지를 쓰는 `images` 하나만 받는다. 제외 목록(임시·설정)으로 거르면 뒤에 생기는
     * 카테고리가 — 권한이 걸린 파일이라도 — 저절로 공개 원본으로 분류돼 파생본이 공개 썸네일 디스크로 간다.
     */
    public function matches(string $disk, string $path): bool
    {
        $segments = explode('/', ltrim($path, '/'), 3);

        return count($segments) === 3
            && $segments[0] === self::IDENTIFIER
            && $segments[1] === self::CATEGORY
            && $segments[2] !== '';
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
            'items' => $rows
                ->map(fn (Ckeditor5ImageUpload $row) => $this->serveService->thumbnailSource($row))
                ->filter()
                ->values()
                ->all(),
            'next' => $rows->count() < $limit ? null : (string) $rows->last()?->id,
        ];
    }
}
