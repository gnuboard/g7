<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Http\Requests\BulkUpdateStatusRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Modules\Sirsoft\Board\Http\Requests\UpdateStatusRequest;
use Modules\Sirsoft\Board\Http\Resources\ReportCollection;
use Modules\Sirsoft\Board\Http\Resources\ReportDetailResource;
use Modules\Sirsoft\Board\Http\Resources\ReportLogResource;
use Modules\Sirsoft\Board\Http\Resources\ReportResource;
use Modules\Sirsoft\Board\Services\ReportService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 관리자용 신고 관리 컨트롤러
 *
 * 게시판 신고의 조회, 상태 변경, 삭제 등 관리자 전용 기능을 제공합니다.
 */
class ReportController extends AdminBaseController
{
    use ChecksBoardPermission;
    /**
     * ReportController 생성자
     *
     * @param  ReportService  $reportService  신고 서비스
     */
    public function __construct(
        private ReportService $reportService
    ) {
        parent::__construct();
    }

    /**
     * 신고 목록을 조회합니다.
     *
     * 동일 대상(게시글/댓글)에 대한 신고는 그룹화하여 최초 신고만 목록에 표시합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse 신고 목록 응답
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // 조회 권한 체크
            if (! $this->checkModulePermission('reports', 'view')) {
                return $this->forbidden('sirsoft-board::messages.reports.permission_denied');
            }

            // filters 배열에서 검색 조건 추출 (admin_user_list.json과 동일한 구조)
            $filtersParam = $request->input('filters', []);
            $searchField = $filtersParam[0]['field'] ?? 'all';
            $searchValue = $filtersParam[0]['value'] ?? null;

            $filters = [
                'search' => $searchValue,
                'search_field' => $searchField,
                'status' => $request->input('status', []),
                'target_type' => $request->input('target_type', []),
                'target_status' => $request->input('target_status', []),
                'board_id' => $request->input('board_id'),
                'reported_at_from' => $request->input('reported_at_from'),
                'reported_at_to' => $request->input('reported_at_to'),
                'sort_by' => $request->input('sort_by'),
                'sort_order' => $request->input('sort_order'),
            ];

            // perPage 제한 (10~20)
            $perPage = min(max((int) $request->input('per_page', 15), 10), 20);

            // 그룹화된 신고 목록 조회
            $reports = $this->reportService->getGroupedReports($filters, $perPage);
            $statistics = $this->reportService->getStatistics($filters);

            // ReportCollection의 withStatisticsAndPermissions 사용
            $collection = new ReportCollection($reports);

            return $this->success(
                'sirsoft-board::messages.reports.fetch_success',
                $collection->withStatisticsAndPermissions($statistics)
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reports.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 신고 상세 정보를 조회합니다.
     *
     * 동일 대상에 대한 모든 신고와 함께 그룹화된 정보를 반환합니다.
     *
     * @param  int  $id  신고 ID
     * @return JsonResponse 신고 상세 정보 응답
     */
    public function show(int $id): JsonResponse
    {
        try {
            // 조회 권한 체크
            if (! $this->checkModulePermission('reports', 'view')) {
                return $this->forbidden('sirsoft-board::messages.reports.permission_denied');
            }

            // 그룹화된 상세 정보 조회
            $groupedDetail = $this->reportService->getGroupedReportDetail($id);
            $groupedDetail['reportable'] = $this->reportService->buildReportableData($groupedDetail['report']);

            return $this->successWithResource(
                'sirsoft-board::messages.reports.fetch_success',
                new ReportDetailResource($groupedDetail)
            );
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.reports.error_404');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reports.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 신고 케이스의 신고자 목록을 페이지네이션으로 반환합니다.
     *
     * @param  int  $id       신고 케이스 ID
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse 신고자 목록 응답
     */
    public function reporters(int $id, Request $request): JsonResponse
    {
        try {
            if (! $this->checkModulePermission('reports', 'view')) {
                return $this->forbidden('sirsoft-board::messages.reports.permission_denied');
            }

            $perPage = min((int) $request->query('per_page', 10), 50);
            $page = max((int) $request->query('page', 1), 1);

            $paginator = $this->reportService->paginateReporters($id, $perPage, $page);

            return $this->success(
                'sirsoft-board::messages.reports.fetch_success',
                [
                    'data' => ReportLogResource::collection($paginator->items())->resolve(),
                    'pagination' => [
                        'total'        => $paginator->total(),
                        'from'         => $paginator->firstItem() ?? 0,
                        'to'           => $paginator->lastItem() ?? 0,
                        'per_page'     => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page'    => $paginator->lastPage(),
                    ],
                ]
            );
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.reports.error_404');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reports.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 신고 상태를 변경합니다 (그룹 변경 포함).
     *
     * @param  UpdateStatusRequest  $request  상태 변경 요청
     * @param  int  $id  신고 ID
     * @return JsonResponse 변경된 신고 정보 응답
     */
    public function updateStatus(UpdateStatusRequest $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validated();

            // 1케이스 구조: 케이스 ID 직접 사용 (expandGroupedReportIds 불필요)
            $result = $this->reportService->bulkUpdateStatus(
                [$id],
                [
                    'status' => $validated['status'],
                    'process_note' => $validated['process_note'] ?? null,
                ]
            );

            // 케이스 재조회 (응답용)
            $report = $this->reportService->getReport($id);
            $report->reportableData = $this->reportService->buildReportableData($report);

            // 수동 블라인드 복구 안내 메시지
            $messageKey = 'sirsoft-board::messages.reports.status_updated';
            if ($result['manual_blind_restored'] > 0) {
                $messageKey = 'sirsoft-board::messages.reports.status_updated';
            }

            return $this->successWithResource(
                $messageKey,
                new ReportResource($report)
            );
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.reports.error_404');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reports.status_update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 선택된 신고들의 상태별 건수를 조회합니다.
     * 일괄 처리 전 사용자에게 선택한 신고의 상태 분포를 보여주기 위한 API입니다.
     *
     * @param  Request  $request  HTTP 요청 (ids 배열 필요)
     * @return JsonResponse 상태별 건수 응답
     */
    public function getStatusCounts(Request $request): JsonResponse
    {
        try {
            $ids = $request->input('ids', []);
            $targetStatus = $request->input('target_status');

            if (empty($ids)) {
                return $this->error('sirsoft-board::messages.reports.no_reports_selected', 422);
            }

            // 상태별 건수 집계
            $statusCounts = $this->reportService->getStatusCountsByIds($ids, $targetStatus);

            // 요약 데이터 계산
            $summary = $this->reportService->getStatusCountsSummary($statusCounts, $ids, $targetStatus);

            return $this->success(
                'sirsoft-board::messages.reports.status_counts_success',
                $summary
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reports.status_counts_failed', 500, $e->getMessage());
        }
    }

    /**
     * 여러 신고의 상태를 일괄 변경합니다.
     *
     * @param  BulkUpdateStatusRequest  $request  대량 상태 변경 요청
     * @return JsonResponse 변경 결과 응답
     */
    public function bulkUpdateStatus(BulkUpdateStatusRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $targetStatus = $validated['status'];

            // 선택한 신고만 처리 (그룹 확장 제거)
            // 일괄 변경 실행
            $result = $this->reportService->bulkUpdateStatus(
                $validated['ids'],
                [
                    'status' => $targetStatus,
                    'process_note' => $validated['process_note'] ?? null,
                ]
            );

            $affectedCount = $result['affected_count'];

            // 상태명 다국어 키 가져오기
            $statusEnum = \Modules\Sirsoft\Board\Enums\ReportStatus::from($targetStatus);
            $statusLabel = $statusEnum->label();

            // 성공 메시지 생성
            $successMessage = __('sirsoft-board::messages.reports.bulk_status_updated_with_count', [
                'count' => $affectedCount,
                'status' => $statusLabel,
            ]);

            // 수동 블라인드 복구 안내 메시지 추가
            if ($result['manual_blind_restored'] > 0) {
                $successMessage .= ' '.__('sirsoft-board::messages.reports.manual_blind_restored_notice', [
                    'count' => $result['manual_blind_restored'],
                ]);
            }

            return $this->success(
                $successMessage,
                [
                    'affected_count' => $affectedCount,
                    'restored_count' => $result['restored_count'],
                    'manual_blind_restored' => $result['manual_blind_restored'],
                    'status_label' => $statusLabel,
                    'message' => $successMessage,
                ],
                200,
                ['count' => $affectedCount, 'status' => $statusLabel]
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reports.bulk_status_update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 신고를 삭제합니다 (소프트 삭제).
     *
     * @param  int  $id  신고 ID
     * @return JsonResponse 삭제 성공 응답
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->reportService->deleteReport($id);

            return $this->success('sirsoft-board::messages.reports.delete_success');
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.reports.error_404');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reports.delete_failed', 500, $e->getMessage());
        }
    }
}
