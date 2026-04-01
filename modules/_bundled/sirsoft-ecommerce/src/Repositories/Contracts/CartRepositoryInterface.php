<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\Cart;

/**
 * 장바구니 Repository 인터페이스
 */
interface CartRepositoryInterface
{
    /**
     * ID로 장바구니 아이템 조회
     *
     * @param int $id 장바구니 ID
     * @return Cart|null
     */
    public function find(int $id): ?Cart;

    /**
     * 장바구니 아이템 생성
     *
     * @param array $data 장바구니 데이터
     * @return Cart
     */
    public function create(array $data): Cart;

    /**
     * 장바구니 아이템 수정
     *
     * @param Cart $cart 장바구니 모델
     * @param array $data 수정 데이터
     * @return Cart
     */
    public function update(Cart $cart, array $data): Cart;

    /**
     * 장바구니 아이템 삭제
     *
     * @param Cart $cart 장바구니 모델
     * @return bool
     */
    public function delete(Cart $cart): bool;

    /**
     * 회원 ID로 장바구니 조회 (상품/옵션 관계 포함)
     *
     * @param int $userId 회원 ID
     * @return Collection
     */
    public function findByUserId(int $userId): Collection;

    /**
     * cart_key로 비회원 장바구니 조회 (user_id가 null인 것만)
     *
     * @param string $cartKey 비회원 장바구니 키
     * @return Collection
     */
    public function findByCartKeyWithoutUser(string $cartKey): Collection;

    /**
     * 회원 ID와 옵션 ID로 장바구니 아이템 조회
     *
     * @param int $userId 회원 ID
     * @param int $productOptionId 상품 옵션 ID
     * @return Cart|null
     */
    public function findByUserAndOption(int $userId, int $productOptionId): ?Cart;

    /**
     * cart_key와 옵션 ID로 비회원 장바구니 아이템 조회
     *
     * @param string $cartKey 비회원 장바구니 키
     * @param int $productOptionId 상품 옵션 ID
     * @return Cart|null
     */
    public function findByCartKeyAndOption(string $cartKey, int $productOptionId): ?Cart;

    /**
     * 여러 ID로 장바구니 아이템 조회
     *
     * @param array $ids 장바구니 ID 배열
     * @return Collection
     */
    public function findByIds(array $ids): Collection;

    /**
     * 여러 ID로 장바구니 아이템 삭제
     *
     * @param array $ids 장바구니 ID 배열
     * @return int 삭제된 개수
     */
    public function deleteByIds(array $ids): int;

    /**
     * 회원의 장바구니 전체 삭제
     *
     * @param int $userId 회원 ID
     * @return int 삭제된 개수
     */
    public function deleteByUserId(int $userId): int;

    /**
     * 비회원의 장바구니 전체 삭제
     *
     * @param string $cartKey 비회원 장바구니 키
     * @return int 삭제된 개수
     */
    public function deleteByCartKey(string $cartKey): int;

    /**
     * 장바구니 아이템 수 조회
     *
     * @param int|null $userId 회원 ID (null이면 비회원)
     * @param string|null $cartKey 비회원 장바구니 키
     * @return int 아이템 수
     */
    public function countItems(?int $userId, ?string $cartKey): int;

    /**
     * cart_key 존재 여부 확인
     *
     * @param string $cartKey 비회원 장바구니 키
     * @return bool 존재하면 true
     */
    public function existsByCartKey(string $cartKey): bool;
}
