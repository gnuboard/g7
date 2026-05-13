<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 공개용 이커머스 설정 컨트롤러
 *
 * 비회원/회원 모두 접근 가능한 설정 조회 API를 제공합니다.
 */
class EcommerceSettingsController extends PublicBaseController
{
    public function __construct(
        private EcommerceSettingsService $settingsService
    ) {}

    /**
     * 배송 설정을 조회합니다.
     *
     * 체크아웃 페이지에서 필요한 배송 관련 설정을 반환합니다.
     * - 기본 배송 국가
     * - 이용 가능한 국가 목록
     * - 국제 배송 활성화 여부
     * - 국내/국제 배송 타입
     * - 무료 배송 설정
     *
     * @return JsonResponse 배송 설정을 포함한 JSON 응답
     */
    public function shipping(): JsonResponse
    {
        try {
            $this->logApiUsage('settings.shipping');

            $shippingSettings = $this->settingsService->getSettings('shipping');

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                [
                    'shipping' => $shippingSettings,
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
     * 결제 설정을 조회합니다.
     *
     * 체크아웃 페이지에서 필요한 결제 관련 설정을 반환합니다.
     * - 활성화된 결제 수단
     * - 무통장입금 설정
     *
     * @return JsonResponse 결제 설정을 포함한 JSON 응답
     */
    public function payment(): JsonResponse
    {
        try {
            $this->logApiUsage('settings.payment');

            $orderSettings = $this->settingsService->getPublicPaymentSettings();

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                [
                    'order_settings' => $orderSettings,
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
     * 체크아웃에 필요한 설정을 조회합니다.
     *
     * 배송 및 결제 설정을 한 번에 반환합니다.
     *
     * @return JsonResponse 체크아웃 설정을 포함한 JSON 응답
     */
    public function checkout(): JsonResponse
    {
        try {
            $this->logApiUsage('settings.checkout');

            $shippingSettings = $this->settingsService->getSettings('shipping');
            $orderSettings = $this->settingsService->getSettings('order_settings');

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                [
                    'shipping' => $shippingSettings,
                    'order_settings' => $orderSettings,
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
}
