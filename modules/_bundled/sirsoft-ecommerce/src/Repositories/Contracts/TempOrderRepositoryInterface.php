<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Modules\Sirsoft\Ecommerce\Models\TempOrder;

/**
 * 임시 주문 Repository 인터페이스
 */
interface TempOrderRepositoryInterface
{
    /**
     * ID로 임시 주문 조회
     *
     * @param  int  $id  임시 주문 ID
     * @return TempOrder|null
     */
    public function find(int $id): ?TempOrder;

    /**
     * cart_key로 임시 주문 조회 (비회원)
     *
     * @param  string  $cartKey  비회원 장바구니 키
     * @return TempOrder|null
     */
    public function findByCartKey(string $cartKey): ?TempOrder;

    /**
     * 회원 ID로 임시 주문 조회
     *
     * @param  int  $userId  회원 ID
     * @return TempOrder|null
     */
    public function findByUserId(int $userId): ?TempOrder;

    /**
     * 임시 주문 생성
     *
     * @param  array  $data  임시 주문 데이터
     * @return TempOrder
     */
    public function create(array $data): TempOrder;

    /**
     * 임시 주문 수정
     *
     * @param  TempOrder  $tempOrder  임시 주문 모델
     * @param  array  $data  수정 데이터
     * @return TempOrder
     */
    public function update(TempOrder $tempOrder, array $data): TempOrder;

    /**
     * 임시 주문 삭제
     *
     * @param  TempOrder  $tempOrder  임시 주문 모델
     * @return bool
     */
    public function delete(TempOrder $tempOrder): bool;

    /**
     * 임시 주문 생성 또는 수정 (upsert)
     * 동일 cart_key 또는 user_id가 존재하면 덮어쓰기
     *
     * @param  array  $data  임시 주문 데이터
     * @return TempOrder
     */
    public function upsert(array $data): TempOrder;

    /**
     * 만료된 임시 주문 삭제
     *
     * @return int 삭제된 개수
     */
    public function deleteExpired(): int;

    /**
     * cart_key로 임시 주문 삭제
     *
     * @param  string  $cartKey  비회원 장바구니 키
     * @return bool
     */
    public function deleteByCartKey(string $cartKey): bool;

    /**
     * 회원 ID로 임시 주문 삭제
     *
     * @param  int  $userId  회원 ID
     * @return bool
     */
    public function deleteByUserId(int $userId): bool;

    /**
     * 회원 또는 비회원으로 임시 주문 조회
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return TempOrder|null
     */
    public function findByUserOrCartKey(?int $userId, ?string $cartKey): ?TempOrder;

    /**
     * 만료되지 않은 유효한 임시 주문 조회
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return TempOrder|null
     */
    public function findValidByUserOrCartKey(?int $userId, ?string $cartKey): ?TempOrder;

    /**
     * 회원의 임시 주문 ID 를 모두 조회합니다 (회원 삭제 정리용 — 한 회원에 여러 행이 남아 있을 수 있다).
     *
     * @param  int  $userId  회원 ID
     * @return array<int, int> 임시 주문 ID 목록
     */
    public function getIdsByUserId(int $userId): array;
}
