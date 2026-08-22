<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Sirsoft\Ecommerce\Models\Cart;

/**
 * 장바구니 Repository 인터페이스
 */
interface CartRepositoryInterface
{
    /**
     * ID로 장바구니 아이템 조회
     *
     * @param  int  $id  장바구니 ID
     * @return Cart|null 장바구니 모델 또는 null
     */
    public function find(int $id): ?Cart;

    /**
     * 장바구니 아이템 생성
     *
     * @param  array  $data  장바구니 데이터
     * @return Cart 생성된 장바구니 모델
     */
    public function create(array $data): Cart;

    /**
     * 장바구니 아이템 수정
     *
     * @param  Cart  $cart  장바구니 모델
     * @param  array  $data  수정 데이터
     * @return Cart 수정된 장바구니 모델
     */
    public function update(Cart $cart, array $data): Cart;

    /**
     * 장바구니 아이템 삭제
     *
     * @param  Cart  $cart  장바구니 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Cart $cart): bool;

    /**
     * 회원 ID로 장바구니 조회 (상품/옵션 관계 포함)
     *
     * @param  int  $userId  회원 ID
     * @return Collection 장바구니 컬렉션
     */
    public function findByUserId(int $userId): Collection;

    /**
     * cart_key로 비회원 장바구니 조회 (user_id가 null인 것만)
     *
     * @param  string  $cartKey  비회원 장바구니 키
     * @return Collection 장바구니 컬렉션
     */
    public function findByCartKeyWithoutUser(string $cartKey): Collection;

    /**
     * 회원 ID와 옵션 ID로 장바구니 아이템 조회
     *
     * @param  int  $userId  회원 ID
     * @param  int  $productOptionId  상품 옵션 ID
     * @return Cart|null 장바구니 모델 또는 null
     */
    public function findByUserAndOption(int $userId, int $productOptionId): ?Cart;

    /**
     * cart_key와 옵션 ID로 비회원 장바구니 아이템 조회
     *
     * @param  string  $cartKey  비회원 장바구니 키
     * @param  int  $productOptionId  상품 옵션 ID
     * @return Cart|null 장바구니 모델 또는 null
     */
    public function findByCartKeyAndOption(string $cartKey, int $productOptionId): ?Cart;

    /**
     * 회원 ID와 옵션 ID로 동일 옵션 장바구니 아이템 전체 조회
     *
     * 추가옵션 선택이 다르면 별개 행이므로, 합산 판정을 위해 같은 옵션의
     * 모든 행을 반환합니다 (호출부에서 추가옵션 해시로 매칭).
     *
     * @param  int  $userId  회원 ID
     * @param  int  $productOptionId  상품 옵션 ID
     * @return Collection 장바구니 컬렉션
     */
    public function findAllByUserAndOption(int $userId, int $productOptionId): Collection;

    /**
     * 회원 장바구니에서 여러 옵션의 라인을 한 번에 조회해 옵션별로 묶어 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  array<int, int>  $productOptionIds  옵션 ID 목록
     * @return SupportCollection<int, Collection> 옵션 ID ⇒ 장바구니 라인 컬렉션
     */
    public function findAllByUserAndOptions(int $userId, array $productOptionIds): SupportCollection;

    /**
     * cart_key와 옵션 ID로 동일 옵션 비회원 장바구니 아이템 전체 조회
     *
     * @param  string  $cartKey  비회원 장바구니 키
     * @param  int  $productOptionId  상품 옵션 ID
     * @return Collection 장바구니 컬렉션
     */
    public function findAllByCartKeyAndOption(string $cartKey, int $productOptionId): Collection;

    /**
     * 여러 ID로 장바구니 아이템 조회
     *
     * @param  array  $ids  장바구니 ID 배열
     * @return Collection 장바구니 컬렉션
     */
    public function findByIds(array $ids): Collection;

    /**
     * 여러 ID로 장바구니 아이템 삭제
     *
     * @param  array  $ids  장바구니 ID 배열
     * @return int 삭제된 개수
     */
    public function deleteByIds(array $ids): int;

    /**
     * 회원의 장바구니 전체 삭제
     *
     * @param  int  $userId  회원 ID
     * @return int 삭제된 개수
     */
    public function deleteByUserId(int $userId): int;

    /**
     * 비회원의 장바구니 전체 삭제
     *
     * @param  string  $cartKey  비회원 장바구니 키
     * @return int 삭제된 개수
     */
    public function deleteByCartKey(string $cartKey): int;

    /**
     * 장바구니 아이템 수 조회
     *
     * @param  int|null  $userId  회원 ID (null이면 비회원)
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return int 아이템 수
     */
    public function countItems(?int $userId, ?string $cartKey): int;

    /**
     * cart_key 존재 여부 확인
     *
     * @param  string  $cartKey  비회원 장바구니 키
     * @return bool 존재하면 true
     */
    public function existsByCartKey(string $cartKey): bool;

    /**
     * 특정 상품(product_id)의 장바구니 총수량을 합산합니다.
     *
     * 동일 상품의 모든 옵션 라인 수량을 합산합니다 (구매수량 한도 검증용).
     *
     * @param  int  $productId  상품 ID
     * @param  int|null  $userId  회원 ID (null이면 비회원)
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @param  int|null  $excludeCartId  합산에서 제외할 장바구니 ID (자기 라인 제외용)
     * @return int 총수량
     */
    public function sumQuantityByProduct(int $productId, ?int $userId, ?string $cartKey, ?int $excludeCartId = null): int;

    /**
     * 회원 장바구니에서 여러 상품의 수량 합계를 한 번에 조회합니다.
     *
     * 여러 항목을 한꺼번에 다루는 경로(비회원 장바구니 병합 등)에서 항목마다 합계를
     * 조회하면 항목 수만큼 쿼리가 납니다. 시작 시점의 합계를 한 번 읽어 오고, 이후
     * 변동분은 호출자가 메모리에서 누적합니다.
     *
     * @param  array<int, int>  $productIds  상품 ID 목록
     * @param  int  $userId  회원 ID
     * @return array<int, int> 상품 ID ⇒ 수량 합계 (없는 상품은 키 자체가 없음)
     */
    public function sumQuantityByProducts(array $productIds, int $userId): array;

    /**
     * 보관기간이 지난 장바구니 항목을 삭제합니다 (마지막 활동 updated_at 기준).
     *
     * 마지막 활동(담기/수량변경/옵션변경) 이후 $days 일이 경과한 항목을 삭제합니다.
     * $days < 1 이면 만료 비활성 정책으로 간주하여 한 건도 삭제하지 않습니다
     * (전체 삭제 사고 차단).
     *
     * @param  int  $days  보관 일수
     * @param  int|null  $limit  한 번에 삭제할 최대 행 수 (null = 제한 없음)
     * @return int 삭제된 행 수
     */
    public function pruneExpiredItems(int $days, ?int $limit = null): int;
}
