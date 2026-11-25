# Orders API 레퍼런스

> **소유**: plugin `sirsoft-pay_nhnkcp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Orders 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/test-mode-map
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.admin.orders.test-mode-map -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.admin.orders.test-mode-map`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\AdminOrderListController@testModeMap`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/test-mode-map HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

`data` 는 **주문번호를 키로 하고 값이 언제나 `true`** 인 객체다. 최근 6개월 안의 NHN KCP 결제 중 **테스트 모드로 결제된 주문만** 실리므로, 그런 주문이 없으면 빈 객체가 온다. 목록에 있는 주문번호만 테스트 결제로 간주하면 된다.

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| (키) | string | 주문번호. |
| (값) | boolean | 언제나 `true`. 해당 주문이 테스트 모드 결제임을 뜻한다. |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": []
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/escrow-delivery
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.admin.orders.escrow-delivery.form -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.admin.orders.escrow-delivery.form`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\AdminEscrowDeliveryController@formData`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/escrow-delivery HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| has_escrow_payment | boolean | 이 주문이 에스크로 결제인지. 거짓이면 `data` 가 `null` 로만 온다. |
| tno | string | 결제사 거래번호. 배송정보 등록이 어느 결제 건에 붙는지를 가리킨다. |
| courier_codes | object | 등록 가능한 택배사 코드와 이름의 표. 등록 요청의 `deli_corp` 는 이 표의 키만 받는다. |
| prefill | object | 주문의 배송지에서 채워 둔 수령인 정보(이름·연락처·우편번호·주소). 운영자가 다시 입력하지 않게 하는 값이며 등록 요청에는 보내지 않는다. |
| registered_delivery | object · null | 이미 등록된 배송정보. 아직 등록하지 않았으면 `null` 이다. |

**응답 예시**

```json
{
    "success": true,
    "message": "성공했습니다.",
    "data": {
        "has_escrow_payment": true,
        "tno": "TNO0123456789",
        "courier_codes": { "01": "우체국택배" },
        "prefill": { "recv_name": "홍길동", "recv_tel": "010-1234-5678", "recv_post": "06236", "recv_addr": "서울特…" },
        "registered_delivery": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### POST /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/escrow-delivery
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.admin.orders.escrow-delivery.register -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.admin.orders.escrow-delivery.register`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\AdminEscrowDeliveryController@register`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |
| deli_numb | body | string | 예 | max 30 | 운송장 번호. 결제사의 에스크로 배송정보로 등록된다. |
| deli_corp | body | string | 예 | 택배사 코드 목록 | 택배사 코드. 위 조회 응답의 `courier_codes` 에 있는 값만 받는다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/escrow-delivery HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "deli_numb": "예시값",
    "deli_corp": "예시값"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| res_cd | string | 결제사가 돌려준 등록 결과코드. `0000` 이 성공이다. |
| deli_numb | string | 등록된 운송장 번호. 요청 값을 그대로 되돌려 준다. |
| courier_name | string | 등록된 택배사 이름. 요청의 코드를 이름으로 옮긴 값이다. |

**응답 예시**

```json
{
    "success": true,
    "message": "성공했습니다.",
    "data": {
        "res_cd": "0000",
        "deli_numb": "1234567890",
        "courier_name": "우체국택배"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/transaction-status
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.admin.orders.transaction-status -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.admin.orders.transaction-status`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\AdminTransactionController@queryByOrder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/transaction-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| tno · app_no · app_time | string · null | 결제사 거래번호 · 승인번호 · 승인시각. 결제사 화면과 대조할 때 쓰는 값이다. |
| use_pay_method | string · null | 실제로 승인된 결제수단 코드. |
| res_cd | string | 승인 결과코드. `0000` 이 정상이다. |
| card_name · bank_name · account | string · null | 카드사명 · 은행명 · 계좌번호. 결제수단에 따라 채워지는 것이 다르다. |
| payment_status · payment_status_label · payment_status_variant | string · null | 결제 상태와 그 화면 표기(라벨·색). 화면이 상태를 직접 해석하지 않도록 서버가 함께 준다. |
| cancelled_amount · cancelled_amount_formatted · cancelled_at | number · string · null | 취소 금액과 그 통화 표기, 취소 시각. 표기는 주문 통화 기준으로 서버가 만든다. |
| cancel_history | array | 부분취소를 포함한 취소 이력. |
| refund_number · refund_status · refund_status_label · refund_status_variant · refund_amount | string · number · null | 이 결제에 연결된 환불 건과 그 상태·금액. 환불이 없으면 비어 있다. |
| _is_test_mode | boolean | 테스트 모드 결제인지. 화면에 테스트 표시를 띄우는 근거다. |

**응답 예시**

```json
{
    "success": true,
    "message": "성공했습니다.",
    "data": {
        "tno": "TNO0123456789",
        "app_no": "12345678",
        "res_cd": "0000",
        "payment_status": "paid",
        "payment_status_label": "결제완료",
        "cancelled_amount": 0,
        "cancel_history": [],
        "_is_test_mode": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/receipt
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.user.orders.receipt -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.user.orders.receipt`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\UserReceiptController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/receipt HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| receipt_url | string · null | 매출전표 주소. 카드 결제처럼 전표가 있는 수단에서만 채워진다. |
| cash_receipt_url | string · null | 현금영수증 주소. 발급된 경우에만 채워진다. |
| is_test_mode | boolean | 테스트 모드 결제인지. |
| payment_method_label · payment_method_display_label · selected_payment_method | string · null | 결제수단의 기본 이름 · 화면 표시 이름 · 구매자가 고른 수단 식별자. 간편결제는 표시 이름이 기본 이름과 다르다. |
| embedded_pg_provider · embedded_pg_provider_label | string · null | 간편결제 안에서 실제 승인을 처리한 결제사와 그 표시 이름. |

**응답 예시**

```json
{
    "receipt_url": "https://receipt.example.com/…",
    "cash_receipt_url": null,
    "is_test_mode": true,
    "payment_method_label": "신용카드",
    "payment_method_display_label": "카카오페이",
    "selected_payment_method": "kakaopay",
    "embedded_pg_provider": null,
    "embedded_pg_provider_label": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

> **간편결제 id 로 저장된 결제의 라벨** — 주문서에서 고른 KCP 간편결제는 결제수단 칸에 자기 id(예: `nhnkcp_kakaopay`)로 저장될 수 있다. 이때 `payment_method_label` 은 원시 id 가 아니라 PG 이름 `NHN KCP` 이고, `payment_method_display_label` 은 `카카오페이 (NHN KCP)` 다. 결제 메타·`embedded_pg_provider` 가 비어도 결제수단 id 로 간편결제 제공자(`kakaopay`)를 찾는다.


**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/vbank-mock-deposit-info
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.user.orders.vbank-mock-deposit-info -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.user.orders.vbank-mock-deposit-info`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\UserVbankMockDepositController@show`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/vbank-mock-deposit-info HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| available | boolean | 모의 입금을 쓸 수 있는지. 테스트 모드의 가상계좌 주문이 아니면 거짓이고, 그때는 아래 필드가 실리지 않는다. |
| trade_no | string | 결제사 거래번호. 모의 입금 화면에 넣을 값이다. |
| account_no | string | 발급된 가상계좌 번호. |
| notify_url | string | 결제사가 입금 결과를 통보할 주소. 사이트가 하위 폴더에 설치돼 있어도 그 접두사를 포함한 절대 주소로 내려간다. |
| mock_url | string | 결제사가 제공하는 테스트용 모의 입금 화면 주소. |
| is_admin_view | boolean | 관리자가 보고 있는지. 화면이 안내 문구를 가르는 데 쓴다. |

**응답 예시**

```json
{
    "available": true,
    "trade_no": "TNO0123456789",
    "account_no": "12345678901234",
    "notify_url": "https://example.com/shop/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify",
    "mock_url": "https://testadmin.example.com/mock",
    "is_admin_view": false
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->

