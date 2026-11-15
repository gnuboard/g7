<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptTransactionType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCashReceiptRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\RegistersTestCashReceiptProvider;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 현금영수증 발급 동시성 테스트 (KVE-2026-3191)
 *
 * 수정 전 발급은 「활성 영수증 조회(잠금 없음) → 외부 PG 발급 → 이력 INSERT」 순서였다. 같은 주문에 발급 요청이
 * 겹치면 두 요청 모두 「활성 없음」 을 보고 PG 에 각각 발급해 활성 영수증이 2건 생겼다.
 *
 * 수정 후에는 PG 호출 전에 처리 중(IN_PROGRESS) 발급 행을 먼저 INSERT 해 주문의 발급 슬롯을 예약하고, 그 슬롯은
 * DB 유니크(`active_issue_order_key`)가 지킨다.
 *
 * 경합 재현: 한 프로세스 테스트에서 두 요청을 진짜로 겹치게 할 수는 없으므로, **PG 필터 안에서 같은 주문의 발급을
 * 다시 부른다**(재진입). 그 순간은 바깥 요청이 PG 응답을 기다리는 창과 같다 — 수정 전 코드는 재진입 호출도
 * 「활성 없음」 을 보고 PG 를 한 번 더 불렀다(PG 2회·활성 2건).
 *
 * 트랜잭션 격리: ModuleTestCase(RefreshDatabase) 트랜잭션 안에서만 돈다. 유니크 위반은 세이브포인트로 되돌리므로
 * 바깥 테스트 트랜잭션이 깨지지 않는다. DDL 은 쓰지 않는다.
 *
 * 시나리오 매니페스트: `tests/scenarios/cash-receipt-concurrency.yaml`
 */
class CashReceiptIssueConcurrencyTest extends ModuleTestCase
{
    use RegistersTestCashReceiptProvider;

    private const PROVIDER = 'tosspayments';

    private const IDENTIFIER = '01012345678';

    /** @var array<int, array<string, mixed>> 발급 필터가 받은 페이로드 기록 (= PG 발급 호출) */
    private array $issueCalls = [];

    /** @var (callable(Order, array<string, mixed>): void)|null 발급 필터 안에서 1회 실행할 재진입 동작 */
    private $reentry = null;

    /** @var array<int, string|null> 응답할 영수증 키 순서 (비면 순번 키) */
    private array $receiptKeys = [];

    private int $receiptSequence = 0;

