# Orders API 레퍼런스

> **소유**: plugin `sirsoft-pay_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Orders 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-pay_kginicis/admin/orders/test-mode-map
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.test-mode-map -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.test-mode-map`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminOrderListController@testModeMap`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_kginicis/admin/orders/test-mode-map HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| 20260619-1358556131 | boolean | `true` | 응답 객체의 키가 곧 주문번호이며 값 `true`는 해당 주문이 KG 이니시스 테스트 모드 결제임을 뜻한다. 목록에 존재하는 주문번호만 테스트 결제로 간주하면 된다. |
| 20260619-1425382147 | boolean | `true` | 위와 동일 — 키가 주문번호, 값 `true`는 테스트 모드 결제 주문임을 나타낸다. |
| APIDOC-KGINICIS-000001 | boolean | `true` | 위와 동일 — 키가 주문번호, 값 `true`는 테스트 모드 결제 주문임을 나타낸다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "APIDOC-KGINICIS-000001": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**
`AdminOrderListController@testModeMap` 가 최근 6개월 이내 `pg_provider = kginicis` 결제 주문을 조회해 그중 테스트 모드 결제만 `{ "주문번호": true, ... }` 맵으로 반환한다. 테스트 판별은 `payment_meta.is_test_mode === true` → `pg_raw_response.mid` 가 KG 이니시스 Live MID 접두사(`SIR`)가 아님 → `transaction_id` 에 `Test` 포함 순으로 이루어진다. 어드민 주문 목록에서 결제수단 셀 하단에 "(테스트 결제)" 배지를 붙이는 용도이며, 응답 필드 키가 곧 주문번호이므로 특정 주문이 맵에 존재하면 테스트 결제로 간주하면 된다. `sirsoft-ecommerce.orders.read` 권한이 필요하고, 파라미터 없이 전체 맵을 한 번에 조회한다.


### POST /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/cash-receipt
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.cash-receipt.issue -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.cash-receipt.issue`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminCashReceiptController@issue`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/cash-receipt HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**
`AdminCashReceiptController@issue` 가 이미 승인 완료된 KG 이니시스 결제 건에 대해 현금영수증을 별도 발행한다. 요청의 `issue_type`(`0`=소득공제, `1`=지출증빙)과 `issue_number`(휴대폰/사업자번호 등 식별번호)를 검증한 뒤, `paid_amount_local` 기준 금액과 부가세(저장값 우선, 없으면 총액의 10/110)를 계산해 `KgInicisApiService::issueCashReceipt` 로 발행 요청을 보낸다. 발행 성공 시 `is_cash_receipt_issued`, `cash_receipt_type`, 마스킹된 식별번호(`cash_receipt_identifier`, 끝 4자리만 노출)를 저장하며, 식별번호 원문은 저장하지 않는다. `sirsoft-ecommerce.orders.update` 권한이 필요하고, 검증 실패 422 / 주문 미존재 404 / 이미 발행됨 409 / PG 발행 실패(resultCode≠`00`) 502 / 예외 500 으로 상태코드가 매핑된다.


### GET /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/cbt-cvs
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.show -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.show`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtCvsOperationsController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/cbt-cvs HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| is_cbt_cvs | boolean | `true` | cbt cvs 여부 |
| order_number | string | `APIDOC-KGINICIS-000001` | 주문번호 |
| order_status | string | `pending_payment` | 주문상태 (OrderStatusEnum) |
| payment_status | string | `paid` | 결제 상태값(PaymentStatusEnum). CVS 입금 흐름에서는 `waiting_deposit`(입금대기) → `paid`(입금완료) 또는 `expired`(기한만료) 로 전이한다. |
| tid | string | `INIJPGCARDapidocsmpl0000000001` | KG 이니시스 거래 ID(transaction_id). 결제 승인·통보를 식별하는 이니시스 측 거래번호다. |
| amount | integer | `5000` | 청구 금액. 결제 메타의 `cvs_amount`(CVS 통보 기준 결제 통화 환산액)를 우선 사용하고, 없으면 결제 승인액 또는 주문 총 청구액으로 대체한다. |
| currency | string | `KRW` | 결제 통화 (KRW, USD, EUR 등) |
| cbt_mid | string | `apidocmid1` | CBT(일본결제)에 사용된 KG 이니시스 일본 가맹점 ID. 입금 통보(NOTI) 검증 시 통보의 mid와 대조하는 기준값이다. |
| cbt_sid | string | `apidocsid1` | CBT 결제 세션/상점 식별자(SID). 입금 통보의 sid와 일치하는지 검증하는 데 사용된다. |
| is_test_mode | boolean | `true` | test mode 여부 |
| convenience | string | `seven_eleven` | 구매자가 선택한 일본 편의점 식별값. 어느 편의점에서 입금하는지를 나타낸다. |
| conf_no | string | `1234567890` | 편의점 입금용 확인번호(수납확인번호). 구매자가 편의점 단말에서 입력·제시하는 번호다. |
| receipt_no | string | `0987654321` | 편의점 입금용 접수번호(수납번호). 확인번호와 함께 편의점 결제 접수를 식별한다. |
| payment_term | string | `20260710235959` | 편의점 입금 마감 일시(YmdHis 압축 문자열). 이 기한이 지나면 시간 경과 만료 대상이 된다. |
| payment_term_formatted | string | `2026-07-10 23:59:59` | `payment_term` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_expired_by_time | boolean | `false` | expired by time 여부 |
| cvs_status | string | `waiting_deposit` | CVS 입금 상태(`payment_meta.cvs_status`). 입금대기(`waiting_deposit`)·입금완료(`paid`)·만료(`expired`) 등을 나타내며, 메타값이 없고 결제가 입금대기면 `waiting_deposit`로 채운다. |
| last_notify_at | string | `` | last notify 일시 |
| last_notify_result | string | `` | 마지막 입금 통보(NOTI) 처리 결과. `confirmed`(입금확정)·`ignored`(무시)·`failed`(검증실패) 중 하나가 기록된다. |
| last_notify_reason | string | `` | 마지막 통보 처리 결과의 상세 사유(예: `deposit_confirmed`, `tid_mismatch`, `amount_mismatch`, `already_paid` 등). |
| notify_history | array | `[]` | 최근 입금 통보 이력 목록(최대 10건). 각 항목은 수신시각·발신IP·결과·사유와 통보 payload 요약(tid·금액·통화 등)을 담는다. |
| notify_url | string | `https://api.example.com/plugins/sirsoft-pay_…` | notify URL |
| can_simulate_notify | boolean | `false` | simulate notify 수행 가능 여부 (권한 기반) |
| can_mark_expired | boolean | `false` | mark expired 수행 가능 여부 (권한 기반) |
| last_recheck_at | string | `` | last recheck 일시 |
| last_recheck_result | string | `` | 마지막 로컬 상태 재확인 결과. recheck 액션 실행 시 `local_status_checked` 로 기록되며, CBT 편의점 입금은 외부 PG 조회 대상이 아니라 로컬 확인 흔적만 남긴다. |
| expired_at | string | `` | expired 일시 |
| expiry_reason | string | `` | 만료 처리 사유. 입금 기한 경과로 만료된 경우 `payment_term_elapsed` 가 기록된다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "is_cbt_cvs": true,
        "order_number": "APIDOC-KGINICIS-000001",
        "order_status": "pending_payment",
        "payment_status": "paid",
        "tid": "INIJPGCARDapidocsmpl0000000001",
        "amount": 5000,
        "currency": "KRW",
        "cbt_mid": "apidocmid1",
        "cbt_sid": "apidocsid1",
        "is_test_mode": true,
        "convenience": "seven_eleven",
        "conf_no": "1234567890",
        "receipt_no": "0987654321",
        "payment_term": "20260710235959",
        "payment_term_formatted": "2026-07-10 23:59:59",
        "is_expired_by_time": false,
        "cvs_status": "waiting_deposit",
        "last_notify_at": "",
        "last_notify_result": "",
        "last_notify_reason": "",
        "notify_history": [],
        "notify_url": "https://api.example.com/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify",
        "can_simulate_notify": false,
        "can_mark_expired": false,
        "last_recheck_at": "",
        "last_recheck_result": "",
        "expired_at": "",
        "expiry_reason": ""
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

