<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

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
     * 클레임의 첨부 이미지를 모두 삭제합니다.
     *
     * @param  string  $claimableType  클레임 유형
     * @param  int  $claimableId  클레임 ID
     * @return int 삭제된 행 수
     */
    public function deleteByClaimable(string $claimableType, int $claimableId): int;
}
