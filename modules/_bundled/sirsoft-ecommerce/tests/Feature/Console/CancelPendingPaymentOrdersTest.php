<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Console;

use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 입금 기한 만료 자동취소 커맨드 테스트 (#599 후속 P17)
 *
 * 커맨드가 사유 **코드** 자리에 번역 **문장**을 넣고 있었다. 영문 로케일의 문장은 59자라
 * `order_cancels.cancel_reason_type`(varchar(50))을 넘겨 DB 오류가 났고, **만료 주문
 * 자동취소가 전량 실패**했다 — 스케줄러 로그에만 남으므로 운영자는 미결제 주문이 쌓이는
 * 것만 봤다.
 *
 * 결제수단 표기 버그도 함께 잠근다 — `payment_method` 는 순수 string 인데 enum 으로 보고
 * `?->value` 로 읽어, 수단이 전건 `unknown` 이고 입금 기한 대신 주문 시각이 찍혔다.
 */
class CancelPendingPaymentOrdersTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();

        app(EcommerceSettingsService::class)->setSetting('order_settings.cancellable_statuses', [
            OrderStatusEnum::PENDING_ORDER->value,
            OrderStatusEnum::PENDING_PAYMENT->value,
            OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 입금 기한이 지난 무통장 미결제 주문을 만듭니다.
     *
     * @return Order 주문
     */
    protected function expiredDbankOrder(): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(29000, 1, $policy);
        $order = $this->attachPolicyShippings($order, $policy);

        $order->forceFill([
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'ordered_at' => Carbon::now()->subDays(10),
        ])->save();
        $order->options->first()->forceFill(['option_status' => OrderStatusEnum::PENDING_PAYMENT])->save();
        $order->payment?->forceFill([
            'payment_method' => 'dbank',
            'pg_provider' => '',
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'deposit_due_at' => Carbon::now()->subDays(5),
        ])->save();

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 영문 로케일에서도 만료 자동취소가 통과하고 사유 **코드**가 저장된다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=none
     *
     * @effects auto_cancel_expired_stores_a_reason_code_not_a_sentence
     */
    public function test_expired_orders_are_cancelled_with_a_reason_code_in_the_english_locale(): void
    {
        app()->setLocale('en');
        $order = $this->expiredDbankOrder();

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')->assertExitCode(0);

        $this->assertSame(
            OrderStatusEnum::CANCELLED,
            $order->fresh()->order_status,
            '영문 로케일에서도 만료 주문이 취소되어야 한다 (P17)',
        );

        $cancel = OrderCancel::where('order_id', $order->id)->first();
        $this->assertNotNull($cancel);
        $this->assertSame('auto_cancel_expired', $cancel->cancel_reason_type);
        // 사유 자리에 문장을 넣으면 varchar(50) 을 넘긴다 — 길이로도 못박는다.
        $this->assertLessThanOrEqual(50, mb_strlen((string) $cancel->cancel_reason_type));
    }

    /**
     * 한국어 로케일에서도 같은 코드가 저장된다 — 저장값은 로케일에 좌우되지 않는다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=none
     *
     * @effects auto_cancel_expired_reason_code_is_locale_independent
     */
    public function test_the_stored_reason_code_does_not_depend_on_the_locale(): void
    {
        app()->setLocale('ko');
        $order = $this->expiredDbankOrder();

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')->assertExitCode(0);

        $cancel = OrderCancel::where('order_id', $order->id)->first();
        $this->assertNotNull($cancel);
        $this->assertSame('auto_cancel_expired', $cancel->cancel_reason_type);
    }

    /**
     * 결제수단 표기가 `unknown` 으로 무너지지 않는다.
     *
     * `payment_method` 는 순수 string 이다(#475 — 확장 결제수단 ID 허용). enum 으로 보고
     * `?->value` 로 읽으면 경고와 함께 언제나 null 이 되어, 수단 표기가 전건 `unknown` 이고
     * 아래 match 가 한 번도 맞지 않아 입금 기한 대신 주문 시각이 찍힌다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=none
     *
     * @effects auto_cancel_expired_prints_the_real_payment_method
     */
    public function test_the_dry_run_prints_the_real_payment_method(): void
    {
        $this->expiredDbankOrder();

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders --dry-run')
            ->expectsOutputToContain('dbank')
            ->assertExitCode(0);
    }
}
