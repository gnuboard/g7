<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 나이스페이먼츠 관리자 가상계좌 환불 엔드포인트 테스트
 *
 * POST /api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund 가 PG 취소 뒤 주문 도메인을 갱신하고,
 * 금액 불일치·권한 부족·형식 위반 요청은 PG 취소 전에 차단하는지 검증한다.
 * 시나리오 축·효과는 매니페스트 tests/scenarios/admin-vbank-refund.yaml 참조.
 */
class AdminVbankRefundControllerTest extends PluginTestCase
{
    private const TID = 'NICE_VBANK_TID_001';

    /**
     * 취소·환불 번호 채번 규칙과 클레임 사유를 준비합니다.
     *
     * 한 프로세스에서 여러 확장 스위트를 함께 돌리면 먼저 실행된 TestCase 의 씨드만 남는다 — 이커머스
     * 씨드 없이 스키마를 만든 스위트(예: 마케팅)가 앞서면 사유 테이블이 비어, 운영자 취소 사유
     * (`admin_cancel`)를 찾지 못한 환불이 502 로 끝난다. 채번 규칙처럼 이 테스트가 직접 채운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCancelSequences();

        if (! ClaimReason::query()->where('code', 'admin_cancel')->exists()) {
            $this->seed(ClaimReasonSeeder::class);
        }
    }

    /**
     * PG 취소가 성공하면 주문·결제를 취소 상태로 바꾸고 환불 기록을 남기는지 확인합니다.
     *
     * @scenario case=valid_refund
     *
     * @effects vbank_refund_cancels_order_and_records_refund_after_pg_cancel
     *
     * @return void 반환값 없음
     */
    public function test_admin_vbank_refund_updates_order_domain_after_pg_cancel(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.claims.execute-refund']);
        $order = $this->createPaidVbankOrder();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->once())
            ->method('useStoredCredentials')
            ->with(false, 'SRLIVE001');
        $apiServiceMock->expects($this->once())
            ->method('cancelPayment')
            ->with(
                self::TID,
                $order->order_number,
                30000,
                '가상계좌 환불',
                0,
                '1234567890',
                '004',
                '홍길동',
            )
            ->willReturn([
                'ResultCode' => '2001',
                'ResultMsg' => '취소 성공',
                'TID' => self::TID,
                'CancelAmt' => '30000',
            ]);

        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)->postJson('/api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund', [
            'tid' => self::TID,
            'moid' => $order->order_number,
            'cancel_amt' => 30000,
            'cancel_msg' => '가상계좌 환불',
            'refund_acct_no' => '1234567890',
            'refund_bank_cd' => '004',
            'refund_acct_nm' => '홍길동',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $payment = $order->payment->fresh();
        $refund = OrderRefund::query()->where('order_id', $order->id)->first();

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertEquals(30000.0, (float) $payment->cancelled_amount);
        $this->assertSame('completed', $payment->payment_meta['vbank_refund_status'] ?? null);
        $this->assertNotNull($refund);
        $this->assertEquals(30000.0, (float) $refund->refund_amount);
    }

    /**
     * 환불 금액이 결제 금액과 다르면 PG 취소 전에 422 로 거절하고 주문을 그대로 두는지 확인합니다.
     *
     * @scenario case=amount_mismatch
     *
     * @effects vbank_refund_amount_mismatch_returns_422_without_pg_cancel
     *
     * @return void 반환값 없음
     */
    public function test_admin_vbank_refund_rejects_amount_mismatch_before_pg_cancel(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.claims.execute-refund']);
        $order = $this->createPaidVbankOrder();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->never())->method('cancelPayment');
        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)->postJson('/api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund', [
            'tid' => self::TID,
            'moid' => $order->order_number,
            'cancel_amt' => 29999,
            'refund_acct_no' => '1234567890',
            'refund_bank_cd' => '004',
            'refund_acct_nm' => '홍길동',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(0.0, (float) $order->payment->cancelled_amount);
    }

    /**
     * 환불 실행 권한 없이 주문 수정 권한만 있으면 403 으로 거절하는지 확인합니다.
     *
     * @scenario case=missing_execute_refund_permission
     *
     * @effects vbank_refund_without_execute_refund_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_admin_vbank_refund_requires_execute_refund_permission(): void
    {
        // 가상계좌 환불은 PG 취소와 주문 취소까지 하므로 「환불 실행」 권한을 요구한다(제품 결정 R1, 7.1.0) — 주문 수정 권한만으로는 403
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.orders.update']);
        $order = $this->createPaidVbankOrder();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->never())->method('cancelPayment');
        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)->postJson('/api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund', [
            'tid' => self::TID,
            'moid' => $order->order_number,
            'cancel_amt' => 30000,
            'refund_acct_no' => '1234567890',
            'refund_bank_cd' => '004',
            'refund_acct_nm' => '홍길동',
        ]);

        $response->assertForbidden();
    }

    /**
     * 환불 계좌 형식 위반 4종(계좌번호 17자리 / 은행코드 비숫자 / 예금주 11자 / 취소사유 101자)은
     * VbankRefundRequest 가 표준 422 로 차단하고 PG 취소 API 는 호출되지 않아야 한다.
     *
     * @scenario case=malformed_account_fields
     *
     * @effects vbank_refund_malformed_account_fields_return_422_without_pg_cancel
     *
     * @return void 반환값 없음
     */
    public function test_admin_vbank_refund_rejects_malformed_refund_account_fields(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.claims.execute-refund']);
        $order = $this->createPaidVbankOrder();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->never())->method('useStoredCredentials');
        $apiServiceMock->expects($this->never())->method('cancelPayment');
        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)->postJson('/api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund', [
            'tid' => self::TID,
            'moid' => $order->order_number,
            'cancel_amt' => 30000,
            'cancel_msg' => str_repeat('가', 101),
            'refund_acct_no' => str_repeat('1', 17),
            'refund_bank_cd' => '00A',
            'refund_acct_nm' => str_repeat('가', 11),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'cancel_msg',
                'refund_acct_no',
                'refund_bank_cd',
                'refund_acct_nm',
            ]);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(0.0, (float) $order->payment->cancelled_amount);
    }

    /**
     * 배열 주입(`tid[]=x`)은 string 규칙이 422 로 차단해야 한다 — 종전 수동 검사는
     * 문자열 캐스팅 전제라 배열 입력이 형식 검증을 우회할 수 있었다.
     *
     * @scenario case=array_injection
     *
     * @effects vbank_refund_array_input_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_admin_vbank_refund_rejects_array_injection(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.claims.execute-refund']);
        $order = $this->createPaidVbankOrder();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->never())->method('cancelPayment');
        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)->postJson('/api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund', [
            'tid' => ['x'],
            'moid' => $order->order_number,
            'cancel_amt' => 30000,
            'refund_acct_no' => '1234567890',
            'refund_bank_cd' => '004',
            'refund_acct_nm' => '홍길동',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tid']);
    }

    /**
     * 30,000원을 가상계좌로 결제 완료한 주문을 만듭니다.
     *
     * @return Order 결제·옵션·배송 관계를 불러온 주문
     */
    private function createPaidVbankOrder(): Order
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 30000,
            'total_shipping_amount' => 0,
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now(),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 30000,
            'subtotal_price' => 30000,
            'subtotal_paid_amount' => 30000,
            'subtotal_discount_amount' => 0,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'product_snapshot' => [
                'id' => null,
                'name' => ['ko' => '테스트상품', 'en' => 'Test Product'],
                'product_code' => null,
                'sku' => null,
                'brand_id' => null,
                'list_price' => 30000,
                'selling_price' => 30000,
                'currency_code' => 'KRW',
                'stock_quantity' => 100,
                'tax_status' => 'taxable',
                'tax_rate' => 10,
                'has_options' => false,
                'option_groups' => null,
                'thumbnail_url' => null,
            ],
            'option_snapshot' => [
                'id' => null,
                'option_code' => null,
                'option_values' => null,
                'option_name' => '기본',
                'price_adjustment' => 0,
                'list_price' => 30000,
                'selling_price' => 30000,
                'currency_code' => 'KRW',
                'stock_quantity' => 100,
                'weight' => 0.5,
                'volume' => 0.01,
                'sku' => null,
                'is_default' => true,
            ],
        ]);

        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 30000,
            'paid_amount_base' => 30000,
            'cancelled_amount' => 0,
            'paid_at' => now(),
            'transaction_id' => self::TID,
            'vbank_number' => '0987654321',
            'vbank_name' => '국민은행',
            'payment_meta' => [
                'mid' => 'SRLIVE001',
                'is_test_mode' => false,
                'vbank_tid' => self::TID,
                'vbank_num' => '0987654321',
            ],
        ]);

        return $order->fresh(['payment', 'options', 'shippings']);
    }

    /**
     * 취소·환불 번호 채번 규칙이 없으면 기본 설정으로 만듭니다.
     *
     * @return void 반환값 없음
     */
    private function createCancelSequences(): void
    {
        foreach ([SequenceType::CANCEL, SequenceType::REFUND] as $type) {
            $cfg = $type->getDefaultConfig();
            Sequence::firstOrCreate(
                ['type' => $type->value],
                [
                    'algorithm' => $cfg['algorithm']->value,
                    'prefix' => $cfg['prefix'],
                    'current_value' => 0,
                    'increment' => 1,
                    'min_value' => 1,
                    'max_value' => $cfg['max_value'],
                    'cycle' => false,
                    'pad_length' => $cfg['pad_length'],
                ]
            );
        }
    }
}
