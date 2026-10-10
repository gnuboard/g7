<?php

namespace App\Contracts\Repositories;

use Illuminate\Contracts\Pagination\Paginator;

/**
 * 회원 알림함(in-app 수신함) 관리자 조회 Repository 인터페이스
 *
 * 기존 NotificationRepository 는 전부 `$user->notifications()` 스코프라 전 회원 조회
 * 메서드가 없다. 그 계약을 넓히는 대신 «관리자 전 회원 조회» 라는 다른 목적을 별도 계약으로 둔다 —
 * 사용자 스코프 메서드에 "관리자면 스코프 해제" 분기를 넣으면 그 분기가 곧 권한 우회로가 된다.
 */
interface MemberNotificationRepositoryInterface
{
    /**
     * 관리자용 전 회원 알림 목록을 페이지네이션합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (search_type, search, read, user_id, 기간, 정렬)
     * @param  int  $perPage  페이지당 개수
     * @return Paginator 페이지네이션된 알림 목록
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): Paginator;

    /**
     * 알림 한 건을 삭제합니다 (소유자 무관 — 관리자 전용).
     *
     * @param  string  $id  알림 uuid
     * @return bool 삭제 성공 여부
     */
    public function deleteById(string $id): bool;

    /**
     * 알림을 uuid 목록으로 일괄 삭제합니다 (소유자 무관 — 관리자 전용).
     *
     * @param  array<int, string>  $ids  알림 uuid 목록
     * @return int 실제로 삭제된 건수
     */
    public function deleteMany(array $ids): int;

    /**
     * 회원의 알림 요약(총건·안읽음)을 조회합니다.
     *
     * @param  int  $userId  회원 ID
     * @return array{total: int, unread: int} 요약
     */
    public function summaryByUser(int $userId): array;
}
