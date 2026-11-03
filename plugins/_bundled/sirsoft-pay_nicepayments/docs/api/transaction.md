# Transaction API 레퍼런스

> **소유**: plugin `sirsoft-pay_nicepayments` · 사람이 서술한 레퍼런스 (docgen 스캐폴딩 불가 구간 — 응답이 PG 원본 응답 합성이라 실측 자리는 사람이 채운다).

---

## TL;DR (5초 요약)

```text
1. 관리자 화면이 거래번호(TID)로 나이스페이먼츠 단건 거래를 조회하는 경로다
2. auth:sanctum + admin + permission:sirsoft-ecommerce.orders.read
3. tid 는 필수 문자열, 최대 30자. 위반하면 PG 호출 없이 422 (TransactionQueryRequest)
4. 응답 data 는 NicePay 단건 조회 원본 키 + 로컬 보강 필드(_local_is_escrow, _is_test_mode 등)
5. 주문번호로 찾아 조회하는 경로(admin/orders/{orderNumber}/transaction-status)는 orders.md 에 있다
```

---

### POST /api/plugins/sirsoft-pay_nicepayments/admin/transaction/query
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.admin.transaction.query -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.admin.transaction.query`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminTransactionController@query`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| tid | body | string | 예 | max 30 | 조회할 나이스페이 거래번호(TID). 누락, 문자열 아님, 30자 초과는 PG 호출 전에 422 (`TransactionQueryRequest`) |

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

_단건 응답: `data` 객체의 필드._

`data` 는 나이스페이먼츠 단건 거래 조회 응답(원본 키 그대로)에 로컬 결제 레코드에서 얻은 보강 필드를 덧붙인 객체다. 원본 키는 결제수단과 거래 상태에 따라 존재 여부가 달라진다. `EscrowYN`, `_is_test_mode` 는 로컬 결제 레코드에 결제 메타가 있을 때만 붙는다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| ResultCode | string | `0000` | NicePay 조회 결과 코드 (원본 응답). 조회 성공은 `0000` |
| ResultMsg | string | `정상처리` | NicePay 조회 결과 메시지 (원본 응답) |
| TID | string | `nictest00m01011104191651325596` | 거래번호 |
| Status | string | `0` | 거래 상태 (원본 응답). `0` 승인, `1` 취소, `9` 거래 없음. 가상계좌는 입금이 끝나야 `0` 으로 조회된다 |
| AuthCode | string | `30001234` | 승인번호 (원본 응답). 승인 전이거나 거래가 없으면 빈 문자열 |
| AuthDate | string | `260814103000` | 승인 일시 (원본 응답, 나이스페이먼츠 형식 그대로). 승인 전이거나 거래가 없으면 빈 문자열 |
| _local_is_escrow | boolean | `false` | 같은 TID 의 로컬 결제 레코드(`is_escrow`) 기준 에스크로 결제 여부. 로컬 레코드가 없으면 `false` |
| _base_pay_method_label | string \| null | `신용카드` | 로컬 결제 레코드 결제수단(`payment_method`)의 표시 라벨. 로컬 레코드가 없으면 `null` |
| _embedded_pg_provider | string \| null | `kakaopay` | 간편결제 제공자 코드(`naverpay`, `kakaopay`, `samsungpay`, `applepay`, `payco`, `skpay`, `ssgpay`, `lpay`). 간편결제가 아니거나 로컬 레코드가 없으면 `null` |
| _embedded_pg_provider_label | string \| null | `카카오페이` | 간편결제 제공자 표시 라벨. 간편결제가 아니면 `null` |
| _pay_method_label | string \| null | `카카오페이 (신용카드)` | 화면 표시용 최종 결제수단 라벨. 간편결제면 `제공자 (기본수단)`, 아니면 기본수단 라벨 |
| payment_method_display_label | string \| null | `카카오페이 (신용카드)` | `_pay_method_label` 과 같은 값 |
| EscrowYN | string | `N` | 에스크로 여부. 승인 당시 저장한 결제대행사 응답의 `EscrowYN`, 없으면 조회 응답의 값, 그것도 없으면 `N` |
| _is_test_mode | boolean | `true` | 테스트 모드 자격으로 결제된 거래인지 여부 (결제 메타 `is_test_mode`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "ResultCode": "0000",
        "ResultMsg": "정상처리",
        "TID": "nictest00m01011104191651325596",
        "Status": "0",
        "AuthCode": "30001234",
        "AuthDate": "260814103000",
        "_local_is_escrow": false,
        "_base_pay_method_label": "신용카드",
        "_embedded_pg_provider": "kakaopay",
        "_embedded_pg_provider_label": "카카오페이",
        "_pay_method_label": "카카오페이 (신용카드)",
        "payment_method_display_label": "카카오페이 (신용카드)",
        "EscrowYN": "N",
        "_is_test_mode": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우. 권한을 「본인만」 또는 「소유역할」 범위로 받은 관리자가 범위 밖 주문의 거래번호나 이 사이트 주문에 없는 거래번호를 보낸 경우 `auth.scope_denied` |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 결제대행사를 부르지 않는다 |
| 502 | Bad Gateway | 결제대행사 조회 호출이 실패한 경우 (`message` 는 `common.failed`, 원인은 서버 로그에만 남는다) |

<!-- @generated:end -->

**설명**

관리자 주문 상세의 결제 건별 거래 조회 버튼이 그 결제의 거래번호로 부른다. 받은 거래번호로 나이스페이먼츠 단건 거래 조회를 호출하고, 같은 거래번호의 로컬 결제 레코드에서 얻은 값을 덧붙여 돌려준다. 결제대행사 응답에 위 표에 없는 원본 키(금액 `Amt` 등)가 있으면 그대로 실린다.

`_local_is_escrow` 와 결제수단 라벨 필드는 로컬 레코드가 없어도 언제나 실린다(없으면 `false` 또는 `null`). `EscrowYN` 은 로컬 레코드에 결제 메타가 있을 때만 이 플러그인이 덮어쓰고, 결제 메타가 없으면 결제대행사 응답에 실린 값이 있을 때만 나타난다.

주문번호만으로 같은 조회를 하는 `GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/transaction-status` 는 [orders.md](orders.md) 에서 다룬다.
