<?php

namespace Plugins\Sirsoft\SocialLogin\Drivers;

use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;

/**
 * 네이버 로그인 드라이버.
 *
 * 네이버는 이메일 검증 여부를 알려 주지 않는다(emailVerified = null). 프로필 오류는 HTTP 200 에 `resultcode` 가 00 이
 * 아닌 값으로 오고(`024` = 인증 실패), 토큰 오류도 200 본문의 `error` 로 온다. `expires_in` 은 문자열이다.
 * PKCE 는 지원하지 않는다.
 *
 * @since 1.0.0
 */
class NaverDriver extends AbstractOAuth2Driver
{
    /**
     * 드라이버 이름을 반환합니다.
     *
     * @return string naver
     */
    public function driverName(): string
    {
        return 'naver';
    }

    /**
     * 기본 주소를 반환합니다.
     *
     * @return array<string, string|null> 주소
     */
    protected function defaultEndpoints(): array
    {
        return [
            'authorize' => 'https://nid.naver.com/oauth2.0/authorize',
            'token' => 'https://nid.naver.com/oauth2.0/token',
            'userinfo' => 'https://openapi.naver.com/v1/nid/me',
            'revoke' => 'https://nid.naver.com/oauth2.0/token',
        ];
    }

    /**
     * 지원 기능을 반환합니다.
     *
     * @return array<string, mixed> 지원 기능
     */
    public function supports(): array
    {
        return ['pkce' => false, 'revoke' => true, 'webhook' => true] + parent::supports();
    }

    /**
     * 네이버 토큰 발급 요청은 시작 때 만든 state 를 함께 받는다(발급 요청 필수 값).
     *
     * @param  AuthorizationRequest  $req  시작 정보
     * @return array<string, string> 추가 요청 값
     */
    protected function extraTokenParams(AuthorizationRequest $req): array
    {
        return ['state' => $req->state];
    }

    /**
     * 프로필 응답을 정규화합니다.
     *
     * @param  array<string, mixed>  $body  `v1/nid/me` 응답
     * @param  TokenSet  $tokens  토큰
     * @param  AuthorizationRequest  $req  시작 정보
     * @return NormalizedProfile 정규화 프로필
     *
     * @throws ProviderException resultcode 가 00 이 아닐 때
     */
    protected function normalizeProfile(array $body, TokenSet $tokens, AuthorizationRequest $req): NormalizedProfile
    {
        $resultCode = (string) ($body['resultcode'] ?? '');
        if ($resultCode !== '00') {
            throw new ProviderException(
                $resultCode !== '' ? $resultCode : 'profile_fetch_failed',
                mb_substr((string) ($body['message'] ?? ''), 0, 500),
            );
        }

        $profile = is_array($body['response'] ?? null) ? $body['response'] : [];

        return new NormalizedProfile(
            providerUserId: $this->requireId($profile['id'] ?? null),
            email: NormalizedProfile::email($profile['email'] ?? null),
            emailVerified: null,
            name: NormalizedProfile::str($profile['name'] ?? null),
            nickname: NormalizedProfile::str($profile['nickname'] ?? null),
            avatarUrl: NormalizedProfile::str($profile['profile_image'] ?? null),
            raw: $profile,
        );
    }

    /**
     * 네이버 쪽 연결을 끊습니다 — 결과는 HTTP 상태로만 판정합니다.
     *
     * @param  TokenSet  $tokens  토큰
     * @param  SocialAccount  $account  연결
     * @return bool 성공 여부
     */
    public function revoke(TokenSet $tokens, SocialAccount $account): bool
    {
        $response = $this->send(
            fn () => $this->http()->asForm()->post((string) $this->endpoint('revoke'), [
                'grant_type' => 'delete',
                'client_id' => (string) ($this->config['client_id'] ?? ''),
                'client_secret' => $this->clientSecret(),
                'access_token' => $tokens->accessToken,
                'service_provider' => 'NAVER',
            ]),
            'revoke_failed',
        );

        return $response->successful();
    }
}
