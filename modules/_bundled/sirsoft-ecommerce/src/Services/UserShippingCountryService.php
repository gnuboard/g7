<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;

/**
 * 유저별 배송국가 서비스 (MP08 후속)
 *
 * 유저 프로필의 선호 배송국가를 조회·저장하는 도메인 진입점입니다(UserCurrencyService 미러).
 * Repository 인터페이스에 위임하며, 컨트롤러는 본 서비스만 의존합니다.
 */
class UserShippingCountryService
{
    public function __construct(
        protected EcommerceUserProfileRepositoryInterface $profileRepository,
        protected UserRepositoryInterface $userRepository,
    ) {}

    /**
     * 사용자의 선호 배송국가를 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return string|null 선호 배송국가 코드 (미설정 시 null)
     */
    public function getPreferredShippingCountry(int $userId): ?string
    {
        return $this->profileRepository->getPreferredShippingCountry($userId);
    }

    /**
     * 사용자의 선호 배송국가를 저장합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $countryCode  국가 코드 (ISO 3166-1 alpha-2, FormRequest 에서 활성 국가 검증 완료)
     * @return string 저장된 국가 코드
     */
    public function setPreferredShippingCountry(int $userId, string $countryCode): string
    {
        $countryCode = strtoupper($countryCode);
        $this->profileRepository->setPreferredShippingCountry($userId, $countryCode);

        return $countryCode;
    }

    /**
     * 관리자가 특정 회원의 배송국가를 변경합니다.
     *
     * 회원 존재 확인 → 배송국가 저장 → 활동 로그 훅 발화까지 한 도메인 단위로 처리합니다.
     *
     * @param  int  $userId  대상 회원 ID
     * @param  string  $countryCode  국가 코드 (ISO 3166-1 alpha-2, FormRequest 에서 활성 국가 검증 완료)
     * @return string 저장된 국가 코드
     *
     * @throws ModelNotFoundException 회원 미존재 시
     */
    public function changeUserShippingCountryByAdmin(int $userId, string $countryCode): string
    {
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw (new ModelNotFoundException)
                ->setModel(User::class, [$userId]);
        }

        $countryCode = strtoupper($countryCode);
        $previous = $this->profileRepository->getPreferredShippingCountry($userId);
        $this->profileRepository->setPreferredShippingCountry($userId, $countryCode);

        // 활동 로그 훅 발화 (EcommerceAdminActivityLogListener 구독)
        HookManager::doAction('sirsoft-ecommerce.admin.user_shipping_country.changed', $user, [
            'previous_country' => $previous,
            'new_country' => $countryCode,
        ]);

        return $countryCode;
    }
}
