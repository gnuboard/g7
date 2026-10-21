<?php

namespace Plugins\Sirsoft\SocialLogin\Mock;

use App\Contracts\Extension\CacheInterface;
use App\Support\SiteUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Plugins\Sirsoft\SocialLogin\Support\HtmlPage;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\Base64Url;

/**
 * 모의 제공자 — 인가 화면, 토큰, 사용자 정보, discovery, JWKS, 권한 회수.
 *
 * 실제 드라이버가 주소만 바꿔 이 엔드포인트를 부른다. 그래서 드라이버의 요청 모양(PKCE, 페이스북 GET 토큰, 네이버 문자열
 * expires_in, OIDC id_token)을 그대로 밟는다. 게이트가 꺼져 있으면 모든 엔드포인트가 404 다 — 판정은 라우트군의 그룹 미들웨어
 * `MockGateMiddleware` 한 곳이 맡고 핸들러는 본래 일만 한다.
 *
 * @since 1.0.0
 */
class MockController extends Controller
{
    /** 인가 코드 TTL(초) */
    private const CODE_TTL = 300;

    /** 접근 토큰 TTL(초) */
    private const TOKEN_TTL = 3600;

    /**
     * @param  MockKeyPair  $keys  서명 키
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(
        private readonly MockKeyPair $keys,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 동의 화면(GET) 또는 동의 처리(POST).
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
        if ($request->input('decision') !== 'approve' || $request->input('variant') === 'deny') {
            return redirect()->away($this->withQuery($redirectUri, ['error' => 'access_denied', 'state' => $state]));
        }

        $variant = in_array($request->input('variant'), MockProfiles::VARIANTS, true) ? (string) $request->input('variant') : 'full_verified';
        $style = $this->style($request);
        $code = Str::random(40);

        $this->cache->put('mock_code:'.hash('sha256', $code), [
            'style' => $style,
            'variant' => $variant,
            'subject' => $this->subject($request, $style),
            'email' => $this->email($request),
            'nonce' => $request->input('nonce'),
            'client_id' => (string) $request->input('client_id', ''),
            'redirect_uri' => $redirectUri,
            'code_challenge' => $request->input('code_challenge'),
        ], self::CODE_TTL);

        return redirect()->away($this->withQuery($redirectUri, ['code' => $code, 'state' => $state]));
    }

    /**
     * 인가 코드를 토큰으로 바꿉니다 (GET·POST — 페이스북은 GET).
     *
     * @param  Request  $request  요청
     * @return JsonResponse 토큰 응답
     */
    public function token(Request $request): JsonResponse
    {
        $key = 'mock_code:'.hash('sha256', (string) $request->input('code', ''));
        $grant = $this->cache->get($key);
        $this->cache->forget($key);

        if (! is_array($grant) || $grant['redirect_uri'] !== (string) $request->input('redirect_uri', '')) {
            return response()->json(['error' => 'invalid_grant', 'error_code' => 'KOE320', 'error_description' => 'authorization code not found'], 400);
        }

        if (is_string($grant['code_challenge']) && $grant['code_challenge'] !== '') {
            $verifier = (string) $request->input('code_verifier', '');
            if (! hash_equals($grant['code_challenge'], Base64Url::encode(hash('sha256', $verifier, true)))) {
                return response()->json(['error' => 'invalid_grant', 'error_description' => 'code_verifier mismatch'], 400);
            }
        }

        $accessToken = Str::random(48);
        $this->cache->put('mock_token:'.hash('sha256', $accessToken), $grant, self::TOKEN_TTL);

        $body = [
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => $grant['style'] === 'naver' ? (string) self::TOKEN_TTL : self::TOKEN_TTL,
        ];

        if ($grant['style'] !== 'facebook') {
            $body['refresh_token'] = Str::random(48);
        }

        if (in_array($grant['style'], ['google', 'oidc'], true)) {
            $body['id_token'] = $this->keys->sign([
                'iss' => $this->issuer(),
                'aud' => $grant['client_id'],
                'sub' => $grant['subject'],
                'iat' => time(),
                'exp' => time() + self::TOKEN_TTL,
                'nonce' => $grant['nonce'],
            ]);
        }

        return response()->json($body);
    }

    /**
     * 권한 회수(연결 해제) 요청을 받습니다 — 제공자마다 다른 방식(카카오 어드민 키 POST, 네이버·구글 폼 POST, 페이스북 DELETE)을
     * 모두 성공으로 답한다. 모의 연결 해제가 실제 드라이버의 회수 경로를 끝까지 타되 가짜 실패를 남기지 않게 한다.
     *
     * @return JsonResponse 회수 결과 (제공자 응답 모양을 묶은 본문)
     */
    public function revoke(): JsonResponse
    {
        return response()->json(['success' => true, 'result' => 'success']);
    }

    /**
     * 사용자 정보를 반환합니다 (Bearer 헤더 또는 access_token 쿼리).
     *
     * @param  Request  $request  요청
     * @return JsonResponse 사용자 정보
     */
    public function userinfo(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? (string) $request->query('access_token', '');
        $grant = $this->cache->get('mock_token:'.hash('sha256', $token));

        if (! is_array($grant)) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $result = MockProfiles::userinfo($grant['style'], $grant['variant'], $grant['subject'], $grant['email']);

        return response()->json($result['body'], $result['status']);
    }

