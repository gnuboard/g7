<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;

/**
 * 회원 응답에 영속 결제 통화 주입 리스너 (filter 훅, A3)
 *
 * 코어 UserResource 의 프로필(/api/me)·관리자 상세 응답에 발화하는 core.user.filter_resource_data
 * 필터 훅을 구독해, 유저의 영속 결제 통화(ecommerce_preferred_currency)를 병합합니다.
 * 프론트(_user_base.json)는 로그인 유저면 이 값으로 _global.preferredCurrency 를 초기화해
 * "로그인 시 계정 통화로 덮어씀"(D-LOGIN-CUR) 을 구조적으로 충족합니다.
 */
class UserCurrencyInfoListener implements HookListenerInterface
{
    /**
     * @param  EcommerceUserProfileRepositoryInterface  $profileRepository  유저 프로필 Repository
     */
    public function __construct(
        private EcommerceUserProfileRepositoryInterface $profileRepository,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, type: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.filter_resource_data' => [
                'method' => 'injectPreferredCurrency',
                'type' => 'filter',
                'priority' => 25,
            ],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void
    {
        // method 매핑(injectPreferredCurrency)을 사용하므로 직접 호출되지 않음
    }

    /**
     * 회원 응답 데이터에 영속 결제 통화를 병합합니다.
     *
     * 미설정(가입 시 부여 전/외부 가입자)이면 null 을 주입해 프론트가 default_currency 로 폴백합니다.
     *
     * @param  array<string, mixed>  $data  코어가 직렬화한 회원 응답 데이터
     * @param  User  $user  회원 모델
     * @return array<string, mixed> 결제 통화가 병합된 데이터
     */
    public function injectPreferredCurrency(array $data, User $user): array
    {
        $data['ecommerce_preferred_currency'] = $this->profileRepository->getPreferredCurrency($user->id);

        return $data;
    }
}
