<?php

namespace Plugins\Sirsoft\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\SocialLogin\Contracts\ProviderDriverInterface;
use Plugins\Sirsoft\SocialLogin\Mock\MockController;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;

/**
 * 모의 제공자 등록 리스너.
 *
 * 모의 게이트가 켜져 있을 때만 `mock_kakao`·`mock_naver`·`mock_google`·`mock_facebook`·`mock_oidc` 를 더한다. 각각 실제
 * 드라이버에 주소만 모의 엔드포인트로 바꾼 것이라, 확장이 제공자를 등록하는 경로(`provider.filter_registered`)를 그대로 실증한다.
 *
 * @since 1.0.0
 */
class RegisterMockProvidersListener implements HookListenerInterface
{
    /** 모의 키 접두사 (커스텀 제공자 키로 예약) */
    public const PREFIX = 'mock_';

    /**
     * @param  MockGate  $gate  모의 게이트
     */
    public function __construct(private readonly MockGate $gate) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        // 훅 이름은 문자열로 적는다 — 확장 문서 생성기가 구독 표를 정적으로 읽는다(값은 ProviderRegistry::FILTER_REGISTERED 와 같다).
        return [
            'sirsoft-social_login.provider.filter_registered' => ['method' => 'register', 'priority' => 10, 'type' => 'filter', 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 모의 제공자를 더합니다.
     *
     * @param  array<string, ProviderDriverInterface>  $drivers  등록 목록
     * @return array<string, ProviderDriverInterface> 모의 제공자를 더한 목록
     */
    public function register(array $drivers): array
    {
        if (! $this->gate->enabled()) {
            return $drivers;
        }

        foreach (self::configs() as $key => $config) {
            $drivers[$key] = app(ProviderRegistry::DRIVERS[$config['driver']])->withConfig($config);
        }

        return $drivers;
    }

    /**
     * 모의 제공자 설정을 만듭니다.
     *
     * @return array<string, array<string, mixed>> 키 => 설정
     */
    public static function configs(): array
    {
        $base = MockController::baseUrl();
        $configs = [];

        foreach (['kakao', 'naver', 'google', 'facebook', 'oidc'] as $style) {
            $override = $style === 'oidc' ? [] : [
                'authorize' => $base.'/authorize?provider='.$style,
                'token' => $base.'/token',
                'userinfo' => $base.'/userinfo',
                'jwks' => $base.'/jwks',
                'issuer' => $base,
                'revoke' => $base.'/token',
            ];

            $configs[self::PREFIX.$style] = [
                'key' => self::PREFIX.$style,
                'driver' => $style,
                'type' => 'custom',
                'enabled' => true,
                'label' => [
                    'ko' => __('sirsoft-social_login::messages.mock.label', ['provider' => $style], 'ko'),
                    'en' => __('sirsoft-social_login::messages.mock.label', ['provider' => $style], 'en'),
                ],
                'client_id' => 'mock-client-'.$style,
                'client_secret' => 'mock-secret',
                'discovery_url' => $style === 'oidc' ? $base.'/.well-known/openid-configuration' : null,
                'signup_allowed' => true,
                'email_missing_policy' => 'ask',
                'email_trust' => 'flag',
                'extra' => ['endpoint_override' => $override, 'mock' => true],
            ];
        }

        return $configs;
    }
}
