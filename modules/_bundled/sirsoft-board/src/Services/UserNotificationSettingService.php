<?php

namespace Modules\Sirsoft\Board\Services;

use Modules\Sirsoft\Board\Models\UserNotificationSetting;
use Modules\Sirsoft\Board\Repositories\Contracts\UserNotificationSettingRepositoryInterface;

/**
 * 사용자 알림 설정 서비스 클래스
 *
 * 사용자별 게시판 알림 설정의 비즈니스 로직을 담당합니다.
 */
class UserNotificationSettingService
{
    /**
     * UserNotificationSettingService 생성자
     *
     * @param UserNotificationSettingRepositoryInterface $repository 알림 설정 리포지토리
     */
    public function __construct(
        private UserNotificationSettingRepositoryInterface $repository
    ) {}

    /**
     * 사용자 ID로 알림 설정을 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @return UserNotificationSetting|null 알림 설정 또는 null
     */
    public function getByUserId(int $userId): ?UserNotificationSetting
    {
        return $this->repository->findByUserId($userId);
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
        return $this->repository->createOrUpdate($userId, $data);
    }

    /**
     * 사용자 ID로 알림 설정을 삭제합니다.
     *
     * @param int $userId 사용자 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $userId): bool
    {
        return $this->repository->deleteByUserId($userId);
    }
}
