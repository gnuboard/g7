<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyClaimAddress;

/**
 * 배송정책 클레임 회수지 리포지토리 인터페이스
 */
interface ShippingPolicyClaimAddressRepositoryInterface
{
    /**
     * 배송정책 × 클레임 유형의 회수지를 조회합니다.
     *
     * @param  int  $shippingPolicyId  배송정책 ID
     * @param  string  $claimType  클레임 유형 (exchange, return)
     * @return ShippingPolicyClaimAddress|null 회수지 (미등록이면 null)
     */
    public function findByPolicyAndType(int $shippingPolicyId, string $claimType): ?ShippingPolicyClaimAddress;

    /**
     * 배송정책의 회수지 목록을 조회합니다.
     *
     * @param  int  $shippingPolicyId  배송정책 ID
     * @return Collection 회수지 목록
     */
    public function listByPolicy(int $shippingPolicyId): Collection;

    /**
     * 배송정책 × 클레임 유형의 회수지를 등록하거나 갱신합니다.
     *
     * UNIQUE(shipping_policy_id, claim_type) 를 매칭 키로 사용합니다.
     *
     * @param  int  $shippingPolicyId  배송정책 ID
     * @param  string  $claimType  클레임 유형
     * @param  array  $data  회수지 데이터
     * @return ShippingPolicyClaimAddress 등록/갱신된 회수지
     */
    public function upsert(int $shippingPolicyId, string $claimType, array $data): ShippingPolicyClaimAddress;

    /**
     * 배송정책 × 클레임 유형의 회수지를 삭제합니다.
     *
     * @param  int  $shippingPolicyId  배송정책 ID
     * @param  string  $claimType  클레임 유형
     * @return int 삭제된 행 수
     */
    public function deleteByPolicyAndType(int $shippingPolicyId, string $claimType): int;
}
