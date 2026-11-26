<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Tosspayments\Listeners\ProvidePaymentMethodDisplayLabelListener;
use Plugins\Sirsoft\Tosspayments\Plugin;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 토스 간편결제 주문 결제수단 표시 라벨 리스너 테스트
 *
 * 토스 간편결제 주문은 결제수단 칸이 코어 값 `card` 라 이커머스는 「신용카드」만 안다. 결제 결과의 간편결제사 이름
 * (`embedded_pg_provider`)으로 「카카오페이 (토스페이먼츠)」 를 채우고, 자기 결제가 아니거나 앞선 구독자가 채운 값은
 * 건드리지 않는다.
 */
class ProvidePaymentMethodDisplayLabelListenerTest extends PluginTestCase
{
    /**
     * 저장하지 않은 토스 결제 행 Model 을 만듭니다.
     *
     * @param  string|null  $embeddedPgProvider  결제 결과 간편결제사 이름
     * @param  string  $pgProvider  결제 행 PG 식별자
     * @return OrderPayment 결제 행 (저장하지 않음)
     */
    private function tossPayment(?string $embeddedPgProvider, string $pgProvider = 'tosspayments'): OrderPayment
    {
        return (new OrderPayment)->forceFill([
            'payment_status' => PaymentStatusEnum::PAID,
            'pg_provider' => $pgProvider,
            'payment_method' => 'card',
            'embedded_pg_provider' => $embeddedPgProvider,
        ]);
    }

    /**
     * 이커머스 필터 훅을 필터로 구독하고 플러그인이 리스너를 선언한다.
     *
     * @scenario payment_plugin=tosspayments
     *
     * @effects display_label_listener_subscribes_to_the_ecommerce_filter
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_to_the_ecommerce_display_label_filter(): void
    {
        $hook = ProvidePaymentMethodDisplayLabelListener::getSubscribedHooks()['sirsoft-ecommerce.order.filter_payment_method_display_label'] ?? null;

        $this->assertIsArray($hook);
        $this->assertSame('filter', $hook['type'] ?? null);
        $this->assertSame('provideDisplayLabel', $hook['method'] ?? null);
        $this->assertSame(10, $hook['priority'] ?? null);
        $this->assertContains(ProvidePaymentMethodDisplayLabelListener::class, (new Plugin)->getHookListeners());
    }

    /**
     * 토스 간편결제사 8종의 한국어 표시 라벨.
     *
     * @return array<string, array{0: string, 1: string}> 간편결제사 이름, 기대 라벨
     */
    public static function easyPayProviders(): array
    {
        return [
            '토스페이' => ['토스페이', '토스페이 (토스페이먼츠)'],
            '카카오페이' => ['카카오페이', '카카오페이 (토스페이먼츠)'],
            '네이버페이' => ['네이버페이', '네이버페이 (토스페이먼츠)'],
            '페이코' => ['페이코', '페이코 (토스페이먼츠)'],
            '삼성페이' => ['삼성페이', '삼성페이 (토스페이먼츠)'],
            '애플페이' => ['애플페이', '애플페이 (토스페이먼츠)'],
            '엘페이' => ['엘페이', 'L.'."\u{200B}".'pay (토스페이먼츠)'],
            'SSG페이' => ['SSG페이', 'SSG페이 (토스페이먼츠)'],
        ];
    }

    /**
     * 결제 결과 간편결제사 이름으로 카탈로그와 같은 이름을 채운다.
     *
     * @scenario payment_plugin=tosspayments
     *
     * @effects display_label_listener_labels_only_its_own_easy_pay_payments, easy_pay_display_label_from_provider
     *
     * @param  string  $provider  결제 결과 간편결제사 이름
     * @param  string  $expected  기대 라벨
     * @return void 반환값 없음
     */
    #[DataProvider('easyPayProviders')]
    public function test_labels_toss_easy_pay_payment_with_catalog_name(string $provider, string $expected): void
    {
        app()->setLocale('ko');

        $label = app(ProvidePaymentMethodDisplayLabelListener::class)->provideDisplayLabel(null, $this->tossPayment($provider));

        $this->assertSame($expected, $label);
    }

