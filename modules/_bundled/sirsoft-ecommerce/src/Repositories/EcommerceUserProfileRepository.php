<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Models\EcommerceUserProfile;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;

/**
 * 이커머스 사용자 프로필 Repository 구현체 (A3)
 */
class EcommerceUserProfileRepository implements EcommerceUserProfileRepositoryInterface
{
    public function __construct(
        protected EcommerceUserProfile $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findByUserId(int $userId): ?EcommerceUserProfile
    {
        return $this->model->newQuery()->where('user_id', $userId)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function getPreferredCurrency(int $userId): ?string
    {
        return $this->findByUserId($userId)?->preferred_currency;
    }

    /**
     * {@inheritDoc}
     */
    public function setPreferredCurrency(int $userId, string $currency): EcommerceUserProfile
    {
        return $this->model->newQuery()->updateOrCreate(
            ['user_id' => $userId],
            ['preferred_currency' => $currency],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getPreferredShippingCountry(int $userId): ?string
    {
        return $this->findByUserId($userId)?->preferred_shipping_country;
    }

    /**
     * {@inheritDoc}
     */
    public function setPreferredShippingCountry(int $userId, string $countryCode): EcommerceUserProfile
    {
        return $this->model->newQuery()->updateOrCreate(
            ['user_id' => $userId],
            ['preferred_shipping_country' => $countryCode],
        );
    }
}