    /**
     * 테스트 프로바이더를 설정하고 기록을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->issueCalls = [];
        $this->reentry = null;
        $this->receiptKeys = [];
        $this->receiptSequence = 0;

        $this->registerCashReceiptProvider(self::PROVIDER);
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', self::PROVIDER);
        $this->registerPgDouble();
    }

    /**
     * PG 대역(발급 필터)을 등록합니다. 재진입 동작이 있으면 첫 호출에서 1회 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function registerPgDouble(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.issue',
            function (array $result, Order $order, string $provider, array $payload) {
                if ($provider !== self::PROVIDER) {
                    return $result;
                }

                $this->issueCalls[] = $payload;

                if ($this->reentry !== null) {
                    $reentry = $this->reentry;
                    $this->reentry = null;
                    $reentry($order, $payload);
                }

                $key = $this->receiptKeys !== []
                    ? array_shift($this->receiptKeys)
                    : 'receipt-'.(++$this->receiptSequence);

                return [
                    'success' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'receipt_key' => $key,
                    'receipt_url' => $key !== null ? "https://example.test/receipt/{$key}" : null,
                    'issue_number' => '12345678',
                    'raw_response' => null,
                ];
            },
        );
    }

    /**
     * 무통장 결제완료 주문을 만듭니다.
     *
     * @param  User|null  $owner  주문 회원 (null 이면 회원 미지정)
     * @return Order 주문
     */
    private function makeOrder(?User $owner = null): Order
    {
        $order = Order::factory()->create([
            'user_id' => $owner?->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 11000,
            'total_cash_equivalent_amount' => 11000,
            'total_tax_amount' => 11000,
            'total_tax_free_amount' => 0,
        ]);

        OrderPayment::factory()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
        ]);

        return $order->fresh();
    }

    /**
     * 비회원 무통장 결제완료 주문과 조회 토큰을 만듭니다.
     *
     * @return array{0: Order, 1: string} 주문과 조회 토큰
     */
    private function makeGuestOrderWithToken(): array
    {
        $phone = '010-1234-5678';
        $password = 'guest1234';

        $order = Order::factory()->forGuest()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'guest_lookup_password_hash' => Hash::make($password),
            'total_amount' => 11000,
            'total_cash_equivalent_amount' => 11000,
            'total_tax_amount' => 11000,
            'total_tax_free_amount' => 0,
        ]);

        OrderAddress::factory()->forOrder($order)->create([
            'address_type' => 'shipping',
            'orderer_phone' => $phone,
        ]);

        OrderPayment::factory()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
        ]);

        $token = $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $order->order_number,
            'orderer_phone' => $phone,
            'guest_lookup_password' => $password,
        ])->assertOk()->json('data.guest_order_token');

        return [$order->fresh(), $token];
    }

    /**
     * 처리 중(IN_PROGRESS) 발급 행을 직접 기록합니다. (다른 요청이 PG 응답을 기다리는 상태)
     *
     * @param  Order  $order  주문
     * @return OrderCashReceipt 처리 중 발급 행
     */
    private function makeInProgressIssue(Order $order): OrderCashReceipt
    {
        return OrderCashReceipt::create([
            'order_id' => $order->id,
            'order_payment_id' => $order->payment?->id,
            'provider' => self::PROVIDER,
            'receipt_key' => null,
            'transaction_type' => CashReceiptTransactionType::ISSUE->value,
            'receipt_type' => CashReceiptType::INCOME->value,
            'amount' => 11000,
            'tax_free_amount' => 0,
            'identifier_masked' => '*******5678',
            'issue_status' => CashReceiptIssueStatus::IN_PROGRESS->value,
        ]);
    }

    /**
     * 실패한 발급 이력을 직접 기록합니다. (발급 회차를 소모한 과거 시도)
     *
     * @param  Order  $order  주문
     * @return OrderCashReceipt 실패 발급 행
     */
    private function makeFailedIssue(Order $order): OrderCashReceipt
    {
        return OrderCashReceipt::create([
            'order_id' => $order->id,
            'provider' => self::PROVIDER,
            'transaction_type' => CashReceiptTransactionType::ISSUE->value,
            'receipt_type' => CashReceiptType::INCOME->value,
            'amount' => 11000,
            'tax_free_amount' => 0,
            'issue_status' => CashReceiptIssueStatus::FAILED->value,
            'error_code' => 'PROVIDER_ERROR',
        ]);
    }

    /**
     * 관리자·회원·비회원 발급 API 요청 본문을 반환합니다.
     *
     * @return array<string, string> 요청 본문
     */
    private function issuePayload(): array
    {
        return [
            'receipt_type' => CashReceiptType::INCOME->value,
            'identifier_type' => CashReceiptIdentifierType::PHONE->value,
            'identifier' => self::IDENTIFIER,
        ];
    }

    /**
     * 현금영수증 서비스를 반환합니다.
     *
     * @return CashReceiptService 서비스
     */
    private function service(): CashReceiptService
    {
        return app(CashReceiptService::class);
    }

    /**
     * 주문의 발급 이력 행 수를 셉니다.
     *
     * @param  Order  $order  주문
     * @return int 발급 이력 수
     */
    private function issueRowCount(Order $order): int
    {
        return OrderCashReceipt::where('order_id', $order->id)
            ->where('transaction_type', CashReceiptTransactionType::ISSUE->value)
            ->count();
    }

    /**
     * PG 발급이 응답을 기다리는 사이 같은 주문의 발급이 다시 들어와도 PG 는 1회만 불리고 활성 영수증은 1건이다.
     * 재진입한 요청에는 처리 중 행이 돌아가고, 같은 순간 발급 가드는 ISSUE_IN_PROGRESS 로 막는다. (수정 전: PG 2회·활성 2건)
     *
     * @scenario case=issue_reentry
     *
     * @effects concurrent_issue_calls_pg_once, concurrent_issue_leaves_one_active_receipt, fresh_in_progress_issue_blocks_issue_guard
     *
     * @return void 반환값 없음
     */
    public function test_reentrant_issue_calls_pg_once_and_leaves_one_active_receipt(): void
    {
        $order = $this->makeOrder();
        $inner = null;
        $blockerDuringPg = null;

        $this->reentry = function (Order $order) use (&$inner, &$blockerDuringPg) {
            $blockerDuringPg = $this->service()->resolveIssueBlocker($order->fresh());
            $inner = $this->service()->issue($order->fresh(), CashReceiptType::INCOME, self::IDENTIFIER);
        };

        $outer = $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertCount(1, $this->issueCalls, 'PG 발급이 두 번 불렸습니다 (KVE-2026-3191 재현)');
        $this->assertSame(CashReceiptService::BLOCKER_ISSUE_IN_PROGRESS, $blockerDuringPg);
        $this->assertNotNull($inner);
        $this->assertTrue($inner->isInProgress(), '재진입 요청에는 처리 중 행이 돌아가야 한다');
        $this->assertSame((int) $outer->id, (int) $inner->id, '재진입 요청은 바깥 요청의 예약 행을 받아야 한다');

        $this->assertTrue($outer->isCompletedIssue());
        $active = app(OrderCashReceiptRepositoryInterface::class)->findActiveReceipts($order->fresh());
        $this->assertCount(1, $active, '활성 영수증이 1건이어야 한다');
        $this->assertSame(1, $this->issueRowCount($order), '재진입 요청이 발급 이력을 따로 남기면 안 된다');
        $this->assertTrue($order->fresh()->payment->is_cash_receipt_issued);
    }

    /**
     * 같은 주문의 발급이 처리 중(10분 이내)이면 관리자·회원·비회원 발급 API 3곳 모두 409 ISSUE_IN_PROGRESS 로 답하고 PG 를 부르지 않는다.
     *
     * @scenario case=issue_in_progress_fresh
     *
     * @effects fresh_in_progress_issue_returns_409, fresh_in_progress_issue_blocks_issue_guard
     *
     * @return void 반환값 없음
     */
    public function test_fresh_in_progress_issue_returns_409_on_all_issue_endpoints(): void
    {
        // 관리자
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $adminOrder = $this->makeOrder();
        $this->makeInProgressIssue($adminOrder);

        $this->actingAs($admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$adminOrder->id}/cash-receipt", $this->issuePayload())
            ->assertStatus(409)
            ->assertJsonPath('errors.error_code', 'ISSUE_IN_PROGRESS');

        // 회원 본인
        $owner = $this->createUser();
        $memberOrder = $this->makeOrder($owner);
        $this->makeInProgressIssue($memberOrder);

        $this->actingAs($owner)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$memberOrder->id}/cash-receipt", $this->issuePayload())
            ->assertStatus(409)
            ->assertJsonPath('errors.error_code', 'ISSUE_IN_PROGRESS');

        // 비회원 (조회 토큰)
        [$guestOrder, $token] = $this->makeGuestOrderWithToken();
        $this->makeInProgressIssue($guestOrder);

        $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$guestOrder->order_number}/cash-receipt",
            $this->issuePayload(),
            ['X-Guest-Order-Token' => $token],
        )
            ->assertStatus(409)
            ->assertJsonPath('errors.error_code', 'ISSUE_IN_PROGRESS');

        $this->assertSame([], $this->issueCalls, '처리 중인 발급이 있는데 PG 발급이 불렸습니다');

        foreach ([$adminOrder, $memberOrder, $guestOrder] as $order) {
            $this->assertSame(1, $this->issueRowCount($order));
        }
    }

    /**
     * 10분이 지난 처리 중 발급은 다음 발급 시도가 FAILED(IN_PROGRESS_STALE)로 정리하고, 자동 재발급하지 않는다(PG 0회).
     * 관리자가 PG 화면을 확인한 뒤 다시 발급하면 그때 정상 발급된다.
     *
     * @scenario case=issue_in_progress_stale
     *
     * @effects stale_in_progress_issue_marked_failed_without_reissue
     *
     * @return void 반환값 없음
     */
    public function test_stale_in_progress_issue_is_marked_failed_without_reissue(): void
    {
        $order = $this->makeOrder();
        $stale = $this->makeInProgressIssue($order);

        $this->travel(CashReceiptService::IN_PROGRESS_STALE_MINUTES + 1)->minutes();

        // 오래된 처리 중은 가드에서 막지 않는다 — 발급 시도가 정리한다.
        $this->assertNull($this->service()->resolveIssueBlocker($order->fresh()));

        $result = $this->service()->issue($order->fresh(), CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertSame((int) $stale->id, (int) $result->id, '오래된 처리 중 행 자체가 돌아와야 한다');
        $this->assertSame(CashReceiptIssueStatus::FAILED, $result->issue_status);
        $this->assertSame(CashReceiptService::STALE_ERROR_CODE, $result->error_code);
        $this->assertSame([], $this->issueCalls, '오래된 처리 중 행을 정리하면서 자동 재발급했습니다');
        $this->assertSame(1, $this->issueRowCount($order));
        $this->assertNull($this->service()->findActiveReceipt($order->fresh()));

        // 관리자 재발급 — 슬롯이 비었으므로 정상 발급되고 회차는 2 다.
        $retry = $this->service()->issue($order->fresh(), CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertTrue($retry->isCompletedIssue());
        $this->assertCount(1, $this->issueCalls);
        $this->assertSame(2, $this->issueCalls[0]['issue_sequence']);
    }

    /**
     * 영수증 키 없이 성공한 발급은 완료로 남되 슬롯을 놓아(slot_released_at) 다시 발급할 수 있다.
     *
     * @scenario case=issue_without_key
     *
     * @effects keyless_completed_issue_releases_slot
     *
     * @return void 반환값 없음
     */
    public function test_keyless_completed_issue_releases_slot(): void
    {
        $order = $this->makeOrder();
        $this->receiptKeys = [null];

        $keyless = $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertSame(CashReceiptIssueStatus::COMPLETED, $keyless->issue_status);
        $this->assertNotNull($keyless->slot_released_at, '키 없는 완료 발급이 슬롯을 쥐고 있습니다');
        $this->assertNull($this->service()->findActiveReceipt($order->fresh()));
        $this->assertNull(app(OrderCashReceiptRepositoryInterface::class)->findSlotHolder($order->fresh()));

        $second = $this->service()->issue($order->fresh(), CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertTrue($second->isCompletedIssue());
        $this->assertNotNull($second->receipt_key);
        $this->assertCount(2, $this->issueCalls);
        $this->assertSame((int) $second->id, (int) $this->service()->findActiveReceipt($order->fresh())?->id);
    }

    /**
     * 발급 회차는 예약 행까지의 발급 이력 수다 — 앞선 실패 2건 뒤 예약은 3회차이고, 예약 뒤에 생긴 행은 회차를 바꾸지 않는다.
     *
     * @scenario case=issue_sequence
     *
     * @effects issue_sequence_follows_reserved_row_order
     *
     * @return void 반환값 없음
     */
    public function test_issue_sequence_follows_reserved_row_order(): void
    {
        $order = $this->makeOrder();
        $this->makeFailedIssue($order);
        $this->makeFailedIssue($order);

        // PG 응답 대기 중에 같은 주문의 실패 이력이 하나 더 생겨도(예: 다른 경로의 사전 검사 실패) 이번 회차는 그대로다.
        $this->reentry = function (Order $order) {
            $this->makeFailedIssue($order);
        };

        $receipt = $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertTrue($receipt->isCompletedIssue());
        $this->assertCount(1, $this->issueCalls);
        $this->assertSame(3, $this->issueCalls[0]['issue_sequence']);
        $this->assertSame(
            3,
            app(OrderCashReceiptRepositoryInterface::class)->countIssuesUpTo($order->fresh(), (int) $receipt->id),
            '예약 행 뒤에 생긴 이력이 회차에 섞였습니다',
        );
    }
}
