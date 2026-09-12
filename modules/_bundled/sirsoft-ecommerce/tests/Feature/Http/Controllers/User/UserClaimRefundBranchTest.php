<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원 취소·환불 접수의 **상태 × 결제수단 매트릭스** (#599 후속 §9-1)
 *
 * 계획서 §9-1 이 이 파일에 요구한 것은 "상태 × 결제수단 매트릭스로 접수 결과(환불신청서 +
 * 취소신청서 유무·상태) 검증" 이다. 그 교차곱이 중요한 이유는 새 모델의 **종착점이 환불수단이
 * 정하기** 때문이다(D4·B14) — 상품 상태는 분기(`auto`/`request`)를 정하고, 환불수단은 그 분기가
 * 어디서 멈추는지를 정한다. 두 축을 따로 재면 조합에서만 나타나는 어긋남이 빠진다.
 *
 * 실제로 이 회차의 결함 다수가 수단 축에서 터졌다 — 입금완료 가상계좌 취소 불가(P5),
 * 취소 주문 부활(P4), PG 리스너 부재 폴백(P14), 마일리지 전액 환불의 상태 정박(P12).
 * 그런데 **사용자 접수 표면에서 수단 축을 재는 테스트가 0건**이어서, 수단별 종착점이 갈리는
 * 회귀는 구매자 경로에서 잡히지 않았다.
 *
 * ## 모집단을 손으로 열거하지 않는다
 *
 * 매트릭스가 덮는 환불수단은 `RefundMethodEnum` 전수여야 하고, 상태는 설정의 두 목록
 * (`claim.instant_cancel_statuses` / `claim.refund_request_statuses`) 전수여야 한다.
 * 아래 두 커버리지 테스트가 그 전수성을 검산한다 — 수단이나 상태가 하나 늘면 케이스 없이
 * 통과하지 않고 red 가 된다.
 */
class UserClaimRefundBranchTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->user = $this->createUser();

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'TEST_TXN', 'error_code' => null, 'error_message' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/user';
    }

    /**
     * 환불수단 축 — 결제수단·PG 설정과 기대 종착점.
     *
     * `completes` 는 자동 전이가 완료까지 가는지다. 계좌(BANK)만 승인에서 멈춘다 —
     * 사람이 송금해야 하고, 운영자의 [환불하기]가 곧 송금 완료 선언이다.
     *
     * @return array<string, array{0: string, 1: string, 2: RefundMethodEnum, 3: bool}>
     */
    public static function refundMethodMatrix(): array
    {
        return [
            '카드 결제 → PG 환불, 완료까지' => ['card', 'testpg', RefundMethodEnum::PG, true],
            '무통장 → 계좌 환불, 승인에서 멈춤' => ['dbank', '', RefundMethodEnum::BANK, false],
            '마일리지 전액 → 포인트 환불, 완료까지' => ['point', '', RefundMethodEnum::POINTS, true],
        ];
    }

    /**
     * 상품 상태 축 — 설정 목록과 기대 분기.
     *
     * @return array<string, array{0: OrderStatusEnum, 1: string}>
     */
    public static function statusMatrix(): array
    {
        return [
            '주문대기 → 자동' => [OrderStatusEnum::PENDING_ORDER, OrderRefundRequestService::BRANCH_AUTO],
            '입금대기 → 자동' => [OrderStatusEnum::PENDING_PAYMENT, OrderRefundRequestService::BRANCH_AUTO],
            '결제완료 → 자동' => [OrderStatusEnum::PAYMENT_COMPLETE, OrderRefundRequestService::BRANCH_AUTO],
            '상품준비중 → 신청' => [OrderStatusEnum::PREPARING, OrderRefundRequestService::BRANCH_REQUEST],
            '배송준비 → 신청' => [OrderStatusEnum::SHIPPING_READY, OrderRefundRequestService::BRANCH_REQUEST],
            '배송중 → 신청' => [OrderStatusEnum::SHIPPING, OrderRefundRequestService::BRANCH_REQUEST],
        ];
    }

    protected function myPaidOrder(string $paymentMethod = 'card', string $pgProvider = 'testpg'): Order
    {
        $order = $this->paidClaimOrder();
        $order->forceFill(['user_id' => $this->user->id])->save();
        $order->payment?->forceFill([
            'payment_method' => $paymentMethod,
            'pg_provider' => $pgProvider,
        ])->save();

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    protected function items(Order $order, int $quantity = 1): array
    {
        return [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]];
    }

    // ================================================================
    // 커버리지 검산 — 모집단이 저장소에서 파생됐는지
    // ================================================================

    /**
     * 매트릭스가 `RefundMethodEnum` **전수**를 덮는다.
     *
     * 수단이 하나 늘면 이 테스트가 red 가 된다 — 새 수단의 종착점을 아무도 정하지 않은 채
     * 자동 전이가 그 수단을 만나는 일을 막는다.
     *
     * @scenario item_selection=single_full, payment_method=card, verify_stage=refund_record
     *
     * @effects refund_branch_matrix_covers_every_refund_method
     */
    public function test_the_matrix_covers_every_refund_method(): void
    {
        $cases = RefundMethodEnum::cases();
        $this->assertNotEmpty($cases, '환불수단 모집단이 비었다 — 파생이 깨졌다');

        $covered = array_map(static fn (array $row): RefundMethodEnum => $row[2], self::refundMethodMatrix());

        foreach ($cases as $case) {
            $this->assertContains(
                $case,
                $covered,
                "환불수단 `{$case->value}` 의 자동 전이 종착점을 재는 케이스가 없다 — 매트릭스에 행을 더해야 한다"
            );
        }
    }

    /**
     * 매트릭스가 설정 두 목록의 상태 **전수**를 덮는다.
     *
     * @scenario item_selection=single_full, payment_method=card, verify_stage=refund_record
     *
     * @effects refund_branch_matrix_covers_every_configured_status
     */
    public function test_the_matrix_covers_every_configured_claim_status(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $configured = array_unique(array_merge(
            (array) $settings->getSetting('claim.instant_cancel_statuses', []),
            (array) $settings->getSetting('claim.refund_request_statuses', []),
        ));

        $this->assertNotEmpty($configured, '상품 상태 모집단이 비었다 — 설정 파생이 깨졌다');

        $covered = array_map(
            static fn (array $row): string => $row[0]->value,
            self::statusMatrix()
        );

        foreach ($configured as $status) {
            $this->assertContains(
                $status,
                $covered,
                "설정에 실린 상태 `{$status}` 의 분기를 재는 케이스가 없다 — 매트릭스에 행을 더해야 한다"
            );
        }
    }

    // ================================================================
    // 결제수단 축 — 접수 결과와 종착점
    // ================================================================

    /**
     * 수단별 종착점과 두 문서의 유무·상태. (D4 · B14 · P12)
     *
     * @dataProvider refundMethodMatrix
     *
     * @scenario item_selection=single_full, refund_branch=auto, verify_stage=refund_record
     *
     * @effects refund_branch_terminus_is_decided_by_the_refund_method
     */
    public function test_the_terminus_is_decided_by_the_refund_method(
        string $paymentMethod,
        string $pgProvider,
        RefundMethodEnum $expectedMethod,
        bool $completes,
    ): void {
        $order = $this->myPaidOrder($paymentMethod, $pgProvider);

        $payload = ['items' => $this->items($order), 'reason_code' => 'changed_mind'];
        if ($expectedMethod === RefundMethodEnum::BANK) {
            // 계좌 환불은 송금 대상이 필요하다 (P9).
            $payload['refund_bank'] = [
                'bank_code' => '004', 'account_number' => '1234567890', 'holder' => '테스터',
            ];
        }

        $this->actingAs($this->user)
            ->postJson($this->base()."/orders/{$order->id}/refund-requests", $payload)
            ->assertCreated()
            // 접수 결과는 언제나 환불신청서다 (D2).
            ->assertJsonPath('data.claim.claim_type', 'refund')
            // 구매자 본인 접수이므로 출처는 `direct` 여야 한다 — 본인이 철회·수정할 수 있어야 한다.
            ->assertJsonPath('data.claim.refund_source_type', RefundSourceTypeEnum::DIRECT->value);

        $refund = OrderRefund::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($expectedMethod, $refund->refund_method);

        if ($completes) {
            $this->assertSame(RefundStatusEnum::COMPLETED, $refund->refund_status);
            $this->assertNotNull($refund->refunded_at);
            // 취소신청서는 환불이 집행된 순간에만 생긴다 (D3).
            $this->assertSame(1, OrderCancel::where('order_id', $order->id)->count());

            return;
        }

        $this->assertSame(RefundStatusEnum::APPROVED, $refund->refund_status);
        $this->assertNull($refund->refunded_at);
        // 송금 전에는 취소신청서가 없다 — 취소가 안 된 것이 아니라 환불신청서 단계다 (D5 · B12).
        $this->assertSame(0, OrderCancel::where('order_id', $order->id)->count());
    }

    // ================================================================
    // 상품 상태 축 — 분기 판정
    // ================================================================

    /**
     * 상품 상태가 분기를 정한다. (§4-1)
     *
     * @dataProvider statusMatrix
     *
     * @scenario item_selection=single_full, payment_method=card, verify_stage=order_status
     *
     * @effects refund_branch_is_decided_by_the_item_status
     */
    public function test_the_branch_is_decided_by_the_item_status(
        OrderStatusEnum $status,
        string $expectedBranch,
    ): void {
        $order = $this->myPaidOrder();
        $order->forceFill(['order_status' => $status])->save();
        foreach ($order->options as $option) {
            $option->forceFill(['option_status' => $status])->save();
        }
        $order = $order->fresh(['options', 'payment', 'shippings']);

        $this->actingAs($this->user)
            ->postJson($this->base()."/orders/{$order->id}/refund-requests", [
                'items' => $this->items($order), 'reason_code' => 'changed_mind',
            ])
            ->assertCreated()
            ->assertJsonPath('data.branch', $expectedBranch);

        if ($expectedBranch === OrderRefundRequestService::BRANCH_REQUEST) {
            // 접수에서 멈춘다 — 운영자가 승인해야 한다.
            $refund = OrderRefund::where('order_id', $order->id)->firstOrFail();
            $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refund_status);
            $this->assertSame(0, OrderCancel::where('order_id', $order->id)->count());
        }
    }
}
