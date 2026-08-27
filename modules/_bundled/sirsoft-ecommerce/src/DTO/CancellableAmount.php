<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 취소 가능 금액 산정 결과 DTO (D19)
 *
 * "이 주문에서 지금 얼마까지 되돌릴 수 있는가" 는 성격이 다른 세 상한의 최솟값이다.
 *
 * ```
 * amount = max(0, min(① domainRefundable, ② paymentCapacity) − ③ reserved)
 * ```
 *
 * 세 축을 하나로 합치면 반드시 어긋난다 — 도메인상 돌려줘야 할 금액과 결제수단이 실제로
 * 받아 줄 금액은 별개이고, 승인만 되고 아직 실행되지 않은 클레임은 어느 쪽에도 잡히지 않는다.
 * 그래서 이 DTO 는 최종 금액뿐 아니라 **세 축의 값과 어느 축이 한도를 정했는지**(`$binding`)를
 * 함께 싣는다. 관리자 화면은 이 근거를 그대로 표에 노출하고(D19), 서버는 환불 실행 직전
 * 같은 메서드를 다시 불러 동시 처리를 막는다.
 *
 * 금액은 모두 **주문 기본 통화(base)** 기준이다. 결제수단별 잔여(`$methods`)만 결제 통화
 * 값(`remaining_local`)을 함께 싣는데, PG 가 실제로 받아 주는 한도는 결제 통화 기준이라
 * 화면이 그 숫자를 그대로 보여야 하기 때문이다.
 */
class CancellableAmount
{
    /** 도메인 환불 대상 금액이 한도를 정함 */
    public const BINDING_DOMAIN = 'domain_refundable';

    /** 결제수단 잔여 한도가 한도를 정함 */
    public const BINDING_PAYMENT = 'payment_capacity';

    /** 진행 중 클레임 선점액이 한도를 정함 (선점 차감으로 0이 된 경우 포함) */
    public const BINDING_RESERVED = 'reserved';

    /** 결제수단 종류 — PG·간편결제 등 결제행 기반 */
    public const METHOD_PAYMENT = 'payment';

    /** 결제수단 종류 — 마일리지 (주문 컬럼 기반, 결제행 없음) */
    public const METHOD_MILEAGE = 'mileage';

    /**
     * @param  float  $amount  최종 취소 가능 금액 (기본 통화)
     * @param  float  $domainRefundable  ① 도메인 환불 대상 금액
     * @param  float  $paymentCapacity  ② 결제수단 잔여 한도 합계 (기본 통화 환산)
     * @param  float  $reserved  ③ 진행 중 클레임 선점액 합계
     * @param  string  $binding  한도를 정한 축 (BINDING_* 상수)
     * @param  array<int, array>  $methods  수단별 잔여 [{type, method, currency, remaining_local, remaining_base}]
     * @param  array<int, array>  $reservedClaims  선점 근거 [{type, id, number, amount}]
     * @param  string|null  $currency  기본 통화 코드
     * @param  array<string, mixed>  $mcAmount  다통화 표기 {통화코드 => 금액}
     * @param  array<string, mixed>|null  $currencySnapshot  주문 시점 통화 스냅샷 (표기 자릿수·절사 규칙의 SSoT)
     */
    public function __construct(
        public float $amount = 0,
        public float $domainRefundable = 0,
        public float $paymentCapacity = 0,
        public float $reserved = 0,
        public string $binding = self::BINDING_DOMAIN,
        public array $methods = [],
        public array $reservedClaims = [],
        public ?string $currency = null,
        public array $mcAmount = [],
        public ?array $currencySnapshot = null,
    ) {}

    /**
     * 요청 금액이 취소 가능 한도 안인지 판정합니다.
     *
     * 소수 통화의 반올림 오차를 허용한다 — 정수 통화(KRW)에는 영향이 없다.
     *
     * @param  float  $requested  요청 환불 금액 (기본 통화)
     * @return bool 한도 이내이면 true
     */
    public function allows(float $requested): bool
    {
        return $requested <= $this->amount + 0.01;
    }

    /**
     * 한도를 정한 축의 다국어 라벨 키를 반환합니다.
     *
     * @return string 다국어 키
     */
    public function bindingLabelKey(): string
    {
        return 'sirsoft-ecommerce::messages.claims.cancellable_binding.'.$this->binding;
    }

    /**
     * 화면·응답용 배열을 반환합니다.
     *
     * @return array 산정 결과 배열
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'domain_refundable' => $this->domainRefundable,
            'payment_capacity' => $this->paymentCapacity,
            'reserved' => $this->reserved,
            'binding' => $this->binding,
            'binding_label_key' => $this->bindingLabelKey(),
            'methods' => $this->methods,
            'reserved_claims' => $this->reservedClaims,
            'currency' => $this->currency,
            'mc_amount' => $this->mcAmount,
        ];
    }
}
