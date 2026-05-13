<?php

namespace App\Services;

use App\Contracts\Repositories\NotificationLogRepositoryInterface;
use App\Enums\NotificationLogStatus;
use App\Extension\HookManager;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationLogService
{
    public function __construct(
        private readonly NotificationLogRepositoryInterface $repository,
    ) {}

    /**
     * 발송 성공 로그를 기록합니다.
     */
    public function logSent(array $data): NotificationLog
    {
        $data['status'] = NotificationLogStatus::Sent->value;

        HookManager::doAction('core.notification_log.before_log_sent', $data);

        $log = $this->repository->create($data);

        HookManager::doAction('core.notification_log.after_log_sent', $log);

        return $log;
    }

    /**
     * 발송 실패 로그를 기록합니다.
     */
    public function logFailed(array $data): NotificationLog
    {
        $data['status'] = NotificationLogStatus::Failed->value;

        HookManager::doAction('core.notification_log.before_log_failed', $data);

        $log = $this->repository->create($data);

        HookManager::doAction('core.notification_log.after_log_failed', $log);

        return $log;
    }

    /**
     * 발송 건너뜀 로그를 기록합니다.
     */
    public function logSkipped(array $data): NotificationLog
    {
        $data['status'] = NotificationLogStatus::Skipped->value;

        HookManager::doAction('core.notification_log.before_log_skipped', $data);

        $log = $this->repository->create($data);

        HookManager::doAction('core.notification_log.after_log_skipped', $log);

        return $log;
    }

    /**
     * 로그를 삭제합니다.
     */
    public function deleteLog(NotificationLog $log): bool
    {
        HookManager::doAction('core.notification_log.before_delete', $log);

        $result = $this->repository->delete($log);

        HookManager::doAction('core.notification_log.after_delete', $log);

        return $result;
    }

    /**
     * 다건 삭제합니다.
     */
    public function bulkDelete(array $ids): int
    {
        HookManager::doAction('core.notification_log.before_bulk_delete', $ids);

        $count = $this->repository->bulkDelete($ids);

        HookManager::doAction('core.notification_log.after_bulk_delete', $ids, $count);

        return $count;
    }

    /**
     * 페이지네이션 목록을 조회합니다.
     *
     * @param  User|null  $user  스코프 적용 대상 사용자 (null이면 스코프 미적용)
     */
    public function getLogs(array $filters = [], int $perPage = 20, ?User $user = null): LengthAwarePaginator
    {
        return $this->repository->getPaginated($filters, $perPage, $user);
    }
}
