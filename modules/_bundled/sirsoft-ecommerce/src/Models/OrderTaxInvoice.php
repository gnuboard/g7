<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderTaxInvoiceFactory;
use Modules\Sirsoft\Ecommerce\Enums\TaxInvoiceStatusEnum;

/**
 * 세금계산서 모델
 */
class OrderTaxInvoice extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return OrderTaxInvoiceFactory::new();
    }
    protected $table = 'ecommerce_order_tax_invoices';

    protected $fillable = [
        'order_id',
        'payment_id',
        'invoice_status',
        'company_name',
        'company_number',
        'ceo_name',
        'business_type',
        'business_category',
        'zipcode',
        'address',
        'address_detail',
        'manager_name',
        'manager_email',
        'manager_phone',
        'supply_amount',
        'tax_amount',
        'total_amount',
        'invoice_number',
        'invoice_url',
        'requested_at',
        'issued_at',
    ];

    protected $casts = [
        'supply_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'issued_at' => 'datetime',
        'invoice_status' => TaxInvoiceStatusEnum::class,
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
        return $this->belongsTo(OrderPayment::class, 'payment_id');
    }

    /**
     * 발급 완료 여부 확인
     *
     * @return bool 발급 완료 여부
     */
    public function isIssued(): bool
    {
        return $this->invoice_status === TaxInvoiceStatusEnum::ISSUED;
    }

    /**
     * 발급 대기 여부 확인
     *
     * @return bool 발급 대기 여부
     */
    public function isPending(): bool
    {
        return $this->invoice_status === TaxInvoiceStatusEnum::PENDING;
    }

    /**
     * 발급 실패 여부 확인
     *
     * @return bool 발급 실패 여부
     */
    public function isFailed(): bool
    {
        return $this->invoice_status === TaxInvoiceStatusEnum::FAILED;
    }

    /**
     * 전체 주소 반환
     *
     * @return string 전체 주소
     */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->address,
            $this->address_detail,
        ]);

        return implode(' ', $parts);
    }

    /**
     * 사업자번호 포맷팅 (000-00-00000)
     *
     * @return string 포맷된 사업자번호
     */
    public function getFormattedCompanyNumber(): string
    {
        $number = preg_replace('/[^0-9]/', '', $this->company_number);

        if (strlen($number) === 10) {
            return sprintf(
                '%s-%s-%s',
                substr($number, 0, 3),
                substr($number, 3, 2),
                substr($number, 5, 5)
            );
        }

        return $this->company_number;
    }

    /**
     * 발급 소요 시간 계산 (시간 단위)
     *
     * @return int|null 발급 소요 시간 (시간)
     */
    public function getIssuanceDurationInHours(): ?int
    {
        if (! $this->issued_at || ! $this->requested_at) {
            return null;
        }

        return $this->requested_at->diffInHours($this->issued_at);
    }
}
