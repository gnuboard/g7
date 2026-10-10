<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Exceptions\RefundLedgerOutsideTransactionException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\AppliesRefundBankAccount;

/**
 * 환불 실행 서비스
 *
 * PG 환불 훅 실행과 쿠폰/마일리지/재고 복원, 현금영수증 동기화를 담당합니다.
 * 취소(OrderCancellationService)와 클레임(교환/반품) 양쪽이 같은 규칙으로 환불을
 * 실행하도록 OrderCancellationService 에서 추출한 공용 서비스입니다.
 *
 * 이 클래스는 "환불을 실행하는 방법"만 안다 — 무엇을 환불할지(계산)는
 * OrderAdjustmentService 가, 환불 레코드의 상태 전이와 출처 기록은 호출부가 정한다.
 *
 * 돈·권리에 관한 부수효과(마일리지 반환·지급, 쿠폰·재고 복원)의 실패는 삼키지 않는다(D2) —
 * 예외로 올려 호출부 트랜잭션 전체를 되돌린다. 삼키면 카드는 취소됐는데 마일리지·쿠폰·재고는
 * 돌아오지 않은 채 「완료」로 닫힌다. 현금영수증 동기화만 커밋 뒤·트랜잭션 밖이라 삼킨다.
 */
class RefundExecutionService
{
    use AppliesRefundBankAccount;

    /**
     * 이번 실행에서 PG 가 승인한 환불 기록 (§14-C 표식용)
     *
     * PG 가 돈을 내보낸 뒤 장부 확정이 롤백되면, 호출부가 이 값을 꺼내 critical 로그와 신청서
     * 표식을 남긴다 — 트랜잭션 롤백은 PG 응답을 되돌리지 못하므로 남길 근거가 이것뿐이다.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $pgSettlement = null;

    /**
     * 클레임 환불이 주문 헤더에 동기화할 수 있는 컬럼 (S3-D17 · #599 validate P2 #8)
     *
     * **세금 분류 + 현금성 금액만** 담는다. `total_paid_amount`·`total_amount` 가 섞여 들어오면
     * 잔여 한도(`total_paid_amount - total_refunded_amount`)가 같은 금액을 두 번 차감한다
     * (`execute()` 독블록). `total_cash_equivalent_amount` 가 빠지면 현금영수증 발급 가능액이
     * 전액 환불 뒤에도 남는다(2026-09-16 실측). `mc_*` 두 개는 base 와 짝을 맞춘다.
     *
     * 목록의 정본은 여기 하나다 — 호출부가 골라 싣더라도 쓰기 직전에 이 목록으로 다시 거른다.
     *
     * @var array<int, string>
     */
    public const TAX_SYNC_COLUMNS = [
        'total_tax_amount',
        'total_tax_free_amount',
        'total_vat_amount',
        'total_cash_equivalent_amount',
        'mc_total_tax_amount',
        'mc_total_tax_free_amount',
    ];

    /**
     * @param  StockService  $stockService  재고 서비스
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     * @param  CashReceiptService  $cashReceiptService  현금영수증 발급/취소 서비스
     * @param  OrderPaymentRepositoryInterface  $orderPaymentRepository  주문 결제 저장소 (송금 대상 계좌 갱신)
     * @param  MileageTransactionRepositoryInterface  $mileageLedger  마일리지 원장 (환불 지급 기록 확인 — fail-closed)
     */
    public function __construct(
        protected StockService $stockService,
        protected EcommerceSettingsService $settingsService,
        protected CashReceiptService $cashReceiptService,
        protected OrderPaymentRepositoryInterface $orderPaymentRepository,
        protected MileageTransactionRepositoryInterface $mileageLedger,
    ) {}

    /**
     * 환불을 실행하고 주문·결제의 환불 누적을 반영합니다.
     *
     * 장부(송금 계좌·결제행 취소 누적·주문 환불 누적)를 먼저 저장하고, PG 환불이 필요한
     * 결제수단이면 **마지막 단계로** 훅을 부른다(P-D5). PG 리스너는 결제행 누적에 이번 환불이
     * 이미 들어 있다고 보고 전액/부분 취소를 가르므로 저장된 값이 곧 그 입력이다. PG 가 실패하면
     * 이 메서드가 여는 트랜잭션(호출부 트랜잭션 안이면 savepoint)이 누적을 함께 되돌린다.
     *
     * 환불신청서 실행(`OrderRefundRequestService::executeRefund`)은 이 메서드를 쓰지 않는다 —
     * 그 경로는 취소신청서·결제행 정리까지 끝낸 뒤 PG 를 부르려고 `prepareLedger()` 와
     * `executePgRefund()` 를 따로 부른다. 이 메서드는 단독 호출(단위 테스트 등)의 공용 진입점이다.
     *
     * 취소(OrderCancellationService)는 이 메서드를 쓰지 않는다 — 취소는 orderUpdates 로
     * total_paid_amount 자체를 재계산값으로 덮어써 잔여 한도를 이미 좁히므로, 여기서
     * total_refunded_amount 까지 누적하면 OrderAdjustmentService 의 잔여 한도
     * (total_paid_amount - total_refunded_amount)가 같은 금액을 두 번 차감한다.
     * 반면 클레임 환불은 total_paid_amount 를 깎지 않으므로 이 누적이 유일한 근거다.
     * 누적 지점을 이 메서드 하나로 고정해 두 경로가 서로의 값을 덧쓰지 않게 한다.
     *
     * 쿠폰/마일리지/재고 복원은 여기서 부르지 않는다 — 클레임은 복원 시점이 환불 시점과
     * 다르다(반품 재고는 회수 도착 시 관리자 선택). 호출부가 자기 시점에 개별 호출한다.
     * 마일리지 반환·지급은 호출부가 **PG 보다 먼저** 부른다(D2) — 실패하면 PG 를 부르지 않는다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  환불 레코드
     * @param  AdjustmentResult  $result  계산 결과
     * @param  string|null  $reason  사유
     * @param  bool  $executePg  PG 환불 훅 실행 여부 (운영자 수동 환불이면 false)
     * @param  bool  $recordPaymentLedger  결제행의 취소 누적(`cancelled_amount`)을 쌓을지 여부.
     *                                     그 결제수단에서 **실제로 취소가 일어난** 환불만 true 다 (S1-D24).
     * @return void
     *
     * @throws \Exception PG 환불 실패 시
     */
    public function execute(
        Order $order,
        OrderRefund $refund,
        AdjustmentResult $result,
        ?string $reason = null,
        bool $executePg = true,
        bool $recordPaymentLedger = true,
    ): void {
        // 트랜잭션은 한 번만 시도한다(attempts=1) — 교착 재시도가 클로저를 다시 돌리면 PG 를 두 번 부른다.
        DB::transaction(function () use ($order, $refund, $result, $reason, $executePg, $recordPaymentLedger) {
            $this->prepareLedger($order, $refund, $result, $recordPaymentLedger);

            // PG사 선택이 필요한 결제수단(카드/가상계좌/계좌이체/휴대폰)만 PG 환불 훅에 진입한다.
            // 무통장입금(dbank)·포인트·예치금·무료는 PG 리스너가 인식하지 못해 success=false → 예외.
            if ($executePg && $order->payment && $result->refundAmount > 0
                && $order->payment->needsPgProvider()) {
                $this->executePgRefund($order, $refund, $result, $reason);
            } elseif ($executePg && $order->payment && $result->refundAmount > 0
                && $refund->refund_method === RefundMethodEnum::PG) {
                // 수단은 PG 환불인데 결제수단이 PG 를 거치지 않는다(무통장·예치금 등 — P-D4). 예전에는 PG 단계를
                // 건너뛰고 환불완료로 끝나, 돈은 어디로도 나가지 않았는데 「PG사 환불 완료」 로 남았다.
                // 운영자가 수단을 계좌 환불로 바꾸게 422 로 막는다.
                $messageKey = 'sirsoft-ecommerce::exceptions.pg_refund_unsupported';

                throw new OrderCancellationException(__($messageKey), $messageKey);
            }
        });
    }

