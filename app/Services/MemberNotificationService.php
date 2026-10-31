<?php

namespace App\Services;

use App\Contracts\Repositories\MemberNotificationRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * 회원 알림함(in-app 수신함) 관리자 서비스
 *
 * 발송 이력(NotificationLogService)과 다른 축이다 — 그쪽은 "무엇을 보냈나" 를 남기고,
 * 이쪽은 "회원 화면에 무엇이 남아 있나" 를 다룬다. 삭제도 의미가 다르다: 여기서 지우면
 * 그 회원의 알림함에서 사라진다.
 */
class MemberNotificationService
{
    /**
     * MemberNotificationService 생성자
     *
     * @param  MemberNotificationRepositoryInterface  $repository  회원 알림함 리포지토리
     */
    public function __construct(
        private readonly MemberNotificationRepositoryInterface $repository,
    ) {}

    /**
     * 관리자용 전 회원 알림 목록을 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터
     * @param  int  $perPage  페이지당 개수
     * @return Paginator 페이지네이션된 알림 목록
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): Paginator
    {
        return $this->repository->paginateForAdmin($filters, $perPage);
    }

    /**
     * 알림 한 건을 삭제합니다 (소유자 무관).
     *
     * @param  string  $id  알림 uuid
     * @return bool 삭제 성공 여부
     */
    public function deleteByAdmin(string $id): bool
    {
        return $this->repository->deleteById($id);
    }

    /**
     * 알림을 일괄 삭제합니다 (소유자 무관).
     *
     * 훅을 루프 앞뒤로 한 번씩만 발화한다 — 항목마다 발화하면 활동 로그가 폭주한다.
     *
     * @param  array<int, string>  $ids  알림 uuid 목록
     * @return int 실제로 삭제된 건수
     */
    public function bulkDeleteByAdmin(array $ids): int
    {
        HookManager::doAction('core.member_notification.before_bulk_delete', $ids);

        $count = $this->repository->deleteMany($ids);

        HookManager::doAction('core.member_notification.after_bulk_delete', $ids, $count);

        return $count;
    }

    /**
     * 회원의 알림 요약(총건·안읽음)을 조회합니다.
     *
     * @param  int  $userId  회원 ID
     * @return array{total: int, unread: int} 요약
     */
    public function summaryForUser(int $userId): array
    {
        return $this->repository->summaryByUser($userId);
    }
}
