<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;

/**
 * 주문 결제 Repository 구현체
 */
class OrderPaymentRepository implements OrderPaymentRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function findByOrderIdForUpdate(int $orderId): ?OrderPayment
    {
        return OrderPayment::query()->where('order_id', $orderId)->lockForUpdate()->first();
    }

    /**
     * {@inheritDoc}
     */
    public function markCashReceiptIssued(
        OrderPayment $payment,
        OrderCashReceipt $receipt,
        CashReceiptType $type,
        string $identifier,
        string $identifierMasked,
        ?CashReceiptIdentifierType $identifierType,
    ): OrderPayment {
        // receipt_url 은 건드리지 않는다 — 그 컬럼은 PG 영수증(카드 매출전표) URL 전용이고
        // PG 플러그인이 결제 승인 시 기록한다. 현금영수증 URL 은 이력 테이블
        // (ecommerce_order_cash_receipts.receipt_url) 이 보관하며 OrderResource 가 따로 노출한다.
        // 여기서 덮어쓰면 카드 매출전표 URL 이 유실되고 한 컬럼이 두 의미를 갖게 된다.
        $payment->update([
            'is_cash_receipt_issued' => true,
            'cash_receipt_type' => $type->value,
            'cash_receipt_identifier' => $identifierMasked,
            'cash_receipt_identifier_type' => $identifierType,
            'cash_receipt_identifier_encrypted' => $identifier,
            'cash_receipt_issued_at' => $receipt->issued_at,
        ]);

        return $payment;
    }

    /**
     * {@inheritDoc}
     */
    public function markCashReceiptCancelled(OrderPayment $payment): OrderPayment
    {
        $payment->update([
            'is_cash_receipt_issued' => false,
            'cash_receipt_issued_at' => null,
        ]);

        return $payment;
    }

    /**
     * {@inheritDoc}
     */
    public function purgeCashReceiptIdentifier(OrderPayment $payment): OrderPayment
    {
        if (! $this->hasCashReceiptIdentifier($payment)) {
            return $payment;
        }

        $payment->update(['cash_receipt_identifier_encrypted' => null]);

        return $payment;
    }

    /**
     * {@inheritDoc}
     */
    public function resolveCashReceiptIdentifier(OrderPayment $payment): ?string
    {
        try {
            $identifier = $payment->cash_receipt_identifier_encrypted;
        } catch (\Throwable $e) {
            // APP_KEY 로테이션 등으로 복호화 실패
            return null;
        }

        return is_string($identifier) && $identifier !== '' ? $identifier : null;
    }

    /**
     * {@inheritDoc}
     */
    public function hasCashReceiptIdentifier(OrderPayment $payment): bool
    {
        return $payment->getRawOriginal('cash_receipt_identifier_encrypted') !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function updateRefundBank(
        OrderPayment $payment,
        string $bankCode,
        string $bankName,
        string $accountNumber,
        string $holder,
    ): OrderPayment {
        $payment->update([
            'refund_bank_code' => $bankCode,
            'refund_bank_name' => $bankName,
            'refund_bank_account' => $accountNumber,
            'refund_bank_holder' => $holder,
        ]);

        return $payment;
    }

    /**
     * {@inheritDoc}
     */
    public function isTransactionPaid(?string $transactionId): bool
    {
        if ($transactionId === null || $transactionId === '') {
            return false;
        }

        return OrderPayment::query()
            ->where('transaction_id', $transactionId)
            ->where('payment_status', PaymentStatusEnum::PAID->value)
            ->exists();
    }
}
