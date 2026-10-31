<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;

/**
 * 클레임 첨부 이미지 리포지토리 인터페이스
 */
interface ClaimImageRepositoryInterface
{
    /**
     * 첨부 이미지를 생성합니다.
     *
     * @param  array  $data  첨부 이미지 데이터
     * @return ClaimImage 생성된 첨부 이미지
     */
    public function create(array $data): ClaimImage;

    /**
     * ID로 첨부 이미지를 조회합니다.
     *
     * @param  int  $id  첨부 이미지 ID
     * @return ClaimImage|null 첨부 이미지 (없으면 null)
     */
    public function findById(int $id): ?ClaimImage;

    /**
     * 해시로 첨부 이미지를 조회합니다.
     *
     * @param  string  $hash  URL용 고유 해시
     * @return ClaimImage|null 첨부 이미지 (없으면 null)
     */
    public function findByHash(string $hash): ?ClaimImage;

    /**
     * 클레임의 첨부 이미지 목록을 정렬 순서대로 조회합니다.
     *
     * @param  string  $claimableType  클레임 유형 (exchange, return, refund)
     * @param  int  $claimableId  클레임 ID
     * @return Collection 첨부 이미지 목록
     */
    public function listByClaimable(string $claimableType, int $claimableId): Collection;

    /**
     * 클레임의 첨부 이미지 개수를 반환합니다.
     *
     * 장수 상한(`claim.max_images`) 검증의 근거입니다.
     *
     * @param  string  $claimableType  클레임 유형
     * @param  int  $claimableId  클레임 ID
     * @return int 첨부 이미지 개수
     */
    public function countByClaimable(string $claimableType, int $claimableId): int;

    /**
     * 첨부 이미지를 삭제합니다.
     *
     * 실제 파일 삭제는 첨부 서비스가 StorageInterface 로 수행합니다.
     *
     * @param  ClaimImage  $image  첨부 이미지
     * @return bool 삭제 성공 여부
     */
    public function delete(ClaimImage $image): bool;

    /**
     * 주문에 매달린 미귀속(신청서 작성 중) 첨부를 조회합니다.
     *
     * 신청서가 만들어지기 전에 올린 이미지는 `claimable_type=pending` · `claimable_id=주문 ID` 로
     * 주문에 묶어 둔다 — 업로더 없는 이미지가 어느 주문의 것인지 판정할 근거가 이것뿐이다(비회원).
     *
     * @param  int  $orderId  주문 ID
     * @return Collection<int, ClaimImage> 미귀속 첨부 목록
     */
    public function listPendingByOrder(int $orderId): Collection;

    /**
     * 기준 시각보다 오래된 미귀속(pending) 첨부를 조회합니다. (정리 배치용, 오래된 순)
     *
     * 귀속된 첨부는 `claimable_type` 이 클레임 유형 값으로 바뀌므로 이 조회에 걸리지 않는다 —
     * 걸리는 것은 신청으로 이어지지 않은 채 보존 기간이 지난 임시 업로드뿐이다.
     *
     * @param  Carbon  $cutoff  기준 시각 (이보다 오래된 것만)
     * @param  int  $limit  최대 건수
     * @return Collection<int, ClaimImage> 미귀속 첨부 목록
     */
    public function listPendingOlderThan(Carbon $cutoff, int $limit): Collection;

    /**
     * ID 목록으로 첨부를 조회합니다.
     *
     * @param  array<int, int>  $ids  첨부 ID 목록
     * @return Collection<int, ClaimImage> 첨부 목록
     */
    public function listByIds(array $ids): Collection;

    /**
     * 첨부를 수정합니다.
     *
     * @param  ClaimImage  $image  첨부
     * @param  array  $data  수정 데이터
     * @return ClaimImage 수정된 첨부
     */
    public function update(ClaimImage $image, array $data): ClaimImage;
}
