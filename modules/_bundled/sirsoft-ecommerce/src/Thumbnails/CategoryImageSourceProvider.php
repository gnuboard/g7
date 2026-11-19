<?php

namespace Modules\Sirsoft\Ecommerce\Thumbnails;

use App\Thumbnails\DTO\ThumbnailSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;

/**
 * 카테고리 이미지(`/api/modules/sirsoft-ecommerce/category-image/{hash}`) 썸네일 원본 제공자 — 공개.
 *
 * 저장 경로는 `category/{Y/m/d}/{uuid}.{ext}` 다. 저장 전 임시 업로드도 같은 경로에 쓰이므로(`temp_key` 로만
 * 구분) 경로로는 가르지 않고, 미리 만들기 대상에서만 카테고리에 연결된 행으로 좁힌다(리포지토리).
 */
class CategoryImageSourceProvider extends EcommerceImageSourceProvider
{
    /** 카테고리 이미지 리포지토리 — 처음 쓸 때 해석한다 */
    private ?CategoryImageRepositoryInterface $repository = null;

    /** 카테고리 이미지 서비스 — 처음 쓸 때 해석한다 */
    private ?CategoryImageService $service = null;

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'sirsoft-ecommerce.category_image';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'sirsoft-ecommerce::messages.thumbnail_sources.category_image';
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
        return 'category/';
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
     * 카테고리 이미지 리포지토리 (처음 쓸 때 해석)
     *
     * 제공자 목록은 저장소 쓰기마다 경로 분류에 불린다. 분류는 경로만 보므로, 세고 읽을 때(count·chunk)까지
     * 리포지토리·서비스를 만들지 않는다.
     *
     * @return CategoryImageRepositoryInterface 리포지토리
     */
    private function repository(): CategoryImageRepositoryInterface
    {
        return $this->repository ??= app(CategoryImageRepositoryInterface::class);
    }

    /**
     * 카테고리 이미지 서비스 (처음 쓸 때 해석 — 행 disk 기준 원본 경로)
     *
     * @return CategoryImageService 서비스
     */
    private function service(): CategoryImageService
    {
        return $this->service ??= app(CategoryImageService::class);
    }
}
