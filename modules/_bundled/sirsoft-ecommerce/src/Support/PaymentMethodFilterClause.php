<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 관리자 주문 목록 결제수단 필터의 조건 한 묶음 (#933 S6)
 *
 * 결제수단 선택지 하나가 결제 행에 거는 조건이다. 여러 선택지는 이 묶음들의 OR 이다.
 *
 *   payment_method = {paymentMethod}
 *   [AND pg_provider = {pgProvider}]
 *   [AND embedded_pg_provider IN {embeddedIn}]
 *   [AND 각 PG 마다 (pg_provider <> PG OR embedded_pg_provider IS NULL OR embedded_pg_provider NOT IN 목록)]
 *
 * 마지막 조건(`excludeEmbeddedByPg`)은 활성 간편결제 별칭이 찾는 주문을 코어 값·비간편결제 별칭 결과에서 빼는 데 쓴다.
 * `PaymentMethodResolver::orderListFilterClauses()` 만 만든다 — 요청 인자로 들어온 값은 쓰지 않는다.
 */
final class PaymentMethodFilterClause
{
    /**
     * @param  string  $paymentMethod  결제 행 `payment_method` 값
     * @param  string|null  $pgProvider  결제 행 `pg_provider` 값 (null 이면 조건 없음)
     * @param  array<int, string>|null  $embeddedIn  결제 행 `embedded_pg_provider` 허용 값 (null 이면 조건 없음)
     * @param  array<string, array<int, string>>  $excludeEmbeddedByPg  PG => 제외할 `embedded_pg_provider` 값 목록
     */
    public function __construct(
        public readonly string $paymentMethod,
        public readonly ?string $pgProvider = null,
        public readonly ?array $embeddedIn = null,
        public readonly array $excludeEmbeddedByPg = [],
    ) {}
}
