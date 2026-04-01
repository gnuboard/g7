<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;

/**
 * 신고 관리 서비스 클래스
 *
 * 신고의 생성, 수정, 삭제 등 비즈니스 로직을 담당하며,
 * 훅 시스템과 스냅샷 관리 기능을 제공합니다.
 *
 * 1케이스 구조: boards_reports = 게시글/댓글당 1행(케이스), boards_report_logs = 신고자별 기록
 */
class ReportService
{
    /**
     * PostService 인스턴스 (lazy loading)
     */
    private ?PostService $postService = null;

    /**
     * CommentService 인스턴스 (lazy loading)
     */
    private ?CommentService $commentService = null;

    /**
     * ReportService 생성자
     */
    public function __construct(
        private ReportRepositoryInterface $reportRepository,
        private PostRepositoryInterface $postRepository,
        private CommentRepositoryInterface $commentRepository,
        private BoardRepositoryInterface $boardRepository,
        private UserRepositoryInterface $userRepository
    ) {}

    /**
     * PostService 인스턴스를 반환합니다 (lazy loading).
     *
     * @return PostService
     */
    protected function getPostService(): PostService
    {
        if ($this->postService === null) {
            $this->postService = app(PostService::class);
        }

        return $this->postService;
    }

    /**
     * CommentService 인스턴스를 반환합니다 (lazy loading).
     *
     * @return CommentService
     */
    protected function getCommentService(): CommentService
    {
        if ($this->commentService === null) {
            $this->commentService = app(CommentService::class);
        }

        return $this->commentService;
    }

