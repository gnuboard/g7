<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Helpers\InlineMediaPolicy;
use App\Thumbnails\Contracts\ThumbnailSourceProvider;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;

/**
 * 배너 이미지(`/api/plugins/sirsoft-hero_section/images/{hash}`) 썸네일 원본 제공자 — 완전 공개.
 *
 * 배너 이미지는 사이트 첫 화면에 인증 없이 보이고, 해시만 알면 누구나 받는다(원본 응답도 `public` 1년 캐시). 그래서 파생본은
 * 공개 썸네일 디스크에 두고, 그 디스크가 공개 자산 디스크(CDN 등)면 302 로 직접 주소를 알려 준다. PC·모바일 이미지는 기기별로
 * 따로 올린 각자의 원본이라 행마다 하나의 원본으로 다룬다.
 *
 * 원본 정보는 서빙 서비스(`BannerImageService::thumbnailSource`)가 만든다 — 원본 응답과 같은 스토리지를 따라야 파생본 키가
 * 저장소 계층의 무효화 경로와 어긋나지 않는다.
 */
class BannerImageThumbnailSourceProvider implements ThumbnailSourceProvider
{
    /** 플러그인 식별자 — 플러그인 스토리지 드라이버가 모든 디스크에서 전체 경로 앞에 붙이는 접두 */
    public const IDENTIFIER = 'sirsoft-hero_section';

    /** 배너 이미지·영상을 쓰는 카테고리 — 이미지 서비스가 이 카테고리에만 쓴다 */
    public const CATEGORY = 'images';

    /** @var list<string> 원본으로 받는 래스터 확장자 — 같은 카테고리의 영상(mp4·webm)은 원본이 아니다 */
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * BannerImageThumbnailSourceProvider 생성자
     *
     * @param  BannerImageRepositoryInterface  $images  이미지 Repository
     * @param  BannerImageService  $serveService  원본 정보를 만드는 서빙 서비스
     */
    public function __construct(
        private readonly BannerImageRepositoryInterface $images,
        private readonly BannerImageService $serveService,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return self::IDENTIFIER.'.banner_image';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'sirsoft-hero_section::messages.thumbnail.source_label';
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
     * 디스크 이름으로 거르지 않는다 — 배너 이미지는 기본 디스크와 운영자가 고른 디스크 어디에나 있을 수 있다. 대신 플러그인 스토리지
     * 드라이버가 어느 디스크에 쓰든 붙이는 `sirsoft-hero_section/images/` 접두와 래스터 확장자로 가른다.
     *
     * 임시 업로드도 같은 경로(`images/Y/m/{해시}.{확장자}`)에 있어 경로로는 가를 수 없다. 임시 업로드도 저장 전 미리보기를 위해
     * 같은 해시 주소로 공개되므로 같은 공개 분류가 맞다. 기존 이미지 썸네일 만들기(`count`·`chunk`)만 배너에 붙은 이미지로 좁힌다.
     */
    public function matches(string $disk, string $path): bool
    {
        $segments = explode('/', ltrim($path, '/'), 3);

        if (count($segments) !== 3 || $segments[0] !== self::IDENTIFIER || $segments[1] !== self::CATEGORY || $segments[2] === '') {
            return false;
        }

        return in_array(strtolower(pathinfo($segments[2], PATHINFO_EXTENSION)), self::RASTER_EXTENSIONS, true);
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return $this->images->countAttachedImages(InlineMediaPolicy::RASTER_IMAGE_MIME_TYPES);
    }

    /**
     * {@inheritDoc}
     */
    public function chunk(?string $cursor, int $limit): array
    {
        $rows = $this->images->chunkAttachedImages(InlineMediaPolicy::RASTER_IMAGE_MIME_TYPES, (int) $cursor, $limit);

        return [
            'items' => $rows
                ->map(fn (BannerImage $row) => $this->serveService->thumbnailSource($row))
                ->filter()
                ->values()
                ->all(),
            'next' => $rows->count() < max(1, $limit) ? null : (string) $rows->last()?->id,
        ];
    }
}
