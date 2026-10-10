<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Exceptions\PermissionEscalationException;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Privacy\PrivacyRetentionManager;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Modules\Sirsoft\Board\Exceptions\BulkApplyAbortedException;
use Modules\Sirsoft\Board\Exceptions\ReactionTypeInUseException;
use Modules\Sirsoft\Board\Http\Requests\Admin\BulkApplySettingsRequest;
use Modules\Sirsoft\Board\Http\Requests\Admin\IndexBoardSettingsRequest;
use Modules\Sirsoft\Board\Http\Requests\Admin\ShowBoardSettingsRequest;
use Modules\Sirsoft\Board\Http\Requests\Admin\StoreBoardSettingsRequest;
use Modules\Sirsoft\Board\Privacy\BoardGuestRetentionHandler;
use Modules\Sirsoft\Board\Privacy\BoardIpRetentionHandler;
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

            $user = $request->user();
            $canUpdate = PermissionHelper::check('sirsoft-board.settings.update', $user);
            $settings['abilities'] = [
                'can_update' => $canUpdate,
                // 일괄 적용은 기존 게시판 설정을 덮어쓰므로 게시판 수정 권한도 요구한다(제품 결정 2026-10-09) — 라우트와 같은 두 권한
                'can_bulk_apply' => $canUpdate && PermissionHelper::check('sirsoft-board.boards.update', $user),
                // 게시판 유형 추가·수정·삭제 API 는 게시판 등록 권한을 쓴다(board-types 라우트)
                'can_manage_board_types' => PermissionHelper::check('sirsoft-board.boards.create', $user),
                // SEO 캐시 초기화는 코어 API(/api/admin/seo/clear-cache)라 코어 환경설정 수정 권한이 필요하다
                'can_clear_seo_cache' => PermissionHelper::check('core.settings.update', $user),
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

            // 신고 관리 권한의 역할 변경이 상한 밖이면 설정 파일을 쓰기 전에 거부한다 — 설정을 먼저
            // 저장하고 권한 동기화에서 거부하면 요청의 절반만 반영된다.
            if ($request->has('report_permissions')) {
                $this->settingsService->assertReportPermissionRolesChangeAllowed($request->input('report_permissions'));
            }

            // 게시판 기본 권한도 같은 이유로 설정 파일을 쓰기 전에 판정한다 — 기본 권한은 앞으로 만들
            // 게시판에 그대로 붙으므로, 자기 역할을 넣어 두면 새 게시판의 관리 권한을 얻는다.
            if (is_array($settings['basic_defaults'] ?? null) && array_key_exists('default_board_permissions', $settings['basic_defaults'])) {
                $this->settingsService->assertDefaultBoardPermissionsChangeAllowed(
                    (array) ($settings['basic_defaults']['default_board_permissions'] ?? [])
                );
            }

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
        } catch (PermissionEscalationException $e) {
            return $this->error('exceptions.cannot_grant_unheld_permission', 403);
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
        } catch (PermissionEscalationException $e) {
            // 보유하지 않은 게시판 권한을 기본값 덮어쓰기로 여러 게시판에 붙이려 한 요청 — 전체 롤백됨
            return $this->error('exceptions.cannot_grant_unheld_permission', 403);
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

    /**
     * 개인정보 보유기간 처리 대상 건수를 미리 조회합니다 (이슈 #636).
     *
     * 설정 화면의 대상 건수 뱃지가 쓰는 값이며, 코어 보유기간 커맨드의 `--dry-run` 과 **같은
     * 조건식**(각 핸들러의 `preview()`)을 지납니다 — 화면에서 본 숫자와 실제 처리 건수가
     * 어긋나면 운영자가 규모를 오판한 채 켜게 됩니다. **저장된 설정** 기준이므로 화면에서 값을
     * 바꾸고 저장하기 전에는 이전 설정의 건수입니다.
     *
     * **꺼진 축도 건수를 셉니다**(켜기 전에 규모를 본다 — 2026-09-23 확정). 「꺼짐」과 「대상 0건」은
     * `ip_enabled`·`guest_enabled` 로 구분합니다. 비회원 보존기간 0(미적용)이면 비회원 건수는 0 입니다.
     * `can_view_targets` 는 요청 관리자가 「더보기」 명단(코어 목록 API)을 볼 권한이 있는지입니다.
     * 읽기 전용이며 입력이 없어 FormRequest 를 두지 않습니다 (`clearCache` 와 동형).
     *
     * @param  BoardIpRetentionHandler  $ipHandler  작성 IP 핸들러
     * @param  BoardGuestRetentionHandler  $guestHandler  비회원 작성자 정보 핸들러
     * @param  PrivacyRetentionManager  $retentionManager  코어 보유기간 수집기 (목록 권한 판정)
     * @return JsonResponse 축별 대상 건수 JSON 응답
     */
    public function privacyRetentionPreview(
        BoardIpRetentionHandler $ipHandler,
        BoardGuestRetentionHandler $guestHandler,
        PrivacyRetentionManager $retentionManager,
    ): JsonResponse {
        try {
            return ResponseHelper::moduleSuccess('sirsoft-board', 'messages.settings.fetch_success', [
                'ip' => $ipHandler->preview(),
                'guest' => $guestHandler->preview(),
                'ip_enabled' => $ipHandler->isEnabled(),
                'guest_enabled' => $guestHandler->isEnabled(),
                'guest_mode' => $guestHandler->mode(),
                // 두 축의 목록 권한은 같다(설정 읽기 + 게시판 관리 읽기).
                'can_view_targets' => $retentionManager->canViewTargets($ipHandler, $this->getCurrentUser()),
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-board', 'messages.settings.fetch_failed', 500);
        }
    }
}
