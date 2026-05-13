<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Http\Requests\Admin\BulkApplySettingsRequest;
use Modules\Sirsoft\Board\Http\Requests\Admin\StoreBoardSettingsRequest;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\BoardSettingsService;

/**
 * 게시판 모듈 환경설정 컨트롤러
 *
 * 게시판 모듈의 환경설정을 관리하는 API를 제공합니다.
 */
class BoardSettingsController extends AdminBaseController
{
    /**
     * BoardSettingsController 생성자
     *
     * @param BoardSettingsService $settingsService 환경설정 서비스
     * @param BoardService $boardService 게시판 서비스
     */
    public function __construct(
        private BoardSettingsService $settingsService,
        private BoardService $boardService
    ) {}

    /**
     * 모든 게시판 설정을 조회합니다.
     *
     * @return JsonResponse 설정 목록을 포함한 JSON 응답
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $settings = $this->settingsService->getAllSettings();
            $settings['report_permissions'] = $this->settingsService->getReportPermissionRoles();

            $settings['abilities'] = [
                'can_update' => PermissionHelper::check('sirsoft-board.settings.update', $request->user()),
            ];

            return ResponseHelper::moduleSuccess(
                'sirsoft-board',
                'messages.settings.fetch_success',
                $settings
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
                'messages.settings.fetch_failed',
                500
            );
        }
    }

    /**
     * 카테고리별 설정을 조회합니다.
     *
     * @param string $category 카테고리명
     * @return JsonResponse 카테고리 설정을 포함한 JSON 응답
     */
    public function show(Request $request, string $category): JsonResponse
    {
        try {
            $settings = $this->settingsService->getSettings($category);

            return ResponseHelper::moduleSuccess(
                'sirsoft-board',
                'messages.settings.fetch_success',
                [
                    'category' => $category,
                    'settings' => $settings,
                    'abilities' => [
                        'can_update' => PermissionHelper::check('sirsoft-board.settings.update', $request->user()),
                    ],
                ]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
                'messages.settings.fetch_failed',
                500
            );
        }
    }

    /**
     * 게시판 설정을 저장합니다.
     *
     * @param StoreBoardSettingsRequest $request 저장 요청 데이터
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function store(StoreBoardSettingsRequest $request): JsonResponse
    {
        try {
            $settings = $request->validatedSettings();

            $result = $this->settingsService->saveSettings($settings);

            if ($result) {
                if ($request->has('report_permissions')) {
                    $this->settingsService->syncReportPermissionRoles($request->input('report_permissions'));
                }

                $updatedSettings = $this->settingsService->getAllSettings();
                $updatedSettings['report_permissions'] = $this->settingsService->getReportPermissionRoles();

                return ResponseHelper::moduleSuccess(
                    'sirsoft-board',
                    'messages.settings.save_success',
                    $updatedSettings
                );
            } else {
                return ResponseHelper::moduleError(
                    'sirsoft-board',
                    'messages.settings.save_failed',
                    400
                );
            }
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
                'messages.settings.save_error',
                500
            );
        }
    }

    /**
     * 환경설정 기본값을 기존 게시판에 일괄 적용합니다.
     *
     * @param BulkApplySettingsRequest $request 일괄 적용 요청 데이터
     * @return JsonResponse 일괄 적용 결과 JSON 응답
     */
    public function bulkApply(BulkApplySettingsRequest $request): JsonResponse
    {
        try {
            $fields = $request->validated('fields');
            $applyAll = $request->validated('apply_all');
            $boardIds = $request->validated('board_ids', []);
            $overrideValues = $request->validated('override_values', []);

            $updatedCount = $this->boardService->bulkApplySettings(
                $fields,
                $applyAll,
                $boardIds,
                $overrideValues
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-board',
                'messages.settings.bulk_apply_success',
                ['updated_count' => $updatedCount],
                200,
                ['count' => $updatedCount]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
                'messages.settings.bulk_apply_failed',
                500
            );
        }
    }

    /**
     * 설정 캐시를 초기화합니다.
     *
     * ModuleSettings 캐시와 게시판 캐시를 모두 초기화합니다.
     *
     * @return JsonResponse 초기화 결과 JSON 응답
     */
    public function clearCache(): JsonResponse
    {
        try {
            // ModuleSettings 캐시 초기화
            $this->settingsService->clearCache();

            // 게시판 캐시 전체 초기화
            $this->boardService->clearAllBoardCaches();

            return ResponseHelper::moduleSuccess(
                'sirsoft-board',
                'messages.settings.clear_cache_success',
                ['cleared' => true]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
                'messages.settings.clear_cache_error',
                500
            );
        }
    }
}
