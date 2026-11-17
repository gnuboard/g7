<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use App\Extension\HookManager;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptTransactionType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCashReceiptRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\RegistersTestCashReceiptProvider;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 현금영수증 취소·재발급 동시성 테스트 (U1 — KVE-2026-3191 동형)
 *
 * 수정 전 전액취소(cancelAll)는 「활성 영수증 조회 → 외부 PG 취소 → 취소 이력 INSERT」 순서였다. 취소가 겹치면 같은
 * 영수증에 PG 취소가 두 번 나갔고, 금액 동기화(syncFromOrder)가 겹치면 두 호출이 각각 「취소 → 재발급」 을 끝까지
 * 진행해 대체 영수증이 2건 생겼다.
 *
 * 수정 후에는 PG 취소 전에 처리 중(IN_PROGRESS) 취소 행을 먼저 INSERT 해 예약하고(발급 행당 열린 취소 1건 —
 * DB 유니크 `open_cancel_target_key`), 취소가 완료되면 같은 트랜잭션에서 발급 행의 슬롯을 놓는다. 재발급은 발급 슬롯
 * 예약(KVE-2026-3191 수정)이 지킨다.
 *
 * 경합 재현: PG 필터 안에서 같은 주문의 취소·동기화를 다시 부른다(재진입) — 바깥 요청이 PG 응답을 기다리는 창과 같다.
 *
 * 트랜잭션 격리: ModuleTestCase(RefreshDatabase) 트랜잭션 안에서만 돈다. DDL 은 쓰지 않는다.
 *
 * 시나리오 매니페스트: `tests/scenarios/cash-receipt-concurrency.yaml`
 */
class CashReceiptCancelConcurrencyTest extends ModuleTestCase
{
    use RegistersTestCashReceiptProvider;

    private const PROVIDER = 'tosspayments';

    private const IDENTIFIER = '01012345678';

    /** @var array<int, array<string, mixed>> 발급 필터가 받은 페이로드 기록 (= PG 발급 호출) */
    private array $issueCalls = [];

    /** @var array<int, string> 취소 필터가 받은 영수증 키 기록 (= PG 취소 호출) */
    private array $cancelCalls = [];

    /** @var array<int, bool> 취소 필터가 차례로 돌려줄 성공 여부 (비면 성공) */
    private array $cancelOutcomes = [];

    /** @var (callable(Order): void)|null 취소 필터 안에서 1회 실행할 재진입 동작 */
    private $cancelReentry = null;

    /** @var (callable(Order): void)|null 두 번째 발급 필터 호출(재발급) 안에서 1회 실행할 재진입 동작 */
    private $reissueReentry = null;

    private int $receiptSequence = 0;