    /**
     * PG 앞에서 장부를 준비합니다. (송금 계좌 반영 + 결제행 취소 누적 저장 + 주문 환불 누적)
     *
     * PG 는 호출부 트랜잭션의 마지막 단계다(P-D5). 그래서 장부를 PG 앞에서 **저장한다** — 실패한
     * 환불이 장부를 깎지 않게 하는 것은 이제 저장 순서가 아니라 트랜잭션 롤백이다. 그 보장이
     * 없으면 PG 실패 뒤에도 누적이 남으므로, 트랜잭션 밖에서 부르면 거절한다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  환불 레코드
     * @param  AdjustmentResult  $result  계산 결과
     * @param  bool  $recordPaymentLedger  결제행의 취소 누적을 쌓을지 (그 수단에서 실제 취소가 일어난 환불만 — S1-D24)
     * @return void
     *
     * @throws RefundLedgerOutsideTransactionException 트랜잭션 밖에서 호출했을 때
     */
    public function prepareLedger(Order $order, OrderRefund $refund, AdjustmentResult $result, bool $recordPaymentLedger): void
    {
        if (! $this->inTransaction()) {
            throw new RefundLedgerOutsideTransactionException;
        }

        $order->loadMissing('payment');

        // 송금 대상 계좌를 이 환불의 계좌로 맞춘다 — PG 환불 API 의 refundReceiveAccount 와
        // 무통장 수동 이체가 읽는 자리는 결제행 하나뿐이라, 구매자가 신청서에서 고친 계좌는
        // 실행 직전 여기에 반영되어야 실제 송금에 닿는다. 신청 접수만으로 덮어쓰지 않는 이유는
        // AppliesRefundBankAccount 독블록에 있다.
        $this->applyRefundBank($order, $this->refundBankInfoOf($refund));

        // 결제행은 PG 훅의 입력이기도 하다 — 리스너는 이 행을 읽고 "이번이 잔액 전부인가" 를
        // 판정해 전액취소/부분취소 코드를 고른다(NICE 의 `isPartial` 등). 누적을 채우지 않고 부르면
        // 마지막 잔액을 환불하는데도 취소액이 아직 0 이어서 "남은 돈이 있다" 로 보이고, PG 에는
        // **부분취소**로 나간다. 그래서 PG 앞에서 이번 환불을 누적해 저장한다.
        //
        // 그리고 그 누적은 **그 결제수단에서 실제로 취소가 일어난 환불에만** 쌓는다 (S1-D24).
        //
        // `cancelled_amount` 는 「이 결제수단에서 실제로 취소된 금액」이고, PG 환불가능액을
        // 되짚는 근거이자 대사의 기준이다. 카드 주문에 `계좌이체` 환불수단을 지정하면 PG
        // 왕복은 건너뛰는데(bank 이므로 당연) 이 누적만 깎여, 카드사 잔액은 그대로인 채
        // 장부의 환불가능액만 줄었다 — 이후 정상적인 카드 환불이 한도에 먼저 걸린다
        // (실측 1-P: 58,000 → 44,000, 토스 잔액은 58,000 그대로).
        //
        // 운영자가 카드 주문을 손으로 계좌 송금하는 운영은 막지 않는다(운영 결정 2026-09-16 —
        // 「허용하되 장부는 건드리지 않음」). 다만 그 송금은 **카드 취소가 아니므로** 카드
        // 결제행의 취소 누적이 아니다. 돈이 나간 사실은 주문 축(`total_refunded_amount`)과
        // 환불 레코드가 그대로 기록한다.
        //
        // 판정은 **호출부가 내린다**(`$recordPaymentLedger`). 여기서 `refund_method` 로 가르려
        // 했지만 그 값으로는 두 경우를 구분할 수 없다 — 카드 주문의 `BANK` 는 ① 운영자가 수단을
        // 계좌로 바꾼 경우이면서, ② PG 리스너가 없어 코어가 계좌로 폴백시킨 경우이기도 하다
        // (P14, `determineRefundMethod`). ② 는 **정상 취소**라 장부에 남아야 한다.
        // 호출부만이 자기 경로가 어느 쪽인지 안다.
        $paymentUpdates = $recordPaymentLedger && $order->payment !== null
            ? $this->buildCancelledAmountUpdates($order->payment, $result)
            : [];

        if ($paymentUpdates !== []) {
            $order->payment->fill($paymentUpdates);
            $this->orderPaymentRepository->savePendingChanges($order->payment);
        }

        $this->accumulateRefundTotals($order, $result);
    }

