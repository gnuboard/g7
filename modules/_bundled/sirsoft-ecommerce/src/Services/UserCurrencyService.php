<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;

/**
 * 유저별 결제 통화 서비스 (A3)
 *
 * 유저 프로필의 선호 통화를 조회·저장하는 도메인 진입점입니다.
 * Repository 인터페이스에 위임하며, 컨트롤러는 본 서비스만 의존합니다.
 */
class UserCurrencyService
{
    public function __construct(
        protected EcommerceUserProfileRepositoryInterface $profileRepository,
        protected UserRepositoryInterface $userRepository,
    ) {}

    /**
     * 사용자의 선호 통화를 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return string|null 선호 통화 코드 (미설정 시 null)
     */
    public function getPreferredCurrency(int $userId): ?string
    {
        return $this->profileRepository->getPreferredCurrency($userId);
    }

    /**
     * 사용자의 선호 통화를 저장합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $currency  통화 코드 (ISO 4217, FormRequest 에서 등록 통화 검증 완료)
     * @return string 저장된 통화 코드
     */
    public function setPreferredCurrency(int $userId, string $currency): string
    {
        $this->profileRepository->setPreferredCurrency($userId, $currency);

        return $currency;
    }

    /**
     * 관리자가 특정 회원의 결제 통화를 변경합니다. (A3)
     *
     * 회원 존재 확인 → 통화 저장 → 활동 로그 훅 발화까지 한 도메인 단위로 처리합니다.
     *
     * @param  int  $userId  대상 회원 ID
     * @param  string  $currency  통화 코드 (ISO 4217, FormRequest 에서 등록 통화 검증 완료)
     * @return string 저장된 통화 코드
     *
     * @throws ModelNotFoundException 회원 미존재 시
     */
    public function changeUserCurrencyByAdmin(int $userId, string $currency): string
    {
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw (new ModelNotFoundException)
                ->setModel(User::class, [$userId]);
        }

        $previous = $this->profileRepository->getPreferredCurrency($userId);
        $this->profileRepository->setPreferredCurrency($userId, $currency);

        // 활동 로그 훅 발화 (EcommerceAdminActivityLogListener 구독)
        HookManager::doAction('sirsoft-ecommerce.admin.user_currency.changed', $user, [
            'previous_currency' => $previous,
            'new_currency' => $currency,
        ]);

        return $currency;
    }
}
