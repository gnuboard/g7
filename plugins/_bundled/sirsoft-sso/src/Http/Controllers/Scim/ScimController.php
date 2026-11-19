<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers\Scim;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Plugins\Sirsoft\Sso\Exceptions\ScimException;
use Plugins\Sirsoft\Sso\Http\Middleware\ScimBearerAuth;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * SCIM 컨트롤러의 공통 바탕.
 *
 * SCIM 응답은 G7 응답 형식(`ResponseHelper`)이 아니라 규격 형식(`application/scim+json`, 자원 그대로)이다 — IdP 의 SCIM 클라이언트가
 * 읽는다. IdP 는 미들웨어(`ScimBearerAuth`)가 토큰으로 확정해 요청 속성에 둔다.
 *
 * @since 1.0.0
 */
abstract class ScimController extends Controller
{
    /**
     * 토큰으로 확정된 IdP 를 꺼냅니다.
     *
     * @param  Request  $request  요청
     * @return IdentityProvider IdP
     *
     * @throws ScimException 미들웨어를 거치지 않았으면 401
     */
    protected function idp(Request $request): IdentityProvider
    {
        $idp = $request->attributes->get(ScimBearerAuth::IDP_ATTRIBUTE);
        if (! $idp instanceof IdentityProvider) {
            throw new ScimException(401, null, __('sirsoft-sso::scim.error.unauthorized'));
        }

        return $idp;
    }

    /**
     * SCIM 응답을 만듭니다.
     *
     * @param  array<string, mixed>|null  $body  본문 (null 이면 빈 본문)
     * @param  int  $status  상태 코드
     * @param  array<string, string>  $headers  더할 헤더
     * @return JsonResponse 응답
     */
    protected function scim(?array $body, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json( // audit:allow response-helper-bypass reason: SCIM 2.0 규격 응답(application/scim+json, 자원 본문 그대로) — IdP 의 SCIM 클라이언트가 읽는다
            $body ?? new \stdClass, $status, array_merge(['Content-Type' => ScimException::CONTENT_TYPE], $headers));
    }
}
