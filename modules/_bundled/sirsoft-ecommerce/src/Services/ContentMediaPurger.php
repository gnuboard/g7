<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ContentMediaRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ContentMedia\EcommerceContentMediaService;

/**
 * 소유자 삭제에 따른 본문 미디어 영구 삭제 (#887)
 *
 * 상품(영구 삭제)·상품 공통 정보를 지울 때 그 소유의 본문 미디어를 행과 파일째 지운다. 본문에서 빠져 소프트 삭제된 행도
 * 파일이 남아 있으므로 함께 지운다. 종전에는 두 삭제 경로가 본문 미디어 행을 건드리지 않아 행·파일이 영구히 남았다.
 *
 * 지우기는 코어 골격의 `purgeRows` 로 한다 — 행마다 `core.content_media.before_delete` 를 발화하고, 파일은 행에 기록된 디스크에서
 * 커밋된 뒤에 지우며(StorageTransaction), 행은 같은 트랜잭션에서 지운다. 소유자 삭제 트랜잭션 안에서 부르므로 삭제가 되돌아가면
 * 행·파일이 모두 남는다.
 */
class ContentMediaPurger
{
    /**
     * @param  ContentMediaRepositoryInterface  $repository  본문 미디어 리포지토리
     * @param  EcommerceContentMediaService  $media  골격 서비스
     */
    public function __construct(
        private ContentMediaRepositoryInterface $repository,
        private EcommerceContentMediaService $media,
    ) {}

    /**
     * 소유자의 본문 미디어(소프트 삭제 포함)를 행과 파일째 영구 삭제합니다.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  int  $ownerId  소유자 ID
     * @return int 지운 행 수
     */
    public function purgeForOwner(ContentMediaOwnerType $ownerType, int $ownerId): int
    {
        $rows = $this->repository->getByOwnerWithTrashed($ownerType, $ownerId);
        if ($rows->isEmpty()) {
            return 0;
        }

        $purged = $this->media->purgeRows($this->media->ecommerceStore()->context($ownerType, $ownerId), $rows);

        Log::info('이커머스 본문 미디어 소유자 삭제 정리', ['owner_type' => $ownerType->value, 'owner_id' => $ownerId, 'purged' => $purged]);

        return $purged;
    }
}
