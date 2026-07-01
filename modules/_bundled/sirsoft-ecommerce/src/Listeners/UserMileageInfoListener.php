<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 회원 화면 마일리지 총합 주입 리스너 (filter 훅)
 *
 * 코어 UserResource 의 관리자 상세(withAdminInfo)·프로필(/api/me) 응답에 발화하는
 * core.user.filter_resource_data 필터 훅을 구독해, 모듈 소유 잔액 캐시(O(1))를 응답에 병합합니다.
 * 회원 목록(toListArray)에는 이 훅이 발화하지 않으므로 N+1·목록 풀스캔이 구조적으로 없습니다.
 *
 * 부하 설계: getBalance(원장 SUM) 를 호출하지 않고 MileageBalanceRepository::getCachedBalance
 * (캐시 단일 행) 로만 조회합니다. pending 도 캐시 컬럼에서 읽습니다.
 */
class UserMileageInfoListener implements HookListenerInterface
{
    /**
     * @param  MileageBalanceRepositoryInterface  $balanceRepository  잔액 캐시 Repository
     * @param  EcommerceSettingsService  $settings  환경설정 서비스
     */
    public function __construct(
        private MileageBalanceRepositoryInterface $balanceRepository,
        private EcommerceSettingsService $settings,
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
                'method' => 'injectMileageTotal',
                'type' => 'filter',
                'priority' => 20,
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
        // method 매핑(injectMileageTotal)을 사용하므로 직접 호출되지 않음
    }

    /**
     * 회원 응답 데이터에 마일리지 잔액 요약을 병합합니다.
     *
     * mileage.enabled=false 이면 잔액 대신 비활성화 신호(['enabled' => false])만 주입합니다.
     * 화면이 "데이터 없음"과 "기능 비활성화"를 구별해 비활성 안내를 표시할 수 있도록 합니다.
     * 활성 상태에서는 enabled=true 와 함께 캐시 잔액(없으면 0)을 주입합니다.
     *
     * @param  array<string, mixed>  $data  코어가 직렬화한 회원 응답 데이터
     * @param  User  $user  회원 모델
     * @return array<string, mixed> 마일리지 요약이 병합된 데이터
     */
    public function injectMileageTotal(array $data, User $user): array
    {
        if (! (bool) $this->settings->getSetting('mileage.enabled', false)) {
            $data['ecommerce_mileage'] = ['enabled' => false];

            return $data;
        }

        $data['ecommerce_mileage'] = ['enabled' => true]
            + $this->balanceRepository->getCachedBalance($user->id);

        return $data;
    }
}
