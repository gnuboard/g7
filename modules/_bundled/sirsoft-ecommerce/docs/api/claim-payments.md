# Claim Payments API 레퍼런스

> **소유**: 모듈 `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반).
> 이 문서는 회원의 클레임(교환/반품) 추가결제 엔드포인트를 다룹니다. 비회원 경로는 [guest.md](guest.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 추가결제 건(ClaimPayment)은 신청·승인 시점에 서버가 만들고, 회원은 pay → (PG 결제창) → approve 순서로 결제한다
2. pay 응답의 결제 진입 4키(requires_pg_payment / pg_provider / pg_payment_handler / pg_payment_data)는 주문 결제와 같은 계약이다
3. 마일리지(point) 결제는 pay 한 번으로 끝난다 (requires_pg_payment=false, payment_status=paid)
4. approve 는 PG 콜백 파라미터를 그대로 넘기면 서버가 승인 훅으로 확정한다 (중복 호출은 기확정 건을 그대로 돌려준다)
5. 결제 건은 신청자 본인 것만 보인다 — 남의 결제번호는 404
```

## 엔드포인트 목록

| 메서드 | URI | 라우트명 |
| --- | --- | --- |
| POST | /api/modules/sirsoft-ecommerce/user/claim-payments/{paymentNumber}/pay | `api.modules.sirsoft-ecommerce.user.claim-payments.pay` |
| POST | /api/modules/sirsoft-ecommerce/user/claim-payments/{paymentNumber}/approve | `api.modules.sirsoft-ecommerce.user.claim-payments.approve` |

