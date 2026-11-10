<?php

namespace Modules\Sirsoft\Ecommerce\Thumbnails;

use App\Thumbnails\DTO\ThumbnailSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;

/**
 * 상품 이미지(`/api/modules/sirsoft-ecommerce/product-image/{hash}`) 썸네일 원본 제공자 — 공개.
 *
 * 저장 경로는 `products/{상품코드}/{uuid}.{ext}` 이고, 상품 저장 전 임시 업로드는 `products/temp/{키}/…` 다.
 * 임시 업로드는 저장 때 본경로로 옮겨지므로 미리 만들 대상이 아니다.
 */
class ProductImageSourceProvider extends EcommerceImageSourceProvider
{
    /** 상품 이미지 리포지토리 — 처음 쓸 때 해석한다 */
    private ?ProductImageRepositoryInterface $repository = null;

    /** 상품 이미지 서비스 — 처음 쓸 때 해석한다 */
    private ?ProductImageService $service = null;

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'sirsoft-ecommerce.product_image';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'sirsoft-ecommerce::messages.thumbnail_sources.product_image';
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
    protected function pathPrefix(): string
    {
        return 'products/';
    }

    /**
     * {@inheritDoc}
     */
    protected function excludedPrefixes(): array
    {
        return ['products/temp/'];
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
     * 상품 이미지 리포지토리 (처음 쓸 때 해석)
     *
     * 제공자 목록은 저장소 쓰기마다 경로 분류에 불린다. 분류는 경로만 보므로, 세고 읽을 때(count·chunk)까지
     * 리포지토리·서비스를 만들지 않는다.
     *
     * @return ProductImageRepositoryInterface 리포지토리
     */
    private function repository(): ProductImageRepositoryInterface
    {
        return $this->repository ??= app(ProductImageRepositoryInterface::class);
    }

    /**
     * 상품 이미지 서비스 (처음 쓸 때 해석 — 행 disk 기준 원본 경로)
     *
     * @return ProductImageService 서비스
     */
    private function service(): ProductImageService
    {
        return $this->service ??= app(ProductImageService::class);
    }
}
