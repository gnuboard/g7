<?php

namespace Plugins\Sirsoft\Gdpr\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Gdpr\Consent\GdprConsentProvider;

/**
 * 이 플러그인의 동의 제공자를 코어 동의 매니저에 등록하는 필터 리스너 (#639)
 *
 * `core.consent.registered_providers` 필터에 제공자 인스턴스를 더합니다(본인인증 제공자 등록과 같은 방식).
 * 플러그인을 비활성화하면 리스너가 부팅되지 않아 제공자도 사라지고, 코어는 운영자 선택에 따라
 * none(제공자 없음) 또는 missing(고른 제공자 사라짐)으로 판정합니다.
 */
class RegisterGdprConsentProviderListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록.
     *
     * @return array<string, array{method?: string, priority?: int, type?: string, sync?: bool}> 훅 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.consent.registered_providers' => [
                'method' => 'register',
                'priority' => 20,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 표준 진입점 — 실제 진입점은 register() 다.
     *
     * @param  mixed  ...$args  사용 안 함
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // no-op — 필터 진입점은 register()
    }

    /**
     * 제공자를 목록에 더합니다.
     *
     * @param  mixed  $providers  지금까지의 제공자 목록
     * @return array<int, mixed> 이 플러그인의 제공자를 더한 목록
     */
    public function register(mixed $providers): array
    {
        $providers = is_array($providers) ? $providers : [];
        $providers[] = app(GdprConsentProvider::class);

        return $providers;
    }
}
