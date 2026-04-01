<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;

/**
 * 신고 Repository 인터페이스
 */
interface ReportRepositoryInterface
{
    /**
     * 신고 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * 신고를 생성합니다.
     *
     * @param  array  $data  신고 생성 데이터
     * @return Report 생성된 신고 모델
     */
    public function create(array $data): Report;

    /**
     * ID로 신고를 조회합니다.
     *
     * @param  int  $id  신고 ID
     */
    public function find(int $id): ?Report;

    /**
     * ID로 신고를 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  신고 ID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id): Report;

    /**
     * 신고를 수정합니다.
     *
     * @param  int  $id  신고 ID
     * @param  array  $data  수정할 데이터
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update(int $id, array $data): Report;

    /**
     * 신고를 삭제합니다 (소프트 삭제).
     *
     * @param  int  $id  신고 ID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function delete(int $id): bool;

    /**
     * 신고를 영구 삭제합니다.
     *
     * @param  int  $id  신고 ID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function forceDelete(int $id): bool;

    /**
     * 여러 신고의 상태를 일괄 변경합니다.
     *
     * @param  array  $ids  신고 ID 배열
     * @param  array  $data  변경할 데이터
     * @return int 변경된 행 수
     */
    public function bulkUpdateStatus(array $ids, array $data): int;

    /**
     * 신고 통계를 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @return array{by_status: array, by_type: array, total: int}
     */
    public function getStatistics(array $filters = []): array;

    /**
     * 케이스 기준으로 신고 목록을 페이지네이션하여 조회합니다.
     * boards_reports 1행 = 1케이스이므로 복잡한 그룹핑 없이 직접 페이지네이션합니다.
     * 재신고 시 last_reported_at이 갱신되어 목록 상단으로 올라옵니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     */
    public function paginateGrouped(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * 특정 대상(게시글/댓글)에 대한 모든 신고를 조회합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입 (post/comment)
     * @param  int  $targetId  대상 ID
     */
    public function findByTarget(int $boardId, string $targetType, int $targetId): \Illuminate\Database\Eloquent\Collection;

    /**
     * 특정 대상에 대한 모든 신고의 상태를 일괄 변경합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입
     * @param  int  $targetId  대상 ID
     * @param  array  $data  변경할 데이터
     * @return int 변경된 행 수
     */
    public function bulkUpdateStatusByTarget(int $boardId, string $targetType, int $targetId, array $data): int;

    /**
     * 취소된 신고를 포함하여 동일 대상의 케이스를 조회합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입 (post/comment)
     * @param  int  $targetId  대상 ID
     */
    public function findByTargetWithTrashed(int $boardId, string $targetType, int $targetId): \Illuminate\Database\Eloquent\Collection;

    /**
     * 사용자의 오늘(자정 기준) 전체 신고 건수를 boards_report_logs 기준으로 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return int 오늘 신고 건수
     */
    public function countTodayReportsByUser(int $userId): int;

    /**
     * 사용자의 최근 N일 내 반려된 신고 건수를 조회합니다.
     * 반려 기준: boards_reports.processed_at >= now() - $days일 이고 status = rejected
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $days  조회 기간 (일)
     * @return int 반려 건수
     */
    public function countRejectedReportsByUser(int $userId, int $days): int;

    /**
     * 특정 대상에 대한 현재 활성 사이클의 신고 수를 조회합니다.
     * boards_report_logs.created_at >= boards_reports.last_activated_at 조건으로
     * 재신고(재활성) 이후의 로그만 카운트합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입 (post, comment)
     * @param  int  $targetId  대상 ID
     * @return int 현재 활성 사이클 신고 수
     */
    public function countActiveReportsByTarget(int $boardId, string $targetType, int $targetId): int;

    /**
     * (board_id, target_type, target_id)로 케이스를 조회하거나 신규 생성합니다.
     * 케이스가 없으면 status=pending으로 생성 후 반환합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입 (post/comment)
     * @param  int  $targetId  대상 ID
     * @param  array  $createData  케이스 신규 생성 시 추가 데이터 (author_id, metadata 등)
     * @return array{report: Report, created: bool}
     */
    public function findOrCreateCase(int $boardId, string $targetType, int $targetId, array $createData = []): array;

    /**
     * 신고 로그(신고자 기록)를 생성합니다.
     *
     * @param  array  $data  로그 생성 데이터
     * @return ReportLog 생성된 로그 모델
     *
     * @throws \Modules\Sirsoft\Board\Exceptions\DuplicateReportException
     */
    public function createLog(array $data): ReportLog;

    /**
     * 신고 케이스의 신고자 로그를 페이지네이션으로 반환합니다.
     *
     * @param  int  $reportId  신고 케이스 ID
     * @param  int  $perPage   페이지당 항목 수
     * @param  int  $page      페이지 번호
     * @return LengthAwarePaginator
     */
    public function paginateLogsByReport(int $reportId, int $perPage = 10, int $page = 1): LengthAwarePaginator;

    /**
     * 특정 사용자가 특정 대상을 이미 신고했는지 확인합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입 (post/comment)
     * @param  int  $targetId  대상 ID
     * @return bool 이미 신고 여부
     */
    public function hasUserReported(int $userId, int $boardId, string $targetType, int $targetId): bool;
}
