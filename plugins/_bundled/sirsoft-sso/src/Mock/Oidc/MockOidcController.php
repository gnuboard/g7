<?php

namespace Plugins\Sirsoft\Sso\Mock\Oidc;

use App\Contracts\Extension\CacheInterface;
use App\Support\SiteUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\Services\SafeRedirect;
use Plugins\Sirsoft\Sso\Support\BridgeScript;

/**
 * 모의 OIDC IdP — 디스커버리, 인가 화면, 토큰, JWKS, 로그아웃.
 *
 * 실제 OIDC 드라이버가 디스커버리 주소만 바꿔 이 엔드포인트를 부른다. 그래서 드라이버의 요청 모양(PKCE S256, client_secret_post 와
 * basic, nonce, refresh_token)을 그대로 밟는다. 게이트가 꺼져 있으면 모든 엔드포인트가 404 다 — 판정은 라우트군 미들웨어
 * `MockGateMiddleware` 한 곳이 맡는다.
 *
 * @since 1.0.0
 */
class MockOidcController extends Controller
{
    /** 인가 코드 수명(초) */
    private const CODE_TTL = 300;

    /** refresh_token 수명(초) */
    private const REFRESH_TTL = 86400;

    /**
     * @param  MockOidcKeys  $keys  서명 키
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(
        private readonly MockOidcKeys $keys,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 모의 IdP 의 발급자(issuer) 주소.
     *
     * @return string 발급자
     */
    public static function issuer(): string
    {
        return SiteUrl::absolute('/plugins/sirsoft-sso/mock/oidc');
    }