    /**
     * 지금 DB 트랜잭션 안인지 판정합니다. (prepareLedger 가드 — 테스트가 바꿔 끼울 수 있게 분리)
     *
     * @return bool 트랜잭션 안이면 true
     */
    protected function inTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /**
     * 이번 실행에서 PG 가 승인한 환불 기록을 꺼내고 비웁니다. (§14-C)
     *
     * @return array<string, mixed>|null PG 승인 기록 (없으면 null)
     */
    public function takePgSettlement(): ?array
    {
        $settled = $this->pgSettlement;
        $this->pgSettlement = null;

        return $settled;
    }

    /**
     * 남아 있는 PG 승인 기록을 버립니다. (실행 시작 전 초기화 — 앞선 실행의 값이 섞이지 않게)
     *
     * @return void
     */
    public function forgetPgSettlement(): void
    {
        $this->pgSettlement = null;
    }

    /**
     * 주문의 총 환불 누적을 갱신합니다.
     *
     * total_refunded_amount / total_refunded_points_amount 는 OrderAdjustmentService 가
     * 잔여 환불 한도(remainingPg / remainingPoints)의 상한으로 읽는 값이다.
     * 누적 규칙은 execute() 의 docblock 을 따른다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     * @return void
     */
    protected function accumulateRefundTotals(Order $order, AdjustmentResult $result): void
    {
        $order->update([
            'total_refunded_amount' => (float) $order->total_refunded_amount + (float) $result->refundAmount,
            'total_refunded_points_amount' => (float) $order->total_refunded_points_amount
                + (float) $result->refundPointsAmount,
        ] + $this->taxColumnUpdates($order, $result));
    }

    /**
     * 재계산된 과세·면세·부가세를 주문 헤더에 반영할 갱신값을 만듭니다. (S3-D17)
     *
     * **금액 컬럼은 건드리지 않는다** — `total_paid_amount` 를 깎으면 잔여 한도
     * (`total_paid_amount - total_refunded_amount`)에서 같은 금액을 두 번 차감한다
     * (execute() 독블록의 그 이유). 여기서 맞추는 것은 **세금 분류 세 컬럼뿐**이다.
     *
     * 왜 필요한가: `CashReceiptService::calculateIssuableAmount()` 가 이 컬럼들을 **그대로**
     * 읽고, 그 주석이 「지금 이 주문이 얼마짜리인가만 읽으면 된다」를 계약으로 명시한다.
     * 반품·환불 경로가 갱신하지 않으면 **전액 환불된 주문에 현금영수증이 발급 가능하다고
     * 답한다**(2026-09-16 실측: 주문 964 전액 환불 후에도 `issuable.amount = 62,000`).
     * 전액취소 경로는 이미 0 으로 내린다(S1-D22) — 반품·환불만 빠져 있었다.
     *
     * 재계산값이 없으면(출처 소실·취소 발 등) **아무것도 바꾸지 않는다** — 근거 없이
     * 세금 컬럼을 0 으로 만들면 멀쩡한 주문의 과세 근거가 사라진다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     * @return array<string, float> 갱신값 (근거가 없으면 빈 배열)
     */
    protected function taxColumnUpdates(Order $order, AdjustmentResult $result): array
    {
        // 쓰기 직전에 **화이트리스트로 거른다**(#599 validate P2 #8). 종전에는 「호출부가 이미
        // 골라 실었다」를 믿고 `orderUpdates` 를 통째로 썼는데, 그 전제가 깨지면(재계산 결과를
        // 그대로 넘기는 새 호출부) `total_paid_amount` 가 재계산값으로 덮여 잔여 한도가 두 번
        // 깎인다 — 오류 없이 다음 환불이 0 원이 된다. 목록은 `TAX_SYNC_COLUMNS` 하나가 정본이다.
        return array_intersect_key($result->orderUpdates, array_flip(self::TAX_SYNC_COLUMNS));
    }

    /**
     * 환불 레코드가 든 환불 계좌를 돌려줍니다. (세 값이 모두 있을 때만)
     *
     * 일부만 채워 반영하면 은행만 바뀌고 계좌번호는 옛 값으로 남은 조합이 송금 대상이 된다.
     *
     * @param  OrderRefund  $refund  환불 레코드
     * @return array{bank_code: string, account_number: string, holder: string}|null 환불 계좌
     */
    protected function refundBankInfoOf(OrderRefund $refund): ?array
    {
        $bankCode = (string) ($refund->refund_bank_code ?? '');
        $account = (string) ($refund->refund_bank_account ?? '');
        $holder = (string) ($refund->refund_bank_holder ?? '');

        if ($bankCode === '' || $account === '' || $holder === '') {
            return null;
        }

        return [
            'bank_code' => $bankCode,
            'account_number' => $account,
            'holder' => $holder,
        ];
    }

