<?php

namespace App\Services;

use App\Contracts\Repositories\NotificationLogRepositoryInterface;
use App\Enums\NotificationLogStatus;
use App\Extension\HookManager;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 알림 발송 이력 서비스
 */
class NotificationLogService
{
    public function __construct(
        private readonly NotificationLogRepositoryInterface $repository,
    ) {}

    /**
     * 발송 성공 로그를 기록합니다.
     *
     * @param  array<string, mixed>  $data  로그 데이터
     * @return NotificationLog 기록된 로그
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
     *
     * @param  array<string, mixed>  $data  로그 데이터
     * @return NotificationLog 기록된 로그
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
     *
     * @param  array<string, mixed>  $data  로그 데이터
     * @return NotificationLog 기록된 로그
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
     *
     * @param  NotificationLog  $log  삭제할 로그
     * @return bool 삭제 성공 여부
     */
    public function deleteLog(NotificationLog $log): bool
    {
        HookManager::doAction('core.notification_log.before_delete', $log);

        $result = $this->repository->delete($log);

        HookManager::doAction('core.notification_log.after_delete', $log);

        return $result;
    }

    /**
     * 보존 기간이 지난 발송 이력을 정리합니다 (자동 파기).
     *
     * 운영자가 고른 ID 를 지우는 bulkDelete 와는 별개의 훅을 발행한다 — 자동 파기는
     * 대상을 기간으로 정하고 사람 없이 예약 실행되므로, 대화형 가드를 전제한 훅에
     * 얹으면 예약이 그 가드에 걸린다. 확장은 이 훅으로 자기 처리를 붙인다.
     *
     * @param  int  $days  보존 기간 (일)
     * @return int 삭제된 건수
     */
    public function prune(int $days): int
    {
        HookManager::doAction('core.notification_log.before_prune', $days);

        $count = $this->repository->deleteOlderThan($days);

        HookManager::doAction('core.notification_log.after_prune', $days, $count);

        return $count;
    }

    /**
     * 다건 삭제합니다.
     *
     * @param  array<int, int>  $ids  삭제할 로그 ID 목록
     * @return int 삭제된 건수
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
     * @param  array<string, mixed>  $filters  필터 조건
     * @param  int  $perPage  페이지당 건수
     * @param  User|null  $user  스코프 적용 대상 사용자 (null이면 스코프 미적용)
     * @return LengthAwarePaginator|CursorPaginator 페이지 결과 (커서 요청 시 키셋)
     */
    public function getLogs(array $filters = [], int $perPage = 20, ?User $user = null): LengthAwarePaginator|CursorPaginator
    {
        return $this->repository->getPaginated($filters, $perPage, $user);
    }
}
