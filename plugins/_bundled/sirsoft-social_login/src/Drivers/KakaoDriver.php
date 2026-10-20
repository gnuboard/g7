<?php

namespace Plugins\Sirsoft\SocialLogin\Drivers;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookEventType;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * 카카오 로그인 드라이버.
 *
 * 이메일은 `is_email_valid && is_email_verified` 일 때만 검증된 것으로 본다. 회원이 이메일 제공에 동의하지 않았거나
 * (`email_needs_agreement`) 마스킹된 값(`*` 포함)이면 이메일을 비운다 — 마스킹 주소로 회원을 찾거나 가입시키면 안 된다.
 *
 * @since 1.0.0
 */
class KakaoDriver extends AbstractOAuth2Driver
{
    /** 카카오 웹훅 발신 대역 기본값 (카카오 개발자 문서 「웹훅 발신 서버 IP」) */
    public const WEBHOOK_IP_ALLOWLIST = [
        '121.53.90.32/27', '121.53.91.32/27', '203.133.184.32/27', '203.133.185.32/27',
        '211.242.11.0/27', '211.242.12.0/27', '211.242.13.0/27', '211.242.14.0/27',
    ];

    /**
     * 드라이버 이름을 반환합니다.
     *
     * @return string kakao
     */
    public function driverName(): string
    {
        return 'kakao';
    }

    /**
     * 기본 주소를 반환합니다.
     *
     * @return array<string, string|null> 주소
     */
    protected function defaultEndpoints(): array
    {
        return [
            'authorize' => 'https://kauth.kakao.com/oauth/authorize',
            'token' => 'https://kauth.kakao.com/oauth/token',
            'userinfo' => 'https://kapi.kakao.com/v2/user/me',
            'revoke' => 'https://kapi.kakao.com/v1/user/unlink',
        ];
    }

    /**
     * 지원 기능을 반환합니다.
     *
     * @return array<string, mixed> 지원 기능
     */
    public function supports(): array
    {
        return ['revoke' => true, 'webhook' => true] + parent::supports();
    }

    /**
     * 설정 화면 자격증명 필드 정의를 반환합니다 — 어드민 키가 더 있습니다.
     *
     * @return array<int, array<string, mixed>> 필드 정의
     */
    public function credentialSchema(): array
    {
        return array_merge(parent::credentialSchema(), [
            ['key' => 'admin_key', 'type' => 'secret', 'required' => false, 'label' => ['ko' => '어드민 키', 'en' => 'Admin Key']],
        ]);
    }

    /**
     * 프로필 응답을 정규화합니다.
     *
     * @param  array<string, mixed>  $body  `v2/user/me` 응답
     * @param  TokenSet  $tokens  토큰
     * @param  AuthorizationRequest  $req  시작 정보
     * @return NormalizedProfile 정규화 프로필
     */
    protected function normalizeProfile(array $body, TokenSet $tokens, AuthorizationRequest $req): NormalizedProfile
    {
        $account = is_array($body['kakao_account'] ?? null) ? $body['kakao_account'] : [];
        $profile = is_array($account['profile'] ?? null) ? $account['profile'] : [];

        $needsAgreement = ($account['email_needs_agreement'] ?? false) === true;
        $email = $needsAgreement ? null : NormalizedProfile::email($account['email'] ?? null);
        $verified = $email === null
            ? null
            : (($account['is_email_valid'] ?? false) === true && ($account['is_email_verified'] ?? false) === true);

        return new NormalizedProfile(
            providerUserId: $this->requireId($body['id'] ?? null),
            email: $email,
            emailVerified: $verified,
            name: null,
            nickname: NormalizedProfile::str($profile['nickname'] ?? null),
            avatarUrl: NormalizedProfile::str($profile['profile_image_url'] ?? null),
            raw: $body,
        );
    }

    /**
     * 카카오 오류 본문을 읽습니다.
     *
     * 인증 서버 오류는 `error_code`(예 KOE320), API 서버 오류는 `code`(예 -401)·`msg` 로 온다.
     *
     * @param  Response  $response  응답
     * @param  string  $fallbackCode  기본 오류 코드
     * @return ProviderException 예외
     */
    protected function errorFrom(Response $response, string $fallbackCode): ProviderException
    {
        $code = $response->json('code');
        if ($response->json('error_code') === null && is_numeric($code)) {
            return new ProviderException(
                'kakao_'.$code,
                mb_substr((string) ($response->json('msg') ?? ''), 0, 500),
                $response->status(),
            );
        }

        return parent::errorFrom($response, $fallbackCode);
    }

