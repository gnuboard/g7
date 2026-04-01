<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\GetSettingRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreBanksRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateSettingRequest;
use Modules\Sirsoft\Ecommerce\Services\ClaimReasonService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ShippingCarrierService;

/**
 * 이커머스 모듈 환경설정 컨트롤러
 *
 * 이커머스 모듈의 환경설정을 관리하는 API를 제공합니다.
 */
class EcommerceSettingsController extends AdminBaseController
{
    public function __construct(
        private EcommerceSettingsService $settingsService,
        private ShippingCarrierService $carrierService,
        private ClaimReasonService $claimReasonService
    ) {}

    /**
     * 모든 이커머스 설정을 조회합니다.
     *
     * @return JsonResponse 설정 목록을 포함한 JSON 응답
     */
    public function index(): JsonResponse
    {
        try {
            $settings = $this->settingsService->getAllSettings();
            $settings = $this->appendCarriersToSettings($settings);
            $settings = $this->appendClaimReasonsToSettings($settings);
            $settings['available_pg_providers'] = $this->settingsService->getRegisteredPgProviders();
            $settings['abilities'] = [
                'can_update' => PermissionHelper::check('sirsoft-ecommerce.settings.update', request()->user()),
            ];

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
     * @param string $category 카테고리명
     * @return JsonResponse 카테고리 설정을 포함한 JSON 응답
     */
    public function show(string $category): JsonResponse
    {
        try {
            $settings = $this->settingsService->getSettings($category);

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
     * @param StoreEcommerceSettingsRequest $request 저장 요청 데이터
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function store(StoreEcommerceSettingsRequest $request): JsonResponse
    {
        try {
            $settings = $request->validatedSettings();

            // shipping.carriers는 DB 관리 대상 — JSON 저장에서 제외
            $carriers = null;
            if (isset($settings['shipping']['carriers'])) {
                $carriers = $settings['shipping']['carriers'];
                unset($settings['shipping']['carriers']);
            }

            // claim.refund_reasons는 DB 관리 대상 — JSON 저장에서 제외
            $refundReasons = null;
            if (isset($settings['claim']['refund_reasons'])) {
                $refundReasons = $settings['claim']['refund_reasons'];
                unset($settings['claim']['refund_reasons']);
            }

            $result = $this->settingsService->saveSettings($settings);

            // carriers DB 동기화
            if ($result && $carriers !== null) {
                $this->carrierService->syncCarriers($carriers);
            }

            // claim reasons DB 동기화
            if ($result && $refundReasons !== null) {
                $this->claimReasonService->syncReasons('refund', $refundReasons);
            }

            if ($result) {
                // 저장 후 전체 설정 반환 (관리자 UI 상태 업데이트용)
                $updatedSettings = $this->settingsService->getAllSettings();
                $updatedSettings = $this->appendCarriersToSettings($updatedSettings);
                $updatedSettings = $this->appendClaimReasonsToSettings($updatedSettings);
                $updatedSettings['available_pg_providers'] = $this->settingsService->getRegisteredPgProviders();

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
     * 은행 목록만 저장합니다.
     *
     * @param StoreBanksRequest $request 은행 목록 저장 요청 데이터
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function storeBanks(StoreBanksRequest $request): JsonResponse
    {
        try {
            $banks = $request->validated('banks') ?? [];

            $result = $this->settingsService->saveBanks($banks);

            if ($result) {
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
     * @param GetSettingRequest $request 요청 데이터
     * @return JsonResponse 설정값을 포함한 JSON 응답
     */
    public function getSetting(GetSettingRequest $request): JsonResponse
    {
        try {
            $key = $request->validated('key');

            $value = $this->settingsService->getSetting($key);

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
     * @param UpdateSettingRequest $request 요청 데이터
     * @return JsonResponse 업데이트 결과 JSON 응답
     */
    public function updateSetting(UpdateSettingRequest $request): JsonResponse
    {
        try {
            $key = $request->validated('key');
            $value = $request->validated('value');

            $result = $this->settingsService->setSetting($key, $value);

            if ($result) {
                return ResponseHelper::moduleSuccess(
                    'sirsoft-ecommerce',
                    'messages.settings.update_success',
                    ['updated' => true]
                );
            } else {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.settings.update_failed',
                    400
                );
            }
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
     * 설정 응답에 배송사 목록을 추가합니다.
     *
     * DB 관리 대상인 carriers를 shipping 섹션에 포함시킵니다.
     *
     * @param array $settings 설정 배열
     * @return array carriers가 추가된 설정 배열
     */
    private function appendCarriersToSettings(array $settings): array
    {
        $carriers = $this->carrierService->getAllCarriers();

        $settings['shipping']['carriers'] = $carriers->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'type' => $c->type,
            'tracking_url' => $c->tracking_url,
            'is_active' => $c->is_active,
            'sort_order' => $c->sort_order,
        ])->values()->toArray();

        return $settings;
    }

    /**
     * 설정 응답에 클래임 사유 목록을 추가합니다.
     *
     * DB 관리 대상인 claim reasons를 claim 섹션에 포함시킵니다.
     *
     * @param array $settings 설정 배열
     * @return array claim reasons가 추가된 설정 배열
     */
    private function appendClaimReasonsToSettings(array $settings): array
    {
        $settings['claim']['refund_reasons'] = $this->claimReasonService->getReasonsForSettings('refund');

        return $settings;
    }
}
