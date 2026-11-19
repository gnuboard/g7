<?php

namespace Modules\Sirsoft\Ecommerce\Thumbnails;

use App\Thumbnails\DTO\ThumbnailSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ContentMediaRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;

/**
 * 본문 미디어 이미지(`/api/modules/sirsoft-ecommerce/content-media/{hash}`) 썸네일 원본 제공자 — 게이트.
 *
 * 상품 설명·공통 정보 편집기 본문에 넣은 이미지다. 미전시 상품의 본문 미디어는 상품 읽기 권한 관리자와 서명 요청만 볼 수 있고,
 * 전시 중인 상품도 나중에 전시가 중단될 수 있어 상태와 무관하게 권한 원본으로 둔다 — 파생본은 비공개 썸네일 디스크에 있고
 * 서빙 게이트(`ContentMediaService::findServable()`)를 지난 요청에만 나간다.
 *
 * 파일은 모듈 스토리지 `content-media` 카테고리에 있다(`sirsoft-ecommerce/content-media/{owner_type}/Y/m/d/{uuid}.ext`).
 * 다른 이미지 제공자(`images` 카테고리)와 디스크 위 접두가 달라 같은 파일을 두 제공자가 세지 않는다. 업로드한 파일은 처음부터
 * 이 경로에 놓이므로(저장 전에는 행만 임시) 경로로는 임시 여부를 가르지 않고, 「기존 이미지 썸네일 만들기」 대상에서만
 * 소유자에 이어진 행으로 좁힌다(리포지토리). 동영상·오디오 행은 래스터 MIME 조건에서 빠진다.
 */
class ContentMediaSourceProvider extends EcommerceImageSourceProvider
{
    /** 본문 미디어 리포지토리 — 처음 쓸 때 해석한다 */
    private ?ContentMediaRepositoryInterface $repository = null;

    /** 본문 미디어 서비스 — 처음 쓸 때 해석한다 */
    private ?ContentMediaService $service = null;

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'sirsoft-ecommerce.content_media';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'sirsoft-ecommerce::messages.thumbnail_sources.content_media';
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
    protected function storageCategory(): string
    {
        return ContentMedia::STORAGE_CATEGORY;
    }

    /**
     * {@inheritDoc}
     *
     * 카테고리 전체가 본문 미디어다 — 소유 종류(`product/`·`common_info/`)로 더 가르지 않는다.
     */
    protected function pathPrefix(): string
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    protected function countRows(array $mimeTypes): int
    {
        return $this->repository()->countLinkedByMimeTypes($mimeTypes);
    }

    /**
     * {@inheritDoc}
     */
    protected function chunkRows(array $mimeTypes, int $afterId, int $limit): Collection
    {
        return $this->repository()->chunkLinkedByMimeTypes($mimeTypes, $afterId, $limit);
    }

    /**
     * {@inheritDoc}
     *
     * 서빙과 같은 원본 정보(코어 골격 `thumbnailSourceFor()` — 행 디스크·권한 원본)를 쓴다.
     */
    protected function sourceForRow(Model $row): ?ThumbnailSource
    {
        return $this->service()->engine()->thumbnailSourceFor($row);
    }

    /**
     * 본문 미디어 리포지토리 (처음 쓸 때 해석)
     *
     * 제공자 목록은 저장소 쓰기마다 경로 분류에 불린다. 분류는 경로만 보므로, 세고 읽을 때(count·chunk)까지
     * 리포지토리·서비스를 만들지 않는다.
     *
     * @return ContentMediaRepositoryInterface 리포지토리
     */
    private function repository(): ContentMediaRepositoryInterface
    {
        return $this->repository ??= app(ContentMediaRepositoryInterface::class);
    }

    /**
     * 본문 미디어 서비스 (처음 쓸 때 해석 — 행 디스크 기준 원본 경로)
     *
     * @return ContentMediaService 서비스
     */
    private function service(): ContentMediaService
    {
        return $this->service ??= app(ContentMediaService::class);
    }
}
