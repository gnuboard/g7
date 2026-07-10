# Orders API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

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


### GET /api/modules/sirsoft-ecommerce/admin/orders
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search_field | query | string | 아니오 | `all`, `order_number`, `orderer_name`, `recipient_name`, `orderer_phone`, `recipient_phone`, `product_name`, `sku` | 검색 대상 필드명 (검색어를 적용할 컬럼) |
| search_keyword | query | string | 아니오 | max 200 | 검색 키워드 (부분 일치) |
| date_type | query | string | 아니오 | — | 기간 필터 기준 일자 종류 (ordered_at 주문일 / paid_at 결제일 / confirmed_at 구매확정일 / delivered_at 배송완료일 / cancelled_at 취소일) |
| start_date | query | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| end_date | query | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |
| order_status | query | array | 아니오 | — | 주문상태 다중 선택 필터 (OrderStatusEnum 값 배열, 해당 상태의 주문만 조회) |
| option_status | query | array | 아니오 | — | 주문옵션 상태 다중 선택 필터 (OrderStatusEnum 값 배열, 해당 옵션 상태를 가진 주문만 조회) |
| shipping_type | query | array | 아니오 | — | 배송유형 다중 선택 필터 (ShippingType 코드 배열) |
| payment_method | query | array | 아니오 | — | 결제수단 다중 선택 필터 (PaymentMethodEnum 값 배열) |
| category_id | query | integer | 아니오 | — | category 식별자 |
| min_amount | query | integer | 아니오 | min 0 | 주문금액 범위 필터 하한 (이 금액 이상 주문만 조회) |
| max_amount | query | integer | 아니오 | min 0 | 주문금액 범위 필터 상한 (이 금액 이하 주문만 조회) |
| country_codes | query | array | 아니오 | — | 배송국가 코드 다중 선택 필터 (ISO 3166-1 alpha-2 2자리 코드 배열) |
| order_device | query | array | 아니오 | — | 주문 디바이스 다중 선택 필터 (DeviceTypeEnum 값 배열 — pc/mobile/app 등) |
| min_shipping_amount | query | integer | 아니오 | min 0 | 배송비 범위 필터 하한 (이 배송비 이상 주문만 조회) |
| max_shipping_amount | query | integer | 아니오 | min 0 | 배송비 범위 필터 상한 (이 배송비 이하 주문만 조회) |
| shipping_policy_id | query | integer | 아니오 | — | shipping policy 식별자 |
| user_id | query | integer | 아니오 | — | user 식별자 |
| orderer_uuid | query | uuid | 아니오 | — | 특정 회원의 주문만 조회하는 주문자 UUID 필터 (회원 검색 연동용) |
| member_type | query | string | 아니오 | `member`, `guest` | 회원 구분 필터 (member 회원 주문 / guest 비회원 주문) |
| sort_by | query | string | 아니오 | `ordered_at`, `paid_at`, `total_amount` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| per_page | query | integer | 아니오 | min 10, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.order.list_validation_rules`, `sirsoft-ecommerce.order.list_validation_messages`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/orders?search_field=all&search_keyword=%EC%98%88%EC%8B%9C%EA%B0%92&date_type=%EC%98%88%EC%8B%9C%EA%B0%92&start_date=2026-01-01&end_date=2026-01-01&order_status=%EC%98%88%EC%8B%9C%EA%B0%92&option_status=%EC%98%88%EC%8B%9C%EA%B0%92&shipping_type=%EC%98%88%EC%8B%9C%EA%B0%92&payment_method=%EC%98%88%EC%8B%9C%EA%B0%92&category_id=1&min_amount=1&max_amount=1&country_codes=KR&order_device=%EC%98%88%EC%8B%9C%EA%B0%92&min_shipping_amount=1&max_shipping_amount=1&shipping_policy_id=1&user_id=1&orderer_uuid=9f8b2c1a-4d3e-4a2b-8c1d-0e1f2a3b4c5d&member_type=member&sort_by=ordered_at&sort_order=asc&per_page=1&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `128` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `455` | 기본 키 (내부 식별자) |
| order_number | string | `ORD-20260707-000002` | 주문번호 (사용자 노출용 고유 식별 코드) |
| order_status | string | `pending_payment` | 주문상태 (OrderStatusEnum 값 — 결제대기/결제완료/배송중 등) |
| order_status_label | string | `결제대기` | `order_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| order_status_variant | string | `warning` | `order_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| base_currency | string | `KRW` | 금액 표기 기준 통화 (모든 *_formatted 필드의 통화, 주문 시점 base_currency 고정) |
| payment_currency | string | `KRW` | 결제 통화 (유저가 선택·결제한 통화, base_currency 와 다르면 병기 표시) |
| is_cross_currency | boolean | `false` | cross currency 여부 |
| is_partially_cancelled | boolean | `false` | partially cancelled 여부 |
| total_amount | integer | `193397` | 최종 주문금액 (상품합계 − 할인 + 배송비) |
| total_amount_formatted | string | `193,397원` | `total_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_shipping_amount | integer | `0` | 총 배송비 |
| total_shipping_amount_formatted | string | `0원` | `total_shipping_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_paid_amount | integer | `0` | 총 실제 결제금액 (PG 결제된 금액) |
| total_paid_amount_formatted | string | `0원` | `total_paid_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_unpaid_amount | integer | `193397` | 미결제 잔액 (최종 주문금액 − 실제 결제금액) |
| total_unpaid_amount_formatted | string | `193,397원` | `total_unpaid_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_cancelled_amount | integer | `0` | 총 취소금액 |
| total_refunded_amount | integer | `0` | 총 환불금액 |
| total_points_used_amount | integer | `0` | 총 포인트(마일리지) 사용액 |
| total_points_used_amount_formatted | string | `0원` | `total_points_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_earned_points_amount | integer | `1934` | 총 적립 예정 포인트 |
| total_earned_points_amount_formatted | string | `1,934원` | `total_earned_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| ordered_at | string | `2026-07-07T05:47:30+00:00` | ordered 일시 |
| ordered_at_formatted | string | `2026-07-07 14:47:30` | `ordered_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| order_device | string | `pc` | 주문 디바이스 (DeviceTypeEnum 값 — pc/mobile/app) |
| order_device_label | string | `PC` | `order_device` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_first_order | boolean | `true` | first order 여부 |
| user | object | `{"uuid":"a23317ba-05bc-4de3-8272-2b73c091a266","name":"남상준"}` | 회원 주문의 주문자 요약 (uuid·name, 비회원 주문이면 미포함) |
| first_option | object | `{"product_name":"quisquam et quia","product_option_name":…` | 대표 표시용 첫 번째 주문 옵션 요약 (상품명·옵션명·수량·썸네일·추가옵션 요약) |
| options_count | integer | `1` | options 개수 (집계) |
| address | object | `{"orderer_name":"관리자","recipient_name":"구태호","recipient_c…` | 배송지 요약 (주문자명·수령인명·배송국가 코드/현지화명) |
| payment | object | `{"payment_method":"dbank","payment_method_label":"무통장입금"}` | 결제 요약 (결제수단 값·현지화 라벨) |
| shipping | object | `{"shipping_type":null,"shipping_type_label":null,"shippin…` | 배송 요약 (배송유형·배송방법 라벨·택배사명·송장번호, 첫 번째 배송 기준) |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문 정보를 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 2,
                "id": 5,
                "order_number": "ORD-20260708-000002",
                "order_status": "pending_payment",
                "order_status_label": "결제대기",
                "order_status_variant": "warning",
                "base_currency": "KRW",
                "payment_currency": "KRW",
                "is_cross_currency": false,
                "is_partially_cancelled": false,
                "total_amount": 163969,
                "total_amount_formatted": "163,969원",
                "total_shipping_amount": 2500,
                "total_shipping_amount_formatted": "2,500원",
                "total_paid_amount": 0,
                "total_paid_amount_formatted": "0원",
                "total_unpaid_amount": 163969,
                "total_unpaid_amount_formatted": "163,969원",
                "total_cancelled_amount": 0,
                "total_refunded_amount": 0,
                "total_points_used_amount": 0,
                "total_points_used_amount_formatted": "0원",
                "total_earned_points_amount": 1640,
                "total_earned_points_amount_formatted": "1,640원",
                "ordered_at": "2026-07-08T01:44:49+00:00",
                "ordered_at_formatted": "2026-07-08 10:44:49",
                "order_device": "pc",
                "order_device_label": "PC",
                "is_first_order": true,
                "user": {
                    "uuid": "a234c3ea-a4f9-496d-a5e7-b44f0d53fc2f",
                    "name": "선지영"
                },
                "first_option": {
                    "product_name": "assumenda doloremque ipsam",
                    "product_option_name": "ea",
                    "product_code": null,
                    "quantity": 2,
                    "thumbnail_url": null,
                    "additional_options_summary": null
                },
                "options_count": 1,
                "address": null,
                "payment": null,
                "shipping": {
                    "shipping_type": null,
                    "shipping_type_label": null,
                    "shipping_method_label": null,
                    "carrier_name": null,
                    "tracking_number": null
                },
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_update": true
                }
            },
            {
                "number": 1,
                "id": 4,
                "order_number": "APIDOC-20260708-000001",
                "order_status": "pending_payment",
                "order_status_label": "결제대기",
                "order_status_variant": "warning",
                "base_currency": "KRW",
                "payment_currency": "KRW",
                "is_cross_currency": false,
                "is_partially_cancelled": false,
                "total_amount": 327327,
                "total_amount_formatted": "327,327원",
                "total_shipping_amount": 3000,
                "total_shipping_amount_formatted": "3,000원",
                "total_paid_amount": 0,
                "total_paid_amount_formatted": "0원",
                "total_unpaid_amount": 327327,
                "total_unpaid_amount_formatted": "327,327원",
                "total_cancelled_amount": 0,
                "total_refunded_amount": 0,
                "total_points_used_amount": 0,
                "total_points_used_amount_formatted": "0원",
                "total_earned_points_amount": 3273,
                "total_earned_points_amount_formatted": "3,273원",
                "ordered_at": "2026-07-08T01:44:49+00:00",
                "ordered_at_formatted": "2026-07-08 10:44:49",
                "order_device": "pc",
                "order_device_label": "PC",
                "is_first_order": false,
                "user": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자"
                },
                "first_option": {
                    "product_name": "",
                    "product_option_name": "",
                    "product_code": null,
                    "quantity": null,
                    "thumbnail_url": null,
                    "additional_options_summary": null
                },
                "options_count": 0,
                "address": null,
                "payment": null,
                "shipping": {
                    "shipping_type": null,
                    "shipping_type_label": null,
                    "shipping_method_label": null,
                    "carrier_name": null,
                    "tracking_number": null
                },
                "is_owner": true,
                "abilities": {
                    "can_read": true,
                    "can_update": true
                }
            }
        ],
        "abilities": {
            "can_update": true
        },
        "statistics": {
            "total": 2,
            "status_counts": {
                "pending_payment": 2
            },
            "today_count": 2,
            "today_revenue": "0.00",
            "monthly_revenue": "0.00"
        },
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 2,
            "from": 1,
            "to": 2,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 주문을 다양한 필터·검색·정렬 조건으로 페이지네이션 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.read` 권한이 필요하며, `Admin\OrderController@index`가 `OrderService::getList()`로 목록을, `getStatistics()`로 상태별 통계를 함께 가져와 `OrderCollection`에 담아 반환합니다. 검색 필드(주문번호/주문자명/상품명/SKU 등)·기간·주문상태·결제수단·금액대·회원/비회원 구분 등 폭넓은 필터를 지원합니다. 관리자 주문 목록 화면의 기본 데이터 소스입니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/orders/bulk
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.bulk -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.bulk`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@bulkUpdate`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| order_status | body | string | 아니오 | — | 일괄 전환할 주문상태 (OrderStatusEnum 값, pending_order 제외 · 전이 규칙 검증) |
| carrier_id | body | integer | 아니오 | — | carrier 식별자 |
| tracking_number | body | string | 아니오 | max 50 | 송장(운송장)번호 (배송 관련 상태로 전환 시 carrier_id 와 함께 필수) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/orders/bulk HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "order_status": "예시값",
    "carrier_id": 1,
    "tracking_number": "예시값"
}
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 여러 주문(`ids`)의 주문상태나 배송 정보(택배사·송장번호)를 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@bulkUpdate`가 `OrderService::bulkUpdate()`로 처리합니다. 주문 목록에서 여러 건을 선택해 "배송 처리"·"상태 일괄 변경" 등을 수행할 때 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/orders/{order}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/orders/4 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)을 소프트 삭제합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.delete` 권한이 필요하며, `Admin\OrderController@destroy`가 `OrderService::delete()`를 호출합니다. 물리 삭제가 아닌 소프트 삭제(deleted_at 표시)이므로 데이터는 보존되며, 주문 목록/상세에서 제외됩니다.


### GET /api/modules/sirsoft-ecommerce/admin/orders/{order}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/orders/4 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `322` | 기본 키 (내부 식별자) |
| order_number | string | `20260617-0207256237` | 주문번호 |
| base_currency | string | `KRW` | 금액 표기 기준 통화 (모든 *_formatted 필드의 통화, 주문 시점 base_currency 고정) |
| payment_currency | string | `KRW` | 결제 통화 (유저가 선택·결제한 통화, base_currency 와 다르면 병기 표시) |
| is_cross_currency | boolean | `false` | cross currency 여부 |
| order_status | string | `payment_complete` | 주문상태 (OrderStatusEnum) |
| order_status_label | string | `결제완료` | `order_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| order_status_variant | string | `info` | `order_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| is_partially_cancelled | boolean | `false` | partially cancelled 여부 |
| order_device | string | `pc` | 주문 디바이스 (pc/mobile/app) |
| order_device_label | string | `PC` | `order_device` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_first_order | boolean | `true` | first order 여부 |
| subtotal_amount | integer | `140000` | 상품 합계 (할인 전, 상품가×수량 합계) |
| subtotal_amount_formatted | string | `140,000원` | `subtotal_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_discount_amount | integer | `25000` | 총 할인금액 (모든 할인 합계) |
| total_discount_amount_formatted | string | `25,000원` | `total_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_shipping_amount | integer | `0` | 총 배송비 |
| total_shipping_amount_formatted | string | `0원` | `total_shipping_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_amount | integer | `115000` | 최종 주문금액 (subtotal - discount + shipping) |
| total_amount_formatted | string | `115,000원` | `total_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_paid_amount | integer | `115000` | 총 실제 결제금액 (PG 결제액) |
| total_paid_amount_formatted | string | `115,000원` | `total_paid_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_due_amount | integer | `0` | 총 결제예정금액 (무통장 등) |
| total_due_amount_formatted | string | `0원` | `total_due_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| depositor_name | null | `null` | 무통장 입금자명 (입금확인 모달 기본값, payment 관계 로드 시에만 노출) |
| total_cancelled_amount | integer | `0` | 총 취소금액 |
| total_cancelled_amount_formatted | string | `0원` | `total_cancelled_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_amount | integer | `0` | 총 환불금액 |
| total_refunded_amount_formatted | string | `0원` | `total_refunded_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_points_amount | integer | `0` | 총 환불 포인트 |
| total_refunded_points_amount_formatted | string | `0원` | `total_refunded_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_product_coupon_discount_amount | integer | `0` | 상품 쿠폰 할인 합계 |
| total_product_coupon_discount_amount_formatted | string | `0원` | `total_product_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_order_coupon_discount_amount | integer | `25000` | 주문 쿠폰 할인 합계 |
| total_order_coupon_discount_amount_formatted | string | `25,000원` | `total_order_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_coupon_discount_amount | integer | `25000` | 총 쿠폰 할인금액 |
| total_coupon_discount_amount_formatted | string | `25,000원` | `total_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_code_discount_amount | integer | `0` | 총 할인코드 할인금액 |
| total_code_discount_amount_formatted | string | `0원` | `total_code_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_points_used_amount | integer | `0` | 총 포인트 사용액 |
| total_points_used_amount_formatted | string | `0원` | `total_points_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_deposit_used_amount | integer | `0` | 총 예치금 사용액 |
| total_deposit_used_amount_formatted | string | `0원` | `total_deposit_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_earned_points_amount | integer | `1150` | 총 적립 예정 포인트 |
| total_earned_points_amount_formatted | string | `1,150원` | `total_earned_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| mc_subtotal_amount | object | `{"KRW":{"amount":140000,"formatted":"140,000원"},"USD":{"a…` | 상품합계 다중 통화 |
| mc_total_discount_amount | object | `{"KRW":{"amount":25000,"formatted":"25,000원"},"USD":{"amo…` | 총 할인 다중 통화 |
| mc_total_shipping_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 총 배송비 다중 통화 |
| mc_total_amount | object | `{"KRW":{"amount":115000,"formatted":"115,000원"},"USD":{"a…` | 최종금액 다중 통화 (payment_amount) |
| mc_total_product_coupon_discount_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 상품 쿠폰 할인 다중 통화 |
| mc_total_order_coupon_discount_amount | object | `{"KRW":{"amount":25000,"formatted":"25,000원"},"USD":{"amo…` | 주문 쿠폰 할인 다중 통화 |
| mc_total_coupon_discount_amount | object | `{"KRW":{"amount":25000,"formatted":"25,000원"},"USD":{"amo…` | 쿠폰 할인 합계 다중 통화 |
| mc_total_code_discount_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 할인코드 할인 다중 통화 |
| mc_total_points_used_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 포인트 사용 다중 통화 |
| mc_total_deposit_used_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 예치금 사용 다중 통화 |
| item_count | integer | `2` | item 개수 (집계) |
| total_quantity | integer | `5` | 주문 옵션 수량 합계 (options 로드 시) |
| total_list_price | integer | `177000` | 정가 합계 (옵션 스냅샷 정가 × 수량 합계) |
| total_list_price_formatted | string | `177,000원` | `total_list_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| ordered_at | string | `2026-06-15T02:07:25+00:00` | ordered 일시 |
| ordered_at_formatted | string | `2026-06-15 11:07:25` | `ordered_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| paid_at | string | `2026-06-17T02:07:25+00:00` | paid 일시 |
| paid_at_formatted | string | `2026-06-17 11:07:25` | `paid_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| confirmed_at | null | `null` | confirmed 일시 |
| confirmed_at_formatted | null | `null` | `confirmed_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| cancelled_at | null | `null` | cancelled 일시 |
| cancelled_at_formatted | null | `null` | `cancelled_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| delivered_at | null | `null` | delivered 일시 |
| total_tax_amount | integer | `10455` | 총 과세금액 |
| total_tax_amount_formatted | string | `10,455원` | `total_tax_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_vat_amount | integer | `0` | 총 부가세금액 |
| total_vat_amount_formatted | string | `0원` | `total_vat_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_taxable_supply_amount | integer | `10455` | 과세 공급가액 (총 과세금액 − 부가세, 영수증 과세금액 표시 SSoT) |
| total_taxable_supply_amount_formatted | string | `10,455원` | `total_taxable_supply_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_tax_free_amount | integer | `0` | 총 면세금액 |
| total_tax_free_amount_formatted | string | `0원` | `total_tax_free_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| user | object | `{"uuid":"a20683c6-14f8-4061-baa9-157c45e9de5a","name":"Jo…` | 회원 주문의 주문자 정보 (uuid·name·email, user 관계 로드 시 · 비회원이면 미포함) |
| user_id | string | `a20683c6-14f8-4061-baa9-157c45e9de5a` | user 식별자 (연관 리소스 참조) |
| user_login_id | null | `null` | 회원 로그인 아이디 (login_id, 비회원 주문이면 null) |
| orderer_name | string | `설창용` | 주문자 이름 (배송지에서 플래튼) |
| orderer_phone | string | `010-0650-9192` | 주문자 휴대전화 (배송지에서 플래튼) |
| orderer_tel | null | `null` | 주문자 일반전화 (배송지에서 플래튼, 미입력 시 null) |
| orderer_email | string | `moonchang.shim@gmail.com` | 주문자 이메일 (배송지에서 플래튼, 비회원 알림 수신 통로) |
| recipient_name | string | `길준` | 수령인 이름 (배송지에서 플래튼) |
| recipient_phone | string | `010-1612-1979` | 수령인 휴대전화 (배송지에서 플래튼) |
| recipient_tel | null | `null` | 수령인 일반전화 (배송지에서 플래튼, 미입력 시 null) |
| recipient_zipcode | string | `19882` | 수령인 우편번호 (배송지에서 플래튼) |
| recipient_address | string | `부산광역시 도봉구 역삼로 201` | 수령인 기본 주소 (배송지에서 플래튼) |
| recipient_detail_address | null | `null` | 수령인 상세 주소 (배송지에서 플래튼, 미입력 시 null) |
| delivery_memo | null | `null` | 배송 메모 (배송지에서 플래튼, 미입력 시 null) |
| delivery_memo_label | null | `null` | `delivery_memo` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| options | array | `[{"id":612,"option_status":"payment_complete","option_sta…` | 주문 옵션(품목) 목록 (OrderOptionResource — 상품·옵션·수량·옵션상태·금액) |
| shipping_address | object | `{"id":320,"address_type":"shipping","orderer_name":"설창용",…` | 배송지 상세 (OrderAddressResource — 주문자/수령인/국내·해외 주소) |
| billing_address | null | `null` | 청구지 상세 (OrderAddressResource, 미분리 시 null) |
| payment | object | `{"id":284,"payment_status":"paid","payment_status_label":…` | 대표 결제 정보 (OrderPaymentResource — 결제수단·결제상태·금액) |
| payments | array | `[{"id":284,"payment_status":"paid","payment_status_label"…` | 결제 이력 목록 (OrderPaymentResource 배열 — 다회 결제/부분결제 포함) |
| shippings | array | `[]` | 배송 이력 목록 (OrderShippingResource 배열 — 배송유형·택배사·송장번호) |
| cancels | array | `[]` | 취소 이력 목록 (OrderCancelResource 배열 — 취소 사유·상세·취소일시, 최근순) |
| promotions_applied_snapshot | object | `{"coupon_issue_ids":[7330],"item_coupons":[],"discount_co…` | 적용된 프로모션 스냅샷 (재계산용) |
| shipping_policy_applied_snapshot | null | `null` | 적용된 배송정책 스냅샷 (재계산용) |
| admin_memo | null | `null` | 관리자 메모 (내부 관리용) |
| customer_memo | null | `null` | 고객 메모 (주문 시 고객이 남긴 메모) |
| created_at | string | `2026-06-17T02:07:25+00:00` | 생성 일시 |
| updated_at | string | `2026-06-17T02:07:25+00:00` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_update":true,"can_cancel":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문 정보를 조회했습니다.",
    "data": {
        "id": 4,
        "order_number": "APIDOC-20260708-000001",
        "base_currency": "KRW",
        "payment_currency": "KRW",
        "is_cross_currency": false,
        "order_status": "pending_payment",
        "order_status_label": "결제대기",
        "order_status_variant": "warning",
        "is_partially_cancelled": false,
        "order_device": "pc",
        "order_device_label": "PC",
        "is_first_order": false,
        "subtotal_amount": 324327,
        "subtotal_amount_formatted": "324,327원",
        "total_discount_amount": 0,
        "total_discount_amount_formatted": "0원",
        "total_shipping_amount": 3000,
        "total_shipping_amount_formatted": "3,000원",
        "total_amount": 327327,
        "total_amount_formatted": "327,327원",
        "total_paid_amount": 0,
        "total_paid_amount_formatted": "0원",
        "total_due_amount": 327327,
        "total_due_amount_formatted": "327,327원",
        "depositor_name": null,
        "total_cancelled_amount": 0,
        "total_cancelled_amount_formatted": "0원",
        "total_refunded_amount": 0,
        "total_refunded_amount_formatted": "0원",
        "total_refunded_points_amount": 0,
        "total_refunded_points_amount_formatted": "0원",
        "total_product_coupon_discount_amount": 0,
        "total_product_coupon_discount_amount_formatted": "0원",
        "total_order_coupon_discount_amount": 0,
        "total_order_coupon_discount_amount_formatted": "0원",
        "total_coupon_discount_amount": 0,
        "total_coupon_discount_amount_formatted": "0원",
        "total_code_discount_amount": 0,
        "total_code_discount_amount_formatted": "0원",
        "total_points_used_amount": 0,
        "total_points_used_amount_formatted": "0원",
        "total_deposit_used_amount": 0,
        "total_deposit_used_amount_formatted": "0원",
        "total_earned_points_amount": 3273,
        "total_earned_points_amount_formatted": "3,273원",
        "mc_subtotal_amount": [],
        "mc_total_discount_amount": [],
        "mc_total_shipping_amount": [],
        "mc_total_amount": [],
        "mc_total_product_coupon_discount_amount": [],
        "mc_total_order_coupon_discount_amount": [],
        "mc_total_coupon_discount_amount": [],
        "mc_total_code_discount_amount": [],
        "mc_total_points_used_amount": [],
        "mc_total_deposit_used_amount": [],
        "item_count": 4,
        "total_quantity": 0,
        "total_list_price": 0,
        "total_list_price_formatted": "0원",
        "ordered_at": "2026-07-08T01:44:49+00:00",
        "ordered_at_formatted": "2026-07-08 10:44:49",
        "paid_at": null,
        "paid_at_formatted": null,
        "confirmed_at": null,
        "confirmed_at_formatted": null,
        "cancelled_at": null,
        "cancelled_at_formatted": null,
        "delivered_at": null,
        "total_tax_amount": 29757,
        "total_tax_amount_formatted": "29,757원",
        "total_vat_amount": 0,
        "total_vat_amount_formatted": "0원",
        "total_taxable_supply_amount": 29757,
        "total_taxable_supply_amount_formatted": "29,757원",
        "total_tax_free_amount": 0,
        "total_tax_free_amount_formatted": "0원",
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com"
        },
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "user_login_id": null,
        "orderer_name": null,
        "orderer_phone": null,
        "orderer_tel": null,
        "orderer_email": null,
        "recipient_name": null,
        "recipient_phone": null,
        "recipient_tel": null,
        "recipient_zipcode": null,
        "recipient_address": null,
        "recipient_detail_address": null,
        "delivery_memo": null,
        "delivery_memo_label": null,
        "options": [],
        "shipping_address": null,
        "billing_address": null,
        "payment": null,
        "payments": [],
        "shippings": [],
        "cancels": [],
        "promotions_applied_snapshot": null,
        "shipping_policy_applied_snapshot": null,
        "admin_memo": null,
        "customer_memo": null,
        "created_at": "2026-07-08T01:44:49+00:00",
        "updated_at": "2026-07-08T01:44:49+00:00",
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_update": true,
            "can_cancel": true
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

**설명** 관리자가 특정 주문(`order`)의 전체 상세를 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.read` 권한이 필요하며, `Admin\OrderController@show`가 `OrderService::getDetail()`로 옵션·배송·결제·취소 이력·금액 내역(과세/면세/다중통화 포함)까지 풀로드해 `OrderResource`로 반환합니다. 관리자 주문 상세 화면의 데이터 소스이며, 주문이 없으면 404를 반환합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/orders/{order}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| order_status | body | string | 아니오 | — | 변경할 주문상태 (OrderStatusEnum 값, 현재 상태에서 전이 가능한 값만 허용) |
| admin_memo | body | string | 아니오 | max 2000 | 관리자 메모 (내부 관리용, 고객 비노출) |
| recipient_name | body | string | 예 | max 50 | 수령인 이름 |
| recipient_phone | body | string | 아니오 | max 20 | 수령인 연락처 |
| recipient_tel | body | string | 아니오 | max 20 | 수령인 일반전화 (recipient_phone 없을 때 필수) |
| recipient_zipcode | body | string | 아니오 | max 10 | 수령인 우편번호 (국내 주소, 해외 주소 없을 때 필수) |
| recipient_address | body | string | 아니오 | max 255 | 수령인 기본 주소 (국내 주소, 해외 주소 없을 때 필수) |
| recipient_detail_address | body | string | 아니오 | max 255 | 수령인 상세 주소 (recipient_address 입력 시 필수) |
| address_line_1 | body | string | 아니오 | max 255 | 주소 1행 (기본 주소) |
| address_line_2 | body | string | 아니오 | max 255 | 주소 2행 (상세 주소) |
| intl_city | body | string | 아니오 | max 100 | 도시 (국제 주소) |
| intl_state | body | string | 아니오 | max 100 | 주/도 (국제 주소) |
| intl_postal_code | body | string | 아니오 | max 20 | 우편번호 (국제 주소) |
| delivery_memo | body | string | 아니오 | max 500 | 배송 메모 (배송 시 요청사항) |
| recipient_country_code | body | string | 아니오 | — | 수령인 배송국가 코드 (ISO 3166-1 alpha-2 2자리) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/orders/4 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "order_status": "예시값",
    "admin_memo": "예시값",
    "recipient_name": "예시 이름",
    "recipient_phone": "010-1234-5678",
    "recipient_tel": "예시값",
    "recipient_zipcode": "06234",
    "recipient_address": "서울특별시 강남구 테헤란로 1",
    "recipient_detail_address": "서울특별시 강남구 테헤란로 1",
    "address_line_1": "서울특별시 강남구 테헤란로 1",
    "address_line_2": "서울특별시 강남구 테헤란로 1",
    "intl_city": "예시값",
    "intl_state": "예시값",
    "intl_postal_code": "06234",
    "delivery_memo": "예시값",
    "recipient_country_code": "KR"
}
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)의 주문상태·관리자 메모·수취인 배송지(국내/해외 주소 포함)를 수정합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@update`가 `OrderService::update()`로 처리한 뒤 수정된 주문을 `OrderResource`로 반환합니다. 관리자 주문 상세에서 배송지 정정·메모 기록·상태 변경 등에 사용합니다.


### POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancel
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.cancel -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.cancel`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@cancelOrder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| type | body | string | 예 | `full`, `partial` | 취소 유형 (full 전체취소 / partial 부분취소 — partial 이면 items 필수) |
| reason | body | string | 예 | — | 취소 사유 코드 (ClaimReason 의 refund·활성 코드) |
| reason_detail | body | string | 아니오 | max 500 | 취소 사유 상세 (관리자 입력 자유 텍스트) |
| items | body | array | 아니오 | min 1 | 처리 대상 항목 배열 |
| cancel_pg | body | boolean | 아니오 | — | PG 결제 취소 동반 여부 (미지정 시 기본 true — 실제 PG 취소 수행) |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 (pg_first PG 우선 / points_first 포인트 우선, 기본 pg_first) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/orders/4/cancel HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "type": "예시값",
    "reason": "예시값",
    "reason_detail": "예시값",
    "items": [
        "예시값"
    ],
    "cancel_pg": true,
    "refund_priority": "pg_first"
}
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)을 전체취소 또는 부분취소합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@cancelOrder`가 `items` 유무에 따라 `OrderCancellationService`의 `cancelOrder()`(전체) 또는 `cancelOrderOptions()`(부분)를 호출합니다. 취소자(`cancelledBy`)로 관리자 ID가 기록되고, `cancel_pg`로 PG 결제 취소 동반 여부를, `refund_priority`로 PG/포인트 환불 우선순위를 지정합니다. 취소 후 갱신된 주문을 `OrderResource`로 반환하며, 취소 불가 상태 등 실패 시 422를 반환합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/orders/{order}/confirm-deposit
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.confirm-deposit -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.confirm-deposit`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@confirmDeposit`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| amount | body | number | 예 | min 0 | 확인된 입금액 (결제예정금액과 정확히 일치해야 함, 불일치 시 422) |
| depositor_name | body | string | 아니오 | max 100 | depositor 이름 (식별자) |
| mark_order_complete | body | boolean | 아니오 | — | 입금확인과 동시에 주문완료 처리 여부 (미지정 시 기본 false — 결제완료 전이만) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/orders/4/confirm-deposit HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "amount": 1,
    "depositor_name": "예시 이름",
    "mark_order_complete": true
}
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 무통장(dbank) 미결제 주문(`order`)의 입금을 확인해 결제완료로 전이합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@confirmDeposit`가 `OrderProcessingService::confirmManualDeposit()`으로 입금자명·입금액을 기록하고 결제완료 처리합니다. 입금액(`amount`)이 결제예정금액과 정확히 일치하지 않으면 422(deposit_amount_mismatch)를 반환하며, `mark_order_complete`로 결제완료와 동시에 주문완료 처리 여부를 지정할 수 있습니다.


### POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/estimate-refund
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.estimate-refund -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.estimate-refund`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@estimateRefund`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| items | body | array | 예 | min 1 | 처리 대상 항목 배열 |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 (pg_first PG 우선 / points_first 포인트 우선, 기본 pg_first) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/orders/4/estimate-refund HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [
        "예시값"
    ],
    "refund_priority": "pg_first"
}
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)의 선택 옵션(`items`) 취소 시 예상 환불 금액을 실제 취소 없이 미리 계산합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@estimateRefund`가 `OrderCancellationService::previewRefund()`로 환불 예상값을 반환합니다. `refund_priority`에 따라 PG 우선/포인트 우선 환불 배분 결과가 달라집니다. 취소 화면에서 "환불 예정 금액"을 관리자에게 미리 보여주는 용도입니다.


### GET /api/modules/sirsoft-ecommerce/admin/orders/{order}/logs
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.logs -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.logs`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@logs`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort_order | query | string | 아니오 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/orders/4/logs?per_page=1&sort_order=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문 처리 이력을 조회했습니다.",
    "data": {
        "data": [],
        "links": {
            "first": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/4/logs?page=1",
            "last": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/4/logs?page=1",
            "prev": null,
            "next": null
        },
        "meta": {
            "current_page": 1,
            "from": null,
            "last_page": 1,
            "links": [
                {
                    "url": null,
                    "label": "pagination.previous",
                    "page": null,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/4/logs?page=1",
                    "label": "1",
                    "page": 1,
                    "active": true
                },
                {
                    "url": null,
                    "label": "pagination.next",
                    "page": null,
                    "active": false
                }
            ],
            "path": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/4/logs",
            "per_page": 25,
            "to": null,
            "total": 0
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)의 활동 로그(주문·주문옵션·배송지 변경 이력 합산)를 페이지네이션 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.read` 권한이 필요하며, `Admin\OrderController@logs`가 `OrderService::getActivityLogs()`로 조회해 `ActivityLogResource`로 반환합니다. `sort_order`로 시간 정렬 방향을 지정할 수 있습니다. 관리자 주문 상세의 "처리 이력" 탭에서 누가 언제 무엇을 변경했는지 추적하는 용도입니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/orders/{order}/options/bulk-status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.options.bulk-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.options.bulk-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@bulkChangeOptionStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| items | body | array | 예 | min 1 | 처리 대상 항목 배열 |
| status | body | string | 예 | — | 일괄 전환할 옵션 상태 (OrderStatusEnum 값, 옵션별 전이 규칙 검증) |
| carrier_id | body | integer | 아니오 | — | carrier 식별자 |
| tracking_number | body | string | 아니오 | max 50 | 송장(운송장)번호 (배송 관련 상태로 전환 시 carrier_id 와 함께 필수) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/orders/4/options/bulk-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [
        "예시값"
    ],
    "status": "예시값",
    "carrier_id": 1,
    "tracking_number": "예시값"
}
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)의 여러 주문 옵션 상태를 수량 분할까지 지원해 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@bulkChangeOptionStatus`가 `status`를 `OrderStatusEnum`으로 변환한 뒤 `OrderOptionService::bulkChangeStatusWithQuantity()`로 처리합니다. 배송중으로 전환 시 `carrier_id`·`tracking_number`(택배사·송장번호)를 함께 넘길 수 있습니다. 한 옵션의 일부 수량만 상태 전환(부분 배송 등)하는 시나리오를 지원합니다.


### POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/reset-guest-lookup-password
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.reset-guest-lookup-password -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.reset-guest-lookup-password`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@resetGuestLookupPassword`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| guest_lookup_password | body | string | 예 | min 8, max 255 | 재설정할 비회원 주문 조회 비밀번호 (8자 이상, 해시로 저장 · 회원가입 정책과 동일) |
| guest_lookup_password_confirmation | body | string | 예 | — | 조회 비밀번호 확인 (guest_lookup_password 와 일치해야 함) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/orders/4/reset-guest-lookup-password HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "guest_lookup_password": "Password123!",
    "guest_lookup_password_confirmation": "Password123!"
}
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 비회원 주문(`order`)의 조회 비밀번호를 재설정합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, 비회원 주문(`user_id IS NULL`)만 허용하고 회원 주문에는 422를 반환합니다. `Admin\OrderController@resetGuestLookupPassword`가 `OrderService::resetGuestLookupPassword()`로 새 비밀번호를 해시로 저장하며, 평문은 응답/로그에 노출하지 않습니다. 비회원이 조회 비밀번호를 분실했을 때 관리자가 대신 재설정해 주는 용도입니다.


### POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/send-email
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.send-email -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.send-email`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@sendEmail`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| email | body | email | 예 | max 255 | 이메일 주소 |
| message | body | string | 예 | max 5000 | 관리자가 작성한 안내 메일 본문 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/orders/4/send-email HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "email": "user@example.com",
    "message": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-500 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)에 대해 주문 관련 안내 이메일을 지정 주소(`email`)로 발송합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@sendEmail`이 `OrderService::sendEmail()`로 관리자가 작성한 메시지(`message`)를 전송합니다. 주문 관련 개별 안내가 필요할 때 관리자가 상세 화면에서 수동으로 메일을 보내는 용도입니다.


### POST /api/modules/sirsoft-ecommerce/orders/{orderNumber}/cancel-payment
<!-- @generated:start:api.modules.sirsoft-ecommerce.orders.cancel-payment -->
- **라우트명**: `api.modules.sirsoft-ecommerce.orders.cancel-payment`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\OrderController@cancelPayment`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |
| cancel_code | body | string | 아니오 | max 100 | PG사 취소 코드 (예: USER_CANCEL, order_payments 취소 이력에 기록) |
| cancel_message | body | string | 아니오 | max 500 | PG사 취소 메시지 (order_payments 취소 이력에 기록) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/orders/APIDOC-20260708-000001/cancel-payment HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "cancel_code": "예시값",
    "cancel_message": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 회원/비회원이 PG 결제창을 닫았을 때 결제 취소 이력만 기록합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `Public\OrderController@cancelPayment`가 `OrderProcessingService::recordPaymentCancellation()`으로 주문 상태는 변경하지 않고 `order_payments`에 취소창 닫힘 이력(`cancel_code`·`cancel_message`)만 남깁니다. 결제 SDK가 사용자 취소 콜백을 받았을 때 프론트가 호출해 결제 시도 이력을 추적하는 용도입니다.


### GET /api/modules/sirsoft-ecommerce/user/orders
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\OrderController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 50 | 페이지당 항목 수 |
| status | query | string | 아니오 | — | 상태 필터 (해당 상태의 항목만 조회) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/orders?page=1&per_page=1&status=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `452` | 기본 키 (내부 식별자) |
| order_number | string | `20260625-1144420949` | 주문번호 (사용자 노출용 고유 식별 코드) |
| status | string | `shipping` | 주문상태 값 (OrderStatusEnum value — 마이페이지용 status 별칭) |
| status_label | string | `배송중` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `primary` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| is_partially_cancelled | boolean | `false` | partially cancelled 여부 |
| recipient_country_code | string | `KR` | 배송국가 코드 (ISO 3166-1 alpha-2, shippingAddress 로드 시) |
| recipient_country_name | object | `{"ko":"한국","en":"South Korea"}` | 배송국가 현지화명 (로케일별 국가명 맵) |
| ordered_at | string | `2026-06-25T11:44:42+00:00` | ordered 일시 |
| ordered_at_formatted | string | `2026-06-25 20:44:42` | `ordered_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_amount | integer | `31000` | 최종 주문금액 (상품합계 − 할인 + 배송비) |
| total_amount_formatted | string | `31,000원` | `total_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| mc_total_amount | object | `{"KRW":{"amount":31000,"formatted":"31,000원"},"USD":{"amo…` | 최종 주문금액 다중 통화 (주문 시점 스냅샷, 통화별 amount·formatted) |
| total_shipping_amount | integer | `0` | 총 배송비 |
| total_shipping_amount_formatted | string | `0원` | `total_shipping_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| mc_total_shipping_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 총 배송비 다중 통화 (주문 시점 스냅샷, 통화별 amount·formatted) |
| total_points_used_amount | integer | `0` | 총 포인트(마일리지) 사용액 |
| total_points_used_amount_formatted | string | `0원` | `total_points_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_earned_points_amount | integer | `310` | 총 적립 예정 포인트 |
| total_earned_points_amount_formatted | string | `310원` | `total_earned_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| items | array | `[{"product_name":"프리미엄 샤인머스캣 2kg #94","product_option_nam…` | 주문 품목 목록 (상품명·옵션명·썸네일·수량·단가/소계·추가옵션 요약) |
| item_count | integer | `1` | item 개수 (집계) |
| abilities | object | `{"can_view":true,"can_cancel":false}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문 정보를 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 4,
                "order_number": "APIDOC-20260708-000001",
                "status": "pending_payment",
                "status_label": "결제대기",
                "status_variant": "warning",
                "is_partially_cancelled": false,
                "recipient_country_code": null,
                "recipient_country_name": null,
                "ordered_at": "2026-07-08T01:44:49+00:00",
                "ordered_at_formatted": "2026-07-08 10:44:49",
                "total_amount": 327327,
                "total_amount_formatted": "327,327원",
                "mc_total_amount": [],
                "total_shipping_amount": 3000,
                "total_shipping_amount_formatted": "3,000원",
                "mc_total_shipping_amount": [],
                "total_points_used_amount": 0,
                "total_points_used_amount_formatted": "0원",
                "total_earned_points_amount": 3273,
                "total_earned_points_amount_formatted": "3,273원",
                "items": [],
                "item_count": 0,
                "abilities": {
                    "can_view": true,
                    "can_cancel": true
                }
            }
        ],
        "statistics": {
            "pending_payment": 1,
            "payment_complete": 0,
            "preparing": 0,
            "shipping": 0,
            "delivered": 0,
            "confirmed": 0
        },
        "abilities": {
            "can_create": true
        },
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 회원이 마이페이지 주문내역에서 본인 주문 목록을 상태별 통계와 함께 페이지네이션 조회합니다. `auth:sanctum` 인증이 필요하며, `User\OrderController@index`가 `user_id`를 본인으로 고정한 뒤 `OrderService::getList()`와 `getUserStatistics()`를 호출해 `UserOrderCollection`으로 반환합니다. `status`로 특정 주문상태만 필터링할 수 있습니다. 관리자 목록과 달리 항상 본인 주문으로만 한정됩니다.


### POST /api/modules/sirsoft-ecommerce/user/orders
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\OrderController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-orders.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| payment_method | body | string | 예 | — | 결제수단 (PaymentMethodEnum 값 — card/vbank/dbank 등) |
| expected_total_amount | body | number | 예 | min 0 | 프론트가 계산한 예상 결제금액 (서버 재계산값과 대조해 금액 위변조 검증) |
| shipping_memo | body | string | 아니오 | max 500 | 배송 요청사항 메모 |
| depositor_name | body | string | 아니오 | max 50 | depositor 이름 (식별자) |
| save_shipping_address | body | boolean | 아니오 | — | 회원 주소록에 이번 배송지 저장 여부 (회원 주문 한정) |
| guest_lookup_password | body | string | 예 | min 8, max 255 | 비회원 주문 조회 비밀번호 (비회원만 필수, 8자 이상 · 해시로 저장) |
| guest_lookup_password_confirmation | body | string | 예 | — | 조회 비밀번호 확인 (guest_lookup_password 와 일치해야 함) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.order.create_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "payment_method": "예시값",
    "expected_total_amount": 1,
    "shipping_memo": "예시값",
    "depositor_name": "예시 이름",
    "save_shipping_address": true,
    "guest_lookup_password": "Password123!",
    "guest_lookup_password_confirmation": "Password123!"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-orders.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 주문서 작성을 마치고 실제 주문을 생성(결제하기)하는 회원/비회원 공용 엔드포인트입니다. PG 플러그인의 fetch 인터셉터가 이 한 경로만 매칭하므로 회원/비회원이 동일 URL로 진입하고, `Public\OrderController@store`가 `Auth::id()`로 분기합니다(회원은 `OrderResource`, 비회원은 민감 필드를 가린 `GuestOrderResource`). `optional.sanctum` + `sirsoft-ecommerce.user-orders.create` 권한이 필요하며, `expected_total_amount`로 금액 위변조를 검증하고 비회원은 `guest_lookup_password`로 이후 조회 비밀번호를 설정합니다. 회원이 `save_shipping_address`를 켜면 배송지가 자동 저장(PG 결제는 결제완료 시점) 됩니다.


### GET /api/modules/sirsoft-ecommerce/user/orders/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.show-by-id -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.show-by-id`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\OrderController@show`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/orders/4 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `4` | 기본 키 (내부 식별자) |
| order_number | string | `APIDOC-20260708-000001` | 주문번호 |
| base_currency | string | `KRW` | 금액 표기 기준 통화 (모든 *_formatted 필드의 통화, 주문 시점 base_currency 고정) |
| payment_currency | string | `KRW` | 결제 통화 (유저가 선택·결제한 통화, base_currency 와 다르면 병기 표시) |
| is_cross_currency | boolean | `false` | cross currency 여부 |
| order_status | string | `pending_payment` | 주문상태 (OrderStatusEnum) |
| order_status_label | string | `결제대기` | `order_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| order_status_variant | string | `warning` | `order_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| is_partially_cancelled | boolean | `false` | partially cancelled 여부 |
| order_device | string | `pc` | 주문 디바이스 (pc/mobile/app) |
| order_device_label | string | `PC` | `order_device` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_first_order | boolean | `false` | first order 여부 |
| subtotal_amount | integer | `324327` | 상품 합계 (할인 전, 상품가×수량 합계) |
| subtotal_amount_formatted | string | `324,327원` | `subtotal_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_discount_amount | integer | `0` | 총 할인금액 (모든 할인 합계) |
| total_discount_amount_formatted | string | `0원` | `total_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_shipping_amount | integer | `3000` | 총 배송비 |
| total_shipping_amount_formatted | string | `3,000원` | `total_shipping_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_amount | integer | `327327` | 최종 주문금액 (subtotal - discount + shipping) |
| total_amount_formatted | string | `327,327원` | `total_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_paid_amount | integer | `0` | 총 실제 결제금액 (PG 결제액) |
| total_paid_amount_formatted | string | `0원` | `total_paid_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_due_amount | integer | `327327` | 총 결제예정금액 (무통장 등) |
| total_due_amount_formatted | string | `327,327원` | `total_due_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| depositor_name | null | `null` | 무통장 입금자명 (입금확인 모달 기본값, payment 관계 로드 시에만 노출) |
| total_cancelled_amount | integer | `0` | 총 취소금액 |
| total_cancelled_amount_formatted | string | `0원` | `total_cancelled_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_amount | integer | `0` | 총 환불금액 |
| total_refunded_amount_formatted | string | `0원` | `total_refunded_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_points_amount | integer | `0` | 총 환불 포인트 |
| total_refunded_points_amount_formatted | string | `0원` | `total_refunded_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_product_coupon_discount_amount | integer | `0` | 상품 쿠폰 할인 합계 |
| total_product_coupon_discount_amount_formatted | string | `0원` | `total_product_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_order_coupon_discount_amount | integer | `0` | 주문 쿠폰 할인 합계 |
| total_order_coupon_discount_amount_formatted | string | `0원` | `total_order_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_coupon_discount_amount | integer | `0` | 총 쿠폰 할인금액 |
| total_coupon_discount_amount_formatted | string | `0원` | `total_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_code_discount_amount | integer | `0` | 총 할인코드 할인금액 |
| total_code_discount_amount_formatted | string | `0원` | `total_code_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_points_used_amount | integer | `0` | 총 포인트 사용액 |
| total_points_used_amount_formatted | string | `0원` | `total_points_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_deposit_used_amount | integer | `0` | 총 예치금 사용액 |
| total_deposit_used_amount_formatted | string | `0원` | `total_deposit_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_earned_points_amount | integer | `3273` | 총 적립 예정 포인트 |
| total_earned_points_amount_formatted | string | `3,273원` | `total_earned_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| mc_subtotal_amount | array | `[]` | 상품합계 다중 통화 |
| mc_total_discount_amount | array | `[]` | 총 할인 다중 통화 |
| mc_total_shipping_amount | array | `[]` | 총 배송비 다중 통화 |
| mc_total_amount | array | `[]` | 최종금액 다중 통화 (payment_amount) |
| mc_total_product_coupon_discount_amount | array | `[]` | 상품 쿠폰 할인 다중 통화 |
| mc_total_order_coupon_discount_amount | array | `[]` | 주문 쿠폰 할인 다중 통화 |
| mc_total_coupon_discount_amount | array | `[]` | 쿠폰 할인 합계 다중 통화 |
| mc_total_code_discount_amount | array | `[]` | 할인코드 할인 다중 통화 |
| mc_total_points_used_amount | array | `[]` | 포인트 사용 다중 통화 |
| mc_total_deposit_used_amount | array | `[]` | 예치금 사용 다중 통화 |
| item_count | integer | `4` | item 개수 (집계) |
| total_quantity | integer | `0` | 주문 옵션 수량 합계 (options 로드 시) |
| total_list_price | integer | `0` | 정가 합계 (옵션 스냅샷 정가 × 수량 합계) |
| total_list_price_formatted | string | `0원` | `total_list_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| ordered_at | string | `2026-07-08T01:44:49+00:00` | ordered 일시 |
| ordered_at_formatted | string | `2026-07-08 10:44:49` | `ordered_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| paid_at | null | `null` | paid 일시 |
| paid_at_formatted | null | `null` | `paid_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| confirmed_at | null | `null` | confirmed 일시 |
| confirmed_at_formatted | null | `null` | `confirmed_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| cancelled_at | null | `null` | cancelled 일시 |
| cancelled_at_formatted | null | `null` | `cancelled_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| delivered_at | null | `null` | delivered 일시 |
| total_tax_amount | integer | `29757` | 총 과세금액 |
| total_tax_amount_formatted | string | `29,757원` | `total_tax_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_vat_amount | integer | `0` | 총 부가세금액 |
| total_vat_amount_formatted | string | `0원` | `total_vat_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_taxable_supply_amount | integer | `29757` | 과세 공급가액 (총 과세금액 − 부가세, 영수증 과세금액 표시 SSoT) |
| total_taxable_supply_amount_formatted | string | `29,757원` | `total_taxable_supply_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_tax_free_amount | integer | `0` | 총 면세금액 |
| total_tax_free_amount_formatted | string | `0원` | `total_tax_free_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| user | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 대상 사용자 정보 객체 (uuid/name/email 등 — user 관계 파생) |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | user 식별자 (연관 리소스 참조) |
| user_login_id | null | `null` | 회원 로그인 아이디 (login_id, 비회원 주문이면 null) |
| orderer_name | null | `null` | 주문자 이름 (배송지에서 플래튼) |
| orderer_phone | null | `null` | 주문자 휴대전화 (배송지에서 플래튼) |
| orderer_tel | null | `null` | 주문자 일반전화 (배송지에서 플래튼, 미입력 시 null) |
| orderer_email | null | `null` | 주문자 이메일 (배송지에서 플래튼, 비회원 알림 수신 통로) |
| recipient_name | null | `null` | 수령인 이름 (배송지에서 플래튼) |
| recipient_phone | null | `null` | 수령인 휴대전화 (배송지에서 플래튼) |
| recipient_tel | null | `null` | 수령인 일반전화 (배송지에서 플래튼, 미입력 시 null) |
| recipient_zipcode | null | `null` | 수령인 우편번호 (배송지에서 플래튼) |
| recipient_address | null | `null` | 수령인 기본 주소 (배송지에서 플래튼) |
| recipient_detail_address | null | `null` | 수령인 상세 주소 (배송지에서 플래튼, 미입력 시 null) |
| delivery_memo | null | `null` | 배송 메모 (배송지에서 플래튼, 미입력 시 null) |
| delivery_memo_label | null | `null` | `delivery_memo` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| options | array | `[]` | 주문 옵션(품목) 목록 (OrderOptionResource — 상품·옵션·수량·옵션상태·금액) |
| shipping_address | null | `null` | 배송지 상세 (OrderAddressResource — 주문자/수령인/국내·해외 주소) |
| billing_address | null | `null` | 청구지 상세 (OrderAddressResource, 미분리 시 null) |
| payment | null | `null` | 대표 결제 정보 (OrderPaymentResource — 결제수단·결제상태·금액) |
| payments | array | `[]` | 결제 이력 목록 (OrderPaymentResource 배열 — 다회 결제/부분결제 포함) |
| shippings | array | `[]` | 배송 이력 목록 (OrderShippingResource 배열 — 배송유형·택배사·송장번호) |
| cancels | array | `[]` | 취소 이력 목록 (OrderCancelResource 배열 — 취소 사유·상세·취소일시, 최근순) |
| promotions_applied_snapshot | null | `null` | 적용된 프로모션 스냅샷 (재계산용) |
| shipping_policy_applied_snapshot | null | `null` | 적용된 배송정책 스냅샷 (재계산용) |
| admin_memo | null | `null` | 관리자 메모 (내부 관리용) |
| customer_memo | null | `null` | 고객 메모 (주문 시 고객이 남긴 메모) |
| created_at | string | `2026-07-08T01:44:49+00:00` | 생성 일시 |
| updated_at | string | `2026-07-08T01:44:49+00:00` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_update":true,"can_cancel":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문 정보를 조회했습니다.",
    "data": {
        "id": 4,
        "order_number": "APIDOC-20260708-000001",
        "base_currency": "KRW",
        "payment_currency": "KRW",
        "is_cross_currency": false,
        "order_status": "pending_payment",
        "order_status_label": "결제대기",
        "order_status_variant": "warning",
        "is_partially_cancelled": false,
        "order_device": "pc",
        "order_device_label": "PC",
        "is_first_order": false,
        "subtotal_amount": 324327,
        "subtotal_amount_formatted": "324,327원",
        "total_discount_amount": 0,
        "total_discount_amount_formatted": "0원",
        "total_shipping_amount": 3000,
        "total_shipping_amount_formatted": "3,000원",
        "total_amount": 327327,
        "total_amount_formatted": "327,327원",
        "total_paid_amount": 0,
        "total_paid_amount_formatted": "0원",
        "total_due_amount": 327327,
        "total_due_amount_formatted": "327,327원",
        "depositor_name": null,
        "total_cancelled_amount": 0,
        "total_cancelled_amount_formatted": "0원",
        "total_refunded_amount": 0,
        "total_refunded_amount_formatted": "0원",
        "total_refunded_points_amount": 0,
        "total_refunded_points_amount_formatted": "0원",
        "total_product_coupon_discount_amount": 0,
        "total_product_coupon_discount_amount_formatted": "0원",
        "total_order_coupon_discount_amount": 0,
        "total_order_coupon_discount_amount_formatted": "0원",
        "total_coupon_discount_amount": 0,
        "total_coupon_discount_amount_formatted": "0원",
        "total_code_discount_amount": 0,
        "total_code_discount_amount_formatted": "0원",
        "total_points_used_amount": 0,
        "total_points_used_amount_formatted": "0원",
        "total_deposit_used_amount": 0,
        "total_deposit_used_amount_formatted": "0원",
        "total_earned_points_amount": 3273,
        "total_earned_points_amount_formatted": "3,273원",
        "mc_subtotal_amount": [],
        "mc_total_discount_amount": [],
        "mc_total_shipping_amount": [],
        "mc_total_amount": [],
        "mc_total_product_coupon_discount_amount": [],
        "mc_total_order_coupon_discount_amount": [],
        "mc_total_coupon_discount_amount": [],
        "mc_total_code_discount_amount": [],
        "mc_total_points_used_amount": [],
        "mc_total_deposit_used_amount": [],
        "item_count": 4,
        "total_quantity": 0,
        "total_list_price": 0,
        "total_list_price_formatted": "0원",
        "ordered_at": "2026-07-08T01:44:49+00:00",
        "ordered_at_formatted": "2026-07-08 10:44:49",
        "paid_at": null,
        "paid_at_formatted": null,
        "confirmed_at": null,
        "confirmed_at_formatted": null,
        "cancelled_at": null,
        "cancelled_at_formatted": null,
        "delivered_at": null,
        "total_tax_amount": 29757,
        "total_tax_amount_formatted": "29,757원",
        "total_vat_amount": 0,
        "total_vat_amount_formatted": "0원",
        "total_taxable_supply_amount": 29757,
        "total_taxable_supply_amount_formatted": "29,757원",
        "total_tax_free_amount": 0,
        "total_tax_free_amount_formatted": "0원",
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com"
        },
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "user_login_id": null,
        "orderer_name": null,
        "orderer_phone": null,
        "orderer_tel": null,
        "orderer_email": null,
        "recipient_name": null,
        "recipient_phone": null,
        "recipient_tel": null,
        "recipient_zipcode": null,
        "recipient_address": null,
        "recipient_detail_address": null,
        "delivery_memo": null,
        "delivery_memo_label": null,
        "options": [],
        "shipping_address": null,
        "billing_address": null,
        "payment": null,
        "payments": [],
        "shippings": [],
        "cancels": [],
        "promotions_applied_snapshot": null,
        "shipping_policy_applied_snapshot": null,
        "admin_memo": null,
        "customer_memo": null,
        "created_at": "2026-07-08T01:44:49+00:00",
        "updated_at": "2026-07-08T01:44:49+00:00",
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_update": true,
            "can_cancel": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 회원이 마이페이지 주문 상세에서 주문 ID(`id`)로 본인 주문의 전체 상세를 조회합니다. `auth:sanctum` 인증이 필요하며, `User\OrderController@show`가 `OrderService::getDetail()`로 로드한 뒤 소유자 검증(`user_id === Auth::id()`)을 거쳐 `OrderResource`로 반환합니다. 본인 주문이 아니거나 존재하지 않으면 정보 노출 방지를 위해 404를 반환합니다. 주문번호로 조회하는 `showByOrderNumber`와 달리 내부 주문 ID를 사용합니다.


### POST /api/modules/sirsoft-ecommerce/user/orders/{id}/cancel
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.cancel -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.cancel`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\OrderController@cancel`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-orders.cancel`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| reason | body | string | 예 | — | 취소 사유 코드 (ClaimReason 의 refund·활성·사용자 선택 가능 코드) |
| reason_detail | body | string | 아니오 | max 500 | 취소 사유 상세 (회원 입력 자유 텍스트) |
| items | body | array | 아니오 | min 1 | 처리 대상 항목 배열 |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 (pg_first PG 우선 / points_first 포인트 우선, 기본 pg_first) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders/4/cancel HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason": "예시값",
    "reason_detail": "예시값",
    "items": [
        "예시값"
    ],
    "refund_priority": "pg_first"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-orders.cancel`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 회원이 마이페이지에서 본인 주문(`id`)을 취소합니다. `auth:sanctum` + `sirsoft-ecommerce.user-orders.cancel` 권한이 필요하며, `User\OrderController@cancel`이 `items` 유무에 따라 `OrderCancellationService`의 `cancelOrderOptions()`(부분) 또는 `cancelOrder()`(전체)를 호출합니다. 취소자(`cancelledBy`)로 회원 본인 ID가 기록되고, `refund_priority`로 PG/포인트 환불 우선순위를 지정합니다. 취소 가능 상태의 주문만 취소되며, 취소 후 갱신된 주문을 `OrderResource`로 반환합니다.


### POST /api/modules/sirsoft-ecommerce/user/orders/{id}/estimate-refund
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.estimate-refund -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.estimate-refund`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\OrderController@estimateRefund`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-orders.cancel`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| items | body | array | 예 | min 1 | 처리 대상 항목 배열 |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 (pg_first PG 우선 / points_first 포인트 우선, 기본 pg_first) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders/4/estimate-refund HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [
        "예시값"
    ],
    "refund_priority": "pg_first"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-orders.cancel`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 회원이 마이페이지에서 본인 주문(`id`)의 선택 옵션(`items`) 취소 시 예상 환불 금액을 실제 취소 없이 미리 계산합니다. `auth:sanctum` + `sirsoft-ecommerce.user-orders.cancel` 권한이 필요하며, `User\OrderController@estimateRefund`가 `OrderCancellationService::previewRefund()`로 환불 예상값을 반환합니다. `refund_priority`에 따라 PG 우선/포인트 우선 환불 배분 결과가 달라집니다. 취소 확정 전 "환불 예정 금액"을 회원에게 안내하는 용도입니다.


### POST /api/modules/sirsoft-ecommerce/user/orders/{id}/options/{optionId}/confirm
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.confirm-option -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.confirm-option`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\OrderController@confirmOption`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-orders.confirm`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| optionId | path | string | 예 | — | 대상 option의 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders/4/options/{optionId}/confirm HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-orders.confirm`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 회원이 마이페이지에서 본인 주문(`id`)의 개별 옵션(`optionId`)을 구매확정합니다. `auth:sanctum` + `sirsoft-ecommerce.user-orders.confirm` 권한이 필요하며, `User\OrderController@confirmOption`이 `OrderService::confirmOption()`을 호출합니다. 구매확정 시 적립 포인트 확정 등 후속 처리가 이어지며, 확정 불가 상태(배송 미완료 등)면 422를 반환합니다. 배송 완료된 상품을 회원이 직접 "구매확정" 할 때 사용합니다.


### POST /api/modules/sirsoft-ecommerce/user/orders/{id}/reorder
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.reorder -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.reorder`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\OrderController@reorder`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders/4/reorder HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| added_count | integer | `0` | added 개수 (집계) |
| skipped | array | `[]` | 장바구니에 담지 못한 항목 목록 (품절·단종 등으로 재주문에서 건너뛴 옵션) |
| cart_count | integer | `0` | cart 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "과거 주문의 상품을 장바구니에 추가했습니다.",
    "data": {
        "added_count": 0,
        "skipped": [],
        "cart_count": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 회원이 과거 주문(`id`)의 옵션들을 현재 장바구니에 다시 담는 재주문 기능입니다. `auth:sanctum` 인증이 필요하며, `User\OrderController@reorder`가 `CartService::reorderFromOrder()`로 처리해 담긴 수량(`added_count`), 담지 못한 항목(`skipped[]`), 현재 장바구니 총 개수(`cart_count`)를 반환합니다. 취소된 주문도 재주문 대상이 되며, 품절·단종 등으로 추가 불가한 항목은 건너뛰어 `skipped` 배열로 안내합니다. 마이페이지 주문내역의 "재주문" 버튼에 사용합니다.


### PUT /api/modules/sirsoft-ecommerce/user/orders/{id}/shipping-address
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.update-shipping-address -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.update-shipping-address`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\OrderController@updateShippingAddress`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| address_id | body | integer | 아니오 | — | address 식별자 |
| recipient_name | body | string | 아니오 | max 50 | 수령인 이름 |
| recipient_phone | body | string | 아니오 | max 20 | 수령인 연락처 |
| country_code | body | string | 아니오 | — | 국가 코드 (ISO 3166-1 alpha-2) |
| zipcode | body | string | 아니오 | max 10 | 우편번호 |
| address | body | string | 아니오 | max 255 | 기본 주소 |
| address_detail | body | string | 아니오 | max 255 | 상세 주소 |
| address_line_1 | body | string | 아니오 | max 255 | 주소 1행 (기본 주소) |
| address_line_2 | body | string | 아니오 | max 255 | 주소 2행 (상세 주소) |
| intl_city | body | string | 아니오 | max 100 | 도시 (국제 주소) |
| intl_state | body | string | 아니오 | max 100 | 주/도 (국제 주소) |
| intl_postal_code | body | string | 아니오 | max 20 | 우편번호 (국제 주소) |
| delivery_memo | body | string | 아니오 | max 255 | 배송 메모 (배송 시 요청사항) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.order.shipping_address_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/user/orders/4/shipping-address HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "address_id": 1,
    "recipient_name": "예시 이름",
    "recipient_phone": "010-1234-5678",
    "country_code": "KR",
    "zipcode": "06234",
    "address": "서울특별시 강남구 테헤란로 1",
    "address_detail": "서울특별시 강남구 테헤란로 1",
    "address_line_1": "서울특별시 강남구 테헤란로 1",
    "address_line_2": "서울특별시 강남구 테헤란로 1",
    "intl_city": "예시값",
    "intl_state": "예시값",
    "intl_postal_code": "06234",
    "delivery_memo": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| order | object | `{"id":4,"order_number":"APIDOC-20260708-000001","base_cur…` | 배송지 변경이 반영된 주문 상세 (OrderResource — 변경 후 최신 주문 전체) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배송지가 변경되었습니다.",
    "data": {
        "order": {
            "id": 4,
            "order_number": "APIDOC-20260708-000001",
            "base_currency": "KRW",
            "payment_currency": "KRW",
            "is_cross_currency": false,
            "order_status": "pending_payment",
            "order_status_label": "결제대기",
            "order_status_variant": "warning",
            "is_partially_cancelled": false,
            "order_device": "pc",
            "order_device_label": "PC",
            "is_first_order": false,
            "subtotal_amount": 324327,
            "subtotal_amount_formatted": "324,327원",
            "total_discount_amount": 0,
            "total_discount_amount_formatted": "0원",
            "total_shipping_amount": 3000,
            "total_shipping_amount_formatted": "3,000원",
            "total_amount": 327327,
            "total_amount_formatted": "327,327원",
            "total_paid_amount": 0,
            "total_paid_amount_formatted": "0원",
            "total_due_amount": 327327,
            "total_due_amount_formatted": "327,327원",
            "total_cancelled_amount": 0,
            "total_cancelled_amount_formatted": "0원",
            "total_refunded_amount": 0,
            "total_refunded_amount_formatted": "0원",
            "total_refunded_points_amount": 0,
            "total_refunded_points_amount_formatted": "0원",
            "total_product_coupon_discount_amount": 0,
            "total_product_coupon_discount_amount_formatted": "0원",
            "total_order_coupon_discount_amount": 0,
            "total_order_coupon_discount_amount_formatted": "0원",
            "total_coupon_discount_amount": 0,
            "total_coupon_discount_amount_formatted": "0원",
            "total_code_discount_amount": 0,
            "total_code_discount_amount_formatted": "0원",
            "total_points_used_amount": 0,
            "total_points_used_amount_formatted": "0원",
            "total_deposit_used_amount": 0,
            "total_deposit_used_amount_formatted": "0원",
            "total_earned_points_amount": 3273,
            "total_earned_points_amount_formatted": "3,273원",
            "mc_subtotal_amount": [],
            "mc_total_discount_amount": [],
            "mc_total_shipping_amount": [],
            "mc_total_amount": [],
            "mc_total_product_coupon_discount_amount": [],
            "mc_total_order_coupon_discount_amount": [],
            "mc_total_coupon_discount_amount": [],
            "mc_total_code_discount_amount": [],
            "mc_total_points_used_amount": [],
            "mc_total_deposit_used_amount": [],
            "item_count": 4,
            "total_quantity": 0,
            "total_list_price": 0,
            "total_list_price_formatted": "0원",
            "ordered_at": "2026-07-08T01:44:49+00:00",
            "ordered_at_formatted": "2026-07-08 10:44:49",
            "paid_at": null,
            "paid_at_formatted": null,
            "confirmed_at": null,
            "confirmed_at_formatted": null,
            "cancelled_at": null,
            "cancelled_at_formatted": null,
            "delivered_at": null,
            "total_tax_amount": 29757,
            "total_tax_amount_formatted": "29,757원",
            "total_vat_amount": 0,
            "total_vat_amount_formatted": "0원",
            "total_taxable_supply_amount": 29757,
            "total_taxable_supply_amount_formatted": "29,757원",
            "total_tax_free_amount": 0,
            "total_tax_free_amount_formatted": "0원",
            "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "user_login_id": null,
            "orderer_name": null,
            "orderer_phone": null,
            "orderer_tel": null,
            "orderer_email": null,
            "recipient_name": null,
            "recipient_phone": null,
            "recipient_tel": null,
            "recipient_zipcode": null,
            "recipient_address": null,
            "recipient_detail_address": null,
            "delivery_memo": null,
            "delivery_memo_label": null,
            "options": [],
            "shipping_address": null,
            "promotions_applied_snapshot": null,
            "shipping_policy_applied_snapshot": null,
            "admin_memo": null,
            "customer_memo": null,
            "created_at": "2026-07-08T01:44:49+00:00",
            "updated_at": "2026-07-08T01:44:49+00:00",
            "is_owner": true,
            "abilities": {
                "can_read": true,
                "can_update": true,
                "can_cancel": true
            }
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 회원이 배송 전 상태의 본인 주문(`id`) 배송지를 변경합니다. `auth:sanctum` 인증이 필요하며, `User\OrderController@updateShippingAddress`가 소유자 검증 후 `OrderService::updateShippingAddress()`로 처리합니다. 저장된 회원 주소(`address_id`)를 선택하거나 수취인·연락처·주소 필드를 직접 입력할 수 있고, 국내(`zipcode`/`address`)와 해외(`address_line_1`·`intl_city` 등) 주소를 모두 지원합니다. 이미 배송이 시작된 주문 등 변경 불가 상태면 422를 반환합니다.


### GET /api/modules/sirsoft-ecommerce/user/orders/{orderNumber}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\OrderController@showByOrderNumber`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/orders/APIDOC-20260708-000001 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `4` | 기본 키 (내부 식별자) |
| order_number | string | `APIDOC-20260708-000001` | 주문번호 |
| base_currency | string | `KRW` | 금액 표기 기준 통화 (모든 *_formatted 필드의 통화, 주문 시점 base_currency 고정) |
| payment_currency | string | `KRW` | 결제 통화 (유저가 선택·결제한 통화, base_currency 와 다르면 병기 표시) |
| is_cross_currency | boolean | `false` | cross currency 여부 |
| order_status | string | `pending_payment` | 주문상태 (OrderStatusEnum) |
| order_status_label | string | `결제대기` | `order_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| order_status_variant | string | `warning` | `order_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| is_partially_cancelled | boolean | `false` | partially cancelled 여부 |
| order_device | string | `pc` | 주문 디바이스 (pc/mobile/app) |
| order_device_label | string | `PC` | `order_device` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_first_order | boolean | `false` | first order 여부 |
| subtotal_amount | integer | `324327` | 상품 합계 (할인 전, 상품가×수량 합계) |
| subtotal_amount_formatted | string | `324,327원` | `subtotal_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_discount_amount | integer | `0` | 총 할인금액 (모든 할인 합계) |
| total_discount_amount_formatted | string | `0원` | `total_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_shipping_amount | integer | `3000` | 총 배송비 |
| total_shipping_amount_formatted | string | `3,000원` | `total_shipping_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_amount | integer | `327327` | 최종 주문금액 (subtotal - discount + shipping) |
| total_amount_formatted | string | `327,327원` | `total_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_paid_amount | integer | `0` | 총 실제 결제금액 (PG 결제액) |
| total_paid_amount_formatted | string | `0원` | `total_paid_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_due_amount | integer | `327327` | 총 결제예정금액 (무통장 등) |
| total_due_amount_formatted | string | `327,327원` | `total_due_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| depositor_name | null | `null` | 무통장 입금자명 (입금확인 모달 기본값, payment 관계 로드 시에만 노출) |
| total_cancelled_amount | integer | `0` | 총 취소금액 |
| total_cancelled_amount_formatted | string | `0원` | `total_cancelled_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_amount | integer | `0` | 총 환불금액 |
| total_refunded_amount_formatted | string | `0원` | `total_refunded_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_points_amount | integer | `0` | 총 환불 포인트 |
| total_refunded_points_amount_formatted | string | `0원` | `total_refunded_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_product_coupon_discount_amount | integer | `0` | 상품 쿠폰 할인 합계 |
| total_product_coupon_discount_amount_formatted | string | `0원` | `total_product_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_order_coupon_discount_amount | integer | `0` | 주문 쿠폰 할인 합계 |
| total_order_coupon_discount_amount_formatted | string | `0원` | `total_order_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_coupon_discount_amount | integer | `0` | 총 쿠폰 할인금액 |
| total_coupon_discount_amount_formatted | string | `0원` | `total_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_code_discount_amount | integer | `0` | 총 할인코드 할인금액 |
| total_code_discount_amount_formatted | string | `0원` | `total_code_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_points_used_amount | integer | `0` | 총 포인트 사용액 |
| total_points_used_amount_formatted | string | `0원` | `total_points_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_deposit_used_amount | integer | `0` | 총 예치금 사용액 |
| total_deposit_used_amount_formatted | string | `0원` | `total_deposit_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_earned_points_amount | integer | `3273` | 총 적립 예정 포인트 |
| total_earned_points_amount_formatted | string | `3,273원` | `total_earned_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| mc_subtotal_amount | array | `[]` | 상품합계 다중 통화 |
| mc_total_discount_amount | array | `[]` | 총 할인 다중 통화 |
| mc_total_shipping_amount | array | `[]` | 총 배송비 다중 통화 |
| mc_total_amount | array | `[]` | 최종금액 다중 통화 (payment_amount) |
| mc_total_product_coupon_discount_amount | array | `[]` | 상품 쿠폰 할인 다중 통화 |
| mc_total_order_coupon_discount_amount | array | `[]` | 주문 쿠폰 할인 다중 통화 |
| mc_total_coupon_discount_amount | array | `[]` | 쿠폰 할인 합계 다중 통화 |
| mc_total_code_discount_amount | array | `[]` | 할인코드 할인 다중 통화 |
| mc_total_points_used_amount | array | `[]` | 포인트 사용 다중 통화 |
| mc_total_deposit_used_amount | array | `[]` | 예치금 사용 다중 통화 |
| item_count | integer | `4` | item 개수 (집계) |
| total_quantity | integer | `0` | 주문 옵션 수량 합계 (options 로드 시) |
| total_list_price | integer | `0` | 정가 합계 (옵션 스냅샷 정가 × 수량 합계) |
| total_list_price_formatted | string | `0원` | `total_list_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| ordered_at | string | `2026-07-08T01:44:49+00:00` | ordered 일시 |
| ordered_at_formatted | string | `2026-07-08 10:44:49` | `ordered_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| paid_at | null | `null` | paid 일시 |
| paid_at_formatted | null | `null` | `paid_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| confirmed_at | null | `null` | confirmed 일시 |
| confirmed_at_formatted | null | `null` | `confirmed_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| cancelled_at | null | `null` | cancelled 일시 |
| cancelled_at_formatted | null | `null` | `cancelled_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| delivered_at | null | `null` | delivered 일시 |
| total_tax_amount | integer | `29757` | 총 과세금액 |
| total_tax_amount_formatted | string | `29,757원` | `total_tax_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_vat_amount | integer | `0` | 총 부가세금액 |
| total_vat_amount_formatted | string | `0원` | `total_vat_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_taxable_supply_amount | integer | `29757` | 과세 공급가액 (총 과세금액 − 부가세, 영수증 과세금액 표시 SSoT) |
| total_taxable_supply_amount_formatted | string | `29,757원` | `total_taxable_supply_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_tax_free_amount | integer | `0` | 총 면세금액 |
| total_tax_free_amount_formatted | string | `0원` | `total_tax_free_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| user | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 대상 사용자 정보 객체 (uuid/name/email 등 — user 관계 파생) |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | user 식별자 (연관 리소스 참조) |
| user_login_id | null | `null` | 회원 로그인 아이디 (login_id, 비회원 주문이면 null) |
| orderer_name | null | `null` | 주문자 이름 (배송지에서 플래튼) |
| orderer_phone | null | `null` | 주문자 휴대전화 (배송지에서 플래튼) |
| orderer_tel | null | `null` | 주문자 일반전화 (배송지에서 플래튼, 미입력 시 null) |
| orderer_email | null | `null` | 주문자 이메일 (배송지에서 플래튼, 비회원 알림 수신 통로) |
| recipient_name | null | `null` | 수령인 이름 (배송지에서 플래튼) |
| recipient_phone | null | `null` | 수령인 휴대전화 (배송지에서 플래튼) |
| recipient_tel | null | `null` | 수령인 일반전화 (배송지에서 플래튼, 미입력 시 null) |
| recipient_zipcode | null | `null` | 수령인 우편번호 (배송지에서 플래튼) |
| recipient_address | null | `null` | 수령인 기본 주소 (배송지에서 플래튼) |
| recipient_detail_address | null | `null` | 수령인 상세 주소 (배송지에서 플래튼, 미입력 시 null) |
| delivery_memo | null | `null` | 배송 메모 (배송지에서 플래튼, 미입력 시 null) |
| delivery_memo_label | null | `null` | `delivery_memo` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| options | array | `[]` | 주문 옵션(품목) 목록 (OrderOptionResource — 상품·옵션·수량·옵션상태·금액) |
| shipping_address | null | `null` | 배송지 상세 (OrderAddressResource — 주문자/수령인/국내·해외 주소) |
| billing_address | null | `null` | 청구지 상세 (OrderAddressResource, 미분리 시 null) |
| payment | null | `null` | 대표 결제 정보 (OrderPaymentResource — 결제수단·결제상태·금액) |
| payments | array | `[]` | 결제 이력 목록 (OrderPaymentResource 배열 — 다회 결제/부분결제 포함) |
| shippings | array | `[]` | 배송 이력 목록 (OrderShippingResource 배열 — 배송유형·택배사·송장번호) |
| cancels | array | `[]` | 취소 이력 목록 (OrderCancelResource 배열 — 취소 사유·상세·취소일시, 최근순) |
| promotions_applied_snapshot | null | `null` | 적용된 프로모션 스냅샷 (재계산용) |
| shipping_policy_applied_snapshot | null | `null` | 적용된 배송정책 스냅샷 (재계산용) |
| admin_memo | null | `null` | 관리자 메모 (내부 관리용) |
| customer_memo | null | `null` | 고객 메모 (주문 시 고객이 남긴 메모) |
| created_at | string | `2026-07-08T01:44:49+00:00` | 생성 일시 |
| updated_at | string | `2026-07-08T01:44:49+00:00` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_update":true,"can_cancel":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문 정보를 조회했습니다.",
    "data": {
        "id": 4,
        "order_number": "APIDOC-20260708-000001",
        "base_currency": "KRW",
        "payment_currency": "KRW",
        "is_cross_currency": false,
        "order_status": "pending_payment",
        "order_status_label": "결제대기",
        "order_status_variant": "warning",
        "is_partially_cancelled": false,
        "order_device": "pc",
        "order_device_label": "PC",
        "is_first_order": false,
        "subtotal_amount": 324327,
        "subtotal_amount_formatted": "324,327원",
        "total_discount_amount": 0,
        "total_discount_amount_formatted": "0원",
        "total_shipping_amount": 3000,
        "total_shipping_amount_formatted": "3,000원",
        "total_amount": 327327,
        "total_amount_formatted": "327,327원",
        "total_paid_amount": 0,
        "total_paid_amount_formatted": "0원",
        "total_due_amount": 327327,
        "total_due_amount_formatted": "327,327원",
        "depositor_name": null,
        "total_cancelled_amount": 0,
        "total_cancelled_amount_formatted": "0원",
        "total_refunded_amount": 0,
        "total_refunded_amount_formatted": "0원",
        "total_refunded_points_amount": 0,
        "total_refunded_points_amount_formatted": "0원",
        "total_product_coupon_discount_amount": 0,
        "total_product_coupon_discount_amount_formatted": "0원",
        "total_order_coupon_discount_amount": 0,
        "total_order_coupon_discount_amount_formatted": "0원",
        "total_coupon_discount_amount": 0,
        "total_coupon_discount_amount_formatted": "0원",
        "total_code_discount_amount": 0,
        "total_code_discount_amount_formatted": "0원",
        "total_points_used_amount": 0,
        "total_points_used_amount_formatted": "0원",
        "total_deposit_used_amount": 0,
        "total_deposit_used_amount_formatted": "0원",
        "total_earned_points_amount": 3273,
        "total_earned_points_amount_formatted": "3,273원",
        "mc_subtotal_amount": [],
        "mc_total_discount_amount": [],
        "mc_total_shipping_amount": [],
        "mc_total_amount": [],
        "mc_total_product_coupon_discount_amount": [],
        "mc_total_order_coupon_discount_amount": [],
        "mc_total_coupon_discount_amount": [],
        "mc_total_code_discount_amount": [],
        "mc_total_points_used_amount": [],
        "mc_total_deposit_used_amount": [],
        "item_count": 4,
        "total_quantity": 0,
        "total_list_price": 0,
        "total_list_price_formatted": "0원",
        "ordered_at": "2026-07-08T01:44:49+00:00",
        "ordered_at_formatted": "2026-07-08 10:44:49",
        "paid_at": null,
        "paid_at_formatted": null,
        "confirmed_at": null,
        "confirmed_at_formatted": null,
        "cancelled_at": null,
        "cancelled_at_formatted": null,
        "delivered_at": null,
        "total_tax_amount": 29757,
        "total_tax_amount_formatted": "29,757원",
        "total_vat_amount": 0,
        "total_vat_amount_formatted": "0원",
        "total_taxable_supply_amount": 29757,
        "total_taxable_supply_amount_formatted": "29,757원",
        "total_tax_free_amount": 0,
        "total_tax_free_amount_formatted": "0원",
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com"
        },
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "user_login_id": null,
        "orderer_name": null,
        "orderer_phone": null,
        "orderer_tel": null,
        "orderer_email": null,
        "recipient_name": null,
        "recipient_phone": null,
        "recipient_tel": null,
        "recipient_zipcode": null,
        "recipient_address": null,
        "recipient_detail_address": null,
        "delivery_memo": null,
        "delivery_memo_label": null,
        "options": [],
        "shipping_address": null,
        "billing_address": null,
        "payment": null,
        "payments": [],
        "shippings": [],
        "cancels": [],
        "promotions_applied_snapshot": null,
        "shipping_policy_applied_snapshot": null,
        "admin_memo": null,
        "customer_memo": null,
        "created_at": "2026-07-08T01:44:49+00:00",
        "updated_at": "2026-07-08T01:44:49+00:00",
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_update": true,
            "can_cancel": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 주문번호(`orderNumber`)로 주문 상세를 조회하는 회원/비회원 공용 엔드포인트입니다. `optional.sanctum`으로 로그인 여부에 따라 분기하는데, 로그인 상태면 본인 회원 주문만 `OrderResource`로 반환하고 아니면 404(마이페이지 주문 목록으로 안내), 비로그인이면 `X-Guest-Order-Token`으로 비회원 주문을 매칭해 `GuestOrderResource`로 반환하고 실패 시 404(비회원 조회 폼으로 안내)합니다. 회원이 비회원 토큰을 들고 와도 회원 분기가 우선하며, 실패 사유는 모두 동일한 404로 처리해 정보 노출을 차단합니다. 결제 완료 후 주문번호 기반 주문 완료/상세 페이지에서 사용합니다.


