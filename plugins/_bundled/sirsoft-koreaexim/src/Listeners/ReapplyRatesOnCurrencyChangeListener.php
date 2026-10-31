<?php

namespace Plugins\Sirsoft\Koreaexim\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Koreaexim\Services\ExchangeRateUpdateService;

class ReapplyRatesOnCurrencyChangeListener implements HookListenerInterface
{
    /**
     * 처리에 필요한 의존 객체를 주입합니다.
     *
     * @param  ExchangeRateUpdateService  $service  service 입력값
     */
    public function __construct(private ExchangeRateUpdateService $service) {}

    /**
     * 구독 메서드와 동기 실행 계약을 선언합니다.
     *
     * @return array 처리 결과
     */
    public static function getSubscribedHooks(): array
    {
        return ['sirsoft-ecommerce.settings.after_save' => ['method' => 'reapply', 'sync' => true, 'priority' => 10]];
    }

    /**
     * 등록된 처리를 실행합니다.
     *
     * @param  mixed  $args  args 입력값
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 최신 성공 응답으로 HTTP 없이 통화 환율을 재적용합니다.
     *
     * @param  array  $categories  categories 입력값
     * @return void 반환값 없음
     */
    public function reapply(array $categories): void
    {
        if (in_array('language_currency', $categories, true)) {
            $this->service->reapply();
        }
    }
}
