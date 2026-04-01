<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Helpers\PermissionHelper;
use App\Extension\HookManager;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
     * 사용 가능한 알림 채널 목록을 반환합니다.
     *
     * 기본 채널(mail, database)을 제공하며, 플러그인이
     * 'sirsoft-board.notification.available_channels' Filter 훅으로 채널을 추가할 수 있습니다.
     * 추후 코어에 공통 API가 생기면 이 메서드만 교체하면 됩니다.
     *
     * @return JsonResponse 채널 목록 JSON 응답
     */
    public function notificationChannels(): JsonResponse
    {
        $defaultChannels = [
            ['value' => 'mail', 'label' => __('sirsoft-board::admin.settings.notification_channels.channel_mail')],
        ];

        $channels = HookManager::applyFilters(
            'sirsoft-board.notification.available_channels',
            $defaultChannels
        );

        return ResponseHelper::success('messages.fetch_success', $channels);
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

            // 게시판 캐시 초기화 (boards:list)
            Cache::forget('boards:list');

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
