<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;

/**
 * 사용자 배송지 Repository 인터페이스
 */
interface UserAddressRepositoryInterface
{
    /**
     * ID로 배송지 조회
     *
     * @param int $id 배송지 ID
     * @return UserAddress|null
     */
    public function find(int $id): ?UserAddress;

    /**
     * 배송지 생성
     *
     * @param array $data 배송지 데이터
     * @return UserAddress
     */
    public function create(array $data): UserAddress;

    /**
     * 배송지 수정
     *
     * @param UserAddress $address 배송지 모델
     * @param array $data 수정 데이터
     * @return UserAddress
     */
    public function update(UserAddress $address, array $data): UserAddress;

    /**
     * 배송지 삭제
     *
     * @param UserAddress $address 배송지 모델
     * @return bool
     */
    public function delete(UserAddress $address): bool;

    /**
     * 회원의 배송지 목록 조회
     *
     * @param int $userId 회원 ID
     * @return Collection
     */
    public function findByUserId(int $userId): Collection;

    /**
     * 회원의 기본 배송지 조회
     *
     * @param int $userId 회원 ID
     * @return UserAddress|null
     */
    public function findDefaultByUserId(int $userId): ?UserAddress;

    /**
     * 기본 배송지 설정
     *
     * @param int $userId 회원 ID
     * @param int $addressId 배송지 ID
     * @return bool
     */
    public function setDefault(int $userId, int $addressId): bool;

    /**
     * 회원의 배송지 개수 조회
     *
     * @param int $userId 회원 ID
     * @return int 배송지 개수
     */
    public function countByUserId(int $userId): int;

    /**
     * 회원의 특정 배송지 조회 (소유권 확인)
     *
     * @param int $userId 회원 ID
     * @param int $addressId 배송지 ID
     * @return UserAddress|null
     */
    public function findByUserIdAndId(int $userId, int $addressId): ?UserAddress;

    /**
     * 사용자 ID와 배송지명으로 배송지 조회
     *
     * @param int $userId 사용자 ID
     * @param string $name 배송지명
     * @return UserAddress|null
     */
    public function findByUserIdAndName(int $userId, string $name): ?UserAddress;
}
