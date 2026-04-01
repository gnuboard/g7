<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;

/**
 * 배송정책 Repository 인터페이스
 */
interface ShippingPolicyRepositoryInterface
{
    /**
     * ID로 배송정책 조회
     *
     * @param int $id 배송정책 ID
     * @return ShippingPolicy|null
     */
    public function find(int $id): ?ShippingPolicy;

    /**
     * 필터링된 배송정책 목록 조회 (페이지네이션)
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 배송정책 생성
     *
     * @param array $data 배송정책 데이터
     * @return ShippingPolicy
     */
    public function create(array $data): ShippingPolicy;

    /**
     * 배송정책 수정
     *
     * @param ShippingPolicy $shippingPolicy 배송정책 모델
     * @param array $data 수정 데이터
     * @return ShippingPolicy
     */
    public function update(ShippingPolicy $shippingPolicy, array $data): ShippingPolicy;

    /**
     * 배송정책 삭제
     *
     * @param ShippingPolicy $shippingPolicy 배송정책 모델
     * @return bool
     */
    public function delete(ShippingPolicy $shippingPolicy): bool;

    /**
     * 배송정책 사용여부 토글
     *
     * @param ShippingPolicy $shippingPolicy 배송정책 모델
     * @return ShippingPolicy
     */
    public function toggleActive(ShippingPolicy $shippingPolicy): ShippingPolicy;

    /**
     * 배송정책 일괄 삭제
     *
     * @param array $ids 배송정책 ID 배열
     * @return int 삭제된 개수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 배송정책 일괄 사용여부 변경
     *
     * @param array $ids 배송정책 ID 배열
     * @param bool $isActive 사용여부
     * @return int 변경된 개수
     */
    public function bulkToggleActive(array $ids, bool $isActive): int;

    /**
     * 배송정책 통계 조회
     *
     * @return array 배송정책 통계 데이터
     */
    public function getStatistics(): array;

    /**
     * 활성화된 배송정책 목록 조회 (Select 옵션용)
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveList(): \Illuminate\Database\Eloquent\Collection;

    /**
     * 기본 배송정책 해제
     *
     * @param int|null $exceptId 제외할 배송정책 ID
     * @return int 변경된 개수
     */
    public function clearDefault(?int $exceptId = null): int;
}