    /**
     * 카카오 쪽 연결을 끊습니다 — 어드민 키로 대상 회원을 지정합니다.
     *
     * @param  TokenSet  $tokens  토큰 (쓰지 않음)
     * @param  SocialAccount  $account  연결
     * @return bool 성공 여부
     */
    public function revoke(TokenSet $tokens, SocialAccount $account): bool
    {
        $adminKey = (string) ($this->config['admin_key'] ?? '');
        if ($adminKey === '') {
            return false;
        }

        $response = $this->send(
            fn () => $this->http()
                ->withHeaders(['Authorization' => 'KakaoAK '.$adminKey])
                ->asForm()
                ->post((string) $this->endpoint('revoke'), [
                    'target_id_type' => 'user_id',
                    'target_id' => $account->provider_user_id,
                ]),
            'revoke_failed',
        );

        return $response->successful();
    }

    /**
     * 카카오 「연결 끊기」 알림을 검증해 이벤트로 바꿉니다.
     *
     * 카카오는 어드민 키를 `Authorization: KakaoAK {어드민 키}` 로 실어 보낸다 — 시간 일정 비교로 맞춘다. 발신 IP 확인
     * (`extra.webhook_ip_allowlist_enabled`, 기본 켜짐)은 카카오 발신 대역(`extra.webhook_ip_allowlist`, 없으면
     * {@see self::WEBHOOK_IP_ALLOWLIST})만 받는다. 리버스 프록시 뒤 사이트는 신뢰 프록시를 설정해야 실제 발신 IP 가 보인다.
     * 앱 ID(`extra.app_id`)를 설정해 두면 그것도 맞춘다.
     *
     * 같은 알림이 다시 와도 한 번만 처리하도록 사건 키를 「회원·사유·수신 분」으로 만든다(카카오 알림에는 사건 번호가 없다).
     *
     * @param  Request  $request  알림 요청
     * @return SocialLoginWebhookEvent 저장 전 이벤트
     *
     * @throws ProviderException 어드민 키(`webhook_auth_invalid`)·IP(`webhook_ip_not_allowed`)·앱 ID(`webhook_app_mismatch`)·본문(`webhook_payload_invalid`)
     */
    public function verifyWebhook(Request $request): ?SocialLoginWebhookEvent
    {
        $adminKey = (string) ($this->config['admin_key'] ?? '');
        if ($adminKey === '' || ! hash_equals('KakaoAK '.$adminKey, (string) $request->header('Authorization', ''))) {
            throw new ProviderException('webhook_auth_invalid');
        }

        $extra = is_array($this->config['extra'] ?? null) ? $this->config['extra'] : [];
        if (($extra['webhook_ip_allowlist_enabled'] ?? true) !== false) {
            $allowlist = is_array($extra['webhook_ip_allowlist'] ?? null) ? $extra['webhook_ip_allowlist'] : self::WEBHOOK_IP_ALLOWLIST;
            if (! IpUtils::checkIp((string) $request->ip(), $allowlist)) {
                throw new ProviderException('webhook_ip_not_allowed');
            }
        }

        $appId = NormalizedProfile::str($request->input('app_id'));
        if (isset($extra['app_id']) && (string) $extra['app_id'] !== '' && ! hash_equals((string) $extra['app_id'], (string) $appId)) {
            throw new ProviderException('webhook_app_mismatch');
        }

        $userId = NormalizedProfile::str($request->input('user_id'));
        $referrer = strtoupper((string) NormalizedProfile::str($request->input('referrer_type')));
        if ($userId === null) {
            throw new ProviderException('webhook_payload_invalid');
        }

        $type = match ($referrer) {
            'ACCOUNT_DELETE', 'FORCED_ACCOUNT_DELETE' => WebhookEventType::AccountDelete,
            'INCOMPLETE_SIGN_UP' => WebhookEventType::IncompleteSignup,
            default => WebhookEventType::Unlink,
        };

        return new SocialLoginWebhookEvent([
            'provider_key' => $this->key(),
            'event_key' => 'kakao:'.$userId.':'.($referrer !== '' ? $referrer : 'UNKNOWN').':'.now()->format('YmdHi'),
            'event_type' => $type->value,
            'provider_user_id' => $userId,
            'payload' => ['app_id' => $appId, 'referrer_type' => $referrer !== '' ? $referrer : null],
        ]);
    }
}
