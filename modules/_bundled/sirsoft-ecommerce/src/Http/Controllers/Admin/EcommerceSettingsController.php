<?php

// audit:allow api-doc-coverage reason: develop-7.1.0 리베이스 후속 — 설정 응답 조립(공개 자산 디스크 카탈로그 정리·마일리지 알림 채널 후보)을 EcommerceSettingsAdminService 로 옮긴 내부 정리. 요청/응답 계약 불변(docs/api/settings.md 가 이미 같은 응답을 서술)

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Privacy\PrivacyRetentionManager;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\ClaimReasonException;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingCarrierOperationException;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingTypeOperationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\GetSettingRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreBanksRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateSettingRequest;
use Modules\Sirsoft\Ecommerce\Privacy\OrderRetentionHandler;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsAdminService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 이커머스 모듈 환경설정 컨트롤러
 *
 * 이커머스 모듈의 환경설정을 관리하는 API를 제공합니다.
 * 설정 JSON 과 DB 카탈로그의 저장·조회 조립은 EcommerceSettingsAdminService 가 맡고,
 * 이 컨트롤러는 "관리자가 설정을 저장했다" 는 훅 발화와 응답 구성만 한다.
 */
class EcommerceSettingsController extends AdminBaseController
{
    /**
     * 설정 화면의 도메인 서비스를 주입합니다.
     *
     * @param  EcommerceSettingsService  $settingsService  환경설정 서비스
     * @param  EcommerceSettingsAdminService  $adminSettingsService  관리자 설정 저장·조회 조립 서비스
     */
    public function __construct(
        private EcommerceSettingsService $settingsService,
        private EcommerceSettingsAdminService $adminSettingsService
    ) {}

