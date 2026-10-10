<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 환불 현금분 마일리지 지급(refund_credit) 서비스 테스트 (D1·Q2)
 *
 * 「마일리지로 환불」은 현금 환불액을 회원 원장에 무기한 lot 으로 지급한다. 같은 환불의 쓴 마일리지
 * 반환(refund_restore)과 멱등 축이 섞이지 않는지, 소멸·연장·편집이 그 lot 에 기간을 붙이지 않는지,
 * FIFO 차감이 가장 나중에 쓰는지를 원장 행으로 단언한다.
 */
class UserMileageRefundCreditTest extends ModuleTestCase
{
    private UserMileageService $service;

    /**
     * 마일리지 설정(소멸 365일)을 쓰고 서비스를 꺼냅니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        file_put_contents($path.'/mileage.json', json_encode([
            'enabled' => true,
            'default_earn_rate' => 1,
            'earn_trigger' => 'confirmed',
            'earn_delay_days' => 0,
            'currency_rules' => [
                ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
            ],
            'expiry_enabled' => true,
            'expiry_days' => 365,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->service = app(UserMileageService::class);
    }

    /**
     * 테스트가 쓴 마일리지 설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $file = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/mileage.json');
        if (file_exists($file)) {
            unlink($file);
        }

        parent::tearDown();
    }

    /**
     * 만료일이 있는 적립 lot 을 직접 만듭니다.
     *
     * @param  int  $userId  회원 ID
     * @param  float  $amount  금액
     * @param  mixed  $expiresAt  만료일
     * @return MileageTransaction 적립 lot
     */
    private function seedLot(int $userId, float $amount, $expiresAt = null): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $amount,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 환불신청서 한 건을 만듭니다. (`order_refund_id` 는 FK 라 실제 행이 필요하다)
     *
     * @param  Order  $order  주문
     * @return OrderRefund 환불신청서
     */
    private function makeRefund(Order $order): OrderRefund
    {
        return OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_source_id' => null,
            'refund_number' => 'RF-'.uniqid(),
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::POINTS,
            'refund_amount' => 5000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);
    }

    /**
     * 회원과 그 회원의 주문을 만듭니다.
     *
     * @return array{0: User, 1: Order} 회원과 주문
     */
    private function memberOrder(): array
    {
        $user = User::factory()->create();

        return [$user, Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW'])];
    }

    /**
     * 환불 지급은 무기한 lot 을 한 번만 발행한다 — 같은 환불로 다시 부르면 null, 잔액 캐시도 함께 맞는다.
     *
     * @effects points_refund_credits_cash_share_as_non_expiring_mileage
     *
     * @return void 반환값 없음
     */
    public function test_credit_for_refund_issues_a_non_expiring_lot_once(): void
    {
        [$user, $order] = $this->memberOrder();
        $refund = $this->makeRefund($order);
        $this->seedLot($user->id, 1000, now()->addDays(30));

        $tx = $this->service->creditForRefund($user->id, $order->id, $refund->id, 5000, 'KRW');

        $this->assertNotNull($tx);
        $this->assertSame(MileageTransactionTypeEnum::REFUND_CREDIT, $tx->type);
        $this->assertNull($tx->expires_at);
        $this->assertSame(5000.0, (float) $tx->remaining_amount);
        $this->assertSame(6000.0, (float) $tx->balance_after);
        $this->assertNull($this->service->creditForRefund($user->id, $order->id, $refund->id, 5000, 'KRW'));
        $this->assertSame(6000.0, (float) MileageBalance::where('user_id', $user->id)->where('currency', 'KRW')->value('available'));
    }

    /**
     * 같은 환불의 사용 마일리지 반환과 현금분 지급은 서로의 멱등을 막지 않는다 — 타입으로 축을 가른다.
     *
     * @effects refund_restore_and_credit_do_not_block_each_other
     *
     * @return void 반환값 없음
     */
    public function test_credit_and_restore_for_the_same_refund_do_not_block_each_other(): void
    {
        [$user, $order] = $this->memberOrder();

        $first = $this->makeRefund($order);
        $this->assertNotNull($this->service->restoreForRefund($user->id, $order->id, $first->id, 3000, 'KRW'));
        $this->assertNotNull($this->service->creditForRefund($user->id, $order->id, $first->id, 7000, 'KRW'));

        $second = $this->makeRefund($order);
        $this->assertNotNull($this->service->creditForRefund($user->id, $order->id, $second->id, 7000, 'KRW'));
        $this->assertNotNull($this->service->restoreForRefund($user->id, $order->id, $second->id, 3000, 'KRW'));

        $this->assertSame(2, MileageTransaction::where('order_refund_id', $first->id)->count());
        $this->assertSame(2, MileageTransaction::where('order_refund_id', $second->id)->count());
    }

    /**
     * 현금 대체 지급분은 소멸 배치 대상이 아니다(Q2).
     *
     * @effects refund_credit_lot_is_never_expired
     *
     * @return void 반환값 없음
     */
    public function test_refund_credit_lot_is_never_expired_by_the_batch(): void
    {
        [$user, $order] = $this->memberOrder();
        $credit = $this->service->creditForRefund($user->id, $order->id, $this->makeRefund($order)->id, 5000, 'KRW');

        $this->service->expireLots(now()->addYears(10));

        $credit->refresh();
        $this->assertNull($credit->expired_at);
        $this->assertSame(5000.0, (float) $credit->remaining_amount);
    }

    /**
     * FIFO 차감은 만료가 있는 lot 부터 쓰고 현금 대체 지급분은 가장 나중에 쓴다.
     *
     * @effects refund_credit_is_consumed_after_expiring_lots
     *
     * @return void 반환값 없음
     */
    public function test_refund_credit_is_consumed_after_expiring_lots(): void
    {
        [$user, $order] = $this->memberOrder();
        $expiring = $this->seedLot($user->id, 1000, now()->addDays(10));
        $credit = $this->service->creditForRefund($user->id, $order->id, $this->makeRefund($order)->id, 1000, 'KRW');

        $this->service->deductFifo($user->id, 1000, 'KRW', $order);

        $this->assertSame(0.0, (float) $expiring->fresh()->remaining_amount);
        $this->assertSame(1000.0, (float) $credit->fresh()->remaining_amount);
    }

    /**
     * 현금 대체 지급분에는 유효기간을 붙일 수 없다 — 일괄 연장도, 관리자 편집도 막는다(Q2).
     *
     * @effects refund_credit_expiry_cannot_be_set
     *
     * @return void 반환값 없음
     */
    public function test_refund_credit_expiry_cannot_be_set(): void
    {
        [$user, $order] = $this->memberOrder();
        $credit = $this->service->creditForRefund($user->id, $order->id, $this->makeRefund($order)->id, 5000, 'KRW');

        $this->service->extendLotExpiry(null, [$credit->id], 30);
        $this->assertNull($credit->fresh()->expires_at);

        $this->expectException(MileageValidationException::class);
        $this->service->updateAdminTransaction($credit->id, null, now()->addDay(), false, true);
    }
}