    /**
     * 테스트 프로바이더와 PG 대역을 설정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->issueCalls = [];
        $this->cancelCalls = [];
        $this->cancelOutcomes = [];
        $this->cancelReentry = null;
        $this->reissueReentry = null;
        $this->receiptSequence = 0;

        $this->registerCashReceiptProvider(self::PROVIDER);
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', self::PROVIDER);
        $this->registerPgDouble();
    }

    /**
     * PG 대역(발급·취소 필터)을 등록합니다.
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

                if (count($this->issueCalls) >= 2 && $this->reissueReentry !== null) {
                    $reentry = $this->reissueReentry;
                    $this->reissueReentry = null;
                    $reentry($order);
                }

                $key = 'receipt-'.(++$this->receiptSequence);

                return [
                    'success' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'receipt_key' => $key,
                    'receipt_url' => "https://example.test/receipt/{$key}",
                    'issue_number' => '12345678',
                    'raw_response' => null,
                ];
            },
        );

        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.cancel',
            function (array $result, Order $order, string $provider, string $receiptKey) {
                if ($provider !== self::PROVIDER) {
                    return $result;
                }

                $this->cancelCalls[] = $receiptKey;

                if ($this->cancelReentry !== null) {
                    $reentry = $this->cancelReentry;
                    $this->cancelReentry = null;
                    $reentry($order);
                }

                $succeeded = $this->cancelOutcomes !== [] ? array_shift($this->cancelOutcomes) : true;

                return $succeeded
                    ? ['success' => true, 'error_code' => null, 'error_message' => null, 'receipt_key' => $receiptKey, 'raw_response' => null]
                    : ['success' => false, 'error_code' => 'PROVIDER_ERROR', 'error_message' => '취소 실패', 'receipt_key' => null, 'raw_response' => null];
            },
        );
    }

    /**
     * 현금영수증이 발급된 무통장 결제완료 주문을 만듭니다.
     *
     * @return array{0: Order, 1: OrderCashReceipt} 주문과 활성 발급 행
     */
    private function makeIssuedOrder(): array
    {
        $order = Order::factory()->create([
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

        $issue = $this->service()->issue($order->fresh(), CashReceiptType::INCOME, self::IDENTIFIER);
        $this->assertTrue($issue->isCompletedIssue(), '준비 단계 발급이 실패했습니다');

        return [$order->fresh(), $issue];
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
     * 주문의 취소 이력을 id 오름차순으로 반환합니다.
     *
     * @param  Order  $order  주문
     * @return Collection<int, OrderCashReceipt> 취소 이력
     */
    private function cancelRows(Order $order)
    {
        return OrderCashReceipt::where('order_id', $order->id)
            ->where('transaction_type', CashReceiptTransactionType::CANCEL->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * PG 취소가 응답을 기다리는 사이 같은 주문의 전액취소가 다시 들어와도 PG 취소는 1회만 나가고 취소 이력도 1건이다.
     * 재진입 호출은 처리 중인 취소를 보고 미완료(false)로 끝나며, 바깥 호출이 끝나면 발급 행의 슬롯이 풀린다. (수정 전: PG 취소 2회)
     *
     * @scenario case=cancel_reentry
     *
     * @effects concurrent_cancel_calls_pg_once, completed_cancel_releases_issue_slot
     *
     * @return void 반환값 없음
     */
    public function test_reentrant_cancel_calls_pg_once(): void
    {
        [$order, $issue] = $this->makeIssuedOrder();
        $inner = null;

        $this->cancelReentry = function (Order $order) use (&$inner) {
            $inner = $this->service()->cancelAll($order->fresh(), '재진입 취소');
        };

        $outer = $this->service()->cancelAll($order, '전액취소');

        $this->assertTrue($outer);
        $this->assertFalse($inner, '재진입 호출은 처리 중인 취소를 성공으로 보면 안 된다');
        $this->assertCount(1, $this->cancelCalls, 'PG 취소가 두 번 나갔습니다 (U1 재현)');

        $cancels = $this->cancelRows($order);
        $this->assertCount(1, $cancels);
        $this->assertSame(CashReceiptIssueStatus::COMPLETED, $cancels[0]->issue_status);
        $this->assertSame((int) $issue->id, (int) $cancels[0]->cancel_target_id);

        $this->assertNotNull($issue->fresh()->slot_released_at, '취소 완료 뒤 발급 행의 슬롯이 풀려야 한다');
        $this->assertNull($this->service()->findActiveReceipt($order->fresh()));
        $this->assertFalse((bool) $order->fresh()->payment->is_cash_receipt_issued);
    }

    /**
     * 실패한 취소는 열린 취소로 남지 않아(취소 슬롯 해제) 다시 취소할 수 있고, 재시도가 성공하면 발급 행 슬롯도 풀린다.
     *
     * @scenario case=cancel_failed_retry
     *
     * @effects failed_cancel_releases_cancel_slot, completed_cancel_releases_issue_slot
     *
     * @return void 반환값 없음
     */
    public function test_failed_cancel_releases_cancel_slot_for_retry(): void
    {
        [$order, $issue] = $this->makeIssuedOrder();
        $this->cancelOutcomes = [false, true];

        $this->assertFalse($this->service()->cancelAll($order, '1차'));

        $repository = app(OrderCashReceiptRepositoryInterface::class);
        $this->assertNull($repository->findOpenCancel($issue->fresh()), '실패한 취소가 취소 슬롯을 쥐고 있습니다');
        $this->assertNull($issue->fresh()->slot_released_at, '취소가 실패했는데 발급 슬롯이 풀렸습니다');
        $this->assertNotNull($this->service()->findActiveReceipt($order->fresh()));

        $this->assertTrue($this->service()->cancelAll($order->fresh(), '2차'));

        $cancels = $this->cancelRows($order);
        $this->assertCount(2, $cancels);
        $this->assertSame(CashReceiptIssueStatus::FAILED, $cancels[0]->issue_status);
        $this->assertSame('PROVIDER_ERROR', $cancels[0]->error_code);
        $this->assertSame(CashReceiptIssueStatus::COMPLETED, $cancels[1]->issue_status);
        $this->assertCount(2, $this->cancelCalls);
        $this->assertNotNull($issue->fresh()->slot_released_at);
    }

    /**
     * 10분이 지난 처리 중 취소는 다음 취소 시도가 FAILED(IN_PROGRESS_STALE)로 정리하고 PG 를 다시 부르지 않는다(미완료).
     * 그다음 시도부터는 열린 취소가 없어 정상 취소된다.
     *
     * @scenario case=cancel_in_progress_stale
     *
     * @effects stale_in_progress_cancel_marked_failed
     *
     * @return void 반환값 없음
     */
    public function test_stale_in_progress_cancel_is_marked_failed(): void
    {
        [$order, $issue] = $this->makeIssuedOrder();

        $staleCancel = OrderCashReceipt::create([
            'order_id' => $order->id,
            'provider' => self::PROVIDER,
            'receipt_key' => $issue->receipt_key,
            'transaction_type' => CashReceiptTransactionType::CANCEL->value,
            'receipt_type' => CashReceiptType::INCOME->value,
            'amount' => 11000,
            'tax_free_amount' => 0,
            'issue_status' => CashReceiptIssueStatus::IN_PROGRESS->value,
            'cancel_target_id' => $issue->id,
        ]);

        // 처리 중 10분 기준을 넘긴다 — 서비스 상수가 아니라 외부 계약(10분)을 직접 적어 동작만 잰다
        $this->travel(11)->minutes();

        $this->assertFalse($this->service()->cancelAll($order->fresh(), '정리'));
        $this->assertSame([], $this->cancelCalls, '오래된 처리 중 취소를 정리하면서 PG 취소를 다시 불렀습니다');
        $this->assertSame(CashReceiptIssueStatus::FAILED, $staleCancel->fresh()->issue_status);
        $this->assertSame('IN_PROGRESS_STALE', $staleCancel->fresh()->error_code);

        $this->assertTrue($this->service()->cancelAll($order->fresh(), '재시도'));
        $this->assertCount(1, $this->cancelCalls);
        $this->assertNotNull($issue->fresh()->slot_released_at);
    }

    /**
     * 다른 화면의 취소가 아직 처리 중이면 관리자 전액 취소는 「취소 실패」(422)가 아니라 「처리 중」(409)으로 답하고
     * PG 를 다시 부르지 않는다. (검증 중 발견 — 계획 다국어 표의 cancel_in_progress 미구현)
     *
     * @scenario case=cancel_in_progress_conflict
     *
     * @effects fresh_in_progress_cancel_returns_409
     *
     * @return void 반환값 없음
     */
    public function test_admin_cancel_answers_409_while_another_cancel_is_in_progress(): void
    {
        [$order, $issue] = $this->makeIssuedOrder();

        OrderCashReceipt::create([
            'order_id' => $order->id,
            'provider' => self::PROVIDER,
            'receipt_key' => $issue->receipt_key,
            'transaction_type' => CashReceiptTransactionType::CANCEL->value,
            'receipt_type' => CashReceiptType::INCOME->value,
            'amount' => 11000,
            'tax_free_amount' => 0,
            'issue_status' => CashReceiptIssueStatus::IN_PROGRESS->value,
            'cancel_target_id' => $issue->id,
        ]);

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        $this->actingAs($admin)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->id}/cash-receipt")
            ->assertStatus(409)
            ->assertJsonPath('message', __('sirsoft-ecommerce::cash_receipt.errors.cancel_in_progress'));

        $this->assertSame([], $this->cancelCalls, '처리 중인 취소가 있는데 PG 취소를 다시 불렀습니다');
        $this->assertNull($issue->fresh()->slot_released_at);
    }

    /**
     * 금액 동기화(syncFromOrder)가 PG 취소 중·재발급 중에 각각 다시 들어와도 PG 취소 1회·대체 발급 1건으로 끝나고,
     * 결제 요약은 대체 영수증 기준으로 남는다. (수정 전: 겹친 두 호출이 각각 취소·재발급해 대체 영수증 2건)
     *
     * @scenario case=sync_reentry
     *
     * @effects concurrent_sync_reissues_once
     *
     * @return void 반환값 없음
     */
    public function test_reentrant_sync_from_order_reissues_once(): void
    {
        [$order, $original] = $this->makeIssuedOrder();
        $order->update(['total_cash_equivalent_amount' => 5500, 'total_tax_amount' => 5500]);

        $innerResults = [];

        // ① PG 취소 대기 중 재진입 — 처리 중인 취소를 보고 재발급하지 않아야 한다.
        $this->cancelReentry = function (Order $order) use (&$innerResults) {
            $this->service()->syncFromOrder($order->fresh(), '재진입 동기화(취소 중)');
            $innerResults[] = 'cancel';
        };

        // ② 재발급 PG 대기 중 재진입 — 활성 영수증이 없으므로(새 발급은 처리 중) 아무것도 하지 않아야 한다.
        $this->reissueReentry = function (Order $order) use (&$innerResults) {
            $this->service()->syncFromOrder($order->fresh(), '재진입 동기화(재발급 중)');
            $innerResults[] = 'reissue';
        };

        $this->service()->syncFromOrder($order->fresh(), '부분환불');

        $this->assertSame(['cancel', 'reissue'], $innerResults, '두 재진입 지점이 모두 실행돼야 한다');
        $this->assertCount(1, $this->cancelCalls, 'PG 취소가 두 번 나갔습니다');
        $this->assertCount(2, $this->issueCalls, '원 발급 1 + 대체 발급 1 이어야 한다 (U1 재현 시 3)');

        $active = app(OrderCashReceiptRepositoryInterface::class)->findActiveReceipts($order->fresh());
        $this->assertCount(1, $active, '대체 영수증이 1건이어야 한다');
        $this->assertSame(5500, (int) $active->first()->amount);
        $this->assertNotSame((int) $original->id, (int) $active->first()->id);

        $completedIssues = OrderCashReceipt::where('order_id', $order->id)
            ->where('transaction_type', CashReceiptTransactionType::ISSUE->value)
            ->where('issue_status', CashReceiptIssueStatus::COMPLETED->value)
            ->count();
        $this->assertSame(2, $completedIssues);

        $payment = $order->fresh()->payment;
        $this->assertTrue((bool) $payment->is_cash_receipt_issued, '재진입 호출이 대체 영수증의 결제 요약을 지웠습니다');

        // 이어서 한 번 더 동기화해도 금액이 같으므로 아무것도 하지 않는다.
        $this->service()->syncFromOrder($order->fresh(), '중복 호출');
        $this->assertCount(1, $this->cancelCalls);
        $this->assertCount(2, $this->issueCalls);
    }
}