    /**
     * 모든 이커머스 설정을 조회합니다.
     *
     * @return JsonResponse 설정 목록을 포함한 JSON 응답
     */
    public function index(): JsonResponse
    {
        try {
            $settings = $this->adminSettingsService->getSettingsWithProviders();
            $settings['abilities'] = [
                'can_update' => PermissionHelper::check('sirsoft-ecommerce.settings.update', request()->user()),
            ];

            // 화면의 숫자 입력 경계값 — 저장 규칙과 같은 출처를 내려준다.
            // 화면이 리터럴을 들면 규칙이 바뀔 때 따라오지 못해 "화면은 받는데 저장은 422" 가 된다.
            $settings['_meta'] = ['limits' => config('sirsoft-ecommerce.limits', [])];

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                $settings
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.fetch_failed',
                500
            );
        }
    }

    /**
     * 카테고리별 설정을 조회합니다.
     *
     * @param  string  $category  카테고리명
     * @return JsonResponse 카테고리 설정을 포함한 JSON 응답
     */
    public function show(string $category): JsonResponse
    {
        try {
            $settings = $this->adminSettingsService->getSettingsForAdmin()[$category] ?? [];

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                [
                    'category' => $category,
                    'settings' => $settings,
                    'abilities' => [
                        'can_update' => PermissionHelper::check('sirsoft-ecommerce.settings.update', request()->user()),
                    ],
                ]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.fetch_failed',
                500
            );
        }
    }

    /**
     * 이커머스 설정을 저장합니다.
     *
     * @param  StoreEcommerceSettingsRequest  $request  저장 요청 데이터
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function store(StoreEcommerceSettingsRequest $request): JsonResponse
    {
        try {
            $outcome = $this->adminSettingsService->saveSettings($request->validatedSettings());

            if (! $outcome['result']) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.settings.save_failed',
                    400
                );
            }

            $this->fireAfterSaveHooks($outcome);

            // 저장 후 전체 설정 반환 (관리자 UI 상태 업데이트용)
            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.save_success',
                $this->adminSettingsService->getSettingsWithProviders()
            );
        } catch (ClaimReasonException|ShippingTypeOperationException|ShippingCarrierOperationException $e) {
            return $this->catalogDomainErrorResponse($e);
        } catch (Exception $e) {
            Log::error('이커머스 설정 저장 실패', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.save_error',
                500
            );
        }
    }

    /**
     * 관리자 설정 저장 훅을 발화합니다.
     *
     * 발화 지점을 서비스가 아니라 관리자 컨트롤러에 두는 이유: 훅 의미가
     * "관리자가 설정을 저장했다" 이고, 서비스에 두면 내부 저장 호출 전부가
     * 활동로그·캐시 무효화를 유발한다. `settings.after_save` 를 서비스에서 발행하면
     * 그 훅을 구독하는 환율 제공자 플러그인과 무한 재귀하기도 한다.
     *
     * @param  array{result: bool, saved_categories: array<int, string>, settings: array}  $outcome  서비스 저장 결과
     * @return void 반환값 없음
     */
    private function fireAfterSaveHooks(array $outcome): void
    {
        // 설정 저장 활동로그 (저장된 카테고리 목록 전달)
        HookManager::doAction('sirsoft-ecommerce.settings.after_save', $outcome['saved_categories']);

        // 코어 모듈 설정 저장 훅 — SEO 캐시 무효화 등 코어/타 확장 리스너가 구독한다.
        HookManager::doAction('core.module_settings.after_save', 'sirsoft-ecommerce', $outcome['settings'], $outcome['result']);
    }

    /**
     * DB 카탈로그 도메인 실패를 운영자가 조치할 수 있는 400 응답으로 바꿉니다.
     *
     * 사용 중인 사유를 지우려 한 것은 운영자가 조치할 수 있는 도메인 실패다.
     * 상세 삭제 경로(ClaimReasonController::destroy)와 같은 400·같은 메시지 키를 쓴다 —
     * 두 경로의 안내가 갈리면 같은 거절이 화면마다 다르게 보인다.
     * 배송유형·배송사의 도메인 실패(사용 중이라 삭제 불가 등)도 같다 — generic 500 으로
     * 뭉개면 "왜" 저장이 실패했는지 화면에서 알 수 없다. 키가 이미 `sirsoft-ecommerce::` 로
     * 정규화돼 있어 moduleError 가 아닌 error 를 쓴다 (ShippingCarrierController::destroy 와 동형).
     *
     * @param  ClaimReasonException|ShippingTypeOperationException|ShippingCarrierOperationException  $e  카탈로그 도메인 예외
     * @return JsonResponse 400 오류 응답
     */
    private function catalogDomainErrorResponse(
        ClaimReasonException|ShippingTypeOperationException|ShippingCarrierOperationException $e
    ): JsonResponse {
        $messageKey = $e->getMessageKey();

        return ResponseHelper::error(
            $messageKey,
            400,
            null,
            $e->getMessageParams()
        );
    }

    /**
     * 은행 목록만 저장합니다.
     *
     * @param  StoreBanksRequest  $request  은행 목록 저장 요청 데이터
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function storeBanks(StoreBanksRequest $request): JsonResponse
    {
        try {
            $banks = $request->validated('banks') ?? [];

            $result = $this->settingsService->saveBanks($banks);

            if ($result) {
                HookManager::doAction(
                    'core.module_settings.after_save',
                    'sirsoft-ecommerce',
                    ['order_settings' => ['banks' => $banks]],
                    $result
                );

                $updatedSettings = $this->settingsService->getAllSettings();

                return ResponseHelper::moduleSuccess(
                    'sirsoft-ecommerce',
                    'messages.settings.save_success',
                    $updatedSettings
                );
            } else {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.settings.save_failed',
                    400
                );
            }
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.save_error',
                500
            );
        }
    }

    /**
     * 특정 설정값을 조회합니다.
     *
     * @param  GetSettingRequest  $request  요청 데이터
     * @return JsonResponse 설정값을 포함한 JSON 응답
     */
    public function getSetting(GetSettingRequest $request): JsonResponse
    {
        try {
            $key = $request->validated('key');

            $value = Arr::get($this->adminSettingsService->getSettingsForAdmin(), $key);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                [
                    'key' => $key,
                    'value' => $value,
                ]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.fetch_failed',
                500
            );
        }
    }

    /**
     * 특정 설정값을 업데이트합니다.
     *
     * @param  UpdateSettingRequest  $request  요청 데이터
     * @return JsonResponse 업데이트 결과 JSON 응답
     */
    public function updateSetting(UpdateSettingRequest $request): JsonResponse
    {
        try {
            $outcome = $this->adminSettingsService->updateSetting(
                $request->validated('key'),
                $request->validated('value')
            );

            if (! $outcome['result']) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.settings.update_failed',
                    400
                );
            }

            // 단건 저장도 dot-key 를 카테고리 하위 구조로 되돌린 벌크 저장과 같은 payload 형태로 발화한다.
            $this->fireAfterSaveHooks($outcome);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.update_success',
                ['updated' => true]
            );
        } catch (ClaimReasonException|ShippingTypeOperationException|ShippingCarrierOperationException $e) {
            return $this->catalogDomainErrorResponse($e);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.update_error',
                500
            );
        }
    }

    /**
     * 설정 캐시를 초기화합니다.
     *
     * @return JsonResponse 초기화 결과 JSON 응답
     */
    public function clearCache(): JsonResponse
    {
        try {
            $this->settingsService->clearCache();
            app(SeoCacheManagerInterface::class)->clearAll();

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.cache_clear_success',
                ['cleared' => true]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.cache_clear_error',
                500
            );
        }
    }

    /**
     * SEO 캐시 정보를 조회합니다.
     *
     * @return JsonResponse 캐시 페이지 수 및 용량을 포함한 JSON 응답
     */
    public function seoCacheInfo(): JsonResponse
    {
        try {
            $cacheManager = app(SeoCacheManagerInterface::class);
            $urls = $cacheManager->getCachedUrls();
            $count = count($urls);

            // 각 캐시 엔트리의 HTML 크기를 합산하여 총 용량 계산
            $totalBytes = 0;
            foreach ($urls as $url) {
                foreach (config('app.supported_locales', ['ko']) as $locale) {
                    $html = $cacheManager->get($url, $locale);
                    if ($html !== null) {
                        $totalBytes += strlen($html);
                    }
                }
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                [
                    'count' => $count,
                    'size_bytes' => $totalBytes,
                    'size_formatted' => $this->formatBytes($totalBytes),
                ]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.fetch_failed',
                500
            );
        }
    }

    /**
     * 바이트를 사람이 읽기 쉬운 형태로 변환합니다.
     *
     * @param  int  $bytes  바이트 수
     * @return string 포맷된 문자열 (예: "1.5 MB")
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 1).' '.$units[$i];
    }

    /**
     * 종결 주문 개인정보 보유기간 처리 대상 건수를 미리 조회합니다 (이슈 #636).
     *
     * 설정 화면의 대상 건수 뱃지가 쓰는 값이며, 코어 보유기간 커맨드의 `--dry-run` 과 **같은
     * 조건식**({@see OrderRetentionHandler::preview()})을 지납니다. **저장된 설정** 기준이라 화면에서
     * 값을 바꾸고 저장하기 전에는 이전 설정의 건수입니다. **꺼져 있어도 건수를 셉니다**(켜기 전에
     * 규모를 본다 — 2026-09-23 확정). 「꺼짐」과 「대상 0건」은 `enabled` 로 구분합니다.
     * `can_view_targets` 는 요청 관리자가 「더보기」 명단(코어 목록 API)을 볼 권한이 있는지입니다.
     *
     * 읽기 전용이며 입력이 없어 FormRequest 를 두지 않습니다 (`seoCacheInfo` 와 동형).
     *
     * @param  OrderRetentionHandler  $handler  종결 주문 보유기간 핸들러
     * @param  PrivacyRetentionManager  $retentionManager  코어 보유기간 수집기 (목록 권한 판정)
     * @return JsonResponse 대상 건수 JSON 응답
     */
    public function privacyRetentionPreview(OrderRetentionHandler $handler, PrivacyRetentionManager $retentionManager): JsonResponse
    {
        try {
            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.settings.fetch_success', [
                'orders' => $handler->preview(),
                'enabled' => $handler->isEnabled(),
                'mode' => $handler->mode(),
                'retention_years' => $handler->retentionYears(),
                'can_view_targets' => $retentionManager->canViewTargets($handler, $this->getCurrentUser()),
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.settings.fetch_failed', 500);
        }
    }
}
