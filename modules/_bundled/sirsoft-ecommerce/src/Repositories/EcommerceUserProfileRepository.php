<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Models\EcommerceUserProfile;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;

/**
 * 이커머스 사용자 프로필 Repository 구현체 (A3)
 */
class EcommerceUserProfileRepository implements EcommerceUserProfileRepositoryInterface
{
    /**
     * @param  EcommerceUserProfile  $model  회원 쇼핑 프로필 모델
     */
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

    /**
     * 사용자의 프로필 행을 지웁니다 (탈퇴 회원 파기, 회원 삭제).
     *
     * @param  int  $userId  사용자 ID
     * @return int 지운 행 수 (행이 없으면 0)
     */
    public function deleteByUserId(int $userId): int
    {
        return (int) $this->model->newQuery()->where('user_id', $userId)->delete();
    }
}
