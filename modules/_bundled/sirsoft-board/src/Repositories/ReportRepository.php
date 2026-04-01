<?php

namespace Modules\Sirsoft\Board\Repositories;

use App\Helpers\PermissionHelper;
use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Exceptions\DuplicateReportException;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;

/**
 * 신고 Repository
 *
 * 신고 데이터 접근 계층을 담당합니다.
 */
class ReportRepository implements ReportRepositoryInterface
{
    /**
     * 신고 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Report::query()
            ->with(['board', 'author', 'processor']);

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-board.reports.view');

        // 검색
        $this->applySearchConditions($query, $filters);

        // 상태 필터
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->byStatus($filters['status']);
        }

        // 타입 필터
        if (! empty($filters['type']) && $filters['type'] !== 'all') {
            $query->byType($filters['type']);
        }

        // 게시판 필터
        if (! empty($filters['board_id'])) {
            $query->byBoard($filters['board_id']);
        }

        // 날짜 필터 (개별 처리)
        if (! empty($filters['reported_at_from'])) {
            $query->where('created_at', '>=', $filters['reported_at_from'].' 00:00:00');
        }
        if (! empty($filters['reported_at_to'])) {
            $query->where('created_at', '<=', $filters['reported_at_to'].' 23:59:59');
        }

        // 정렬
        $query->orderBy('created_at', 'desc');

        return $query->paginate($perPage);
    }

    /**
     * 신고를 생성합니다.
     *
     * @param  array  $data  신고 생성 데이터
     * @return Report 생성된 신고 모델
     */
    public function create(array $data): Report
    {
        return Report::create($data);
    }

    /**
     * ID로 신고를 조회합니다.
     *
     * @param  int  $id  신고 ID
     */
    public function find(int $id): ?Report
    {
        return Report::find($id);
    }

    /**
     * ID로 신고를 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  신고 ID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id): Report
    {
        return Report::with(['board', 'author', 'processor'])
            ->findOrFail($id);
    }

    /**
     * 신고를 수정합니다.
     *
     * @param  int  $id  신고 ID
     * @param  array  $data  수정할 데이터
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update(int $id, array $data): Report
    {
        $report = $this->findOrFail($id);
        $report->update($data);

        return $report->fresh(['board', 'author', 'processor']);
    }

    /**
     * 신고를 삭제합니다 (소프트 삭제).
     *
     * @param  int  $id  신고 ID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function delete(int $id): bool
    {
        $report = $this->findOrFail($id);

        return $report->delete();
    }

    /**
     * 신고를 영구 삭제합니다.
     *
     * @param  int  $id  신고 ID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function forceDelete(int $id): bool
    {
        $report = $this->findOrFail($id);

        return $report->forceDelete();
    }

    /**
     * 여러 신고의 상태를 일괄 변경합니다.
     *
     * @param  array  $ids  신고 ID 배열
     * @param  array  $data  변경할 데이터
     * @return int 변경된 행 수
     */
    public function bulkUpdateStatus(array $ids, array $data): int
    {
        // processed_by 자동 설정
        if (Auth::check() && ! isset($data['processed_by'])) {
            $data['processed_by'] = Auth::id();
        }

        // processed_at 자동 설정
        if (! isset($data['processed_at'])) {
            $data['processed_at'] = now();
        }

        return Report::whereIn('id', $ids)->update($data);
    }

    /**
     * 신고 통계를 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @return array{by_status: array, by_type: array, total: int}
     */
    public function getStatistics(array $filters = []): array
    {
        $query = Report::query();

        // 필터 적용
        if (! empty($filters['board_id'])) {
            $query->byBoard($filters['board_id']);
        }

        if (! empty($filters['reported_at_from']) && ! empty($filters['reported_at_to'])) {
            $query->byDateRange($filters['reported_at_from'], $filters['reported_at_to']);
        }

        // 상태별 통계
        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // 타입별 통계
        $byType = (clone $query)
            ->select('target_type', DB::raw('COUNT(*) as count'))
            ->groupBy('target_type')
            ->pluck('count', 'target_type')
            ->toArray();

        // 전체 개수
        $total = $query->count();

        return [
            'by_status' => $byStatus,
            'by_type' => $byType,
            'total' => $total,
        ];
    }

