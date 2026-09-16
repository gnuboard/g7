<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Enums\ReportType;
use Modules\Sirsoft\Board\Exceptions\DuplicateReportException;
use Modules\Sirsoft\Board\Exceptions\ReportCancelNotAllowedException;
use Modules\Sirsoft\Board\Http\Requests\StoreReportRequest;
use Modules\Sirsoft\Board\Http\Requests\User\IndexMyReportRequest;
use Modules\Sirsoft\Board\Http\Resources\ReportResource;
use Modules\Sirsoft\Board\Http\Resources\UserReportCollection;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Services\ReportService;

/**
 * 사용자용 신고 컨트롤러
 *
 * 게시글/댓글 신고 기능과 마이페이지 「내 신고」 조회·취소를 제공합니다.
 */
class ReportController extends AuthBaseController
{
    /**
     * ReportController 생성자
     *
     * @param  ReportService  $reportService  신고 서비스
     * @param  BoardService  $boardService  게시판 서비스 (slug → 게시판 해석)
     */
    public function __construct(
        private ReportService $reportService,
        private BoardService $boardService
    ) {
        parent::__construct();
    }

    /**
     * 게시글을 신고합니다.
     *
     * @param  StoreReportRequest  $request  신고 요청
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @return JsonResponse 신고 결과 응답
     */
    public function storePostReport(StoreReportRequest $request, string $slug, int $postId): JsonResponse
    {
        return $this->createReport($request, $slug, $postId, ReportType::Post);
    }

    /**
     * 댓글을 신고합니다.
     *
     * @param  StoreReportRequest  $request  신고 요청
     * @param  string  $slug  게시판 slug
     * @param  int  $commentId  댓글 ID
     * @return JsonResponse 신고 결과 응답
     */
    public function storeCommentReport(StoreReportRequest $request, string $slug, int $commentId): JsonResponse
    {
        return $this->createReport($request, $slug, $commentId, ReportType::Comment);
    }

    /**
     * 신고를 생성합니다.
     *
     * @param  StoreReportRequest  $request  신고 요청
     * @param  string  $slug  게시판 slug
     * @param  int  $targetId  신고 대상 ID
     * @param  ReportType  $targetType  신고 대상 타입
     * @return JsonResponse 신고 결과 응답
     */
    protected function createReport(
        StoreReportRequest $request,
        string $slug,
        int $targetId,
        ReportType $targetType
    ): JsonResponse {
        try {
            // 신고는 게시판 읽기 스코프와 무관하게 slug 로만 해석한다 (기존 동작 유지).
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

            if (! $board) {
                return $this->notFound('sirsoft-board::messages.boards.error_404');
            }

            if (! $board->use_report) {
                return $this->error('sirsoft-board::messages.reports.report_disabled', 403);
            }

            // 본인 글 신고 검증
            if ($this->reportService->isOwnContent($board->id, $targetType->value, $targetId, Auth::id())) {
                return $this->error('sirsoft-board::messages.reports.cannot_report_own', 403);
            }

            // 신고 대상 상태 검증 (블라인드/삭제된 대상은 신고 불가)
            if (! $this->reportService->isTargetReportable($board->id, $targetType->value, $targetId)) {
                return $this->error('sirsoft-board::messages.reports.target_not_reportable', 403);
            }

            $data = array_merge($request->validated(), [
                'board_id' => $board->id,
                'target_type' => $targetType->value,
                'target_id' => $targetId,
                'reporter_id' => Auth::id(),
                'status' => ReportStatus::Pending->value,
                'metadata' => [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ]);

            $report = $this->reportService->createReport($data);

            // 쿨다운 캐시 기록 (신고 생성 성공 후)
            // BoardSettingsService를 사용하여 최신 설정값 반영 (g7_module_settings는 부팅 시 Config 캐시 사용)
            $security = app(BoardSettingsService::class)->getSettings('spam_security');
            $cooldown = (int) ($security['report_cooldown_seconds'] ?? 60);
            if ($cooldown > 0) {
                $identifier = Auth::id() ?? $request->ip();
                $this->reportService->recordReportCooldown($slug, $identifier, $cooldown);
            }

            $this->logUserActivity('board_report.create', [
                'report_id' => $report->id,
                'board_slug' => $slug,
                'target_type' => $targetType->value,
                'target_id' => $targetId,
            ]);

            return $this->successWithResource(
                'sirsoft-board::messages.reports.create_success',
                new ReportResource($report),
                201
            );
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.error_404');
        } catch (DuplicateReportException $e) {
            return $this->error('sirsoft-board::messages.reports.duplicate_report', 409);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reports.create_failed', 500, $e->getMessage());
        }
    }

    /**
     * 마이페이지 「내 신고」 목록을 조회합니다.
     *
     * 본인이 남긴 신고 로그만 대상이며, 다른 신고자·처리자 정보는 싣지 않는다.
     *
     * @param  IndexMyReportRequest  $request  목록 조회 요청
     * @return JsonResponse 신고 목록 응답
     */
    public function index(IndexMyReportRequest $request): JsonResponse
    {
        try {
            $filters = [
                'search' => $request->validated('search'),
                'search_field' => $request->validated('search_field') ?? 'title_content',
                'target_type' => $request->validated('target_type'),
                'status' => $request->validated('status'),
                'board_slug' => $request->validated('board_slug'),
                'sort' => $request->validated('sort') ?? 'latest',
            ];

            $paginator = $this->reportService->paginateMyReports(
                (int) Auth::id(),
                $filters,
                (int) ($request->validated('per_page') ?? 10),
            );

            return $this->success(
                'sirsoft-board::messages.report.my_retrieved',
                (new UserReportCollection($paginator))->withQuery($filters)->resolve($request),
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.report.my_retrieve_failed', 500, $e->getMessage());
        }
    }

    /**
     * 신고를 취소합니다 (접수 상태만).
     *
     * @param  int  $reportLogId  신고 로그 ID
     * @return JsonResponse 취소 결과
     */
    public function cancel(int $reportLogId): JsonResponse
    {
        try {
            $result = $this->reportService->cancelReport($reportLogId, (int) Auth::id());

            return $this->success('sirsoft-board::messages.report.cancelled', $result);
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.report.log_not_found');
        } catch (ReportCancelNotAllowedException $e) {
            return $this->error(
                $e->getMessageKey(),
                403,
                ['code' => 'report_cancel_not_allowed'],
                $e->getMessageParams()
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.report.cancel_failed', 500, $e->getMessage());
        }
    }
}