    /**
     * 환불 수단을 결정합니다.
     *
     * 확장 결제수단(간편결제 등)도 카탈로그 선언을 통해 올바른 환불수단으로 해석된다.
     * enum match 로 두면 확장 ID 가 default → BANK 로 떨어져 카드 취소가 누락된다(#475).
     * 취소와 클레임이 같은 규칙으로 환불 수단을 정하도록 이 서비스가 SSoT 다.
     *
     * @param  Order  $order  주문
     * @return RefundMethodEnum 환불 수단
     */
    public function determineRefundMethod(Order $order): RefundMethodEnum
    {
        if (! $order->payment) {
            return RefundMethodEnum::BANK;
        }

        $method = $order->payment->refundMethod();

        // 결제 플러그인이 제거된 뒤의 주문 (P14).
        //
        // 플러그인을 지우면 `payment.refund` 훅을 구독하던 리스너가 사라진다. 그런데 결제행은
        // 여전히 "PG 환불" 이라고 말하므로 코어는 PG 경로로 들어가고, 아무도 응답하지 않아
        // `success=false` → 예외 → 트랜잭션 롤백이 된다. 운영자는 **그 주문의 결제완료 건을
        // 영영 취소할 수 없고**, 화면에는 원인 없는 오류만 뜬다.
        //
        // 리스너가 없으면 계좌 환불로 떨어뜨린다 — 운영자가 손으로 송금하고 [환불하기]로
        // 완료를 찍는 경로는 살아 있다. 조용히 바꾸지 않고 로그를 남겨 이유가 드러나게 한다.
        if ($method === RefundMethodEnum::PG && ! $this->hasPgRefundListener($order)) {
            Log::warning('PG 환불 리스너 없음 — 계좌 환불로 폴백', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => (string) $order->payment->payment_method,
                'pg_provider' => $order->payment->pg_provider,
            ]);

            return RefundMethodEnum::BANK;
        }