    /**
     * 디스커버리 문서.
     *
     * @return JsonResponse 문서
     */
    public function discovery(): JsonResponse
    {
        $issuer = self::issuer();

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/authorize',
            'token_endpoint' => $issuer.'/token',
            'jwks_uri' => $issuer.'/jwks',
            'end_session_endpoint' => $issuer.'/end-session',
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'scopes_supported' => ['openid', 'email', 'profile', 'offline_access'],
            'backchannel_logout_supported' => true,
            'backchannel_logout_session_supported' => true,
        ]);
    }

    /**
     * JWKS.
     *
     * @return JsonResponse 공개키 묶음
     */
    public function jwks(): JsonResponse
    {
        return response()->json($this->keys->jwks());
    }

    /**
     * 인가 화면(GET) 또는 승인 처리(POST).
     *
     * @param  Request  $request  요청
     * @return Response|RedirectResponse 화면 또는 콜백으로 이동
     */
    public function authorize(Request $request): Response|RedirectResponse
    {
        $redirectUri = (string) $request->input('redirect_uri', '');
        if (! $this->isOwnCallback($redirectUri)) {
            abort(400);
        }

        if ($request->isMethod('GET')) {
            return $this->consentPage($request);
        }

        $state = (string) $request->input('state', '');
        $variant = in_array($request->input('variant'), MockOidcVariants::ALL, true) ? (string) $request->input('variant') : 'full';
        if ($request->input('decision') !== 'approve' || $variant === 'deny') {
            return redirect()->away($this->withQuery($redirectUri, ['error' => 'access_denied', 'state' => $state]));
        }

        $email = mb_strtolower(trim((string) $request->input('email', 'meas798.oidc@example.test')));
        $code = Str::random(40);
        $this->cache->put('mock_oidc_code:'.hash('sha256', $code), [
            'variant' => $variant,
            'subject' => $this->subject($request, $email),
            'email' => $email,
            'name' => mb_substr(trim((string) $request->input('name', '')) ?: '모의 직원', 0, 100),
            'nonce' => is_string($request->input('nonce')) ? (string) $request->input('nonce') : null,
            'client_id' => (string) $request->input('client_id', ''),
            'redirect_uri' => $redirectUri,
            'code_challenge' => is_string($request->input('code_challenge')) ? (string) $request->input('code_challenge') : null,
        ], self::CODE_TTL);

        return redirect()->away($this->withQuery($redirectUri, ['code' => $code, 'state' => $state]));
    }

    /**
     * 토큰 교환 (authorization_code, refresh_token).
     *
     * @param  Request  $request  요청
     * @return JsonResponse 토큰 응답
     */
    public function token(Request $request): JsonResponse
    {
        if ($request->input('grant_type') === 'refresh_token') {
            return $this->refresh($request);
        }

        $key = 'mock_oidc_code:'.hash('sha256', (string) $request->input('code', ''));
        $grant = $this->cache->get($key);
        $this->cache->forget($key);

        if (! is_array($grant) || $grant['redirect_uri'] !== (string) $request->input('redirect_uri', '')) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'authorization code not found'], 400);
        }

        if ($this->clientId($request) !== $grant['client_id']) {
            return response()->json(['error' => 'invalid_client'], 401);
        }

        if (is_string($grant['code_challenge']) && $grant['code_challenge'] !== '') {
            $verifier = (string) $request->input('code_verifier', '');
            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (! hash_equals($grant['code_challenge'], $expected)) {
                return response()->json(['error' => 'invalid_grant', 'error_description' => 'code_verifier mismatch'], 400);
            }
        }

        if ($grant['variant'] === 'token_error') {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'mock token_error'], 400);
        }

        $accessToken = Str::random(48);
        $refreshToken = Str::random(48);
        $this->cache->put('mock_oidc_refresh:'.hash('sha256', $refreshToken), $grant, self::REFRESH_TTL);

        $claims = MockOidcVariants::claims($grant['variant'], [
            'issuer' => self::issuer(),
            'client_id' => $grant['client_id'],
            'nonce' => $grant['nonce'],
            'subject' => $grant['subject'],
            'email' => $grant['email'],
            'name' => $grant['name'],
            'sid' => 'mock-sid-'.substr(hash('sha256', $grant['subject'].$accessToken), 0, 16),
        ], now()->getTimestamp());

        $idToken = match ($grant['variant']) {
            'alg_none' => $this->keys->unsigned($claims),
            'alg_hs256' => $this->keys->signHs256WithPublicKey($claims),
            'kid_rotated' => $this->keys->sign($claims, rotated: true),
            default => $this->keys->sign($claims),
        };

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refreshToken,
            'id_token' => $idToken,
        ]);
    }

    /**
     * IdP 로그아웃 — 같은 사이트의 복귀 주소면 그리로, 아니면 안내 화면.
     *
     * @param  Request  $request  요청
     * @return Response|RedirectResponse 이동 또는 화면
     */
    public function endSession(Request $request): Response|RedirectResponse
    {
        $return = (string) $request->input('post_logout_redirect_uri', '');
        $path = SafeRedirect::path($return, '');
        if ($path !== '') {
            return redirect()->away(SiteUrl::absolute($path));
        }

        return response('<!doctype html><meta charset="utf-8"><title>Mock IdP</title><p>signed out</p>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * refresh_token 갱신 — `refresh_invalid` 변종으로 받은 토큰은 invalid_grant(주기 점검 「퇴사」).
     *
     * @param  Request  $request  요청
     * @return JsonResponse 토큰 응답
     */
    private function refresh(Request $request): JsonResponse
    {
        $key = 'mock_oidc_refresh:'.hash('sha256', (string) $request->input('refresh_token', ''));
        $grant = $this->cache->get($key);

        if (! is_array($grant) || $grant['variant'] === 'refresh_invalid' || $this->clientId($request) !== $grant['client_id']) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        $this->cache->forget($key);
        $next = Str::random(48);
        $this->cache->put('mock_oidc_refresh:'.hash('sha256', $next), $grant, self::REFRESH_TTL);

        return response()->json(['access_token' => Str::random(48), 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => $next]);
    }

    /**
     * 요청의 client_id (post 또는 basic).
     *
     * @param  Request  $request  요청
     * @return string client_id
     */
    private function clientId(Request $request): string
    {
        $basic = $request->getUser();
        if (is_string($basic) && $basic !== '') {
            return rawurldecode($basic);
        }

        return (string) $request->input('client_id', '');
    }

    /**
     * 승인 화면을 그립니다 — 변종, subject, 이메일, 이름을 고른다.
     *
     * @param  Request  $request  요청
     * @return Response 화면
     */
    private function consentPage(Request $request): Response
    {
        $hidden = '';
        foreach (['client_id', 'redirect_uri', 'state', 'nonce', 'code_challenge', 'code_challenge_method', 'scope'] as $field) {
            $hidden .= '<input type="hidden" name="'.$field.'" value="'.e((string) $request->input($field, '')).'">';
        }

        $options = '';
        foreach (MockOidcVariants::ALL as $variant) {
            $options .= '<option value="'.e($variant).'">'.e($variant).'</option>';
        }

        $html = '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            // 모양은 정적 파일이 준다 — 인라인 <style> 은 스타일 보안 정책(style-src 'self')에서 막힌다(#163)
            .'<title>Mock OIDC IdP</title>'.BridgeScript::stylesheetTag('mock-idp.css').'</head><body>'
            .'<h1>Mock OIDC IdP</h1><p>'.e(__('sirsoft-sso::oidc.mock.notice')).'</p>'
            .'<form method="post" action="'.e(self::issuer().'/authorize').'">'.$hidden
            .'<label for="variant">variant</label><select id="variant" name="variant">'.$options.'</select>'
            .'<label for="email">email</label><input id="email" name="email" value="meas798.oidc@example.test">'
            .'<label for="sub">sub</label><input id="sub" name="sub" placeholder="(email hash)">'
            .'<label for="name">name</label><input id="name" name="name" value="모의 직원">'
            .'<button type="submit" name="decision" value="approve">Approve</button> '
            .'<button type="submit" name="decision" value="deny">Deny</button></form></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * subject — 입력값, 없으면 이메일에서 만든 고정 값(같은 이메일은 같은 subject).
     *
     * @param  Request  $request  요청
     * @param  string  $email  이메일
     * @return string subject
     */
    private function subject(Request $request, string $email): string
    {
        $sub = trim((string) $request->input('sub', ''));

        return $sub !== '' ? mb_substr($sub, 0, 191) : 'mock-'.substr(hash('sha256', $email), 0, 24);
    }

    /**
     * 콜백 주소가 이 사이트의 SSO 콜백인지 확인합니다 (모의 IdP 가 남의 주소로 코드를 보내지 않게).
     *
     * @param  string  $uri  redirect_uri
     * @return bool 이 사이트 콜백이면 true
     */
    private function isOwnCallback(string $uri): bool
    {
        $path = SafeRedirect::path($uri, '');

        return $path !== '' && preg_match('#^/plugins/sirsoft-sso/oidc/[a-z][a-z0-9_]{1,49}/callback$#', $path) === 1;
    }

    /**
     * 주소에 쿼리를 붙입니다.
     *
     * @param  string  $url  주소
     * @param  array<string, string>  $query  쿼리
     * @return string 주소
     */
    private function withQuery(string $url, array $query): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}