    /**
     * 케이스 기준으로 신고 목록을 페이지네이션하여 조회합니다.
     * boards_reports 1행 = 1케이스이므로 복잡한 그룹핑 없이 직접 페이지네이션합니다.
     * 재신고 시 last_reported_at이 갱신되어 목록 상단으로 올라옵니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     */
    public function paginateGrouped(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $tableName = (new Report)->getTable();
        $prefix = DB::getTablePrefix();
        $fullTableName = $prefix.$tableName;
        $logsTable = $prefix.'boards_report_logs';
        $postsTable = $prefix.'board_posts';
        $commentsTable = $prefix.'board_comments';

        $query = Report::query()
            ->with(['board', 'author', 'processor', 'logs' => fn ($q) => $q->oldest()->limit(1)->with('reporter')]);

        // 상태 필터
        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $statuses = array_filter($statuses, fn ($v) => $v !== 'all');
            if (! empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        // 타입 필터
        if (! empty($filters['target_type'])) {
            $types = is_array($filters['target_type']) ? $filters['target_type'] : [$filters['target_type']];
            $types = array_filter($types, fn ($v) => $v !== 'all');
            if (! empty($types)) {
                $query->whereIn('target_type', $types);
            }
        }

        // 게시판 필터
        if (! empty($filters['board_id'])) {
            $query->where('board_id', $filters['board_id']);
        }

        // 날짜 필터
        if (! empty($filters['reported_at_from'])) {
            $query->where('last_reported_at', '>=', $filters['reported_at_from'].' 00:00:00');
        }
        if (! empty($filters['reported_at_to'])) {
            $query->where('last_reported_at', '<=', $filters['reported_at_to'].' 23:59:59');
        }

        // 검색
        $this->applySearchConditions($query, $filters, ['post_title']);

        // 서브쿼리: 신고 건수, 대상 상태, trigger_type, post_id
        $query->selectRaw("`{$fullTableName}`.*")
            ->selectRaw("(
                SELECT COUNT(*)
                FROM `{$logsTable}`
                WHERE `{$logsTable}`.report_id = `{$fullTableName}`.id
            ) AS report_count")
            ->selectRaw("(
                CASE `{$fullTableName}`.target_type
                    WHEN 'post' THEN (SELECT bp.status FROM `{$postsTable}` bp WHERE bp.board_id = `{$fullTableName}`.board_id AND bp.id = `{$fullTableName}`.target_id LIMIT 1)
                    WHEN 'comment' THEN (SELECT bc.status FROM `{$commentsTable}` bc WHERE bc.board_id = `{$fullTableName}`.board_id AND bc.id = `{$fullTableName}`.target_id LIMIT 1)
                END
            ) AS target_status")
            ->selectRaw("(
                CASE `{$fullTableName}`.target_type
                    WHEN 'post' THEN (SELECT bp.trigger_type FROM `{$postsTable}` bp WHERE bp.board_id = `{$fullTableName}`.board_id AND bp.id = `{$fullTableName}`.target_id LIMIT 1)
                    WHEN 'comment' THEN (SELECT bc.trigger_type FROM `{$commentsTable}` bc WHERE bc.board_id = `{$fullTableName}`.board_id AND bc.id = `{$fullTableName}`.target_id LIMIT 1)
                END
            ) AS target_trigger_type")
            ->selectRaw("(
                CASE `{$fullTableName}`.target_type
                    WHEN 'post' THEN `{$fullTableName}`.target_id
                    WHEN 'comment' THEN (SELECT bc.post_id FROM `{$commentsTable}` bc WHERE bc.board_id = `{$fullTableName}`.board_id AND bc.id = `{$fullTableName}`.target_id LIMIT 1)
                END
            ) AS target_post_id");

        // 대상 상태 필터
        if (! empty($filters['target_status'])) {
            $targetStatuses = is_array($filters['target_status']) ? $filters['target_status'] : [$filters['target_status']];
            $targetStatuses = array_filter($targetStatuses, fn ($v) => $v !== 'all');
            if (! empty($targetStatuses)) {
                $placeholders = implode(',', array_fill(0, count($targetStatuses), '?'));
                $query->whereRaw("(
                    CASE `{$fullTableName}`.target_type
                        WHEN 'post' THEN (SELECT bp.status FROM `{$postsTable}` bp WHERE bp.board_id = `{$fullTableName}`.board_id AND bp.id = `{$fullTableName}`.target_id LIMIT 1)
                        WHEN 'comment' THEN (SELECT bc.status FROM `{$commentsTable}` bc WHERE bc.board_id = `{$fullTableName}`.board_id AND bc.id = `{$fullTableName}`.target_id LIMIT 1)
                    END
                ) IN ({$placeholders})", $targetStatuses);
            }
        }

        // 정렬: last_reported_at DESC (재신고 시 위로 올라옴)
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy('last_reported_at', $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * 쿼리에 검색 조건을 적용합니다.
     *
     * 공통 검색 필드: board_name, author_name, reporter_name
     * 추가 검색 필드: $extraFields로 전달 (예: post_title)
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  쿼리 빌더
     * @param  array  $filters  필터 조건 (search, search_field 키 사용)
     * @param  array  $extraFields  추가 검색 필드 목록
     */
    private function applySearchConditions($query, array $filters, array $extraFields = []): void
    {
        if (empty($filters['search'])) {
            return;
        }

        $keyword = $filters['search'];
        $searchField = $filters['search_field'] ?? 'all';
        $lowerKeyword = mb_strtolower($keyword);

        $query->where(function ($q) use ($keyword, $lowerKeyword, $searchField, $extraFields) {
            // 추가 필드: post_title (paginateGrouped 전용 — logs 첫 번째 snapshot 기준)
            if (in_array('post_title', $extraFields) && ($searchField === 'all' || $searchField === 'post_title')) {
                $q->orWhereHas('logs', function ($lq) use ($keyword) {
                    DatabaseFulltextEngine::whereFulltext($lq, 'snapshot', $keyword);
                    $lq->oldest();
                });
            }

            // 게시판명 검색
            if ($searchField === 'all' || $searchField === 'board_name') {
                $q->orWhereHas('board', function ($bq) use ($keyword) {
                    DatabaseFulltextEngine::whereFulltext($bq, 'name', $keyword);
                });
            }

            // 작성자 검색
            if ($searchField === 'all' || $searchField === 'author_name') {
                $q->orWhereHas('author', function ($aq) use ($keyword) {
                    $aq->where('name', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%");
                });
            }

            // 신고자 검색 (logs → reporter 관계)
            if ($searchField === 'all' || $searchField === 'reporter_name') {
                $q->orWhereHas('logs.reporter', function ($rq) use ($keyword) {
                    $rq->where('name', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%");
                });
            }
        });
    }

    /**
     * 특정 대상(게시글/댓글)에 대한 케이스를 조회합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입 (post/comment)
     * @param  int  $targetId  대상 ID
     */
    public function findByTarget(int $boardId, string $targetType, int $targetId): \Illuminate\Database\Eloquent\Collection
    {
        return Report::where('board_id', $boardId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->get();
    }

    /**
     * 특정 대상에 대한 신고의 상태를 일괄 변경합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입
     * @param  int  $targetId  대상 ID
     * @param  array  $data  변경할 데이터
     * @return int 변경된 행 수
     */
    public function bulkUpdateStatusByTarget(int $boardId, string $targetType, int $targetId, array $data): int
    {
        // processed_by 자동 설정
        if (Auth::check() && ! isset($data['processed_by'])) {
            $data['processed_by'] = Auth::id();
        }

        // processed_at 자동 설정
        if (! isset($data['processed_at'])) {
            $data['processed_at'] = now();
        }

        return Report::where('board_id', $boardId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->update($data);
    }

    /**
     * 취소된 신고를 포함하여 동일 대상의 케이스를 조회합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입 (post/comment)
     * @param  int  $targetId  대상 ID
     */
    public function findByTargetWithTrashed(int $boardId, string $targetType, int $targetId): \Illuminate\Database\Eloquent\Collection
    {
        return Report::withTrashed()
            ->where('board_id', $boardId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->get();
    }

    /**
     * 사용자의 오늘(자정 기준) 전체 신고 건수를 boards_report_logs 기준으로 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return int 오늘 신고 건수
     */
    public function countTodayReportsByUser(int $userId): int
    {
        return ReportLog::where('reporter_id', $userId)
            ->whereDate('created_at', today())
            ->count();
    }

    /**
     * 사용자의 최근 N일 내 반려된 신고 건수를 조회합니다.
     * 반려 기준: boards_reports.processed_at >= now() - $days일 이고 status = rejected
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $days  조회 기간 (일)
     * @return int 반려 건수
     */
    public function countRejectedReportsByUser(int $userId, int $days): int
    {
        return ReportLog::where('reporter_id', $userId)
            ->whereHas('report', function ($q) use ($days) {
                $q->where('status', ReportStatus::Rejected)
                    ->where('processed_at', '>=', now()->subDays($days));
            })
            ->count();
    }

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
    public function countActiveReportsByTarget(int $boardId, string $targetType, int $targetId): int
    {
        $report = Report::where('board_id', $boardId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->whereIn('status', [ReportStatus::Pending, ReportStatus::Review])
            ->first();

        if (! $report) {
            return 0;
        }

        $query = ReportLog::where('report_id', $report->id);

        // last_activated_at이 있으면 해당 시점 이후 로그만 카운트 (현재 사이클)
        if ($report->last_activated_at) {
            $query->where('created_at', '>=', $report->last_activated_at);
        }

        return $query->count();
    }

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
    public function findOrCreateCase(int $boardId, string $targetType, int $targetId, array $createData = []): array
    {
        $report = Report::where('board_id', $boardId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        if ($report) {
            return ['report' => $report, 'created' => false];
        }

        $report = Report::create(array_merge([
            'board_id' => $boardId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => ReportStatus::Pending,
            'last_reported_at' => now(),
            'last_activated_at' => null,
        ], $createData));

        return ['report' => $report, 'created' => true];
    }

    /**
     * 신고 로그(신고자 기록)를 생성합니다.
     *
     * @param  array  $data  로그 생성 데이터
     * @return ReportLog 생성된 로그 모델
     *
     * @throws \Modules\Sirsoft\Board\Exceptions\DuplicateReportException
     */
    public function createLog(array $data): ReportLog
    {
        try {
            return ReportLog::create($data);
        } catch (QueryException $e) {
            // 중복 신고 감지 (SQLSTATE 23000: Integrity constraint violation)
            if ($e->getCode() == 23000) {
                throw new DuplicateReportException;
            }

            throw $e;
        }
    }

    /**
     * 신고 케이스의 신고자 로그를 페이지네이션으로 반환합니다.
     *
     * @param  int  $reportId  신고 케이스 ID
     * @param  int  $perPage   페이지당 항목 수
     * @param  int  $page      페이지 번호
     * @return LengthAwarePaginator
     */
    public function paginateLogsByReport(int $reportId, int $perPage = 10, int $page = 1): LengthAwarePaginator
    {
        return ReportLog::where('report_id', $reportId)
            ->with('reporter')
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 특정 사용자가 특정 대상을 이미 신고했는지 확인합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  대상 타입 (post/comment)
     * @param  int  $targetId  대상 ID
     * @return bool 이미 신고 여부
     */
    public function hasUserReported(int $userId, int $boardId, string $targetType, int $targetId): bool
    {
        return ReportLog::whereHas('report', function ($q) use ($boardId, $targetType, $targetId) {
            $q->where('board_id', $boardId)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId);
        })
            ->where('reporter_id', $userId)
            ->exists();
    }
}
