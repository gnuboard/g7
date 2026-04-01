<?php

namespace Modules\Sirsoft\Board\Repositories;

use Modules\Sirsoft\Board\Models\UserNotificationSetting;
use Modules\Sirsoft\Board\Repositories\Contracts\UserNotificationSettingRepositoryInterface;

/**
 * 사용자 알림 설정 Repository
 *
 * 사용자 알림 설정 데이터 접근 계층을 담당합니다.
 */
class UserNotificationSettingRepository implements UserNotificationSettingRepositoryInterface
{
    /**
     * 사용자 ID로 알림 설정을 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @return UserNotificationSetting|null 알림 설정 또는 null
     */
    public function findByUserId(int $userId): ?UserNotificationSetting
    {
        return UserNotificationSetting::where('user_id', $userId)->first();
    }

    /**
     * 알림 설정을 생성하거나 수정합니다.
     *
     * @param int $userId 사용자 ID
     * @param array $data 알림 설정 데이터
     * @return UserNotificationSetting 생성 또는 수정된 알림 설정
     */
    public function createOrUpdate(int $userId, array $data): UserNotificationSetting
    {
        return UserNotificationSetting::updateOrCreate(
            ['user_id' => $userId],
            $data
        );
    }

    /**
     * 사용자 ID로 알림 설정을 삭제합니다.
     *
     * @param int $userId 사용자 ID
     * @return bool 삭제 성공 여부
     */
    public function deleteByUserId(int $userId): bool
    {
        return UserNotificationSetting::where('user_id', $userId)->delete() > 0;
    }
}
