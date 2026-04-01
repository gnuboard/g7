<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;

/**
 * 클래임 사유 Repository 인터페이스
 */
interface ClaimReasonRepositoryInterface
{
    /**
     * 클래임 사유 목록 조회
     *
     * @param array $filters 필터 조건
     * @param array $with Eager loading 관계
     * @return Collection
     */
    public function getAll(array $filters = [], array $with = []): Collection;

    /**
     * ID로 클래임 사유 조회
     *
     * @param int $id 클래임 사유 ID
     * @param array $with Eager loading 관계
     * @return ClaimReason|null
     */
    public function findById(int $id, array $with = []): ?ClaimReason;

    /**
     * 코드로 클래임 사유 조회
     *
     * @param string $code 사유 코드
     * @param string $type 사유 유형
     * @return ClaimReason|null
     */
    public function findByCode(string $code, string $type = 'refund'): ?ClaimReason;

    /**
     * 클래임 사유 생성
     *
     * @param array $data 사유 데이터
     * @return ClaimReason
     */
    public function create(array $data): ClaimReason;

    /**
     * 클래임 사유 수정
     *
     * @param int $id 사유 ID
     * @param array $data 수정 데이터
     * @return ClaimReason
     */
    public function update(int $id, array $data): ClaimReason;

    /**
     * 클래임 사유 삭제
     *
     * @param int $id 사유 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 코드 중복 확인
     *
     * @param string $code 사유 코드
     * @param string $type 사유 유형
     * @param int|null $excludeId 제외할 사유 ID
     * @return bool
     */
    public function existsByCode(string $code, string $type = 'refund', ?int $excludeId = null): bool;

    /**
     * 활성 클래임 사유 목록 조회
     *
     * @param string $type 사유 유형
     * @return Collection
     */
    public function getActiveReasons(string $type = 'refund'): Collection;

    /**
     * 사용자 선택 가능한 클래임 사유 목록 조회
     *
     * @param string $type 사유 유형
     * @return Collection
     */
    public function getUserSelectableReasons(string $type = 'refund'): Collection;
}
