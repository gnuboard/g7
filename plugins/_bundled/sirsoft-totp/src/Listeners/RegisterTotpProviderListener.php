<?php

namespace Plugins\Sirsoft\Totp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Extension\IdentityVerificationInterface;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Totp\Identity\TotpIdentityProvider;

/**
 * TOTP Provider 를 코어 IdentityVerificationManager 에 등록하는 filter 훅 listener.
 *
 * `core.identity.registered_providers` filter 를 구독해 본 플러그인의 Provider 인스턴스를
 * 레지스트리에 추가한다. nhnkcp 선례와 동형이다.
 *
 * @since 1.0.0
 */
class RegisterTotpProviderListener implements HookListenerInterface
{
    /** 본 플러그인 식별자 */
    private const IDENTIFIER = 'sirsoft-totp';

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>> 훅 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.identity.registered_providers' => [
                'method' => 'register',
                'priority' => 20,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 표준 진입점 — getSubscribedHooks 가 method='register' 를 명시하므로 미사용.
     *
     * @param  mixed  ...$args  사용 안 함
     * @return void
     */
    public function handle(...$args): void
    {
        // no-op — 실제 진입점은 register() 메서드 (filter 훅)
    }

    /**
     * Provider 인스턴스를 레지스트리에 추가합니다.
     *
     * settings 는 `PluginSettingsService::get()` 으로 매 요청 직접 조회한다 — 코어
     * `PluginSettingsService::save()` 가 인메모리 캐시만 비우므로 config() 로 읽으면
     * 변경된 설정이 다음 요청에 반영되지 않는다 (nhnkcp 선례와 같은 이유).
     *
     * @param  array<string, IdentityVerificationInterface>  $providers  현재 레지스트리
     * @return array<string, IdentityVerificationInterface> 본 provider 가 추가된 레지스트리
     */
    public function register(array $providers): array
    {
        $settings = app(PluginSettingsService::class)->get(self::IDENTIFIER);

        $provider = app()->makeWith(TotpIdentityProvider::class, [
            'config' => is_array($settings) ? $settings : [],
        ]);

        $providers[TotpIdentityProvider::PROVIDER_ID] = $provider;

        return $providers;
    }
}
