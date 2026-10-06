<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\PayKginicis\Listeners\RestrictPaymentMethodsForCurrencyListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 결제 통화별 KG 이니시스 결제수단 제한 — 쇼핑몰 서버 훅에 답한다 (#639 ⑤, 결정 D17)
 *
 * 예전 브라우저 제한기(`checkoutJpyPaymentMethodRestrictor`)와 같은 규칙이어야 한다: 엔화는 운영자가 제한을 켰을 때만
 * `card`·PayPay·편의점 외를 빼고, 엔화가 아니면 일본 전용 수단을 늘 뺀다. 다른 플러그인 id 는 건드리지 않는다.
 */
class RestrictPaymentMethodsForCurrencyListenerTest extends PluginTestCase
{
    private const ALL = ['card', 'vbank', 'dbank', 'kginicis_kakaopay', 'kginicis_japan_paypay', 'kginicis_japan_cvs', 'toss_easy_pay'];

    /**
     * 플러그인 설정을 흉내 냅니다.
     *
     * @param  array<string, mixed>  $settings  설정 값
     * @return void 반환값 없음
     */
    private function mockSettings(array $settings): void
    {
        $service = $this->createMock(PluginSettingsService::class);
        $service->method('get')->willReturnCallback(
            fn (string $identifier, ?string $key = null, mixed $default = null): mixed => $identifier !== 'sirsoft-pay_kginicis'
                ? $default
                : ($key === null ? $settings : ($settings[$key] ?? $default))
        );
        $this->app->instance(PluginSettingsService::class, $service);
    }

    /**
     * 엔화 + 제한 켬: 이니시스 수단 중 card·PayPay·편의점만 남고, 다른 플러그인 수단은 그대로다.
     *
     * @scenario currency=jpy, restrict=on
     *
     * @effects jpy_keeps_only_allowed_inicis_methods
     *
     * @return void 반환값 없음
     */
    public function test_jpy_with_restriction_keeps_only_allowed_methods(): void
    {
        $this->mockSettings(['japan_restrict_jpy_payment_methods' => true]);

        $this->assertSame(
            ['card', 'kginicis_japan_paypay', 'kginicis_japan_cvs', 'toss_easy_pay'],
            (new RestrictPaymentMethodsForCurrencyListener)->filter(self::ALL, 'JPY'),
        );
    }

    /**
     * 엔화 + 제한 끔: 아무것도 빼지 않는다(운영자 토글에 종속).
     *
     * @scenario currency=jpy, restrict=off
     *
     * @effects jpy_restriction_follows_operator_toggle
     *
     * @return void 반환값 없음
     */
    public function test_jpy_without_restriction_keeps_everything(): void
    {
        $this->mockSettings(['japan_restrict_jpy_payment_methods' => false]);

        $this->assertSame(self::ALL, (new RestrictPaymentMethodsForCurrencyListener)->filter(self::ALL, 'jpy'));
    }

    /**
     * 엔화가 아니면 일본 전용 수단을 늘 뺀다.
     *
     * @scenario currency=krw, restrict=off
     *
     * @effects non_jpy_hides_japan_only_methods
     *
     * @return void 반환값 없음
     */
    public function test_non_jpy_hides_japan_only_methods(): void
    {
        $this->mockSettings([]);

        $this->assertSame(
            ['card', 'vbank', 'dbank', 'kginicis_kakaopay', 'toss_easy_pay'],
            (new RestrictPaymentMethodsForCurrencyListener)->filter(self::ALL, 'KRW'),
        );
    }

    /**
     * 쇼핑몰 필터 훅을 동기 필터로 구독한다.
     *
     * @scenario currency=krw, restrict=off
     *
     * @effects non_jpy_hides_japan_only_methods
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_to_the_ecommerce_currency_filter(): void
    {
        $hooks = RestrictPaymentMethodsForCurrencyListener::getSubscribedHooks();
        $hook = $hooks['sirsoft-ecommerce.checkout.filter_payment_methods_for_currency'];

        $this->assertSame('filter', $hook['type']);
        $this->assertSame('filter', $hook['method']);
        $this->assertTrue($hook['sync']);
    }
}
