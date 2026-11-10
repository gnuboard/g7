<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * NHN KCP 관리자 결제조회·에스크로 화면 시각의 시간대 테스트 (#160)
 *
 * DB 는 UTC 로 저장한다. 결제조회 패널의 취소·환불 시각과 에스크로 배송등록 시각은 보는 사람 시간대 문자열로 낸다.
 */
class KcpDisplayTimezoneTest extends PluginTestCase
{
    /**
     * 취소·환불 시각(DB UTC 원문)은 보는 사람 시간대로 바꿔 낸다 — UTC 16:00 = 서울 다음 날 01:00.
     *
     * @scenario surface=transaction_cancel_refund
     *
     * @effects kcp_admin_times_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_cancelled_and_refunded_times_use_viewer_timezone(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KCP-TZ-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::CANCELLED,
            'total_amount' => 1100,
            'total_due_amount' => 0,
            'total_paid_amount' => 0,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::CANCELLED,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => '26438048818999',
            'cancelled_amount' => 1100,
            'cancelled_at' => '2026-11-03 16:00:00',
            'payment_meta' => ['res_cd' => '0000'],
        ]);
        DB::table('ecommerce_order_refunds')->insert([
            'order_id' => $order->id,
            'refund_number' => 'RF-KCP-TZ-'.random_int(10000, 99999),
            'refund_status' => RefundStatusEnum::COMPLETED->value,
            'refund_method' => 'pg',
            'refund_amount' => 1100,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'refunded_at' => '2026-11-03 17:30:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = $this->actingAs($admin)->withHeader('X-Timezone', 'Asia/Seoul')
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{$order->order_number}/transaction-status")
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-11-04 01:00:00', $data['cancelled_at']);
        $this->assertSame('2026-11-04 02:30:00', $data['refunded_at']);
    }

    /**
     * 에스크로 배송등록 시각은 UTC 로 저장하고, 화면 응답에서 보는 사람 시간대로 바꾼다.
     *
     * @scenario surface=escrow_registered_at
     *
     * @effects kcp_admin_times_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_escrow_registered_at_uses_viewer_timezone(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KCP-ESCROW-TZ-'.random_int(10000, 99999),
        ]);
        $payment = OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => 'KCP_ESCROW_TZ_TNO',
            'is_escrow' => true,
            'payment_meta' => ['escrow_delivery' => ['registered_at' => '2026-11-03 16:00:00', 'deli_numb' => 'INV-TZ']],
        ]);

        $data = $this->actingAs($admin)->withHeader('X-Timezone', 'Asia/Seoul')
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{$order->order_number}/escrow-delivery")
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-11-04 01:00:00', $data['registered_delivery']['registered_at']);
        $this->assertSame('2026-11-03 16:00:00', $payment->fresh()->payment_meta['escrow_delivery']['registered_at']);
    }
}
