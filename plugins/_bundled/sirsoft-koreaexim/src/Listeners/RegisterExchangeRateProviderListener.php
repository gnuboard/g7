<?php

namespace Plugins\Sirsoft\Koreaexim\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Koreaexim\Services\ManagedCurrencyResolver;

class RegisterExchangeRateProviderListener implements HookListenerInterface
{
    /**
     * 처리에 필요한 의존 객체를 주입합니다.
     *
     * @param  ManagedCurrencyResolver  $resolver  resolver 입력값
     */
    public function __construct(private ManagedCurrencyResolver $resolver) {}

    /**
     * 구독 메서드와 동기 실행 계약을 선언합니다.
     *
     * @return array 처리 결과
     */
    public static function getSubscribedHooks(): array
    {
        return ['sirsoft-ecommerce.currency.registered_rate_providers' => ['method' => 'registerProvider', 'type' => 'filter', 'priority' => 10]];
    }

    /**
     * 등록된 처리를 실행합니다.
     *
     * @param  mixed  $args  args 입력값
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 모듈 카탈로그에 제공자 정보와 관리 범위를 추가합니다.
     *
     * @param  array  $providers  providers 입력값
     * @return array 처리 결과
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = array_merge(['id' => 'koreaexim', 'name_key' => 'sirsoft-koreaexim::provider.name',
            'name' => localized_label(nameKey: 'sirsoft-koreaexim::provider.name'), 'icon' => 'chart-line',
            'settings_url' => '/admin/plugins/sirsoft-koreaexim/settings'], $this->resolver->resolve());

        return $providers;
    }
}
