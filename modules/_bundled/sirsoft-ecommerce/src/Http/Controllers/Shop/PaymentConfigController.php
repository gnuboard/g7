<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Shop;

use App\Extension\HookManager;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;

/**
 * PG사별 프론트엔드 클라이언트 설정 API 컨트롤러
 *
 * PG 플러그인이 훅을 통해 등록한 SDK 설정(client_key, sdk_url 등)을
 * 프론트엔드에 제공합니다.
 */
class PaymentConfigController extends PublicBaseController
{
    /**
     * PG 제공자의 프론트엔드 클라이언트 설정을 반환합니다.
     *
     * @param  string  $provider  PG 제공자 ID (예: tosspayments)
     * @return JsonResponse 클라이언트 설정을 포함한 JSON 응답
     */
    public function clientConfig(string $provider): JsonResponse
    {
        $config = HookManager::applyFilters(
            'sirsoft-ecommerce.payment.get_client_config',
            [],
            $provider
        );

        if (empty($config)) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.payment.provider_not_found',
                404
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.payment.client_config_success',
            $config
        );
    }
}
