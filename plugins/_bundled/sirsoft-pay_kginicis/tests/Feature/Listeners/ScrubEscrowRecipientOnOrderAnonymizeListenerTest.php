<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Listeners;

use App\Extension\HookListenerRegistrar;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderPrivacyRetentionService;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\PayKginicis\Listeners\ScrubEscrowRecipientOnOrderAnonymizeListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 에스크로 배송 등록 수령인 사본 파기 리스너 테스트 (이슈 #636)
 *
 * 이커머스 보유기간 익명화가 발행하는 `sirsoft-ecommerce.order.after_anonymize` 를 실제 등록 경로로
 * 구독해, 이 플러그인이 결제 행 `payment_meta.escrow_delivery` 에 남긴 수령인 이름·주소를 지우는지
 * 고정합니다. 훅을 흉내 내지 않고 이커머스 익명화 서비스를 그대로 태운다.
 */
class ScrubEscrowRecipientOnOrderAnonymizeListenerTest extends PluginTestCase
{
    /**
     * 리스너를 실제 등록 경로로 붙입니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookListenerRegistrar::register(ScrubEscrowRecipientOnOrderAnonymizeListener::class, 'sirsoft-pay_kginicis');
    }

    /**
     * 에스크로 배송 등록 기록이 든 결제 행을 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  string  $pgProvider  결제 대행사 식별자
     * @return OrderPayment 결제 행
     */
    private function escrowPayment(Order $order, string $pgProvider): OrderPayment
    {
        $payment = OrderPayment::factory()->create([
            'order_id' => $order->id,
            'pg_provider' => $pgProvider,
            'payment_meta' => [
                'mid' => 'INIpayTest',
                'escrow_delivery' => [
                    'registered_at' => '2020-01-01 10:00:00',
                    'invoice' => '123456789012',
                    'ex_code' => 'cjgls',
                    'recv_name' => '김철수',
                    'recv_addr' => '서울특별시 강남구 테헤란로 1 101호',
                ],
            ],
        ]);

        DB::table('ecommerce_order_payments')->where('id', $payment->id)->update(['updated_at' => '2020-01-01 10:00:00']);

        return $payment->fresh();
    }

    /**
     * 주문 익명화는 KG 이니시스 결제 행의 에스크로 수령인 이름·주소를 지우고, 송장번호·택배사 등
     * 배송 거래 기록과 결제 행 수정 시각은 남기며, 다른 결제 대행사의 행은 건드리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario escrow_state=registered, pg_provider=kginicis
     *
     * @effects escrow_recipient_is_scrubbed_on_order_anonymize, escrow_delivery_record_is_preserved
     */
    #[Test]
    public function order_anonymize_scrubs_escrow_recipient_of_kginicis_payment_only(): void
    {
        $order = Order::factory()->confirmed()->create();
        $kginicis = $this->escrowPayment($order, 'kginicis');
        $other = $this->escrowPayment($order, 'nhnkcp');

        app(OrderPrivacyRetentionService::class)->anonymize($order);

        $meta = $kginicis->fresh()->payment_meta['escrow_delivery'];
        $this->assertNull($meta['recv_name'], '수령인 이름을 지운다');
        $this->assertNull($meta['recv_addr'], '수령인 주소를 지운다');
        $this->assertSame('123456789012', $meta['invoice'], '송장번호는 배송 거래 기록이라 남는다');
        $this->assertSame('cjgls', $meta['ex_code']);
        $this->assertSame('INIpayTest', $kginicis->fresh()->payment_meta['mid'], '에스크로 밖 메타는 그대로');
        $this->assertSame('2020-01-01 10:00:00', (string) DB::table('ecommerce_order_payments')->where('id', $kginicis->id)->value('updated_at'), '파기는 결제 변경이 아니다 — 수정 시각 보존');

        $this->assertSame('김철수', $other->fresh()->payment_meta['escrow_delivery']['recv_name'], '다른 결제 대행사의 행은 그 플러그인의 몫');
    }

    /**
     * 에스크로 배송 등록이 없는 결제 행은 그대로 둔다(빈 `escrow_delivery` 를 만들지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario escrow_state=not_registered, pg_provider=kginicis
     *
     * @effects payment_without_escrow_is_untouched
     */
    #[Test]
    public function payment_without_escrow_delivery_is_left_untouched(): void
    {
        $order = Order::factory()->confirmed()->create();
        $payment = OrderPayment::factory()->create([
            'order_id' => $order->id,
            'pg_provider' => 'kginicis',
            'payment_meta' => ['mid' => 'INIpayTest'],
        ]);

        app(OrderPrivacyRetentionService::class)->anonymize($order);

        $this->assertSame(['mid' => 'INIpayTest'], $payment->fresh()->payment_meta);
    }
}
