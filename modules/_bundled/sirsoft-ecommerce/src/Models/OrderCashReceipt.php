<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptTransactionType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;

/**
 * 주문 현금영수증 이력 모델
 *
 * 발급/취소 원장(ledger)이다. ecommerce_order_payments 의 cash_receipt_* 컬럼은
 * "현재 상태 요약"이고, 이 테이블이 국세청 신고 근거가 되는 전체 이력을 보관한다.
 *
 * 식별번호 원본(평문/암호문)은 절대 이 테이블에 저장하지 않는다 — 마스킹 값만 남긴다.
 */
class OrderCashReceipt extends Model
{
    protected $table = 'ecommerce_order_cash_receipts';

    protected $fillable = [
        'order_id',
        'order_payment_id',
        'provider',
        'receipt_key',
        'transaction_type',
        'receipt_type',
        'amount',
        'tax_free_amount',
        'identifier_masked',
        'receipt_url',
        'issue_number',
        'issue_status',
        'error_code',
        'error_message',
        'issued_at',
        'raw_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax_free_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'raw_response' => 'array',
        'transaction_type' => CashReceiptTransactionType::class,
        'receipt_type' => CashReceiptType::class,
        'issue_status' => CashReceiptIssueStatus::class,
    ];

    /**
     * 주문 관계
     *
     * @return BelongsTo 주문 모델과의 관계
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * 결제 관계
     *
     * @return BelongsTo 결제 모델과의 관계
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'order_payment_id');
    }

    /**
     * 발급 이력인지 확인합니다.
     *
     * @return bool 발급 이력 여부
     */
    public function isIssue(): bool
    {
        return $this->transaction_type === CashReceiptTransactionType::ISSUE;
    }

    /**
     * 성공적으로 발급 완료된 이력인지 확인합니다.
     *
     * @return bool 발급 완료 여부
     */
    public function isCompletedIssue(): bool
    {
        return $this->isIssue() && $this->issue_status === CashReceiptIssueStatus::COMPLETED;
    }

    /**
     * 발급 금액과 면세 금액이 주어진 목표치와 동일한지 확인합니다.
     *
     * decimal cast 는 문자열을 반환하므로 정수 비교로 정규화한다.
     *
     * @param  int  $amount  목표 발급 금액
     * @param  int  $taxFreeAmount  목표 면세 금액
     * @return bool 동일 여부
     */
    public function matchesAmounts(int $amount, int $taxFreeAmount): bool
    {
        return (int) $this->amount === $amount
            && (int) $this->tax_free_amount === $taxFreeAmount;
    }

    /**
     * 이력 컬렉션에서 활성 영수증만 최신순으로 골라냅니다.
     *
     * "활성" = 발급 완료(COMPLETED) 이면서 같은 receipt_key 의 취소 완료 이력이 없는 건.
     * receipt_key 가 없는 발급 건은 취소 대상을 특정할 수 없으므로 활성으로 보지 않는다.
     *
     * Repository(DB 조회)와 Resource(로드된 컬렉션) 양쪽이 같은 판정을 쓰도록
     * 활성 판정 규칙은 이 메서드가 단일 SSoT 다.
     *
     * @param  iterable<int, self>  $receipts  발급/취소 이력 (정렬 무관)
     * @return array<int, self> 활성 영수증 (id 내림차순)
     */
    public static function filterActive(iterable $receipts): array
    {
        $rows = collect($receipts)
            ->filter(fn (self $row) => $row->issue_status === CashReceiptIssueStatus::COMPLETED)
            ->sortByDesc('id');

        $cancelledKeys = $rows
            ->filter(fn (self $row) => $row->transaction_type === CashReceiptTransactionType::CANCEL)
            ->pluck('receipt_key')
            ->filter()
            ->unique()
            ->all();

        return $rows
            ->filter(function (self $row) use ($cancelledKeys) {
                if ($row->transaction_type !== CashReceiptTransactionType::ISSUE) {
                    return false;
                }

                if ($row->receipt_key === null || $row->receipt_key === '') {
                    return false;
                }

                return ! in_array($row->receipt_key, $cancelledKeys, true);
            })
            ->values()
            ->all();
    }
}
