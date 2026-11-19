<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

use DateTimeInterface;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;

/**
 * 가상계좌(편의점 결제 포함) 발급 정보 (공개 계약 DTO)
 *
 * 결제 행을 입금 대기로 바꾸고 발급 정보를 저장한다. `writeNulls` 가 false 면 null 필드는 쓰지 않는다.
 *
 * @since 1.4.0
 */
final readonly class VbankIssuance
{
    /**
     * VbankIssuance 생성자
     *
     * @param  DateTimeInterface  $vbankIssuedAt  발급 시각
     * @param  string|null  $transactionId  결제사 거래번호
     * @param  string|null  $vbankCode  은행(편의점) 코드
     * @param  string|null  $vbankName  은행명
     * @param  string|null  $vbankNumber  계좌(수납) 번호
     * @param  string|null  $vbankHolder  예금주
     * @param  DateTimeInterface|null  $vbankDueAt  입금 기한
     * @param  bool|null  $isEscrow  에스크로 여부
     * @param  string|null  $paymentDevice  결제 기기
     * @param  array<string, mixed>|null  $paymentMeta  결제 meta(통째로 교체)
     * @param  bool  $writeNulls  null 필드도 그대로 쓸지
     * @param  bool  $clearCart  발급 뒤 주문한 장바구니 상품을 정리할지
     * @param  bool  $takeOverProvider  결제 행의 결제사 id 를 호출 결제사로 기록할지
     */
    public function __construct(
        public DateTimeInterface $vbankIssuedAt,
        public ?string $transactionId = null,
        public ?string $vbankCode = null,
        public ?string $vbankName = null,
        public ?string $vbankNumber = null,
        public ?string $vbankHolder = null,
        public ?DateTimeInterface $vbankDueAt = null,
        public ?bool $isEscrow = null,
        public ?string $paymentDevice = null,
        public ?array $paymentMeta = null,
        public bool $writeNulls = false,
        public bool $clearCart = true,
        public bool $takeOverProvider = true,
    ) {}

    /**
     * 결제 행에 쓸 컬럼 배열로 바꿉니다.
     *
     * @internal 이커머스 계약 구현만 쓴다.
     *
     * @param  string  $pg  호출 결제사 id
     * @return array<string, mixed> 컬럼 => 값
     */
    public function toAttributes(string $pg): array
    {
        $attributes = [
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT->value,
            'pg_provider' => $this->takeOverProvider ? $pg : null,
            'transaction_id' => $this->transactionId,
            'vbank_code' => $this->vbankCode,
            'vbank_name' => $this->vbankName,
            'vbank_number' => $this->vbankNumber,
            'vbank_holder' => $this->vbankHolder,
            'vbank_due_at' => $this->vbankDueAt,
            'vbank_issued_at' => $this->vbankIssuedAt,
            'is_escrow' => $this->isEscrow,
            'payment_device' => $this->paymentDevice,
            'payment_meta' => $this->paymentMeta,
        ];

        if (! $this->takeOverProvider) {
            unset($attributes['pg_provider']);
        }

        return $this->writeNulls ? $attributes : array_filter($attributes, fn ($value) => $value !== null);
    }
}
