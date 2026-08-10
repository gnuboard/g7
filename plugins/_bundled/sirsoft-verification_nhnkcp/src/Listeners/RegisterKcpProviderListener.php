<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Extension\IdentityVerificationInterface;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;

/**
 * KCP Provider 를 코어 IdentityVerificationManager 에 등록하는 filter 훅 listener.
 *
 * `core.identity.registered_providers` filter 훅을 구독하여 본 plugin 의 Provider 인스턴스를
 * 코어 레지스트리 배열에 추가한다. isAvailable 여부와 무관하게 항상 등록한다 — 코어 admin UI 가
 * 자동으로 "사용 불가" 배지를 표시한다.
 *
 * @since 1.0.0
 */
class RegisterKcpProviderListener implements HookListenerInterface
{
    /** 본 플러그인 식별자 */
    private const IDENTIFIER = 'sirsoft-verification_nhnkcp';

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
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
     * HookListenerInterface 의 추상 메서드 충족 목적으로만 정의한다.
     *
     * @param  mixed  ...$args  사용 안 함
     */
    public function handle(...$args): void
    {
        // no-op — 실제 진입점은 register() 메서드 (filter 훅)
    }

    /**
     * Provider 인스턴스를 레지스트리에 추가한다.
     *
     * settings 는 `PluginSettingsService::get()` 으로 매 요청마다 직접 조회한다 (코어
     * PluginSettingsService::save() 가 인메모리 캐시만 초기화하므로 config() 사용 시 변경된
     * settings 가 다음 요청에 반영되지 않는다).
     *
     * @param  array<string, IdentityVerificationInterface>  $providers  현재 레지스트리
     * @return array<string, IdentityVerificationInterface> 본 provider 가 추가된 레지스트리
     */
    public function register(array $providers): array
    {
        $settings = app(PluginSettingsService::class)->get(self::IDENTIFIER);

        // 컨테이너 해석에 위임해 ServiceProvider 의 contextual binding(cacheServices)으로
        // 본 플러그인 도메인의 캐시가 자동 주입되도록 한다. config 만 동적 값이라 makeWith 로 주입.
        $provider = app()->makeWith(KcpIdentityProvider::class, [
            'config' => is_array($settings) ? $settings : [],
        ]);

        $providers[KcpIdentityProvider::PROVIDER_ID] = $provider;

        return $providers;
    }
}
