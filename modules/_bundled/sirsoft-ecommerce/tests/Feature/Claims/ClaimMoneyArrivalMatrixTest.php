<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\ReadsReturnRefundLink;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 「돈의 도착」 매트릭스 — 결제 구성 × 클레임 경로 × 환불수단(× 우선순위) (C11 · 06 §6-2)
 *
 * 환불신청서가 「완료」로 끝나면 돈이 정확히 도착했는지 다섯 축으로 단언한다 — 상태 · ① PG 호출 금액 ·
 * ② 계좌 기록 · ③ 마일리지 원장(반환·지급)과 잔액 · ④ 주문 장부 · ⑤ 결제행 상태·취소액·취소 이력.
 * 이번 결함(마일리지로 환불 × 현금 > 0)은 이 표의 한 칸이었다.
 *
 * 기대값은 provider 의 **리터럴**이다(서비스 재계산 호출 금지). 상품 A 20,000 · B 30,000, 무료배송,
 * 쿠폰 없음, 회수·교환 배송비 0 으로 고정해 손으로 계산한다. 혼합 결제는 현금 40,000 + 마일리지 10,000.
 *
 * 모든 경로가 「접수 → 승인 → [환불하기]」 운영 흐름을 탄다 — 자동 승인 허용 목록을 비워 접수가
 * 요청 상태에서 멈추게 하고(P16 정지 경로), 환불수단 변경은 실행 본문(override)으로 준다. 자동 완료
 * 갈래 자체는 P16 매니페스트의 테스트가 잰다.
 *
 * 시나리오 매니페스트: `tests/scenarios/claim-money-arrival-matrix.yaml`
 */
class ClaimMoneyArrivalMatrixTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;
    use ReadsReturnRefundLink;

    /** 실행·접수에 싣는 환불 계좌 */
    private const BANK = ['bank_code' => '004', 'account_number' => '12345678901234', 'holder' => '홍길동'];

    private const GUEST_PASSWORD = 'guest12';

    private const GUEST_PHONE = '010-1234-5678';

    protected User $admin;

    /** @var array<int, array{amount: float}> PG 환불 훅 호출 기록 */
    private array $pgCalls = [];

    /**
     * 통화·사유·관리자, 마일리지 기능, 비어 있는 자동 승인 목록, PG 환불 훅을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
            'sirsoft-ecommerce.claims.update-amounts',
            'sirsoft-ecommerce.claims.execute-refund',
            'sirsoft-ecommerce.orders.update',
        ]);

        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('mileage.enabled', true);
        $settings->setSetting('mileage.expiry_enabled', true);
        $settings->setSetting('mileage.expiry_days', 365);
        $settings->setSetting('mileage.currency_rules', [
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
        ]);
        // 모든 수단이 접수에서 멈춘다 — 운영자가 승인하고 [환불하기]로 끝내는 표준 흐름(P16). 카탈로그가
        // 자동 승인을 선언한 수단(카드 등)도 운영자 결정 이력에 올려 합류하지 않게 한다(P17).
        $settings->setSetting('claim.auto_approve_refund_payment_methods', []);
        $settings->setSetting('claim.auto_approve_refund_payment_methods_decided', app(PaymentMethodResolver::class)->allValidIds());

        $this->pgCalls = [];
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default, $order, $payment, $amount) {
            $this->pgCalls[] = ['amount' => (float) $amount];

            return ['success' => true, 'transaction_id' => 'PGTXN-'.count($this->pgCalls), 'error_code' => null, 'error_message' => null];
        });
    }

    /**
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 결제 구성 × 경로 × 환불수단 × 우선순위의 기대 리터럴 표입니다.
     *
     * 각 행: [결제 구성, 경로, 환불수단, 우선순위, 기대]. 기대 키 — outcome(completed|rejected), final(최종 수단),
     * pg(PG 호출 금액 합)·pg_calls, bank(계좌 기록 여부), restore(사용 마일리지 반환 합), credit(현금분 지급 합),
     * cash·points(주문 장부), payment_status·cancelled(결제행), settled(취소 이력의 결제행 정산 여부).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>}> 케이스 표
     */
    public static function moneyArrivalCases(): array
    {
        return [
            /**
             * @scenario payment=card, path=cancel_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card/cancel_full/native/none' => ['card', 'cancel_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 50000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=card, path=cancel_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card/cancel_partial/native/none' => ['card', 'cancel_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=card, path=return_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card/return_full/native/none' => ['card', 'return_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 50000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=card, path=return_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card/return_partial/native/none' => ['card', 'return_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=card, path=return_partial_twice, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card/return_partial_twice/native/none' => ['card', 'return_partial_twice', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 50000, 'pg_calls' => 2, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=card, path=exchange_downgrade_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card/exchange_downgrade_refund/native/none' => ['card', 'exchange_downgrade_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 10000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 10000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=card, path=direct_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card/direct_refund/native/none' => ['card', 'direct_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=card, path=admin_proxy_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card/admin_proxy_refund/native/none' => ['card', 'admin_proxy_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=card, path=cancel_full, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card/cancel_full/points_override/none' => ['card', 'cancel_full', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 50000, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=cancel_partial, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card/cancel_partial/points_override/none' => ['card', 'cancel_partial', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=return_full, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card/return_full/points_override/none' => ['card', 'return_full', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 50000, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=return_partial, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card/return_partial/points_override/none' => ['card', 'return_partial', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=return_partial_twice, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card/return_partial_twice/points_override/none' => ['card', 'return_partial_twice', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 50000, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=exchange_downgrade_refund, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card/exchange_downgrade_refund/points_override/none' => ['card', 'exchange_downgrade_refund', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 10000, 'cash' => 10000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=direct_refund, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card/direct_refund/points_override/none' => ['card', 'direct_refund', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=admin_proxy_refund, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card/admin_proxy_refund/points_override/none' => ['card', 'admin_proxy_refund', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=cancel_full, method=bank_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, foreign_method_refund_keeps_payment_row_paid
             */
            'card/cancel_full/bank_override/none' => ['card', 'cancel_full', 'bank_override', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=cancel_partial, method=bank_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, foreign_method_refund_keeps_payment_row_paid
             */
            'card/cancel_partial/bank_override/none' => ['card', 'cancel_partial', 'bank_override', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=return_full, method=bank_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, foreign_method_refund_keeps_payment_row_paid
             */
            'card/return_full/bank_override/none' => ['card', 'return_full', 'bank_override', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=return_partial, method=bank_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, foreign_method_refund_keeps_payment_row_paid
             */
            'card/return_partial/bank_override/none' => ['card', 'return_partial', 'bank_override', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=return_partial_twice, method=bank_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, foreign_method_refund_keeps_payment_row_paid
             */
            'card/return_partial_twice/bank_override/none' => ['card', 'return_partial_twice', 'bank_override', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=exchange_downgrade_refund, method=bank_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, foreign_method_refund_keeps_payment_row_paid
             */
            'card/exchange_downgrade_refund/bank_override/none' => ['card', 'exchange_downgrade_refund', 'bank_override', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 10000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=direct_refund, method=bank_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, foreign_method_refund_keeps_payment_row_paid
             */
            'card/direct_refund/bank_override/none' => ['card', 'direct_refund', 'bank_override', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card, path=admin_proxy_refund, method=bank_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, foreign_method_refund_keeps_payment_row_paid
             */
            'card/admin_proxy_refund/bank_override/none' => ['card', 'admin_proxy_refund', 'bank_override', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=vbank_paid, path=cancel_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'vbank_paid/cancel_full/native/none' => ['vbank_paid', 'cancel_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 50000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=vbank_paid, path=cancel_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'vbank_paid/cancel_partial/native/none' => ['vbank_paid', 'cancel_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=vbank_paid, path=return_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'vbank_paid/return_full/native/none' => ['vbank_paid', 'return_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 50000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=vbank_paid, path=return_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'vbank_paid/return_partial/native/none' => ['vbank_paid', 'return_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=vbank_paid, path=return_partial_twice, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'vbank_paid/return_partial_twice/native/none' => ['vbank_paid', 'return_partial_twice', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 50000, 'pg_calls' => 2, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=vbank_paid, path=exchange_downgrade_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'vbank_paid/exchange_downgrade_refund/native/none' => ['vbank_paid', 'exchange_downgrade_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 10000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 10000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=vbank_paid, path=direct_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'vbank_paid/direct_refund/native/none' => ['vbank_paid', 'direct_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=vbank_paid, path=admin_proxy_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'vbank_paid/admin_proxy_refund/native/none' => ['vbank_paid', 'admin_proxy_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=vbank_paid, path=cancel_full, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'vbank_paid/cancel_full/points_override/none' => ['vbank_paid', 'cancel_full', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 50000, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=vbank_paid, path=cancel_partial, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'vbank_paid/cancel_partial/points_override/none' => ['vbank_paid', 'cancel_partial', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=vbank_paid, path=return_full, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'vbank_paid/return_full/points_override/none' => ['vbank_paid', 'return_full', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 50000, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=vbank_paid, path=return_partial, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'vbank_paid/return_partial/points_override/none' => ['vbank_paid', 'return_partial', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=vbank_paid, path=return_partial_twice, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'vbank_paid/return_partial_twice/points_override/none' => ['vbank_paid', 'return_partial_twice', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 50000, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=vbank_paid, path=exchange_downgrade_refund, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'vbank_paid/exchange_downgrade_refund/points_override/none' => ['vbank_paid', 'exchange_downgrade_refund', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 10000, 'cash' => 10000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=vbank_paid, path=direct_refund, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'vbank_paid/direct_refund/points_override/none' => ['vbank_paid', 'direct_refund', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=vbank_paid, path=admin_proxy_refund, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'vbank_paid/admin_proxy_refund/points_override/none' => ['vbank_paid', 'admin_proxy_refund', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank, path=cancel_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank/cancel_full/native/none' => ['dbank', 'cancel_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=dbank, path=cancel_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank/cancel_partial/native/none' => ['dbank', 'cancel_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=dbank, path=return_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank/return_full/native/none' => ['dbank', 'return_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=dbank, path=return_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank/return_partial/native/none' => ['dbank', 'return_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=dbank, path=return_partial_twice, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank/return_partial_twice/native/none' => ['dbank', 'return_partial_twice', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=dbank, path=exchange_downgrade_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank/exchange_downgrade_refund/native/none' => ['dbank', 'exchange_downgrade_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 10000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=dbank, path=direct_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank/direct_refund/native/none' => ['dbank', 'direct_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=dbank, path=admin_proxy_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank/admin_proxy_refund/native/none' => ['dbank', 'admin_proxy_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=dbank, path=cancel_full, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank/cancel_full/points_override/none' => ['dbank', 'cancel_full', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 50000, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank, path=cancel_partial, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank/cancel_partial/points_override/none' => ['dbank', 'cancel_partial', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank, path=return_full, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank/return_full/points_override/none' => ['dbank', 'return_full', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 50000, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank, path=return_partial, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank/return_partial/points_override/none' => ['dbank', 'return_partial', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank, path=return_partial_twice, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank/return_partial_twice/points_override/none' => ['dbank', 'return_partial_twice', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 50000, 'cash' => 50000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank, path=exchange_downgrade_refund, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank/exchange_downgrade_refund/points_override/none' => ['dbank', 'exchange_downgrade_refund', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 10000, 'cash' => 10000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank, path=direct_refund, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank/direct_refund/points_override/none' => ['dbank', 'direct_refund', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank, path=admin_proxy_refund, method=points_override, priority=none
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank/admin_proxy_refund/points_override/none' => ['dbank', 'admin_proxy_refund', 'points_override', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card_mileage, path=cancel_full, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/cancel_full/native/pg_first' => ['card_mileage', 'cancel_full', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 40000, 'pg_calls' => 1, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=cancel_full, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/cancel_full/native/points_first' => ['card_mileage', 'cancel_full', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 40000, 'pg_calls' => 1, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=cancel_partial, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/cancel_partial/native/pg_first' => ['card_mileage', 'cancel_partial', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=cancel_partial, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/cancel_partial/native/points_first' => ['card_mileage', 'cancel_partial', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 10000, 'pg_calls' => 1, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 10000, 'points' => 10000, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=return_full, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/return_full/native/pg_first' => ['card_mileage', 'return_full', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 40000, 'pg_calls' => 1, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=return_full, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/return_full/native/points_first' => ['card_mileage', 'return_full', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 40000, 'pg_calls' => 1, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=return_partial, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/return_partial/native/pg_first' => ['card_mileage', 'return_partial', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=return_partial, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/return_partial/native/points_first' => ['card_mileage', 'return_partial', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 10000, 'pg_calls' => 1, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 10000, 'points' => 10000, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=return_partial_twice, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/return_partial_twice/native/pg_first' => ['card_mileage', 'return_partial_twice', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 40000, 'pg_calls' => 2, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=return_partial_twice, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/return_partial_twice/native/points_first' => ['card_mileage', 'return_partial_twice', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 40000, 'pg_calls' => 2, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=exchange_downgrade_refund, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/exchange_downgrade_refund/native/pg_first' => ['card_mileage', 'exchange_downgrade_refund', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 10000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 10000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=direct_refund, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/direct_refund/native/pg_first' => ['card_mileage', 'direct_refund', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=direct_refund, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/direct_refund/native/points_first' => ['card_mileage', 'direct_refund', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 10000, 'pg_calls' => 1, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 10000, 'points' => 10000, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=admin_proxy_refund, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/admin_proxy_refund/native/pg_first' => ['card_mileage', 'admin_proxy_refund', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 20000, 'pg_calls' => 1, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=admin_proxy_refund, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'card_mileage/admin_proxy_refund/native/points_first' => ['card_mileage', 'admin_proxy_refund', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'pg', 'pg' => 10000, 'pg_calls' => 1, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 10000, 'points' => 10000, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=card_mileage, path=cancel_full, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card_mileage/cancel_full/points_override/pg_first' => ['card_mileage', 'cancel_full', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 10000, 'credit' => 40000, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card_mileage, path=cancel_partial, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card_mileage/cancel_partial/points_override/pg_first' => ['card_mileage', 'cancel_partial', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card_mileage, path=return_full, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card_mileage/return_full/points_override/pg_first' => ['card_mileage', 'return_full', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 10000, 'credit' => 40000, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card_mileage, path=return_partial, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card_mileage/return_partial/points_override/pg_first' => ['card_mileage', 'return_partial', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card_mileage, path=return_partial_twice, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card_mileage/return_partial_twice/points_override/pg_first' => ['card_mileage', 'return_partial_twice', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 10000, 'credit' => 40000, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card_mileage, path=exchange_downgrade_refund, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card_mileage/exchange_downgrade_refund/points_override/pg_first' => ['card_mileage', 'exchange_downgrade_refund', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 10000, 'cash' => 10000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card_mileage, path=direct_refund, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card_mileage/direct_refund/points_override/pg_first' => ['card_mileage', 'direct_refund', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=card_mileage, path=admin_proxy_refund, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'card_mileage/admin_proxy_refund/points_override/pg_first' => ['card_mileage', 'admin_proxy_refund', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank_mileage, path=cancel_full, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/cancel_full/native/pg_first' => ['dbank_mileage', 'cancel_full', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=cancel_full, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/cancel_full/native/points_first' => ['dbank_mileage', 'cancel_full', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=cancel_partial, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/cancel_partial/native/pg_first' => ['dbank_mileage', 'cancel_partial', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=cancel_partial, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/cancel_partial/native/points_first' => ['dbank_mileage', 'cancel_partial', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 10000, 'credit' => 0, 'cash' => 10000, 'points' => 10000, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=return_full, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/return_full/native/pg_first' => ['dbank_mileage', 'return_full', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=return_full, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/return_full/native/points_first' => ['dbank_mileage', 'return_full', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=return_partial, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/return_partial/native/pg_first' => ['dbank_mileage', 'return_partial', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=return_partial, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/return_partial/native/points_first' => ['dbank_mileage', 'return_partial', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 10000, 'credit' => 0, 'cash' => 10000, 'points' => 10000, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=return_partial_twice, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/return_partial_twice/native/pg_first' => ['dbank_mileage', 'return_partial_twice', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=return_partial_twice, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/return_partial_twice/native/points_first' => ['dbank_mileage', 'return_partial_twice', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 10000, 'credit' => 0, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'cancelled', 'cancelled' => 40000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=exchange_downgrade_refund, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/exchange_downgrade_refund/native/pg_first' => ['dbank_mileage', 'exchange_downgrade_refund', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 10000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=direct_refund, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/direct_refund/native/pg_first' => ['dbank_mileage', 'direct_refund', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=direct_refund, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/direct_refund/native/points_first' => ['dbank_mileage', 'direct_refund', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 10000, 'credit' => 0, 'cash' => 10000, 'points' => 10000, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=admin_proxy_refund, method=native, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/admin_proxy_refund/native/pg_first' => ['dbank_mileage', 'admin_proxy_refund', 'native', 'pg_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=admin_proxy_refund, method=native, priority=points_first
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'dbank_mileage/admin_proxy_refund/native/points_first' => ['dbank_mileage', 'admin_proxy_refund', 'native', 'points_first', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 10000, 'credit' => 0, 'cash' => 10000, 'points' => 10000, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=dbank_mileage, path=cancel_full, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank_mileage/cancel_full/points_override/pg_first' => ['dbank_mileage', 'cancel_full', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 10000, 'credit' => 40000, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank_mileage, path=cancel_partial, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank_mileage/cancel_partial/points_override/pg_first' => ['dbank_mileage', 'cancel_partial', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank_mileage, path=return_full, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank_mileage/return_full/points_override/pg_first' => ['dbank_mileage', 'return_full', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 10000, 'credit' => 40000, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank_mileage, path=return_partial, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank_mileage/return_partial/points_override/pg_first' => ['dbank_mileage', 'return_partial', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank_mileage, path=return_partial_twice, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank_mileage/return_partial_twice/points_override/pg_first' => ['dbank_mileage', 'return_partial_twice', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 10000, 'credit' => 40000, 'cash' => 40000, 'points' => 10000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank_mileage, path=exchange_downgrade_refund, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank_mileage/exchange_downgrade_refund/points_override/pg_first' => ['dbank_mileage', 'exchange_downgrade_refund', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 10000, 'cash' => 10000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank_mileage, path=direct_refund, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank_mileage/direct_refund/points_override/pg_first' => ['dbank_mileage', 'direct_refund', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=dbank_mileage, path=admin_proxy_refund, method=points_override, priority=pg_first
             *
             * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage, foreign_method_refund_keeps_payment_row_paid
             */
            'dbank_mileage/admin_proxy_refund/points_override/pg_first' => ['dbank_mileage', 'admin_proxy_refund', 'points_override', 'pg_first', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 20000, 'cash' => 20000, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_card, path=cancel_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_card/cancel_full/native/none' => ['mileage_only_card', 'cancel_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 50000, 'credit' => 0, 'cash' => 0, 'points' => 50000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_card, path=cancel_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_card/cancel_partial/native/none' => ['mileage_only_card', 'cancel_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 20000, 'credit' => 0, 'cash' => 0, 'points' => 20000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_card, path=return_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_card/return_full/native/none' => ['mileage_only_card', 'return_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 50000, 'credit' => 0, 'cash' => 0, 'points' => 50000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_card, path=return_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_card/return_partial/native/none' => ['mileage_only_card', 'return_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 20000, 'credit' => 0, 'cash' => 0, 'points' => 20000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_card, path=return_partial_twice, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_card/return_partial_twice/native/none' => ['mileage_only_card', 'return_partial_twice', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 50000, 'credit' => 0, 'cash' => 0, 'points' => 50000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_card, path=exchange_downgrade_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_card/exchange_downgrade_refund/native/none' => ['mileage_only_card', 'exchange_downgrade_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 0, 'points' => 10000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_card, path=direct_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_card/direct_refund/native/none' => ['mileage_only_card', 'direct_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 20000, 'credit' => 0, 'cash' => 0, 'points' => 20000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_card, path=admin_proxy_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_card/admin_proxy_refund/native/none' => ['mileage_only_card', 'admin_proxy_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 20000, 'credit' => 0, 'cash' => 0, 'points' => 20000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_dbank, path=cancel_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_dbank/cancel_full/native/none' => ['mileage_only_dbank', 'cancel_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 50000, 'credit' => 0, 'cash' => 0, 'points' => 50000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_dbank, path=cancel_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_dbank/cancel_partial/native/none' => ['mileage_only_dbank', 'cancel_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 20000, 'credit' => 0, 'cash' => 0, 'points' => 20000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_dbank, path=return_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_dbank/return_full/native/none' => ['mileage_only_dbank', 'return_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 50000, 'credit' => 0, 'cash' => 0, 'points' => 50000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_dbank, path=return_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_dbank/return_partial/native/none' => ['mileage_only_dbank', 'return_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 20000, 'credit' => 0, 'cash' => 0, 'points' => 20000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_dbank, path=return_partial_twice, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_dbank/return_partial_twice/native/none' => ['mileage_only_dbank', 'return_partial_twice', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 50000, 'credit' => 0, 'cash' => 0, 'points' => 50000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_dbank, path=exchange_downgrade_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_dbank/exchange_downgrade_refund/native/none' => ['mileage_only_dbank', 'exchange_downgrade_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 10000, 'credit' => 0, 'cash' => 0, 'points' => 10000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_dbank, path=direct_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_dbank/direct_refund/native/none' => ['mileage_only_dbank', 'direct_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 20000, 'credit' => 0, 'cash' => 0, 'points' => 20000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=mileage_only_dbank, path=admin_proxy_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'mileage_only_dbank/admin_proxy_refund/native/none' => ['mileage_only_dbank', 'admin_proxy_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'points', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 20000, 'credit' => 0, 'cash' => 0, 'points' => 20000, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=guest_dbank, path=cancel_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'guest_dbank/cancel_full/native/none' => ['guest_dbank', 'cancel_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=guest_dbank, path=cancel_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'guest_dbank/cancel_partial/native/none' => ['guest_dbank', 'cancel_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=guest_dbank, path=return_full, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'guest_dbank/return_full/native/none' => ['guest_dbank', 'return_full', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=guest_dbank, path=return_partial, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'guest_dbank/return_partial/native/none' => ['guest_dbank', 'return_partial', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=guest_dbank, path=return_partial_twice, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'guest_dbank/return_partial_twice/native/none' => ['guest_dbank', 'return_partial_twice', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 50000, 'points' => 0, 'payment_status' => 'cancelled', 'cancelled' => 50000, 'settled' => true]],
            /**
             * @scenario payment=guest_dbank, path=exchange_downgrade_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'guest_dbank/exchange_downgrade_refund/native/none' => ['guest_dbank', 'exchange_downgrade_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 10000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 10000, 'settled' => true]],
            /**
             * @scenario payment=guest_dbank, path=direct_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'guest_dbank/direct_refund/native/none' => ['guest_dbank', 'direct_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=guest_dbank, path=admin_proxy_refund, method=native, priority=none
             *
             * @effects refund_completes_only_when_money_arrives
             */
            'guest_dbank/admin_proxy_refund/native/none' => ['guest_dbank', 'admin_proxy_refund', 'native', 'none', ['outcome' => 'completed', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => true, 'restore' => 0, 'credit' => 0, 'cash' => 20000, 'points' => 0, 'payment_status' => 'partial_cancelled', 'cancelled' => 20000, 'settled' => true]],
            /**
             * @scenario payment=guest_dbank, path=cancel_full, method=points_override, priority=none
             *
             * @effects guest_or_disabled_points_refund_rejected
             */
            'guest_dbank/cancel_full/points_override/none' => ['guest_dbank', 'cancel_full', 'points_override', 'none', ['outcome' => 'rejected', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 0, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=guest_dbank, path=cancel_partial, method=points_override, priority=none
             *
             * @effects guest_or_disabled_points_refund_rejected
             */
            'guest_dbank/cancel_partial/points_override/none' => ['guest_dbank', 'cancel_partial', 'points_override', 'none', ['outcome' => 'rejected', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 0, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=guest_dbank, path=return_full, method=points_override, priority=none
             *
             * @effects guest_or_disabled_points_refund_rejected
             */
            'guest_dbank/return_full/points_override/none' => ['guest_dbank', 'return_full', 'points_override', 'none', ['outcome' => 'rejected', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 0, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=guest_dbank, path=return_partial, method=points_override, priority=none
             *
             * @effects guest_or_disabled_points_refund_rejected
             */
            'guest_dbank/return_partial/points_override/none' => ['guest_dbank', 'return_partial', 'points_override', 'none', ['outcome' => 'rejected', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 0, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=guest_dbank, path=return_partial_twice, method=points_override, priority=none
             *
             * @effects guest_or_disabled_points_refund_rejected
             */
            'guest_dbank/return_partial_twice/points_override/none' => ['guest_dbank', 'return_partial_twice', 'points_override', 'none', ['outcome' => 'rejected', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 0, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=guest_dbank, path=exchange_downgrade_refund, method=points_override, priority=none
             *
             * @effects guest_or_disabled_points_refund_rejected
             */
            'guest_dbank/exchange_downgrade_refund/points_override/none' => ['guest_dbank', 'exchange_downgrade_refund', 'points_override', 'none', ['outcome' => 'rejected', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 0, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=guest_dbank, path=direct_refund, method=points_override, priority=none
             *
             * @effects guest_or_disabled_points_refund_rejected
             */
            'guest_dbank/direct_refund/points_override/none' => ['guest_dbank', 'direct_refund', 'points_override', 'none', ['outcome' => 'rejected', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 0, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
            /**
             * @scenario payment=guest_dbank, path=admin_proxy_refund, method=points_override, priority=none
             *
             * @effects guest_or_disabled_points_refund_rejected
             */
            'guest_dbank/admin_proxy_refund/points_override/none' => ['guest_dbank', 'admin_proxy_refund', 'points_override', 'none', ['outcome' => 'rejected', 'final' => 'bank', 'pg' => 0, 'pg_calls' => 0, 'bank' => false, 'restore' => 0, 'credit' => 0, 'cash' => 0, 'points' => 0, 'payment_status' => 'paid', 'cancelled' => 0, 'settled' => false]],
        ];
    }

    /**
     * 클레임이 「완료」로 끝나면 돈이 기대한 곳에 기대한 만큼 도착하고, 거절 행은 아무것도 움직이지 않는다.
     *
     * 행마다의 축 조합은 provider 의 행 주석에 있다. 아래는 이 메서드의 대표 조합이다.
     *
     * @scenario payment=card, path=cancel_full, method=native, priority=none
     *
     * @effects refund_completes_only_when_money_arrives, points_refund_credits_cash_share_as_non_expiring_mileage,
     *          foreign_method_refund_keeps_payment_row_paid, guest_or_disabled_points_refund_rejected
     *
     * @param  string  $payment  결제 구성
     * @param  string  $path  클레임 경로
     * @param  string  $method  환불수단 축(native|points_override|bank_override)
     * @param  string  $priority  환불 우선순위(none|pg_first|points_first)
     * @param  array<string, mixed>  $expect  기대 리터럴
     * @return void 반환값 없음
     */
    #[DataProvider('moneyArrivalCases')]
    public function test_money_arrives_exactly(string $payment, string $path, string $method, string $priority, array $expect): void
    {
        $order = $this->matrixOrder($payment, $this->statusFor($path));
        [$optionA, $optionB] = $this->optionsByPrice($order);

        $rounds = match ($path) {
            'cancel_full', 'return_full' => [[$optionA, $optionB]],
            'return_partial_twice' => [[$optionA], [$optionB]],
            'exchange_downgrade_refund' => [[$optionB]],
            default => [[$optionA]],
        };

        $refundIds = [];
        foreach ($rounds as $options) {
            $refund = $this->fileRefund($path, $order->fresh(['options', 'shippings', 'payment', 'shippingAddress']), $options, $payment, $priority);
            $refundIds[] = $refund->id;
            $response = $this->approveAndExecute($refund, $payment, $method);

            if ($expect['outcome'] === 'rejected') {
                $this->assertNotNull($response);
                $response->assertStatus(422);
                $this->assertNothingMoved($order, $refund);

                return;
            }

            $response?->assertOk();
        }

        $this->assertMoneyArrived($order->fresh(), $refundIds, $expect);
    }

    /**
     * 실제로 차감된 적 없는 마일리지는 돌려주지도 장부에 적지도 않는다(Q15).
     *
     * @scenario payment=card_mileage, path=cancel_full, method=native, priority=pg_first
     *
     * @effects mileage_never_deducted_refunds_zero_points
     *
     * @return void 반환값 없음
     */
    public function test_mileage_never_deducted_refunds_zero_points(): void
    {
        $order = $this->matrixOrder('card_mileage', OrderStatusEnum::PAYMENT_COMPLETE, deductMileage: false);
        $refund = $this->fileRefund('cancel_full', $order, $this->optionsByPrice($order), 'card_mileage', 'pg_first');

        $this->approveAndExecute($refund, 'card_mileage', 'native')?->assertOk();

        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
        $this->assertSame([40000.0], array_column($this->pgCalls, 'amount'));
        $this->assertSame(0.0, (float) $refund->fresh()->refund_points_amount);
        $this->assertSame(0, MileageTransaction::query()->where('order_refund_id', $refund->id)->count());
        $this->assertSame(0.0, (float) $order->fresh()->total_refunded_points_amount);
    }

    /**
     * 마일리지 기능이 꺼진 상점은 현금을 마일리지로 돌려주지 않는다 — 422, 돈은 움직이지 않는다(Q21).
     *
     * @scenario payment=card, path=return_full, method=points_override, priority=none
     *
     * @effects guest_or_disabled_points_refund_rejected
     *
     * @return void 반환값 없음
     */
    public function test_points_refund_of_cash_is_rejected_when_mileage_is_disabled(): void
    {
        $order = $this->matrixOrder('card', OrderStatusEnum::DELIVERED);
        $refund = $this->fileRefund('return_full', $order, $this->optionsByPrice($order), 'card', 'none');
        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', false);

        $this->approveAndExecute($refund, 'card', 'points_override')?->assertStatus(422);

        $this->assertNothingMoved($order, $refund);
    }

    // ================================================================
    // 픽스처
    // ================================================================

    /**
     * 경로가 출발하는 주문 상태를 돌려줍니다.
     *
     * @param  string  $path  클레임 경로
     * @return OrderStatusEnum 주문 상태
     */
    private function statusFor(string $path): OrderStatusEnum
    {
        return match ($path) {
            'cancel_full', 'cancel_partial' => OrderStatusEnum::PAYMENT_COMPLETE,
            'direct_refund', 'admin_proxy_refund' => OrderStatusEnum::PREPARING,
            default => OrderStatusEnum::DELIVERED,
        };
    }

    /**
     * 결제 구성에 맞는 상품 2개(20,000 / 30,000) 주문을 만들고, 마일리지를 쓴 회원 주문이면 실제 차감 원장을 남깁니다.
     *
     * @param  string  $payment  결제 구성
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  bool  $deductMileage  사용 마일리지를 실제로 차감할지 (false = 차감된 적 없는 주문)
     * @return Order 주문 (options·payment·shippings 로드)
     */
    private function matrixOrder(string $payment, OrderStatusEnum $status, bool $deductMileage = true): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $policy->countrySettings()->where('country_code', 'KR')->update([
            'claim_fee_mode' => 'fixed',
            'return_fixed_fee' => 0,
            'exchange_fixed_fee' => 0,
        ]);
        [$productA, $optionA] = $this->createClaimProduct(20000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(30000, $policy);

        $points = match ($payment) {
            'card_mileage', 'dbank_mileage' => 10000,
            'mileage_only_card', 'mileage_only_dbank' => 50000,
            default => 0,
        };
        $method = match ($payment) {
            'vbank_paid' => 'vbank',
            'dbank', 'dbank_mileage', 'mileage_only_dbank', 'guest_dbank' => 'dbank',
            default => 'card',
        };
        $guest = $payment === 'guest_dbank';
        $member = $guest ? null : $this->createUser();

        $order = $this->createClaimOrder(
            new CalculationInput(
                items: [
                    new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                    new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
                ],
                usePoints: $points,
            ),
            [
                'user_id' => $member?->id,
                'is_mileage_deducted' => $points > 0 && $deductMileage,
                'currency_snapshot' => $this->krwCurrencySnapshot(),
            ] + ($guest ? ['guest_lookup_password_hash' => Hash::make(self::GUEST_PASSWORD)] : []),
            ['payment_method' => $method, 'pg_provider' => ''],
        );
        $this->assertSame($points, (int) $order->total_points_used_amount, '픽스처의 마일리지 사용액이 손계산과 다르다');

        $order = $this->moveOrderTo($this->attachPolicyShippings($order, $policy), $status);
        OrderAddress::where('order_id', $order->id)->update(['orderer_phone' => self::GUEST_PHONE]);

        if ($member !== null && $points > 0 && $deductMileage) {
            $this->grantMileage($member->id, $points);
            app(UserMileageService::class)->deductFifo($member->id, $points, 'KRW', $order);
        }

        return $order->fresh(['options', 'payment', 'shippings', 'shippingAddress']);
    }

    /**
     * 주문 상품을 가격순(A 20,000, B 30,000)으로 돌려줍니다.
     *
     * @param  Order  $order  주문
     * @return array{0: OrderOption, 1: OrderOption} 가격순 주문 상품 [A, B]
     */
    private function optionsByPrice(Order $order): array
    {
        return array_values($order->options->sortBy(fn (OrderOption $o) => (float) $o->unit_price)->all());
    }

    /**
     * 결제 구성이 접수 때 환불 계좌를 요구하는지 판정합니다. (무통장 계열·입금완료 가상계좌)
     *
     * @param  string  $payment  결제 구성
     * @return bool 계좌를 실어야 하면 true
     */
    private function needsBankAtIntake(string $payment): bool
    {
        return in_array($payment, ['vbank_paid', 'dbank', 'dbank_mileage', 'guest_dbank'], true);
    }

    /**
     * 경로에 맞는 실제 접수 흐름으로 환불신청서를 만듭니다.
     *
     * @param  string  $path  클레임 경로
     * @param  Order  $order  주문
     * @param  array<int, OrderOption>  $options  대상 상품
     * @param  string  $payment  결제 구성
     * @param  string  $priority  환불 우선순위
     * @return OrderRefund 접수된 환불신청서
     */
    private function fileRefund(string $path, Order $order, array $options, string $payment, string $priority): OrderRefund
    {
        $priorityValue = $priority === 'none' ? RefundPriorityEnum::PG_FIRST->value : $priority;
        $bank = $this->needsBankAtIntake($payment) ? ['refund_bank' => self::BANK] : [];

        return match ($path) {
            'cancel_full', 'cancel_partial', 'direct_refund' => $this->fileByCustomer($order, $options, $priorityValue, $bank),
            'admin_proxy_refund' => $this->fileByAdmin($order, $options, $priorityValue, $bank),
            'exchange_downgrade_refund' => $this->fileExchange($order, $options[0]),
            default => $this->fileReturn($order, $options, RefundPriorityEnum::from($priorityValue)),
        };
    }

    /**
     * 고객(회원·비회원) 취소·환불 신청 API 로 접수합니다.
     *
     * @param  Order  $order  주문
     * @param  array<int, OrderOption>  $options  대상 상품
     * @param  string  $priority  환불 우선순위 값
     * @param  array<string, mixed>  $bank  환불 계좌 페이로드
     * @return OrderRefund 접수된 환불신청서
     */
    private function fileByCustomer(Order $order, array $options, string $priority, array $bank): OrderRefund
    {
        $payload = [
            'items' => array_map(fn (OrderOption $o) => ['order_option_id' => $o->id, 'quantity' => (int) $o->quantity], $options),
            'reason_code' => 'changed_mind',
            'refund_priority' => $priority,
        ] + $bank;

        if ($order->user_id === null) {
            $token = app(GuestOrderAuthService::class)->authenticate($order->order_number, self::GUEST_PHONE, self::GUEST_PASSWORD, '10.0.0.9')['token'];
            $this->postJson("/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/refund-requests", $payload, ['X-Guest-Order-Token' => $token])
                ->assertCreated();
        } else {
            $this->actingAs(User::findOrFail($order->user_id))
                ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/refund-requests", $payload)
                ->assertCreated();
        }

        return $this->latestRefund($order);
    }

    /**
     * 관리자 [주문취소](대리 접수, 자동 전이 끔)로 접수합니다.
     *
     * @param  Order  $order  주문
     * @param  array<int, OrderOption>  $options  대상 상품
     * @param  string  $priority  환불 우선순위 값
     * @param  array<string, mixed>  $bank  환불 계좌 페이로드
     * @return OrderRefund 접수된 환불신청서
     */
    private function fileByAdmin(Order $order, array $options, string $priority, array $bank): OrderRefund
    {
        $this->actingAs($this->admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'partial',
                'items' => array_map(fn (OrderOption $o) => ['order_option_id' => $o->id, 'cancel_quantity' => (int) $o->quantity], $options),
                'reason' => 'changed_mind',
                'auto_advance_refund' => false,
                'refund_priority' => $priority,
            ] + $bank)
            ->assertOk();

        $refund = $this->latestRefund($order);
        $this->assertSame(RefundSourceTypeEnum::ADMIN, $refund->refund_source_type);

        return $refund;
    }

    /**
     * 반품을 접수해 수거완료 → 완료(환불신청서 접수)까지 보냅니다.
     *
     * @param  Order  $order  주문
     * @param  array<int, OrderOption>  $options  반품 상품
     * @param  RefundPriorityEnum  $priority  환불 우선순위
     * @return OrderRefund 반품 완료가 접수한 환불신청서
     */
    private function fileReturn(Order $order, array $options, RefundPriorityEnum $priority): OrderRefund
    {
        $service = app(OrderReturnService::class);
        $return = $service->requestReturn($order, [
            'items' => array_map(fn (OrderOption $o) => ['order_option_id' => $o->id, 'quantity' => (int) $o->quantity], $options),
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $order->user_id,
            'refund_priority' => $priority,
        ]);

        foreach ([ReturnStatusEnum::APPROVED, ReturnStatusEnum::COLLECTION_READY, ReturnStatusEnum::COLLECTING, ReturnStatusEnum::COLLECTED] as $step) {
            $return = $service->transition($return, $step, $this->claimTrackingFor($step));
        }

        $return = $service->completeReturn($return, [], new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true));

        return OrderRefund::findOrFail($this->returnRefundId($return));
    }

    /**
     * 상품 B(30,000)를 20,000 짜리 옵션으로 교환해 재발송완료 → 완료(차액 환불신청서 접수)까지 보냅니다.
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  교환 대상 상품(B)
     * @return OrderRefund 교환 완료가 접수한 차액 환불신청서
     */
    private function fileExchange(Order $order, OrderOption $option): OrderRefund
    {
        $service = app(OrderExchangeService::class);
        $newOption = ProductOptionFactory::new()
            ->forProduct($option->product)
            ->create(['price_adjustment' => -10000, 'stock_quantity' => 100, 'is_default' => false, 'is_active' => true]);

        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $option->id, 'quantity' => 1, 'new_product_option_id' => $newOption->id]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $order->user_id,
        ]);

        foreach ([
            ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::COLLECTION_READY, ExchangeStatusEnum::COLLECTING,
            ExchangeStatusEnum::COLLECTED, ExchangeStatusEnum::REDELIVERY_READY, ExchangeStatusEnum::REDELIVERING,
            ExchangeStatusEnum::REDELIVERED,
        ] as $step) {
            $exchange = $service->transition($exchange, $step, $this->claimTrackingFor($step));
        }

        $exchange = $service->completeExchange($exchange, [], new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true));

        return OrderRefund::query()
            ->where('refund_source_type', RefundSourceTypeEnum::EXCHANGE->value)
            ->where('refund_source_id', $exchange->id)
            ->firstOrFail();
    }

    /**
     * 주문의 가장 최근 환불신청서를 돌려줍니다.
     *
     * @param  Order  $order  주문
     * @return OrderRefund 환불신청서
     */
    private function latestRefund(Order $order): OrderRefund
    {
        return OrderRefund::query()->where('order_id', $order->id)->orderByDesc('id')->firstOrFail();
    }

    /**
     * 환불신청서를 승인하고 관리자 [환불하기]로 실행합니다. (환불수단 변경은 실행 본문으로)
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  string  $payment  결제 구성
     * @param  string  $method  환불수단 축
     * @return TestResponse|null 실행 응답 (현금 0원 「마일리지로 환불」이 접수에서 자동 완료됐으면 null)
     */
    private function approveAndExecute(OrderRefund $refund, string $payment, string $method): ?TestResponse
    {
        // 현금 0원 「마일리지로 환불」은 송금이 없어 결제수단 게이트와 무관하게 자동 완료된다(결함 4) —
        // 그 갈래는 실행할 것이 없고 돈의 도착만 잰다.
        if ($method === 'native' && str_starts_with($payment, 'mileage_only_')
            && $refund->fresh()->refund_status === RefundStatusEnum::COMPLETED) {
            return null;
        }

        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->fresh()->refund_status, '접수가 요청 상태에서 멈춰야 운영자 흐름을 잴 수 있다');
        $refund = app(OrderRefundRequestService::class)->transition($refund->fresh(), RefundStatusEnum::APPROVED);

        $body = match ($method) {
            'points_override' => ['refund_method' => 'points'],
            'bank_override' => ['refund_method' => 'bank'],
            default => [],
        };
        $bankFinal = $method === 'bank_override' || ($method === 'native' && in_array($payment, ['dbank', 'dbank_mileage', 'guest_dbank'], true));
        if ($bankFinal || ($payment === 'vbank_paid' && $method === 'native')) {
            $body['refund_bank'] = self::BANK;
        }

        return $this->actingAs($this->admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/refunds/{$refund->id}/execute", $body);
    }

    // ================================================================
    // 단언
    // ================================================================

    /**
     * 다섯 축(상태·PG·계좌·마일리지·장부·결제행)으로 돈의 도착을 단언합니다.
     *
     * @param  Order  $order  주문
     * @param  array<int, int>  $refundIds  이 경로가 실행한 환불신청서 ID
     * @param  array<string, mixed>  $expect  기대 리터럴
     * @return void 반환값 없음
     */
    private function assertMoneyArrived(Order $order, array $refundIds, array $expect): void
    {
        $refunds = OrderRefund::query()->whereIn('id', $refundIds)->get();

        // 상태
        foreach ($refunds as $refund) {
            $this->assertSame(RefundStatusEnum::COMPLETED, $refund->refund_status, "환불신청서 {$refund->id} 가 완료되지 않았다");
            $this->assertSame($expect['final'], $refund->refund_method->value, '최종 환불수단');
        }

        // ① PG
        $this->assertCount($expect['pg_calls'], $this->pgCalls, 'PG 호출 횟수');
        $this->assertSame((float) $expect['pg'], (float) array_sum(array_column($this->pgCalls, 'amount')), 'PG 호출 금액 합');

        // ② 계좌
        $payment = $order->payment()->first();
        if ($expect['bank']) {
            foreach ($refunds as $refund) {
                $this->assertSame(self::BANK['bank_code'], $refund->refund_bank_code);
                $this->assertSame(self::BANK['account_number'], $refund->refund_bank_account);
                $this->assertSame(self::BANK['holder'], $refund->refund_bank_holder);
            }
            $this->assertSame(self::BANK['bank_code'], $payment->refund_bank_code);
            $this->assertSame(self::BANK['account_number'], $payment->refund_bank_account);
            $this->assertSame(self::BANK['holder'], $payment->refund_bank_holder);
        }

        // ③ 마일리지
        $restore = (float) MileageTransaction::query()->whereIn('order_refund_id', $refundIds)
            ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)->sum('amount');
        $credits = MileageTransaction::query()->whereIn('order_refund_id', $refundIds)
            ->where('type', MileageTransactionTypeEnum::REFUND_CREDIT->value)->get();
        $this->assertSame((float) $expect['restore'], $restore, '사용 마일리지 반환 합');
        $this->assertSame((float) $expect['credit'], (float) $credits->sum('amount'), '현금분 마일리지 지급 합');
        $this->assertSame(0, $credits->whereNotNull('expires_at')->count(), '현금분 지급은 무기한이다');
        if ($order->user_id !== null) {
            $this->assertSame(
                (float) ($expect['restore'] + $expect['credit']),
                (float) app(MileageTransactionRepositoryInterface::class)->getBalanceByCurrency($order->user_id, 'KRW'),
                '회원 잔액 = 반환 + 지급 (차감 뒤 0 에서 출발)'
            );
        }

        // ④ 장부
        $this->assertSame((float) $expect['cash'], (float) $order->total_refunded_amount, '주문 현금 환불 누적');
        $this->assertSame((float) $expect['points'], (float) $order->total_refunded_points_amount, '주문 마일리지 환불 누적');

        // ⑤ 결제행
        $this->assertSame($expect['payment_status'], $payment->payment_status->value, '결제행 상태');
        $this->assertSame((float) $expect['cancelled'], (float) $payment->cancelled_amount, '결제행 취소액');

        $history = collect($payment->cancel_history ?? []);
        $mine = $history->filter(fn ($h) => in_array((int) ($h['refund_id'] ?? 0), $refundIds, true));
        $this->assertSame((float) $expect['cash'], (float) $mine->sum(fn ($h) => (float) ($h['amount'] ?? 0)), '취소 이력 현금 합');
        $this->assertSame((float) $expect['points'], (float) $mine->sum(fn ($h) => (float) ($h['points_amount'] ?? 0)), '취소 이력 마일리지 합');
        foreach ($mine as $entry) {
            $this->assertSame($expect['final'], $entry['refund_method'] ?? null, '취소 이력 환불수단');
            if ((float) ($entry['amount'] ?? 0) > 0) {
                $this->assertSame($expect['settled'], (bool) ($entry['settled_on_payment'] ?? false), '취소 이력 결제행 정산 여부');
            }
        }

        // 불변식 — 결제행 정산분 합 == 취소액, 장부 현금 == PG + 계좌 + 지급
        $settled = (float) $history->sum(fn ($h) => ! empty($h['settled_on_payment']) ? (float) ($h['amount'] ?? 0) : 0.0);
        $this->assertSame((float) $payment->cancelled_amount, $settled, '정산 이력 합 == 결제행 취소액');
        $bankAmount = $expect['bank'] ? (float) $expect['cash'] : 0.0;
        $this->assertSame((float) $expect['cash'], (float) $expect['pg'] + $bankAmount + (float) $expect['credit'], '장부 현금 == PG + 계좌 + 지급');
    }

    /**
     * 거절된 실행이 신청서·PG·마일리지·장부·결제행을 하나도 움직이지 않았음을 단언합니다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  거절된 환불신청서
     * @return void 반환값 없음
     */
    private function assertNothingMoved(Order $order, OrderRefund $refund): void
    {
        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
        $this->assertSame([], $this->pgCalls);
        $this->assertSame(0, MileageTransaction::query()->where('order_refund_id', $refund->id)->count());

        $fresh = $order->fresh();
        $this->assertSame(0.0, (float) $fresh->total_refunded_amount);
        $this->assertSame(0.0, (float) $fresh->total_refunded_points_amount);

        $payment = $fresh->payment()->first();
        $this->assertSame(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame(0.0, (float) $payment->cancelled_amount);
    }
}
