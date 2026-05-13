<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;

/**
 * 사용자 배송지 Repository 구현체
 */
class UserAddressRepository implements UserAddressRepositoryInterface
{
    public function __construct(
        protected UserAddress $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function find(int $id): ?UserAddress
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): UserAddress
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(UserAddress $address, array $data): UserAddress
    {
        $address->update($data);

        return $address->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(UserAddress $address): bool
    {
        return $address->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserId(int $userId): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findDefaultByUserId(int $userId): ?UserAddress
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function setDefault(int $userId, int $addressId): bool
    {
        return DB::transaction(function () use ($userId, $addressId) {
            // 기존 기본 배송지 해제
            $this->model
                ->where('user_id', $userId)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            // 새 기본 배송지 설정
            return $this->model
                ->where('user_id', $userId)
                ->where('id', $addressId)
                ->update(['is_default' => true]) > 0;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function countByUserId(int $userId): int
    {
        return $this->model
            ->where('user_id', $userId)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserIdAndId(int $userId, int $addressId): ?UserAddress
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('id', $addressId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserIdAndName(int $userId, string $name): ?UserAddress
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('name', $name)
            ->first();
    }
}
