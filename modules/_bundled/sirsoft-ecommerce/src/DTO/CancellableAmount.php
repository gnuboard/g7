<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 취소 가능 금액 산정 결과 DTO (D19)
 *
 * "이 주문에서 지금 얼마까지 되돌릴 수 있는가" 는 성격이 다른 두 상한의 최솟값이다.
 *
 * ```
 * amount = max(0, min(① domainRefundable, ② paymentCapacity))
 * ```
 *
 * 두 축을 하나로 합치면 반드시 어긋난다 — 도메인상 돌려줘야 할 금액과 결제수단이 실제로
 * 받아 줄 금액은 별개다. 그래서 이 DTO 는 최종 금액뿐 아니라 **축별 값과 어느 축이 한도를
 * 정했는지**(`$binding`)를 함께 싣는다. 관리자 화면은 이 근거를 그대로 표에 노출하고(D19),
 * 서버는 환불 실행 직전 같은 메서드를 다시 불러 동시 처리를 막는다.
 *
 * ③(`$reserved`)은 **참고 축**이다. 아직 실행되지 않은 다른 클레임의 환불 예정액을 운영자에게
 * 알리되 한도에서 빼지 않는다 — 실지급 가능액은 ② 가 이미 실제 취소 누적을 뺀 값이라
 * 예정액까지 빼면 승인도 되지 않은 남의 신청서가 이 건의 정당한 환불을 0 원으로 막는다.
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

    /** 결제수단 종류 — PG·간편결제 등 결제행 기반 */
    public const METHOD_PAYMENT = 'payment';

    /** 결제수단 종류 — 마일리지 (주문 컬럼 기반, 결제행 없음) */
    public const METHOD_MILEAGE = 'mileage';

    /**
     * 결제수단 종류 — 재활성으로 다시 받은 현금 (주문 메타 기반, 결제행 없음 — Q14·Q24)
     *
     * 취소 때 PG 로 돌려준 결제는 결제행에서 이미 취소됐다. 재활성으로 그 금액을 「다시 받은 것」으로
     * 기록하면 주문 장부에는 돌려줄 수 있는 현금이 생기지만 결제행 한도는 0 이다. 이 몫은 계좌·마일리지로
     * 돌려줄 수 있는 현금 한도로 따로 센다(PG 재취소는 실패할 수 있다 — 재활성 확인 창 경고).
     */
    public const METHOD_REACTIVATED_CASH = 'reactivated_cash';

    /**
     * @param  float  $amount  최종 취소 가능 금액 (기본 통화)
     * @param  float  $domainRefundable  ① 도메인 환불 대상 금액
     * @param  float  $paymentCapacity  ② 결제수단 잔여 한도 합계 (기본 통화 환산)
     * @param  float  $reserved  ③ 진행 중 클레임 환불 예정액 합계 (참고 — 한도를 깎지 않는다)
     * @param  string  $binding  한도를 정한 축 (BINDING_* 상수)
     * @param  array<int, array>  $methods  수단별 잔여 [{type, method, currency, remaining_local, remaining_base}]
     * @param  array<int, array>  $reservedClaims  예정액 근거 [{type, id, number, amount}]
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
     * 현금(결제수단)과 마일리지를 **수단별로** 나눈 잔여를 반환합니다. (S3-D21)
     *
     * `allows()` 는 두 잔여를 더한 하나와 비교한다 — 그 합계는 "이 주문에서 통틀어 얼마까지
     * 되돌릴 수 있는가" 로는 맞지만, **어느 수단으로 나가는지는 말해 주지 않는다.** 실제
     * 지급은 수단별로 일어나므로 합계만 보면 한쪽이 마른 채 다른 쪽 여유로 통과한다.
     *
     * 실측(주문 753): 현금 잔여 9,000 · 마일리지 잔여 20,000 인 시점에 **현금 14,000** 요청이
     * `14,000 ≤ 29,000` 으로 통과해, 결제행의 취소 누적이 결제액을 **5,000 초과**했다
     * (`cancelled_amount 350,000 > paid_amount_base 345,000`). 마일리지는 20,000 중 10,000 이
     * 끝내 환급되지 못했다 — 두 수단이 서로 어긋난 채 종료된다.
     *
     * @return array{cash: float, mileage: float} 수단별 잔여 (기본 통화)
     */
    public function remainingByMethod(): array
    {
        $cash = 0.0;
        $mileage = 0.0;

        foreach ($this->methods as $method) {
            $amount = (float) ($method['remaining_base'] ?? 0);

            if (($method['type'] ?? null) === self::METHOD_MILEAGE) {
                $mileage += $amount;

                continue;
            }

            $cash += $amount;
        }

        return ['cash' => $cash, 'mileage' => $mileage];
    }

    /**
     * 수단별 요청액이 **각 수단의 잔여** 안인지 판정합니다. (S3-D21)
     *
     * `allows()` 를 대체하지 않고 **함께** 쓴다 — 도메인 상한(①)은 여전히 합계로 걸러야 하고,
     * 이 메서드는 그 뒤에서 "그 돈이 실제로 나갈 수 있는 수단인가" 를 본다.
     *
     * @param  float  $cash  현금(결제수단) 요청액
     * @param  float  $mileage  마일리지 요청액
     * @return bool 두 수단 모두 잔여 이내이면 true
     */
    public function allowsByMethod(float $cash, float $mileage): bool
    {
        $remaining = $this->remainingByMethod();

        return $cash <= $remaining['cash'] + 0.01
            && $mileage <= $remaining['mileage'] + 0.01;
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
