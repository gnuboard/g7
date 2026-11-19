<?php

namespace Modules\Sirsoft\Ecommerce\Thumbnails;

use App\Thumbnails\DTO\ThumbnailSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;

/**
 * 리뷰 이미지(`/api/modules/sirsoft-ecommerce/review-image/{hash}`) 썸네일 원본 제공자 — 게이트.
 *
 * 리뷰 이미지는 숨김(HIDDEN) 상태 게이트와 한시 서명이 걸린 원본이다. 원본이 공개 자산 디스크(CDN)에 있어도
 * 파생본은 비공개 썸네일 디스크에 둔다(설계 결정 D25). 저장 경로는 `reviews/{리뷰 ID}/{uuid}.{ext}` 다.
 */
class ReviewImageSourceProvider extends EcommerceImageSourceProvider
{
    /** 리뷰 이미지 리포지토리 — 처음 쓸 때 해석한다 */
    private ?ProductReviewImageRepositoryInterface $repository = null;

    /** 리뷰 이미지 서비스 — 처음 쓸 때 해석한다 */
    private ?ProductReviewImageService $service = null;

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'sirsoft-ecommerce.review_image';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'sirsoft-ecommerce::messages.thumbnail_sources.review_image';
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
    protected function pathPrefix(): string
    {
        return 'reviews/';
    }

    /**
     * {@inheritDoc}
     */
    protected function countRows(array $mimeTypes): int
    {
        return $this->repository()->countByMimeTypes($mimeTypes);
    }

    /**
     * {@inheritDoc}
     */
    protected function chunkRows(array $mimeTypes, int $afterId, int $limit): Collection
    {
        return $this->repository()->chunkByMimeTypes($mimeTypes, $afterId, $limit);
    }

    /**
     * {@inheritDoc}
     */
    protected function sourceForRow(Model $row): ?ThumbnailSource
    {
        return $this->service()->thumbnailSourceFor($row);
    }

    /**
     * 리뷰 이미지 리포지토리 (처음 쓸 때 해석)
     *
     * 제공자 목록은 저장소 쓰기마다 경로 분류에 불린다. 분류는 경로만 보므로, 세고 읽을 때(count·chunk)까지
     * 리포지토리·서비스를 만들지 않는다.
     *
     * @return ProductReviewImageRepositoryInterface 리포지토리
     */
    private function repository(): ProductReviewImageRepositoryInterface
    {
        return $this->repository ??= app(ProductReviewImageRepositoryInterface::class);
    }

    /**
     * 리뷰 이미지 서비스 (처음 쓸 때 해석 — 행 disk 기준 원본 경로)
     *
     * @return ProductReviewImageService 서비스
     */
    private function service(): ProductReviewImageService
    {
        return $this->service ??= app(ProductReviewImageService::class);
    }
}
