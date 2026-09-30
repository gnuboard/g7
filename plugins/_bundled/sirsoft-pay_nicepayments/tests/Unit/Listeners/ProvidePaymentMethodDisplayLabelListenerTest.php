<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Listeners;

use Plugins\Sirsoft\PayNicepayments\Listeners\ProvidePaymentMethodDisplayLabelListener;
use Plugins\Sirsoft\PayNicepayments\Plugin;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 관리자 주문 목록 결제수단 표시 라벨 제공 리스너 테스트
 *
 * 이커머스는 PG 를 모르므로 간편결제 원 수단 라벨을 이 플러그인이 필터 훅으로 채운다. 한 목록에 여러 PG 의
 * 주문이 섞이므로 자기 결제가 아니거나 앞선 구독자가 채운 값은 건드리지 않아야 한다.
 */
class ProvidePaymentMethodDisplayLabelListenerTest extends PluginTestCase
{
    /**
     * 이 플러그인의 간편결제 결제 레코드 표본을 만듭니다.
     *
     * @param  string  $pgProvider  결제 레코드의 PG 식별자
     * @return object 결제 레코드 (이커머스 OrderPayment 와 같은 속성)
     */
    private function easyPayPayment(string $pgProvider = 'nicepayments'): object
    {
        return (object) [
            'pg_provider' => $pgProvider,
            'payment_method' => 'card',
            'embedded_pg_provider' => 'payco',
            'payment_meta' => [
                'nicepay_easy_pay_method' => 'nicepay_payco',
                'nicepay_easy_pay_provider' => 'payco',
                'nicepay_easy_pay_label' => ['ko' => 'PAYCO (페이코)', 'en' => 'PAYCO'],
            ],
        ];
    }

    /**
     * 이커머스 필터 훅을 필터로 구독하고 플러그인이 리스너를 선언한다.
     *
     * @scenario payment_plugin=nicepayments
     *
     * @effects display_label_listener_subscribes_to_the_ecommerce_filter
     *
     * @return void 없음
     */
    public function test_subscribes_to_the_ecommerce_display_label_filter(): void
    {
        $hooks = ProvidePaymentMethodDisplayLabelListener::getSubscribedHooks();
        $hook = $hooks['sirsoft-ecommerce.order.filter_payment_method_display_label'] ?? null;

        $this->assertIsArray($hook);
        $this->assertSame('filter', $hook['type'] ?? null);
        $this->assertSame('provideDisplayLabel', $hook['method'] ?? null);
        $this->assertContains(ProvidePaymentMethodDisplayLabelListener::class, (new Plugin)->getHookListeners());
    }

    /**
     * 자기 간편결제 결제는 「원 수단 (기본 수단)」 라벨을 채운다.
     *
     * @scenario payment_plugin=nicepayments
     *
     * @effects display_label_listener_labels_only_its_own_easy_pay_payments
     *
     * @return void 없음
     */
    public function test_labels_own_easy_pay_payment(): void
    {
        app()->setLocale('ko');

        $label = (new ProvidePaymentMethodDisplayLabelListener)->provideDisplayLabel(null, $this->easyPayPayment());

        $this->assertIsString($label);
        $this->assertStringStartsWith('PAYCO (페이코) (', $label);
    }

    /**
     * 다른 PG 의 결제·자기 결제의 일반 카드 결제는 라벨을 채우지 않는다.
     *
     * @scenario payment_plugin=nicepayments
     *
     * @effects display_label_listener_labels_only_its_own_easy_pay_payments
     *
     * @return void 없음
     */
    public function test_leaves_other_pg_and_plain_payments_untouched(): void
    {
        $listener = new ProvidePaymentMethodDisplayLabelListener;

        $this->assertNull($listener->provideDisplayLabel(null, $this->easyPayPayment('nhnkcp')));
        $this->assertNull($listener->provideDisplayLabel(null, (object) [
            'pg_provider' => 'nicepayments',
            'payment_method' => 'card',
            'embedded_pg_provider' => null,
            'payment_meta' => [],
        ]));
        $this->assertNull($listener->provideDisplayLabel(null, null));
    }

    /**
     * 앞선 구독자가 채운 라벨은 덮어쓰지 않는다.
     *
     * @scenario payment_plugin=nicepayments
     *
     * @effects display_label_listener_labels_only_its_own_easy_pay_payments
     *
     * @return void 없음
     */
    public function test_keeps_a_label_provided_by_an_earlier_subscriber(): void
    {
        $label = (new ProvidePaymentMethodDisplayLabelListener)->provideDisplayLabel('앞선 라벨', $this->easyPayPayment());

        $this->assertSame('앞선 라벨', $label);
    }
}
