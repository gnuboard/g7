<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyClaimAddress;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyClaimAddressRepositoryInterface;

/**
 * 배송정책 클레임 회수지 리포지토리 구현체
 */
class ShippingPolicyClaimAddressRepository implements ShippingPolicyClaimAddressRepositoryInterface
{
    /**
     * @param  ShippingPolicyClaimAddress  $model  클레임 회수지 모델
     */
    public function __construct(
        protected ShippingPolicyClaimAddress $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findByPolicyAndType(int $shippingPolicyId, string $claimType): ?ShippingPolicyClaimAddress
    {
        return $this->model->newQuery()
            ->where('shipping_policy_id', $shippingPolicyId)
            ->where('claim_type', $claimType)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function listByPolicy(int $shippingPolicyId): Collection
    {
        return $this->model->newQuery()
            ->where('shipping_policy_id', $shippingPolicyId)
            ->orderBy('claim_type')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function upsert(int $shippingPolicyId, string $claimType, array $data): ShippingPolicyClaimAddress
    {
        return $this->model->newQuery()->updateOrCreate(
            ['shipping_policy_id' => $shippingPolicyId, 'claim_type' => $claimType],
            $data,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByPolicyAndType(int $shippingPolicyId, string $claimType): int
    {
        return $this->model->newQuery()
            ->where('shipping_policy_id', $shippingPolicyId)
            ->where('claim_type', $claimType)
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByPolicyIds(array $shippingPolicyIds): int
    {
        if ($shippingPolicyIds === []) {
            return 0;
        }

        return $this->model->newQuery()
            ->whereIn('shipping_policy_id', $shippingPolicyIds)
            ->delete();
    }
}
