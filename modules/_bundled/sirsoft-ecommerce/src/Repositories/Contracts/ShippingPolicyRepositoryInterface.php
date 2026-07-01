<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;

/**
 * 배송정책 Repository 인터페이스
 */
interface ShippingPolicyRepositoryInterface
{
    /**
     * ID로 배송정책 조회
     *
     * @param  int  $id  배송정책 ID
     * @return ShippingPolicy|null 조회된 배송정책 (없으면 null)
     */
    public function find(int $id): ?ShippingPolicy;

    /**
     * 필터링된 배송정책 목록 조회 (페이지네이션)
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이션된 배송정책 목록
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 배송정책 생성
     *
     * @param  array  $data  배송정책 데이터
     * @return ShippingPolicy 생성된 배송정책
     */
    public function create(array $data): ShippingPolicy;

    /**
     * 배송정책 수정
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @param  array  $data  수정 데이터
     * @return ShippingPolicy 수정된 배송정책
     */
    public function update(ShippingPolicy $shippingPolicy, array $data): ShippingPolicy;

    /**
     * 배송정책 삭제
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(ShippingPolicy $shippingPolicy): bool;

    /**
     * 배송정책 사용여부 토글
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @return ShippingPolicy 토글된 배송정책
     */
    public function toggleActive(ShippingPolicy $shippingPolicy): ShippingPolicy;

    /**
     * 배송정책 일괄 삭제
     *
     * @param  array  $ids  배송정책 ID 배열
     * @return int 삭제된 개수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 배송정책 일괄 사용여부 변경
     *
     * @param  array  $ids  배송정책 ID 배열
     * @param  bool  $isActive  사용여부
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
     * @return Collection 활성 배송정책 목록
     */
    public function getActiveList(): Collection;

    /**
     * 기본 배송정책 해제
     *
     * @param  int|null  $exceptId  제외할 배송정책 ID
     * @return int 변경된 개수
     */
    public function clearDefault(?int $exceptId = null): int;

    /**
     * 기본 배송정책(is_default=true)을 조회합니다.
     *
     * 상품에 배송정책이 부여되지 않은 경우(shipping_policy_id=null) 런타임 폴백 대상입니다.
     * countrySettings 관계를 함께 로드해 호출처에서 즉시 국가별 설정을 사용할 수 있습니다.
     *
     * @return ShippingPolicy|null 기본 배송정책 (없으면 null)
     */
    public function findDefault(): ?ShippingPolicy;

    /**
     * ID 목록으로 배송정책을 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  배송정책 ID 목록
     * @return Collection ID 키 맵으로 정렬된 배송정책 컬렉션
     */
    public function findByIdsKeyed(array $ids): Collection;

    /**
     * 배송정책 ID 목록에 속한 국가별 설정을 일괄 삭제합니다 (DB CASCADE 비의존 명시적 삭제).
     *
     * @param  array<int, int>  $ids  배송정책 ID 목록
     * @return int 삭제된 국가별 설정 행 수
     */
    public function deleteCountrySettingsByPolicyIds(array $ids): int;
}
