<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;

/**
 * 배송유형 Repository 인터페이스
 */
interface ShippingTypeRepositoryInterface
{
    /**
     * 배송유형 목록 조회
     *
     * @param  array  $filters  필터 조건
     * @param  array  $with  Eager loading 관계
     * @return Collection 배송유형 컬렉션
     */
    public function getAll(array $filters = [], array $with = []): Collection;

    /**
     * ID로 배송유형 조회
     *
     * @param  int  $id  배송유형 ID
     * @param  array  $with  Eager loading 관계
     * @return ShippingType|null 배송유형 모델 (없으면 null)
     */
    public function findById(int $id, array $with = []): ?ShippingType;

    /**
     * 배송유형 생성
     *
     * @param  array  $data  배송유형 데이터
     * @return ShippingType 생성된 배송유형 모델
     */
    public function create(array $data): ShippingType;

    /**
     * 배송유형 수정
     *
     * @param  int  $id  배송유형 ID
     * @param  array  $data  수정 데이터
     * @return ShippingType 수정된 배송유형 모델
     */
    public function update(int $id, array $data): ShippingType;

    /**
     * 배송유형 삭제
     *
     * @param  int  $id  배송유형 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool;

    /**
     * 코드 중복 확인
     *
     * @param  string  $code  배송유형 코드
     * @param  int|null  $excludeId  제외할 배송유형 ID
     * @return bool 코드 존재 여부
     */
    public function existsByCode(string $code, ?int $excludeId = null): bool;

    /**
     * 활성 배송유형 목록 조회
     *
     * @param  string|null  $category  카테고리 필터 (domestic, international, other, null=전체)
     * @return Collection 활성 배송유형 컬렉션
     */
    public function getActiveTypes(?string $category = null): Collection;

    /**
     * 활성 배송유형 코드 목록 조회
     *
     * @return array<int, string> 활성 배송유형 코드 목록
     */
    public function getActiveCodes(): array;

    /**
     * 카테고리별 우선순위가 가장 높은 활성 배송유형 코드를 조회합니다.
     *
     * @param  string  $category  카테고리 (domestic, international 등)
     * @return string|null 배송유형 코드 (없으면 null)
     */
    public function getFirstActiveCodeByCategory(string $category): ?string;
}
