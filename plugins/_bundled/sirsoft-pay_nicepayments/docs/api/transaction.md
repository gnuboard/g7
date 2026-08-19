# Transaction API 레퍼런스

> **소유**: plugin `sirsoft-pay_nicepayments` · 사람이 서술한 레퍼런스 (docgen 스캐폴딩 불가 구간 — 응답이 PG 원본 응답 합성이라 실측 자리는 사람이 채운다).

---

## TL;DR (5초 요약)

```text
1. 관리자 주문 상세의 나이스페이 거래 조회 2종 (TID 직접 / 주문번호 자동 매핑)
2. 두 경로 모두 auth:sanctum + permission:sirsoft-ecommerce.orders.read
3. tid 는 필수·문자열·최대 30자 — 위반 시 PG 호출 없이 422 (TransactionQueryRequest)
4. 응답 data 는 NicePay 단건 조회 원본 키 + 로컬 보강 필드(_local_is_escrow, _is_test_mode 등)
5. 주문번호 경로는 나이스페이 결제 레코드가 없으면 data: null 로 성공 응답
```

---

### POST /api/plugins/sirsoft-pay_nicepayments/admin/transaction/query

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.transaction.query`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminTransactionController@query`
- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| tid | body | string | 예 | max 30 | 조회할 나이스페이 거래번호(TID). 누락·문자열 아님·30자 초과는 PG 호출 전에 422 (`TransactionQueryRequest`) |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nicepayments/admin/transaction/query HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "tid": "nictest00m01011104191651325596"
}
```

**응답 필드** (`data` 내부)

`data` 는 NicePay 단건 거래 조회 응답(원본 키 그대로)에 로컬 보강 필드를 덧붙인 객체다. 원본 키는 결제수단·거래 상태에 따라 존재 여부가 달라진다.

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| ResultCode | string | NicePay 조회 결과 코드 (원본 응답) |
| ResultMsg | string | NicePay 조회 결과 메시지 (원본 응답) |
| TID | string | 거래번호 |
| Amt | string | 거래 금액 (원본 응답) |
| \_local_is_escrow | boolean | 로컬 결제 레코드 기준 에스크로 거래 여부 (조회 응답에 EscrowYN 이 없을 때의 보강) |
| \_is_test_mode | boolean | 테스트 모드 자격증명으로 결제된 거래 여부 (로컬 레코드 기준) |

**오류 응답**

| 상태 | 조건 |
| --- | --- |
| 422 | `tid` 검증 실패 (누락 / 문자열 아님·배열 주입 / 30자 초과) — PG 미호출 |
| 401 / 403 | 미인증 / `sirsoft-ecommerce.orders.read` 권한 없음 |

---

### GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/transaction-status

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.orders.transaction-status`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminTransactionController@queryByOrder`
- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 용도 |
| --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | 주문번호. `ecommerce_order_payments.transaction_id` 를 찾아 TID 조회로 위임 |

**응답**

- 나이스페이 결제 레코드(TID)가 있으면 위 `transaction/query` 와 동일한 `data`.
- 매핑되는 나이스페이 거래가 없으면 `data: null` 로 성공 응답 (오류 아님 — 화면이 "조회 대상 없음" 으로 표시).
