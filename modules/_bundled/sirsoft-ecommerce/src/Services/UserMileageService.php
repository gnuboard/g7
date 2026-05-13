<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;

/**
 * 사용자 마일리지 서비스
 *
 * 마이페이지 마일리지 조회 기능을 제공합니다.
 * 현재는 기본 구현으로, 실제 마일리지 시스템 연동 시 확장됩니다.
 */
class UserMileageService
{
    /**
     * 사용자 마일리지 잔액 조회
     *
     * @param int $userId 사용자 ID
     * @return array 마일리지 정보 (balance, pending, expiring_soon 등)
     */
    public function getBalance(int $userId): array
    {
        HookManager::doAction('sirsoft-ecommerce.user_mileage.before_balance', $userId);

        // TODO: 실제 마일리지 테이블 연동 시 구현
        // 현재는 기본값 반환
        $balance = [
            'available' => 0,
            'pending' => 0,
            'expiring_soon' => 0,
            'expiring_date' => null,
            'total_earned' => 0,
            'total_used' => 0,
        ];

        $balance = HookManager::applyFilters('sirsoft-ecommerce.user_mileage.filter_balance', $balance, $userId);

        HookManager::doAction('sirsoft-ecommerce.user_mileage.after_balance', $balance, $userId);

        return $balance;
    }

    /**
     * 마일리지 사용 가능 여부 확인
     *
     * @param int $userId 사용자 ID
     * @param int $amount 사용할 마일리지 금액
     * @return bool 사용 가능 여부
     */
    public function canUse(int $userId, int $amount): bool
    {
        $balance = $this->getBalance($userId);

        return $balance['available'] >= $amount;
    }

    /**
     * 사용 가능한 최대 마일리지 조회
     *
     * @param int $userId 사용자 ID
     * @param int $orderAmount 주문 금액 (마일리지 사용 한도 계산용)
     * @return int 사용 가능한 최대 마일리지
     */
    public function getMaxUsable(int $userId, int $orderAmount): int
    {
        $balance = $this->getBalance($userId);

        // 마일리지 설정에서 최대 사용 비율 가져오기 (기본 100%)
        $maxRatio = HookManager::applyFilters('sirsoft-ecommerce.user_mileage.max_use_ratio', 1.0);
        $maxByOrder = (int) floor($orderAmount * $maxRatio);

        return min($balance['available'], $maxByOrder);
    }
}