**설명**
`AdminCbtCvsOperationsController@show` 가 `CbtCvsOperationsService::summary` 를 호출해 CBT(일본결제) 편의점(CVS) 입금 결제의 운영 요약을 반환한다. 주문번호로 결제·메타(`payment_meta`)를 읽어 입금 상태(`cvs_status`), 편의점 확인번호/접수번호(`conf_no`/`receipt_no`), 입금 마감(`payment_term` 및 포맷된 값), 시간 경과 만료 여부(`is_expired_by_time`), NOTI 통보 이력(`notify_history`), 통보 수신 URL 등을 집계한다. `can_simulate_notify`(테스트 모드 + 입금대기)와 `can_mark_expired`(입금대기 + 기한 경과)는 후속 운영 액션의 버튼 노출을 제어하는 게이트값이다. `sirsoft-ecommerce.orders.read` 권한이 필요하며, 주문이 없으면 플러그인 표준 404(`order_not_found`)를 반환한다.


### POST /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/cbt-cvs/expire
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.expire -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.expire`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtCvsOperationsController@expire`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/cbt-cvs/expire HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**
`AdminCbtCvsOperationsController@expire` 가 `CbtCvsOperationsService::expireOverdue` 를 호출해 입금 기한이 지난 CBT 편의점 결제를 만료 상태로 전환한다. 결제 row 를 트랜잭션 내에서 잠근 뒤 `canMarkExpired`(CVS 메타 + `waiting_deposit` 상태 + `cvs_payment_term` 경과)를 재판정해 통과할 때만 `payment_status` 를 `expired` 로 바꾸고 메타에 `cvs_expired_at`·`cvs_expiry_reason=payment_term_elapsed` 를 기록한다. 잠금·재판정으로 동시 입금 통보(NOTI)와의 경합을 방지하며, 만료 조건 미충족 시 `not_expirable` 422 를 반환한다. `sirsoft-ecommerce.orders.update` 권한이 필요하고, CVS 결제가 아니면 `not_cvs` 422, 주문 미존재 시 404 로 응답한다.