    /**
     * OIDC discovery 문서를 반환합니다.
     *
     * @return JsonResponse discovery
     */
    public function discovery(): JsonResponse
    {
        return response()->json([
            'issuer' => $this->issuer(),
            'authorization_endpoint' => $this->issuer().'/authorize?provider=oidc',
            'token_endpoint' => $this->issuer().'/token',
            'userinfo_endpoint' => $this->issuer().'/userinfo',
            'jwks_uri' => $this->issuer().'/jwks',
            'response_types_supported' => ['code'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]);
    }

    /**
     * 서명 공개키 목록을 반환합니다.
     *
     * @return JsonResponse JWKS
     */
    public function jwks(): JsonResponse
    {
        return response()->json(['keys' => [$this->keys->jwk()]]);
    }

    /**
     * 모의 발급자 주소(= 모의 엔드포인트 기준 주소)를 반환합니다.
     *
     * @return string 발급자
     */
    public static function baseUrl(): string
    {
        return SiteUrl::absolute('/plugins/sirsoft-social_login/mock');
    }

    /**
     * 발급자를 반환합니다.
     *
     * @return string 발급자
     */
    private function issuer(): string
    {
        return self::baseUrl();
    }

    /**
     * 동의 화면을 그립니다.
     *
     * @param  Request  $request  요청
     * @return Response 화면
     */
    private function consentPage(Request $request): Response
    {
        $hidden = '';
        foreach (['provider', 'client_id', 'redirect_uri', 'state', 'nonce', 'code_challenge', 'code_challenge_method', 'scope'] as $field) {
            if ($request->filled($field)) {
                $hidden .= '<input type="hidden" name="'.e($field).'" value="'.e((string) $request->input($field)).'">';
            }
        }

        $options = '';
        foreach (MockProfiles::VARIANTS as $variant) {
            $selected = $request->query('variant') === $variant ? ' selected' : '';
            $options .= '<option value="'.e($variant).'"'.$selected.'>'.e($variant).'</option>';
        }

        $style = $this->style($request);
        $body = '<form method="post" action="'.e($request->url()).'">'
            .'<input type="hidden" name="_token" value="'.e(csrf_token()).'">'.$hidden
            .'<p class="note">'.e(__('sirsoft-social_login::messages.mock.notice', ['style' => $style])).'</p>'
            .'<label for="variant">'.e(__('sirsoft-social_login::messages.mock.variant')).'</label>'
            .'<select id="variant" name="variant">'.$options.'</select>'
            .'<label for="email">'.e(__('sirsoft-social_login::messages.mock.email')).'</label>'
            .'<input id="email" name="email" type="email" value="'.e('meas40.'.Str::lower(Str::random(8)).'@example.test').'">'
            .'<label for="subject">'.e(__('sirsoft-social_login::messages.mock.subject')).'</label>'
            .'<input id="subject" name="subject" type="text" value="'.e((string) random_int(100000000, 999999999)).'">'
            .'<div class="actions">'
            .'<button class="primary" type="submit" name="decision" value="approve">'.e(__('sirsoft-social_login::messages.mock.approve')).'</button>'
            .'<button type="submit" name="decision" value="deny">'.e(__('sirsoft-social_login::messages.mock.deny')).'</button>'
            .'</div></form>';

        return HtmlPage::render(__('sirsoft-social_login::messages.mock.title'), $body);
    }

    /**
     * 응답 모양을 고릅니다.
     *
     * @param  Request  $request  요청
     * @return string 모양
     */
    private function style(Request $request): string
    {
        $style = (string) $request->input('provider', 'oidc');

        return in_array($style, MockProfiles::STYLES, true) ? $style : 'oidc';
    }

    /**
     * 모의 사용자 ID 를 정합니다 — 카카오 모양은 숫자, 네이버 모양은 대소문자 섞인 문자열.
     *
     * @param  Request  $request  요청
     * @param  string  $style  모양
     * @return string 사용자 ID
     */
    private function subject(Request $request, string $style): string
    {
        $input = trim((string) $request->input('subject', ''));
        if ($input !== '' && strlen($input) <= 64) {
            return $input;
        }

        return match ($style) {
            'kakao', 'facebook' => (string) random_int(100000000, 999999999),
            'naver' => 'Nv'.Str::random(20),
            default => 'mock-'.Str::lower(Str::random(16)),
        };
    }

    /**
     * 이메일 입력을 정리합니다.
     *
     * @param  Request  $request  요청
     * @return string 이메일
     */
    private function email(Request $request): string
    {
        $email = trim((string) $request->input('email', ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : 'meas40.'.Str::lower(Str::random(8)).'@example.test';
    }

    /**
     * 돌아갈 주소가 이 사이트의 이 플러그인 콜백인지 확인합니다 — 모의 제공자가 열린 리다이렉트가 되지 않게.
     *
     * @param  string  $uri  redirect_uri
     * @return bool 이 플러그인 콜백이면 true
     */
    private function isOwnCallback(string $uri): bool
    {
        $prefix = SiteUrl::absolute('/plugins/sirsoft-social_login/oauth').'/';

        return str_starts_with($uri, $prefix) && str_ends_with((string) parse_url($uri, PHP_URL_PATH), '/callback');
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
