<?php

namespace Modules\Sirsoft\Board\Repositories;

use App\Helpers\PermissionHelper;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Search\KeywordSearch;
use App\Support\Query\PaginationLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Exceptions\DuplicateReportException;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
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
    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;

    /**
     * 신고 대상 종류(`target_type`) → 대상 모델 매핑.
     *
     * 신고는 게시글 또는 댓글을 가리키므로 대상 테이블이 갈린다. 갈래를 SQL CASE 문자열로
     * 잇는 대신 이 매핑을 돌며 갈래별 빌더 조건을 만든다.
     *
     * @var array<string, class-string<Model>>
     */
    private const TARGET_MODELS = [
        'post' => Post::class,
        'comment' => Comment::class,
    ];

    /**
     * 신고 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지네이션된 신고 목록
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Report::query();

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

        // 날짜 필터 (개별 처리) — 목록은 접수일을 보는 사람의 시간대로 그리므로 그 하루로 자른다.
        $this->applyViewerDateRangeFilter(
            $query,
            'created_at',
            $filters['reported_at_from'] ?? null,
            $filters['reported_at_to'] ?? null
        );

        // 지연 조인: inner 는 id 만 훑고, 관계 로딩은 해당 페이지에만 적용된다
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: [['column' => 'created_at', 'direction' => 'desc']],
            perPage: $perPage,
            relations: ['board', 'author', 'processor'],
            resultCap: PaginationLimits::resultCap('admin.reports'),
        );
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
     * @return Report|null 신고 모델 또는 null
     */
    public function find(int $id): ?Report
    {
        return Report::find($id);
    }

    /**
     * ID로 신고를 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  신고 ID
     * @return Report 신고 모델
     *
     * @throws ModelNotFoundException
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
     * @return Report 수정된 신고 모델
     *
     * @throws ModelNotFoundException
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
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
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
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
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

        // 목록과 같은 하루로 자른다 — 종료일을 'Y-m-d' 그대로 비교하면 그날 하루가 통째로 빠진다.
        if (! empty($filters['reported_at_from']) && ! empty($filters['reported_at_to'])) {
            $this->applyViewerDateRangeFilter(
                $query,
                'created_at',
                $filters['reported_at_from'],
                $filters['reported_at_to']
            );
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
     * @return LengthAwarePaginator 페이지네이션된 신고 케이스 목록
     */
    public function paginateGrouped(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        // 테이블명은 전부 모델에서 얻는다 (문자열 하드코딩 금지)
        $reportsTable = (new Report)->getTable();

        /**
         * 대상(게시글/댓글) 컬럼을 신고 행에 대응시켜 읽는 상관 서브쿼리를 만듭니다.
         *
         * 신고는 target_type 에 따라 게시글 또는 댓글을 가리키므로 대상 테이블이 갈린다.
         * 두 갈래를 CASE 로 잇는 대신, 갈래마다 빌더 서브쿼리를 만들고 그 중 조건에 맞는
         * 쪽만 값을 내도록 한다 — 결과는 같고 SQL 문자열 조립이 사라진다.
         *
         * @param  class-string<Model>  $model  대상 모델
         * @param  string  $targetType  이 갈래가 담당하는 target_type 값
         * @param  string  $column  읽어올 대상 컬럼
         * @return Builder 상관 서브쿼리
         */
        $targetColumn = function (string $model, string $targetType, string $column) use ($reportsTable) {
            return $model::query()
                ->select($column)
                ->whereColumn((new $model)->getTable().'.board_id', "{$reportsTable}.board_id")
                ->whereColumn((new $model)->getTable().'.id', "{$reportsTable}.target_id")
                ->where("{$reportsTable}.target_type", $targetType)
                ->limit(1);
        };

        $query = Report::query();

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

        // 날짜 필터 — 목록은 마지막 신고일을 보는 사람의 시간대로 그리므로 그 하루로 자른다.
        $this->applyViewerDateRangeFilter(
            $query,
            'last_reported_at',
            $filters['reported_at_from'] ?? null,
            $filters['reported_at_to'] ?? null
        );

        // 검색
        $this->applySearchConditions($query, $filters, ['post_title']);

        // 대상 상태 필터 — 대상 종류에 따라 갈리는 조건을 CASE 문자열 대신 갈래별 조건으로 쓴다
        if (! empty($filters['target_status'])) {
            $targetStatuses = is_array($filters['target_status']) ? $filters['target_status'] : [$filters['target_status']];
            $targetStatuses = array_filter($targetStatuses, fn ($v) => $v !== 'all');

            if (! empty($targetStatuses)) {
                $query->where(function (Builder $outerWhere) use ($targetStatuses, $reportsTable) {
                    foreach (self::TARGET_MODELS as $targetType => $model) {
                        $outerWhere->orWhere(function (Builder $branch) use ($targetType, $model, $targetStatuses, $reportsTable) {
                            $branch->where("{$reportsTable}.target_type", $targetType)
                                ->whereIn("{$reportsTable}.target_id", $model::query()
                                    ->select('id')
                                    ->whereIn('status', $targetStatuses)
                                    ->whereColumn((new $model)->getTable().'.board_id', "{$reportsTable}.board_id"));
                        });
                    }
                });
            }
        }

        // 정렬: last_reported_at DESC (재신고 시 위로 올라옴)
        $sortOrder = $this->normalizeSortDirection($filters['sort_order'] ?? null);

        $paginator = $this->paginateWithDeferredJoin(
            query: $query,
            columns: ["{$reportsTable}.*"],
            sort: [['column' => 'last_reported_at', 'direction' => $sortOrder]],
            perPage: $perPage,
            relations: ['board', 'author', 'processor', 'logs' => fn ($q) => $q->oldest()->limit(1)->with('reporter')],
            // 신고 건수와 대상(게시글/댓글) 정보는 스캔되는 행마다 계산되므로
            // outer(이번 페이지 $perPage 건)에서만 붙인다.
            outerUsing: function (Builder $outer) use ($reportsTable, $targetColumn) {
                $outer->select("{$reportsTable}.*")->withCount(['logs as report_count']);

                foreach (self::TARGET_MODELS as $targetType => $model) {
                    $outer->addSelect([
                        "target_status_{$targetType}" => $targetColumn($model, $targetType, 'status'),
                        "target_trigger_type_{$targetType}" => $targetColumn($model, $targetType, 'trigger_type'),
                    ]);
                }

                // 대상 글 ID — 게시글 신고는 target_id 자체, 댓글 신고는 그 댓글이 달린 글
                $outer->addSelect(['target_post_id_comment' => $targetColumn(Comment::class, 'comment', 'post_id')]);
            },
            resultCap: PaginationLimits::resultCap('admin.reports'),
        );

        // 갈래별로 나눠 읽은 대상 정보를 화면이 쓰는 단일 속성으로 합친다.
        // 이번 페이지 항목에만 적용되므로 추가 조회가 발생하지 않는다.
        return $paginator->through(function (Report $report) {
            $report->setAttribute('target_status', $report->target_status_post ?? $report->target_status_comment);
            $report->setAttribute('target_trigger_type', $report->target_trigger_type_post ?? $report->target_trigger_type_comment);
            $report->setAttribute(
                'target_post_id',
                $report->target_type === 'post' ? $report->target_id : $report->target_post_id_comment
            );

            return $report;
        });
    }

    /**
     * 쿼리에 검색 조건을 적용합니다.
     *
     * 공통 검색 필드: board_name, author_name, reporter_name
     * 추가 검색 필드: $extraFields로 전달 (예: post_title)
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  array  $filters  필터 조건 (search, search_field 키 사용)
     * @param  array  $extraFields  추가 검색 필드 목록
     * @return void
     */
    private function applySearchConditions($query, array $filters, array $extraFields = []): void
    {
        if (empty($filters['search'])) {
            return;
        }

        $keyword = $filters['search'];
        $searchField = $filters['search_field'] ?? 'all';
        $lowerKeyword = mb_strtolower($keyword);

        $query->where(function ($q) use ($keyword, $searchField, $extraFields) {
            // 추가 필드: post_title (paginateGrouped 전용 — logs 첫 번째 snapshot 기준)
            if (in_array('post_title', $extraFields) && ($searchField === 'all' || $searchField === 'post_title')) {
                $q->orWhereHas('logs', function ($lq) use ($keyword) {
                    KeywordSearch::apply($lq, 'snapshot', $keyword);
                    $lq->oldest();
                });
            }

            // 게시판명 검색
            if ($searchField === 'all' || $searchField === 'board_name') {
                $q->orWhereHas('board', function ($bq) use ($keyword) {
                    KeywordSearch::apply($bq, 'name', $keyword);
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
     * 사용자의 오늘 전체 신고 건수를 boards_report_logs 기준으로 조회합니다.
     *
     * 「오늘」은 신고하는 사람 자신의 하루다(보는 사람 시간대 — 7.1.0 O-CF3 · 원칙 「화면은 유저 시간대,
     * 기록은 UTC」). UTC 자정으로 자르면 한국 시간 새벽 0~9시 신고가 전날 한도에 섞인다.
     *
     * @param  int  $userId  사용자 ID
     * @return int 오늘 신고 건수
     */
    public function countTodayReportsByUser(int $userId): int
    {
        // whereDate 는 컬럼에 DATE() 를 씌워 인덱스를 무력화한다 — 범위 조건으로 준다.
        $query = ReportLog::where('reporter_id', $userId);
        $this->applyViewerTodayFilter($query, 'created_at');

        return $query->count();
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
     * @throws DuplicateReportException
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
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  페이지 번호
     * @return LengthAwarePaginator 페이지네이션된 신고자 로그
     */
    public function paginateLogsByReport(int $reportId, int $perPage = 10, int $page = 1): LengthAwarePaginator
    {
        return ReportLog::where('report_id', $reportId)
            ->with('reporter')
            ->latest()
            // 전순서 보장 — 같은 초에 기록된 처리 로그가 페이지 경계에서 뒤섞이지 않도록
            ->orderByDesc('id')
            // audit:allow repository-paginate-column-pruning reason: 신고 케이스 1건에 종속된 신고자 로그 —
            // where(report_id) 로 이미 좁혀져 OFFSET 이 깊어질 수 없다
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
    public function getReportedTargetIds(int $userId, int $boardId, string $targetType, array $targetIds): array
    {
        if (empty($targetIds)) {
            return [];
        }

        return Report::where('board_id', $boardId)
            ->where('target_type', $targetType)
            ->whereIn('target_id', $targetIds)
            ->whereHas('logs', fn ($q) => $q->where('reporter_id', $userId))
            ->pluck('target_id')
            ->all();
    }

    /**
     * ID 목록으로 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  ID 목록
     * @return Collection
     */
    public function findByIdsKeyed(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        return Report::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * 전체 게시판에서 미처리 신고를 최신순으로 조회합니다 (대시보드 신고 카드용).
     *
     * @param  int  $limit  조회 건수
     * @return Collection<int, Report> 미처리 신고 컬렉션
     */
    public function getPendingAcrossBoards(int $limit): Collection
    {
        $query = Report::query()
            ->whereIn('status', [ReportStatus::Pending, ReportStatus::Review])
            // 비활성 게시판의 신고는 대시보드 집계에서 제외한다(활성 게시판 스코프).
            ->whereHas('board', fn ($q) => $q->where('is_active', true));

        // 신고 조회 권한 스코프 필터링 (목록 엔드포인트와 동일 게이트)
        PermissionHelper::applyPermissionScope($query, 'sirsoft-board.reports.view');

        return $query
            ->with(['board', 'author'])
            ->orderByDesc('last_reported_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 전체 게시판의 미처리 신고 건수를 조회합니다 (대시보드 신고 카드 합계용).
     *
     * @return int 미처리 신고 건수
     */
    public function countPendingAcrossBoards(): int
    {
        $query = Report::query()
            ->whereIn('status', [ReportStatus::Pending, ReportStatus::Review])
            // 목록(getPendingAcrossBoards)과 동일 스코프여야 배지 건수와 목록 건수가 일치한다.
            ->whereHas('board', fn ($q) => $q->where('is_active', true));

        PermissionHelper::applyPermissionScope($query, 'sirsoft-board.reports.view');

        return $query->count();
    }

    /**
     * {@inheritDoc}
     */
    public function paginateLogsByReporter(int $reporterId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ReportLog::query()
            ->where('reporter_id', $reporterId)
            ->with(['report' => fn ($q) => $q->withTrashed()->with('board:id,slug,name')]);

        // 케이스 기준 필터는 whereHas 로 — 로그는 케이스의 상태·대상을 갖지 않는다.
        $caseFilters = array_filter([
            'status' => $filters['status'] ?? null,
            'target_type' => $filters['target_type'] ?? null,
            'board_slug' => $filters['board_slug'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($caseFilters !== []) {
            $query->whereHas('report', function (Builder $r) use ($caseFilters) {
                $r->withTrashed();

                if (isset($caseFilters['status'])) {
                    $r->where('status', $caseFilters['status']);
                }

                if (isset($caseFilters['target_type'])) {
                    $r->where('target_type', $caseFilters['target_type']);
                }

                if (isset($caseFilters['board_slug'])) {
                    $r->whereHas('board', fn (Builder $b) => $b->where('slug', $caseFilters['board_slug']));
                }
            });
        }

        $this->applyReporterSearch($query, (string) ($filters['search_field'] ?? 'title_content'), $filters['search'] ?? null);

        $direction = ($filters['sort'] ?? 'latest') === 'oldest' ? 'asc' : 'desc';

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            // 목록이 실제로 쓰는 컬럼만 — metadata(IP/UA)는 신고자 화면에 싣지 않으므로
            // 아예 읽지 않는다. 본인 로그만 보는 화면이라 OFFSET 이 크게 자라지 않는다.
            ->paginate($perPage, ['id', 'report_id', 'reporter_id', 'snapshot', 'reason_type', 'reason_detail', 'created_at']);

        $this->hydrateReportLogParentPosts($paginator->getCollection());

        return $paginator;
    }

    /**
     * {@inheritDoc}
     */
    public function countLogsByReporter(int $reporterId): int
    {
        return ReportLog::query()->where('reporter_id', $reporterId)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function findLogByIdAndReporter(int $logId, int $reporterId): ?ReportLog
    {
        return ReportLog::query()
            ->where('id', $logId)
            ->where('reporter_id', $reporterId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteLog(int $logId): bool
    {
        return (bool) ReportLog::query()->where('id', $logId)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function countLogsByReport(int $reportId): int
    {
        return ReportLog::query()->where('report_id', $reportId)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function latestLogCreatedAt(int $reportId)
    {
        return ReportLog::query()->where('report_id', $reportId)->max('created_at');
    }

    /**
     * {@inheritDoc}
     */
    public function countLogsByReportSince(int $reportId, ?\DateTimeInterface $since): int
    {
        return ReportLog::query()
            ->where('report_id', $reportId)
            ->when($since, fn (Builder $query) => $query->where('created_at', '>=', $since))
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function findLatestLogByReport(int $reportId): ?ReportLog
    {
        return ReportLog::query()
            ->where('report_id', $reportId)
            ->latest()
            ->latest('id')
            ->first();
    }

    /**
     * 댓글 대상 신고 로그의 부모 글 ID 를 일괄 프리로드합니다.
     *
     * 댓글 신고는 대상이 댓글이라 "어느 글의 댓글인지" 를 알아야 이동 링크를 만든다 —
     * 로그마다 조회하면 N+1 이므로 한 번에 맵으로 싣는다.
     *
     * @param  \Illuminate\Support\Collection<int, ReportLog>  $logs  신고 로그 목록
     * @return void
     */
    private function hydrateReportLogParentPosts($logs): void
    {
        $commentIds = $logs
            ->filter(fn ($log) => $log->report?->target_type?->value === 'comment')
            ->map(fn ($log) => (int) $log->report->target_id)
            ->unique()
            ->values()
            ->all();

        if ($commentIds === []) {
            return;
        }

        $postIds = Comment::query()
            ->withTrashed()
            ->whereIn('id', $commentIds)
            ->pluck('post_id', 'id');

        foreach ($logs as $log) {
            if ($log->report?->target_type?->value !== 'comment') {
                continue;
            }

            $log->parent_post_id_preloaded = $postIds[(int) $log->report->target_id] ?? null;
        }
    }

    /**
     * 신고자 목록의 검색 조건을 적용합니다.
     *
     * 제목·내용은 신고 시점 스냅샷(JSON)에서 찾는다 — 원본이 수정·삭제돼도 신고자가
     * "무엇을 신고했는지" 는 스냅샷 기준이어야 목록이 흔들리지 않는다.
     * FULLTEXT 는 쓰지 않는다(JSON 추출값 대상).
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $field  검색 대상
     * @param  string|null  $search  검색어
     * @return void
     */
    private function applyReporterSearch(Builder $query, string $field, ?string $search): void
    {
        if ($search === null || trim($search) === '') {
            return;
        }

        $like = '%'.addcslashes(trim($search), '%_'.chr(92)).'%';

        $query->where(function (Builder $outer) use ($field, $like) {
            if (in_array($field, ['title', 'title_content'], true)) {
                $outer->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(snapshot, "$.title")) LIKE ?', [$like]);
            }

            if (in_array($field, ['content', 'title_content'], true)) {
                $outer->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(snapshot, "$.content")) LIKE ?', [$like]);
            }

            if ($field === 'reason') {
                $outer->orWhere('reason_detail', 'like', $like);
            }
        });
    }

    /**
     * 신고 대상의 스냅샷에서 개인정보 필드를 덮어씁니다 (개인정보 보유기간 처리, 이슈 #636).
     *
     * 스냅샷에 그 키가 없는 로그는 건드리지 않는다. 수정 시각을 바꾸지 않도록 타임스탬프를
     * 끄고 모델 이벤트 없이 저장한다 — 파기는 신고 처리 이력이 아니다.
     *
     * @param  string  $targetType  신고 대상 종류 (post|comment)
     * @param  array<int, int>  $targetIds  대상 ID 목록
     * @param  array<string, string>  $replacements  스냅샷 키 => 치환값
     * @return int 갱신한 신고 로그 수
     */
    public function scrubSnapshotsForTargets(string $targetType, array $targetIds, array $replacements): int
    {
        if ($targetIds === [] || $replacements === []) {
            return 0;
        }

        $reportIds = Report::withTrashed()
            ->where('target_type', $targetType)
            ->whereIn('target_id', $targetIds)
            ->pluck('id')
            ->all();

        if ($reportIds === []) {
            return 0;
        }

        $updated = 0;

        ReportLog::query()
            ->whereIn('report_id', $reportIds)
            ->whereNotNull('snapshot')
            ->chunkById(200, function ($logs) use ($replacements, &$updated) {
                foreach ($logs as $log) {
                    $snapshot = is_array($log->snapshot) ? $log->snapshot : [];
                    $changed = false;

                    foreach ($replacements as $key => $value) {
                        if (array_key_exists($key, $snapshot) && $snapshot[$key] !== $value) {
                            $snapshot[$key] = $value;
                            $changed = true;
                        }
                    }

                    if (! $changed) {
                        continue;
                    }

                    $log->snapshot = $snapshot;
                    $log->timestamps = false;
                    $log->saveQuietly();
                    $updated++;
                }
            });

        return $updated;
    }
}