### POST /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/cbt-cvs/recheck
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.recheck -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.recheck`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtCvsOperationsController@recheck`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/cbt-cvs/recheck HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| is_cbt_cvs | boolean | `true` | cbt cvs 여부 |
| order_number | string | `APIDOC-KGINICIS-000001` | 주문번호 |
| order_status | string | `pending_payment` | 주문상태 (OrderStatusEnum) |
| payment_status | string | `paid` | 결제 상태값(PaymentStatusEnum). CVS 입금 흐름에서는 `waiting_deposit`(입금대기) → `paid`(입금완료) 또는 `expired`(기한만료) 로 전이한다. |
| tid | string | `INIJPGCARDapidocsmpl0000000001` | KG 이니시스 거래 ID(transaction_id). 결제 승인·통보를 식별하는 이니시스 측 거래번호다. |
| amount | integer | `5000` | 청구 금액. 결제 메타의 `cvs_amount`(CVS 통보 기준 결제 통화 환산액)를 우선 사용하고, 없으면 결제 승인액 또는 주문 총 청구액으로 대체한다. |
| currency | string | `KRW` | 결제 통화 (KRW, USD, EUR 등) |
| cbt_mid | string | `apidocmid1` | CBT(일본결제)에 사용된 KG 이니시스 일본 가맹점 ID. 입금 통보(NOTI) 검증 시 통보의 mid와 대조하는 기준값이다. |
| cbt_sid | string | `apidocsid1` | CBT 결제 세션/상점 식별자(SID). 입금 통보의 sid와 일치하는지 검증하는 데 사용된다. |
| is_test_mode | boolean | `true` | test mode 여부 |
| convenience | string | `seven_eleven` | 구매자가 선택한 일본 편의점 식별값. 어느 편의점에서 입금하는지를 나타낸다. |
| conf_no | string | `1234567890` | 편의점 입금용 확인번호(수납확인번호). 구매자가 편의점 단말에서 입력·제시하는 번호다. |
| receipt_no | string | `0987654321` | 편의점 입금용 접수번호(수납번호). 확인번호와 함께 편의점 결제 접수를 식별한다. |
| payment_term | string | `20260710235959` | 편의점 입금 마감 일시(YmdHis 압축 문자열). 이 기한이 지나면 시간 경과 만료 대상이 된다. |
| payment_term_formatted | string | `2026-07-10 23:59:59` | `payment_term` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_expired_by_time | boolean | `false` | expired by time 여부 |
| cvs_status | string | `waiting_deposit` | CVS 입금 상태(`payment_meta.cvs_status`). 입금대기(`waiting_deposit`)·입금완료(`paid`)·만료(`expired`) 등을 나타내며, 메타값이 없고 결제가 입금대기면 `waiting_deposit`로 채운다. |
| last_notify_at | string | `` | last notify 일시 |
| last_notify_result | string | `` | 마지막 입금 통보(NOTI) 처리 결과. `confirmed`(입금확정)·`ignored`(무시)·`failed`(검증실패) 중 하나가 기록된다. |
| last_notify_reason | string | `` | 마지막 통보 처리 결과의 상세 사유(예: `deposit_confirmed`, `tid_mismatch`, `amount_mismatch`, `already_paid` 등). |
| notify_history | array | `[]` | 최근 입금 통보 이력 목록(최대 10건). 각 항목은 수신시각·발신IP·결과·사유와 통보 payload 요약(tid·금액·통화 등)을 담는다. |
| notify_url | string | `http://localhost/plugins/sirsoft-pay_…` | notify URL |
| can_simulate_notify | boolean | `false` | simulate notify 수행 가능 여부 (권한 기반) |
| can_mark_expired | boolean | `false` | mark expired 수행 가능 여부 (권한 기반) |
| last_recheck_at | string | `2026-07-08T06:32:39+00:00` | last recheck 일시 |
| last_recheck_result | string | `local_status_checked` | 마지막 로컬 상태 재확인 결과. recheck 액션 실행 시 `local_status_checked` 로 기록되며, CBT 편의점 입금은 외부 PG 조회 대상이 아니라 로컬 확인 흔적만 남긴다. |
| expired_at | string | `` | expired 일시 |
| expiry_reason | string | `` | 만료 처리 사유. 입금 기한 경과로 만료된 경우 `payment_term_elapsed` 가 기록된다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-pay_kginicis::messages.cbt_cvs.recheck_success",
    "data": {
        "is_cbt_cvs": true,
        "order_number": "APIDOC-KGINICIS-000001",
        "order_status": "pending_payment",
        "payment_status": "paid",
        "tid": "INIJPGCARDapidocsmpl0000000001",
        "amount": 5000,
        "currency": "KRW",
        "cbt_mid": "apidocmid1",
        "cbt_sid": "apidocsid1",
        "is_test_mode": true,
        "convenience": "seven_eleven",
        "conf_no": "1234567890",
        "receipt_no": "0987654321",
        "payment_term": "20260710235959",
        "payment_term_formatted": "2026-07-10 23:59:59",
        "is_expired_by_time": false,
        "cvs_status": "waiting_deposit",
        "last_notify_at": "",
        "last_notify_result": "",
        "last_notify_reason": "",
        "notify_history": [],
        "notify_url": "http://localhost/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify",
        "can_simulate_notify": false,
        "can_mark_expired": false,
        "last_recheck_at": "2026-07-08T06:32:39+00:00",
        "last_recheck_result": "local_status_checked",
        "expired_at": "",
        "expiry_reason": ""
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

**설명**
`AdminCbtCvsOperationsController@recheck` 가 `CbtCvsOperationsService::markRechecked` 를 호출해 CBT 편의점 결제의 로컬 상태 확인 시각만 메타에 기록한다(`cvs_last_recheck_at`, `cvs_last_recheck_result=local_status_checked`). CBT 편의점 입금은 한국 INIAPI 거래조회 대상이 아니므로 외부 PG 조회 없이 관리자가 "로컬 상태를 확인했다"는 감사 흔적을 남기는 용도이며, 결제 상태 자체는 변경하지 않는다. 처리 후 갱신된 운영 요약(summary)을 반환한다. `sirsoft-ecommerce.orders.read` 권한이 필요하고, CVS 결제가 아니면 `not_cvs` 422, 주문 미존재 시 404 로 응답한다.


### POST /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/cbt-cvs/simulate-notify
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.simulate-notify -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-cvs.simulate-notify`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtCvsOperationsController@simulateNotify`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/cbt-cvs/simulate-notify HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**
`AdminCbtCvsOperationsController@simulateNotify` 가 `CbtCvsOperationsService::simulatePaidNotify` 를 호출해, 테스트 모드에 한해 편의점 입금 완료 NOTI(`status=00`)를 관리자 동작으로 합성·재생한다. 저장된 메타(`cbt_mid`/`cbt_sid`/`cvs_*`)와 결제 통화 환산 금액으로 payload 를 만들어 실제 통보 처리 경로(`handleNotify`)에 흘려보내므로, 정상 처리 시 결제가 입금완료로 전환되고 주문 결제가 확정된다. 가드로 `is_test_mode` 가 아니면 `not_test_mode` 422, 결제가 `waiting_deposit` 이 아니면 `not_waiting_deposit` 422, 합성 통보가 OK 가 아니면 `simulate_failed` 422 를 반환한다. `sirsoft-ecommerce.orders.update` 권한이 필요하며, 운영(Live) 결제에는 사용할 수 없는 테스트 전용 도구다.


### GET /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/cbt-reconciliation
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-reconciliation.show -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-reconciliation.show`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtReconciliationController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/cbt-reconciliation HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**
`AdminCbtReconciliationController@show` 가 `CbtReconciliationService::get` 을 호출해 주문에 연결된 CBT 자동환불 조정(reconciliation) 레코드를 반환한다. 이 레코드는 CBT 결제의 자동환불 처리 이력(상태, TID, 금액, 환불 결과/에러, 재시도 횟수)을 담으며, `status` 에서 파생된 `manual_action_required`(수동 환불 필요 여부)와 `can_retry`(수동환불필요 + TID 존재 시 재시도 가능)를 함께 노출한다. 조정 레코드가 없는 주문이면 `data` 가 `null` 로 내려간다(정상 응답). `sirsoft-ecommerce.orders.read` 권한이 필요하며, 관리자 화면에서 자동환불 실패 건의 재시도 버튼 활성화 판단에 사용된다.


### POST /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/cbt-reconciliation/refund-retry
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-reconciliation.refund-retry -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.cbt-reconciliation.refund-retry`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtReconciliationController@retryRefund`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/cbt-reconciliation/refund-retry HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**
`AdminCbtReconciliationController@retryRefund` 가 실패한 CBT 자동환불을 관리자 동작으로 재시도한다. 먼저 `claimRefundRetry` 로 레코드를 잠금 획득하는데, `can_retry`(상태가 `manual_refund_required` + TID 존재)가 아니면 획득에 실패해 `not_retryable` 422 를 반환한다. 획득 성공 시 저장된 CBT 자격증명(`is_test_mode`/`cbt_mid`)으로 `KgInicisApiService::refundCbtPayment` 를 호출하고, 성공하면 상태를 `auto_refunded` 로, 예외 발생 시 `manual_refund_required` 로 되돌리며 에러를 기록한다. `sirsoft-ecommerce.orders.update` 권한이 필요하고, 환불 API 실패 시 `retry_failed` 502 로 응답한다.


### GET /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/escrow-delivery
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-delivery.form -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-delivery.form`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDeliveryController@formData`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/escrow-delivery HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| has_escrow_payment | boolean | `true` | escrow payment 여부 |
| tid | string | `INIJPGCARDapidocsmpl0000000001` | 배송정보를 등록할 에스크로 결제의 KG 이니시스 거래 ID(transaction_id). INIAPI 배송등록 호출의 대상 거래를 지정한다. |
| price | integer | `170624` | 에스크로 결제 금액(paid_amount_local을 정수 반올림). 배송등록 요청의 price로 전송된다. |
| courier_codes | object | `{"hanjin":"한진택배","cjgls":"CJ대한통운","loge":"롯데택배","epost":"…` | KG 이니시스 공식 택배사 코드표(코드→택배사명). 배송등록 시 `ex_code`는 이 표에 존재하는 코드여야 한다. |
| prefill | object | `{"recvName":"API 문서 샘플 수령인","recvTel":"010-0000-0002","re…` | 배송지 DB에서 채운 수령인 선입력값. 수령인명(`recvName`)·연락처(`recvTel`)·우편번호(`recvPost`)·주소(`recvAddr`)를 담아 등록 폼을 미리 채운다. |
| registered_delivery | null | `null` | 이미 등록된 배송 이력(`payment_meta.escrow_delivery`). 미등록이면 `null`이며, 값이 있으면 운송장·택배사 등 중복 등록 방지 판단에 쓰인다. |
| escrow_confirm | null | `null` | 구매확정 이력(`payment_meta.escrow_confirm`). 구매자 구매확정 처리 전이면 `null`이다. |
| deny_confirmed | boolean | `false` | 판매자 구매거절 확인 이력(`payment_meta.escrow_deny_confirm`) 존재 여부. 이미 거절확인했으면 `true`가 되어 중복 처리를 막는다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "has_escrow_payment": true,
        "tid": "INIJPGCARDapidocsmpl0000000001",
        "price": 460829,
        "courier_codes": {
            "hanjin": "한진택배",
            "cjgls": "CJ대한통운",
            "loge": "롯데택배",
            "epost": "우체국택배",
            "lotte": "롯데글로벌로지스",
            "kdexp": "경동택배",
            "cvs": "편의점택배",
            "ilyang": "일양로지스",
            "chunil": "천일택배",
            "cvsnet": "CVSnet편의점",
            "daesin": "대신택배",
            "kunyoung": "건영택배",
            "gsilogis": "GSI Express",
            "etc": "기타"
        },
        "prefill": {
            "recvName": "API 문서 샘플 수령인",
            "recvTel": "010-0000-0002",
            "recvPost": "06134",
            "recvAddr": "서울특별시 강남구 테헤란로 001 API 문서 샘플 빌딩 1층"
        },
        "registered_delivery": null,
        "escrow_confirm": null,
        "deny_confirmed": false
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

**설명**
`AdminEscrowDeliveryController@formData` 가 에스크로 배송등록 폼에 필요한 초기 데이터를 반환한다. 대상 주문의 KG 이니시스 에스크로 결제(`is_escrow = true`)를 찾아 TID, 결제금액, 공식 택배사 코드표(`courier_codes`), 배송지 기반 수령인 선입력값(`prefill`)을 내려주고, `payment_meta` 에서 이미 등록된 배송 이력(`registered_delivery`)·구매확정(`escrow_confirm`)·구매거절확인(`deny_confirmed`) 여부를 함께 제공한다. 에스크로 결제가 아닌 주문은 `has_escrow_payment` 없이 `data` 가 `null` 로 내려간다. `sirsoft-ecommerce.orders.read` 권한이 필요하며, 실제 배송등록(POST) 전 화면 표시·중복 등록 방지 판단에 사용된다.


### POST /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/escrow-delivery
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-delivery.register -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-delivery.register`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDeliveryController@register`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/escrow-delivery HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**
`AdminEscrowDeliveryController@register` 가 KG 이니시스 에스크로 결제의 배송정보를 INIAPI 에 등록한다. 운송장번호(`invoice`)와 택배사코드(`ex_code`, 공식 코드표에 존재해야 함)를 필수 검증하고, 수령인 정보는 요청값 우선·부재 시 배송지 DB 로 채운다. 에스크로 결제는 반드시 에스크로 자격증명(`useEscrowCredentials`)으로 `registerEscrowDelivery` 를 호출하며, 성공 시 정제된 PG 응답과 배송 이력을 `payment_meta.escrow_delivery` 에 저장한다. `sirsoft-ecommerce.orders.update` 권한이 필요하고, 입력 검증 실패 422 / 에스크로 결제 미존재 404 / PG 실패(resultCode≠`00`) 502 / 예외 500 으로 매핑된다.


### POST /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/escrow-deny-confirm
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-deny-confirm -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.escrow-deny-confirm`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDenyConfirmController@confirm`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/escrow-deny-confirm HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-502 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**
`AdminEscrowDenyConfirmController@confirm` 이 구매자가 구매거절을 선택한 에스크로 주문에 대해 판매자(관리자) 측 거절 확인을 처리한다(INIAPI v1 `type=Dncf`). 대상 에스크로 결제(`is_escrow = true`)를 찾아 이미 거절확인 이력(`payment_meta.escrow_deny_confirm`)이 있으면 중복 처리 없이 422 로 막고, 에스크로 자격증명으로 `denyConfirmEscrow` 를 호출한다. 성공 시 확인 시각과 담당자명(`dcnf_name`, 기본 "관리자"), 정제된 PG 응답을 메타에 기록한다. `sirsoft-ecommerce.orders.update` 권한이 필요하며, 에스크로 결제 미존재 404 / 이미 확인됨 422 / PG 실패(resultCode≠`00`) 502 / 예외 500 으로 매핑된다.


### GET /api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/transaction-status
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.orders.transaction-status -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.orders.transaction-status`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminTransactionController@queryByOrder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_kginicis/admin/orders/APIDOC-KGINICIS-000001/transaction-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| resultCode | string | `0000` | 거래조회 결과 코드. `queryTransaction` API 응답의 처리 결과 코드이며, CBT 로컬 확인 경로에서는 저장된 승인 코드가 없으면 `LOCAL_CBT` 로 대체된다. |
| resultMsg | string | `정상처리` | 결과 코드에 대응하는 메시지. CBT 로컬 확인 시에는 "CBT 거래는 로컬 결제 확인 정보로 표시됩니다." 안내 문구가 들어간다. |
| tid | string | `INIJPGCARDapidocsmpl0000000001` | 조회 대상 KG 이니시스 거래 ID(transaction_id). |
| _is_cbt | boolean | `true` | 이 거래가 CBT(일본결제)인지 여부. TID가 `INIJPG`로 시작하거나 통화가 JPY이면 CBT로 판정된다. |
| _is_local_confirmation | boolean | `true` | 응답이 실제 INIAPI 조회가 아니라 로컬 저장 정보로 구성됐는지 여부. CBT는 한국 INIAPI 거래조회 대상이 아니라 이 값이 `true`가 된다. |
| _is_test_mode | boolean | `true` | 결제가 테스트 모드였는지 여부. `payment_meta.is_test_mode` 또는 자격증명 모드에서 파생된다. |
| _local_is_escrow | boolean | `true` | 로컬 결제 레코드의 에스크로 결제 여부(`is_escrow`). |
| _pay_method | string | `CVS` | KG 이니시스 결제수단 코드(대문자 정규화). 예: `CARD`(신용카드)·`VBANK`(가상계좌)·`CVS`(일본 편의점결제) 등. |
| _base_pay_method_label | string | `일본 편의점결제` | `_base_pay_method` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| _embedded_pg_provider | null | `null` | 간편결제 등 결제창에 내장된 실제 PG 제공사 식별값(예: `kakaopay`, `naverpay`). 일반 결제면 `null`이다. |
| _embedded_pg_provider_label | null | `null` | `_embedded_pg_provider` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| _pay_method_label | string | `일본 편의점결제` | `_pay_method` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| _auth_code | null | `null` | 승인번호. CVS 결제는 확인번호/접수번호를 대체로 사용하며, 카드 등에서는 승인번호(applNum)가 채워진다. |
| _auth_date | string | `2026-07-07 07:17:11` | 승인 일시(YYYY-MM-DD HH:MM:SS 포맷). 승인 일시가 없으면 로컬 결제완료 시각(`paid_at`)으로 대체된다. |
| _total_price | string | `5000` | 결제 총액. 조회 응답 또는 로컬 저장 금액에서 취한 값이다. |
| _currency | string | `JPY` | 결제 통화 코드. CBT는 기본 `JPY`, 일반 국내결제는 원화(응답에 없으면 `WON`)로 표기된다. |
| _moid | string | `APIDOC-KGINICIS-000001` | 가맹점 주문번호(MOID). 이 거래에 대응하는 주문의 주문번호다. |
| _buyer_name | string | `API 문서 샘플 구매자` | 구매자 이름. |
| _buyer_email | string | `apidoc-sample-user@example.com` | 구매자 이메일. |
| _buyer_tel | string | `010-0000-0001` | 구매자 전화번호. |
| _status | string | `waiting_deposit` | 거래/결제 상태. CVS는 결제 상태(`waiting_deposit` 등), 일반 결제는 조회 응답의 거래 상태값이 들어간다. |
| _cancel_price | null | `null` | 취소(환불) 금액. 취소 이력이 없으면 `null`이다. |
| _cancel_date | null | `null` | 취소 일시(YYYY-MM-DD HH:MM:SS). 취소 이력이 없으면 `null`이다. |
| _part_cancel_list | array | `[]` | 부분취소 이력 목록. 각 항목은 취소 금액·일시·메시지·취소 TID로 정규화된다. |
| _card_name | string | `KB국민카드` | 카드 결제 시 발급사(카드사) 이름. |
| _card_num | string | `1554-****-****-9102` | 마스킹된 카드번호(중간 자리는 `*` 처리). |
| _card_code | null | `null` | KG 이니시스 카드사 코드. 없으면 `null`이다. |
| _card_quota | string | `일시불` | 할부 개월. `0`이면 `일시불`, 그 외에는 `N개월`로 변환된다. |
| _card_interest | null | `null` | 무이자 할부 여부. CBT 로컬 확인 경로에서는 `null`이다. |
| _vbank_num | null | `null` | 가상계좌 번호. CVS 결제에서는 확인번호/접수번호로 대체되며, 미해당 시 `null`이다. |
| _vbank_bank_code | null | `null` | 가상계좌 은행 코드. CVS 결제에서는 편의점 식별값(`convenience`)이 들어갈 수 있고, 미해당 시 `null`이다. |
| _vbank_bank_name | string | `CVS` | 가상계좌 은행명. 은행명이 없으면 은행 코드로 매핑하며, 편의점(CVS) 결제에서는 `CVS`로 표기된다. |
| _vbank_holder | null | `null` | 가상계좌 예금주명. 미해당 시 `null`이다. |
| _vbank_expire_date | string | `2026-07-10 23:59:59` | 가상계좌/편의점 입금 마감 일시. 로컬 `vbank_due_at`가 있으면 KST로 변환해 사용하고, 없으면 조회 응답의 입금기한을 포맷한다. |
| _vbank_status | string | `waiting_deposit` | 가상계좌/편의점 입금 상태(입금대기 등). |
| _vbank_paid_at | null | `null` |  vbank paid 일시 |
| _bank_code | null | `null` | 계좌이체 은행 코드. 계좌이체 결제가 아니면 `null`이다. |
| _bank_name | null | `null` | 계좌이체 은행명. 은행명이 없으면 은행 코드로 매핑하며, 미해당 시 `null`이다. |
| _bank_acnt_num | null | `null` | 계좌이체 계좌번호. 미해당 시 `null`이다. |
| _hpp_num | null | `null` | 휴대폰 결제 시 결제 휴대폰 번호. 미해당 시 `null`이다. |
| _hpp_corp | null | `null` | 휴대폰 결제 시 이동통신사. 미해당 시 `null`이다. |
| _escrow_status | null | `null` | 에스크로 거래 상태. 에스크로 결제가 아니거나 CBT 로컬 확인 경로에서는 `null`이다. |
| _escrow_confirm | null | `null` | 에스크로 구매확정 일시(YYYY-MM-DD HH:MM:SS). 미확정 시 `null`이다. |
| _inquiry_at | string | `2026-07-07 07:24:51` |  inquiry 일시 |
| _local_notice | string | `CBT 거래는 한국 INIAPI 거래조회 대상이 아니므로 저장된 승…` | CBT 로컬 확인 경로에서만 채워지는 안내 문구. 이 거래가 실시간 PG 조회가 아니라 저장된 승인/입금 정보로 표시됨을 관리자에게 알린다. |
| _cbt_cvs | object | `{"status":"waiting_deposit","last_notify_at":"","last_not…` | CBT 편의점(CVS) 결제일 때만 채워지는 입금 운영 요약. 입금 상태·최근 통보/재확인 결과·만료 정보·최근 통보 이력(최대 10건)을 담으며, CVS가 아니면 `null`이다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "resultCode": "0000",
        "resultMsg": "정상처리",
        "tid": "INIJPGCARDapidocsmpl0000000001",
        "_is_cbt": true,
        "_is_local_confirmation": true,
        "_is_test_mode": true,
        "_local_is_escrow": true,
        "_pay_method": "CVS",
        "_base_pay_method_label": "일본 편의점결제",
        "_embedded_pg_provider": null,
        "_embedded_pg_provider_label": null,
        "_pay_method_label": "일본 편의점결제",
        "_auth_code": null,
        "_auth_date": "2026-07-08 06:32:34",
        "_total_price": "5000",
        "_currency": "JPY",
        "_moid": "APIDOC-KGINICIS-000001",
        "_buyer_name": "API 문서 샘플 구매자",
        "_buyer_email": "apidoc-sample-user@example.com",
        "_buyer_tel": "010-0000-0001",
        "_status": "waiting_deposit",
        "_cancel_price": null,
        "_cancel_date": null,
        "_part_cancel_list": [],
        "_card_name": "신한카드",
        "_card_num": "2570-****-****-6458",
        "_card_code": null,
        "_card_quota": "일시불",
        "_card_interest": null,
        "_vbank_num": null,
        "_vbank_bank_code": null,
        "_vbank_bank_name": "CVS",
        "_vbank_holder": null,
        "_vbank_expire_date": "2026-07-10 23:59:59",
        "_vbank_status": "waiting_deposit",
        "_vbank_paid_at": null,
        "_bank_code": null,
        "_bank_name": null,
        "_bank_acnt_num": null,
        "_hpp_num": null,
        "_hpp_corp": null,
        "_escrow_status": null,
        "_escrow_confirm": null,
        "_inquiry_at": "2026-07-08 06:32:40",
        "_local_notice": "CBT 거래는 한국 INIAPI 거래조회 대상이 아니므로 저장된 승인/입금 확인 정보로 표시됩니다.",
        "_cbt_cvs": {
            "status": "waiting_deposit",
            "last_notify_at": "",
            "last_notify_result": "",
            "last_notify_reason": "",
            "last_recheck_at": "",
            "last_recheck_result": "",
            "expired_at": "",
            "expiry_reason": "",
            "notify_history": []
        }
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

**설명**
`AdminTransactionController@queryByOrder` 가 주문번호로 KG 이니시스 결제의 실시간 거래 상태를 조회한다. 결제 TID 를 찾은 뒤, CBT(일본결제, TID 가 `INIJPG` 로 시작하거나 통화 JPY)면 한국 INIAPI 조회 대상이 아니므로 로컬에 저장된 승인/입금 정보로 응답을 구성하고(`_is_local_confirmation=true`), 그 외에는 결제 시점 MID·모드(`is_test_mode`, MID `SIR` 접두사=Live 추정)에 맞는 자격증명으로 실제 `queryTransaction` API 를 호출한다. 응답은 `_pay_method_label`·카드/가상계좌/은행 상세·부분취소 이력·에스크로 상태 등 화면 표시용 `_` 접두 필드로 보강되며, 결제수단/은행/할부 코드는 한국어 라벨로 매핑된다. `sirsoft-ecommerce.orders.read` 권한이 필요하고, 결제 미존재 시 `data`=`null`, 조회 예외 시 502 로 응답한다.


### GET /api/plugins/sirsoft-pay_kginicis/user/orders/{orderNumber}/receipt
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.user.orders.receipt -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.user.orders.receipt`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\UserReceiptController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_kginicis/user/orders/APIDOC-KGINICIS-000001/receipt HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "receipt_type": "cbt_confirmation",
    "receipt_url": null,
    "receipt_label": "결제확인",
    "receipt_view_label": "결제확인서 보기",
    "receipt_title": "KG 이니시스 CBT 결제확인서",
    "receipt_notice": "일본 CBT 결제는 한국 KG 이니시스 매출전표 조회와 별도로 결제 승인 정보를 표시합니다.",
    "receipt_fields": [
        {
            "label": "주문번호",
            "value": "APIDOC-KGINICIS-000001"
        },
        {
            "label": "결제수단",
            "value": "일본 편의점결제"
        },
        {
            "label": "거래번호",
            "value": "INIJPGCARDapidocsmpl0000000001"
        },
        {
            "label": "결제금액",
            "value": "5,000 KRW"
        },
        {
            "label": "입금 상태",
            "value": "입금완료"
        },
        {
            "label": "편의점 코드",
            "value": "seven_eleven"
        },
        {
            "label": "편의점 확인번호",
            "value": "1234567890"
        },
        {
            "label": "편의점 접수번호",
            "value": "0987654321"
        },
        {
            "label": "입금 마감일시",
            "value": "2026-07-10 23:59:59"
        }
    ],
    "is_test_mode": true,
    "payment_method_label": "일본 편의점결제",
    "payment_method_display_label": "일본 편의점결제",
    "cbt_pay_method": "CVS",
    "payment_status": "paid",
    "selected_payment_method": null,
    "embedded_pg_provider": null,
    "embedded_pg_provider_label": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**
`UserReceiptController@show` 가 회원/비회원 공용 영수증 조회를 제공한다(선택적 인증). 로그인 사용자는 본인 소유 주문만, 비로그인 사용자는 `X-Guest-Order-Token` 헤더로 비회원 주문을 매칭하고, 토큰이 없거나 stale 하면 결제 직후 PG 콜백이 발급한 단기 영수증 쿠키(5분 유효)로 폴백한다. 소유권/토큰/쿠키 검증 실패는 모두 404 로 통일된다. CBT(일본결제) 결제면 KG 이니시스 매출전표가 없으므로 결제확인서용 필드 목록(`receipt_fields`)과 라벨을 직접 구성해 `receipt_type=cbt_confirmation` 으로 내려주고, 일반 결제는 KG 이니시스 영수증 URL(`receipt_type=inicis_receipt`)을 생성해 반환한다.


