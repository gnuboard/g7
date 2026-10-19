<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Services\OAuthFlowService;

/**
 * 관리자 제공자 연결 테스트.
 *
 * 제공자를 켜기 전에 자격증명과 콜백 주소가 맞는지 확인하는 용도다. 관리자 브라우저가 반환된 인가 주소를 새 창으로 열어
 * 제공자 동의를 마치면, 콜백은 로그인하지 않고 일회용 결과 화면(받은 프로필 요약)을 보여 준다.
 *
 * @since 1.0.0
 */
class ProviderTestController extends AdminBaseController
{
    /**
     * @param  OAuthFlowService  $flow  왕복 서비스
     */
    public function __construct(private readonly OAuthFlowService $flow)
    {
        parent::__construct();
    }

    /**
     * 테스트 왕복을 시작하고 인가 주소를 반환합니다.
     *
     * @param  string  $provider  제공자 키
     * @return JsonResponse `{authorize_url}` 또는 404
     */
    public function testLogin(string $provider): JsonResponse
    {
        try {
            $url = $this->flow->start($provider, AttemptIntent::Test, null, $this->getCurrentUser()?->id);
        } catch (ProviderException $e) {
            return ResponseHelper::error('sirsoft-social_login::messages.errors.provider_unknown', 404, ['code' => $e->errorCode]);
        }

        return ResponseHelper::success('sirsoft-social_login::messages.test_login.started', ['authorize_url' => $url]);
    }
}
