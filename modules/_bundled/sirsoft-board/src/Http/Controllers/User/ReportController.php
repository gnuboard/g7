<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Enums\ReportType;
use Modules\Sirsoft\Board\Exceptions\DuplicateReportException;
use Modules\Sirsoft\Board\Http\Requests\StoreReportRequest;
use Modules\Sirsoft\Board\Http\Resources\ReportResource;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Services\ReportService;

/**
 * 사용자용 신고 컨트롤러
 *
 * 게시글/댓글 신고 기능을 제공합니다.
 */
class ReportController extends AuthBaseController
{
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
            $board = Board::where('slug', $slug)->firstOrFail();

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

}
