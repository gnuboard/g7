<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Modules\Sirsoft\Board\Exceptions\BulkApplyAbortedException;
use Modules\Sirsoft\Board\Exceptions\ReactionTypeInUseException;
use Modules\Sirsoft\Board\Http\Requests\Admin\BulkApplySettingsRequest;
use Modules\Sirsoft\Board\Http\Requests\Admin\IndexBoardSettingsRequest;
use Modules\Sirsoft\Board\Http\Requests\Admin\ShowBoardSettingsRequest;
use Modules\Sirsoft\Board\Http\Requests\Admin\StoreBoardSettingsRequest;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Services\ReactionTypeService;

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
     * @param  BoardSettingsService  $settingsService  환경설정 서비스
     * @param  BoardService  $boardService  게시판 서비스
     * @param  ReactionTypeService  $reactionTypeService  반응 유형 서비스
     */
    public function __construct(
        private BoardSettingsService $settingsService,
        private BoardService $boardService,
        private ReactionTypeService $reactionTypeService
    ) {}

    /**
     * 모든 게시판 설정을 조회합니다.
     *
     * @param  IndexBoardSettingsRequest  $request  환경설정 조회 요청
     * @return JsonResponse 설정 목록을 포함한 JSON 응답
     */
    public function index(IndexBoardSettingsRequest $request): JsonResponse
    {
        try {
            $settings = $this->settingsService->getAllSettings();
            $settings['report_permissions'] = $this->settingsService->getReportPermissionRoles();
            $settings = $this->appendReactionTypesToSettings($settings);

            $settings['abilities'] = [
                'can_update' => PermissionHelper::check('sirsoft-board.settings.update', $request->user()),
            ];

            $settings['_meta'] = [
                'limits' => config('sirsoft-board.limits', []),
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
     * @param  ShowBoardSettingsRequest  $request  카테고리별 설정 조회 요청
     * @param  string  $category  카테고리명
     * @return JsonResponse 카테고리 설정을 포함한 JSON 응답
     */
    public function show(ShowBoardSettingsRequest $request, string $category): JsonResponse
    {
        try {
            $settings = $this->settingsService->getSettings($category);

            // 반응 유형 행은 설정 JSON 이 아니라 DB 에 있다 — 기본 설정 탭이 편집기를
            // 그리려면 같은 응답에 실어야 한다.
            if ($category === 'basic_defaults') {
                $settings['reaction_types'] = $this->reactionTypeService->getTypesForSettings();
            }

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
     * @param  StoreBoardSettingsRequest  $request  저장 요청 데이터
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function store(StoreBoardSettingsRequest $request): JsonResponse
    {
        try {
            $settings = $request->validatedSettings();

            // 반응 유형 행은 설정 JSON 에 저장하지 않는다 — basic_defaults 규칙이
            // ['sometimes','array'] 라 미지 하위 키를 버리지 않으므로, 떼어내지 않으면
            // basic_defaults.json 과 config 미러에 행 배열이 그대로 새어 들어간다.
            $reactionTypes = array_key_exists('reaction_types', $settings['basic_defaults'] ?? [])
                ? (Arr::pull($settings['basic_defaults'], 'reaction_types') ?? [])
                : null;

            if ($reactionTypes !== null) {
                // 설정 파일을 쓰기 전에 동기화한다 — 사용 중 유형 삭제로 422 가 나면
                // JSON 은 손대지 않은 상태로 남아야 한다(부분 저장 금지).
                $sync = $this->reactionTypeService->syncTypes($reactionTypes);

                if ($sync['removed_codes'] !== [] && isset($settings['basic_defaults']['active_reaction_types'])) {
                    $settings['basic_defaults']['active_reaction_types'] = array_values(array_diff(
                        $settings['basic_defaults']['active_reaction_types'],
                        $sync['removed_codes'],
                    ));
                }
            }

            $result = $this->settingsService->saveSettings($settings);

            if ($result) {
                if ($request->has('report_permissions')) {
                    $this->settingsService->syncReportPermissionRoles($request->input('report_permissions'));
                }

                // 코어 모듈 설정 저장 훅 — SEO 캐시 무효화 등 코어/타 확장 리스너가 구독한다.
                // 훅 의미가 "관리자가 설정을 저장했다" 이므로 서비스가 아니라 여기서 발화한다.
                HookManager::doAction('core.module_settings.after_save', 'sirsoft-board', $settings, $result);

                $updatedSettings = $this->settingsService->getAllSettings();
                $updatedSettings['report_permissions'] = $this->settingsService->getReportPermissionRoles();
                $updatedSettings = $this->appendReactionTypesToSettings($updatedSettings);

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
        } catch (ReactionTypeInUseException $e) {
            // 예외가 들고 다니는 다국어 키를 그대로 넘긴다 (번역문을 키로 해석하면 실패한다).
            return $this->error(
                'sirsoft-board::messages.reaction_type.in_use',
                422,
                ['code' => 'reaction_type_in_use'],
                $e->getMessageParams()
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
                'messages.settings.save_error',
                500
            );
        }
    }

    /**
     * 설정 응답에 반응 유형 행을 덧붙입니다.
     *
     * getAllSettings() 안에 넣지 않는 이유: 그 결과는 설정 캐시·config 미러로 흘러가는데
     * 유형 행은 DB 원본이라 캐시에 얼어붙으면 편집 결과가 화면에 늦게 반영된다.
     *
     * @param  array<string, mixed>  $settings  설정 배열
     * @return array<string, mixed> 유형 행이 덧붙은 설정 배열
     */
    private function appendReactionTypesToSettings(array $settings): array
    {
        $settings['basic_defaults'] = $settings['basic_defaults'] ?? [];
        $settings['basic_defaults']['reaction_types'] = $this->reactionTypeService->getTypesForSettings();

        return $settings;
    }

    /**
     * 환경설정 기본값을 기존 게시판에 일괄 적용합니다.
     *
     * @param  BulkApplySettingsRequest  $request  일괄 적용 요청 데이터
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
                ['updated_count' => $updatedCount, 'rolled_back' => false],
                200,
                ['count' => $updatedCount]
            );
        } catch (BulkApplyAbortedException $e) {
            // 일괄 적용 중 실패 → 전체 롤백됨 (의도된 정상 동작). HTTP 200 + rolled_back 플래그로
            // 프론트 onSuccess 흐름에서 안내 처리. board=null 이면 컬럼 업데이트 실패(generic).
            return ResponseHelper::moduleSuccess(
                'sirsoft-board',
                'messages.settings.bulk_apply_aborted',
                [
                    'rolled_back' => true,
                    'board' => $e->boardInfo(),
                    'failed_at' => $e->failedAt,
                    'total' => $e->total,
                ]
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