    /**
     * 토스가 예전에 내려주던 「토스결제」 도 토스페이로 보인다.
     *
     * @scenario payment_plugin=tosspayments
     *
     * @effects easy_pay_display_label_from_provider
     *
     * @return void 반환값 없음
     */
    public function test_legacy_toss_easy_pay_name_maps_to_tosspay(): void
    {
        app()->setLocale('ko');

        $label = app(ProvidePaymentMethodDisplayLabelListener::class)->provideDisplayLabel(null, $this->tossPayment('토스결제'));

        $this->assertSame('토스페이 (토스페이먼츠)', $label);
    }

    /**
     * 이 플러그인이 모르는 간편결제사도 원문에 PG 이름을 붙여 보인다 — 「신용카드」로 숨지 않는다.
     *
     * @scenario payment_plugin=tosspayments
     *
     * @effects easy_pay_display_label_from_provider
     *
     * @return void 반환값 없음
     */
    public function test_unknown_easy_pay_provider_falls_back_to_raw_name_with_pg(): void
    {
        app()->setLocale('ko');

        $label = app(ProvidePaymentMethodDisplayLabelListener::class)->provideDisplayLabel(null, $this->tossPayment(' 핀페이 '));

        $this->assertSame('핀페이 (토스페이먼츠)', $label);
    }

    /**
     * 다른 PG 의 결제·간편결제사가 없는 토스 카드·가상계좌 결제는 받은 값을 그대로 돌려준다.
     *
     * @scenario payment_plugin=tosspayments
     *
     * @effects display_label_listener_labels_only_its_own_easy_pay_payments
     *
     * @return void 반환값 없음
     */
    public function test_leaves_other_pg_and_plain_payments_untouched(): void
    {
        $listener = app(ProvidePaymentMethodDisplayLabelListener::class);

        $this->assertNull($listener->provideDisplayLabel(null, $this->tossPayment('카카오페이', 'nicepayments')));
        $this->assertNull($listener->provideDisplayLabel(null, $this->tossPayment(null)));
        $this->assertNull($listener->provideDisplayLabel(null, $this->tossPayment('  ')));
        $this->assertNull($listener->provideDisplayLabel(null, null));
    }

    /**
     * 앞선 구독자가 채운 라벨은 덮어쓰지 않는다.
     *
     * @scenario payment_plugin=tosspayments
     *
     * @effects display_label_listener_labels_only_its_own_easy_pay_payments
     *
     * @return void 반환값 없음
     */
    public function test_keeps_a_label_provided_by_an_earlier_subscriber(): void
    {
        $label = app(ProvidePaymentMethodDisplayLabelListener::class)->provideDisplayLabel('앞선 라벨', $this->tossPayment('카카오페이'));

        $this->assertSame('앞선 라벨', $label);
    }

    /**
     * 결제 행 Model 이 아닌 일반 객체도 같은 속성으로 읽는다.
     *
     * @scenario payment_plugin=tosspayments
     *
     * @effects easy_pay_display_label_from_provider
     *
     * @return void 반환값 없음
     */
    public function test_reads_plain_object_with_same_attributes(): void
    {
        app()->setLocale('ko');

        $label = app(ProvidePaymentMethodDisplayLabelListener::class)->provideDisplayLabel(null, (object) [
            'pg_provider' => 'tosspayments',
            'embedded_pg_provider' => '네이버페이',
        ]);

        $this->assertSame('네이버페이 (토스페이먼츠)', $label);
    }

    /**
     * 영어 화면은 영어 이름이다.
     *
     * @scenario payment_plugin=tosspayments
     *
     * @effects easy_pay_display_label_from_provider
     *
     * @return void 반환값 없음
     */
    public function test_english_locale_uses_english_names(): void
    {
        app()->setLocale('en');

        $listener = app(ProvidePaymentMethodDisplayLabelListener::class);

        $this->assertSame('KakaoPay (TossPayments)', $listener->provideDisplayLabel(null, $this->tossPayment('카카오페이')));
        $this->assertSame('SSG Pay (TossPayments)', $listener->provideDisplayLabel(null, $this->tossPayment('SSG페이')));
        $this->assertSame('핀페이 (TossPayments)', $listener->provideDisplayLabel(null, $this->tossPayment('핀페이')));
    }
}
