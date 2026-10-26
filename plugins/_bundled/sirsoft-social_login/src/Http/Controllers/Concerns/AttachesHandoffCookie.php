<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers\Concerns;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 로그인 인계 코드의 묶음 쿠키를 API 응답에 싣습니다.
 *
 * 코어는 인계 코드를 발급한 브라우저에 묶는다(쿠키 `g7_login_bind`). API 응답에는 쿠키 대기열이 붙지 않으므로 서비스가 돌려준
 * `handoff_cookie` 를 응답 본문에서 빼고 응답 쿠키로 싣는다 — 빠지면 그 브라우저의 인계 교환이 422 가 된다.
 *
 * @since 1.0.0
 */
trait AttachesHandoffCookie
{
    /**
     * 성공 응답을 만들고 서비스 결과의 묶음 쿠키를 응답에 싣습니다.
     *
     * @param  string  $messageKey  성공 문구 키
     * @param  array<string, mixed>  $data  서비스 결과 (`handoff_cookie` 가 있으면 본문에서 뺀다)
     * @return JsonResponse 응답
     */
    protected function successWithHandoffCookie(string $messageKey, array $data): JsonResponse
    {
        $cookie = $data['handoff_cookie'] ?? null;
        unset($data['handoff_cookie']);

        $response = ResponseHelper::pluginSuccess('sirsoft-social_login', $messageKey, $data);

        return $cookie instanceof Cookie ? $response->withCookie($cookie) : $response;
    }
}
