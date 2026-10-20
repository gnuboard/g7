<?php

namespace Plugins\Sirsoft\SocialLogin\Drivers;

use Illuminate\Http\Request;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookEventType;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;

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

    /**
     * 네이버 「연동 해제」 알림을 검증해 이벤트로 바꿉니다.
     *
     * 알림 값 `clientId`·`encryptUniqueId`·`timestamp`·`signature`. 서명은 `clientId=…&encryptUniqueId=…&timestamp=…` 를
     * 클라이언트 비밀값으로 HMAC-SHA256 한 값(base64)이고 시간 일정 비교로 맞춘다. `encryptUniqueId` 는 회원의 네이버 고유
     * ID 를 AES-128-CBC 로 암호화한 값이다 — 키는 클라이언트 비밀값의 MD5(16바이트), 앞 16바이트가 IV 다
     * ({@see self::decryptUniqueId()}). 실제 앱 실측(M11)에서 이 형식이 맞는지 다시 확인한다.
     * 네이버는 다시 보내지 않으므로 이 검증과 저장은 요청 안에서 끝낸다(처리만 큐로).
     *
     * @param  Request  $request  알림 요청
     * @return SocialLoginWebhookEvent 저장 전 이벤트
     *
     * @throws ProviderException 클라이언트(`webhook_app_mismatch`)·서명(`webhook_signature_invalid`)·복호화(`webhook_payload_invalid`)
     */
    public function verifyWebhook(Request $request): ?SocialLoginWebhookEvent
    {
        $clientId = (string) ($this->config['client_id'] ?? '');
        $secret = $this->clientSecret();
        $inputClient = (string) $request->input('clientId', '');
        $encrypted = (string) $request->input('encryptUniqueId', '');
        $timestamp = (string) $request->input('timestamp', '');
        $signature = (string) $request->input('signature', '');

        if ($clientId === '' || $secret === '' || ! hash_equals($clientId, $inputClient)) {
            throw new ProviderException('webhook_app_mismatch');
        }

        $expected = self::webhookSignature($inputClient, $encrypted, $timestamp, $secret);
        $given = strtr($signature, '-_', '+/');
        if ($signature === '' || ! hash_equals(rtrim($expected, '='), rtrim($given, '='))) {
            throw new ProviderException('webhook_signature_invalid');
        }

        $uniqueId = self::decryptUniqueId($encrypted, $secret);
        if ($uniqueId === null) {
            throw new ProviderException('webhook_payload_invalid');
        }

        return new SocialLoginWebhookEvent([
            'provider_key' => $this->key(),
            'event_key' => 'naver:'.hash('sha256', $uniqueId).':'.$timestamp,
            'event_type' => WebhookEventType::Unlink->value,
            'provider_user_id' => $uniqueId,
            'payload' => ['timestamp' => $timestamp],
        ]);
    }

    /**
     * 알림 서명을 만듭니다.
     *
     * @param  string  $clientId  클라이언트 ID
     * @param  string  $encryptUniqueId  암호화된 고유 ID
     * @param  string  $timestamp  알림 시각 값
     * @param  string  $secret  클라이언트 비밀값
     * @return string base64 서명
     */
    public static function webhookSignature(string $clientId, string $encryptUniqueId, string $timestamp, string $secret): string
    {
        $message = 'clientId='.$clientId.'&encryptUniqueId='.$encryptUniqueId.'&timestamp='.$timestamp;

        return base64_encode(hash_hmac('sha256', $message, $secret, true));
    }

    /**
     * 암호화된 고유 ID 를 풉니다 — base64(IV 16바이트 + 암호문), AES-128-CBC, 키 = MD5(클라이언트 비밀값).
     *
     * @param  string  $value  암호화된 고유 ID
     * @param  string  $secret  클라이언트 비밀값
     * @return string|null 고유 ID (풀 수 없으면 null)
     */
    public static function decryptUniqueId(string $value, string $secret): ?string
    {
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 32) {
            return null;
        }

        $plain = openssl_decrypt(substr($raw, 16), 'aes-128-cbc', md5($secret, true), OPENSSL_RAW_DATA, substr($raw, 0, 16));

        return is_string($plain) ? NormalizedProfile::str($plain) : null;
    }
}
