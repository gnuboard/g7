<?php

namespace Plugins\Sirsoft\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\Exceptions\ScimException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ScimEventRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCIM 2.0 라우트의 IdP 별 Bearer 토큰 인증과 수신 이력.
 *
 * 토큰은 IdP 화면에서 발급할 때 한 번만 보이고 sha256 만 저장한다 — 요청 토큰의 해시를 `hash_equals` 로 대조한다. 그 IdP 가 SCIM 을
 * 끈 상태(`deprovision.scim=false`)면 토큰이 맞아도 403. 모든 응답(거절 포함)을 이력에 남긴다 — 본문은 남기지 않는다.
 *
 * `plugin.php` 의 `getMiddleware()` 가 `api.plugins.sirsoft-sso.scim.*` 에 붙인다.
 *
 * @since 1.0.0
 */
class ScimBearerAuth
{
    /** 인증된 IdP 를 담는 요청 속성 이름 */
    public const IDP_ATTRIBUTE = 'sirsoft-sso.scim_idp';

    /**
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  ScimEventRepositoryInterface  $events  수신 이력
     */
    public function __construct(
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly ScimEventRepositoryInterface $events,
    ) {}

    /**
     * 토큰을 확인하고 다음으로 넘깁니다.
     *
     * @param  Request  $request  요청
     * @param  Closure  $next  다음
     * @return Response 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->route('idp');
        $idp = is_string($key) ? $this->idps->findByKey($key) : null;

        $response = $this->authenticate($request, $idp);
        if ($response === null) {
            $request->attributes->set(self::IDP_ATTRIBUTE, $idp);
            $response = $next($request);
        }

        $this->record($request, $idp, $response);

        return $response;
    }

    /**
     * 토큰을 확인합니다 (통과면 null, 아니면 SCIM 오류 응답).
     *
     * @param  Request  $request  요청
     * @param  IdentityProvider|null  $idp  경로의 IdP
     * @return JsonResponse|null 거절 응답
     */
    private function authenticate(Request $request, ?IdentityProvider $idp): ?JsonResponse
    {
        $token = (string) $request->bearerToken();
        $hash = $idp?->scim_token_hash;

        if ($idp === null || $token === '' || ! is_string($hash) || strlen($hash) !== 64 || ! hash_equals($hash, hash('sha256', $token))) {
            return ScimException::response(401, null, __('sirsoft-sso::scim.error.unauthorized'))
                ->header('WWW-Authenticate', 'Bearer realm="scim"');
        }

        $scimOn = is_array($idp->deprovision) && (bool) ($idp->deprovision['scim'] ?? false);
        if (! $idp->enabled || ! $scimOn) {
            return ScimException::response(403, null, __('sirsoft-sso::scim.error.disabled'));
        }

        return null;
    }

    /**
     * 수신 1건을 이력에 남깁니다 (실패는 응답을 막지 않는다).
     *
     * @param  Request  $request  요청
     * @param  IdentityProvider|null  $idp  IdP
     * @param  Response  $response  응답
     * @return void
     */
    private function record(Request $request, ?IdentityProvider $idp, Response $response): void
    {
        $segments = explode('/', trim((string) $request->route()?->uri(), '/'));
        $v2 = array_search('v2', $segments, true);
        $resource = $v2 !== false ? (string) ($segments[$v2 + 2] ?? '') : '';
        $target = $request->route('id');

        $errorCode = null;
        if ($response->getStatusCode() >= 400 && $response instanceof JsonResponse) {
            $data = $response->getData(true);
            $errorCode = is_array($data) && is_string($data['scimType'] ?? null) ? $data['scimType'] : null;
        }

        try {
            $this->events->record([
                'idp_id' => $idp !== null ? (int) $idp->getKey() : null,
                'method' => $request->method(),
                'resource' => $resource !== '' ? $resource : 'unknown',
                'target_id' => is_scalar($target) ? (string) $target : null,
                'status' => $response->getStatusCode(),
                'error_code' => $errorCode,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