`ClaimPaymentResource` 필드 표는 [claims.md](claims.md#claim-payment-resource) 에 있습니다.

---

### POST /api/modules/sirsoft-ecommerce/user/claim-payments/{paymentNumber}/pay
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.claim-payments.pay -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.claim-payments.pay`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimPaymentController@pay`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.pay`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| paymentNumber | path | string | 예 | — | 결제번호 (`CP` 접두사, 신청 응답의 `payment.payment_number`) |
| payment_method | body | string | 아니오 | 환경설정 `claim.charge_payment_methods` (기본 `card`, `point`) | 결제 수단. 미전달 시 결제 건에 기록된 수단(신청 시 선택값) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/claim-payments/CP20260823000001/pay HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "payment_method": "card"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| payment | object | — | 결제 건 ([ClaimPaymentResource](claims.md#claim-payment-resource)) — 마일리지 결제면 이 시점에 `payment_status=paid` |
| requires_pg_payment | boolean | `true` | PG 결제창을 열어야 하는지 (`point` 결제는 `false`) |
| pg_provider | string\|null | `sirsoft-kginicis` | 결제 PG provider id — 결제 진입 페이로드는 `sirsoft-` 접두를 붙인다(`ClaimPaymentResource.pg_provider` 는 접두 없는 raw id). 대상 PG 는 결제 건에 기록된 값 → 수단별 오버라이드 → 환경설정 `order_settings.default_pg_provider` 순으로 정해진다 |
| pg_payment_handler | string\|null | `sirsoft-pay_kginicis.requestPayment` | 템플릿이 dispatch 할 결제창 핸들러명 (PG 레지스트리 `payment_handler`) |
| pg_payment_data | object\|null | — | 결제창 파라미터 — `order_number`(= 결제번호 CP…), `order_name`, `amount`(결제 통화 최소 화폐단위 정수), `currency`, `claim_payment_number`, `claim_type`, `claim_number`, `customer_name`/`customer_email`/`customer_phone`(숫자만 남긴 연락처), `customer_key`, `payment_method`, `escrow_products` |

**응답 예시**

```json
{
    "success": true,
    "message": "결제를 시작합니다.",
    "data": {
        "payment": {
            "id": 12,
            "payment_number": "CP20260823000001",
            "payable_type": "return",
            "payable_id": 31,
            "payment_method": "card",
            "payment_status": "pending",
            "amount": 3000,
            "amount_formatted": "3,000원",
            "points_amount": 0,
            "total_amount": 3000,
            "net_paid_amount": 0,
            "is_pending": true,
            "is_paid": false
        },
        "requires_pg_payment": true,
        "pg_provider": "sirsoft-kginicis",
        "pg_payment_handler": "sirsoft-pay_kginicis.requestPayment",
        "pg_payment_data": {
            "order_number": "CP20260823000001",
            "order_name": "반품 추가결제 (RT20260823000007)",
            "amount": 3000,
            "currency": "KRW",
            "claim_payment_number": "CP20260823000001",
            "claim_type": "return",
            "claim_number": "RT20260823000007",
            "customer_name": "홍길동",
            "customer_email": "user@example.com",
            "customer_phone": "01012345678",
            "customer_key": "user_7",
            "escrow_products": [],
            "payment_method": "card"
        }
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.pay`)이 없는 경우 |
| 404 | Not Found | 결제번호가 없거나 본인 신청서의 결제 건이 아닌 경우 (`claim_payment_not_found` — 두 경우를 구분하지 않는다) |
| 422 | Unprocessable Entity | `payment_method` 가 허용 목록 밖인 경우(검증), 결제 대기 상태가 아닌 건(`claim_payment_not_pending`), 결제 수단 불허(`claim_payment_method_not_allowed`), 마일리지 잔액 부족(`insufficient_points`), 결제 연동 부재(`claim_payment_provider_unavailable`) |
| 500 | Server Error | 결제 시작 중 예기치 못한 오류 (`클레임 처리 중 오류가 발생했습니다.`) |

<!-- @generated:end -->

**설명** 결제 대기(`pending`) 상태의 추가결제 건에 대해 결제를 시작합니다. `payment_method=point` 면 회원 마일리지를 FIFO 로 차감해 그 자리에서 확정하고(`requires_pg_payment=false`, `payment.payment_status=paid`), 그 밖의 수단은 PG 결제창 진입 파라미터를 돌려줍니다. 결제 진입 4키는 주문 결제 응답과 같은 계약이라 템플릿은 같은 dispatch 로 결제창을 엽니다. `pg_payment_data.order_number` 에는 주문번호가 아니라 **결제번호(CP…)** 가 실리고, 결제 플러그인의 콜백은 이 번호로 결제 건을 되찾아 `approve` 로 넘깁니다. 결제가 확정되면 신청서는 `payment_pending` 을 벗어나 `paid`(결제완료)로 옮겨집니다 — 자동 승인 여부와 무관하게 항상 이 상태를 거치므로, 수납이 끝난 건이 결제대기로 남지 않습니다. 이어서 환경설정(`claim.auto_approve_after_payment`)이 켜져 있거나 청구 방식이 `pay_on_approval` 이면 곧바로 `approved` 로 넘어가고, 아니면 `paid` 에서 운영자 승인을 기다립니다. 비회원은 마일리지 결제를 쓸 수 없습니다(`claim_point_payment_requires_member`).

---

### POST /api/modules/sirsoft-ecommerce/user/claim-payments/{paymentNumber}/approve
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.claim-payments.approve -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.claim-payments.approve`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimPaymentController@approve`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.pay`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| paymentNumber | path | string | 예 | — | 결제번호 (`CP` 접두사) |
| callback | body | object | 예 | — | PG 결제창이 돌려준 콜백 파라미터 원문 (PG 마다 키가 다르며 서버는 그대로 승인 훅에 넘긴다) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/claim-payments/CP20260823000001/approve HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "callback": {
        "paymentKey": "tviva2026...",
        "orderId": "CP20260823000001",
        "amount": 3000
    }
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| payment | object | — | 확정된 결제 건 ([ClaimPaymentResource](claims.md#claim-payment-resource), `payment_status=paid`, `transaction_id` 채워짐) |

**응답 예시**

```json
{
    "success": true,
    "message": "결제가 완료되었습니다.",
    "data": {
        "payment": {
            "id": 12,
            "payment_number": "CP20260823000001",
            "payment_method": "card",
            "pg_provider": "kginicis",
            "payment_status": "paid",
            "amount": 3000,
            "amount_formatted": "3,000원",
            "transaction_id": "TXN-20260823-0001",
            "is_paid": true,
            "paid_at_formatted": "2026-08-23 14:02"
        }
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.pay`)이 없는 경우 |
| 404 | Not Found | 결제번호가 없거나 본인 신청서의 결제 건이 아닌 경우 (`claim_payment_not_found` — 두 경우를 구분하지 않는다) |
| 422 | Unprocessable Entity | `callback` 누락(검증), 결제 연동 부재(`claim_payment_provider_unavailable`), PG 승인 실패(`claim_payment_failed` — PG 가 돌려준 사유 포함), 승인 금액 불일치(`claim_payment_amount_mismatch`) |
| 500 | Server Error | 승인 처리 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** PG 결제창이 닫힌 뒤 프론트가 받은 콜백 파라미터로 결제를 확정합니다. 서버는 결제 건을 잠그고 `sirsoft-ecommerce.claim_payment.approve` 필터 훅을 호출해 결제 플러그인이 실제 승인을 수행하게 하며, 훅이 돌려준 승인 금액이 결제 건의 청구액(주문 통화 스냅샷 환율로 환산한 최소 화폐단위)과 다르면 `claim_payment_amount_mismatch` 로 거절합니다. 이미 확정된 건에 다시 호출하면 오류 없이 그 건을 그대로 돌려주므로(멱등) 콜백 중복 전송에 안전합니다. 승인이 끝나면 신청서는 `payment_pending` 에서 `paid`(결제완료)로 옮겨지고(자동 승인 여부와 무관하게 항상 경유) `sirsoft-ecommerce.claim.{exchange|return}.payment_completed` 훅이 발행됩니다.

---

<a id="claim-payment-filter-contract"></a>

## 결제 플러그인 연동 계약 (필터 훅)

클레임 추가결제는 주문 결제와 **다른 훅**을 씁니다. 주문 결제 훅은 `Order`/`OrderPayment` 를 전제하는데
클레임 결제 건(`ClaimPayment`)은 주문이 아니기 때문입니다. 결제 플러그인은 아래 두 필터를 구현해야
클레임 결제가 동작하며, 리스너가 하나도 없으면 서버는 `claim_payment_provider_unavailable`(422) 로
거절합니다. 이 절이 계약의 단일 출처이고, 각 결제 플러그인 README 는 이 절을 참조합니다.

두 필터 모두 리스너 등록 시 `'type' => 'filter'` 를 명시해야 합니다 — 빠뜨리면 반환값이 버려져
승인·환급이 조용히 실패합니다.

### `sirsoft-ecommerce.claim_payment.approve`

| 인자 | 타입 | 설명 |
| --- | --- | --- |
| `$result` | array | 아래 반환 스키마의 기본값 (그대로 돌려주면 "처리하지 않음") |
| `$payment` | `ClaimPayment` | 승인 대상 결제 건 (`payment_number` 가 PG merchant_order_id) |
| `$callback` | array | 프론트가 받은 PG 콜백 파라미터 원문 |

| 반환 키 | 타입 | 설명 |
| --- | --- | --- |
| `handled` | bool | 이 리스너가 자기 PG 건으로 인식했는지. `handled=false` 이고 `success=false` 면 서버는 연동 부재로 판정합니다 |
| `success` | bool | 승인 성공 여부. `false` 면 서버가 오류를 기록하고 `claim_payment_failed` 로 거절합니다 |
| `transaction_id` | string\|null | PG 거래 ID. 이 컬럼의 UNIQUE 가 이중 청구를 막는 축이므로 승인 성공 시 반드시 채웁니다. **서버는 값이 없어도 승인을 확정합니다**(`null` 은 UNIQUE 제약에 걸리지 않으므로 그 결제 건만 멱등 방어선이 사라집니다) — 채우는 것은 플러그인의 책임입니다 |
| `paid_amount` | int\|null | PG 가 실제 승인한 금액. **결제 통화의 최소 화폐단위 정수**입니다(KRW 3000 → `3000`, USD 12.50 → `1250`). 서버는 주문 통화 스냅샷 환율로 환산한 청구액과 정수 비교하며, 다르면 `claim_payment_amount_mismatch` 로 거절합니다. `null` 이면 대조를 건너뜁니다 |
| `raw_response` | array\|null | PG 원본 응답 (그대로 보관) |
| `error_code` / `error_message` | string\|null | 실패 사유 (관리자 진단용) |

### `sirsoft-ecommerce.claim_payment.refund`

| 인자 | 타입 | 설명 |
| --- | --- | --- |
| `$result` | array | 아래 반환 스키마의 기본값 |
| `$payment` | `ClaimPayment` | 환급 대상 결제 건 |
| `$amount` | float | 환급액. 주문 통화와 결제 통화가 다르고 결제 건에 그 통화의 동결 금액(`mc_amount`)이 있으면 서버가 비례 환산해 넘깁니다. **동결 금액이 없으면(스냅샷 이전에 만들어진 구형 결제 건 등) 환산 없이 기준 통화 금액이 그대로 넘어갑니다** — 승인 축(`paid_amount`)과 달리 이 축은 서버가 단위를 보장하지 못하므로, 플러그인은 `$payment->currency_code` 로 단위를 확인하십시오 |
| `$reason` | string\|null | 환급 사유 |
| `$idempotencyKey` | string | `g7-claimpay-{결제건ID}-{누적환급액×100}` 형식. 같은 키의 재요청은 PG 쪽에서 한 번만 처리되어야 합니다 |

| 반환 키 | 타입 | 설명 |
| --- | --- | --- |
| `handled` | bool | 승인 필터와 같은 의미 |
| `success` | bool | 환급 성공 여부. `false` 면 서버가 `claim_payment_refund_failed` 로 거절합니다. **앞선 철회·차액 정산은 되돌아가지 않습니다** — 그 단계는 자기 트랜잭션으로 이미 커밋된 뒤이고, PG 호출을 트랜잭션 안에 넣지 않는 것이 이 모듈의 원칙이라 두 단계를 한 트랜잭션으로 묶지 않습니다. 따라서 환급이 실패하면 신청서는 철회 상태로, 결제 건은 `paid` 로 남아 운영자 개입이 필요합니다. 결제 건이 여러 개면 앞서 성공한 환급도 유지됩니다 |
| `transaction_id` | string\|null | 환급 거래 ID. **현재 모듈은 이 값을 저장하지 않습니다**(환급 거래 ID 컬럼 없음) — 돌려주어도 무방하나 대사에 쓰이지 않습니다. 환급 멱등의 축은 위 `$idempotencyKey` 인자입니다 |
| `error_code` / `error_message` | string\|null | 실패 사유 |

콜백 경로도 함께 확장해야 합니다 — 결제번호가 `CP` 로 시작하면 주문이 아니라 `ClaimPayment` 로
되찾아야 하며, 주문번호로 `Order` 를 찾는 기존 분기를 그대로 태우면 클레임 결제 콜백이 실패합니다.
