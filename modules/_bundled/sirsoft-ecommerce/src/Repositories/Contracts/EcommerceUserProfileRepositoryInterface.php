<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Modules\Sirsoft\Ecommerce\Models\EcommerceUserProfile;

/**
 * 이커머스 사용자 프로필 Repository 인터페이스 (A3)
 */
interface EcommerceUserProfileRepositoryInterface
{
    /**
     * 사용자 ID 로 프로필을 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return EcommerceUserProfile|null 프로필 (없으면 null)
     */
    public function findByUserId(int $userId): ?EcommerceUserProfile;

    /**
     * 사용자의 선호 통화를 반환합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return string|null 선호 통화 코드 (미설정 시 null)
     */
    public function getPreferredCurrency(int $userId): ?string;

    /**
     * 사용자의 선호 통화를 저장(upsert)합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $currency  통화 코드 (ISO 4217)
     * @return EcommerceUserProfile 저장된 프로필
     */
    public function setPreferredCurrency(int $userId, string $currency): EcommerceUserProfile;

    /**
     * 사용자의 선호 배송국가를 반환합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return string|null 선호 배송국가 코드 (미설정 시 null)
     */
    public function getPreferredShippingCountry(int $userId): ?string;

    /**
     * 사용자의 선호 배송국가를 저장(upsert)합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $countryCode  국가 코드 (ISO 3166-1 alpha-2)
     * @return EcommerceUserProfile 저장된 프로필
     */
    public function setPreferredShippingCountry(int $userId, string $countryCode): EcommerceUserProfile;
}
