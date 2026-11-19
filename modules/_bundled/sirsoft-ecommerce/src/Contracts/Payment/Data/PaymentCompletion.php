<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

use Closure;

/**
 * 결제 완료 처리에 넘기는 결제사 응답 값 (공개 계약 DTO)
 *
 * null 필드는 결제 행에 쓰지 않는다(기존 값 유지).
 *
 * @since 1.4.0
 */
final readonly class PaymentCompletion
{
    /**
     * PaymentCompletion 생성자
     *
     * @param  string|null  $transactionId  결제사 거래번호
     * @param  string|null  $cardApprovalNumber  카드 승인번호
     * @param  string|null  $cardNumberMasked  가린 카드번호
     * @param  string|null  $cardName  카드사명
     * @param  int|null  $cardInstallmentMonths  할부 개월
     * @param  bool|null  $isInterestFree  무이자 여부
     * @param  string|null  $embeddedPgProvider  간편결제 제공자
     * @param  string|null  $receiptUrl  영수증 주소
     * @param  array<string, mixed>|Closure|null  $paymentMeta  배열이면 결제 meta 를 통째로 교체, Closure(array $current): array 면 현재 meta 로 계산한 값으로 교체
     * @param  string|null  $paymentDevice  결제 기기
     * @param  bool|null  $isEscrow  에스크로 여부(null 이면 쓰지 않음, false 도 씀)
     * @param  bool  $takeOverProvider  결제 행의 결제사 id 를 호출 결제사로 기록할지
     */
    public function __construct(
        public ?string $transactionId = null,
        public ?string $cardApprovalNumber = null,
        public ?string $cardNumberMasked = null,
        public ?string $cardName = null,
        public ?int $cardInstallmentMonths = null,
        public ?bool $isInterestFree = null,
        public ?string $embeddedPgProvider = null,
        public ?string $receiptUrl = null,
        public array|Closure|null $paymentMeta = null,
        public ?string $paymentDevice = null,
        public ?bool $isEscrow = null,
        public bool $takeOverProvider = false,
    ) {}

    /**
     * 결제 완료 서비스가 받는 배열로 바꿉니다.
     *
     * @internal 이커머스 계약 구현만 쓴다.
     *
     * @param  array<string, mixed>  $currentMeta  현재 결제 meta(Closure 계산용)
     * @param  string  $pg  호출 결제사 id
     * @return array<string, mixed> 결제 데이터
     */
    public function toPaymentData(array $currentMeta, string $pg): array
    {
        $meta = $this->paymentMeta instanceof Closure ? ($this->paymentMeta)($currentMeta) : $this->paymentMeta;

        return [
            'transaction_id' => $this->transactionId,
            'card_approval_number' => $this->cardApprovalNumber,
            'card_number_masked' => $this->cardNumberMasked,
            'card_name' => $this->cardName,
            'card_installment_months' => $this->cardInstallmentMonths,
            'is_interest_free' => $this->isInterestFree,
            'embedded_pg_provider' => $this->embeddedPgProvider,
            'receipt_url' => $this->receiptUrl,
            'payment_meta' => $meta,
            'payment_device' => $this->paymentDevice,
            'pg_provider' => $this->takeOverProvider ? $pg : null,
            'is_escrow' => $this->isEscrow,
        ];
    }
}
