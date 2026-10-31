<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\SocialLogin\Services\ProviderSettings;

/**
 * 로그인·가입 화면 버튼용 공개 제공자 목록.
 *
 * - GET /api/plugins/sirsoft-social_login/providers
 *
 * 켜진 제공자만 표시 순서대로 돌려준다. 자격증명(client_id 포함)은 싣지 않는다 — 버튼은 이 사이트의 시작 주소로만 간다.
 *
 * @since 1.0.0
 */
class ProviderListController extends PublicBaseController
{
    /**
     * @param  ProviderSettings  $settings  제공자 정책
     */
    public function __construct(private readonly ProviderSettings $settings)
    {
        parent::__construct();
    }

    /**
     * 켜진 제공자 목록을 반환합니다.
     *
     * @return JsonResponse 제공자 목록
     */
    public function index(): JsonResponse
    {
        $providers = array_map(fn (string $key) => [
            'key' => $key,
            'label' => $this->settings->label($key),
            'brand' => $this->settings->brand($key),
            'style' => $this->settings->buttonStyle($key),
            'start_url' => route('web.plugins.sirsoft-social_login.oauth.start', ['provider' => $key], false),
        ], $this->settings->enabledKeys());

        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.providers.listed', $providers);
    }
}