        return $method;
    }

    /**
     * 클레임 환불신청서에 기록할 환불수단을 정합니다. (결함 4)
     *
     * 돌려줄 현금이 남지 않은 회원 주문(마일리지로 전액 결제)은 결제수단과 무관하게 「마일리지」다.
     * 고객은 마일리지 결제수단을 고를 수 없어 그런 주문도 카드·무통장·가상계좌·무료 수단을 단 채 결제완료로
     * 끝난다 — 결제수단만 보면 계좌·PG 환불로 기록되어 계좌를 묻거나 승인에서 멈추는데, 실제로 나갈 돈은
     * 쓴 마일리지뿐이다. 그 밖에는 결제수단의 환불수단(`determineRefundMethod`)을 따른다.
     *
     * @param  Order  $order  주문
     * @return RefundMethodEnum 환불수단
     */
    public function refundMethodForClaim(Order $order): RefundMethodEnum
    {
        return $this->refundsOnlyMileage($order)
            ? RefundMethodEnum::POINTS
            : $this->determineRefundMethod($order);
    }

    /**
     * 이 주문에서 돌려줄 현금(결제수단 몫)이 남아 있는지 판정합니다. (계좌 요구 · 환불수단 · 자동 전이 공용)
     *
     * 금액 엔진의 현금 상한(`total_paid_amount − total_refunded_amount`)이 0 이면 어떤 신청도 현금 0원이다.
     * 신청 시점 미리보기가 아니라 **주문 장부**로 판정한다 — 신청 화면은 상품을 고르기 전에 계좌 칸을 그릴지
     * 정해야 하고, 신청서 단위로 재면 현금이 남은 혼합 주문의 마일리지 우선 부분취소가 「마일리지」로 기록돼
     * 뒤에 현금을 올리면 그 현금이 어디로도 보내지지 않는다.
     *
     * 결제행 수납액도 함께 본다 — 수동 결제완료로 헤더가 0 인 채 결제된 주문을 0원으로 오판하지 않게 한다.
     * 단 결제가 실제로 끝난 결제행(`paid`·`partial_cancelled`)만 본다(00 ⑭): 결제행은 생성 때부터
     * `paid_amount_base` 에 결제예정금액이 들어 있어, 상태를 보지 않으면 결제금액 기록을 끈 수동 결제완료
     * 주문을 「현금 있음」으로 읽는다. 비교는 decimal 문자열 `bccomp`(소수 4자리) — 0.1 + 0.2 같은 부동소수
     * 오차가 「돌려줄 돈 있음」이 되지 않게 한다(`CancelOrderRequest::hasMoneyToReturn` 과 같은 이유).
     *
     * 결제 전 주문은 받은 돈이 없다 — 단 입금이 결제행에 기록된 주문은 예외다(`hasRecordedDepositBeforePayment`, S3-D7).
     *
     * @param  Order  $order  주문
     * @return bool 돌려줄 현금이 남아 있으면 true
     */
    public function hasCashToReturn(Order $order): bool
    {
        if ($order->order_status instanceof OrderStatusEnum && $order->order_status->isBeforePayment()
            && ! $this->hasRecordedDepositBeforePayment($order)) {
            return false;
        }

        if (bccomp($this->decimal($order->total_paid_amount), $this->decimal($order->total_refunded_amount), 4) > 0) {
            return true;
        }

        $order->loadMissing('payment');
        $payment = $order->payment;

        if ($payment === null
            || ! in_array($payment->payment_status, [PaymentStatusEnum::PAID, PaymentStatusEnum::PARTIAL_CANCELLED], true)) {
            return false;
        }

        return bccomp($this->decimal($payment->paid_amount_base), $this->decimal($payment->cancelled_amount), 4) > 0;
    }

    /**
     * 결제 전 주문인데 결제행에 입금이 기록돼 있는지 판정합니다. (S3-D7 — 받은 돈이 있는 결제 전 취소)
     *
     * 무통장 [입금확인]에서 「주문을 결제완료로 처리」를 끄면 결제행만 입금완료(`paid`)가 되고 주문은 결제대기로 남는다
     * (`OrderProcessingService::recordDepositPayment`). 주문 상태만 보면 「결제 전 = 받은 돈 없음」이 되어, 그 주문을
     * 취소할 때 환불신청서 없이 취소만 기록되고 받은 돈이 장부에서 사라졌다. 결제행 상태가 「돈을 받았다」를
     * 말하면 결제 전이라도 돌려줄 돈이 있는 주문으로 본다 — 앞선 부분취소로 일부를 돌려준 결제행(`partial_cancelled`)도 같다.
     *
     * 결제행의 `paid_amount_base` 는 주문 생성 때부터 결제예정금액이 들어 있어 금액만으로는 판정할 수 없다 — 상태로 본다.
     *
     * @param  Order  $order  주문
     * @return bool 결제 전이면서 결제행에 입금이 기록돼 있으면 true
     */
    public function hasRecordedDepositBeforePayment(Order $order): bool
    {
        if (! $order->order_status instanceof OrderStatusEnum || ! $order->order_status->isBeforePayment()) {
            return false;
        }

        $order->loadMissing('payment');

        return in_array(
            $order->payment?->payment_status,
            [PaymentStatusEnum::PAID, PaymentStatusEnum::PARTIAL_CANCELLED],
            true,
        );
    }

    /**
     * 마일리지로만 돌려주는 주문인지 판정합니다. (현금 0원 + 남은 사용 마일리지 > 0 — 환불수단 「마일리지」 근거)
     *
     * 비회원은 마일리지를 쓸 수 없으므로 언제나 false 다.
     *
     * @param  Order  $order  주문
     * @return bool 마일리지로만 돌려주는 회원 주문이면 true
     */
    public function refundsOnlyMileage(Order $order): bool
    {
        return $order->user_id !== null
            && ! $this->hasCashToReturn($order)
            && bccomp($this->decimal($order->total_points_used_amount), $this->decimal($order->total_refunded_points_amount), 4) > 0;
    }

    /**
     * 금액을 bccomp 용 decimal 문자열로 바꿉니다. (null 은 0)
     *
     * @param  mixed  $value  금액
     * @return string 소수 4자리 문자열
     */
    private function decimal(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }

    /**
     * 이 주문의 PG 환불에 응답할 리스너가 실제로 등록돼 있는지 판정합니다. (P14)
     *
     * `payment.refund` 는 필터 훅이라 구독자가 없으면 기본값(`success=false`)이 그대로
     * 돌아온다 — 실패와 미등록을 결과만으로 구분할 수 없다. 그래서 **부르기 전에** 등록
     * 여부를 본다.
     *
     * @param  Order  $order  주문
     * @return bool 리스너가 있으면 true
     */
    public function hasPgRefundListener(Order $order): bool
    {
        // 1차 판정은 구독자 유무다 — 아무도 응답하지 않는 명백한 경우(플러그인 제거)를 잡는다.
        if (empty(HookManager::getFilters()['sirsoft-ecommerce.payment.refund'] ?? [])) {
            return false;
        }

        // 2차 판정은 **이 주문의 결제 플러그인**이 켜져 있는가다(P-D6). 다른 PG 플러그인 하나만 켜져 있어도
        // 구독자는 있으므로, 1차 판정만으로는 꺼진 플러그인의 주문이 PG 환불로 접수돼 실행 때 실패했다.
        // 켜진 플러그인이 등록한 제공자 목록(`registered_pg_providers`)에 결제행의 제공자가 있는지 본다.
        // 목록이 비어 있으면(제공자를 등록하지 않는 확장 리스너만 있는 구성) 1차 판정을 따른다 — 제공자 등록
        // 없이 환불만 처리하는 리스너를 거짓 양성으로 막지 않기 위해서다.
        $providerId = (string) ($order->payment?->pg_provider ?? '');
        if ($providerId === '') {
            return true;
        }

        $registered = HookManager::applyFilters('sirsoft-ecommerce.payment.registered_pg_providers', []);
        if (! is_array($registered) || $registered === []) {
            return true;
        }

        return in_array($providerId, array_map(fn ($p) => (string) ($p['id'] ?? ''), $registered), true);
    }

    /**
     * 환불 상세(옵션별) 레코드의 필드 배열을 만듭니다.
     *
     * 취소 발 환불과 클레임 발 환불이 같은 안분 규칙(원 옵션 할인 비율 기반 차감)을 쓰도록
     * 계산을 한 곳에 둔다. 반환값에는 order_refund_id 가 없다 — 그 값은 환불 레코드를 만든
     * 호출부가 채운다.
     *
     * @param  Order  $order  주문
     * @param  array  $items  대상 아이템 [{order_option_id, cancel_quantity}]
     * @param  AdjustmentResult  $result  계산 결과
     * @return array<int, array<string, mixed>> 환불 옵션 행 배열
     */
    public function buildRefundOptionRows(Order $order, array $items, AdjustmentResult $result): array
    {
        $order->loadMissing('options');

        $rows = [];

        foreach ($items as $item) {
            $option = $order->options->find($item['order_option_id']);

            if (! $option) {
                continue;
            }

            // adjustedItems 에서 해당 옵션의 취소 금액 조회
            $adjustedItem = collect($result->adjustedItems)
                ->firstWhere('order_option_id', $item['order_option_id']);

            $quantity = (int) $item['cancel_quantity'];

            // 단가는 **한 가지**로 센다 — 재계산이 적은 회계 단가(`adjustedItems` — 교환 후속 행이면
            // 신규 옵션 단가)가 있으면 그것을, 없으면 행의 저장 단가를 쓴다. 종전에는 소계는 저장
            // 단가로, 취소액은 회계 단가 × **재계산이 본 수량**으로 따로 세어, 저가 교환 후속 행이나
            // 누적 재계산(반품 2회차)에서 한 행 안의 단가·소계·환불액이 서로 다른 값을 말했다
            // (#599 validate P2 minor).
            $adjustedQuantity = (int) ($adjustedItem['cancel_quantity'] ?? 0);
            $unitPrice = $adjustedItem !== null && $adjustedQuantity > 0 && isset($adjustedItem['cancel_amount'])
                ? (float) $adjustedItem['cancel_amount'] / $adjustedQuantity
                : (float) $option->unit_price;
            $subtotalAmount = $unitPrice * $quantity;

            // 할인 차감분 계산 (원래 옵션 할인 비율 기반)
            $discountAmount = 0;
            if ($option->subtotal_price > 0) {
                $discountRatio = (float) $option->subtotal_discount_amount / (float) $option->subtotal_price;
                $discountAmount = round($subtotalAmount * $discountRatio, 2);
            }

            $refundAmount = $subtotalAmount - $discountAmount;

            $rows[] = [
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'option_status' => RefundStatusEnum::REQUESTED,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal_amount' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                'refund_amount' => max(0, $refundAmount),
            ];
        }

        return $rows;
    }

    /**
     * 결제행의 취소 누적 컬럼 갱신값을 만듭니다.
     *
     * base 통화 누적(cancelled_amount)과 결제 통화 누적(mc_cancelled_amount)은 별개다.
     * base ≠ 결제 통화일 때 PG 실환불·누적은 결제 통화로 정합되어야 한다.
     *
     * @param  OrderPayment  $payment  결제행
     * @param  AdjustmentResult  $result  계산 결과
     * @return array<string, mixed> 갱신값 (cancelled_amount / mc_cancelled_amount)
     */
    public function buildCancelledAmountUpdates(OrderPayment $payment, AdjustmentResult $result): array
    {
        $mcCancelled = $payment->mc_cancelled_amount ?? [];
        foreach ($result->mcRefundAmount ?? [] as $code => $amount) {
            $mcCancelled[$code] = (float) ($mcCancelled[$code] ?? 0) + (float) $amount;
        }

        return [
            'cancelled_amount' => (float) $payment->cancelled_amount + $result->refundAmount,
            'mc_cancelled_amount' => $mcCancelled,
        ];
    }

    /**
     * 환불액을 결제 통화(order_currency)로 환산해 반환합니다.
     *
     * 계산 단계에서 스냅샷 환율로 산출한 mc_refund_amount[order_currency] 를 우선 사용하고,
     * 없으면(결제 통화 환율 누락 등) base refundAmount 로 폴백합니다.
     *
     * @param  AdjustmentResult  $result  환불 계산 결과
     * @param  string|null  $orderCurrency  결제 통화 코드
     * @return float 결제 통화 환산 환불액
     */
    public function resolveOrderCurrencyRefundAmount(AdjustmentResult $result, ?string $orderCurrency): float
    {
        if ($orderCurrency !== null && isset($result->mcRefundAmount[$orderCurrency])) {
            return (float) $result->mcRefundAmount[$orderCurrency];
        }

        return (float) $result->refundAmount;
    }

    /**
     * PG 환불을 실행합니다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  환불 레코드
     * @param  AdjustmentResult  $result  계산 결과
     * @param  string|null  $reason  사유
     * @return void
     *
     * @throws \Exception PG 환불 실패 시
     */
    public function executePgRefund(
        Order $order,
        OrderRefund $refund,
        AdjustmentResult $result,
        ?string $reason,
    ): void {
        $previousStatus = $refund->refund_status instanceof RefundStatusEnum
            ? $refund->refund_status->value
            : (string) $refund->refund_status;

        $refund->update(['refund_status' => RefundStatusEnum::PROCESSING]);

        // 이 전이는 예전에 어떤 줄도 남기지 않았다(B1). PG 왕복 도중 예외로 끊기면 신청서는
        // `processing` 에 멈추는데, 그 상태로 들어간 경위가 이력에 없어 운영자는 "승인에서
        // 갑자기 처리중이 된" 신청서를 보게 된다. 알림은 억제한다 — 고객에게는 완료 1건만
        // 알리는 것이 §4-2 의 규율이고, 처리중은 내부 단계다.
        HookManager::doAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            $refund->fresh(),
            $previousStatus,
            RefundStatusEnum::PROCESSING->value,
            ['suppress_notification' => true],
        );

        // PG 실환불 금액은 결제 통화(order_currency)여야 한다. base≠결제 통화일 때 base 금액을 그대로
        // 전송하면 PG 가 결제 통화 단위로 오인해 실환불액이 틀어진다(예: base JPY 500 → KRW 500 환불).
        // 호출부가 이미 mc_cancelled_amount 를 결제 통화로 누적했으므로, 리스너는 결제 통화 기준
        // (paid_amount_local / mc_cancelled_amount[order_currency])으로 부분취소 누적을 계산한다.
        $orderCurrency = $order->payment->currency ?: ($order->currency_snapshot['order_currency'] ?? null);
        $refundLocal = $this->resolveOrderCurrencyRefundAmount($result, $orderCurrency);

        // $refund 는 이 환불 시도의 고유 레코드다. PG 리스너가 멱등키(Idempotency-Key)를 조립할 때
        // 사용한다 — 네트워크 재시도로 같은 취소가 두 번 도달해도 PG 가 중복 청구를 차단한다.
        // 인자 추가는 하위호환이다: 기존 리스너(5-파라미터)는 초과 인자를 그대로 무시한다.
        // `handled` 는 「이 주문의 결제 플러그인이 응답했는가」 다(P-D6). 리스너는 자기 결제일 때만 true 로 바꾼다 —
        // 다른 PG 플러그인이 구독만 하고 있으면 결과는 기본값 그대로 돌아오고, 그것은 PG 거절이 아니라
        // 「이 주문의 결제 플러그인이 꺼져 있다」 는 뜻이다.
        $pgResult = HookManager::applyFilters(
            'sirsoft-ecommerce.payment.refund',
            ['success' => false, 'handled' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null],
            $order,
            $order->payment,
            $refundLocal,
            $reason,
            $refund
        );

        if (! empty($pgResult['success'])) {
            // 돈이 나간 사실을 **가장 먼저** 메모리에 남긴다 — 아래 UPDATE 나 커밋이 실패해 장부가
            // 롤백되면 호출부가 이 값으로 critical 로그와 재실행 표식을 남긴다(§14-C).
            $this->pgSettlement = [
                'refund_id' => (int) $refund->id,
                'refund_number' => $refund->refund_number,
                'order_id' => (int) $order->id,
                'order_number' => $order->order_number,
                'transaction_id' => $pgResult['transaction_id'] ?? null,
                'amount_local' => $refundLocal,
                'currency' => $orderCurrency,
            ];

            $refund->update([
                'pg_transaction_id' => $pgResult['transaction_id'] ?? null,
                'refund_status' => RefundStatusEnum::COMPLETED,
                'refunded_at' => Carbon::now(),
            ]);
        } else {
            $refund->update([
                'pg_error_code' => $pgResult['error_code'] ?? null,
                'pg_error_message' => $pgResult['error_message'] ?? null,
            ]);

            // 실패 근거는 **애플리케이션 로그에 남긴다**(P13). 위 `pg_error_code/message` 갱신은
            // 곧 이어지는 예외가 트랜잭션째 롤백하므로 DB 에 남지 않는다 — 타임아웃이면 돈은
            // 나갔는데 주문은 정상으로 복구되고, 그 사실을 가리키는 근거가 로그 말고는 없다.
            // 주문·환불 번호를 함께 적어 운영자가 PG 콘솔과 대조할 수 있게 한다.
            Log::error('PG 환불 실패', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'refund_id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'refund_amount_local' => $refundLocal,
                'currency' => $orderCurrency,
                'error_code' => $pgResult['error_code'] ?? null,
                'error_message' => $pgResult['error_message'] ?? null,
            ]);

            // 키·파라미터를 함께 싣는다 — 호출부(회원·비회원 면)는 번역문이 아니라 키로 응답을
            // 조립하므로, 키가 없으면 PG 거절 사유가 뭉뚱그린 실패 안내로 사라진다.
            // 아무 리스너도 자기 결제로 받지 않았으면 PG 거절이 아니라 플러그인 비활성이다(P-D6) — 사유와 해결책을
            // 따로 안내한다. 결과를 새 배열로 돌려주는 옛 리스너(`handled` 키 없음)는 응답한 것으로 본다.
            $unhandled = ($pgResult['handled'] ?? true) === false;
            $messageKey = $unhandled
                ? 'sirsoft-ecommerce::exceptions.pg_provider_inactive'
                : 'sirsoft-ecommerce::exceptions.pg_refund_failed';
            $messageParams = $unhandled
                ? ['provider' => (string) ($order->payment->pg_provider ?? '')]
                : ['error' => $pgResult['error_message'] ?? 'Unknown error'];

            throw new OrderCancellationException(__($messageKey, $messageParams), $messageKey, $messageParams);
        }
    }

    /**
     * 쿠폰을 복원합니다.
     *
     * 실패는 삼키지 않는다(F1·D2) — 삼키면 환불은 끝났는데 쿠폰은 사용됨으로 남는다. 호출부
     * 트랜잭션 전체가 되돌아가고 운영자는 사유를 보고 다시 시도한다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     * @return void
     *
     * @throws OrderCancellationException 복원 실패
     */
    public function restoreCoupons(Order $order, AdjustmentResult $result): void
    {
        if (! empty($result->restoredCouponIssueIds)) {
            try {
                HookManager::doAction(
                    'sirsoft-ecommerce.coupon.restore',
                    $order,
                    $result->restoredCouponIssueIds
                );
            } catch (\Throwable $e) {
                Log::error('쿠폰 복원 실패 — 처리를 중단합니다', [
                    'order_id' => $order->id,
                    'coupon_ids' => $result->restoredCouponIssueIds,
                    'error' => $e->getMessage(),
                ]);

                throw $this->sideEffectFailure($e, 'sirsoft-ecommerce::exceptions.coupon_restore_failed');
            }
        }
    }

    /**
     * 마일리지를 복원합니다.
     *
     * 멱등 축은 두 갈래다 — 취소 발 복원은 `order_cancel_id`, 클레임 환불 실행(S6b)의 사용
     * 마일리지 환급은 `order_refund_id`. 복원 리스너가 취소 축을 우선 해석하므로 두 축을
     * 동시에 넘기지 않는다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     * @param  OrderCancel|null  $orderCancel  취소 레코드 (취소 발 복원 멱등 기준)
     * @param  OrderRefund|null  $orderRefund  환불 레코드 (클레임 환불 실행 멱등 기준)
     * @return void
     *
     * @throws OrderCancellationException 반환 실패 (호출부 트랜잭션 전체 롤백 — D2)
     */
    public function restoreMileage(
        Order $order,
        AdjustmentResult $result,
        ?OrderCancel $orderCancel = null,
        ?OrderRefund $orderRefund = null,
    ): void {
        // 실제 차감이 일어난 주문만 복원한다. 결제수단별 차감 시점이 payment_complete 인데
        // 결제 전 취소되어 마일리지가 차감된 적 없는 주문(is_mileage_deducted=false)은 복원 대상이 아니다.
        // 가드가 없으면 사용 의도(total_points_used_amount)만으로 신규 lot 이 발행되어 유령 적립이 발생한다.
        if (! $order->is_mileage_deducted) {
            return;
        }

        if ($result->refundPointsAmount > 0) {
            // 실패를 삼키지 않는다(D2) — 예전에는 PG 가 카드를 취소한 뒤 여기서 실패해도 경고만 남기고
            // 「완료」로 확정해, 카드는 취소됐는데 마일리지는 돌아오지 않았다. 이제 호출부는 이 반환을
            // PG 보다 먼저 부르고, 실패하면 PG 를 부르지 않은 채 전체를 되돌린다.
            try {
                HookManager::doAction(
                    'sirsoft-ecommerce.mileage.restore',
                    $result->refundPointsAmount,
                    $order,
                    $orderCancel?->id,
                    $orderRefund?->id
                );
            } catch (\Throwable $e) {
                Log::error('마일리지 반환 실패 — 환불·취소를 중단합니다', [
                    'order_id' => $order->id,
                    'order_cancel_id' => $orderCancel?->id,
                    'order_refund_id' => $orderRefund?->id,
                    'points_amount' => $result->refundPointsAmount,
                    'error' => $e->getMessage(),
                ]);

                throw $this->sideEffectFailure($e, 'sirsoft-ecommerce::exceptions.mileage_refund_failed');
            }
        }
    }

    /**
     * 환불 현금분을 회원 마일리지로 지급합니다. (환불수단 「마일리지로 환불」 — D1)
     *
     * 현금(PG·계좌)으로 나갈 환불액을 회원 원장에 무기한 lot 으로 지급한다. 같은 환불의 쓴 마일리지
     * 반환(`restoreMileage`)과 축이 달라 함께 생길 수 있다 — 호출부는 반환을 먼저, 지급을 나중에 부른다.
     *
     * 지급 기록은 **원장으로 확인한다**(fail-closed) — 구독자가 없거나 조용히 건너뛰면 「완료」인데 돈이
     * 없는 상태(refund 274)가 되풀이되므로, 훅이 끝난 뒤 그 환불의 지급 행이 없으면 실패로 올린다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  환불신청서
     * @param  float  $amount  지급액 (기준 통화 현금 환불액)
     * @return void
     *
     * @throws OrderClaimException 비회원 주문 (호출부가 먼저 막는 방어선)
     * @throws OrderCancellationException 지급 실패·원장 기록 없음 (호출부 트랜잭션 전체 롤백)
     */
    public function creditMileage(Order $order, OrderRefund $refund, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        if ($order->user_id === null) {
            throw OrderClaimException::refundPointsRequireMember();
        }

        try {
            HookManager::doAction('sirsoft-ecommerce.mileage.refund_credit', $amount, $order, (int) $refund->id);
        } catch (\Throwable $e) {
            Log::error('환불 마일리지 지급 실패 — 환불을 중단합니다', [
                'order_id' => $order->id,
                'order_refund_id' => $refund->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            throw $this->sideEffectFailure($e, 'sirsoft-ecommerce::exceptions.mileage_refund_failed');
        }

        if (! $this->mileageLedger->existsCreditForRefund((int) $refund->id)) {
            Log::error('환불 마일리지 지급 기록 없음 — 응답한 리스너가 없습니다', [
                'order_id' => $order->id,
                'order_refund_id' => $refund->id,
                'amount' => $amount,
            ]);

            throw $this->sideEffectFailure(null, 'sirsoft-ecommerce::exceptions.mileage_refund_failed');
        }
    }

    /**
     * 돈·권리 부수효과의 실패를 환불·취소를 중단시키는 예외로 바꿉니다. (D2)
     *
     * 이미 취소 예외면(리스너가 자기 키로 던진 경우) 그대로 둔다 — 감싸면 원래 사유가 사라진다.
     *
     * @param  \Throwable|null  $previous  원인 예외
     * @param  string  $messageKey  다국어 키 (공개 면에 그대로 안내해도 되는 문장 — `:error` 없음)
     * @return OrderCancellationException 호출부 트랜잭션을 롤백시킬 예외
     */
    protected function sideEffectFailure(?\Throwable $previous, string $messageKey): OrderCancellationException
    {
        if ($previous instanceof OrderCancellationException) {
            return $previous;
        }

        return new OrderCancellationException(__($messageKey), $messageKey);
    }

    /**
     * 재고를 복원합니다.
     *
     * 실패는 삼키지 않는다(F2·D2) — 삼키면 환불은 끝났는데 재고가 돌아오지 않는다. 설정 꺼짐·
     * 미차감 옵션은 실패가 아니라 건너뛸 대상이다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  복원 대상 (order_option_id / cancel_quantity)
     * @return void
     *
     * @throws OrderCancellationException 복원 실패
     */
    public function restoreStock(Order $order, array $cancelItems): void
    {
        $shouldRestore = $this->settingsService->getSetting(
            'order_settings.stock_restore_on_cancel',
            true
        );

        if (! $shouldRestore) {
            return;
        }

        foreach ($cancelItems as $item) {
            $option = $order->options->find($item['order_option_id']);
            if (! $option || ! $option->is_stock_deducted) {
                continue;
            }

            try {
                // 복원 + is_stock_deducted 플래그 정리를 한 트랜잭션에서 원자적으로 처리.
                // (재고 리스너 제거로 플래그 리셋 주체가 Service 경로로 단일화됨)
                $this->stockService->restoreOptionStockForOrderOption(
                    $option,
                    $item['cancel_quantity']
                );
            } catch (\Throwable $e) {
                Log::error('재고 복원 실패 — 처리를 중단합니다', [
                    'order_id' => $order->id,
                    'option_id' => $option->id,
                    'quantity' => $item['cancel_quantity'],
                    'error' => $e->getMessage(),
                ]);

                throw $this->sideEffectFailure($e, 'sirsoft-ecommerce::exceptions.stock_restore_failed');
            }
        }
    }

    /**
     * 환불 확정 후 현금영수증을 현재 금액에 맞춰 동기화합니다.
     *
     * 발급 이력이 없으면 no-op 이고, 잔여 발급액이 0 이면 전액취소만 수행하며,
     * 그 외에는 전체취소 후 잔여 과세금액 기준으로 재발급한다 (부분취소 API 미사용).
     *
     * 어떤 실패도 호출부로 전파하지 않는다 — 환불은 이미 DB 에 확정됐고 되돌릴 수 없다.
     * 실패는 이력 테이블(issue_status=FAILED)과 로그에 남아 관리자 수동 재발급으로 복구된다.
     *
     * 로그 레벨이 error 인 이유 — CashReceiptService 는 예상 가능한 실패(프로바이더가 success=false
     * 반환, 복호화 불가)를 스스로 삼키고 warning + FAILED 이력으로 남긴다. 따라서 여기까지 예외가
     * 올라왔다면 리스너가 계약을 어긴 것이고, 프로바이더 쪽 영수증이 실제로 취소됐는지조차 알 수 없다
     * (국세청 기록과 DB 가 어긋날 수 있는 상태). 즉시 사람이 개입해야 하므로 PG 환불 실패와 같은
     * error 로 둔다. 재고·쿠폰·마일리지 복원과 달리 삼키는 이유는 하나다 — 이 동기화는 환불이 커밋된
     * 뒤·트랜잭션 밖에서 돌아 되돌릴 대상이 없고, 실패 이력과 수동 재발급이 복구 경로다.
     *
     * @param  Order  $order  환불이 반영된 주문
     * @param  string|null  $reason  사유
     * @return void
     */
    public function syncCashReceipt(Order $order, ?string $reason): void
    {
        try {
            $this->cashReceiptService->syncFromOrder($order, $reason);
        } catch (\Throwable $e) {
            // 식별번호·PG raw 응답은 담지 않는다 (개인정보 로그 미노출).
            Log::error('현금영수증 동기화 실패 — 주문 취소는 확정됨 (관리자 수동 재발급 필요)', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