    /**
     * 신고 목록을 조회합니다 (개별 신고 단위).
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getReports(array $filters = [], int $perPage = 15)
    {
        return $this->reportRepository->paginate($filters, $perPage);
    }

    /**
     * 케이스 기준 신고 목록을 조회합니다.
     * 1케이스 = 1행이므로 boards_reports를 직접 페이지네이션합니다.
     * last_reported_at DESC 정렬로 재신고 시 위로 올라옵니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getGroupedReports(array $filters = [], int $perPage = 15)
    {
        return $this->reportRepository->paginateGrouped($filters, $perPage);
    }

    /**
     * ID로 신고를 조회합니다.
     *
     * @param  int  $id  신고 ID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getReport(int $id): Report
    {
        $report = $this->reportRepository->findOrFail($id);

        // 권한 스코프 접근 체크 (본인만/역할/전체)
        if (! PermissionHelper::checkScopeAccess($report, 'sirsoft-board.reports.view')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        return $report;
    }

    /**
     * 케이스의 상세 정보를 조회합니다.
     * 1케이스 구조이므로 케이스(report) + 신고자 목록(logs) + 처리 이력(process_histories)을 반환합니다.
     *
     * @param  int  $id  케이스 ID
     * @return array{report: Report, reporters: \Illuminate\Database\Eloquent\Collection, cancelled_reports: \Illuminate\Database\Eloquent\Collection, report_count: int, first_reported_at: mixed, last_reported_at: mixed}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getGroupedReportDetail(int $id): array
    {
        $report = $this->reportRepository->findOrFail($id);

        // 권한 스코프 접근 체크 (본인만/역할/전체)
        if (! PermissionHelper::checkScopeAccess($report, 'sirsoft-board.reports.view')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
        
        // 신고자 로그 목록 로드 (reporter 관계 eager load)
        $report->load(['logs' => fn ($q) => $q->latest()->with('reporter'), 'board']);

        $logs = $report->logs ?? collect();
        $reportCount = $logs->count();
        $firstReportedAt = $reportCount > 0 ? $logs->min('created_at') : $report->created_at;
        $lastReportedAt = $report->last_reported_at ?? ($reportCount > 0 ? $logs->max('created_at') : $report->created_at);

        return [
            'report' => $report,
            'reporters' => $logs,
            'cancelled_reports' => collect(), // 케이스 1개 구조에서는 취소된 별도 케이스 없음 (soft delete만 존재)
            'report_count' => $reportCount,
            'first_reported_at' => $firstReportedAt,
            'last_reported_at' => $lastReportedAt,
        ];
    }

    /**
     * 신고 케이스의 신고자 목록을 페이지네이션으로 반환합니다.
     *
     * @param  int  $id       신고 케이스 ID
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page     페이지 번호
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function paginateReporters(int $id, int $perPage = 10, int $page = 1): \Illuminate\Pagination\LengthAwarePaginator
    {
        $this->reportRepository->findOrFail($id); // 케이스 존재 확인 (404 처리)

        return $this->reportRepository->paginateLogsByReport($id, $perPage, $page);
    }

    /**
     * 신고를 생성합니다 (케이스 기반).
     *
     * 1케이스 구조: (board_id, target_type, target_id) 기준으로 케이스를 조회하거나 신규 생성합니다.
     * - 신규 케이스: boards_reports INSERT (pending) + boards_report_logs INSERT
     * - 기존 활성 케이스(pending/review): boards_report_logs INSERT + last_reported_at 갱신
     * - 기존 닫힌 케이스(rejected/suspended): pending 재활성 + last_activated_at 갱신 + boards_report_logs INSERT
     *
     * @param  array  $data  신고 생성 데이터
     * @return Report 케이스 모델
     *
     * @throws \Modules\Sirsoft\Board\Exceptions\DuplicateReportException
     */
    public function createReport(array $data): Report
    {
        // 훅: before_create
        HookManager::doAction('sirsoft-board.report.before_create', $data);

        // 스냅샷 및 작성자 정보 수집 (로그용)
        $logData = $this->collectSnapshotData($data);

        $boardId = $data['board_id'];
        $targetType = $data['target_type'];
        $targetId = $data['target_id'];

        // 케이스 생성 데이터 (reporter_id 제외)
        $caseCreateData = [
            'author_id' => $logData['author_id'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ];

        // 케이스 조회 또는 신규 생성
        ['report' => $report, 'created' => $caseCreated] = $this->reportRepository->findOrCreateCase(
            $boardId,
            $targetType,
            $targetId,
            $caseCreateData
        );

        // 훅: filter_create_data (케이스 생성 후 훅으로 추가 필터링 가능하도록)
        $data = HookManager::applyFilters('sirsoft-board.report.filter_create_data', $data);

        // 재활성 여부 사전 판별 (createLog 전 상태 기준)
        $isReactivation = ! $caseCreated
            && in_array($report->status, [ReportStatus::Rejected, ReportStatus::Suspended]);

        // 신고자 로그 INSERT (DuplicateReportException은 Repository에서 throw)
        $this->reportRepository->createLog([
            'report_id' => $report->id,
            'reporter_id' => $data['reporter_id'] ?? null,
            'snapshot' => $logData['snapshot'] ?? null,
            'reason_type' => $data['reason_type'] ?? null,
            'reason_detail' => $data['reason_detail'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        // 현재 활성 사이클 신고 수 (last_activated_at 이후 logs 기준)
        $currentCycleCount = $this->reportRepository->countActiveReportsByTarget($boardId, $targetType, $targetId);

        $now = now();

        if ($caseCreated) {
            // 신규 케이스: process_histories에 'reported' 항목 추가
            $histories = [
                $this->createHistoryEntry('reported', null, null, $now, $currentCycleCount),
            ];
            $report->update([
                'last_reported_at' => $now,
                'process_histories' => $histories,
            ]);
        } else {
            // 기존 케이스
            $existingHistories = $report->process_histories ?? [];

            if ($isReactivation) {
                // 닫힌 케이스(rejected/suspended) → pending 재활성
                $existingHistories[] = $this->createHistoryEntry('re_reported', null, null, $now, $currentCycleCount);
                $report->update([
                    'status' => ReportStatus::Pending,
                    'last_reported_at' => $now,
                    'last_activated_at' => $now,
                    'process_histories' => $existingHistories,
                ]);
            } else {
                // 활성 케이스(pending/review) → 유지, reported 이력 추가
                // last_reported_at은 재접수(re_reported) 시에만 갱신 (목록 정렬 기준)
                $existingHistories[] = $this->createHistoryEntry('reported', null, null, $now, $currentCycleCount);
                $report->update([
                    'process_histories' => $existingHistories,
                ]);
            }
        }

        // DB에서 최신 케이스 다시 로드
        $report->refresh();

        // 자동 블라인드 체크 및 적용 (신고 생성 성공 후 독립 실행)
        // 재활성 케이스: countActiveReportsByTarget() 재호출 시 last_activated_at과 이전 사이클 로그의
        // created_at이 같은 초(second)에 처리되어 이전 사이클 로그가 포함될 수 있음.
        // 재활성 첫 신고는 항상 정확히 1건이므로 1을 직접 전달.
        // 신규/활성 케이스: countActiveReportsByTarget()이 정확한 값을 반환하므로 그대로 사용.
        $autoHideCount = $isReactivation ? 1 : $currentCycleCount;
        $this->checkAndApplyAutoHide($report, $autoHideCount);

        // 훅: after_create
        HookManager::doAction('sirsoft-board.report.after_create', $report);

        return $report;
    }

    /**
     * 신고 상태를 변경합니다 (숨김 처리 포함).
     *
     * @param  int  $id  신고 ID
     * @param  array  $data  변경할 데이터 (status, process_note)
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function updateReportStatus(int $id, array $data): Report
    {
        $report = $this->reportRepository->findOrFail($id);

        // 권한 스코프 접근 체크 (본인만/역할/전체)
        if (! PermissionHelper::checkScopeAccess($report, 'sirsoft-board.reports.manage')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        // deleted 상태에서는 다른 상태로 변경 불가
        if ($report->status === ReportStatus::Deleted) {
            throw new \Exception(__('sirsoft-board::messages.reports.cannot_change_deleted_status'));
        }

        // 훅: before_update_status
        HookManager::doAction('sirsoft-board.report.before_update_status', $report, $data);

        // 기존 상태 저장 (복구 판단용)
        $oldStatus = $report->status;

        // processed_by, processed_at 자동 설정
        $adminId = Auth::id();
        $processedAt = now();

        $data['processed_by'] = $adminId;
        $data['processed_at'] = $processedAt;

        // 이력 항목 생성 및 추가
        $historyEntry = $this->createHistoryEntry(
            $data['status'] ?? $report->status->value,
            $data['process_note'] ?? null,
            $adminId,
            $processedAt
        );

        // 기존 이력에 새 항목 추가
        $existingHistories = $report->process_histories ?? [];
        $existingHistories[] = $historyEntry;
        $data['process_histories'] = $existingHistories;

        // process_note는 이력에 포함되므로 제거
        unset($data['process_note']);

        // 상태 업데이트
        $updatedReport = $this->reportRepository->update($id, $data);

        // 게시글/댓글 상태 자동 변경 (업데이트된 인스턴스 사용)
        if (isset($data['status'])) {
            $newStatus = ReportStatus::from($data['status']);
            // Repository update 후 fresh 인스턴스를 다시 조회하여 관계 로드
            $freshReport = $this->reportRepository->findOrFail($id);
            $this->applyReportActionToContent($freshReport, $newStatus, $oldStatus);

            // 자동 블라인드 복구: 반려 시 DB 저장 완료 후 활성 신고 0건이면 복구
            if ($newStatus === ReportStatus::Rejected) {
                $this->checkAndRestoreBlindedTarget($freshReport);
            }
        }

        // 훅: after_update_status
        HookManager::doAction('sirsoft-board.report.after_update_status', $updatedReport);

        return $updatedReport;
    }

    /**
     * 여러 케이스의 상태를 일괄 변경합니다.
     * 1케이스 구조이므로 expandGroupedReportIds 불필요 — $ids 그대로 처리합니다.
     *
     * @param  array  $ids  케이스 ID 배열
     * @param  array  $data  변경할 데이터
     * @return array{affected_count: int, restored_count: int, manual_blind_restored: int} 처리 결과
     */
    public function bulkUpdateStatus(array $ids, array $data): array
    {
        // 훅: before_bulk_update_status
        HookManager::doAction('sirsoft-board.report.before_bulk_update_status', $ids, $data);

        $snapshots = Report::whereIn('id', $ids)->get()->keyBy('id')->map(fn ($r) => $r->toArray())->all();

        // 처리 정보 설정
        $adminId = Auth::id();
        $processedAt = now();

        // 이력 항목 생성
        $historyEntry = $this->createHistoryEntry(
            $data['status'] ?? 'pending',
            $data['process_note'] ?? null,
            $adminId,
            $processedAt
        );

        $affectedRows = 0;
        $restoredCount = 0;
        $manualBlindRestored = 0;
        $processedTargets = []; // 이미 처리한 대상 추적 (중복 게시글/댓글 상태 변경 방지)

        // 케이스별로 처리 (deleted 상태 항목은 스킵)
        foreach ($ids as $id) {
            try {
                $report = $this->reportRepository->findOrFail($id);

                // deleted 상태 항목은 스킵
                if ($report->status === ReportStatus::Deleted) {
                    continue;
                }

                $oldStatus = $report->status;

                $existingHistories = $report->process_histories ?? [];
                $existingHistories[] = $historyEntry;

                $updateData = [
                    'status' => $data['status'],
                    'processed_by' => $adminId,
                    'processed_at' => $processedAt,
                    'process_histories' => $existingHistories,
                ];

                $this->reportRepository->update($id, $updateData);
                $affectedRows++;

                // 게시글/댓글 상태 자동 변경 (케이스당 1번만 — 케이스 1개 구조이므로 사실상 항상 실행)
                if (isset($data['status'])) {
                    $targetKey = "{$report->board_id}:{$report->target_type->value}:{$report->target_id}";

                    if (! in_array($targetKey, $processedTargets)) {
                        $newStatus = ReportStatus::from($data['status']);
                        // Repository update 후 fresh 인스턴스를 다시 조회하여 관계 로드
                        $freshReport = $this->reportRepository->findOrFail($id);
                        $actionResult = $this->applyReportActionToContent($freshReport, $newStatus, $oldStatus);

                        // 복구 건수 집계
                        if ($actionResult['action'] === 'restore') {
                            $restoredCount++;
                            if ($actionResult['was_manual_blind']) {
                                $manualBlindRestored++;
                            }
                        }

                        // 처리 완료 표시
                        $processedTargets[] = $targetKey;
                    }
                }
            } catch (\Exception $e) {
                // 존재하지 않는 ID는 스킵
                Log::warning('Failed to update report status', [
                    'report_id' => $id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }

        // 자동 블라인드 복구: 반려 시 모든 케이스 DB 저장 완료 후 대상별 1회 체크
        if (isset($data['status']) && ReportStatus::from($data['status']) === ReportStatus::Rejected) {
            foreach ($processedTargets as $targetKey) {
                [$boardId, $targetType, $targetId] = explode(':', $targetKey);

                // 케이스 ID를 $ids 중에서 찾아 로드 (findOrCreateCase는 없으므로 findOrFail 활용)
                // 이미 처리된 케이스를 대표로 재조회
                foreach ($ids as $id) {
                    try {
                        $r = $this->reportRepository->findOrFail($id);
                        if (
                            (string) $r->board_id === $boardId &&
                            $r->target_type->value === $targetType &&
                            (string) $r->target_id === $targetId
                        ) {
                            $this->checkAndRestoreBlindedTarget($r);
                            break;
                        }
                    } catch (\Exception $e) {
                        // 스킵
                    }
                }
            }
        }

        // 훅: after_bulk_update_status
        HookManager::doAction('sirsoft-board.report.after_bulk_update_status', $ids, $data, $affectedRows, $snapshots);

        return [
            'affected_count' => $affectedRows,
            'restored_count' => $restoredCount,
            'manual_blind_restored' => $manualBlindRestored,
        ];
    }

    /**
     * 처리 이력 항목을 생성합니다.
     *
     * @param  string  $action  처리 액션 (status 값 또는 reported/re_reported 등 특수 타입)
     * @param  string|null  $reason  처리 사유
     * @param  int|null  $processorId  처리자 ID (신고 접수 시 null)
     * @param  \Carbon\Carbon  $processedAt  처리 일시
     * @param  int|null  $reporterCount  현재 사이클 신고자 수 (reported/re_reported 시 사용)
     * @return array 이력 항목
     */
    protected function createHistoryEntry(
        string $action,
        ?string $reason,
        ?int $processorId,
        $processedAt,
        ?int $reporterCount = null
    ): array {
        // 상태 값으로 라벨 가져오기 (reported/re_reported는 ReportStatus에 없으므로 그대로 사용)
        $actionLabel = ReportStatus::tryFrom($action)?->label() ?? $action;

        $entry = [
            'type' => $action,
            'action_label' => $actionLabel,
            'processor_id' => $processorId,
            'reason' => $reason,
            'created_at' => $processedAt->format('Y-m-d H:i:s'),
        ];

        if ($reporterCount !== null) {
            $entry['reporter_count'] = $reporterCount;
        }

        return $entry;
    }

    /**
     * 신고를 삭제합니다 (소프트 삭제).
     *
     * @param  int  $id  신고 ID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function deleteReport(int $id): bool
    {
        $report = $this->reportRepository->findOrFail($id);

        // 권한 스코프 접근 체크 (본인만/역할/전체)
        if (! PermissionHelper::checkScopeAccess($report, 'sirsoft-board.reports.manage')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        // 훅: before_delete
        HookManager::doAction('sirsoft-board.report.before_delete', $report);

        $result = $this->reportRepository->delete($id);

        // 훅: after_delete
        HookManager::doAction('sirsoft-board.report.after_delete', $report);

        return $result;
    }

    /**
     * 신고 통계를 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @return array{by_status: array, by_type: array, total: int}
     */
    public function getStatistics(array $filters = []): array
    {
        return $this->reportRepository->getStatistics($filters);
    }

    /**
     * 신고 상태에 따라 게시글/댓글에 적절한 조치를 적용합니다.
     *
     * @param  Report  $report  신고 모델
     * @param  ReportStatus  $newStatus  새로운 신고 상태
     * @param  ReportStatus  $oldStatus  이전 신고 상태
     * @return array{action: string, was_manual_blind: bool} 적용된 조치 정보
     */
    protected function applyReportActionToContent(Report $report, ReportStatus $newStatus, ReportStatus $oldStatus): array
    {
        // 복구가 필요한 경우 (게시중단/삭제 → 접수/검토/반려)
        if ($this->shouldRestoreContent($oldStatus, $newStatus)) {
            $wasManualBlind = $this->isManualBlindTarget($report);
            $this->restoreContent($report);

            return ['action' => 'restore', 'was_manual_blind' => $wasManualBlind];
        }

        // 블라인드 처리
        if ($newStatus === ReportStatus::Suspended) {
            $this->blindContent($report);

            return ['action' => 'blind', 'was_manual_blind' => false];
        }

        // 영구 삭제
        if ($newStatus === ReportStatus::Deleted) {
            $this->deleteContent($report);

            return ['action' => 'delete', 'was_manual_blind' => false];
        }

        return ['action' => 'none', 'was_manual_blind' => false];
    }

    /**
     * 대상이 관리자 수동 블라인드인지 확인합니다.
     *
     * @param  Report  $report  신고 모델
     * @return bool 관리자 수동 블라인드 여부
     */
    protected function isManualBlindTarget(Report $report): bool
    {
        try {
            $target = $report->target_type->value === 'post'
                ? $this->postRepository->findByBoardId($report->board_id, $report->target_id)
                : $this->commentRepository->findByBoardId($report->board_id, $report->target_id);

            return $target && $target->trigger_type === TriggerType::Admin;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 게시글/댓글 복구가 필요한지 판단합니다.
     *
     * 신고가 반려(Rejected)될 때만 게시글/댓글을 복구합니다.
     * 접수(Pending)/검토(Review)로 되돌리는 것은 "다시 살펴보는 것"이므로 복구하지 않습니다.
     *
     * @param  ReportStatus  $oldStatus  이전 신고 상태
     * @param  ReportStatus  $newStatus  새로운 신고 상태
     * @return bool 복구 필요 여부
     */
    protected function shouldRestoreContent(ReportStatus $oldStatus, ReportStatus $newStatus): bool
    {
        $wasHidden = in_array($oldStatus, [ReportStatus::Suspended, ReportStatus::Deleted]);
        $isRejected = $newStatus === ReportStatus::Rejected;

        return $wasHidden && $isRejected;
    }

    /**
     * 게시글/댓글을 복구합니다 (published 상태로 변경).
     * Service를 통해 호출하여 훅이 실행되도록 합니다.
     *
     * @param  Report  $report  신고 모델
     */
    protected function restoreContent(Report $report): void
    {
        if (! $report->board) {
            return;
        }

        $slug = $report->board->slug;
        $targetId = $report->target_id;
        $reason = __('sirsoft-board::messages.reports.restore_by_report');

        try {
            if ($report->target_type->value === 'post') {
                $this->getPostService()->restorePost($slug, $targetId, $reason, 'report');
            } else {
                $this->getCommentService()->restoreComment($slug, $targetId, $reason, 'report');
            }
        } catch (\Exception $e) {
            Log::error('Failed to restore content', [
                'report_id' => $report->id,
                'target_type' => $report->target_type->value,
                'target_id' => $targetId,
                'error' => $e->getMessage(),
            ]);
        }

        HookManager::doAction('sirsoft-board.report.after_restore_content', $report);
    }

    /**
     * 게시글/댓글을 블라인드 처리합니다.
     * Service를 통해 호출하여 훅이 실행되도록 합니다.
     *
     * @param  Report  $report  신고 모델
     */
    protected function blindContent(Report $report): void
    {
        if (! $report->board) {
            return;
        }

        $slug = $report->board->slug;
        $targetId = $report->target_id;
        $reason = __('sirsoft-board::messages.reports.blind_by_report');

        try {
            if ($report->target_type->value === 'post') {
                $this->getPostService()->blindPost($slug, $targetId, $reason, 'report');
            } else {
                $this->getCommentService()->blindComment($slug, $targetId, $reason, 'report');
            }
        } catch (\Exception $e) {
            Log::error('Failed to blind content', [
                'report_id' => $report->id,
                'target_type' => $report->target_type->value,
                'target_id' => $targetId,
                'error' => $e->getMessage(),
            ]);
        }

        HookManager::doAction('sirsoft-board.report.after_blind_content', $report);
    }

    /**
     * 자동 블라인드 조건을 확인하고 해당 시 적용합니다.
     *
     * threshold 이상의 현재 사이클 신고(last_activated_at 이후 logs)가 누적되면
     * 대상 게시글/댓글을 자동으로 블라인드 처리합니다.
     *
     * @param  Report  $report  생성된 신고 모델
     * @param  int|null  $cycleCount  현재 사이클 신고 수 (null 시 DB 재조회)
     */
    private function checkAndApplyAutoHide(Report $report, ?int $cycleCount = null): void
    {
        // 필수 필드 없으면 skip (Mock 등 불완전한 Report 방어)
        if (! $report->board_id || ! $report->target_type || ! $report->target_id) {
            return;
        }

        $settings = app(BoardSettingsService::class)->getSettings('report_policy');
        $threshold = (int) ($settings['auto_hide_threshold'] ?? 0);
        $target = $settings['auto_hide_target'] ?? 'both';

        // threshold = 0이면 비활성
        if ($threshold <= 0) {
            return;
        }

        // auto_hide_target 설정에 따라 대상 유형 필터
        $targetType = $report->target_type->value;
        if ($target !== 'both' && $target !== $targetType) {
            return;
        }

        // 현재 사이클 신고 수: 전달값 우선 사용, 없으면 DB 조회
        $count = $cycleCount ?? $this->reportRepository->countActiveReportsByTarget(
            $report->board_id,
            $targetType,
            $report->target_id
        );

        if ($count < $threshold) {
            return;
        }

        $this->blindContentForAutoHide($report);
    }

    /**
     * 자동 블라인드 처리를 실행합니다.
     *
     * trigger_type을 'auto_hide'로 기록하여 관리자 수동 블라인드와 구분합니다.
     * blindPost/blindComment의 멱등성 체크로 중복 블라인드가 방지됩니다.
     *
     * @param  Report  $report  신고 모델
     */
    private function blindContentForAutoHide(Report $report): void
    {
        $report->load('board');
        if (! $report->board) {
            return;
        }

        $slug = $report->board->slug;
        $targetId = $report->target_id;
        $reason = __('sirsoft-board::messages.reports.blind_by_auto_hide');

        try {
            if ($report->target_type->value === 'post') {
                $this->getPostService()->blindPost($slug, $targetId, $reason, 'auto_hide');
            } else {
                $this->getCommentService()->blindComment($slug, $targetId, $reason, 'auto_hide');
            }
        } catch (\Exception $e) {
            Log::error('Auto-hide failed', [
                'report_id' => $report->id,
                'target_type' => $report->target_type->value,
                'target_id' => $targetId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 신고 반려 시 블라인드된 대상의 복구 여부를 확인하고 복구합니다.
     *
     * trigger_type에 관계없이(auto_hide, report, system, admin 모두) 블라인드 상태이고
     * 해당 대상의 남은 활성 신고(현재 사이클 logs 기준)가 0건이면 복구합니다.
     *
     * 1케이스 구조에서 케이스가 rejected이면 countActiveReportsByTarget은 0 → 즉시 복구.
     *
     * @param  Report  $report  신고 모델
     * @return array{action: string, was_manual_blind: bool}|null 복구 시 결과, 미복구 시 null
     */
    protected function checkAndRestoreBlindedTarget(Report $report): ?array
    {
        try {
            $target = $report->target_type->value === 'post'
                ? $this->postRepository->findByBoardId($report->board_id, $report->target_id)
                : $this->commentRepository->findByBoardId($report->board_id, $report->target_id);

            if (! $target) {
                return null;
            }

            // blinded 상태가 아니면 복구 불필요
            if ($target->status !== PostStatus::Blinded) {
                return null;
            }

            // 현재 신고는 이미 rejected로 변경된 후이므로 활성 신고 재조회
            // 1케이스 구조에서는 케이스가 1개이고 rejected이므로 countActiveReportsByTarget = 0
            $activeCount = $this->reportRepository->countActiveReportsByTarget(
                $report->board_id,
                $report->target_type->value,
                $report->target_id
            );

            if ($activeCount > 0) {
                return null;
            }

            $wasManualBlind = $target->trigger_type === TriggerType::Admin;
            $this->restoreContent($report);

            return ['action' => 'restore', 'was_manual_blind' => $wasManualBlind];
        } catch (\Exception $e) {
            Log::error('Blinded target restore check failed', [
                'report_id' => $report->id,
                'target_type' => $report->target_type->value,
                'target_id' => $report->target_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 게시글/댓글을 영구 삭제합니다.
     * Service를 통해 호출하여 훅이 실행되도록 합니다.
     *
     * @param  Report  $report  신고 모델
     */
    protected function deleteContent(Report $report): void
    {
        if (! $report->board) {
            return;
        }

        $slug = $report->board->slug;
        $targetId = $report->target_id;

        try {
            if ($report->target_type->value === 'post') {
                $this->getPostService()->deletePost($slug, $targetId, 'report');
            } else {
                $this->getCommentService()->deleteComment($slug, $targetId, 'report');
            }
        } catch (\Exception $e) {
            Log::error('Failed to delete content', [
                'report_id' => $report->id,
                'target_type' => $report->target_type->value,
                'target_id' => $targetId,
                'error' => $e->getMessage(),
            ]);
        }

        HookManager::doAction('sirsoft-board.report.after_delete_content', $report);
    }

    /**
     * 스냅샷 데이터를 수집하여 신고 로그(createLog) 용 데이터를 반환합니다.
     *
     * @param  array  $data  신고 생성 데이터 (board_id, target_type, target_id, reporter_id 포함)
     * @return array 스냅샷과 작성자 정보가 포함된 데이터 배열
     */
    protected function collectSnapshotData(array $data): array
    {
        $boardId = $data['board_id'];
        $targetType = $data['target_type'];
        $targetId = $data['target_id'];

        // 게시판 정보 조회
        $board = $this->boardRepository->find($boardId);
        if (! $board) {
            return $data;
        }

        // 신고 대상 데이터 조회
        $reportableData = $this->getReportableData($board->slug, $targetType, $targetId);

        // snapshot JSON 구성
        $snapshot = [
            'board_name' => $board->getLocalizedName(),
            'title' => null,
            'content' => '',
            'content_mode' => 'text',
            'author_name' => '',
        ];

        if ($reportableData) {
            $snapshot['title'] = $reportableData->title ?? null;
            $snapshot['content'] = $reportableData->content ?? '';
            $snapshot['content_mode'] = $reportableData->content_mode ?? 'text';
            if (isset($reportableData->post_id)) {
                $snapshot['post_id'] = $reportableData->post_id;
            }

            // 작성자 ID 및 스냅샷: 작성자명
            if ($reportableData->user_id) {
                $user = $this->userRepository->findById($reportableData->user_id);
                if ($user) {
                    $data['author_id'] = $user->id;
                    $snapshot['author_name'] = $user->name;
                } else {
                    $data['author_id'] = null;
                }
            } else {
                $data['author_id'] = null;
                $snapshot['author_name'] = $reportableData->author_name ?? '';
            }
        }

        $data['snapshot'] = $snapshot;

        return $data;
    }

    /**
     * 신고 대상 데이터를 동적 테이블에서 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $targetType  신고 대상 타입 (post, comment)
     * @param  int  $targetId  신고 대상 ID
     * @return \stdClass|null 조회된 데이터 (StdClass 형식으로 반환)
     */
    protected function getReportableData(string $slug, string $targetType, int $targetId): ?\stdClass
    {
        try {
            if ($targetType === 'post') {
                $post = $this->postRepository->find($slug, $targetId);

                if (! $post) {
                    return null;
                }

                return (object) [
                    'title' => $post->title,
                    'content' => $post->content,
                    'content_mode' => $post->content_mode ?? 'text',
                    'user_id' => $post->user_id,
                    'author_name' => $post->author_name,
                ];
            } else {
                $comment = $this->commentRepository->find($slug, $targetId);

                if (! $comment) {
                    return null;
                }

                return (object) [
                    'title' => $comment->post?->title,
                    'post_id' => $comment->post_id,
                    'content' => $comment->content,
                    'user_id' => $comment->user_id,
                    'author_name' => $comment->author_name,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get reportable data', [
                'board_slug' => $slug,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 신고 대상의 현재 상태를 포함한 데이터를 반환합니다 (API 조회용).
     *
     * 1케이스 구조에서 snapshot은 logs.first()에서 참조합니다.
     * 스냅샷이 있으면 스냅샷 기반 + DB에서 현재 상태 보완,
     * 스냅샷이 없으면 DB 원본에서 전체 데이터를 구성합니다.
     *
     * @param  Report  $report  신고 모델
     * @return array 신고 대상 데이터
     */
    public function buildReportableData(Report $report): array
    {
        // logs.first()에서 snapshot 참조 (케이스에는 snapshot 없음)
        $report->loadMissing('logs');
        $firstLog = $report->logs->first();
        $snapshot = $firstLog?->snapshot ?? [];

        // 스냅샷이 있으면 우선 반환하되, created_at과 원글 정보는 DB에서 조회
        if (! empty($snapshot['content'])) {
            return $this->buildFromSnapshot($report, $snapshot);
        }

        // 원본 조회 시도 (단일 테이블)
        if ($report->board_id) {
            $result = $this->buildFromOriginal($report);
            if ($result !== null) {
                return $result;
            }
        }

        // 스냅샷도 없고 원본도 없으면 기본값
        return $this->getDefaultReportableData();
    }

    /**
     * 스냅샷 기반으로 신고 대상 데이터를 구성합니다.
     *
     * @param  Report  $report  신고 모델
     * @param  array  $snapshot  로그의 스냅샷 데이터
     * @return array 신고 대상 데이터
     */
    private function buildFromSnapshot(Report $report, array $snapshot): array
    {
        // author_id가 있으면 User에서 이메일/UUID 조회
        $authorEmail = null;
        $authorUuid = null;
        if ($report->author_id) {
            $user = $this->userRepository->findById($report->author_id);
            $authorEmail = $user?->email;
            $authorUuid = $user?->uuid;
        }

        // created_at과 원글 정보를 단일 테이블에서 조회
        $createdAt = null;
        $deletedAt = null;
        $currentStatus = null;
        $isCurrentlyDeleted = false;
        $postData = null;
        $triggerType = null;

        if ($report->board_id) {
            $target = $report->target_type->value === 'post'
                ? $this->postRepository->findByBoardId($report->board_id, $report->target_id)
                : $this->commentRepository->findByBoardId($report->board_id, $report->target_id);

            if ($target) {
                $createdAt = $target->created_at ?? null;
                $deletedAt = $target->deleted_at ?? null;
                $currentStatus = $target->status?->value ?? 'published';
                $isCurrentlyDeleted = $target->deleted_at !== null;
                $triggerType = $target->trigger_type?->value ?? null;

                // 댓글인 경우 상위 게시글 정보 조회
                if ($report->target_type->value === 'comment' && $target->post_id) {
                    $postData = $this->buildPostDataForReport($report->board_id, $target->post_id);
                }
            }
        }

        return [
            'title' => $snapshot['title'] ?? null,
            'content' => $snapshot['content'] ?? '',
            'content_mode' => $snapshot['content_mode'] ?? 'text',
            'author_name' => $snapshot['author_name'] ?? '',
            'author_id' => $report->author_id,
            'author_uuid' => $authorUuid,
            'author_email' => $authorEmail,
            'created_at' => $createdAt,
            'deleted_at' => $deletedAt,
            'post' => $postData,
            'current_status' => $currentStatus,
            'is_currently_deleted' => $isCurrentlyDeleted,
            'trigger_type' => $triggerType,
        ];
    }

    /**
     * DB 원본 데이터에서 신고 대상 데이터를 구성합니다.
     *
     * @param  Report  $report  신고 모델
     * @return array|null 신고 대상 데이터 (원본 없으면 null)
     */
    private function buildFromOriginal(Report $report): ?array
    {
        $target = $report->target_type->value === 'post'
            ? $this->postRepository->findByBoardId($report->board_id, $report->target_id)
            : $this->commentRepository->findByBoardId($report->board_id, $report->target_id);

        if (! $target) {
            return null;
        }

        // 작성자 정보 조회 (Eloquent 모델이므로 user 관계 활용)
        $authorId = null;
        $authorEmail = null;
        $authorName = '';

        $authorUuid = null;
        if ($target->user_id) {
            $user = $target->user;
            if ($user) {
                $authorId = $user->id;
                $authorUuid = $user->uuid;
                $authorEmail = $user->email;
                $authorName = $user->name;
            }
        } else {
            $authorName = $target->author_name ?? '';
            $authorEmail = $target->author_email ?? null;
        }

        // 댓글인 경우 상위 게시글 정보 조회
        $postData = null;
        if ($report->target_type->value === 'comment' && $target->post_id) {
            $postData = $this->buildPostDataForReport($report->board_id, $target->post_id);
        }

        return [
            'title' => $target->title ?? null,
            'content' => $target->content ?? '',
            'content_mode' => $target->content_mode ?? 'text',
            'author_name' => $authorName,
            'author_id' => $authorId,
            'author_uuid' => $authorUuid,
            'author_email' => $authorEmail,
            'created_at' => $target->created_at ?? null,
            'deleted_at' => $target->deleted_at ?? null,
            'post' => $postData,
            'current_status' => $target->status?->value ?? 'published',
            'is_currently_deleted' => isset($target->deleted_at) && $target->deleted_at !== null,
            'trigger_type' => $target->trigger_type?->value ?? null,
        ];
    }

    /**
     * 신고 대상 데이터의 기본값을 반환합니다.
     *
     * @return array 기본값 배열
     */
    private function getDefaultReportableData(): array
    {
        return [
            'title' => null,
            'content' => '',
            'content_mode' => 'text',
            'author_name' => '',
            'author_id' => null,
            'author_email' => null,
            'created_at' => null,
            'deleted_at' => null,
            'post' => null,
            'current_status' => null,
            'is_currently_deleted' => true,
        ];
    }

    /**
     * 상위 게시글 데이터를 구성합니다 (신고 대상 조회용).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  게시글 ID
     * @return array|null 게시글 정보 배열
     */
    private function buildPostDataForReport(int $boardId, int $postId): ?array
    {
        $post = $this->postRepository->findByBoardId($boardId, $postId);

        if (! $post) {
            return null;
        }

        $postAuthorId = null;
        $postAuthorName = '';
        $postAuthorEmail = null;

        $postAuthorUuid = null;
        if ($post->user_id) {
            $postUser = $post->user;
            if ($postUser) {
                $postAuthorId = $postUser->id;
                $postAuthorUuid = $postUser->uuid;
                $postAuthorName = $postUser->name;
                $postAuthorEmail = $postUser->email;
            }
        } else {
            $postAuthorName = $post->author_name ?? '';
            $postAuthorEmail = $post->author_email ?? null;
        }

        return [
            'id' => $post->id,
            'title' => $post->title ?? null,
            'created_at' => $post->created_at ?? null,
            'author' => [
                'id' => $postAuthorId,
                'uuid' => $postAuthorUuid,
                'name' => $postAuthorName,
                'email' => $postAuthorEmail,
            ],
        ];
    }

    /**
     * 본인이 작성한 콘텐츠인지 확인합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  신고 대상 타입 (post/comment)
     * @param  int  $targetId  신고 대상 ID
     * @param  int  $userId  확인할 사용자 ID
     * @return bool 본인 작성 여부
     */
    public function isOwnContent(int $boardId, string $targetType, int $targetId, int $userId): bool
    {
        $target = $targetType === 'post'
            ? $this->postRepository->findByBoardId($boardId, $targetId)
            : $this->commentRepository->findByBoardId($boardId, $targetId);

        if (! $target) {
            return false;
        }

        return $target->user_id === $userId;
    }

    /**
     * 신고 대상이 신고 가능한 상태인지 확인합니다.
     *
     * 블라인드 또는 삭제된 대상은 신고할 수 없습니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $targetType  신고 대상 타입 (post/comment)
     * @param  int  $targetId  신고 대상 ID
     * @return bool 신고 가능 여부
     */
    public function isTargetReportable(int $boardId, string $targetType, int $targetId): bool
    {
        $target = $targetType === 'post'
            ? $this->postRepository->findByBoardId($boardId, $targetId)
            : $this->commentRepository->findByBoardId($boardId, $targetId);

        if (! $target) {
            return false;
        }

        // 블라인드 상태이면 신고 불가
        if ($target->status === PostStatus::Blinded) {
            return false;
        }

        // 삭제된 상태이면 신고 불가
        if ($target->status === PostStatus::Deleted || $target->deleted_at !== null) {
            return false;
        }

        return true;
    }

    /**
     * 선택된 신고들의 상태별 건수 요약을 계산합니다.
     *
     * @param  array  $statusCounts  상태별 건수 배열
     * @param  array  $ids  선택된 케이스 ID 배열
     * @param  string|null  $targetStatus  목표 상태
     * @return array 요약 데이터 (changeable_count, deleted_count 등)
     */
    public function getStatusCountsSummary(array $statusCounts, array $ids, ?string $targetStatus = null): array
    {
        // 영구삭제 및 동일한 상태를 제외한 변경 가능한 개수 계산
        $deletedCount = $statusCounts['deleted']['count'] ?? 0;

        // targetStatus가 'deleted'일 경우 sameStatusCount는 deletedCount와 동일하므로 중복 계산 방지
        if ($targetStatus === 'deleted') {
            $sameStatusCount = 0;
        } else {
            $sameStatusCount = ($targetStatus && isset($statusCounts[$targetStatus])) ? $statusCounts[$targetStatus]['count'] : 0;
        }

        $unchangeableCount = $deletedCount + $sameStatusCount;
        $changeableCount = count($ids) - $unchangeableCount;

        // 모두 영구삭제 상태인지 확인
        $allDeleted = count($statusCounts) === 1 && isset($statusCounts['deleted']);

        // 모두 변경 불가능한 상태인지 확인 (영구삭제 + 동일상태)
        $allUnchangeable = $changeableCount === 0;

        // 영구삭제 및 대상 상태를 제외한 상태 정보만 추출
        $changeableStatusCounts = collect($statusCounts)
            ->except(['deleted', $targetStatus])
            ->toArray();

        // 대상 상태의 레이블 가져오기
        $targetStatusLabel = null;
        if ($targetStatus) {
            $statusEnum = ReportStatus::tryFrom($targetStatus);
            $targetStatusLabel = $statusEnum?->label();
        }

        // 반려(rejected) 시 공개 전환될 blinded 대상 수 계산
        $restorableBlindCount = 0;
        if ($targetStatus === 'rejected' && ! empty($ids)) {
            $restorableBlindCount = $this->countRestorableBlindTargets($ids);
        }

        return [
            'status_counts' => $statusCounts,
            'changeable_status_counts' => $changeableStatusCounts,
            'total_count' => count($ids),
            'changeable_count' => $changeableCount,
            'deleted_count' => $deletedCount,
            'same_status_count' => $sameStatusCount,
            'unchangeable_count' => $unchangeableCount,
            'all_deleted' => $allDeleted,
            'all_unchangeable' => $allUnchangeable,
            'target_status_label' => $targetStatusLabel,
            'restorable_blind_count' => $restorableBlindCount,
        ];
    }

    /**
     * 상태 변경이 가능한지 검증합니다.
     *
     * @param  string  $currentStatus  현재 상태
     * @param  string  $targetStatus  목표 상태
     * @return bool 변경 가능 여부
     */
    public function canChangeStatus(string $currentStatus, string $targetStatus): bool
    {
        // 동일 상태로의 변경은 불가
        if ($currentStatus === $targetStatus) {
            return false;
        }

        // Enum의 전환 규칙 메서드 사용
        $statusEnum = ReportStatus::from($currentStatus);

        return $statusEnum->canTransitionTo($targetStatus);
    }

    /**
     * 상태 변경 불가 사유를 반환합니다.
     *
     * @param  string  $currentStatus  현재 상태
     * @param  string  $targetStatus  목표 상태
     * @return string 블록 사유 메시지
     */
    public function getBlockReason(string $currentStatus, string $targetStatus): string
    {
        // 같은 상태로의 변경 시도
        if ($currentStatus === $targetStatus) {
            return __('sirsoft-board::messages.reports.cannot_change_to_same_status');
        }

        // 전환 규칙 위반
        $currentStatusLabel = ReportStatus::from($currentStatus)->label();
        $targetStatusLabel = ReportStatus::from($targetStatus)->label();

        return __('sirsoft-board::messages.reports.invalid_status_transition_with_labels', [
            'from' => $currentStatusLabel,
            'to' => $targetStatusLabel,
        ]);
    }

    /**
     * 케이스의 상태를 변경합니다.
     * 1케이스 구조에서는 케이스 ID = 처리 단위이므로 bulkUpdateStatus에 직접 위임합니다.
     *
     * @param  int  $representativeId  케이스 ID
     * @param  string  $status  변경할 상태
     * @param  string|null  $processNote  처리 사유
     * @return array 처리 결과
     */
    public function updateGroupStatus(int $representativeId, string $status, ?string $processNote = null): array
    {
        return $this->bulkUpdateStatus([$representativeId], [
            'status' => $status,
            'process_note' => $processNote,
        ]);
    }

    /**
     * 반려 처리 시 공개로 전환될 blinded 대상(게시글/댓글) 수를 계산합니다.
     *
     * 1케이스 구조에서는 선택한 케이스의 대상이 blinded 상태이고
     * 반려 후 현재 사이클 활성 신고가 0건이 되는 대상을 카운트합니다.
     *
     * @param  array  $ids  케이스 ID 배열
     * @return int 복구 가능한 blinded 대상 수
     */
    private function countRestorableBlindTargets(array $ids): int
    {
        $checked = [];
        $count = 0;

        foreach ($ids as $id) {
            try {
                $report = $this->reportRepository->findOrFail($id);
            } catch (\Exception $e) {
                continue;
            }

            if ($report->board_id === null) {
                continue;
            }

            $targetKey = "{$report->board_id}:{$report->target_type->value}:{$report->target_id}";
            if (isset($checked[$targetKey])) {
                continue;
            }
            $checked[$targetKey] = true;

            // 대상이 blinded 상태인지 확인
            $report->load('board');
            if (! $report->board) {
                continue;
            }

            try {
                $slug = $report->board->slug;
                $targetType = $report->target_type->value;
                $targetId = $report->target_id;

                $targetModel = $targetType === 'post'
                    ? $this->postRepository->find($slug, $targetId)
                    : $this->commentRepository->find($slug, $targetId);

                if (! $targetModel || $targetModel->status !== PostStatus::Blinded) {
                    continue;
                }
            } catch (\Exception $e) {
                continue;
            }

            // 1케이스 구조: 케이스가 suspended/deleted 상태이면 반려 시 복구됨 (shouldRestoreContent 기준)
            if (in_array($report->status->value, ['suspended', 'deleted'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 신고 ID 배열로 상태별 건수를 집계합니다.
     *
     * @param  array  $ids  케이스 ID 배열
     * @param  string|null  $targetStatus  대상 상태 (이 상태와 동일한 항목은 변경 불가로 표시)
     * @return array 상태별 건수 배열 (상태값 => ['count' => 건수, 'label' => 라벨])
     */
    public function getStatusCountsByIds(array $ids, ?string $targetStatus = null): array
    {
        $reports = collect();

        foreach ($ids as $id) {
            try {
                $report = $this->reportRepository->findOrFail($id);
                $reports->push($report);
            } catch (\Exception $e) {
                continue;
            }
        }

        return $reports->groupBy(function ($report) {
            return $report->status->value;
        })->map(function ($group) {
            return [
                'count' => $group->count(),
                'label' => $group->first()->status->label(),
            ];
        })->toArray();
    }
}
