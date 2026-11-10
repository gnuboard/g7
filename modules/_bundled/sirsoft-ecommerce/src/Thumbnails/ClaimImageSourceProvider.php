<?php

namespace Modules\Sirsoft\Ecommerce\Thumbnails;

use App\Thumbnails\DTO\ThumbnailSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;

/**
 * 클레임 첨부(`/api/modules/sirsoft-ecommerce/claim-image/{hash}`) 썸네일 원본 제공자 — 게이트.
 *
 * 교환·반품·환불 증빙 이미지는 한시 서명·신청자 본인·운영자만 여는 게이트 원본이라 파생본도 비공개 썸네일
 * 디스크에 둔다. 저장 경로는 `claims/{유형}/{ID}/{uuid}.{ext}` 다. 신청서 작성 중 이미지(`claims/pending/…`)도
 * 신청 화면이 보여 주는 실제 첨부이므로 대상에 넣는다.
 */
class ClaimImageSourceProvider extends EcommerceImageSourceProvider
{
    /** 클레임 첨부 리포지토리 — 처음 쓸 때 해석한다 */
    private ?ClaimImageRepositoryInterface $repository = null;

    /** 클레임 첨부 서비스 — 처음 쓸 때 해석한다 */
    private ?ClaimAttachmentService $service = null;

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'sirsoft-ecommerce.claim_image';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'sirsoft-ecommerce::messages.thumbnail_sources.claim_image';
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
        return 'claims/';
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
     * 클레임 첨부 리포지토리 (처음 쓸 때 해석)
     *
     * 제공자 목록은 저장소 쓰기마다 경로 분류에 불린다. 분류는 경로만 보므로, 세고 읽을 때(count·chunk)까지
     * 리포지토리·서비스를 만들지 않는다.
     *
     * @return ClaimImageRepositoryInterface 리포지토리
     */
    private function repository(): ClaimImageRepositoryInterface
    {
        return $this->repository ??= app(ClaimImageRepositoryInterface::class);
    }

    /**
     * 클레임 첨부 서비스 (처음 쓸 때 해석 — 행 disk 기준 원본 경로)
     *
     * @return ClaimAttachmentService 서비스
     */
    private function service(): ClaimAttachmentService
    {
        return $this->service ??= app(ClaimAttachmentService::class);
    }
}
