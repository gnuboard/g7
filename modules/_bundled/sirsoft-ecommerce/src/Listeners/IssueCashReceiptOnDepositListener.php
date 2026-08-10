<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 입금완료 시 현금영수증 자동 발급 리스너 (D11)
 *
 * 무통장(dbank) 주문의 입금이 확인되는 두 경로를 모두 구독한다:
 *   - order.after_payment_complete : 관리자 결제완료 전이 · 가상계좌 웹훅
 *   - order.after_deposit_recorded : 관리자 "입금만 기록"(결제완료 전이 없이 payment 만 PAID)
 *
 * 두 번째 훅이 없으면 "입금만 기록" 경로에서 자동발급이 통째로 누락된다.
 * 두 훅이 한 입금에 모두 도달할 수 있으나 CashReceiptService::issue() 의 활성 영수증 가드가 멱등을 보장한다.
 *
 * 큐 처리(sync 미지정 = 기본 큐)를 유지한다. 이 리스너는 외부 발급 API 를 호출하므로
 * 입금확인 트랜잭션을 외부 지연·실패에 묶으면 입금확인 자체가 실패한다.
 * 발급 실패는 이력 테이블에 FAILED 로 남아 관리자 화면의 수동 재발급 경로로 복구된다.
 */
class IssueCashReceiptOnDepositListener implements HookListenerInterface
{
    /**
     * @param  CashReceiptService  $cashReceiptService  현금영수증 발급 서비스
     * @param  EcommerceSettingsService  $settingsService  이커머스 환경설정 서비스
     * @param  OrderPaymentRepositoryInterface  $paymentRepository  주문 결제 Repository
     */
    public function __construct(
        protected CashReceiptService $cashReceiptService,
        protected EcommerceSettingsService $settingsService,
        protected OrderPaymentRepositoryInterface $paymentRepository,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 구독 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.order.after_payment_complete' => [
                'method' => 'handleDeposit',
                'priority' => 50,
            ],
            'sirsoft-ecommerce.order.after_deposit_recorded' => [
                'method' => 'handleDeposit',
                'priority' => 50,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리하므로 빈 구현
    }

    /**
     * 입금이 확인된 주문에 현금영수증을 발급합니다.
     *
     * 두 훅의 인자 수가 다르므로(after_deposit_recorded 는 $amount 를 추가로 넘긴다)
     * 첫 인자만 사용하고 나머지는 무시한다.
     *
     * @param  Order  $order  입금이 확인된 주문
     */
    public function handleDeposit(Order $order): void
    {
        try {
            $order->loadMissing('payment');

            // 발급 가능 조건 단일 가드 — 무통장·결제완료·미발급·프로바이더 설정·발급액>0.
            // 발급액 검사가 "무통장 + 전액 마일리지" 0원 주문을 걸러낸다: $isZeroPayable 판정은
            // 결제수단과 무관하게 잔여 결제액만 보므로 그 주문도 after_payment_complete 를 발화시킨다.
            $blocker = $this->cashReceiptService->resolveIssueBlocker($order);

            if ($blocker !== null) {
                return;
            }

            $request = $this->resolveIssueRequest($order);

            // 구매자가 신청하지 않았고 자진발급도 꺼져 있으면 발급하지 않는다.
            if ($request === null) {
                return;
            }

            [$type, $identifier, $identifierType] = $request;

            $receipt = $this->cashReceiptService->issue($order, $type, $identifier, $identifierType);

            if (! $receipt->isCompletedIssue()) {
                Log::warning('IssueCashReceiptOnDepositListener: 자동 발급 실패 — 관리자 수동 발급 필요', [
                    'order_id' => $order->id,
                    'error_code' => $receipt->error_code,
                ]);
            }
        } catch (\Throwable $e) {
            // 큐 재시도로 중복 발급되지 않도록(멱등 가드는 있으나) 예외를 삼키고 로그만 남긴다.
            Log::error('IssueCashReceiptOnDepositListener: 자동 발급 처리 중 예외', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 발급에 사용할 용도·식별번호를 결정합니다.
     *
     * 구매자가 주문 시 신청했으면 저장된 신청 정보(암호문 복호화)를 쓰고,
     * 신청하지 않았으면 자진발급 설정이 켜진 경우에만 국세청 지정번호로 소득공제 발급한다.
     * 자진발급은 제도상 소득공제용만 가능하다.
     *
     * @param  Order  $order  주문 모델
     * @return array{0: CashReceiptType, 1: string, 2: CashReceiptIdentifierType|null}|null 발급 인자 (발급 대상 아니면 null)
     */
    private function resolveIssueRequest(Order $order): ?array
    {
        $payment = $order->payment;

        if ($payment?->is_cash_receipt_requested) {
            $identifier = $this->paymentRepository->resolveCashReceiptIdentifier($payment);
            $type = $payment->getCashReceiptType();

            // 신청은 했으나 식별번호를 복호화할 수 없으면 자진발급으로 대체하지 않는다.
            // 구매자가 지정한 식별번호로만 발급해야 하므로 관리자 수동 발급 경로로 남긴다.
            if ($identifier === null || $type === null) {
                Log::warning('IssueCashReceiptOnDepositListener: 신청 정보 복호화 불가 — 관리자 수동 발급 필요', [
                    'order_id' => $order->id,
                ]);

                return null;
            }

            return [$type, $identifier, $payment->cash_receipt_identifier_type];
        }

        if (! $this->settingsService->isCashReceiptSelfIssueEnabled()) {
            return null;
        }

        return [
            CashReceiptType::INCOME,
            CashReceiptIdentifierType::SELF_ISSUE_NUMBER,
            CashReceiptIdentifierType::PHONE,
        ];
    }
}
