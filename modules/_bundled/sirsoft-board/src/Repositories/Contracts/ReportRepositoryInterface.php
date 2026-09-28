<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Exceptions\DuplicateReportException;
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
     * @return LengthAwarePaginator
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
     * @return Report|null 신고 모델 또는 null
     */
    public function find(int $id): ?Report;

    /**
     * ID로 신고를 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  신고 ID
     * @return Report 신고 모델
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Report;

    /**
     * 신고를 수정합니다.
     *
     * @param  int  $id  신고 ID
     * @param  array  $data  수정할 데이터
     * @return Report 수정된 신고 모델
     *
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Report;

    /**
     * 신고를 삭제합니다 (소프트 삭제).
     *
     * @param  int  $id  신고 ID
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
     */
    public function delete(int $id): bool;

    /**
     * 신고를 영구 삭제합니다.
     *
     * @param  int  $id  신고 ID
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
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
     * @return LengthAwarePaginator 그룹 목록
     */
    public function paginateGrouped(array $filters = [], int $perPage = 15): LengthAwarePaginator;

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
     * @throws DuplicateReportException
     */
    public function createLog(array $data): ReportLog;

    /**
     * 신고 케이스의 신고자 로그를 페이지네이션으로 반환합니다.
     *
     * @param  int  $reportId  신고 케이스 ID
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  페이지 번호
     * @return LengthAwarePaginator 신고 로그 목록
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

    /**
     * 특정 사용자가 여러 대상을 신고했는지 일괄 확인합니다.
     *
     * N+1 방지를 위해 복수 대상의 신고 여부를 한 번의 쿼리로 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입 (post/comment)
     * @param  array<int>  $targetIds  대상 ID 목록
     * @return array<int> 신고한 대상 ID 목록
     */
    public function getReportedTargetIds(int $userId, int $boardId, string $targetType, array $targetIds): array;

    /**
     * ID 목록으로 신고를 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  신고 ID 목록
     * @return Collection
     */
    public function findByIdsKeyed(array $ids): Collection;

    /**
     * 전체 게시판에서 미처리 신고를 최신순으로 조회합니다 (대시보드 신고 카드용).
     *
     * 미처리 기준: status 가 pending 또는 review. last_reported_at 최신순 정렬.
     * 게시판/작성자 관계를 eager load 합니다.
     *
     * @param  int  $limit  조회 건수
     * @return Collection<int, Report> 미처리 신고 컬렉션
     */
    public function getPendingAcrossBoards(int $limit): Collection;

    /**
     * 전체 게시판의 미처리 신고 건수를 조회합니다 (대시보드 신고 카드 합계용).
     *
     * 미처리 기준: status 가 pending 또는 review.
     *
     * @return int 미처리 신고 건수
     */
    public function countPendingAcrossBoards(): int;

    /**
     * 신고자 본인이 남긴 신고 로그 목록을 조회합니다 (마이페이지 「내 신고」).
     *
     * 케이스(boards_reports)가 소프트 삭제됐어도 로그는 남아야 한다 — 신고자에게는
     * "내가 신고했다" 는 사실 자체가 이력이다(목록에서는 삭제 배지로 표시).
     *
     * @param  int  $reporterId  신고자 ID
     * @param  array<string, mixed>  $filters  필터 (status·target_type·board_slug·search·search_field·sort)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 신고 로그 목록
     */
    public function paginateLogsByReporter(int $reporterId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * 신고자가 남긴 신고 로그 수를 셉니다 (마이페이지 활동 통계).
     *
     * @param  int  $reporterId  신고자 ID
     * @return int 신고 건수
     */
    public function countLogsByReporter(int $reporterId): int;

    /**
     * 신고 로그 1건을 신고자 소유 조건으로 조회합니다.
     *
     * @param  int  $logId  신고 로그 ID
     * @param  int  $reporterId  신고자 ID
     * @return ReportLog|null 신고 로그 (없거나 남의 것이면 null)
     */
    public function findLogByIdAndReporter(int $logId, int $reporterId): ?ReportLog;

    /**
     * 신고 로그 1건을 삭제합니다 (신고 취소).
     *
     * @param  int  $logId  신고 로그 ID
     * @return bool 삭제 여부
     */
    public function deleteLog(int $logId): bool;

    /**
     * 케이스에 남아 있는 신고 로그 수를 셉니다.
     *
     * @param  int  $reportId  신고 케이스 ID
     * @return int 로그 수
     */
    public function countLogsByReport(int $reportId): int;

    /**
     * 케이스의 가장 최근 신고 로그 시각을 반환합니다.
     *
     * @param  int  $reportId  신고 케이스 ID
     * @return mixed 최근 신고 시각 (없으면 null)
     */
    public function latestLogCreatedAt(int $reportId);

    /**
     * 신고 대상의 스냅샷에서 개인정보 필드를 덮어씁니다 (개인정보 보유기간 처리, 이슈 #636).
     *
     * 신고 로그는 신고 당시 글의 작성자명·제목·본문을 스냅샷으로 복사해 둔다. 원본 글만
     * 익명화·파기하면 그 사본이 남아 「지웠다」가 거짓이 되므로 같은 처리에서 함께 덮는다.
     *
     * @param  string  $targetType  신고 대상 종류 (post|comment)
     * @param  array<int, int>  $targetIds  대상 ID 목록
     * @param  array<string, string>  $replacements  스냅샷 키 => 치환값
     * @return int 갱신한 신고 로그 수
     */
    public function scrubSnapshotsForTargets(string $targetType, array $targetIds, array $replacements): int;

    /**
     * 케이스의 신고 로그 중 기준 시각 이후(포함) 것만 셉니다.
     *
     * 기준 시각이 없으면 케이스 전체를 셉니다 — 재활성화된 적 없는 케이스의 "현재 주기" 는
     * 케이스 전체이기 때문입니다.
     *
     * @param  int  $reportId  신고 케이스 ID
     * @param  \DateTimeInterface|null  $since  기준 시각 (현재 주기 시작 — last_activated_at)
     * @return int 로그 수
     */
    public function countLogsByReportSince(int $reportId, ?\DateTimeInterface $since): int;

    /**
     * 케이스의 가장 최근 신고 로그 1건을 반환합니다.
     *
     * @param  int  $reportId  신고 케이스 ID
     * @return ReportLog|null 최근 로그 (없으면 null)
     */
    public function findLatestLogByReport(int $reportId): ?ReportLog;
}
