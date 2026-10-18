<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 회원 취소·환불 접수의 **상태 × 결제수단 매트릭스** (#599 후속 §9-1)
 *
 * 계획서 §9-1 이 이 파일에 요구한 것은 "상태 × 결제수단 매트릭스로 접수 결과(환불신청서 +
 * 취소신청서 유무·상태) 검증" 이다. 그 교차곱이 중요한 이유는 새 모델의 **종착점을 결제수단이
 * 정하기** 때문이다(P16 · B14) — 상품 상태는 분기(`auto`/`request`)를 정하고, 결제수단이 환불을
 * 사람 손 없이 집행할 수 있는지가 그 분기의 정지 지점을 정한다. 두 축을 따로 재면 조합에서만
 * 나타나는 어긋남이 빠진다.
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

    /**
     * 통화 설정·클레임 사유를 심고 PG 환불 훅을 성공으로 고정합니다.
     *
     * 이 클래스가 재는 것은 **어느 종착점에서 멈추는가**이지 PG 가 성공하는가가 아니다 —
     * 훅을 고정하지 않으면 PG 미설정 환경에서 전 축이 같은 이유로 실패해 축이 무의미해진다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 테스트가 심은 통화 설정을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 회원 클레임 API 의 접두 경로를 돌려줍니다.
     *
     * @return string API 접두 경로
     */
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
     * 다섯째 값은 **마일리지로 전액 결제한(현금 0원) 주문**인지다(결함 4). 고객은 마일리지 결제수단을
     * 고를 수 없어 그런 주문도 카드·무통장·가상계좌·무료 수단을 그대로 단다 — 돌려줄 현금이 없으므로
     * 결제수단과 무관하게 「마일리지로 환불」로 기록되고, 계좌를 묻지 않고, 완료까지 간다.
     *
     * @return array<string, array{0: string, 1: string, 2: RefundMethodEnum, 3: RefundStatusEnum, 4: bool}> 결제수단·PG·기대 환불수단·기대 환불상태·현금 0원 여부의 조합
     */
    public static function refundMethodMatrix(): array
    {
        return [
            // P16 — 무통장은 사람이 송금해야 끝나므로 **접수에서** 멈춘다. 종전(前D4)에는
            // `approved` 까지 자동으로 갔으나, 아무도 검토하지 않은 건이 "승인됨" 으로 보이는
            // 상태를 없앴다. 승인이 사람의 검토, 실행이 사람의 송금이다.
            '무통장 → 계좌 환불, 접수에서 멈춤' => ['dbank', '', RefundMethodEnum::BANK, RefundStatusEnum::REQUESTED, false],
            '카드 결제 → PG 환불, 완료까지' => ['card', 'testpg', RefundMethodEnum::PG, RefundStatusEnum::COMPLETED, false],
            '마일리지 전액(현금 0원) 무통장 → 마일리지 환불, 완료까지' => ['dbank', '', RefundMethodEnum::POINTS, RefundStatusEnum::COMPLETED, true],
            '마일리지 전액(현금 0원) 가상계좌 → 마일리지 환불, 완료까지' => ['vbank', 'testpg', RefundMethodEnum::POINTS, RefundStatusEnum::COMPLETED, true],
            '마일리지 전액(현금 0원) 무료 → 마일리지 환불, 완료까지' => ['free', '', RefundMethodEnum::POINTS, RefundStatusEnum::COMPLETED, true],
            '마일리지 전액(현금 0원) 카드 → 마일리지 환불, 완료까지' => ['card', 'testpg', RefundMethodEnum::POINTS, RefundStatusEnum::COMPLETED, true],
        ];
    }

    /**
     * 상품 상태 축 — 설정 목록과 기대 분기.
     *
     * @return array<string, array{0: OrderStatusEnum, 1: string}> 주문옵션 상태와 그 상태가 만드는 기대 분기
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

    /**
     * 이 테스트 회원이 결제를 마친 주문을 만들고 결제수단·PG 를 갈아 끼웁니다.
     *
     * 종착점을 정하는 것이 결제수단이므로, 축마다 같은 주문에 수단만 바꿔 끼운다.
     *
     * @param  string  $paymentMethod  결제수단 코드 (card·dbank·point 등)
     * @param  string  $pgProvider  PG 식별자 (PG 를 타지 않는 수단은 빈 문자열)
     * @return Order 결제완료 주문 (options·payment·shippings 로드됨)
     */
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

    /**
     * 주문의 첫 옵션 하나를 신청 항목 배열로 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @param  int  $quantity  신청 수량
     * @return array<int, array{order_option_id: int, quantity: int}> 신청 항목 배열
     */
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * 계좌로 환불되는 주문은 **계좌 없이는 접수되지 않는다.**
     *
     * 화면은 「은행·계좌번호·예금주를 모두 입력해 주세요」라고 적어 두고도 [환불 신청하기] 를
     * 막지 않았고, 서버도 가상계좌에만 계좌를 요구해 **무통장 주문이 계좌 3칸 공란으로 접수**됐다.
     * 그렇게 접수된 건은 운영자가 [환불하기] 를 누르면 송금 없이 장부만 `completed` 로 닫히고,
     * 계좌를 채울 자리가 관리자 화면에 없어 되돌릴 수도 없었다
     * (2026-09-22 #599 3차 실측 L-8 · T2-D5·D6).
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, refund_branch=auto, verify_stage=refund_record
     *
     * @effects refund_request_requires_a_bank_account_when_money_returns_by_transfer
     */
    public function test_bank_refund_is_rejected_without_an_account(): void
    {
        $order = $this->myPaidOrder('dbank', '');

        $this->actingAs($this->user)
            ->postJson($this->base()."/orders/{$order->id}/refund-requests", [
                'items' => $this->items($order),
                'reason_code' => 'changed_mind',
            ])
            ->assertStatus(422);

        $this->assertSame(
            0,
            OrderRefund::where('order_id', $order->id)->count(),
            '계좌 없이 환불신청서가 만들어졌다 — 돈이 갈 곳 없이 장부만 닫히는 길이 열린다',
        );
    }

    /**
     * 수단별 종착점과 두 문서의 유무·상태. (P16 · B14 · P12)
     *
     * @param  string  $paymentMethod  결제수단 코드
     * @param  string  $pgProvider  PG 식별자
     * @param  RefundMethodEnum  $expectedMethod  기대 환불수단
     * @param  RefundStatusEnum  $expectedStatus  기대 환불상태(=종착점)
     * @param  bool  $mileageOnly  마일리지로 전액 결제한(현금 0원) 주문인지
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, refund_branch=auto, verify_stage=refund_record
     *
     * @effects refund_branch_terminus_is_decided_by_the_refund_method
     */
    #[DataProvider('refundMethodMatrix')]
    public function test_the_terminus_is_decided_by_the_refund_method(
        string $paymentMethod,
        string $pgProvider,
        RefundMethodEnum $expectedMethod,
        RefundStatusEnum $expectedStatus,
        bool $mileageOnly,
    ): void {
        $order = $mileageOnly
            ? $this->mileageOnlyPaidOrder($this->user, $paymentMethod, $pgProvider)
            : $this->myPaidOrder($paymentMethod, $pgProvider);

        // 현금 0원 주문은 계좌를 싣지 않는다 — 돌려줄 현금이 없는데 계좌를 요구하면 고객이 취소하지 못한다(결함 4).
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

        $this->assertSame($expectedStatus, $refund->refund_status);

        if ($expectedStatus === RefundStatusEnum::COMPLETED) {
            $this->assertNotNull($refund->refunded_at);
            // 취소신청서는 환불이 집행된 순간에만 생긴다 (D3).
            $this->assertSame(1, OrderCancel::where('order_id', $order->id)->count());

            if ($mileageOnly) {
                // 돈의 도착 — 주문 때 쓴 마일리지가 그 환불에 묶여 원장으로 돌아왔다.
                $used = (float) $order->total_points_used_amount;
                $restored = MileageTransaction::query()
                    ->where('order_refund_id', $refund->id)
                    ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)
                    ->get();
                $this->assertCount(1, $restored);
                $this->assertSame($used, (float) $restored->first()->amount);
                $this->assertSame($used, app(MileageTransactionRepositoryInterface::class)->getBalanceByCurrency($this->user->id, 'KRW'));
                $this->assertSame(0.0, (float) $refund->refund_amount);
            }

            return;
        }

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
     * @param  OrderStatusEnum  $status  주문·주문옵션에 심을 상태
     * @param  string  $expectedBranch  그 상태가 만드는 기대 분기
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_method=card, verify_stage=order_status
     *
     * @effects refund_branch_is_decided_by_the_item_status
     */
    #[DataProvider('statusMatrix')]
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

    /**
     * 현금 0원 주문을 상품준비중에 환불 신청하면 계좌 없이 접수되고 운영자 심사에서 멈춘다 —
     * 심사 단계는 카드 주문과 같은 정책이다. 이어서 관리자가 계좌 없이 [환불하기]를 누르면
     * 마일리지가 돌아오고 완료된다(결함 4).
     *
     * @return void 반환값 없음
     *
     * @effects mileage_only_order_in_request_branch_needs_no_account_and_waits_for_review
     */
    public function test_mileage_only_order_in_request_branch_needs_no_account_and_waits_for_review(): void
    {
        $order = $this->mileageOnlyPaidOrder($this->user, 'dbank', '', OrderStatusEnum::PREPARING);

        $this->actingAs($this->user)
            ->postJson($this->base()."/orders/{$order->id}/refund-requests", [
                'items' => $this->items($order),
                'reason_code' => 'changed_mind',
            ])
            ->assertCreated();

        $refund = OrderRefund::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refund_status);
        $this->assertSame(RefundMethodEnum::POINTS, $refund->refund_method);

        $admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
            'sirsoft-ecommerce.claims.execute-refund',
        ]);
        app(OrderRefundRequestService::class)->transition($refund, RefundStatusEnum::APPROVED);

        $this->actingAs($admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/refunds/{$refund->id}/execute")
            ->assertOk();

        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
        $this->assertSame(1, MileageTransaction::query()
            ->where('order_refund_id', $refund->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)
            ->count());
    }
}
