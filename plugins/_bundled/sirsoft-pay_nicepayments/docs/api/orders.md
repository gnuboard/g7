# Orders(주문 보조 조회) API 레퍼런스

> **소유**: plugin `sirsoft-pay_nicepayments` · 사람이 서술한 레퍼런스. 관리자 주문 화면과 사용자 영수증 화면이 쓰는 보조 조회 경로를 다룬다. 에스크로 배송 등록은 [escrow.md](escrow.md), 거래번호(TID)로 하는 거래 조회는 [transaction.md](transaction.md) 참조.

---

## TL;DR (5초 요약)

```text
1. 관리자 주문 목록 테스트모드 배지용 맵, 주문 상세의 입금통보 이력과 에스크로 결제 목록과 주문번호 거래 조회, 사용자 영수증 조회
2. 관리자 경로는 auth:sanctum + permission:sirsoft-ecommerce.orders.read
3. 테스트모드 맵은 최근 6개월의 나이스페이 결제 건만 집계한다 (주문 목록 배지 표시용). 간편결제 표시 라벨은 이커머스 주문 목록 응답이 싣는다
4. 영수증 조회는 회원이면 본인 주문만, 비회원이면 X-Guest-Order-Token 검증으로 접근 제어
5. 응답 data 는 화면 표시용 경량 구조 — 주문 상세 데이터는 이커머스 API 가 담당
```

---

### GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/vbank-notifications
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.admin.orders.vbank-notifications -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.admin.orders.vbank-notifications`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminVbankNotificationController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 입금통보 이력을 볼 주문의 주문번호(`orders.order_number`) |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/vbank-notifications HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

주문 결제가 가상계좌가 아니거나 결제 레코드가 없으면 `notifications` 는 빈 배열, `summary` 는 `null` 이다. 이 경로의 응답에는 `message` 키가 없다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| notifications | array | `[{"received_at":"2026-08-17T10:43:22+09:00","type":"deposited",…` | 받은 입금통보를 받은 순서대로 쌓은 이력(결제 메타 `vbank_notifications`). 항목 키는 `received_at`(받은 시각, ISO 8601), `type`(`deposited` 입금완료, `cancelled` 입금취소, `other` 계좌발급과 그 밖의 통보), `result_code`, `result_msg`, `state_cd`, `amt`, `tid`, `auth_date`, `auth_code`, `depositor`(입금자명), `vbank_num`, `vbank_name`, `cancel_date`, `raw_sanitized`, `raw`(허용 키만 남긴 통보 원문) |
| summary | object \| null | `{"count":1,"first_received_at":"2026-08-17T10:43:22+09:00",…` | 이력 한 줄 요약(결제 메타 `vbank_notification_summary`). `count`(누적 통보 수, 재통보 포함), `first_received_at`, `last_received_at`, `last_type`, `last_result_code`, `deposited_at`(첫 입금완료 시각), `cancelled_at`(마지막 입금취소 시각), `last_depositor`, `last_amt`. 이력이 없으면 `null` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "data": {
        "notifications": [
            {
                "received_at": "2026-08-17T10:43:22+09:00",
                "type": "deposited",
                "result_code": "4110",
                "result_msg": "입금완료",
                "state_cd": "0",
                "amt": 30000,
                "tid": "nictest00m02012104191651325596",
                "auth_date": "260817104322",
                "auth_code": "AUTH1",
                "depositor": "홍길동",
                "vbank_num": "1234567890",
                "vbank_name": "국민은행",
                "cancel_date": null,
                "raw_sanitized": true,
                "raw": {
                    "ResultCode": "4110",
                    "TID": "nictest00m02012104191651325596",
                    "Amt": "30000"
                }
            }
        ],
        "summary": {
            "count": 1,
            "first_received_at": "2026-08-17T10:43:22+09:00",
            "last_received_at": "2026-08-17T10:43:22+09:00",
            "last_type": "deposited",
            "last_result_code": "4110",
            "deposited_at": "2026-08-17T10:43:22+09:00",
            "cancelled_at": null,
            "last_depositor": "홍길동",
            "last_amt": 30000
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우. 권한을 「본인만」 또는 「소유역할」 범위로 받은 관리자에게 범위 밖 주문이면 `auth.scope_denied` |
| 404 | Not Found | 주문번호에 해당하는 주문이 없는 경우 (`message`: 주문을 찾을 수 없습니다. `data` 는 `{"notifications": [], "summary": null}`) |

<!-- @generated:end -->

**설명**

관리자 주문 상세 패널이 주문의 가상계좌 **입금통보 수신 이력**을 보여 줄 때 부른다. 주문 결제 응답(`OrderPaymentResource`)은 결제 메타를 노출하지 않으므로, 이 경로가 결제 메타에서 통보 이력만 꺼내 돌려준다. 이력의 통보 원문(`raw`)은 허용 키만 남긴 값이다. 주문이 없으면 404 실패 응답(`success: false`)이고, 그 응답에도 빈 `data` 가 실린다. 통보 수신 경로 자체는 [payment.md](payment.md) 의 `POST /plugins/sirsoft-pay_nicepayments/payment/vbank-notify` 절을 본다.

### GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/test-mode-map
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.admin.orders.test-mode-map -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.admin.orders.test-mode-map`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminOrderListController@testModeMap`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/test-mode-map HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

`data` 는 주문번호를 키로 하는 맵이다. 키가 있는 주문만 테스트 모드 결제이고 값은 언제나 `true` 다. 최근 6개월 안에 만든 이 플러그인 결제(`pg_provider` 가 `nicepayments` 또는 `nicepay`) 중 결제 메타 `is_test_mode` 가 참인 주문만 담는다. 해당 주문이 없으면 빈 배열(`[]`)로 내려간다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| {주문번호} | boolean | `true` | 키가 주문번호, 값 `true` 는 그 주문이 나이스페이먼츠 테스트 모드 결제임을 뜻한다. 권한을 「본인만」 또는 「소유역할」 범위로 받은 관리자에게는 범위 밖 주문번호를 뺀다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "20260817-1043226571": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

최근 6개월(결제 레코드 생성 시각 기준) 나이스페이 결제 건 중 **테스트 모드 자격증명으로 결제된 주문**의 맵을 반환한다. 관리자 주문 목록의 결제수단 배지 확장이 주문 행마다 테스트 모드 표시를 붙일지 정할 때 쓴다.

### 간편결제 표시 라벨 (별도 경로 없음)

주문 목록의 **간편결제(내장 PG) 표시 라벨**은 이커머스 주문 목록 응답의 `payment.payment_method_display_label` 로 전달된다 — 이 플러그인은 필터 훅 `sirsoft-ecommerce.order.filter_payment_method_display_label` 을 구독해 라벨을 채운다(기간 제한 없음). 종전의 `GET .../admin/orders/easy-pay-display-map` 은 1.1.0 에서 제거됐다.

### GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/escrow-payments
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.admin.orders.escrow-payments -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.admin.orders.escrow-payments`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminEscrowController@getEscrowPayments`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 에스크로 결제를 찾을 주문의 주문번호(`orders.order_number`) |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/escrow-payments HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| escrow_payments | array | `[{"id":812,"transaction_id":"nicepay20260814012345",…` | 이 주문의 나이스페이먼츠 에스크로 결제(`is_escrow`) 목록. 항목 키는 `id`(결제 레코드 ID), `transaction_id`(나이스페이먼츠 TID), `payment_method`(결제수단 코드), `payment_status`(결제 상태 코드). 해당 결제가 없거나 주문이 없으면 빈 배열 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "escrow_payments": [
            {
                "id": 812,
                "transaction_id": "nicepay20260814012345",
                "payment_method": "vbank",
                "payment_status": "paid"
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우. 권한을 「본인만」 또는 「소유역할」 범위로 받은 관리자에게 범위 밖 주문이면 `auth.scope_denied` |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

주문번호에 해당하는 주문이 없어도 404 를 돌려주지 않는다. 빈 `escrow_payments` 로 성공 응답한다.

<!-- @generated:end -->

**설명**

관리자 주문 상세의 에스크로 배송 등록 화면이 결제를 고르는 목록으로 쓴다. 로컬 결제 레코드만 읽고 결제대행사는 부르지 않는다. `pg_provider` 가 `nicepayments` 이고 에스크로(`is_escrow`)로 저장된 결제만 담는다(옛 표기 `nicepay` 결제는 담지 않는다). 결제 상태로 거르지 않으므로 목록에 배송 등록 대상이 아닌 결제가 섞일 수 있다. 배송 등록 경로는 결제완료(`paid`) 에스크로 결제만 받는다([escrow.md](escrow.md)).


### GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/transaction-status
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.admin.orders.transaction-status -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.admin.orders.transaction-status`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminTransactionController@queryByOrder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 거래를 조회할 주문의 주문번호(`orders.order_number`). 이 주문의 나이스페이먼츠 결제 레코드 `transaction_id` 로 거래 조회를 한다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/transaction-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

거래번호가 있는 나이스페이먼츠 결제 레코드(`pg_provider` 가 `nicepayments` 또는 `nicepay`)가 있으면 `data` 는 [transaction.md](transaction.md) 의 `POST .../admin/transaction/query` 응답과 같은 객체다. 없으면 `data` 는 `null` 이다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| ResultCode | string | `0000` | NicePay 조회 결과 코드 (원본 응답). 조회 성공은 `0000` |
| ResultMsg | string | `정상처리` | NicePay 조회 결과 메시지 (원본 응답) |
| TID | string | `nictest00m01011104191651325596` | 주문 결제 레코드에서 찾은 거래번호 |
| Status | string | `0` | 거래 상태 (원본 응답). `0` 승인, `1` 취소, `9` 거래 없음 |
| _local_is_escrow | boolean | `false` | 로컬 결제 레코드 기준 에스크로 결제 여부 |
| _pay_method_label | string \| null | `신용카드` | 화면 표시용 최종 결제수단 라벨. 나머지 보강 필드(`_base_pay_method_label`, `_embedded_pg_provider`, `_embedded_pg_provider_label`, `payment_method_display_label`, `EscrowYN`, `_is_test_mode`)는 transaction.md 와 같다 |

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
        "_embedded_pg_provider": null,
        "_embedded_pg_provider_label": null,
        "_pay_method_label": "신용카드",
        "payment_method_display_label": "신용카드",
        "EscrowYN": "N",
        "_is_test_mode": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우. 권한을 「본인만」 또는 「소유역할」 범위로 받은 관리자에게 범위 밖 주문이면 `auth.scope_denied` (결제대행사를 부르지 않는다) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 502 | Bad Gateway | 결제대행사 조회 호출이 실패한 경우 (`message` 는 `common.failed`) |

주문이 없거나 매핑되는 나이스페이먼츠 거래가 없으면 404 가 아니라 `data: null` 성공 응답이다.

<!-- @generated:end -->

**설명**

관리자 주문 상세의 결제 조회 확장이 화면을 열 때 자동으로 부른다. 화면이 TID 를 몰라도 주문번호만으로 조회할 수 있게 결제 레코드에서 거래번호를 찾아 `transaction/query` 와 같은 조회로 넘긴다. 거래번호가 비지 않은 나이스페이먼츠 결제 레코드 중 첫 행을 쓴다. 결제대행사 호출이 실패한 502 응답에는 `errors` 가 없고 원인은 서버 로그(`NicePayments queryTransaction failed`)에만 남는다.


### GET /api/plugins/sirsoft-pay_nicepayments/user/orders/{orderNumber}/receipt
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.user.orders.receipt -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.user.orders.receipt`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\UserReceiptController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 영수증을 볼 주문의 주문번호(`orders.order_number`) |
| X-Guest-Order-Token | header | string | 아니오 | — | 비회원 주문 조회 토큰. 비로그인 요청에서 이 토큰이 주문번호와 맞으면 그 비회원 주문의 영수증을 준다. 토큰이 없거나 맞지 않으면 결제 콜백이 발급한 5분짜리 영수증 쿠키(`nicepayments_receipt_token`)로 확인한다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nicepayments/user/orders/{orderNumber}/receipt HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| receipt_url | string \| null | `https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID=…` | 매출전표 주소. 결제 레코드에 저장된 `receipt_url`, 없으면 거래번호로 만든 `type=0` 주소. 둘 다 없으면 `null` |
| payment_receipt_url | string \| null | `https://npg.nicepay.co.kr/issue/IssueLoader.do?type=2&TID=…` | 마이페이지 「결제 영수증」 링크가 여는 주소(`type=2`). 거래번호가 없으면 `null` |
| cash_receipt_url | string \| null | `null` | 현금영수증 주소(`type=1`). 결제 메타 `rcpt_tid`(없으면 승인 응답 `RcptTID`)가 있을 때만 값이 있다 |
| is_test_mode | boolean | `false` | 테스트 모드 자격으로 결제한 주문인지 여부 (결제 메타 `is_test_mode`) |
| payment_method_label | string \| null | `신용카드` | 결제수단 코드(`payment_method`)의 표시 라벨 |
| payment_method_display_label | string \| null | `카카오페이 (신용카드)` | 화면 표시용 최종 결제수단 라벨. 간편결제면 `제공자 (기본수단)` |
| selected_payment_method | string \| null | `nicepay_kakaopay` | 주문 때 고른 간편결제 수단 코드(결제 메타 `nicepay_easy_pay_method`). 간편결제가 아니면 `null` |
| embedded_pg_provider | string \| null | `kakaopay` | 간편결제 제공자 코드. 간편결제가 아니면 `null` |
| embedded_pg_provider_label | string \| null | `카카오페이` | 간편결제 제공자 표시 라벨. 간편결제가 아니면 `null` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "receipt_url": "https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID=nictest00m01011104191651325596",
        "payment_receipt_url": "https://npg.nicepay.co.kr/issue/IssueLoader.do?type=2&TID=nictest00m01011104191651325596",
        "cash_receipt_url": null,
        "is_test_mode": false,
        "payment_method_label": "신용카드",
        "payment_method_display_label": "카카오페이 (신용카드)",
        "selected_payment_method": "nicepay_kakaopay",
        "embedded_pg_provider": "kakaopay",
        "embedded_pg_provider_label": "카카오페이"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 주문의 나이스페이먼츠 결제가 없거나, 로그인한 회원의 주문이 아니거나, 비로그인 요청이 조회 토큰과 영수증 쿠키 모두로 확인되지 않은 경우 (`message` 는 `common.not_found`). 다른 사람의 주문인지 여부를 따로 알려 주지 않는다 |

<!-- @generated:end -->

> **간편결제 id 로 저장된 결제의 라벨** — 주문서에서 고른 나이스 간편결제는 결제수단 칸에 자기 id(예: `nicepay_kakaopay`)로 저장될 수 있다. 이때 `payment_method_label` 은 원시 id 가 아니라 PG 이름 `나이스페이먼츠` 이고, `payment_method_display_label` 은 `카카오페이 (나이스페이먼츠)` 다. 결제 메타·`embedded_pg_provider` 가 비어도 결제수단 id 로 간편결제 제공자(`kakaopay`)를 찾는다. 위 예시처럼 결제수단이 `card` 로 저장된 결제는 종전대로 `카카오페이 (신용카드)` 다.


**설명**

사용자 마이페이지의 영수증 확장이 주문의 나이스페이 **매출전표(영수증) 주소**를 받을 때 부른다. 영수증 주소는 레이아웃에 적지 않고 이 응답에서 받아 쓴다(레이아웃에 결제사 외부 주소를 적으면 편집기 저장 검사가 외부 URL 로 거절한다).

접근 확인은 다음 순서다. 로그인한 회원은 자기 주문(`user_id` 일치)만 볼 수 있고, 비회원 조회 토큰을 함께 보내도 비회원 주문은 보이지 않는다. 비로그인 요청은 `X-Guest-Order-Token` 이 주문번호와 맞으면 그 비회원 주문을, 아니면 결제 콜백이 발급한 영수증 쿠키(`nicepayments_receipt_token`, 5분)가 주문번호와 맞으면 그 비회원 주문을 본다. 확인에 실패하면 다른 사람의 주문인지 알리지 않고 대상 없음(404, `{ "success": false, "message": "…" }`)으로 처리한다.

`receipt_url`(매출전표, `type=0`)과 `payment_receipt_url`(마이페이지 「결제 영수증」 링크가 여는 주소, `type=2`)은 다른 화면이 쓰는 다른 값이라 서로 대신하지 않는다.
