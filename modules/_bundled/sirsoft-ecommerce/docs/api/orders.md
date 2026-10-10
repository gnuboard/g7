# Orders API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

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
| date_basis | query | string | 아니오 | `site` | 시작·종료일을 어느 시간대의 하루로 풀지. 없으면 보는 사람(로그인 계정)의 하루, `site` 면 사이트 기본 시간대의 하루 — 대시보드 「오늘」 배지 링크가 배지가 센 하루와 같은 범위로 목록을 열 때 싣는다. 그 밖의 값은 422 |
| order_status | query | array | 아니오 | — | 주문상태 다중 선택 필터 (OrderStatusEnum 값 배열, 해당 상태의 주문만 조회) |
| option_status | query | array | 아니오 | — | 주문옵션 상태 다중 선택 필터 (OrderStatusEnum 값 배열, 해당 옵션 상태를 가진 주문만 조회) |
| shipping_type | query | array | 아니오 | — | 배송유형 다중 선택 필터 (ShippingType 코드 배열) |
| payment_method | query | array | 아니오 | — | 결제수단 다중 선택 필터. 코어 8종과 PG 플러그인이 등록한 확장 결제수단 ID(예: `nhnkcp_naverpay`)를 모두 허용 |
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
| claim_presence | query | string | 아니오 | `none`, `any`, `active` | 클레임 유무 필터 (D22) — none 클레임 없음 / any 클레임 있음 / active 미종결 클레임 있음. 아래 클레임 축(상태·귀책·신청일)과 조합하면 그 조건의 클레임 기준으로 판정 |
| claim_exchange_statuses | query | array | 아니오 | ExchangeStatusEnum 값 배열 | 교환 상태 필터 — 해당 상태의 교환 신청서가 있는 주문 (여러 유형의 상태 집합은 OR 로 조합) |
| claim_return_statuses | query | array | 아니오 | ReturnStatusEnum 값 배열 | 반품 상태 필터 |
| claim_refund_statuses | query | array | 아니오 | RefundStatusEnum 값 배열 | 환불 상태 필터 — 출처로 거르지 않는다. 취소 발 환불도 환불 탭에 선다(D6) |
| claim_cancel_types | query | array | 아니오 | `full`, `partial` | 취소 필터 — 어휘는 전이 상태가 아니라 **취소 유형**(전체/부분, CancelTypeEnum)이다. 취소는 즉시 파이프라인이라 상태 2종(신청/완료)이 화면 구분 축이 되지 못한다 |
| claim_fault_types | query | array | 아니오 | `customer`, `seller`, `carrier` | 귀책 필터 — 같은 클레임 행에 함께 적용. 취소 테이블에는 귀책 축이 없어 귀책 지정 시 취소 유형은 매칭에서 제외 |
| claim_requested_from | query | date | 아니오 | — | 클레임 신청일 범위 시작 (해당 범위에 접수된 클레임이 있는 주문) |
| claim_requested_to | query | date | 아니오 | after_or_equal:claim_requested_from | 클레임 신청일 범위 종료 |
| sort_by | query | string | 아니오 | `ordered_at`, `paid_at`, `total_amount`, `shipped_at` | 정렬 기준 필드명 (그 외 값은 422). `shipped_at` 은 배송 테이블 기준 — `desc` 는 주문별 **가장 늦은** 발송일, `asc` 는 **가장 이른** 발송일로 정렬하며 발송 이력이 없는 주문도 목록에 남는다 |
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
| number | integer | `100` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `1408` | 기본 키 (내부 식별자) |
| order_number | string | `20260730-1436301187` | 주문번호 (사용자 노출용 고유 식별 코드) |
| order_status | string | `pending_payment` | 주문상태 (OrderStatusEnum 값 — 결제대기/결제완료/배송중 등) |
| order_status_label | string | `결제대기` | `order_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| order_status_variant | string | `warning` | `order_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| order_status_icon | string\|null | `status-paid` | 상태를 상징하는 **아이콘 의미 토큰** (아이콘 라이브러리 중립 — 어휘는 [admin-claims.md](admin-claims.md#progress_steps-항목) "아이콘 의미 토큰 어휘" 참조). `OrderStatusEnum::icon()` 단일 출처이며, 토큰을 글리프로 바꾸는 표는 템플릿의 Icon 컴포넌트가 가진다 |
| base_currency | string | `JPY` | 금액 표기 기준 통화 (모든 *_formatted 필드의 통화, 주문 시점 base_currency 고정) |
| payment_currency | string | `JPY` | 결제 통화 (유저가 선택·결제한 통화, base_currency 와 다르면 병기 표시) |
| is_cross_currency | boolean | `false` | cross currency 여부 |
| is_partially_cancelled | boolean | `false` | partially cancelled 여부 |
| total_amount | integer | `125000` | 최종 주문금액 (상품합계 − 할인 + 배송비) |
| total_amount_formatted | string | `¥125,000` | `total_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_shipping_amount | integer | `0` | 총 배송비 |
| total_shipping_amount_formatted | string | `¥0` | `total_shipping_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_paid_amount | integer | `0` | 총 실제 결제금액 (PG 결제된 금액) |
| total_paid_amount_formatted | string | `¥0` | `total_paid_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_unpaid_amount | integer | `125000` | 미결제 잔액 (최종 주문금액 − 실제 결제금액) |
| total_unpaid_amount_formatted | string | `¥125,000` | `total_unpaid_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_cancelled_amount | integer | `0` | 총 취소금액 |
| total_refunded_amount | integer | `0` | 총 환불금액 |
| total_points_used_amount | integer | `0` | 총 포인트(마일리지) 사용액 |
| total_points_used_amount_formatted | string | `¥0` | `total_points_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_earned_points_amount | integer | `1250` | 총 적립 예정 포인트 |
| total_earned_points_amount_formatted | string | `¥1,250` | `total_earned_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| ordered_at | string | `2026-07-30T10:36:30+00:00` | ordered 일시 |
| ordered_at_formatted | string | `2026-07-30 19:36:30` | `ordered_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| order_device | string | `pc` | 주문 디바이스 (DeviceTypeEnum 값 — pc/mobile/app) |
| order_device_label | string | `PC` | `order_device` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_first_order | boolean | `false` | first order 여부 |
| user | object | `{"uuid":"a26219fc-94a0-4f63-9404-04c2a6ac99e4","name":"최고…` | 회원 주문의 주문자 요약 (uuid·name, 비회원 주문이면 미포함) |
| first_option | object | `{"product_name":"겨울 패딩 점퍼 #16","product_option_name":"카키\…` | 대표 표시용 첫 번째 주문 옵션 요약 (상품명·옵션명·수량·썸네일·추가옵션 요약) |
| options_count | integer | `1` | options 개수 (집계) |
| address | object | `{"orderer_name":"연정훈","recipient_name":"소정훈","recipient_c…` | 배송지 요약 (주문자명·수령인명·배송국가 코드/현지화명) |
| payment | object | `{"payment_method":"dbank","payment_method_label":"무통장입금","payment_method_display_label":null}` | 결제 요약 (결제수단 값·현지화 라벨). `payment_method_display_label` 은 결제 플러그인이 필터 훅 `sirsoft-ecommerce.order.filter_payment_method_display_label` 로 채우는 표시 라벨(간편결제 원 수단 등) — 채우는 플러그인이 없으면 `null` |
| shipping | object | `{"shipping_type":null,"shipping_type_label":null,"shippin…` | 배송 요약 (배송유형·배송방법 라벨·택배사명·송장번호, 첫 번째 배송 기준) |
| claim_summary | array | `[{"type":"return","type_label":"반품","count":2,"latest_cla…` | 유형별 최신 상태 배지 배열 (D22). 항상 직렬화된다 — 접수 건이 있는 유형만 항목으로 실리므로 클레임이 없는 주문은 빈 배열이다. 항목: `{type, type_label, count, latest_claim_number, status, status_label, status_variant}` — 환불 축은 취소 발 신청서를 제외 |
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
                "number": 100,
                "id": 1408,
                "order_number": "20260730-1436301187",
                "order_status": "pending_payment",
                "order_status_label": "결제대기",
                "order_status_variant": "warning",
                "base_currency": "JPY",
                "payment_currency": "JPY",
                "is_cross_currency": false,
                "is_partially_cancelled": false,
                "total_amount": 125000,
                "total_amount_formatted": "¥125,000",
                "total_shipping_amount": 0,
                "total_shipping_amount_formatted": "¥0",
                "total_paid_amount": 0,
                "total_paid_amount_formatted": "¥0",
                "total_unpaid_amount": 125000,
                "total_unpaid_amount_formatted": "¥125,000",
                "total_cancelled_amount": 0,
                "total_refunded_amount": 0,
                "total_points_used_amount": 0,
                "total_points_used_amount_formatted": "¥0",
                "total_earned_points_amount": 1250,
                "total_earned_points_amount_formatted": "¥1,250",
                "ordered_at": "2026-07-30T10:36:30+00:00",
                "ordered_at_formatted": "2026-07-30 19:36:30",
                "order_device": "pc",
                "order_device_label": "PC",
                "is_first_order": false,
                "user": null,
                "first_option": {
                    "product_name": "겨울 패딩 점퍼 #16",
                    "product_option_name": "카키/XL",
                    "product_code": "5E1WSBY0CHFX7UJU",
                    "quantity": 1,
                    "thumbnail_url": "/api/modules/sirsoft-ecommerce/product-image/81d10a0b743c",
                    "additional_options_summary": null
                },
                "options_count": 1,
                "address": {
                    "orderer_name": "연정훈",
                    "recipient_name": "소정훈",
                    "recipient_country_code": "KR",
                    "recipient_country_name": {
                        "ko": "한국",
                        "en": "South Korea"
                    }
                },
                "payment": null,
                "shipping": {
                    "shipping_type": null,
                    "shipping_type_label": null,
                    "shipping_method_label": null,
                    "carrier_name": null,
                    "tracking_number": null
                },
                "claim_summary": [],
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_update": true
                }
            },
            {
                "number": 99,
                "id": 1405,
                "order_number": "20260730-1436298546",
                "order_status": "pending_payment",
                "order_status_label": "결제대기",
                "order_status_variant": "warning",
                "base_currency": "JPY",
                "payment_currency": "JPY",
                "is_cross_currency": false,
                "is_partially_cancelled": true,
                "total_amount": 66000,
                "total_amount_formatted": "¥66,000",
                "total_shipping_amount": 0,
                "total_shipping_amount_formatted": "¥0",
                "total_paid_amount": 66000,
                "total_paid_amount_formatted": "¥66,000",
                "total_unpaid_amount": 0,
                "total_unpaid_amount_formatted": "¥0",
                "total_cancelled_amount": 0,
                "total_refunded_amount": 0,
                "total_points_used_amount": 0,
                "total_points_used_amount_formatted": "¥0",
                "total_earned_points_amount": 698,
                "total_earned_points_amount_formatted": "¥698",
                "ordered_at": "2026-07-30T08:36:29+00:00",
                "ordered_at_formatted": "2026-07-30 17:36:29",
                "order_device": "pc",
                "order_device_label": "PC",
                "is_first_order": false,
                "user": null,
                "first_option": {
                    "product_name": "기본 양말 5족 #2",
                    "product_option_name": "그레이/M",
                    "product_code": "S0SO3A6SJFYLAKSF",
                    "quantity": 3,
                    "thumbnail_url": "/api/modules/sirsoft-ecommerce/product-image/8fc3ae669be2",
                    "additional_options_summary": null
                },
                "options_count": 3,
                "address": {
                    "orderer_name": "양호민",
                    "recipient_name": "전종수",
                    "recipient_country_code": "KR",
                    "recipient_country_name": {
                        "ko": "한국",
                        "en": "South Korea"
                    }
                },
                "payment": null,
                "shipping": {
                    "shipping_type": null,
                    "shipping_type_label": null,
                    "shipping_method_label": null,
                    "carrier_name": null,
                    "tracking_number": null
                },
                "claim_summary": [],
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_update": true
                }
            },
            "... (총 25건 중 2건 표시)"
        ],
        "abilities": {
            "can_update": true
        },
        "statistics": {
            "total": 100,
            "status_counts": {
                "confirmed": 10,
                "delivered": 20,
                "cancelled": 5,
                "shipping": 13,
                "payment_complete": 20,
                "preparing": 15,
                "shipping_ready": 5,
                "shipping_hold": 5,
                "pending_payment": 7
            },
            "today_count": 0,
            "today_revenue": 0,
            "monthly_revenue": 0
        },
        "pagination": {
            "current_page": 1,
            "last_page": 4,
            "per_page": 25,
            "total": 100,
            "from": 1,
            "to": 25,
            "has_more_pages": true
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
| order_status | body | string | 아니오 | — | 일괄 전환할 주문상태 (OrderStatusEnum 값, `pending_order` 및 클레임 코스 상태 4종(`exchange_in_progress`, `return_in_progress`, `exchanged`, `returned`) 제외 · 전이 규칙 검증) |
| carrier_id | body | integer | 아니오 | — | carrier 식별자 |
| tracking_number | body | string | 아니오 | max 50 | 송장(운송장)번호 (배송 관련 상태로 전환 시 carrier_id 와 함께 필수) |
| apply_stock_deduction | body | boolean | 아니오 | — | 수동 결제 처리 — 재고 차감 (판매 상태로 가는 전이에만 쓰임, 생략하면 켬 — Q13) |
| apply_mileage_deduction | body | boolean | 아니오 | — | 수동 결제 처리 — 사용 마일리지 차감 (생략하면 켬. 취소 주문을 되살리면 취소 때 돌려준 마일리지를 다시 뺀다 — Q14) |
| record_paid_amount | body | boolean | 아니오 | — | 수동 결제 처리 — 결제금액 기록 (생략하면 켬. 결제 전 주문은 결제예정금액을, 되살린 주문은 취소 때 돌려준 현금을 받은 것으로 기록한다) |

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

_단건 응답: `data` 객체의 필드 (`OrderService::bulkUpdate()` 반환 배열)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `3` | 실제로 변경된 주문 건수 (상태 변경 건수와 배송정보 변경 건수 중 큰 값) |
| requested_count | integer | `3` | 요청한 `ids` 배열의 건수 (대상 주문 수) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "2개 주문이 수정되었습니다.",
    "data": {
        "updated_count": 3,
        "requested_count": 3
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 상태 전이 규칙 위반(현재 상태 → 목표 상태 불가), 배송 관련 상태 전환 시 `carrier_id`/`tracking_number` 누락, 취소→판매상태 복원 시 재고 부족 등도 여기에 해당 (`일괄 처리에 실패했습니다.`) |
| 422 | Unprocessable Entity | 수동 결제 처리 — 회원 마일리지 잔액 부족(`exceptions.mileage.insufficient_for_payment_complete[_bulk]`, `errors.order_numbers` 에 모자란 주문번호 전부 · 일괄은 전체를 바꾸지 않음), 재고 부족(`exceptions.manual_paid.stock_insufficient`, `errors.items`), 되살릴 주문의 쿠폰을 다른 주문이 이미 씀(`exceptions.reactivation.coupon_unavailable[_bulk]`, `errors.order_numbers`·`errors.coupons`) |
| 500 | Server Error | 일괄 처리 중 예기치 못한 오류 (`일괄 처리에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 관리자가 여러 주문(`ids`)의 주문상태나 배송 정보(택배사·송장번호)를 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@bulkUpdate`가 `OrderService::bulkUpdate()`로 처리합니다. 주문 목록에서 여러 건을 선택해 "배송 처리"·"상태 일괄 변경" 등을 수행할 때 사용합니다.


### POST /api/modules/sirsoft-ecommerce/admin/orders/bulk/manual-paid-preview
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.bulk.manual-paid-preview -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.bulk.manual-paid-preview`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@bulkManualPaidPreview`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터** — `PATCH .../orders/bulk` 와 같은 검증을 쓴다(미리보기 통과 ⇔ 실행 검증 통과).

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 주문 ID 배열 |
| order_status | body | string | 아니오 | — | 일괄 전환할 주문상태 (판매 상태가 아니면 모든 주문이 「해당 없음」) |
| carrier_id | body | integer | 아니오 | — | 택배사 ID (실행과 같은 검증) |
| tracking_number | body | string | 아니오 | max 50 | 송장번호 (실행과 같은 검증) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/orders/bulk/manual-paid-preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [1624, 1625],
    "order_status": "payment_complete"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| target_status | string | `payment_complete` | 목표 상태 |
| orders | array | `[{…}]` | 주문별 판정 (주문 id 오름차순) |
| orders[].transition | object | `{"enters_paid_course":true,"revives_whole_order":false,"revived_item_count":0}` | 전이 분류 — 결제 전 → 판매(P) / 취소 → 판매(R-주문) / 되살리는 취소 상품 수(R-상품) |
| orders[].stock_deduction | object | `{"applicable":true,"option_count":2,"reason":null}` | 재고 차감 해당 여부·대상 상품 행 수 (`reason`: `no_pending_stock`) |
| orders[].mileage_deduction | object | `{"applicable":true,"amount":5000,"amount_formatted":"5,000원","currency":"KRW","balance":3000,"balance_sufficient":false,"reason":null}` | 사용 마일리지 차감 — 같은 회원의 주문은 id 순으로 잔액을 누적 판정 (`reason`: `guest`·`not_used`·`already_deducted`·`nothing_to_reclaim`) |
| orders[].paid_amount_record | object | `{"applicable":true,"amount":55000,"amount_formatted":"55,000원","auto_sync":false,"auto_sync_amount":null,"auto_sync_amount_formatted":null,"reason":null}` | 결제금액 기록 — `auto_sync=true` 는 결제행에 입금이 이미 기록돼 체크와 무관하게 맞춘다는 뜻 (`reason`: `already_recorded`·`payment_row_settled`·`nothing_due`·`nothing_to_reclaim`·`never_paid`(입금 전 취소 주문 — 해당이며 복원 합계를 기록)) |
| orders[].restoration | object | `{"applicable":true,"revived_option_count":2,"revived_quantity":3,"totals_before":{…},"totals_after":{…},"total_amount_before_formatted":"0원","total_amount_after_formatted":"58,000원","coupon_conflicts":[],"legacy_snapshot":false}` | 재활성 복원 — 되살릴 상품과 처음 주문 조건으로 다시 계산한 합계, 쿠폰 충돌(`reason`: `used_by_other_order`·`revoked`·`missing`) |
| orders[].warnings | array | `["pg_already_cancelled"]` | 경고 — PG 결제가 이미 취소된 주문을 되살림(이후 환불은 계좌·마일리지) |
| summary.order_count | integer | `2` | 요청 주문 수 |
| summary.affected_order_count | integer | `1` | 대상 전이가 있는 주문 수 |
| summary.any_applicable | boolean | `true` | 세 항목·자동 정합·복원 중 하나라도 해당하면 true (확인 창 블록 표시) |
| summary.stock_deduction.applicable_count | integer | `1` | 재고 차감 해당 주문 수 |
| summary.mileage_deduction | object | `{"applicable_count":1,"total_amount_formatted":"5,000원","short_order_numbers":["ORD-…"]}` | 마일리지 해당 수·합계(기준 통화가 섞이면 `null`)·잔액 부족 주문번호 |
| summary.paid_amount_record | object | `{"applicable_count":1,"total_amount_formatted":"55,000원","auto_sync_count":0}` | 결제금액 해당 수·합계·자동 정합 수 |
| summary.never_paid_count | integer | `0` | 입금 전에 취소된 주문 수 |
| summary.restoration | object | `{"applicable_count":1,"revived_quantity":3,"coupon_conflict_order_numbers":[],"legacy_snapshot_count":0}` | 복원 대상 수·되살릴 수량·쿠폰 충돌 주문번호(있으면 실행이 422) |
| summary.warnings.pg_already_cancelled | array | `[]` | PG 결제가 이미 취소된 주문번호 |

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없거나 권한 범위 밖 주문이 섞인 경우 |
| 422 | Unprocessable Entity | 실행 요청과 같은 검증 실패 (전이 규칙 위반·택배사 누락·세 값이 boolean 아님 등) |
| 500 | Server Error | 미리보기 계산 실패 (`결제 처리 항목을 확인하지 못했습니다.`) |

<!-- @generated:end -->

**설명** 주문 목록 일괄변경 확인 창이 열릴 때 부르는 미리보기입니다. DB 를 바꾸지 않습니다. 실행(`PATCH .../orders/bulk`)과 같은 판정 메서드(`OrderProcessingService::planManualPaidTransitionEffects`)를 써서, 결제 전 주문을 결제완료 이후로 올리거나 취소 주문을 되살릴 때 「재고 차감 · 사용 마일리지 차감 · 결제금액 기록」 중 무엇이 해당하는지와 금액, 재활성 복원 합계, 경고를 돌려줍니다. 해당 없는 항목은 확인 창에서 숨깁니다.

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
DELETE /api/modules/sirsoft-ecommerce/admin/orders/{order} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 소프트 삭제 성공 여부 (항상 `true`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문이 삭제되었습니다.",
    "data": {
        "deleted": true
    }
}
```

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
- **권한 메모(1.3.0)**: 응답 `abilities.can_execute_refund`(= `sirsoft-ecommerce.claims.execute-refund`) — 취소 창 「환불까지 한 번에 처리」와 결제 플러그인 [환불 재시도]를 잠그는 키. 기존 `can_update` 는 그대로.

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/orders/{order} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1316` | 기본 키 (내부 식별자) |
| order_number | string | `20260730-1436224914` | 주문번호 |
| base_currency | string | `JPY` | 금액 표기 기준 통화 (모든 *_formatted 필드의 통화, 주문 시점 base_currency 고정) |
| payment_currency | string | `JPY` | 결제 통화 (유저가 선택·결제한 통화, base_currency 와 다르면 병기 표시) |
| is_cross_currency | boolean | `false` | cross currency 여부 |
| order_status | string | `payment_complete` | 주문상태 (OrderStatusEnum) |
| order_status_label | string | `Payment Complete` | `order_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| order_status_variant | string | `info` | `order_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| order_status_icon | string\|null | `status-paid` | 상태를 상징하는 **아이콘 의미 토큰** (아이콘 라이브러리 중립 — 어휘는 [admin-claims.md](admin-claims.md#progress_steps-항목) "아이콘 의미 토큰 어휘" 참조). `OrderStatusEnum::icon()` 단일 출처이며, 토큰을 글리프로 바꾸는 표는 템플릿의 Icon 컴포넌트가 가진다 |
| progress_steps | array | `[{"value":"pending_payment","label":"결제대기","state":"done","icon":"status-payment-pending","variant":"warning"}, …]` | **진행단계 배열** — 관리자·회원·비회원 상세가 같은 배열(`OrderProgressSteps` 단일 출처)로 스텝퍼를 그린다. 순서는 `OrderStatusEnum::progressSequence()` 7단계(결제대기 → 결제완료 → 상품준비중 → 배송준비완료 → 배송중 → 배송완료 → 구매확정). 항목 `state` 는 `done`/`current`/`upcoming`/`off_path` — 배송보류·주문취소·교환/반품 진행·교환/반품 완료는 순서 밖이라 마지막에 `off_path` 칸 하나로 붙고 그때 순서 안에 `current` 는 없다. 도달 지점은 현재 상태와 `paid_at`·상품 `delivered_at`·`confirmed_at` 중 더 나아간 쪽. `icon`·`variant` 는 각 상태 Enum 의 `icon()`/`variant()` |
| is_partially_cancelled | boolean | `false` | partially cancelled 여부 |
| is_past_payment | boolean | `true` | 결제 이후 단계(결제완료 ~ 구매확정)인지 여부. 결제 전 상태와 주문취소는 `false`. 판정은 `OrderStatusEnum::isPastPayment()` — 관리자 입금확인 창이 「결제완료로 처리」 체크와 「상태는 그대로 두고 입금만 기록」 안내를 이 값으로 가른다 |
| order_device | string | `pc` | 주문 디바이스 (pc/mobile/app) |
| order_device_label | string | `PC` | `order_device` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_first_order | boolean | `true` | first order 여부 |
| subtotal_amount | integer | `179000` | 상품 합계 (할인 전, 상품가×수량 합계) |
| subtotal_amount_formatted | string | `¥179,000` | `subtotal_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_discount_amount | integer | `3000` | 총 할인금액 (모든 할인 합계) |
| total_discount_amount_formatted | string | `¥3,000` | `total_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_shipping_amount | integer | `0` | 총 배송비 |
| total_shipping_amount_formatted | string | `¥0` | `total_shipping_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_amount | integer | `176000` | 최종 주문금액 (subtotal - discount + shipping) |
| total_amount_formatted | string | `¥176,000` | `total_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_paid_amount | integer | `176000` | 총 실제 결제금액 (PG 결제액) |
| total_paid_amount_formatted | string | `¥176,000` | `total_paid_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_due_amount | integer | `0` | 총 결제예정금액 (무통장 등) |
| total_due_amount_formatted | string | `¥0` | `total_due_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_due_charge_amount | integer | `0` | **결제 통화(`payment_currency`) 기준 실청구액**, 최소 화폐단위 정수 (KRW ×1 / 소수통화 ×10^n). `total_due_amount` 는 base 통화 금액이므로 base≠결제 통화면 값이 다릅니다. 무통장 입금확인이 검증하는 금액이 이 값이며, 화면은 이 값을 그대로 입금액으로 보내야 합니다 |
| total_due_charge_amount_formatted | string | `0원` | `total_due_charge_amount` 를 결제 통화 기호로 표기한 문자열 |
| depositor_name | null | `null` | 무통장 입금자명 (입금확인 모달 기본값, payment 관계 로드 시에만 노출) |
| total_cancelled_amount | integer | `0` | 총 취소금액 |
| total_cancelled_amount_formatted | string | `¥0` | `total_cancelled_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_amount | integer | `0` | 총 환불금액 |
| total_refunded_amount_formatted | string | `¥0` | `total_refunded_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_points_amount | integer | `0` | 총 환불 포인트 |
| total_refunded_points_amount_formatted | string | `¥0` | `total_refunded_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_mileage_credit_amount | number | `0` | 현금 환불 중 「마일리지로 환불」로 회원 마일리지에 지급한 합계(`total_refunded_amount` 에 포함된 그중 내역). 결제수단이 마일리지인 주문은 0 |
| total_refunded_mileage_credit_amount_formatted | string | `¥0` | `total_refunded_mileage_credit_amount` 의 주문 시점 통화 표기 |
| claim_additional_paid_total | number | `0` | 이 주문의 클레임(교환·반품) 추가결제 실수납 합계 — 결제 완료 건의 청구액에서 부분 환급을 뺀 값. 주문 총 배송비와 분리해 표기하며 0원이면 화면에 노출하지 않는다 |
| claim_additional_paid_total_formatted | string | `¥0` | `claim_additional_paid_total` 값의 주문 시점 통화 표기 문자열 |
| mc_claim_additional_paid_total | array | `[]` | 다통화 표기 맵 (통화 코드 => `{amount, formatted}`). 다통화 기록이 없으면 빈 배열 |
| total_product_coupon_discount_amount | integer | `3000` | 상품 쿠폰 할인 합계 |
| total_product_coupon_discount_amount_formatted | string | `¥3,000` | `total_product_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_order_coupon_discount_amount | integer | `0` | 주문 쿠폰 할인 합계 |
| total_order_coupon_discount_amount_formatted | string | `¥0` | `total_order_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_coupon_discount_amount | integer | `3000` | 총 쿠폰 할인금액 |
| total_coupon_discount_amount_formatted | string | `¥3,000` | `total_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_code_discount_amount | integer | `0` | 총 할인코드 할인금액 |
| total_code_discount_amount_formatted | string | `¥0` | `total_code_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_points_used_amount | integer | `0` | 총 포인트 사용액 |
| points_not_deducted | boolean | `false` | 사용 마일리지가 있는데 회원 잔액에서 차감되지 않은 주문인지(수동 결제완료에서 「마일리지 차감」을 끈 경우 등) — 참이면 환불 때 마일리지 몫이 0 이다 |
| total_points_used_amount_formatted | string | `¥0` | `total_points_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_deposit_used_amount | integer | `0` | 총 예치금 사용액 |
| total_deposit_used_amount_formatted | string | `¥0` | `total_deposit_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_earned_points_amount | integer | `1760` | 총 적립 예정 포인트 |
| total_earned_points_amount_formatted | string | `¥1,760` | `total_earned_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| mc_subtotal_amount | object | `{"KRW":{"amount":179000,"formatted":"179,000원"},"USD":{"a…` | 상품합계 다중 통화 |
| mc_total_discount_amount | object | `{"KRW":{"amount":3000,"formatted":"3,000원"},"USD":{"amoun…` | 총 할인 다중 통화 |
| mc_total_shipping_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 총 배송비 다중 통화 |
| mc_total_amount | object | `{"KRW":{"amount":176000,"formatted":"176,000원"},"USD":{"a…` | 최종금액 다중 통화 (payment_amount) |
| mc_total_product_coupon_discount_amount | object | `{"KRW":{"amount":3000,"formatted":"3,000원"},"USD":{"amoun…` | 상품 쿠폰 할인 다중 통화 |
| mc_total_order_coupon_discount_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 주문 쿠폰 할인 다중 통화 |
| mc_total_coupon_discount_amount | object | `{"KRW":{"amount":3000,"formatted":"3,000원"},"USD":{"amoun…` | 쿠폰 할인 합계 다중 통화 |
| mc_total_code_discount_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 할인코드 할인 다중 통화 |
| mc_total_points_used_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 포인트 사용 다중 통화 |
| mc_total_deposit_used_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 예치금 사용 다중 통화 |
| item_count | integer | `2` | item 개수 (집계) |
| total_quantity | integer | `5` | 남은 상품 수량 합계 — 취소 행·교환완료로 닫힌 원 행·교환 진행 중인 후속 행을 뺀 회계 주체 행만 센다 (options 로드 시) |
| total_list_price | integer | `207000` | 정가 합계 (옵션 스냅샷 정가 × 수량) — `total_quantity` 와 같은 행만 센다 |
| total_list_price_formatted | string | `¥207,000` | `total_list_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| ordered_at | string | `2026-07-27T14:36:22+00:00` | ordered 일시 |
| ordered_at_formatted | string | `2026-07-27 23:36:22` | `ordered_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| paid_at | string | `2026-07-30T14:36:22+00:00` | paid 일시 |
| paid_at_formatted | string | `2026-07-30 23:36:22` | `paid_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| confirmed_at | null | `null` | confirmed 일시 |
| confirmed_at_formatted | null | `null` | `confirmed_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| cancelled_at | null | `null` | cancelled 일시 |
| cancelled_at_formatted | null | `null` | `cancelled_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| delivered_at | null | `null` | delivered 일시 |
| total_tax_amount | integer | `16000` | 총 과세금액 |
| total_tax_amount_formatted | string | `¥16,000` | `total_tax_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_vat_amount | integer | `1455` | 총 부가세금액 |
| total_vat_amount_formatted | string | `¥1,455` | `total_vat_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_taxable_supply_amount | integer | `14545` | 과세 공급가액 (총 과세금액 − 부가세, 영수증 과세금액 표시 SSoT) |
| total_taxable_supply_amount_formatted | string | `¥14,545` | `total_taxable_supply_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_tax_free_amount | integer | `0` | 총 면세금액 |
| total_tax_free_amount_formatted | string | `¥0` | `total_tax_free_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| user | object | `{"uuid":"a26219fc-94a0-4f63-9404-04c2a6ac99e4","name":"최고…` | 회원 주문의 주문자 정보 (uuid·name·email, user 관계 로드 시 · 비회원이면 미포함) |
| user_id | string | `a26219fc-94a0-4f63-9404-04c2a6ac99e4` | user 식별자 (연관 리소스 참조) |
| user_login_id | null | `null` | 회원 로그인 아이디 (login_id, 비회원 주문이면 null) |
| orderer_name | string | `박대수` | 주문자 이름 (배송지에서 플래튼) |
| orderer_phone | string | `010-4416-4675` | 주문자 휴대전화 (배송지에서 플래튼) |
| orderer_tel | null | `null` | 주문자 일반전화 (배송지에서 플래튼, 미입력 시 null) |
| orderer_email | string | `shin.yewon@cheon.biz` | 주문자 이메일 (배송지에서 플래튼, 비회원 알림 수신 통로) |
| recipient_name | string | `권강희` | 수령인 이름 (배송지에서 플래튼) |
| recipient_phone | string | `010-6286-6243` | 수령인 휴대전화 (배송지에서 플래튼) |
| recipient_tel | null | `null` | 수령인 일반전화 (배송지에서 플래튼, 미입력 시 null) |
| recipient_zipcode | string | `25530` | 수령인 우편번호 (배송지에서 플래튼) |
| recipient_address | string | `경상남도 광주시 선릉로 768` | 수령인 기본 주소 (배송지에서 플래튼) |
| recipient_detail_address | null | `null` | 수령인 상세 주소 (배송지에서 플래튼, 미입력 시 null) |
| delivery_memo | string | `parcel_box` | 배송 메모 (배송지에서 플래튼, 미입력 시 null) |
| delivery_memo_label | string | `택배함에 넣어주세요` | `delivery_memo` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| options | array | `[{"id":1263,"option_status":"payment_complete","option_st…` | 주문 옵션(품목) 목록 (OrderOptionResource — 상품·옵션·수량·옵션상태·금액) |
| shipping_address | object | `{"id":319,"address_type":"shipping","orderer_name":"박대수",…` | 배송지 상세 (OrderAddressResource — 주문자/수령인/국내·해외 주소) |
| billing_address | null | `null` | 청구지 상세 (OrderAddressResource, 미분리 시 null) |
| payment | object | `{"id":1,"payment_status":"paid","payment_status_label":"P…` | 대표 결제 정보 (OrderPaymentResource — 결제수단·결제상태·금액). 무통장 결제행은 현금영수증 발급 프로바이더 `cash_receipt_provider`(id)와 그 현재 언어 표시명 `cash_receipt_provider_label`(등록 항목의 `name_key` 번역 → `name` → id 순)을 함께 싣는다. `has_cash_to_return`(bool)은 이 주문에 돌려줄 현금이 남아 있는지(주문 장부·결제행 수납액 기준 — [입금확인]에서 입금만 기록한 결제 전 주문도 포함) — 취소 창의 환불 계좌 칸 노출 근거 |
| payments | array | `[{"id":1,"payment_status":"paid","payment_status_label":"…` | 결제 이력 목록 (OrderPaymentResource 배열 — 다회 결제/부분결제 포함) |
| cash_receipt | null | `null` | 현재 유효한 현금영수증 (CashReceiptResource — 취소되지 않은 발급 건, 없으면 null) |
| cash_receipts | array | `[]` | 현금영수증 발급·취소 이력 전체 (CashReceiptResource 배열 — 취소된 건 포함) |

> `CashReceiptResource` 의 `amount`/`tax_free_amount` 는 **결제 통화 기준 실청구액**입니다 (구매자가 실제로 낸 금액으로 세금 증빙이 발행되어야 하므로). `amount_formatted` 도 결제 통화 기호로 표기합니다.
| shippings | array | `[]` | 배송 이력 목록 (OrderShippingResource 배열 — 배송유형·택배사·송장번호) |
| cancels | array | `[]` | 취소 이력 목록 (OrderCancelResource 배열 — 취소 사유·상세·취소일시, 최근순) |
| promotions_applied_snapshot | object | `{"coupon_issue_ids":[7330],"item_coupons":[],"discount_co…` | 적용된 프로모션 스냅샷 (재계산용) |
| shipping_policy_applied_snapshot | object | `{"items": [], "address": {}}` | 적용된 배송정책 스냅샷 (재계산용). `items` 는 옵션별 적용 정책 목록(각 항목: `product_option_id`, `policy`), `address` 는 주문 시점 배송지 메타(`country_code`, `zipcode`). 항목이 없어도 `items` 는 빈 배열이다. 정책의 `standalone_shipping_amount_formatted` 는 저장된 문자열(주문 당시 언어 표기)이 아니라 응답 시점 언어로 다시 포맷한 값이다 — 저장 스냅샷 자체는 바꾸지 않는다 |
| shipping_policy_applied_snapshot (비회원 응답) | object | `{"items": [{"product_option_id": 481, "policy": {"policy_name": "국내 무료배송", "standalone_shipping_amount": 0, "standalone_shipping_amount_formatted": "무료배송"}}]}` | 비로그인(비회원 조회 토큰) 응답은 **표시용 필드만** 내보낸다 — `policy_name` · `standalone_shipping_amount(_formatted)`. 정책 id·계산 근거와 배송지 메타(`address`)는 제외된다. 비회원 주문 상세 화면이 회원과 같은 partial 로 상품별 정책명·개별 배송비를 그리므로 필드 자체를 빼면 그 줄만 오류 없이 사라진다 |
| admin_memo | string\|null | `null` | 관리자 메모 (내부 관리용 — 관리자 응답에서만 값이 실리고 고객·회원 응답은 `null`) |
| customer_memo | null | `null` | 고객 메모 (주문 시 고객이 남긴 메모) |
| created_at | string | `2026-07-30T14:36:22+00:00` | 생성 일시 |
| updated_at | string | `2026-07-30T14:36:22+00:00` | 최종 수정 일시 |
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
        "id": 1316,
        "order_number": "20260730-1436224914",
        "base_currency": "JPY",
        "payment_currency": "JPY",
        "is_cross_currency": false,
        "...": "(101개 키 생략, 총 106개)"
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
| order_status | body | string | 아니오 | — | 변경할 주문상태 (OrderStatusEnum 값, 클레임 코스 상태 4종(`exchange_in_progress`, `return_in_progress`, `exchanged`, `returned`) 제외 · 현재 상태에서 전이 가능한 값만 허용) |
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
| apply_stock_deduction | body | boolean | 아니오 | — | 수동 결제 처리 — 재고 차감 (판매 상태로 가는 전이에만 쓰임, 생략하면 켬 — Q13) |
| apply_mileage_deduction | body | boolean | 아니오 | — | 수동 결제 처리 — 사용 마일리지 차감 (생략하면 켬. 취소 주문을 되살리면 취소 때 돌려준 마일리지를 다시 뺀다 — Q14) |
| record_paid_amount | body | boolean | 아니오 | — | 수동 결제 처리 — 결제금액 기록 (생략하면 켬. 결제 전 주문은 결제예정금액을, 되살린 주문은 취소 때 돌려준 현금을 받은 것으로 기록한다) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/orders/{order} HTTP/1.1
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

_단건 응답: `data` 객체의 필드. 수정된 주문을 `OrderResource` 로 반환하므로 필드 구성은 `GET /admin/orders/{order}` (주문 상세) 의 응답 필드 표와 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| order_number | string | `20260706-1405449337` | 주문번호 |
| order_status | string | `preparing` | 수정 반영된 주문상태 (OrderStatusEnum) |
| order_status_label | string | `상품준비중` | `order_status` 값의 사람이 읽는 라벨 |
| admin_memo | string | `고객 요청으로 주소 정정` | 수정 반영된 관리자 메모 |
| recipient_name | string | `심채원` | 수정 반영된 수령인 이름 |
| recipient_phone | string | `010-3955-6018` | 수정 반영된 수령인 휴대전화 |
| recipient_zipcode | string | `38022` | 수정 반영된 수령인 우편번호 |
| recipient_address | string | `부산광역시 양천구 공항대로 9` | 수정 반영된 수령인 기본 주소 |
| recipient_detail_address | string | `101동 202호` | 수정 반영된 수령인 상세 주소 |
| delivery_memo | string | `parcel_box` | 수정 반영된 배송 메모 |
| shipping_address | object | `{"id":1,"address_type":"shipping",…}` | 배송지 상세 (OrderAddressResource) |
| options / payments / shippings / cancels | array | `[…]` | 주문 상세와 동일한 하위 리소스 목록 |
| updated_at | string | `2026-07-06T14:05:45+00:00` | 최종 수정 일시 |
| abilities | object | `{"can_read":true,"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

> 나머지 금액/일시/다중통화 필드는 `GET /api/modules/sirsoft-ecommerce/admin/orders/{order}` 의 응답 필드 표를 참조하세요 (동일 `OrderResource`).

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문이 수정되었습니다.",
    "data": {
        "id": 1,
        "order_number": "20260706-1405449337",
        "order_status": "preparing",
        "order_status_label": "상품준비중",
        "admin_memo": "고객 요청으로 주소 정정",
        "recipient_name": "심채원",
        "recipient_phone": "010-3955-6018",
        "recipient_zipcode": "38022",
        "recipient_address": "부산광역시 양천구 공항대로 9",
        "recipient_detail_address": "101동 202호",
        "delivery_memo": "parcel_box",
        "delivery_memo_label": "택배함에 넣어주세요",
        "options": [],
        "shipping_address": {},
        "payments": [],
        "shippings": [],
        "cancels": [],
        "updated_at": "2026-07-06T14:05:45+00:00",
        "abilities": {
            "can_read": true,
            "can_update": true
        }
    }
}
```

> `data` 는 주문 상세와 동일한 `OrderResource` 전체 구조입니다 (위 예시는 수정 관련 필드만 발췌).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 주문상태 전이 규칙 위반 등 Service 단계 검증 실패도 포함 (`messages.orders.update_failed`) |
| 500 | Server Error | 수정 처리 중 예기치 못한 오류 (`messages.orders.update_failed`) |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)의 주문상태·관리자 메모·수취인 배송지(국내/해외 주소 포함)를 수정합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@update`가 `OrderService::update()`로 처리한 뒤 수정된 주문을 `OrderResource`로 반환합니다. 관리자 주문 상세에서 배송지 정정·메모 기록·상태 변경 등에 사용합니다.


### POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancel
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.cancel -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.cancel`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@cancelOrder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`
- **권한 메모(1.3.0)**: `auto_advance_refund=true` 이면 환불 실행 권한(`sirsoft-ecommerce.claims.execute-refund`)도 요구한다. 없으면 403(`messages.claims.auto_refund_forbidden`)이고 신청서를 만들지 않는다.

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| type | body | string | 예 | — | 취소 유형 (full 전체취소 / partial 부분취소 — partial 이면 items 필수) |
| reason | body | string | 예 | — | 취소 사유 코드 (ClaimReason 의 refund·활성 코드) |
| reason_detail | body | string | 아니오 | max 500 | 취소 사유 상세 (관리자 입력 자유 텍스트) |
| items | body | array | 아니오 | min 1 | 처리 대상 항목 배열 |
| auto_advance_refund | body | boolean | 아니오 | — | 환불까지 한 번에 처리할지 (미지정 시 **false**). 켜면 환불신청서를 만든 뒤 승인·환불완료까지 자동으로 진행한다 — 계좌 환불 수단은 그래도 승인에서 멈춘다. 종전의 `cancel_pg` 는 **없앴다**: 끈 채로 카드 취소를 하면 PG 를 부르지 않고도 환불이 완료로 찍혀 고객 카드는 그대로인데 장부만 환불완료가 됐다. PG 없이 장부만 정리해야 하면 환불 수단을 계좌 환불로 바꿔 수동 처리한다 |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 (pg_first PG 우선 / points_first 포인트 우선, 기본 pg_first) |
| refund_bank.bank_code | body | string | 아니오 | max 10 | 환불 계좌 은행코드. 가상계좌 + 입금완료 건은 필수(주문 시 입력된 계좌가 있으면 생략 가능). 세 필드는 전부 입력하거나 전부 비워야 함 |
| refund_bank.account_number | body | string | 아니오 | max 50 | 환불 계좌번호 |
| refund_bank.holder | body | string | 아니오 | max 50 | 환불 계좌 예금주 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancel HTTP/1.1
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
    "auto_advance_refund": false,
    "refund_priority": "pg_first",
    "refund_bank.bank_code": "예시값",
    "refund_bank.account_number": "예시값",
    "refund_bank.holder": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. 취소 처리 후 재조회한 주문을 `OrderResource` 로 반환하므로 필드 구성은 `GET /admin/orders/{order}` (주문 상세) 의 응답 필드 표와 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| order_number | string | `20260706-1405449337` | 주문번호 |
| order_status | string | `cancelled` | 취소 반영된 주문상태 (전체취소 시 `cancelled`, 부분취소 시 기존 상태 유지) |
| is_partially_cancelled | boolean | `true` | 부분취소 여부 (일부 옵션만 취소된 경우 `true`) |
| total_cancelled_amount | integer | `31000` | 취소 반영 후 총 취소금액 |
| total_refunded_amount | integer | `31000` | 취소 반영 후 총 환불금액 (PG 환불액) |
| total_refunded_points_amount | integer | `0` | 취소 반영 후 환불된 포인트 |
| cancelled_at | string | `2026-07-11T02:10:00+00:00` | 취소 일시 (전체취소 시 기록) |
| cancels | array | `[{"reason":"change_of_mind","reason_detail":null,…}]` | 취소 이력 목록 (OrderCancelResource — 취소 사유·상세·취소일시, 최근순) |
| options | array | `[{"id":1,"option_status":"cancelled",…}]` | 주문 옵션 목록 (취소된 옵션은 `option_status: cancelled`) |
| payments | array | `[{"payment_status":"cancelled",…}]` | 결제 이력 (PG 취소 동반 시 결제상태 반영) |
| abilities | object | `{"can_read":true,"can_update":true,"can_cancel":false}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

> 나머지 금액/일시/다중통화 필드는 `GET /api/modules/sirsoft-ecommerce/admin/orders/{order}` 의 응답 필드 표를 참조하세요 (동일 `OrderResource`).

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문이 취소되었습니다.",
    "data": {
        "id": 1,
        "order_number": "20260706-1405449337",
        "order_status": "cancelled",
        "order_status_label": "취소완료",
        "is_partially_cancelled": false,
        "total_amount": 184000,
        "total_cancelled_amount": 184000,
        "total_refunded_amount": 184000,
        "total_refunded_points_amount": 0,
        "cancelled_at": "2026-07-11T02:10:00+00:00",
        "cancels": [],
        "options": [],
        "payments": [],
        "abilities": {
            "can_read": true,
            "can_update": true,
            "can_cancel": false
        }
    }
}
```

> `data` 는 주문 상세와 동일한 `OrderResource` 전체 구조입니다 (위 예시는 취소 관련 필드만 발췌).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패, 또는 취소 도메인 규칙 위반 — 취소 불가 상태, PG 취소 실패, 가상계좌 환불계좌 정보 누락 등 (`주문 취소에 실패했습니다.`) |
| 500 | Internal Server Error | 서버 내부 오류 (`작업 처리 중 오류가 발생했습니다.`) |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)을 전체취소 또는 부분취소합니다. `auth:sanctum` +
`sirsoft-ecommerce.orders.update` 권한이 필요합니다.

**취소 즉시 환불되지 않습니다.** 이 API 는 **환불신청서**(출처 `admin`)를 만들 뿐이고, 금액확인
→ 승인 → 환불완료는 운영자가 클레임 상세에서 하나씩 밟습니다. 취소신청서는 그 환불이 완료되는
순간에 생기므로, 응답 시점의 주문은 아직 취소로 확정돼 있지 않습니다.

`auto_advance_refund` 를 켜면 승인·완료까지 자동으로 밉니다. 계좌 환불 수단은 그래도 승인에서
멈추고(사람이 송금해야 합니다) 그때는 취소신청서도 아직 생기지 않습니다. 환불할 돈이 없는
주문(미결제·0원)은 환불신청서 없이 취소신청서가 즉시 생깁니다.

운영자가 접수한 신청서는 **구매자가 철회·수정할 수 없습니다** — 출처가 `admin` 이기 때문입니다.
`refund_priority` 로 PG/포인트 환불 우선순위를, `refund_bank` 로 송금 계좌를 지정합니다(사람이
송금해야 하는 수단은 계좌가 필수입니다). 취소 후 갱신된 주문을 `OrderResource` 로 반환하며,
신청 자격·수량 잠금 위반이나 취소 불가 상태는 422 입니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/orders/{order}/cash-receipt
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.cash-receipt.cancel -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.cash-receipt.cancel`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CashReceiptController@cancel`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 대상 order의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/orders/{order}/cash-receipt HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

`data` 는 항상 `null` 입니다 — 취소 성공 여부는 `success` 로 판정합니다. 취소 후의 원장 상태가 필요하면 주문 상세(`GET admin/orders/{order}`)의 `cash_receipts` 를 다시 조회합니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "현금영수증이 취소되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)에 발급된 현금영수증을 **전액 취소**합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\CashReceiptController@cancel`이 `CashReceiptService::cancelAll()`로 활성 영수증을 발급 프로바이더(토스페이먼츠 등)에 취소 요청합니다. 부분취소 API 는 사용하지 않습니다 — 금액이 바뀌는 경우는 재발급(`/cash-receipt/reissue`)이 담당합니다. 취소 이력은 원장에 남아 국세청 신고 근거로 유지되며, 재발급용 식별번호 암호문은 폐기하지 않습니다(관리자가 같은 번호로 다시 발급할 수 있어야 하므로). 발급 취소는 관리자 전용이며 회원·비회원에게는 노출하지 않습니다.

실패 시 사유는 `errors.error_code` 로 구분합니다 — 활성 영수증이 없으면 `NO_ACTIVE_RECEIPT`, 프로바이더 취소가 실패하면 `CANCEL_FAILED` 이며 둘 다 422 입니다.

```json
{
    "success": false,
    "message": "취소할 현금영수증이 없습니다.",
    "errors": {
        "error_code": "NO_ACTIVE_RECEIPT"
    }
}
```


### POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/cash-receipt
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.cash-receipt.issue -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.cash-receipt.issue`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CashReceiptController@issue`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 대상 order의 식별자 |
| receipt_type | body | string | 예 | `income`, `expense` | 발급 용도 (income 소득공제용 — 개인 연말정산 / expense 지출증빙용 — 사업자 매입세액공제) |
| identifier_type | body | string | 예 | `phone`, `card`, `business` | 발급 수단 (phone 휴대폰번호 / card 현금영수증카드번호 / business 사업자등록번호 — 사업자등록번호는 지출증빙 전용) |
| identifier | body | string | 예 | max 30 | 식별번호 (하이픈·공백 제거 후 검증 — 휴대폰 10~11자리 / 현금영수증카드 13~19자리 / 사업자등록번호 10자리 체크섬) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/cash-receipt HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "receipt_type": "income",
    "identifier_type": "phone",
    "identifier": "example-key"
}
```

**응답 필드** (`data` 내부)

`data` 는 발급 이력 1건(`CashReceiptResource`)입니다.

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| id | integer | 발급 이력 ID |
| provider | string | 발급을 수행한 프로바이더 식별자 (예: `sirsoft-pay_tosspayments`) |
| transaction_type | string | 거래 유형 — `issue`(발급) / `cancel`(취소). 발급 응답은 항상 `issue` |
| receipt_type | string | 발급 용도 — `income`(소득공제용) / `expense`(지출증빙용) |
| receipt_type_label | string | 발급 용도의 다국어 표시명 (예: `소득공제용`) |
| amount | integer | 발급 금액 (주문 통화 기준 반올림, 과세분 포함 총액) |
| amount_formatted | string | 발급 금액의 통화 표기 문자열 (예: `12,000원`) |
| tax_free_amount | integer | 발급 금액 중 면세 금액 |
| tax_free_amount_formatted | string | 면세 금액의 통화 표기 문자열 |
| identifier_masked | string | 마스킹된 식별번호 (원본은 어떤 응답에도 노출되지 않음) |
| receipt_url | string\|null | 프로바이더가 발급한 영수증 조회 링크 |
| issue_number | string\|null | 프로바이더가 부여한 승인번호 |
| issue_status | string | 발급 상태 — `IN_PROGRESS` / `COMPLETED` / `FAILED`. 성공 응답은 항상 `COMPLETED` |
| error_code | string\|null | 실패 사유 코드 (성공 시 `null`) |
| error_message | string\|null | 실패 사유 상세 (성공 시 `null`) |
| issued_at | string\|null | 발급 일시 (ISO8601, 머신 판독용) |
| issued_at_formatted | string\|null | 발급 일시 (사용자 타임존 기준 표시용) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "현금영수증이 발급되었습니다.",
    "data": {
        "id": 12,
        "provider": "sirsoft-pay_tosspayments",
        "transaction_type": "issue",
        "receipt_type": "income",
        "receipt_type_label": "소득공제용",
        "amount": 12000,
        "amount_formatted": "12,000원",
        "tax_free_amount": 0,
        "tax_free_amount_formatted": "0원",
        "identifier_masked": "010****5678",
        "receipt_url": "https://dashboard.tosspayments.com/receipt/cash/...",
        "issue_number": "CR20260710000012",
        "issue_status": "COMPLETED",
        "error_code": null,
        "error_message": null,
        "issued_at": "2026-07-10T14:32:11+09:00",
        "issued_at_formatted": "2026-07-10 14:32"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)에 현금영수증을 발급합니다. 주문 당시 구매자가 신청하지 않은 건의 **사후 발급**과, 발급 실패 후 식별번호를 다시 입력해 발급하는 경우에 사용합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\CashReceiptController@issue`가 `CashReceiptService::issue()`로 발급 프로바이더에 위임합니다.

발급 가능 조건은 **무통장입금(dbank) + 입금완료(PAID) + 미발급 + 현금성 금액 > 0 + 프로바이더 설정됨** 이며, 하나라도 어긋나면 `errors.error_code` 로 사유를 구분해 반환합니다 — 이미 발급된 주문은 **409**(`ALREADY_ISSUED`), 그 외는 422(`NOT_CASH_PAYMENT` / `PAYMENT_NOT_PAID` / `NO_ISSUABLE_AMOUNT` / `PROVIDER_NOT_CONFIGURED`). 프로바이더가 발급을 거부하면 422(`PROVIDER_ERROR`)와 함께 `errors.error_message` 에 상세 사유가 담깁니다.

`receipt_type`(발급 용도)과 `identifier_type`(발급 수단)은 독립 필드입니다. 지출증빙용도 휴대폰번호로 발급할 수 있으며, 사업자등록번호는 체크섬을 검증합니다. 소득공제 + 사업자등록번호 조합과, 지출증빙 + 국세청 자진발급 지정번호(`0100001234`) 조합은 거부됩니다.

응답의 `data` 는 발급 이력 1건이며 `receipt_url`(영수증 조회 링크)·`identifier_masked`(뒤 4자리 외 마스킹)·`issue_number` 를 포함합니다. **식별번호 원본은 응답·로그 어디에도 노출되지 않으며**, 재발급용으로만 암호화 보관하다 구매확정 시 폐기합니다.

발급 불가 응답의 `errors.error_code` 는 두 계열로 나뉩니다. 프론트는 이 값으로 안내 문구와 버튼 노출을 분기합니다.

**① 사전 가드**(`resolveIssueBlocker`) — 프로바이더 호출 전에 판정하며 코드 6종이 고정입니다.

| error_code | 상태코드 | 의미 |
| --- | --- | --- |
| `ALREADY_ISSUED` | 409 | 이미 활성 현금영수증이 발급된 주문 |
| `PROVIDER_NOT_CONFIGURED` | 422 | 현금영수증 발급 프로바이더가 설정되지 않음 |
| `PAYMENT_NOT_FOUND` | 422 | 주문에 결제 정보가 없음 |
| `NOT_CASH_PAYMENT` | 422 | 무통장입금(dbank) 주문이 아님 |
| `PAYMENT_NOT_PAID` | 422 | 입금이 확인되지 않음 |
| `NO_ISSUABLE_AMOUNT` | 422 | 발급 가능한 현금성 금액이 0 (전액 마일리지 결제·전액 환불 등) |

**② 프로바이더 실패** — 422 이며 `error_code` 는 **프로바이더가 반환한 값을 그대로 통과**시킵니다(고정 목록 없음). 어떤 리스너도 발급 요청을 처리하지 않은 경우에만 코어가 `NO_PROVIDER_HANDLED` 를 채웁니다. 상세 사유는 `errors.error_message` 에 담기므로, 프론트는 알 수 없는 코드를 만나면 `error_message` 를 그대로 노출하는 것을 기본 동작으로 삼습니다.

```json
{
    "success": false,
    "message": "이미 현금영수증이 발급된 주문입니다.",
    "errors": {
        "error_code": "ALREADY_ISSUED"
    }
}
```


### POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/cash-receipt/reissue
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.cash-receipt.reissue -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.cash-receipt.reissue`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CashReceiptController@reissue`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 대상 order의 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/cash-receipt/reissue HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

`data` 는 재발급된 이력 1건(`CashReceiptResource`, 필드 구성은 발급 API 와 동일)이거나, 잔여 발급액이 0 이어서 재발급할 것이 없으면 `null` 입니다. 두 경우 모두 200 이므로 **`success` 만으로 판정하지 말고 `data` 의 존재 여부까지 확인**해야 합니다.

**응답 예시**

재발급 성공 (잔여 금액 존재):

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "현금영수증이 재발급되었습니다.",
    "data": {
        "id": 14,
        "provider": "sirsoft-pay_tosspayments",
        "transaction_type": "issue",
        "receipt_type": "income",
        "receipt_type_label": "소득공제용",
        "amount": 8000,
        "amount_formatted": "8,000원",
        "tax_free_amount": 0,
        "tax_free_amount_formatted": "0원",
        "identifier_masked": "010****5678",
        "receipt_url": "https://dashboard.tosspayments.com/receipt/cash/...",
        "issue_number": "CR20260710000014",
        "issue_status": "COMPLETED",
        "error_code": null,
        "error_message": null,
        "issued_at": "2026-07-10T15:02:44+09:00",
        "issued_at_formatted": "2026-07-10 15:02"
    }
}
```

전액 환불되어 재발급 대상 금액이 0 인 경우 (정상 결과):

```json
{
    "success": true,
    "message": "현금영수증이 재발급되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 **"취소 성공 + 재발급 실패"** 중간 상태를 복구합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\CashReceiptController@reissue`가 `CashReceiptService::recoverFailedIssue()`를 호출합니다.

부분환불이 일어나면 기존 영수증을 전액취소하고 잔여 금액으로 재발급하는데(부분취소 API 미사용), 취소는 성공했는데 재발급만 실패하면 활성 영수증이 없는 상태로 남습니다. 이때 원장에는 취소(COMPLETED)와 발급(FAILED) 2행이 남으며, 관리자 주문 상세가 경고 배지를 띄웁니다. 이 엔드포인트가 그 상태를 되돌립니다 — 마지막 발급 이력의 발급 용도와 저장된 식별번호 암호문을 재사용하므로 **요청 본문이 필요 없습니다**.

활성 영수증이 이미 있으면 금액 동기화로 위임하고, 잔여 발급액이 0(전액 환불)이면 활성 영수증 없음이 정상 결과이므로 `data: null` 과 함께 200 을 반환합니다. 구매확정 후에는 식별번호 암호문이 폐기되므로 복구할 수 없고 422(`REISSUE_FAILED`)를 반환합니다 — 이 경우 관리자가 발급 API(`POST .../cash-receipt`)로 식별번호를 다시 입력해야 합니다.


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
PATCH /api/modules/sirsoft-ecommerce/admin/orders/{order}/confirm-deposit HTTP/1.1
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

**응답 메시지** — 요청 전 주문 상태와 `mark_order_complete` 에 따라 갈립니다.

| 요청 전 주문 상태 | `mark_order_complete` | 처리 | `message` |
| --- | --- | --- | --- |
| 주문대기·결제대기 | `true` | 결제완료로 전이 | `입금이 확인되어 결제완료 처리되었습니다.` |
| 주문대기·결제대기 | `false`(기본) | 입금 내역만 기록 | `입금 내역을 기록했습니다. 주문 상태는 결제대기로 유지됩니다.` |
| 결제완료 이후(상품준비중·배송 등) | 무관 | 입금 내역과 주문 결제금액(`total_paid_amount`)을 기록, 상태는 그대로 | `입금 내역과 결제금액을 기록했습니다. 이미 결제 이후 단계인 주문이라 주문 상태는 그대로 유지됩니다.` |

> 운영자가 주문 목록에서 상태를 먼저 올려 둔 뒤 입금을 기록하는 경우다. 종전에는 결제 기록만 입금완료가 되고 주문 결제금액이 0 으로 남아 환불 예정액이 0원으로 계산됐고, `true` 로 보내면 상품준비중 주문이 결제완료로 되돌아갔다(실측 2026-10-03).

> 종전에는 두 경우 모두 「결제완료 처리되었습니다」로 응답해, **결제완료로 전이하지 않은 건에도 전이했다고 알렸습니다.** 운영자가 실제 상태를 오해할 수 있어 분기했습니다.

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. 입금확인 후 재조회한 주문을 `OrderResource` 로 반환하므로 필드 구성은 `GET /admin/orders/{order}` (주문 상세) 의 응답 필드 표와 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| order_number | string | `20260706-1405449337` | 주문번호 |
| order_status | string | `payment_complete` | 입금확인 반영된 주문상태 (`mark_order_complete` 지정 시 주문완료 상태) |
| order_status_label | string | `결제완료` | `order_status` 값의 사람이 읽는 라벨 |
| total_paid_amount | integer | `184000` | 입금 반영된 총 실제 결제금액 |
| total_due_amount | integer | `0` | 입금 반영 후 남은 결제예정금액 |
| depositor_name | string | `홍길동` | 확인된 무통장 입금자명 |
| paid_at | string | `2026-07-11T02:10:00+00:00` | 결제완료 일시 (입금확인 시점 기록) |
| payments | array | `[{"payment_status":"paid",…}]` | 결제 이력 (OrderPaymentResource — 무통장 결제건이 `paid` 로 전이) |
| abilities | object | `{"can_read":true,"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

> 나머지 금액/일시/다중통화 필드는 `GET /api/modules/sirsoft-ecommerce/admin/orders/{order}` 의 응답 필드 표를 참조하세요 (동일 `OrderResource`).

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "입금이 확인되어 결제완료 처리되었습니다.",
    "data": {
        "id": 1,
        "order_number": "20260706-1405449337",
        "order_status": "payment_complete",
        "order_status_label": "결제완료",
        "total_amount": 184000,
        "total_paid_amount": 184000,
        "total_due_amount": 0,
        "depositor_name": "홍길동",
        "paid_at": "2026-07-11T02:10:00+00:00",
        "payments": [],
        "abilities": {
            "can_read": true,
            "can_update": true
        }
    }
}
```

> `data` 는 주문 상세와 동일한 `OrderResource` 전체 구조입니다 (위 예시는 입금확인 관련 필드만 발췌).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패, 입금액 불일치(`입금액이 결제예정금액과 일치하지 않습니다.` + `errors.detail`), 또는 입금확인 처리 실패 — 무통장 결제가 아닌 주문·이미 결제완료된 주문 등 (`입금확인 처리에 실패했습니다.` + `errors.detail`) |
| 500 | Internal Server Error | 서버 내부 오류 (`작업 처리 중 오류가 발생했습니다.`) |

<!-- @generated:end -->

**설명** 관리자가 무통장(dbank) 미결제 주문(`order`)의 입금을 확인해 결제완료로 전이합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@confirmDeposit`가 `OrderProcessingService::confirmManualDeposit()`으로 입금자명·입금액을 기록하고 결제완료 처리합니다. 입금액(`amount`)이 결제예정금액과 정확히 일치하지 않으면 422(deposit_amount_mismatch)를 반환하며, `mark_order_complete`로 결제완료와 동시에 주문완료 처리 여부를 지정할 수 있습니다.


### POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/estimate-refund
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.estimate-refund -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.estimate-refund`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@estimateRefund`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.create`
- **권한 메모(1.3.0)**: 취소·환불 접수 창 전용이라 접수(`cancel-refund`)와 같은 `sirsoft-ecommerce.claims.create` 로 판정한다(종전 `orders.update`).

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| items | body | array | 예 | min 1 | 처리 대상 항목 배열 |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 (pg_first PG 우선 / points_first 포인트 우선, 기본 pg_first) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/estimate-refund HTTP/1.1
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

_단건 응답: `data` 객체의 필드 (`AdjustmentResult::toPreviewArray()` 반환 배열)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| refund_amount | number | `31000` | PG 환불 예상금액 (음수면 추가결제 필요) |
| refund_points_amount | number | `0` | 마일리지(포인트) 환불 예상금액 |
| original_paid_amount | number | `184000` | 재계산 전 원 결제금액 |
| recalculated_paid_amount | number | `153000` | 취소 반영 후 재계산된 결제금액 |
| shipping_difference | number | `0` | 배송비 차이 (양수 환불 / 음수 추가결제) |
| discount_difference | number | `0` | 할인 차이 (양수: 할인 감소분) |
| additional_payment_amount | number | `0` | 추가결제 필요 금액 (환불액이 음수일 때의 절댓값, 없으면 0) |
| cancelled_items | array | `[{"order_option_id":1,"cancel_quantity":1,"cancel_amount":31000}]` | 취소 대상 아이템별 취소 수량·취소 금액 |
| refund_priority | string | `pg_first` | 적용된 환불 배분 우선순위 (RefundPriorityEnum — `pg_first` / `points_first`) |
| remaining_pg_balance | number | `153000` | 환불 후 잔여 PG 결제 잔액 |
| remaining_points_balance | number | `0` | 환불 후 잔여 포인트 잔액 |
| refund_total | number | `31000` | 총 환불 예상금액 (PG 환불액 + 포인트 환불액) |
| refund_formatted | object | `{"refund_total":"31,000원", …}` | 환불 총액·잔액의 base 통화 포맷 문자열 + 결제 통화 병기 (취소 모달 표기 SSoT) |
| restored_coupons | array | `[{"coupon_name":"첫구매 무료배송","discount_amount":0}]` | 취소로 복원되는 쿠폰 정보 |
| shipping_details | array | `[{"policy_name":"기본 배송정책","base_difference":0,"extra_difference":0,"total_difference":0}]` | 배송정책별 배송비 차액 상세 |
| mc_refund_amount | object \| null | `{"KRW":{"amount":31000,"formatted":"31,000원"}, …}` | PG 환불금액 다중 통화 |
| mc_refund_points_amount | object \| null | `{"KRW":{"amount":0,"formatted":"0원"}, …}` | 포인트 환불금액 다중 통화 |
| mc_refund_shipping_amount | object \| null | `{"KRW":{"amount":0,"formatted":"0원"}, …}` | 배송비 환불금액 다중 통화 |
| original_snapshot | object | `{"total_paid_amount":184000,"total_points_used_amount":0, …}` | 재계산 전 주문 금액 스냅샷 |
| recalculated_snapshot | object | `{"total_paid_amount":153000, …}` | 재계산 후 주문 금액 스냅샷 |
| mc_original_snapshot | object \| null | `{"mc_subtotal_amount":{…},"mc_total_paid_amount":{…}}` | 원 주문 다중 통화 스냅샷 |
| mc_recalculated_snapshot | object \| null | `{"mc_subtotal_amount":{…},"mc_total_paid_amount":{…}}` | 재계산 다중 통화 스냅샷 |
| original_coupons | array | `[{"name":"첫구매 무료배송","target_type":"shipping_fee","discount_amount":0}]` | 원 주문에 적용된 쿠폰 상세 |
| recalculated_coupons | array | `[]` | 재계산 후 유지되는 쿠폰 상세 (조건 미달 시 소멸) |
| substituted_items | array | `[]` | 교환 미리보기의 옵션 치환 근거 행 — 취소 경로에서는 항상 빈 배열 (형태는 [claims/preview](#post-apimodulessirsoft-ecommerceuserordersidclaimspreview) 참조) |
| cancel_blocked | boolean | `false` | 취소 차단 여부 (부분취소로 추가결제가 필요해지는 실결제 주문이면 `true`) |
| cancel_blocked_reason | string \| null | `null` | 차단 사유 문구 (차단이 아니면 `null`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "환불 예상금액을 조회했습니다.",
    "data": {
        "refund_amount": 31000,
        "refund_points_amount": 0,
        "original_paid_amount": 184000,
        "recalculated_paid_amount": 153000,
        "shipping_difference": 0,
        "discount_difference": 0,
        "additional_payment_amount": 0,
        "cancelled_items": [
            {
                "order_option_id": 1,
                "cancel_quantity": 1,
                "cancel_amount": 31000
            }
        ],
        "refund_priority": "pg_first",
        "remaining_pg_balance": 153000,
        "remaining_points_balance": 0,
        "refund_total": 31000,
        "refund_formatted": {},
        "restored_coupons": [],
        "shipping_details": [],
        "mc_refund_amount": {
            "KRW": {
                "amount": 31000,
                "formatted": "31,000원"
            }
        },
        "mc_refund_points_amount": null,
        "mc_refund_shipping_amount": null,
        "original_snapshot": {},
        "recalculated_snapshot": {},
        "mc_original_snapshot": null,
        "mc_recalculated_snapshot": null,
        "original_coupons": [],
        "recalculated_coupons": [],
        "cancel_blocked": false,
        "cancel_blocked_reason": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.create`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Server Error | 환불 예상금액 계산 중 오류 (`환불 예상금액 계산에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)의 선택 옵션(`items`) 취소 시 예상 환불 금액을 실제 취소 없이 미리 계산합니다. `auth:sanctum` + `sirsoft-ecommerce.claims.create` 권한이 필요하며, `Admin\OrderController@estimateRefund`가 `OrderCancellationService::previewRefund()`로 환불 예상값을 반환합니다. `refund_priority`에 따라 PG 우선/포인트 우선 환불 배분 결과가 달라집니다. 취소 화면에서 "환불 예정 금액"을 관리자에게 미리 보여주는 용도입니다.

교환·반품·환불 신청으로 **클레임 코스에 들어간 상품**이 `items` 에 섞이면 `422` 입니다 — 미리보기와 실행(`DELETE /admin/orders/{order}`)이 같은 가드를 쓰므로 "미리보기는 되는데 실행만 막히는" 비대칭이 없습니다. 사유는 `errors.detail` 에 실립니다(`claim_option_in_course` / `claim_quantity_locked` — 관리자 전용 면의 조치 근거).


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
GET /api/modules/sirsoft-ecommerce/admin/orders/{order}/logs?per_page=1&sort_order=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `27789` | 기본 키 (내부 식별자) |
| log_type | string | `admin` | 로그 분류 (`ActivityLogType` Enum 값 — 관리자/사용자/시스템 등 활동 주체 구분) |
| log_type_label | string | `관리자` | `log_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| loggable_type | string | `Modules\Sirsoft\Ecommerce\Models\Orde…` | 로그 대상 모델의 FQCN (다형 관계 타입 — 표시용 짧은 이름은 `loggable_type_display`) |
| loggable_type_display | string | `OrderOption` | `loggable_type` 의 표시용 짧은 이름 (네임스페이스를 제외한 클래스명) |
| loggable_id | integer | `1264` | loggable 식별자 (연관 리소스 참조) |
| action | string | `order_option.partial_cancel` | 수행된 활동의 식별 키 (`{대상}.{행위}` 형식 — 라벨은 `action_label`) |
| action_label | string | `부분 취소` | `action` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| localized_description | string | `주문 옵션 부분 취소 (옵션 ID: 1264)` | `description` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| description_key | string | `sirsoft-ecommerce::activity_log.descr…` | 설명 문구의 다국어 키 (`localized_description` 은 이 키를 현재 로케일로 해석한 값) |
| properties | object | `{"order_id":1316,"product_name":{"ko":"신제품 출시 예정 #21","en…` | 활동 시점의 부가 정보 (설명 문구의 치환 파라미터 및 참조 식별자 — 활동 종류마다 키가 다름) |
| changes | null | `null` | 단건 수정의 변경 내역 `[{field, old, new}, …]`. 일괄 수정이거나 변경 추적 대상이 아니면 `null` |
| bulk_changes | null | `null` | 일괄 수정의 변경 내역 `[{model_id, changes[]}, …]`. 단건 수정이면 `null` (`changes` 와 동시에 채워지지 않음) |
| has_changes | boolean | `false` | changes 여부 |
| actor_name | string | `시스템` | 행위를 수행한 주체(사용자/시스템)의 이름 |
| user | object | `{"name":"시스템"}` | 대상 사용자 정보 객체 (uuid/name/email 등 — user 관계 파생) |
| ip_address | string | `10.10.10.1` | 요청/행위가 발생한 IP 주소 |
| created_at | string | `2026-07-29 19:56:44` | 생성 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문 처리 이력을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 27789,
                "log_type": "admin",
                "log_type_label": "관리자",
                "loggable_type": "Modules\\Sirsoft\\Ecommerce\\Models\\OrderOption",
                "loggable_type_display": "OrderOption",
                "loggable_id": 1264,
                "action": "order_option.partial_cancel",
                "action_label": "부분 취소",
                "localized_description": "주문 옵션 부분 취소 (옵션 ID: 1264)",
                "description_key": "sirsoft-ecommerce::activity_log.description.order_option_partial_cancel",
                "properties": {
                    "order_id": 1316,
                    "product_name": {
                        "ko": "신제품 출시 예정 #21",
                        "en": "Coming Soon Product #21"
                    },
                    "quantity": 3
                },
                "changes": null,
                "bulk_changes": null,
                "has_changes": false,
                "actor_name": "시스템",
                "user": {
                    "name": "시스템"
                },
                "ip_address": "10.10.10.1",
                "created_at": "2026-07-29 19:56:44",
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_delete": true
                }
            },
            {
                "id": 27766,
                "log_type": "admin",
                "log_type_label": "관리자",
                "loggable_type": "Modules\\Sirsoft\\Ecommerce\\Models\\OrderOption",
                "loggable_type_display": "OrderOption",
                "loggable_id": 1263,
                "action": "order_option.confirm",
                "action_label": "구매 확정",
                "localized_description": "구매확인 (옵션 ID: 1263)",
                "description_key": "sirsoft-ecommerce::activity_log.description.order_option_confirm",
                "properties": {
                    "order_id": 1316
                },
                "changes": null,
                "bulk_changes": null,
                "has_changes": false,
                "actor_name": "시스템",
                "user": {
                    "name": "시스템"
                },
                "ip_address": "10.0.0.5",
                "created_at": "2026-07-29 09:06:44",
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_delete": true
                }
            },
            "... (총 25건 중 2건 표시)"
        ],
        "links": {
            "first": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs?page=1",
            "last": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs?page=6",
            "prev": null,
            "next": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs?page=2"
        },
        "meta": {
            "current_page": 1,
            "from": 1,
            "last_page": 6,
            "links": [
                {
                    "url": null,
                    "label": "pagination.previous",
                    "page": null,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs?page=1",
                    "label": "1",
                    "page": 1,
                    "active": true
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs?page=2",
                    "label": "2",
                    "page": 2,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs?page=3",
                    "label": "3",
                    "page": 3,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs?page=4",
                    "label": "4",
                    "page": 4,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs?page=5",
                    "label": "5",
                    "page": 5,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs?page=6",
                    "label": "6",
                    "page": 6,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs?page=2",
                    "label": "pagination.next",
                    "page": 2,
                    "active": false
                }
            ],
            "path": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/orders/1316/logs",
            "per_page": 25,
            "to": 25,
            "total": 126
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
| status | body | string | 예 | — | 일괄 전환할 옵션 상태 (OrderStatusEnum 값, 클레임 코스 상태 4종(`exchange_in_progress`, `return_in_progress`, `exchanged`, `returned`) 제외 · 옵션별 전이 규칙 검증) |
| carrier_id | body | integer | 아니오 | — | carrier 식별자 |
| tracking_number | body | string | 아니오 | max 50 | 송장(운송장)번호 (배송 관련 상태로 전환 시 carrier_id 와 함께 필수) |
| apply_stock_deduction | body | boolean | 아니오 | — | 수동 결제 처리 — 재고 차감 (판매 상태로 가는 전이에만 쓰임, 생략하면 켬 — Q13) |
| apply_mileage_deduction | body | boolean | 아니오 | — | 수동 결제 처리 — 사용 마일리지 차감 (생략하면 켬. 취소 주문을 되살리면 취소 때 돌려준 마일리지를 다시 뺀다 — Q14) |
| record_paid_amount | body | boolean | 아니오 | — | 수동 결제 처리 — 결제금액 기록 (생략하면 켬. 결제 전 주문은 결제예정금액을, 되살린 주문은 취소 때 돌려준 현금을 받은 것으로 기록한다) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/orders/{order}/options/bulk-status HTTP/1.1
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

_단건 응답: `data` 객체의 필드 (`OrderOptionService::bulkChangeStatusWithQuantity()` 반환 배열)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| changed_count | integer | `2` | 상태가 변경된 주문 옵션 건수 |
| split_count | integer | `1` | 부분 수량 전환으로 옵션이 분할된 건수 |
| results | array | `[{"order_option_id":1, …}]` | 옵션별 처리 결과 배열 (아래 하위 필드) |
| results[].order_option_id | integer | `1` | 상태를 변경한 원본 주문 옵션 ID |
| results[].split_order_option_id | integer \| null | `57` | 부분 수량 전환으로 새로 분할 생성된 옵션 ID (분할 없으면 `null`) |
| results[].merged_into_order_option_id | integer \| null | `null` | 동일 상태 기존 옵션으로 병합된 경우 그 대상 옵션 ID (병합 없으면 `null`) |
| results[].quantity_changed | integer | `1` | 이번에 상태 전환한 수량 |
| results[].is_full_quantity | boolean | `false` | 옵션의 전체 수량을 전환했는지 여부 (부분 전환이면 `false`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "2개 옵션의 상태가 변경되었습니다.",
    "data": {
        "changed_count": 2,
        "split_count": 1,
        "results": [
            {
                "order_option_id": 1,
                "split_order_option_id": 57,
                "merged_into_order_option_id": null,
                "quantity_changed": 1,
                "is_full_quantity": false
            },
            {
                "order_option_id": 2,
                "split_order_option_id": null,
                "merged_into_order_option_id": null,
                "quantity_changed": 3,
                "is_full_quantity": true
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패, 또는 옵션 상태 변경 실패 — 옵션 전이 규칙 위반, 취소 후 복원 시 재고 부족 등 (`옵션 상태 변경에 실패했습니다.`) |
| 422 | Unprocessable Entity | 대상 옵션이 교환·반품 진행 중(`exchange_in_progress` / `return_in_progress` / `exchanged` / `returned`)인 경우 — 화면 요청은 `items.N.status` 에 안내가 실리고, 검증을 거치지 않는 내부·훅 호출로 서비스 방어가 트립한 경우에는 같은 안내가 `message` 에 실린다 |
| 422 | Unprocessable Entity | 수동 결제 처리 — 회원 마일리지 잔액 부족(`exceptions.mileage.insufficient_for_payment_complete[_bulk]`, `errors.order_numbers` 에 모자란 주문번호 전부 · 일괄은 전체를 바꾸지 않음), 재고 부족(`exceptions.manual_paid.stock_insufficient`, `errors.items`), 되살릴 주문의 쿠폰을 다른 주문이 이미 씀(`exceptions.reactivation.coupon_unavailable[_bulk]`, `errors.order_numbers`·`errors.coupons`) |
| 428 | Identity Verification Required | 결제완료(`payment_complete`) 전이 시 본인인증(IDV) 정책이 enforce 이고 미인증인 경우 |
| 500 | Server Error | 옵션 상태 변경 중 예기치 못한 오류 (`옵션 상태 변경에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)의 여러 주문 옵션 상태를 수량 분할까지 지원해 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@bulkChangeOptionStatus`가 `status`를 `OrderStatusEnum`으로 변환한 뒤 `OrderOptionService::bulkChangeStatusWithQuantity()`로 처리합니다. 배송중으로 전환 시 `carrier_id`·`tracking_number`(택배사·송장번호)를 함께 넘길 수 있습니다. 한 옵션의 일부 수량만 상태 전환(부분 배송 등)하는 시나리오를 지원합니다. `items[].option_id`는 경로의 `{order}`에 속한 옵션이어야 합니다. 다른 주문의 옵션 ID를 포함하면 422(`items.N.option_id`)로 거절되며, 요청 전체가 반영되지 않습니다. 검증을 거치지 않는 내부·훅 호출 경로에서 같은 불일치가 감지되면 400으로 응답합니다.

교환·반품이 진행 중인 옵션은 이 엔드포인트로 상태를 바꿀 수 없습니다. 클레임 코스 상태는 `status` 로 **지정**할 수 없을 뿐 아니라 그 상태에서 **빠져나오는** 전이도 막힙니다 — 코스 상태의 전이 게이트는 클레임 반려·철회 시의 복귀를 위해 판매 상태로의 전이를 허용하지만, 그 허용은 교환·반품 서비스의 복귀 경로 전용입니다. 주문 화면에서 직접 빼내면 그 옵션은 이후 신청서의 복귀 대상에서 조용히 제외되어 신청서와 주문옵션이 어긋납니다. 해당 교환·반품 건을 먼저 처리(승인·반려·철회·완료)한 뒤 상태를 변경하세요.


### POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/options/bulk-status/manual-paid-preview
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.orders.options.bulk-status.manual-paid-preview -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.orders.options.bulk-status.manual-paid-preview`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController@optionBulkManualPaidPreview`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터** — `PATCH .../{order}/options/bulk-status` 와 같은 검증을 쓴다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 주문번호 |
| items | body | array | 예 | min 1 | `[{option_id, quantity}]` — 바꿀 상품 행과 수량 |
| status | body | string | 예 | — | 목표 상품 상태 |
| carrier_id | body | integer | 아니오 | — | 택배사 ID (실행과 같은 검증) |
| tracking_number | body | string | 아니오 | max 50 | 송장번호 (실행과 같은 검증) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/options/bulk-status/manual-paid-preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [{"option_id": 2007, "quantity": 1}],
    "status": "payment_complete"
}
```

**응답 필드** (`data` 내부) — 목록 미리보기(`POST .../orders/bulk/manual-paid-preview`)와 같은 형태이고 `orders` 가 이 주문 하나다. 수량에 따라 부모 주문의 파생 상태(혼합 → 가장 낮은 진행 상태)를 시뮬레이션해 결제 코스 진입 여부를 정한다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 내부·훅 호출에서 상품 행이 경로의 주문에 속하지 않음 |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 실행 요청과 같은 검증 실패, 교환·반품 진행 중 상품 |
| 500 | Server Error | 미리보기 계산 실패 (`결제 처리 항목을 확인하지 못했습니다.`) |

<!-- @generated:end -->

**설명** 주문상세 상품 일괄변경 확인 창이 열릴 때와 수량을 바꿀 때 부르는 미리보기입니다. DB 를 바꾸지 않습니다. 실행과 같은 전이 분류(`OrderOptionService::buildOptionTransition`)와 판정 메서드를 씁니다.

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
POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/reset-guest-lookup-password HTTP/1.1
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



_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`). 재설정한 평문 비밀번호는 응답/로그에 노출하지 않습니다._

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "비회원 조회 비밀번호가 재설정되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지), 또는 회원 주문(`user_id` 가 있는 주문)에 호출한 경우 (`비회원 주문만 조회 비밀번호를 재설정할 수 있습니다.`) |
| 500 | Server Error | 재설정 처리 중 예기치 못한 오류 (`비회원 조회 비밀번호 재설정에 실패했습니다.`) |

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
POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/send-email HTTP/1.1
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

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "이메일이 발송되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | 메일 채널이 꺼져 있거나 준비되지 않았거나 알림 정의 `order_custom_email` 의 메일 템플릿이 꺼져 있어 보낼 수 없는 경우 (`messages.orders.email_channel_disabled`, `error.errors` 없음) |
| 500 | Internal Server Error | 발송 중 예외 (`messages.orders.email_send_failed`, `error.errors.detail` 에 원인) |

<!-- @generated:end -->

**설명** 관리자가 특정 주문(`order`)에 대해 주문 관련 안내 이메일을 지정 주소(`email`)로 발송합니다. `auth:sanctum` + `sirsoft-ecommerce.orders.update` 권한이 필요하며, `Admin\OrderController@sendEmail`이 `OrderService::sendEmail()`로 관리자가 작성한 메시지(`message`)를 전송합니다. 주문 관련 개별 안내가 필요할 때 관리자가 상세 화면에서 수동으로 메일을 보내는 용도입니다. 1.3.0 부터 발송은 알림 정의 `order_custom_email`(이커머스 설정 > 알림에서 제목·본문 틀 편집)을 거치므로, 메일 채널 설정·발송 로그(`notification_logs`)를 따릅니다 — 채널을 끄면 422 로 거절되고 아무것도 보내지 않습니다. 회원 주문은 그 회원 계정으로, 비회원·다른 주소는 그 주소로 보내며, 성공했을 때만 훅 `sirsoft-ecommerce.order.after_send_email` 이 발화합니다(활동 로그).


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
POST /api/modules/sirsoft-ecommerce/orders/{orderNumber}/cancel-payment HTTP/1.1
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

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`). 주문 상태는 변경되지 않고 `order_payments` 에 취소 이력만 기록됩니다._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "결제가 취소되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `orderNumber` 에 해당하는 주문이 없거나 접근 권한이 없는 경우도 포함 (CancelPaymentRequest 의 주문 해석 실패) |

<!-- @generated:end -->

**설명** 회원/비회원이 PG 결제창을 닫았을 때 결제 취소 이력만 기록합니다.

**비회원 요청은 `X-Guest-Order-Token` 헤더가 필요합니다.** 이 경로는 `optional.sanctum` 으로 회원/비회원이 공유하므로 소유권 판정이 분기됩니다 — 회원 주문은 로그인 본인만, 비회원 주문(`user_id` 없음)은 비회원 주문 조회에서 발급받은 토큰이 그 주문번호와 일치할 때만 통과합니다. 토큰이 없거나 만료·위조·다른 주문의 것이면 정보 노출을 막기 위해 존재하지 않는 주문과 동일하게 `404` 를 반환합니다. 토큰 발급은 비회원 주문 조회 인증(`POST /api/modules/sirsoft-ecommerce/guest/orders/authenticate`)이 담당합니다.

`optional.sanctum`으로 회원/비회원 모두 접근하며, `Public\OrderController@cancelPayment`가 `OrderProcessingService::recordPaymentCancellation()`으로 주문 상태는 변경하지 않고 `order_payments`에 취소창 닫힘 이력(`cancel_code`·`cancel_message`)만 남깁니다. 결제 SDK가 사용자 취소 콜백을 받았을 때 프론트가 호출해 결제 시도 이력을 추적하는 용도입니다.


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
| with_items | query | boolean | 아니오 | 기본 `false` | 주문 아이템 전량을 포함할지. 기본값에서는 대표 아이템 1건과 전체 개수(`item_count`)만 내려갑니다. 주문마다 상품을 전부 나열하는 화면만 켜세요 |

**목록은 경량 표현입니다.**

기본 응답의 `items[]` 에는 대표 아이템 1건만 담기고, 전체 개수는 `item_count` 로 제공됩니다. 아이템이 없는 주문은 빈 배열입니다. 주문마다 상품을 전부 나열해야 하는 화면은 `with_items=1` 로 전량을 요청하세요 — 그렇지 않은 호출자까지 주문 수 × 아이템 수를 받지 않게 하려는 기본값입니다.

부분취소 뱃지(`is_partially_cancelled`)는 아이템 전량 없이도 정확합니다. 서버가 집계로 판정하므로 두 경로의 값이 같습니다.

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/orders?page=1&per_page=1&status=%EC%98%88%EC%8B%9C%EA%B0%92&with_items=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1345` | 기본 키 (내부 식별자) |
| order_number | string | `20260730-1436245243` | 주문번호 (사용자 노출용 고유 식별 코드) |
| status | string | `preparing` | 주문상태 값 (OrderStatusEnum value — 마이페이지용 status 별칭) |
| status_label | string | `상품준비중` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `info` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| status_icon | string\|null | `status-preparing` | 상태를 상징하는 **아이콘 의미 토큰** (`OrderStatusEnum::icon()`) — 목록 행 상태 뱃지가 라벨 앞에 아이콘을 함께 그린다 |
| is_partially_cancelled | boolean | `false` | partially cancelled 여부 |
| recipient_country_code | string | `KR` | 배송국가 코드 (ISO 3166-1 alpha-2, shippingAddress 로드 시) |
| recipient_country_name | object | `{"ko":"한국","en":"South Korea","ja":"大韓民国"}` | 배송국가 현지화명 (로케일별 국가명 맵) — 코어 국가 사전(ko·en)에 없는 언어는 상점 배송국가 설정(`shipping.available_countries[].name`)의 이름으로 채운다 |
| ordered_at | string | `2026-07-29T14:36:24+00:00` | ordered 일시 |
| ordered_at_formatted | string | `2026-07-29 23:36:24` | `ordered_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_amount | integer | `30000` | 최종 주문금액 (상품합계 − 할인 + 배송비) |
| total_amount_formatted | string | `¥30,000` | `total_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| mc_total_amount | object | `{"KRW":{"amount":30000,"formatted":"30,000원"},"USD":{"amo…` | 최종 주문금액 다중 통화 (주문 시점 스냅샷, 통화별 amount·formatted) |
| total_shipping_amount | integer | `0` | 총 배송비 |
| total_shipping_amount_formatted | string | `¥0` | `total_shipping_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| mc_total_shipping_amount | object | `{"KRW":{"amount":0,"formatted":"0원"},"USD":{"amount":0,"f…` | 총 배송비 다중 통화 (주문 시점 스냅샷, 통화별 amount·formatted) |
| total_points_used_amount | integer | `0` | 총 포인트(마일리지) 사용액 |
| total_points_used_amount_formatted | string | `¥0` | `total_points_used_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_earned_points_amount | integer | `300` | 총 적립 예정 포인트 |
| total_earned_points_amount_formatted | string | `¥300` | `total_earned_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| items | array | `[{"product_name":"USB 충전 케이블 #7","product_option_name":"2…` | 주문 품목 목록 (상품명·옵션명·썸네일·수량·단가/소계·추가옵션 요약) |
| statistics | object | `{"pending_payment":0,"payment_complete":20,"preparing":10,…}` | 상태별 주문 건수 (응답 최상위 `data.statistics`). `preparing` 은 상품준비중 + 배송준비완료 합산(`OrderStatusEnum::statisticsFilterGroups`) — `status` 필터로 `preparing` 을 보내면 같은 집합으로 확장된다 |
| status_summary | array | `[{"key":"pending_payment","label":"결제대기","icon":"status-payment-pending","variant":"warning","count":0}, …]` | 마이페이지 목록 상단 **건수 칸 배열** (응답 최상위 `data.status_summary`). 순서·키·건수는 `statistics` 와 같고(클릭 필터가 같은 키를 보낸다), 상태 Enum 의 아이콘 토큰·색·라벨이 붙는다. 합산 칸(`preparing`)의 라벨은 `enums.order_status_groups.preparing`("상품준비·배송준비") |
| item_count | integer | `1` | item 개수 (집계) |
| claim_summary | array | `[{"type":"exchange","type_label":"교환","count":1,"latest_c…` | 그 주문에 접수된 클레임의 유형별 최신 상태 배지 배열. 관리자 목록과 **같은 산출**(`SummarizesClaims`)이라 같은 주문이 두 화면에서 같은 상태로 보인다. 항상 직렬화되며 접수 건이 있는 유형만 항목으로 실리므로 클레임이 없는 주문은 빈 배열(`[]`)이다. 항목: `{type, type_label, count, latest_claim_number, status, status_label, status_variant}` |
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
                "id": 1345,
                "order_number": "20260730-1436245243",
                "status": "preparing",
                "status_label": "상품준비중",
                "status_variant": "info",
                "...": "(19개 키 생략, 총 24개)"
            },
            {
                "id": 1344,
                "order_number": "20260730-1436244391",
                "status": "preparing",
                "status_label": "상품준비중",
                "status_variant": "info",
                "...": "(19개 키 생략, 총 24개)"
            },
            "... (총 25건 중 2건 표시)"
        ],
        "statistics": {
            "pending_payment": 0,
            "payment_complete": 20,
            "preparing": 10,
            "shipping": 0,
            "delivered": 0,
            "...": "(1개 키 생략, 총 6개)"
        },
        "abilities": {
            "can_create": true
        },
        "pagination": {
            "current_page": 1,
            "last_page": 2,
            "per_page": 25,
            "total": 30
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
| orderer.name | body | string | 예 | max 50 | 대상의 이름/명칭 |
| orderer.phone | body | string | 예 | max 20 | 전화번호 |
| shipping.recipient_name | body | string | 예 | max 50 | shipping.recipient 이름 (식별자) |
| shipping.recipient_phone | body | string | 아니오 | max 20 | 수령인 연락처 |
| shipping.recipient_tel | body | string | 아니오 | max 20 | 수령인 일반전화 (`shipping.recipient_phone` 미입력 시 필수 — 둘 중 하나는 반드시 입력) |
| shipping.country_code | body | string | 아니오 | — | 국가 코드 (ISO 3166-1 alpha-2) |
| shipping.zipcode | body | string | 아니오 | max 10 | 우편번호 |
| shipping.address | body | string | 아니오 | max 255 | 기본 주소 |
| shipping.address_detail | body | string | 예 | max 255 | 상세 주소 |
| shipping.address_type_code | body | string | 아니오 | `R`, `J` | 국내 주소 표기 방식 (`R` 도로명 / `J` 지번) |
| shipping.address_line_1 | body | string | 아니오 | max 255 | 주소 1행 (기본 주소) |
| shipping.address_line_2 | body | string | 아니오 | max 255 | 주소 2행 (상세 주소) |
| shipping.intl_city | body | string | 아니오 | max 100 | 도시 (국제 주소) |
| shipping.intl_state | body | string | 아니오 | max 100 | 주/도 (국제 주소) |
| shipping.intl_postal_code | body | string | 아니오 | max 20 | 우편번호 (국제 주소) |
| payment_method | body | string | 예 | — | 결제수단. 코어 8종(`card`/`vbank`/`dbank`/`bank`/`phone`/`point`/`deposit`/`free`) 과 PG 플러그인이 등록한 확장 결제수단 ID(예: `nhnkcp_naverpay`, `kginicis_lpay`)를 모두 허용한다. 확장 결제수단도 1급 시민으로 그대로 저장된다. 카탈로그에 없는 값은 422. 결제 통화(회원 선호 통화 → `X-Currency` → 기본 통화)에서 고를 수 없는 수단도 422(`payment_method_not_allowed_for_currency`) — 판정은 필터 훅 `sirsoft-ecommerce.checkout.filter_payment_methods_for_currency` 이고 주문서 응답의 `allowed_payment_methods` 와 같다(#639) |
| expected_total_amount | body | number | 예 | min 0 | 프론트가 계산한 예상 결제금액 (서버 재계산값과 대조해 금액 위변조 검증) |
| shipping_memo | body | string | 아니오 | max 500 | 배송 요청사항 메모 |
| depositor_name | body | string | 아니오 | max 50 | depositor 이름 (식별자) |
| dbank.bank_code | body | string | 아니오 | max 10 | 수동 무통장입금 계좌의 은행코드 (`payment_method=dbank` 이면 필수) |
| dbank.bank_name | body | string | 아니오 | max 50 | 수동 무통장입금 계좌의 은행명 (표시용) |
| dbank.account_number | body | string | 아니오 | max 50 | 수동 무통장입금 입금 계좌번호 (`payment_method=dbank` 이면 필수) |
| dbank.account_holder | body | string | 아니오 | max 50 | 수동 무통장입금 계좌 예금주 (`payment_method=dbank` 이면 필수) |
| dbank.due_days | body | integer | 아니오 | min 1, max 30 | 입금 기한 일수 (주문일로부터 며칠 이내 입금, 1~30일) |
| save_shipping_address | body | boolean | 아니오 | — | 회원 주소록에 이번 배송지 저장 여부 (회원 주문 한정) |
| save_shipping_address_id | body | integer | 아니오 | 1 이상 | **갱신할 주소록 행 ID** (회원 주문 한정). 주문서에서 저장된 배송지를 고른 뒤 그 자리에서 주소를 고쳤을 때 싣습니다 — 새 행을 만들지 않고 그 행을 갱신합니다. 배송지명(`name`)은 유지됩니다. 소유자가 아닌 행 ID 면 아무 것도 바뀌지 않고, 대체로 새로 만들지도 않습니다 |
| cash_receipt_requested | body | boolean | 아니오 | — | 현금영수증 신청 여부 (true 면 아래 3개 필드가 필수) |
| cash_receipt_type | body | string | 아니오 | — | 발급 용도 (`income` 소득공제 / `expense` 지출증빙) |
| cash_receipt_identifier_type | body | string | 아니오 | — | 발급 수단 (`phone` 휴대폰번호 / `card` 현금영수증카드 / `business` 사업자등록번호 — business 는 지출증빙 전용) |
| cash_receipt_identifier | body | string | 아니오 | max 30 | 발급 식별번호 (하이픈 없는 숫자. 사업자등록번호는 체크섬 검증. 마스킹 저장 + 원본은 암호화 보관) |
| refund_bank.bank_code | body | string | 아니오 | max 10 | 환불 계좌 은행코드 (세 필드는 전부 입력하거나 전부 비워야 함) |
| refund_bank.account_number | body | string | 아니오 | max 50 | 환불 계좌번호 |
| refund_bank.holder | body | string | 아니오 | max 50 | 환불 계좌 예금주 |
| orderer.email | body | email | 예 | max 255 | 이메일 주소 |
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
    "orderer.name": "예시 이름",
    "orderer.phone": "010-1234-5678",
    "shipping.recipient_name": "예시 이름",
    "shipping.recipient_phone": "010-1234-5678",
    "shipping.recipient_tel": "예시값",
    "shipping.country_code": "KR",
    "shipping.zipcode": "06234",
    "shipping.address": "서울특별시 강남구 테헤란로 1",
    "shipping.address_detail": "서울특별시 강남구 테헤란로 1",
    "shipping.address_type_code": "R",
    "shipping.address_line_1": "서울특별시 강남구 테헤란로 1",
    "shipping.address_line_2": "서울특별시 강남구 테헤란로 1",
    "shipping.intl_city": "예시값",
    "shipping.intl_state": "예시값",
    "shipping.intl_postal_code": "06234",
    "payment_method": "예시값",
    "expected_total_amount": 1,
    "shipping_memo": "예시값",
    "depositor_name": "예시 이름",
    "dbank.bank_code": "예시값",
    "dbank.bank_name": "예시 이름",
    "dbank.account_number": "예시값",
    "dbank.account_holder": "예시값",
    "dbank.due_days": 1,
    "save_shipping_address": true,
    "cash_receipt_requested": true,
    "cash_receipt_type": "예시값",
    "cash_receipt_identifier_type": "example-key",
    "cash_receipt_identifier": "example-key",
    "refund_bank.bank_code": "예시값",
    "refund_bank.account_number": "예시값",
    "refund_bank.holder": "예시값",
    "orderer.email": "user@example.com",
    "guest_lookup_password": "Password123!",
    "guest_lookup_password_confirmation": "Password123!"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (HTTP 201). 회원은 `order` 에 `OrderResource`, 비회원은 민감 필드를 가린 `GuestOrderResource` 가 담깁니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| order | object | `{"id":181,"order_number":"20260711-0210001234", …}` | 생성된 주문 (회원: `OrderResource` / 비회원: `GuestOrderResource`) |
| redirect_url | string | `/shop/orders/20260711-0210001234/complete` | 주문완료 페이지 경로 (프론트가 이동할 URL). 앞의 상점 경로는 상점 주소 설정을 반영한다 — `basic_info.route_path` 를 바꾸면 그 값이(`/store/orders/…`), `basic_info.no_route` 를 켜면 세그먼트 없이(`/orders/…`) 내려간다 |
| requires_pg_payment | boolean | `true` | PG 결제창 호출이 필요한지 여부 (무통장·전액 마일리지 등 non-PG 는 `false`) |
| pg_provider | string | `sirsoft-tosspayments` | PG 플러그인 식별자 (`requires_pg_payment=true` 일 때만 포함) |
| pg_payment_handler | string | `sirsoft-tosspayments.requestPayment` | 프론트가 dispatch 할 결제 진입 핸들러 풀네임 (provider 가 선언한 경우에만 포함) |
| pg_payment_data | object | `{"order_number":"…","amount":184000,"currency":"KRW", …}` | PG SDK 결제창 호출 파라미터 (`requires_pg_payment=true` 일 때만 포함 — 아래 표 참조) |
| guest_order_token | string | `1759999999\|6f1c…` | **비회원 주문에만** 포함되는 비회원 조회 토큰(30분 유효, `X-Guest-Order-Token` 헤더 값). 결제창 닫힘 보고(PG 플러그인 close-report)·재결제 준비가 이 토큰으로 주문 소유자를 확인한다. 회원 주문에는 포함되지 않는다 |
| guest_order_token_expires_at | string | `2026-09-28T15:30:00+09:00` | `guest_order_token` 의 만료시각 (ISO 8601, 비회원 주문에만 포함) |

`pg_payment_data` 하위 필드 (프로바이더 비의존 공통):

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| order_number | string | `20260711-0210001234` | 주문번호 (PG 주문 식별자) |
| order_name | string | `코튼 후드티 #12 외 2건` | 결제창에 표시할 주문명 (첫 상품명 + 외 N건) |
| amount | integer | `184000` | PG 청구금액 — 결제 통화의 최소 화폐단위 정수 (KRW 원 / USD 센트) |
| currency | string | `KRW` | PG 청구 통화 (주문 스냅샷 환율로 환산된 결제 통화) |
| customer_name | string | `유정우` | 주문자명 (배송지에서 조회) |
| customer_email | string | `ji792@mail.test` | 주문자 이메일 |
| customer_phone | string | `01055144949` | 주문자 휴대전화 (숫자만) |
| customer_key | string \| null | `user_12` | 회원 식별 키 (비회원은 `null`) |
| escrow_products | array | `[{"id":12,"name":"코튼 후드티 #12","code":"YD1JTVLJEMKAUTKS","unitPrice":31000,"quantity":1}]` | 에스크로 결제(가상계좌·계좌이체)용 상품 상세 (`unitPrice` 는 개당가, 비에스크로는 무시) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "주문이 완료되었습니다.",
    "data": {
        "order": {
            "id": 181,
            "order_number": "20260711-0210001234",
            "order_status": "pending_payment",
            "total_amount": 184000,
            "total_due_amount": 184000
        },
        "redirect_url": "/shop/orders/20260711-0210001234/complete",
        "requires_pg_payment": true,
        "pg_provider": "sirsoft-tosspayments",
        "pg_payment_handler": "sirsoft-tosspayments.requestPayment",
        "pg_payment_data": {
            "order_number": "20260711-0210001234",
            "order_name": "코튼 후드티 #12 외 2건",
            "amount": 184000,
            "currency": "KRW",
            "customer_name": "유정우",
            "customer_email": "ji792@mail.test",
            "customer_phone": "01055144949",
            "customer_key": "user_12",
            "escrow_products": [
                {
                    "id": 12,
                    "name": "코튼 후드티 #12",
                    "code": "YD1JTVLJEMKAUTKS",
                    "unitPrice": 31000,
                    "quantity": 1
                }
            ]
        }
    }
}
```

> `order` 는 주문 상세와 동일한 `OrderResource`(회원) / `GuestOrderResource`(비회원) 전체 구조입니다 (위 예시는 주요 필드만 발췌).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-orders.create`)이 없는 경우 |
| 404 | Not Found | 임시 주문(주문서)이 없거나 만료된 경우 (`주문서를 찾을 수 없습니다.` 계열 — `exceptions.temp_order_not_found`) |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패, 예상 결제금액 불일치(`expected_total_amount` ≠ 서버 재계산값), 결제 통화 미지원(`errors.code = unsupported_payment_currency`), 재고 부족(`errors.insufficient_items`), 구매 불가 상품(`errors.code = cart_unavailable`), 주문 확정 재계산 검증 실패(쿠폰 만료·최소주문금액 미달 등 — `errors.code = order_calculation_validation_failed`). `payment_method` 가 결제수단 카탈로그에 없는 값이면 여기서 차단된다. 필드별 메시지는 `error.errors` 에 담긴다 |
| 409 | Conflict | 적용한 쿠폰을 다른 주문이 먼저 사용한 경우 (`errors.code = coupon_already_used`, `errors.coupon_issue_id` 에 해당 발급 ID). 주문은 생성되지 않고 쿠폰도 소모되지 않으므로 그대로 재시도할 수 있습니다 |
| 428 | Identity Verification Required | 결제 진입 본인인증(IDV) 정책이 활성이고 미인증(grace 만료)인 경우. 응답 payload 로 인증 수단·목적이 함께 내려간다 |
| 500 | Server Error | 주문 생성 중 예기치 못한 오류 (`주문 생성에 실패했습니다.` — `messages.order.create_failed`). 원인은 서버 로그 `Order create: unexpected failure` 항목에 예외 클래스·메시지·발생 위치가 기록된다 |

<!-- @generated:end -->

**설명** 주문서 작성을 마치고 실제 주문을 생성(결제하기)하는 회원/비회원 공용 엔드포인트입니다. PG 플러그인의 fetch 인터셉터가 이 한 경로만 매칭하므로 회원/비회원이 동일 URL로 진입하고, `Public\OrderController@store`가 `Auth::id()`로 분기합니다(회원은 `OrderResource`, 비회원은 민감 필드를 가린 `GuestOrderResource`). `optional.sanctum` + `sirsoft-ecommerce.user-orders.create` 권한이 필요하며, `expected_total_amount`로 금액 위변조를 검증하고 비회원은 `guest_lookup_password`로 이후 조회 비밀번호를 설정합니다. 회원이 `save_shipping_address`를 켜면 배송지가 자동 저장(PG 결제는 결제완료 시점) 됩니다. 비회원 주문이면 응답에 그 주문의 조회 토큰(`guest_order_token`, 30분)을 함께 내려줍니다 — 주문을 실제로 만든 브라우저만 받는 값이라, 결제창 닫힘 보고처럼 결제 도중 주문 상태를 바꾸는 요청의 소유 증명으로 쓰입니다. 주문자 이메일·전화번호는 제3자도 알 수 있어 소유 증명이 되지 못합니다 (KVE-2026-2434). 토큰 발급이 실패해도 주문 생성은 성공하며, 그 경우 닫힘 보고는 거부되고 결제대기 주문은 만료 정리 잡이 정리합니다. `save_shipping_address_id` 를 함께 실으면 새로 만드는 대신 그 주소록 행을 갱신합니다.

주문 확정 시점에는 재고·구매대상제한·배송국가·쿠폰 유효성과 함께 **마일리지 사용 정책**도 현재 설정 기준으로 재검증합니다. 임시 주문을 만든 뒤 관리자가 한도를 강화했거나 임시 주문이 조작된 경우 `422`(`errors.code = mileage_usage_not_allowed`)로 차단되며 주문은 생성되지 않습니다. 반대로 정상 생성된 주문에는 그 시점의 사용 정책이 `mileage_policy_snapshot` 으로 고정되어, 이후 설정이 바뀌어도 해당 주문의 판정 근거를 재현할 수 있습니다(통화·프로모션·배송정책 스냅샷과 동일 취지).

**`pg_payment_data` 응답 필드 (PG 결제 주문 한정)** — 결제수단이 PG(카드·가상계좌·계좌이체 등)인 주문은 응답에 `pg_payment_handler`(프론트가 호출할 결제 핸들러 식별자)와 `pg_payment_data` 객체가 함께 내려갑니다. `pg_payment_data`는 PG SDK 결제창 호출에 필요한 값(`order_number`, `order_name`, `amount`, `currency`, `success_url`, `fail_url`, `customer_email`, `customer_phone`, `customer_key` 등)을 담습니다. 이 객체는 결제수단·PG에 따라 동적으로 조립되므로 자동 실측 문서화 대상이 아닙니다. 다음 필드는 프로바이더 비의존적으로 항상 포함됩니다:

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| `escrow_products` | array | 에스크로 결제(가상계좌·계좌이체)에서 필수인 상품 상세 배열. 각 원소는 `{id, name, code, unitPrice, quantity}` 형식이며 `unitPrice`는 개당가(합계 아님), `name`은 현재 로케일로 로컬라이즈됩니다. 에스크로 사용 여부는 PG 프론트에서 결정하므로 항상 조립되어 내려가고, 비에스크로 결제는 이 필드를 무시합니다. |

가상계좌 주문에는 `vbank_due_at`(string, ISO 8601 · 한국 시각 `+09:00`)이 더 실립니다 — 주문이 정한 입금 기한(설정 `order_settings.auto_cancel_days`, 주문서 「입금 기한: N일 이내」와 같은 값)입니다. 결제 플러그인은 이 값을 결제사의 계좌 만료 파라미터(KG이니시스 `acceptmethod` 의 `vbank(YYYYMMDDhhmm)`·모바일 `P_VBANK_DT`/`P_VBANK_TM`, 나이스페이먼츠 `VbankExpDate`, NHN KCP `vcnt_expire_term`/`vcnt_expire_term_time`)로 넘겨, 계좌 기한·주문서 안내·자동취소 시점이 결제사와 무관하게 같게 합니다. 가상계좌가 아니면 키가 없습니다.


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
GET /api/modules/sirsoft-ecommerce/user/orders/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `4` | 기본 키 (내부 식별자) |
| order_number | string | `APIDOC-20260708-1005224318` | 주문번호 |
| base_currency | string | `KRW` | 금액 표기 기준 통화 (모든 *_formatted 필드의 통화, 주문 시점 base_currency 고정) |
| payment_currency | string | `KRW` | 결제 통화 (유저가 선택·결제한 통화, base_currency 와 다르면 병기 표시) |
| is_cross_currency | boolean | `false` | cross currency 여부 |
| order_status | string | `pending_payment` | 주문상태 (OrderStatusEnum) |
| order_status_label | string | `결제대기` | `order_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| order_status_variant | string | `warning` | `order_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| order_status_icon | string\|null | `status-paid` | 상태를 상징하는 **아이콘 의미 토큰** (아이콘 라이브러리 중립 — 어휘는 [admin-claims.md](admin-claims.md#progress_steps-항목) "아이콘 의미 토큰 어휘" 참조). `OrderStatusEnum::icon()` 단일 출처이며, 토큰을 글리프로 바꾸는 표는 템플릿의 Icon 컴포넌트가 가진다 |
| progress_steps | array | `[{"value":"pending_payment","label":"결제대기","state":"done","icon":"status-payment-pending","variant":"warning"}, …]` | **진행단계 배열** — 관리자·회원·비회원 상세가 같은 배열(`OrderProgressSteps` 단일 출처)로 스텝퍼를 그린다. 순서는 `OrderStatusEnum::progressSequence()` 7단계(결제대기 → 결제완료 → 상품준비중 → 배송준비완료 → 배송중 → 배송완료 → 구매확정). 항목 `state` 는 `done`/`current`/`upcoming`/`off_path` — 배송보류·주문취소·교환/반품 진행·교환/반품 완료는 순서 밖이라 마지막에 `off_path` 칸 하나로 붙고 그때 순서 안에 `current` 는 없다. 도달 지점은 현재 상태와 `paid_at`·상품 `delivered_at`·`confirmed_at` 중 더 나아간 쪽. `icon`·`variant` 는 각 상태 Enum 의 `icon()`/`variant()` |
| is_partially_cancelled | boolean | `false` | partially cancelled 여부 |
| is_past_payment | boolean | `true` | 결제 이후 단계(결제완료 ~ 구매확정)인지 여부. 결제 전 상태와 주문취소는 `false`. 판정은 `OrderStatusEnum::isPastPayment()` — 관리자 입금확인 창이 「결제완료로 처리」 체크와 「상태는 그대로 두고 입금만 기록」 안내를 이 값으로 가른다 |
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
| total_due_charge_amount | integer | `327327` | **결제 통화(`payment_currency`) 기준 실청구액**, 최소 화폐단위 정수. base≠결제 통화면 `total_due_amount` 와 다릅니다 |
| total_due_charge_amount_formatted | string | `327,327원` | `total_due_charge_amount` 를 결제 통화 기호로 표기한 문자열 |
| depositor_name | null | `null` | 무통장 입금자명 (입금확인 모달 기본값, payment 관계 로드 시에만 노출) |
| total_cancelled_amount | integer | `0` | 총 취소금액 |
| total_cancelled_amount_formatted | string | `0원` | `total_cancelled_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_amount | integer | `0` | 총 환불금액 |
| total_refunded_amount_formatted | string | `0원` | `total_refunded_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_points_amount | integer | `0` | 총 환불 포인트 |
| total_refunded_points_amount_formatted | string | `0원` | `total_refunded_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_mileage_credit_amount | number | `0` | 현금 환불 중 「마일리지로 환불」로 회원 마일리지에 지급한 합계(`total_refunded_amount` 에 포함된 그중 내역). 결제수단이 마일리지인 주문은 0 |
| total_refunded_mileage_credit_amount_formatted | string | `0원` | `total_refunded_mileage_credit_amount` 의 주문 시점 통화 표기 |
| claim_additional_paid_total | number | `0` | 이 주문의 클레임(교환·반품) 추가결제 실수납 합계 — 결제 완료 건의 청구액에서 부분 환급을 뺀 값. 주문 총 배송비와 분리해 표기하며 0원이면 화면에 노출하지 않는다 |
| claim_additional_paid_total_formatted | string | `0원` | `claim_additional_paid_total` 값의 주문 시점 통화 표기 문자열 |
| mc_claim_additional_paid_total | array | `[]` | 다통화 표기 맵 (통화 코드 => `{amount, formatted}`). 다통화 기록이 없으면 빈 배열 |
| total_product_coupon_discount_amount | integer | `0` | 상품 쿠폰 할인 합계 |
| total_product_coupon_discount_amount_formatted | string | `0원` | `total_product_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_order_coupon_discount_amount | integer | `0` | 주문 쿠폰 할인 합계 |
| total_order_coupon_discount_amount_formatted | string | `0원` | `total_order_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_coupon_discount_amount | integer | `0` | 총 쿠폰 할인금액 |
| total_coupon_discount_amount_formatted | string | `0원` | `total_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_code_discount_amount | integer | `0` | 총 할인코드 할인금액 |
| total_code_discount_amount_formatted | string | `0원` | `total_code_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_points_used_amount | integer | `0` | 총 포인트 사용액 |
| points_not_deducted | boolean | `false` | 사용 마일리지가 있는데 회원 잔액에서 차감되지 않은 주문인지(수동 결제완료에서 「마일리지 차감」을 끈 경우 등) — 참이면 환불 때 마일리지 몫이 0 이다 |
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
| total_quantity | integer | `0` | 남은 상품 수량 합계 — 취소 행·교환완료로 닫힌 원 행·교환 진행 중인 후속 행을 뺀 회계 주체 행만 센다 (options 로드 시) |
| total_list_price | integer | `0` | 정가 합계 (옵션 스냅샷 정가 × 수량) — `total_quantity` 와 같은 행만 센다 |
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
| shipping_policy_applied_snapshot | object | `{"items": [], "address": {}}` | 적용된 배송정책 스냅샷 (재계산용). `items` 는 옵션별 적용 정책 목록(각 항목: `product_option_id`, `policy`), `address` 는 주문 시점 배송지 메타(`country_code`, `zipcode`). 항목이 없어도 `items` 는 빈 배열이다 |
| admin_memo | null | `null` | 관리자 메모 — 고객 응답에서는 항상 `null` (키는 호환용으로 유지, 값은 관리자 주문 API 에서만 제공) |
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
        "id": 1,
        "order_number": "20260706-1405449337",
        "order_status": "payment_complete",
        "order_status_label": "결제완료",
        "total_amount": 184000,
        "total_amount_formatted": "184,000원",
        "options": [],
        "shipping_address": {},
        "payments": [],
        "shippings": [],
        "cancels": [],
        "abilities": {
            "can_read": true,
            "can_cancel": true
        }
    }
}
```

> `data` 는 주문 상세와 동일한 `OrderResource` 전체 구조입니다 (위 예시는 주요 필드만 발췌).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | 주문이 없거나 본인 주문이 아닌 경우 (정보 노출 방지를 위해 권한 없음도 404 — `주문을 찾을 수 없습니다.`) |

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
POST /api/modules/sirsoft-ecommerce/user/orders/{id}/cancel HTTP/1.1
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

_단건 응답: `data` 객체의 필드. 취소 처리 후 재조회한 주문을 `OrderResource` 로 반환하므로 필드 구성은 주문 상세와 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| order_number | string | `20260706-1405449337` | 주문번호 |
| order_status | string | `cancelled` | 취소 반영된 주문상태 (전체취소 시 `cancelled`) |
| is_partially_cancelled | boolean | `false` | 부분취소 여부 (`items` 지정 시 `true`) |
| total_cancelled_amount | integer | `184000` | 총 취소금액 |
| total_refunded_amount | integer | `184000` | 총 환불금액 |
| cancelled_at | string | `2026-07-11T02:10:00+00:00` | 취소 일시 |
| cancels | array | `[{"reason":"change_of_mind", …}]` | 취소 이력 (OrderCancelResource) |
| options | array | `[{"option_status":"cancelled", …}]` | 주문 옵션 목록 (취소된 옵션 반영) |
| abilities | object | `{"can_read":true,"can_cancel":false}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

> 나머지 금액/일시/다중통화 필드는 `GET /api/modules/sirsoft-ecommerce/admin/orders/{order}` 의 응답 필드 표를 참조하세요 (동일 `OrderResource`).

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문이 취소되었습니다.",
    "data": {
        "id": 1,
        "order_number": "20260706-1405449337",
        "order_status": "cancelled",
        "order_status_label": "취소완료",
        "is_partially_cancelled": false,
        "total_cancelled_amount": 184000,
        "total_refunded_amount": 184000,
        "cancelled_at": "2026-07-11T02:10:00+00:00",
        "cancels": [],
        "options": [],
        "abilities": {
            "can_read": true,
            "can_cancel": false
        }
    }
}
```

> `data` 는 주문 상세와 동일한 `OrderResource` 전체 구조입니다 (위 예시는 취소 관련 필드만 발췌).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-orders.cancel`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패(본인 주문 아님·취소 불가 상태 포함), 또는 취소 도메인 규칙 위반 (`exceptions.order_cancel_failed`) |
| 500 | Internal Server Error | 서버 내부 오류 (`exceptions.operation_failed`) |

<!-- @generated:end -->

**설명** 회원이 마이페이지에서 본인 주문(`id`)을 취소합니다. `auth:sanctum` + `sirsoft-ecommerce.user-orders.cancel` 권한이 필요하며, `User\OrderController@cancel`이 `items` 유무에 따라 `OrderCancellationService`의 `cancelOrderOptions()`(부분) 또는 `cancelOrder()`(전체)를 호출합니다. 두 메서드는 **클레임 접수 경로**(`OrderRefundRequestService::request()`)로 흐르는 어댑터입니다 — 돈이 수납된 주문은 환불신청서를 거치고, 취소신청서는 환불이 집행된 순간에만 생깁니다. 취소자(`cancelledBy`)로 회원 본인 ID가 기록되고, `refund_priority`로 PG/포인트 환불 우선순위를 지정합니다. 취소 가능 상태의 주문만 취소되며, 취소 후 갱신된 주문을 `OrderResource`로 반환합니다.


### GET /api/modules/sirsoft-ecommerce/user/orders/{id}/cash-receipt
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.cash-receipt.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.cash-receipt.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\CashReceiptController@show`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/orders/{id}/cash-receipt HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| issuable | boolean | 지금 발급이 가능한지 여부 (무통장 + 입금완료 + 미발급 + 현금성 금액 > 0 + 프로바이더 설정됨) |
| cash_receipt | object\|null | 현재 활성 영수증 1건 (`CashReceiptResource`). 발급 전이거나 전액 취소된 경우 `null` |

`cash_receipt` 의 하위 필드 구성은 발급 API(`POST admin/orders/{order}/cash-receipt`)의 응답 필드 표와 동일합니다.

**응답 예시**

발급 전 (발급 가능):

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "현금영수증 정보를 조회했습니다.",
    "data": {
        "issuable": true,
        "cash_receipt": null
    }
}
```

발급 완료:

```json
{
    "success": true,
    "message": "현금영수증 정보를 조회했습니다.",
    "data": {
        "issuable": false,
        "cash_receipt": {
            "id": 12,
            "provider": "sirsoft-pay_tosspayments",
            "transaction_type": "issue",
            "receipt_type": "income",
            "receipt_type_label": "소득공제용",
            "amount": 12000,
            "amount_formatted": "12,000원",
            "tax_free_amount": 0,
            "tax_free_amount_formatted": "0원",
            "identifier_masked": "010****5678",
            "receipt_url": "https://dashboard.tosspayments.com/receipt/cash/...",
            "issue_number": "CR20260710000012",
            "issue_status": "COMPLETED",
            "error_code": null,
            "error_message": null,
            "issued_at": "2026-07-10T14:32:11+09:00",
            "issued_at_formatted": "2026-07-10 14:32"
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

**설명** 회원이 마이페이지 주문상세에서 본인 주문(`id`)의 현금영수증 발급 상태를 조회합니다. `auth:sanctum` 인증이 필요하며, `User\CashReceiptController@show`가 클라이언트가 넘긴 사용자 식별자를 신뢰하지 않고 `Auth::id()` 로만 소유권을 판정합니다 — 타인 주문이면 404 를 반환합니다(존재 여부를 노출하지 않기 위해 403 이 아닌 404).

응답의 `data.issuable` 은 지금 발급이 가능한지 여부(무통장 + 입금완료 + 미발급 + 현금성 금액 > 0 + 프로바이더 설정됨)이고, `data.cash_receipt` 는 현재 활성 영수증 또는 발급 전이면 `null` 입니다. 주문상세 화면이 이 두 값으로 **[현금영수증 발급] 버튼**과 **[영수증 보기] 링크** 중 무엇을 보일지 결정합니다.


### POST /api/modules/sirsoft-ecommerce/user/orders/{id}/cash-receipt
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.cash-receipt.issue -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.cash-receipt.issue`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\CashReceiptController@issue`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| receipt_type | body | string | 예 | `income`, `expense` | 발급 용도 (income 소득공제용 — 개인 연말정산 / expense 지출증빙용 — 사업자 매입세액공제) |
| identifier_type | body | string | 예 | `phone`, `card`, `business` | 발급 수단 (phone 휴대폰번호 / card 현금영수증카드번호 / business 사업자등록번호 — 사업자등록번호는 지출증빙 전용) |
| identifier | body | string | 예 | max 30 | 식별번호 (하이픈·공백 제거 후 검증 — 휴대폰 10~11자리 / 현금영수증카드 13~19자리 / 사업자등록번호 10자리 체크섬) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders/{id}/cash-receipt HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "receipt_type": "income",
    "identifier_type": "phone",
    "identifier": "example-key"
}
```

**응답 필드** (`data` 내부)

`data` 는 발급 이력 1건(`CashReceiptResource`)이며, 필드 구성은 관리자 발급 API(`POST admin/orders/{order}/cash-receipt`)의 응답 필드 표와 동일합니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "현금영수증이 발급되었습니다.",
    "data": {
        "id": 12,
        "provider": "sirsoft-pay_tosspayments",
        "transaction_type": "issue",
        "receipt_type": "income",
        "receipt_type_label": "소득공제용",
        "amount": 12000,
        "amount_formatted": "12,000원",
        "tax_free_amount": 0,
        "tax_free_amount_formatted": "0원",
        "identifier_masked": "010****5678",
        "receipt_url": "https://dashboard.tosspayments.com/receipt/cash/...",
        "issue_number": "CR20260710000012",
        "issue_status": "COMPLETED",
        "error_code": null,
        "error_message": null,
        "issued_at": "2026-07-10T14:32:11+09:00",
        "issued_at_formatted": "2026-07-10 14:32"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 회원이 주문 당시 신청하지 않은 현금영수증을 마이페이지 주문상세에서 **직접 사후 발급**합니다. `auth:sanctum` 인증이 필요하며, `User\CashReceiptController@issue`가 `Auth::id()` 로 소유권을 확인한 뒤(타인 주문이면 404) 관리자 발급과 **동일한 검증·가드·프로바이더 위임**을 거칩니다.

요청 본문(`receipt_type` / `identifier_type` / `identifier`)과 오류 코드 체계는 관리자 발급 API 와 같습니다 — 이미 발급된 주문은 409(`ALREADY_ISSUED`), 그 외 발급 불가 사유는 422 에 `errors.error_code` 로 구분해 담깁니다.

**발급 취소는 제공하지 않습니다** — 국세청 신고 정정을 동반하므로 관리자 전용이며, 유저용 `DELETE` 라우트 자체를 노출하지 않습니다(403 이 아니라 404/405).


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
POST /api/modules/sirsoft-ecommerce/user/orders/{id}/estimate-refund HTTP/1.1
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

_단건 응답: `data` 객체의 필드 (`AdjustmentResult::toPreviewArray()` 반환 배열 — 관리자 `POST /admin/orders/{order}/estimate-refund` 와 동일 구조)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| refund_amount | number | `31000` | PG 환불 예상금액 (음수면 추가결제 필요) |
| refund_points_amount | number | `0` | 마일리지(포인트) 환불 예상금액 |
| original_paid_amount | number | `184000` | 재계산 전 원 결제금액 |
| recalculated_paid_amount | number | `153000` | 취소 반영 후 재계산된 결제금액 |
| shipping_difference | number | `0` | 배송비 차이 (양수 환불 / 음수 추가결제) |
| discount_difference | number | `0` | 할인 차이 (양수: 할인 감소분) |
| additional_payment_amount | number | `0` | 추가결제 필요 금액 (없으면 0) |
| cancelled_items | array | `[{"order_option_id":1,"cancel_quantity":1,"cancel_amount":31000}]` | 취소 대상 아이템별 취소 수량·금액 |
| refund_priority | string | `pg_first` | 적용된 환불 배분 우선순위 |
| remaining_pg_balance | number | `153000` | 환불 후 잔여 PG 결제 잔액 |
| remaining_points_balance | number | `0` | 환불 후 잔여 포인트 잔액 |
| refund_total | number | `31000` | 총 환불 예상금액 (PG + 포인트) |
| refund_formatted | object | `{"refund_total":"31,000원", …}` | 환불 금액의 통화 포맷 문자열 (취소 모달 표기) |
| restored_coupons | array | `[]` | 취소로 복원되는 쿠폰 정보 |
| shipping_details | array | `[]` | 배송정책별 배송비 차액 상세 |
| mc_refund_amount / mc_refund_points_amount / mc_refund_shipping_amount | object \| null | `{"KRW":{"amount":31000,"formatted":"31,000원"}}` | 환불 금액 다중 통화 |
| original_snapshot / recalculated_snapshot | object | `{"total_paid_amount":184000, …}` | 재계산 전/후 금액 스냅샷 |
| mc_original_snapshot / mc_recalculated_snapshot | object \| null | `{"mc_total_paid_amount":{…}}` | 다중 통화 스냅샷 |
| original_coupons / recalculated_coupons | array | `[]` | 재계산 전/후 쿠폰 적용 상세 |
| substituted_items | array | `[]` | 교환 미리보기의 옵션 치환 근거 행 — 취소 경로에서는 항상 빈 배열 |
| cancel_blocked | boolean | `false` | 취소 차단 여부 (부분취소로 추가결제가 필요해지는 실결제 주문이면 `true`) |
| cancel_blocked_reason | string \| null | `null` | 차단 사유 문구 (차단이 아니면 `null`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "환불 예상금액을 조회했습니다.",
    "data": {
        "refund_amount": 31000,
        "refund_points_amount": 0,
        "original_paid_amount": 184000,
        "recalculated_paid_amount": 153000,
        "shipping_difference": 0,
        "discount_difference": 0,
        "additional_payment_amount": 0,
        "cancelled_items": [
            {
                "order_option_id": 1,
                "cancel_quantity": 1,
                "cancel_amount": 31000
            }
        ],
        "refund_priority": "pg_first",
        "remaining_pg_balance": 153000,
        "remaining_points_balance": 0,
        "refund_total": 31000,
        "refund_formatted": {},
        "restored_coupons": [],
        "shipping_details": [],
        "mc_refund_amount": null,
        "mc_refund_points_amount": null,
        "mc_refund_shipping_amount": null,
        "original_snapshot": {},
        "recalculated_snapshot": {},
        "mc_original_snapshot": null,
        "mc_recalculated_snapshot": null,
        "original_coupons": [],
        "recalculated_coupons": [],
        "cancel_blocked": false,
        "cancel_blocked_reason": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-orders.cancel`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (본인 주문 아님·취소 불가 옵션 포함) |
| 500 | Server Error | 환불 예상금액 계산 중 오류 (`exceptions.order_estimate_refund_failed`) |

<!-- @generated:end -->

**유저 화면 소비처 없음** — 마이페이지의 옛 주문 취소 모달이 삭제되어(sirsoft-basic 1.2.0) 이 라우트를 부르는 유저 화면은 없다. 취소·환불 신청은 [claims/preview](#post-apimodulessirsoft-ecommerceuserordersidclaimspreview)(`type=refund`)가 같은 `AdjustmentResult` 를 `adjustment` 로 싣는다. 관리자 주문상세의 취소 모달만 관리자 `estimate-refund` 를 쓴다. 라우트·요청 검증·응답 형태는 **API 계약으로 유지**한다.

**설명** 회원이 마이페이지에서 본인 주문(`id`)의 선택 옵션(`items`) 취소 시 예상 환불 금액을 실제 취소 없이 미리 계산합니다. `auth:sanctum` + `sirsoft-ecommerce.user-orders.cancel` 권한이 필요하며, `User\OrderController@estimateRefund`가 `OrderCancellationService::previewRefund()`로 환불 예상값을 반환합니다. `refund_priority`에 따라 PG 우선/포인트 우선 환불 배분 결과가 달라집니다. 취소 확정 전 "환불 예정 금액"을 회원에게 안내하는 용도입니다.

교환·반품·환불 신청으로 **클레임 코스에 들어간 상품**이 `items` 에 섞이면 `422` 이며, `message` 에 그 사유가 그대로 실립니다(`claim_option_in_course` / `claim_quantity_locked` — 관리자 면과 같은 키). 미리보기가 성공하면 그 조합은 취소 실행도 가능하다는 뜻입니다.


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
POST /api/modules/sirsoft-ecommerce/user/orders/{id}/options/{optionId}/confirm HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| order | object | `{"id":1,"order_status":"confirmed","options":[…], …}` | 구매확정 반영 후 재조회한 주문 (`OrderResource` — 필드 구성은 주문 상세와 동일). 확정된 옵션의 `option_status` 가 `confirmed` 로 전이하며, 전 옵션 확정 시 주문상태도 `confirmed` 가 됩니다 |

> `order` 의 세부 필드는 `GET /api/modules/sirsoft-ecommerce/admin/orders/{order}` 의 응답 필드 표를 참조하세요 (동일 `OrderResource`).

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "구매확정이 완료되었습니다.",
    "data": {
        "order": {
            "id": 1,
            "order_number": "20260706-1405449337",
            "order_status": "confirmed",
            "order_status_label": "구매확정",
            "confirmed_at": "2026-07-11T02:10:00+00:00",
            "options": [
                {
                    "id": 1,
                    "option_status": "confirmed",
                    "option_status_label": "구매확정"
                }
            ]
        }
    }
}
```

> `order` 는 주문 상세와 동일한 `OrderResource` 전체 구조입니다 (위 예시는 구매확정 관련 필드만 발췌).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-orders.confirm`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 500 | Internal Server Error | 구매확정 처리 실패 (`exceptions.operation_failed`) |

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
POST /api/modules/sirsoft-ecommerce/user/orders/{id}/reorder HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`CartService::reorderFromOrder()` 반환 배열)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| added_count | integer | `2` | 장바구니에 실제로 담긴 옵션 건수 |
| skipped | array | `[{"product_name":"코튼 후드티 #12","reason":"재고가 부족합니다."}]` | 품절·단종 등으로 담지 못한 항목 목록 (`product_name` + `reason`) |
| skipped[].product_name | string | `코튼 후드티 #12` | 담지 못한 상품명 (현재 로케일로 로컬라이즈) |
| skipped[].reason | string | `재고가 부족합니다.` | 담지 못한 사유 (재고 부족·옵션 미존재 등) |
| cart_count | integer | `5` | 재주문 반영 후 현재 장바구니의 총 아이템 수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "과거 주문의 상품을 장바구니에 추가했습니다.",
    "data": {
        "added_count": 2,
        "skipped": [
            {
                "product_name": "코튼 후드티 #12",
                "reason": "재고가 부족합니다."
            }
        ],
        "cart_count": 5
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 재주문 도메인 규칙 위반 — 장바구니 담기 불가·수량 한도 초과 등 (`재주문에 실패했습니다.`) |
| 500 | Internal Server Error | 서버 내부 오류 (`exceptions.operation_failed`) |

<!-- @generated:end -->

**설명** 회원이 과거 주문(`id`)의 옵션들을 현재 장바구니에 다시 담는 재주문 기능입니다. `auth:sanctum` 인증이 필요하며, `User\OrderController@reorder`가 `CartService::reorderFromOrder()`로 처리해 담긴 수량(`added_count`), 담지 못한 항목(`skipped[]`), 현재 장바구니 총 개수(`cart_count`)를 반환합니다. 취소된 주문도 재주문 대상이 되며, 품절·단종·상품별 구매수량 한도 초과 등으로 추가 불가한 항목은 건너뛰어 `skipped` 배열로 안내합니다. 한 항목이 담기지 못해도 나머지 항목은 그대로 담기며, 응답은 200 입니다(항목 하나 때문에 재주문 전체를 실패시키지 않습니다). 마이페이지 주문내역의 "재주문" 버튼에 사용합니다.


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
PUT /api/modules/sirsoft-ecommerce/user/orders/{id}/shipping-address HTTP/1.1
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
| order | object | `{"id":1,"shipping_address":{…},"recipient_name":"심채원", …}` | 배송지 변경 반영 후 주문 (`OrderResource` — 필드 구성은 주문 상세와 동일). `shipping_address` 및 플래튼된 수령인/주소 필드에 변경 내용이 반영됩니다 |

> `order` 의 세부 필드는 `GET /api/modules/sirsoft-ecommerce/admin/orders/{order}` 의 응답 필드 표를 참조하세요 (동일 `OrderResource`).

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
            "id": 1,
            "order_number": "20260706-1405449337",
            "recipient_name": "심채원",
            "recipient_phone": "010-3955-6018",
            "recipient_zipcode": "38022",
            "recipient_address": "부산광역시 양천구 공항대로 9",
            "recipient_detail_address": "101동 202호",
            "delivery_memo": "parcel_box",
            "shipping_address": {}
        }
    }
}
```

> `order` 는 주문 상세와 동일한 `OrderResource` 전체 구조입니다 (위 예시는 배송지 관련 필드만 발췌).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | 주문이 없거나 본인 주문이 아닌 경우 (`주문을 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패, 또는 배송지 변경 불가 상태 — 이미 배송이 시작된 주문 등 (`배송 전 상태에서만 배송지를 변경할 수 있습니다.`) |
| 500 | Internal Server Error | 서버 내부 오류 (`배송지 변경 처리 중 오류가 발생했습니다.`) |

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
GET /api/modules/sirsoft-ecommerce/user/orders/{orderNumber} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `4` | 기본 키 (내부 식별자) |
| order_number | string | `APIDOC-20260708-1005224318` | 주문번호 |
| base_currency | string | `KRW` | 금액 표기 기준 통화 (모든 *_formatted 필드의 통화, 주문 시점 base_currency 고정) |
| payment_currency | string | `KRW` | 결제 통화 (유저가 선택·결제한 통화, base_currency 와 다르면 병기 표시) |
| is_cross_currency | boolean | `false` | cross currency 여부 |
| order_status | string | `pending_payment` | 주문상태 (OrderStatusEnum) |
| order_status_label | string | `결제대기` | `order_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| order_status_variant | string | `warning` | `order_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| order_status_icon | string\|null | `status-paid` | 상태를 상징하는 **아이콘 의미 토큰** (아이콘 라이브러리 중립 — 어휘는 [admin-claims.md](admin-claims.md#progress_steps-항목) "아이콘 의미 토큰 어휘" 참조). `OrderStatusEnum::icon()` 단일 출처이며, 토큰을 글리프로 바꾸는 표는 템플릿의 Icon 컴포넌트가 가진다 |
| progress_steps | array | `[{"value":"pending_payment","label":"결제대기","state":"done","icon":"status-payment-pending","variant":"warning"}, …]` | **진행단계 배열** — 관리자·회원·비회원 상세가 같은 배열(`OrderProgressSteps` 단일 출처)로 스텝퍼를 그린다. 순서는 `OrderStatusEnum::progressSequence()` 7단계(결제대기 → 결제완료 → 상품준비중 → 배송준비완료 → 배송중 → 배송완료 → 구매확정). 항목 `state` 는 `done`/`current`/`upcoming`/`off_path` — 배송보류·주문취소·교환/반품 진행·교환/반품 완료는 순서 밖이라 마지막에 `off_path` 칸 하나로 붙고 그때 순서 안에 `current` 는 없다. 도달 지점은 현재 상태와 `paid_at`·상품 `delivered_at`·`confirmed_at` 중 더 나아간 쪽. `icon`·`variant` 는 각 상태 Enum 의 `icon()`/`variant()` |
| is_partially_cancelled | boolean | `false` | partially cancelled 여부 |
| is_past_payment | boolean | `true` | 결제 이후 단계(결제완료 ~ 구매확정)인지 여부. 결제 전 상태와 주문취소는 `false`. 판정은 `OrderStatusEnum::isPastPayment()` — 관리자 입금확인 창이 「결제완료로 처리」 체크와 「상태는 그대로 두고 입금만 기록」 안내를 이 값으로 가른다 |
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
| total_due_charge_amount | integer | `327327` | **결제 통화(`payment_currency`) 기준 실청구액**, 최소 화폐단위 정수. base≠결제 통화면 `total_due_amount` 와 다릅니다 |
| total_due_charge_amount_formatted | string | `327,327원` | `total_due_charge_amount` 를 결제 통화 기호로 표기한 문자열 |
| depositor_name | null | `null` | 무통장 입금자명 (입금확인 모달 기본값, payment 관계 로드 시에만 노출) |
| total_cancelled_amount | integer | `0` | 총 취소금액 |
| total_cancelled_amount_formatted | string | `0원` | `total_cancelled_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_amount | integer | `0` | 총 환불금액 |
| total_refunded_amount_formatted | string | `0원` | `total_refunded_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_points_amount | integer | `0` | 총 환불 포인트 |
| total_refunded_points_amount_formatted | string | `0원` | `total_refunded_points_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_refunded_mileage_credit_amount | number | `0` | 현금 환불 중 「마일리지로 환불」로 회원 마일리지에 지급한 합계(`total_refunded_amount` 에 포함된 그중 내역). 결제수단이 마일리지인 주문은 0 |
| total_refunded_mileage_credit_amount_formatted | string | `0원` | `total_refunded_mileage_credit_amount` 의 주문 시점 통화 표기 |
| claim_additional_paid_total | number | `0` | 이 주문의 클레임(교환·반품) 추가결제 실수납 합계 — 결제 완료 건의 청구액에서 부분 환급을 뺀 값. 주문 총 배송비와 분리해 표기하며 0원이면 화면에 노출하지 않는다 |
| claim_additional_paid_total_formatted | string | `0원` | `claim_additional_paid_total` 값의 주문 시점 통화 표기 문자열 |
| mc_claim_additional_paid_total | array | `[]` | 다통화 표기 맵 (통화 코드 => `{amount, formatted}`). 다통화 기록이 없으면 빈 배열 |
| total_product_coupon_discount_amount | integer | `0` | 상품 쿠폰 할인 합계 |
| total_product_coupon_discount_amount_formatted | string | `0원` | `total_product_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_order_coupon_discount_amount | integer | `0` | 주문 쿠폰 할인 합계 |
| total_order_coupon_discount_amount_formatted | string | `0원` | `total_order_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_coupon_discount_amount | integer | `0` | 총 쿠폰 할인금액 |
| total_coupon_discount_amount_formatted | string | `0원` | `total_coupon_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_code_discount_amount | integer | `0` | 총 할인코드 할인금액 |
| total_code_discount_amount_formatted | string | `0원` | `total_code_discount_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| total_points_used_amount | integer | `0` | 총 포인트 사용액 |
| points_not_deducted | boolean | `false` | 사용 마일리지가 있는데 회원 잔액에서 차감되지 않은 주문인지(수동 결제완료에서 「마일리지 차감」을 끈 경우 등) — 참이면 환불 때 마일리지 몫이 0 이다 |
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
| total_quantity | integer | `0` | 남은 상품 수량 합계 — 취소 행·교환완료로 닫힌 원 행·교환 진행 중인 후속 행을 뺀 회계 주체 행만 센다 (options 로드 시) |
| total_list_price | integer | `0` | 정가 합계 (옵션 스냅샷 정가 × 수량) — `total_quantity` 와 같은 행만 센다 |
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
| shipping_policy_applied_snapshot | object | `{"items": [], "address": {}}` | 적용된 배송정책 스냅샷 (재계산용). `items` 는 옵션별 적용 정책 목록(각 항목: `product_option_id`, `policy`), `address` 는 주문 시점 배송지 메타(`country_code`, `zipcode`). 항목이 없어도 `items` 는 빈 배열이다 |
| admin_memo | null | `null` | 관리자 메모 — 고객 응답에서는 항상 `null` (키는 호환용으로 유지, 값은 관리자 주문 API 에서만 제공) |
| customer_memo | null | `null` | 고객 메모 (주문 시 고객이 남긴 메모) |
| created_at | string | `2026-07-08T01:44:49+00:00` | 생성 일시 |
| updated_at | string | `2026-07-08T01:44:49+00:00` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_update":true,"can_cancel":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

비회원 응답(`GuestOrderResource`) 필드 — 회원 응답에 있는 `id`·`user`·`admin_memo`·`promotions_applied_snapshot` 등 민감/내부 필드는 포함되지 않습니다:

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| order_number | string | `20260706-1405449337` | 주문번호 (비회원 식별 기준 — 내부 `id` 는 미노출) |
| order_status | string | `payment_complete` | 주문상태 (OrderStatusEnum) |
| order_status_label | string | `결제완료` | `order_status` 의 사람이 읽는 라벨 |
| order_status_variant | string | `info` | `order_status` 의 표시 변형 키 (UI 배지 색상) |
| order_status_icon | string\|null | `status-paid` | 상태를 상징하는 **아이콘 의미 토큰** (아이콘 라이브러리 중립 — 어휘는 [admin-claims.md](admin-claims.md#progress_steps-항목) "아이콘 의미 토큰 어휘" 참조). `OrderStatusEnum::icon()` 단일 출처이며, 토큰을 글리프로 바꾸는 표는 템플릿의 Icon 컴포넌트가 가진다 |
| progress_steps | array | `[{"value":"pending_payment","label":"결제대기","state":"done","icon":"status-payment-pending","variant":"warning"}, …]` | **진행단계 배열** — 관리자·회원·비회원 상세가 같은 배열(`OrderProgressSteps` 단일 출처)로 스텝퍼를 그린다. 순서는 `OrderStatusEnum::progressSequence()` 7단계(결제대기 → 결제완료 → 상품준비중 → 배송준비완료 → 배송중 → 배송완료 → 구매확정). 항목 `state` 는 `done`/`current`/`upcoming`/`off_path` — 배송보류·주문취소·교환/반품 진행·교환/반품 완료는 순서 밖이라 마지막에 `off_path` 칸 하나로 붙고 그때 순서 안에 `current` 는 없다. 도달 지점은 현재 상태와 `paid_at`·상품 `delivered_at`·`confirmed_at` 중 더 나아간 쪽. `icon`·`variant` 는 각 상태 Enum 의 `icon()`/`variant()` |
| is_partially_cancelled | boolean | `false` | 부분취소 여부 (options 로드 시) |
| subtotal_amount / subtotal_amount_formatted | integer / string | `184000` / `184,000원` | 상품 합계 (할인 전) |
| total_discount_amount / total_discount_amount_formatted | integer / string | `0` / `0원` | 총 할인금액 |
| total_shipping_amount / total_shipping_amount_formatted | integer / string | `0` / `0원` | 총 배송비 |
| total_amount / total_amount_formatted | integer / string | `184000` / `184,000원` | 최종 주문금액 |
| total_paid_amount / total_paid_amount_formatted | integer / string | `184000` / `184,000원` | 총 실제 결제금액 |
| total_cancelled_amount / total_cancelled_amount_formatted | integer / string | `0` / `0원` | 총 취소금액 |
| total_refunded_amount / total_refunded_amount_formatted | integer / string | `0` / `0원` | 총 환불금액 |
| total_refunded_points_amount / _formatted | integer / string | `0` / `0원` | 총 환불 포인트 |
| total_points_used_amount / _formatted | integer / string | `0` / `0원` | 총 포인트 사용액 |
| points_not_deducted | boolean | `false` | 사용 마일리지가 있는데 회원 잔액에서 차감되지 않은 주문인지(수동 결제완료에서 「마일리지 차감」을 끈 경우 등) — 참이면 환불 때 마일리지 몫이 0 이다 |
| total_deposit_used_amount / _formatted | integer / string | `0` / `0원` | 총 예치금 사용액 |
| total_earned_points_amount / _formatted | integer / string | `1840` / `1,840원` | 총 적립 예정 포인트 |
| mc_subtotal_amount / mc_total_discount_amount / mc_total_shipping_amount / mc_total_amount / mc_total_points_used_amount / mc_total_deposit_used_amount | object | `{"KRW":{"amount":184000,"formatted":"184,000원"}, …}` | 각 금액의 다중 통화 표기 |
| item_count | integer | `3` | 주문 품목 수 |
| total_quantity | integer | `4` | 남은 상품 수량 합계 — 취소 행·교환완료로 닫힌 원 행·교환 진행 중인 후속 행을 뺀 회계 주체 행만 센다 (options 로드 시) |
| ordered_at / ordered_at_formatted | string | `2026-07-05T14:05:44+00:00` / `2026-07-05 23:05:44` | 주문 일시 |
| paid_at / paid_at_formatted | string \| null | `2026-07-06T14:05:44+00:00` / `2026-07-06 23:05:44` | 결제 일시 |
| confirmed_at / confirmed_at_formatted | string \| null | `null` | 구매확정 일시 |
| cancelled_at / cancelled_at_formatted | string \| null | `null` | 취소 일시 |
| orderer_name / orderer_phone / orderer_email | string | `유정우` / `010-5514-4949` / `ji792@mail.test` | 주문자 정보 (배송지에서 플래튼) |
| recipient_name / recipient_phone | string | `심채원` / `010-3955-6018` | 수령인 정보 |
| recipient_zipcode / recipient_address / recipient_detail_address | string | `38022` / `부산광역시 양천구 공항대로 9` / `101동 202호` | 수령인 주소 |
| delivery_memo / delivery_memo_label | string \| null | `parcel_box` / `택배함에 넣어주세요` | 배송 메모 및 라벨 |
| options | array | `[{"id":1,"option_status":"payment_complete", …}]` | 주문 옵션 목록 (OrderOptionResource) |
| shipping_address | object | `{"recipient_name":"심채원", …}` | 배송지 상세 (OrderAddressResource) |
| payment | object \| null | `{"payment_method":"dbank", …}` | 대표 결제 정보 (OrderPaymentResource) |
| shippings | array | `[]` | 배송 이력 (OrderShippingResource) |
| cancels | array | `[]` | 취소 이력 (OrderCancelResource) |
| abilities | object | `{"can_cancel":true}` | 비회원이 이 주문에 수행 가능한 작업 (취소 가능 상태 여부) |

**응답 예시** (비회원 — `GuestOrderResource`)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문 정보를 조회했습니다.",
    "data": {
        "order_number": "20260706-1405449337",
        "order_status": "payment_complete",
        "order_status_label": "결제완료",
        "order_status_variant": "info",
        "is_partially_cancelled": false,
        "subtotal_amount": 184000,
        "subtotal_amount_formatted": "184,000원",
        "total_discount_amount": 0,
        "total_discount_amount_formatted": "0원",
        "total_shipping_amount": 0,
        "total_shipping_amount_formatted": "0원",
        "total_amount": 184000,
        "total_amount_formatted": "184,000원",
        "total_paid_amount": 184000,
        "total_paid_amount_formatted": "184,000원",
        "item_count": 3,
        "total_quantity": 4,
        "ordered_at": "2026-07-05T14:05:44+00:00",
        "ordered_at_formatted": "2026-07-05 23:05:44",
        "paid_at": "2026-07-06T14:05:44+00:00",
        "paid_at_formatted": "2026-07-06 23:05:44",
        "orderer_name": "유정우",
        "orderer_phone": "010-5514-4949",
        "orderer_email": "ji792@mail.test",
        "recipient_name": "심채원",
        "recipient_phone": "010-3955-6018",
        "recipient_zipcode": "38022",
        "recipient_address": "부산광역시 양천구 공항대로 9",
        "recipient_detail_address": "101동 202호",
        "delivery_memo": "parcel_box",
        "delivery_memo_label": "택배함에 넣어주세요",
        "options": [],
        "shipping_address": {},
        "payment": null,
        "shippings": [],
        "cancels": [],
        "abilities": {
            "can_cancel": true
        }
    }
}
```

> 로그인 상태의 본인 주문이면 `data` 는 주문 상세와 동일한 `OrderResource` 전체 구조로 내려갑니다 (`GET /api/modules/sirsoft-ecommerce/admin/orders/{order}` 의 응답 필드 표 참조).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 주문이 없거나 접근 권한이 없는 경우 — 회원: 본인 주문 아님(`errors.redirect_to = /mypage/orders`), 비회원: `X-Guest-Order-Token` 부재·만료·위조(`errors.redirect_to = /shop/guest/orders` — 상점 주소 설정 `basic_info.route_path`/`no_route` 반영). 정보 노출 방지를 위해 모든 실패를 동일 404 로 처리 |

<!-- @generated:end -->

**설명** 주문번호(`orderNumber`)로 주문 상세를 조회하는 회원/비회원 공용 엔드포인트입니다. `optional.sanctum`으로 로그인 여부에 따라 분기하는데, 로그인 상태면 본인 회원 주문만 `OrderResource`로 반환하고 아니면 404(마이페이지 주문 목록으로 안내), 비로그인이면 `X-Guest-Order-Token`으로 비회원 주문을 매칭해 `GuestOrderResource`로 반환하고 실패 시 404(비회원 조회 폼으로 안내)합니다. 회원이 비회원 토큰을 들고 와도 회원 분기가 우선하며, 실패 사유는 모두 동일한 404로 처리해 정보 노출을 차단합니다. 결제 완료 후 주문번호 기반 주문 완료/상세 페이지에서 사용합니다.

결제 정보(`payment`)의 날짜는 두 벌로 온다 — `vbank_due_at` 은 기계가 읽는 ISO8601 원시 값이고, `vbank_due_at_formatted`·`due_date_formatted` 는 사용자 언어·시간대로 서식을 입힌 표시용 값이다. 화면(템플릿·PG 플러그인 주입 스크립트)은 표시용 값을 그대로 쓰고 원시 값에 다시 서식을 입히지 않는다 — 입히면 모든 언어 화면에 그 플러그인이 정한 한 가지 서식·시간대가 나온다. 번들 PG 플러그인(KG이니시스·NHN KCP·나이스페이먼츠)은 모두 표시용 값만 쓰며, 원시 값은 기계가 읽는 API 소비자(외부 연동)용이다.



---

## 클레임 신청 (주문 단위)

<a id="클레임-신청-주문-단위"></a>

주문 하나를 기준으로 교환/반품/취소·환불을 신청하는 회원 경로입니다. 응답 리소스의 필드 표(`ClaimResource` / `ClaimItemResource` / `ClaimPaymentResource` / `ClaimImageResource`)는 [claims.md](claims.md#claim-resource) 에 있고, 신청 뒤의 목록·상세·수정·철회는 [claims.md](claims.md), 추가결제는 [claim-payments.md](claim-payments.md), 비회원 경로는 [guest.md](guest.md#비회원-클레임) 에 있습니다.

| 메서드 | URI | 라우트명 |
| --- | --- | --- |
| GET | /api/modules/sirsoft-ecommerce/user/orders/{id}/claimable-options | `api.modules.sirsoft-ecommerce.user.orders.claimable-options` |
| GET | /api/modules/sirsoft-ecommerce/user/orders/{id}/claims | `api.modules.sirsoft-ecommerce.user.orders.claims.index` |
| POST | /api/modules/sirsoft-ecommerce/user/orders/{id}/claims/preview | `api.modules.sirsoft-ecommerce.user.orders.claims.preview` |
| POST | /api/modules/sirsoft-ecommerce/user/orders/{id}/exchanges | `api.modules.sirsoft-ecommerce.user.orders.exchanges.store` |
| POST | /api/modules/sirsoft-ecommerce/user/orders/{id}/returns | `api.modules.sirsoft-ecommerce.user.orders.returns.store` |
| POST | /api/modules/sirsoft-ecommerce/user/orders/{id}/refund-requests | `api.modules.sirsoft-ecommerce.user.orders.refund-requests.store` |
| POST | /api/modules/sirsoft-ecommerce/user/orders/{id}/claims/images | `api.modules.sirsoft-ecommerce.user.orders.claim-images.store` |

공통 규칙:

- `{id}` 는 주문 ID 이며 본인 주문이 아니면 존재 여부를 드러내지 않고 404 입니다.
- 신청 대상 `items[]` 는 `order_option_id` + `quantity` 이고, 같은 옵션을 두 줄로 보내면 422 입니다. 수량은 진행 중인 다른 클레임이 잡아 둔 수량을 뺀 잔여(`available_quantity`) 이내여야 합니다(`claim_quantity_locked`).
- 도메인 규칙 위반(신청 불가 상태·기간 경과·수량 잠금·사유 사용 불가·청구 방식 불허 등)은 422 로, 메시지는 해당 사유의 다국어 문구입니다. 그 밖의 서버 오류는 500 이며 예외 원문은 응답에 실리지 않습니다.

---

### GET /api/modules/sirsoft-ecommerce/user/orders/{id}/claimable-options
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.claimable-options -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.claimable-options`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@claimableOptions`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.read`
- **권한 메모(1.3.0)**: 응답 `abilities.can_cancel`(= `user-orders.cancel`)·`abilities.can_request`(= `user-claims.request`) — 신청 버튼 잠금 키. 즉시 취소 분기(`refund_branch=auto`) 상품은 `can_cancel` 도 필요하다.

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 주문 ID |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/orders/120/claimable-options HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| order_id | integer | `120` | 주문 ID |
| order_number | string | `20260823-1130558472` | 주문번호 |
| base_currency | string | `KRW` | 이 주문의 금액 표기 기준 통화. 신청서 화면이 보조 통화 목록에서 기준 통화를 걸러 낼 때 쓴다 — 그 기준을 주문 상세 응답에서 따로 읽으면 두 응답의 도착 순서에 따라 기준 통화가 보조 통화 줄에 한 번 더 나열된다. 판정 규칙은 주문 상세의 `base_currency` 와 같다(거래 시점 통화 스냅샷 → 없으면 상점 기본 통화) |
| resolved_type | string\|null | `null` | 취소·환불 신청이 열리는 상품들의 분기 — 전부 자동 전이면 `auto`, 전부 운영자 승인 대기면 `request`, 섞여 있으면 `mixed`, 열린 상품이 없으면 `null` |
| options[].option | object | — | 주문 옵션 (`OrderOptionResource`, 취소된 옵션은 제외) |
| options[].available_quantity | integer | `1` | 지금 신청할 수 있는 잔여 수량 (옵션 수량 − 진행 중 클레임 점유 수량) |
| options[].locked_quantity | integer | `0` | 진행 중인 교환·반품·환불신청이 잡아 둔 수량 |
| options[].claimable_types | string[] | `["exchange","return"]` | 이 옵션에 열린 클레임 유형 (`exchange`, `return`, `refund`). 잔여 수량이 0 이면 빈 배열 |
| options[].refund_branch | string\|null | `null` | 취소·환불 신청 시 분기 — 자동 전이 대상 상태면 `auto`, 운영자 승인 대상 상태면 `request`, 둘 다 아니면 `null`. **두 값 모두 환불신청서를 만든다** — 다른 것은 승인·완료까지 자동으로 진행하는지 여부뿐이다 |
| reasons.exchange | array | — | 교환 사유 카탈로그 (`ClaimReasonResource`). **`name` 은 다국어 맵**(`{"ko":…,"en":…}`)이고 화면에 그대로 쓸 문자열은 `localized_name` 이다 — `name` 을 라벨에 바인딩하면 객체가 그대로 찍힌다 |
| reasons.return | array | — | 반품 사유 카탈로그 |
| reasons.refund | array | — | 취소·환불 사유 카탈로그 |
| settings.allowed_charge_methods | string[] | `["deduct_from_refund","pay_on_request"]` | 허용된 배송비 청구 방식 (환경설정 `claim.allowed_charge_methods`) |
| settings.charge_payment_methods | string[] | `["card","point"]` | 추가결제에 쓸 수 있는 결제 수단 (환경설정 `claim.charge_payment_methods`) |
| settings.charge_payment_method_labels | object | `{"card":"신용카드","point":"포인트결제"}` | `charge_payment_methods` 의 값마다 현재 언어 표시명(결제수단 카탈로그 이름). 화면은 `card`·`point` 를 자체 문구로, 그 밖의 수단은 이 이름으로 그린다 |
| settings.max_images | integer | `5` | 신청서당 첨부 이미지 상한 (`claim.max_images`) |
| settings.image_max_size_kb | integer | `5120` | 첨부 1장 크기 상한 KB (`claim.image_max_size_kb`) |
| settings.allowed_extensions | string[] | `["jpeg","jpg","png","gif","webp"]` | 서버가 받는 확장자 전수. 화면의 파일 선택창 필터와 서버 검증이 이 하나를 읽는다 |
| settings.accept | string | `.jpeg,.jpg,.png,.gif,.webp` | 파일 선택창에 그대로 넣는 필터 문자열. `image/*` 로 두면 서버가 받지 않는 확장자를 고를 수 있고, 고르면 이유 없이 422 가 된다 |
| settings.request_period_days | integer | `7` | 배송완료 후 신청 가능 일수 (`claim.request_period_days`, 0 이면 제한 없음) |
| settings.exchange_requestable_statuses | string[] | `["delivered","confirmed"]` | 교환 신청이 열리는 주문 옵션 상태 |
| settings.return_requestable_statuses | string[] | `["delivered","confirmed"]` | 반품 신청이 열리는 주문 옵션 상태 |
| settings.instant_cancel_statuses | string[] | `["pending_order","pending_payment","payment_complete"]` | 취소·환불 신청이 **자동 전이**로 처리되는 상태 (`claim.instant_cancel_statuses`). 이 상태의 상품도 환불신청서를 만들고, 승인·환불완료까지 자동으로 진행한다 |
| settings.refund_request_statuses | string[] | `["preparing","shipping_ready"]` | 취소·환불 신청이 환불신청서(운영자 승인)로 접수되는 상태 (`claim.refund_request_statuses`) |
| refund_auto_approved | boolean | `false` | 이 주문의 환불이 **사람 손 없이 끝나는지** (P16·P17). 판정은 결제수단 카탈로그(`PaymentMethodResolver::autoApprovesRefund()`)가 소유하며, 운영자가 클레임 정책 탭에서 고른 목록(`claim.auto_approve_refund_payment_methods`)과 플러그인 선언을 함께 본다. **분기(`resolved_type`·`refund_branch`)와 다른 축이다** — 무통장·가상계좌는 분기가 `auto` 여도 이 값이 거짓이라 접수 뒤 `requested` 에서 멈추고 운영자 승인 → [환불하기] 를 기다린다. 거짓이면 신청서는 「자동 처리」가 아니라 「판매자 확인」 문구로 안내해야 한다 |
| refund_account.required | boolean | `false` | 이 주문의 환불이 **계좌 이체**로 나가는지 (`PaymentMethodResolver::refundMethod()` 판정 = `bank`). 참이면 취소·환불 신청서가 환불 계좌 입력칸을 세운다. 어느 결제수단이 계좌 환불인지는 확장 결제수단이 자기 카탈로그에 선언하므로 화면이 결제수단 코드로 재현하지 않는다 |
| refund_account.banks | array | `[{"code":"004","name":{"ko":"국민은행"}}]` | 운영자가 주문설정에 등록한 은행 목록 (`order_settings.banks`). `required` 가 거짓이면 빈 배열이다. `name` 은 다국어 맵이라 라벨은 `$localized(name)` 로 푼다 |
| refund_account.prefill | object\|null | `{"bank_code":"004","account_number":"********7890","holder":"홍**","masked":true}` | 주문 시 등록해 둔 환불 계좌를 **가린 모양**으로 싣는다(계좌번호 뒤 4자리·예금주 첫 글자, `masked: true`). 이 응답은 비회원 조회 토큰을 가진 누구에게나 나가므로 원문을 싣지 않는다. 신청서는 이 값으로 입력칸을 채우고, 구매자가 손대지 않고 제출하면(세 값이 가린 모양과 정확히 같으면) 서버가 등록 계좌 원문으로 되돌려 저장한다 — 한 칸이라도 고치면 제출값을 쓴다. 세 값이 모두 있을 때만 실리고 그 밖에는 `null`, `required` 가 거짓이면 실리지 않는다 |
| pending_images | array | — | 이 주문에 올려 둔 임시 첨부 ([ClaimImageResource](claims.md#claim-image-resource), `claimable_type=pending`) |

**응답 예시**

```json
{
    "success": true,
    "message": "신청 가능 상품을 조회했습니다.",
    "data": {
        "order_id": 120,
        "order_number": "20260823-1130558472",
        "base_currency": "KRW",
        "resolved_type": null,
        "options": [
            {
                "option": { "id": 310, "product_name": "반팔 티셔츠", "option_name": "화이트 / M", "quantity": 1, "option_status": "delivered" },
                "available_quantity": 1,
                "locked_quantity": 0,
                "claimable_types": ["exchange", "return"],
                "refund_branch": null
            }
        ],
        "reasons": {
            "exchange": [ { "code": "size_mismatch", "name": { "ko": "사이즈가 맞지 않음", "en": "Size Does Not Fit" }, "localized_name": "사이즈가 맞지 않음", "fault_type": "customer", "fault_type_label": "고객 귀책", "is_user_selectable": true, "sort_order": 1 } ],
            "return": [ { "code": "changed_mind", "name": { "ko": "단순 변심", "en": "Changed Mind" }, "localized_name": "단순 변심", "fault_type": "customer", "fault_type_label": "고객 귀책", "is_user_selectable": true, "sort_order": 1 } ],
            "refund": [ { "code": "changed_mind", "name": { "ko": "단순 변심", "en": "Changed Mind" }, "localized_name": "단순 변심", "fault_type": "customer", "fault_type_label": "고객 귀책", "is_user_selectable": true, "sort_order": 1 } ]
        },
        "settings": {
            "allowed_charge_methods": ["deduct_from_refund", "pay_on_request", "pay_on_approval"],
            "charge_payment_methods": ["card", "point"],
            "charge_payment_method_labels": { "card": "신용카드", "point": "포인트결제" },
            "max_images": 5,
            "image_max_size_kb": 5120,
            "allowed_extensions": ["jpeg", "jpg", "png", "gif", "webp"],
            "accept": ".jpeg,.jpg,.png,.gif,.webp",
            "request_period_days": 7,
            "exchange_requestable_statuses": ["delivered", "confirmed"],
            "return_requestable_statuses": ["delivered", "confirmed"],
            "instant_cancel_statuses": ["pending_order", "pending_payment", "payment_complete"],
            "refund_request_statuses": ["preparing", "shipping_ready"]
        },
        "refund_auto_approved": true,
        "refund_account": { "required": false, "banks": [], "prefill": null },
        "pending_images": []
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.read`)이 없는 경우 |
| 404 | Not Found | 주문이 없거나 본인 주문이 아닌 경우 |
| 500 | Server Error | 조회 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 클레임 신청 화면을 여는 첫 호출입니다. 주문의 각 상품에 어떤 클레임이 열려 있는지(`claimable_types`), 잔여 수량, 취소·환불 신청 시 즉시취소/환불신청 분기, 사유 카탈로그, 화면이 따라야 할 설정값, 작성 중 올려 둔 임시 첨부를 한 번에 돌려줍니다. 교환·반품이 진행 중인 상품은 옵션 상태가 코스 상태(`exchange_in_progress`/`return_in_progress`)라 `claimable_types` 가 비고, 직접 환불신청이 진행 중인 상품은 상태가 그대로라도 `locked_quantity` 만큼 잔여가 줄어듭니다. `resolved_type` 이 `mixed` 면 자동 전이 상품과 승인 대기 상품을 한 신청에 섞을 수 없으므로 화면이 상품 선택을 분기별로 나눠야 합니다 — 한쪽은 바로 환불되고 다른 쪽은 심사를 기다려, 고객이 보는 환불 예정액과 실제 환불액이 갈리기 때문입니다. `refund_account` 는 그 주문의 환불이 계좌 이체로 나가는지, 그때 고를 은행 목록, 그리고 주문 시 등록해 둔 계좌(`prefill`)입니다 — 화면은 이 값으로만 계좌 입력칸을 세우고 채웁니다. 프리필은 반드시 `prefill` 을 쓰고 표시용 응답의 마스킹된 계좌를 되쓰지 않습니다(마스킹 값을 되보내면 별표가 섞인 계좌가 저장됩니다). 계좌 입력칸은 반품·취소·환불 세 유형에 서고, 교환은 저가 옵션으로 바꿔 **차액이 구매자에게 돌아갈 때만** 섭니다.

---

### GET /api/modules/sirsoft-ecommerce/user/orders/{id}/claims
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.claims.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.claims.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@orderClaims`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 주문 ID (본인 주문이 아니면 존재를 드러내지 않고 404) |
| type | query | string | 아니오 | `exchange` \| `return` \| `refund` \| `cancel` | 유형 서브탭. 비우면(`?type=` 포함) 접수 건이 있는 첫 유형을 서버가 고른다 — 교환 → 반품 → 취소 → 환불 순, 넷 다 없으면 `exchange`. 고른 값은 응답 `claim_type` 에 실린다 |
| status | query | string | 아니오 | 유형별 상태 Enum | 상태 필터 (`type` 과 함께 쓴다 — 유형을 비우면 상태 어휘는 교환 기준으로 검증된다) |
| from | query | date | 아니오 | — | 신청일 시작 (그날 00:00:00 부터) |
| to | query | date | 아니오 | — | 신청일 종료 (그날 23:59:59 까지) |
| page | query | integer | 아니오 | 1 이상 | 페이지 번호 |
| per_page | query | integer | 아니오 | 1~50 | 페이지당 건수 (기본 20) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/orders/120/claims?type=return&page=1&per_page=20 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | array | — | 이 주문에서 접수된 그 유형의 신청서 목록 ([ClaimResource](claims.md#claim-resource) 목록 표현, 최신순) |
| pagination | object | — | 페이지 정보 (`current_page` / `per_page` / `from` / `to` / `has_more_pages` / `last_page` / `total`) |
| claim_type | string | `return` | 이 응답이 실은 유형 — `type` 을 지정했으면 그 값, 비웠으면 서버가 고른 값. 화면은 주소에 유형이 없을 때 이 값으로 활성 서브탭을 세운다 |
| counts | object | `{"exchange": 0, "return": 1, "refund": 0, "cancel": 0}` | 이 주문의 유형별 접수 건수 (네 유형 전부). 목록과 같은 기준으로 세므로 환불 건수에 취소 발 환불은 들지 않는다 |

**응답 예시**

```json
{
    "success": true,
    "message": "신청 내역을 조회했습니다.",
    "data": {
        "data": [
            { "claim_number": "RT20260823-1358024460", "claim_type": "return", "status": "requested", "order_number": "20260823-1130558472" }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": 1,
            "to": 1,
            "has_more_pages": false,
            "last_page": 1,
            "total": 1
        },
        "claim_type": "return",
        "counts": { "exchange": 0, "return": 1, "refund": 0, "cancel": 0 }
    }
}
```

**오류 응답**

401 (미인증) / 403 (`user-claims.read` 없음) / 404 (본인 주문이 아니거나 없음) / 422 (유형·상태 어휘 밖, 페이지 범위 밖) / 500.

<!-- @generated:end -->

**설명** 마이페이지 주문 상세의 '이 주문의 취소·반품·교환' 섹션이 읽는 목록입니다. 회원 신청내역([claims.md](claims.md) `GET /user/claims`)·비회원 주문상세 섹션([guest.md](guest.md) `GET /guest/orders/{orderNumber}/claims`)과 같은 계약(유형 서브탭 하나 · 최신순 · 페이지)이며 조회도 같은 지점입니다 — 회원 목록에 주문 필터를 얹지 않고 주문 스코프 경로를 따로 두는 것은, 주문 상세가 이미 주문 ID 로 형제 자원(`claimable-options`)을 부르고 있어 같은 모양이 화면에 자연스럽고, 본인 주문이 아니면 존재를 드러내지 않고 404 로 닫는 규칙을 그 형제와 함께 두기 위해서입니다. 화면은 주문 응답이 도착해 주문 ID 를 얻은 뒤 이 목록을 부르고, 유형 서브탭·페이지는 주문 상세 주소의 `?type=`·`?page=` 로 왕복합니다. 주소에 유형이 없으면 화면은 `type` 을 비워 보내고, 서버가 접수 건이 있는 첫 유형(교환 → 반품 → 취소 → 환불)을 골라 `claim_type` 으로 알려 줍니다 — 종전에는 늘 교환이어서 취소만 있는 주문에서 '접수된 건이 없습니다' 가 먼저 보였습니다. `counts` 는 유형별 접수 건수이고 회원 신청내역(`GET /user/claims`)에는 이 두 필드가 없습니다(그 화면은 주소가 늘 유형을 실어 보냅니다).

---

### POST /api/modules/sirsoft-ecommerce/user/orders/{id}/claims/preview
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.claims.preview -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.claims.preview`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@preview`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.request`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 주문 ID |
| type | body | string | 예 | `exchange`, `return`, `refund` | 미리보기할 클레임 유형 |
| items | body | array | 예 | min 1, max 200 | 신청 대상 `[{order_option_id, quantity, new_product_option_id?, custom_options?}]` (`new_product_option_id` 는 교환 전용 — 없으면 동일 옵션 교환) |
| reason_code | body | string | `type` 이 `exchange`·`return` 이면 예 | max 50 | 사유 코드. 귀책 구분이 회수·왕복 배송비의 부담 주체를 정하므로 교환·반품은 **필수**다(없으면 `reason_code` 필드 오류 422). `refund` 는 회수 배송이 없어 선택 |
| charge_method | body | string | 아니오 | 환경설정 `claim.allowed_charge_methods` | 배송비 청구 방식 (교환·반품) |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 (미전달 시 `pg_first`) |
| redelivery_address | body | object | 아니오 | — | (교환) 재발송지 — `zipcode`(max 20), `country_code`(2자). 배송비 재산정에만 쓰인다 |
| claim_number | body | string | 아니오 | max 50 | 수정 화면의 미리보기면 자기 신청서 번호 — 그 신청서가 잡아 둔 수량·코스 상태를 판정에서 제외한다 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders/120/claims/preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "type": "return",
    "items": [ { "order_option_id": 310, "quantity": 1 } ],
    "reason_code": "changed_mind",
    "charge_method": "pay_on_request"
}
```

**응답 필드** (`data` 내부)

공통으로 `type` 이 실리고, 유형별로 형태가 다릅니다.

_교환 (`type=exchange`)_

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| shipping_fee | object | — | 배송비 산정 결과 (`fee`, `extra_fee`, `mc_fee`, `mode`, `claim_type`, `fault_type`, `charged`, `reason`, `redelivery_changed`, `groups`) |
| adjustment | object | — | 교환으로 돌려주는 금액의 재계산 결과 (취소 미리보기와 같은 `AdjustmentResult` 형태). **교환은 옵션 치환 재계산**이다 — `recalculated_snapshot`·`mc_recalculated_snapshot`·`recalculated_coupons`·`total_list_price_amount` 는 대상 상품을 신규 옵션(단가·정가·적립 규칙·추가옵션)으로 바꿔 넣고 쿠폰 재배분·배송비·부가세·적립까지 다시 계산한 "교환 후" 값이며, 환불·청구 산식(`refund_*`·`shipping_difference`·`discount_difference`)은 치환 없이 계산한다(옵션 차액은 `additional_product_amount`/`refund_product_amount` 가 다룬다). `substituted_items` 는 그 치환의 근거 행 `[{order_option_id, quantity, product_option_id|null, unit_price, new_unit_price, line_key, option_label}]` 이고, 고가 교환에서도 `cancel_blocked` 는 `false` 다 |
| items | array | — | 상품별 교환 계획 (`order_option_id`, `quantity`, `is_option_change`, `new_product_option_id`, `unit_price`, `new_unit_price`, `price_difference`) |
| price_difference | number | `0` | 옵션 차액 합계 (양수 추가 결제 / 음수 환불) |
| additional_product_amount | number | `0` | 추가로 받을 상품 금액 |
| refund_product_amount | number | `0` | 돌려줄 상품 금액 |
| mc_price_difference | object\|null | `{"KRW":3000,"USD":2.25}` | 옵션 차액 합계의 통화별 환산 (주문 통화 스냅샷의 동결 환율. 스냅샷이 없으면 `null`). 요약 카드의 형제 금액 줄과 **같은 규약**이라 이 값이 없으면 그 줄만 기준 통화 하나로 남는다 |
| mc_additional_product_amount | object\|null | `{"KRW":3000,"USD":2.25}` | 추가로 받을 상품 금액의 통화별 환산 (주문 통화 스냅샷의 동결 환율. 스냅샷이 없으면 `null`). 요약 카드의 형제 금액 줄과 **같은 규약**이라 이 값이 없으면 그 줄만 기준 통화 하나로 남는다 |
| mc_refund_product_amount | object\|null | `{"KRW":0,"USD":0}` | 돌려줄 상품 금액의 통화별 환산 (주문 통화 스냅샷의 동결 환율. 스냅샷이 없으면 `null`). 요약 카드의 형제 금액 줄과 **같은 규약**이라 이 값이 없으면 그 줄만 기준 통화 하나로 남는다 |
| total_charge_amount | number | `6000` | 총 청구액 (배송비 + 추가 상품 금액) |
| mc_total_charge_amount | object\|null | `{"KRW":9000,"USD":6.75}` | 총 청구액의 통화별 환산 (주문 통화 스냅샷의 동결 환율. 스냅샷이 없으면 `null`) |
| fault_type | string | `customer` | 사유의 귀책 구분 |
| charge_method | string\|null | `pay_on_request` | 적용된 청구 방식 |
| requires_payment | boolean | `true` | 신청 시 추가결제가 필요한지 (청구액 > 0 이고 결제형 청구 방식) |

_반품 (`type=return`)_

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| shipping_fee | object | — | 배송비 산정 결과 (위와 동일 형태) |
| adjustment | object | — | 반품 환불 금액 재계산 결과 (`refund_amount`, `refund_points_amount`, `refund_total`, `shipping_difference`, `cancelled_items`, `refund_priority`, `original_snapshot`/`recalculated_snapshot` + `formatted`, `mc_*_snapshot`, `restored_coupons`, `remaining_*_balance`, `cancel_blocked` …). 신청서 요약 카드의 "주문금액 비교(전/후)" 표와 환불 요약은 이 객체만 읽는다 — `substituted_items` 는 항상 `[]` |
| fault_type | string | `customer` | 귀책 구분 |
| charge_method | string\|null | `pay_on_request` | 적용된 청구 방식 |
| total_charge_amount | number | `3000` | 총 청구액 (반품 배송비) |
| mc_total_charge_amount | object\|null | `{"KRW":3000,"USD":2.25}` | 총 청구액의 통화별 환산 (배송비 산정이 낸 값. 스냅샷이 없으면 `null`) |
| deducted_shipping_fee | number | `3000` | 환불금에서 실제로 차감한 회수 배송비. 청구 방식이 `deduct_from_refund` 일 때만 0 이 아니다 — 별도 결제 방식(`pay_on_request`/`pay_on_approval`)은 배송비를 클레임 결제 건으로 따로 받으므로 0 이다 |
| mc_deducted_shipping_fee | object\|null | `{"KRW":3000,"USD":2.25}` | 차감한 회수 배송비의 통화별 환산 (주문 통화 스냅샷의 동결 환율. 스냅샷이 없으면 `null`). 요약 카드의 형제 금액 줄과 **같은 규약**이라 이 값이 없으면 그 줄만 기준 통화 하나로 남는다 |
| refund_before_deduction | number | `29000` | 회수 배송비를 빼기 **전**의 환불 예정액. `adjustment.refund_total` 은 이미 차감된 뒤의 금액이라, 화면이 "환불예정 / 회수비 / 최종" 세 줄을 세우려면 이 값이 필요하다. 차감하지 않는 방식에서는 `adjustment.refund_total` 과 같다 |
| mc_refund_before_deduction | object\|null | `{"KRW":29000,"USD":21.75}` | 차감 전 환불 예정액의 통화별 환산 (주문 통화 스냅샷의 동결 환율. 스냅샷이 없으면 `null`) |
| requires_payment | boolean | `true` | 신청 시 추가결제가 필요한지 |

_취소·환불 (`type=refund`)_

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| branch | string | `auto` | 이 상품들로 신청하면 타게 될 분기 (`auto` 접수 후 자동 전이 / `request` 접수 후 운영자 승인 대기) |
| adjustment | object | — | 환불 금액 재계산 결과 (취소 미리보기 `estimate-refund` 와 같은 형태 — 전/후 스냅샷·다통화 스냅샷·복원 쿠폰·잔여 잔액·`cancel_blocked` 포함). 신청서의 비교표·환불 우선순위·환불 불가 안내가 전부 이 객체를 읽는다 |

**응답 예시**

```json
{
    "success": true,
    "message": "신청 예상 금액을 계산했습니다.",
    "data": {
        "type": "return",
        "shipping_fee": { "fee": 3000, "extra_fee": 0, "mode": "fixed", "claim_type": "return", "fault_type": "customer", "charged": true },
        "adjustment": { "refund_amount": 29000, "refund_points_amount": 0, "refund_total": 29000, "refund_priority": "pg_first" },
        "fault_type": "customer",
        "charge_method": "pay_on_request",
        "total_charge_amount": 3000,
        "mc_total_charge_amount": { "KRW": 3000, "USD": 2.25 },
        "deducted_shipping_fee": 3000,
        "refund_before_deduction": 29000,
        "mc_refund_before_deduction": { "KRW": 29000, "USD": 21.75 },
        "requires_payment": true
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.request`)이 없는 경우 |
| 404 | Not Found | 주문이 없거나 본인 주문이 아닌 경우 |
| 422 | Unprocessable Entity | 검증 위반, 신청 불가 상태(`claim_option_not_claimable`), 신청 기간 경과(`claim_request_period_expired`), 수량 잠금(`claim_quantity_locked`), 사유 사용 불가(`claim_reason_unavailable`), 청구 방식 불허(`claim_charge_method_not_allowed`), 분기 혼재(`claim_refund_branch_mixed`) |
| 500 | Server Error | 계산 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 신청 전에 배송비·차액·환불액을 미리 계산합니다. 미리보기는 신청과 **같은 자격 가드**를 탑니다 — 신청 불가 상태·기간·수량 잠금이면 신청과 같은 422 를 돌려주므로, 화면은 미리보기 성공을 "신청 가능" 의 근거로 써도 됩니다. 수정 화면에서는 `claim_number` 를 넘겨야 자기 신청서가 잡아 둔 수량과 코스 상태가 판정에서 빠집니다(안 넘기면 자기 신청서에 막혀 신청 불가로 나온다). 금액 계산의 기준은 서버이며 화면은 표시만 합니다.

---

### POST /api/modules/sirsoft-ecommerce/user/orders/{id}/exchanges
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.exchanges.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.exchanges.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@storeExchange`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.request`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 주문 ID |
| items | body | array | 예 | min 1, max 200 | 교환 대상 `[{order_option_id, quantity, new_product_option_id?, custom_options?}]` |
| items.*.new_product_option_id | body | integer | 아니오 | 같은 상품의 옵션 | 교환받을 옵션 (미전달·`null` 이면 동일 옵션 교환) |
| reason_code | body | string | 예 | `exchange` 유형의 활성·사용자 선택 가능 사유 | 교환 사유 |
| reason_detail | body | string | 아니오 | max 1000 | 사유 상세 |
| charge_method | body | string | 아니오 | 환경설정 `claim.allowed_charge_methods` | 배송비·차액 청구 방식. 청구액이 0 이면 무시 |
| payment_method | body | string | 아니오 | 환경설정 `claim.charge_payment_methods` | `pay_on_request` 일 때 결제 수단 (`point` 는 회원만) |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 옵션 차액이 음수일 때 환불 배분 우선순위 |
| refund_bank | body | object | 아니오 | `bank_code`(max 10) · `account_number`(max 50) · `holder`(max 50) | 환불 계좌. 저가 옵션으로 바꿔 **차액이 구매자에게 돌아가는** 교환에서, 그 환불이 계좌 이체로 나가는 주문(`claimable-options` 의 `refund_account.required=true`)일 때 화면이 싣는다. **세 값이 함께 오거나 하나도 오지 않아야 한다** — 일부만 오면 422 다. 값이 오면 교환 신청서 행에 저장되고, 완료 시 만들어지는 환불 레코드가 그 값을 이어받는다 |
| image_ids | body | integer[] | 아니오 | max = 환경설정 `claim.max_images`(기본 5) | 귀속시킬 임시 첨부 ID (이 주문에 올린 `pending` 첨부만). 상한은 운영자 설정을 그대로 따른다 |
| redelivery_address | body | object | 아니오 | — | 재발송지 — `recipient_name`, `phone`, `zipcode`, `address1`, `address2`, `country_code`(원 배송지와 같은 국가만), `changed_by_user` |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders/120/exchanges HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [ { "order_option_id": 310, "quantity": 1, "new_product_option_id": 56 } ],
    "reason_code": "size_mismatch",
    "reason_detail": "한 치수 크게",
    "charge_method": "pay_on_request",
    "payment_method": "card",
    "image_ids": [9]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | — | 접수된 교환 신청서 ([ClaimResource](claims.md#claim-resource), 결제 블록 포함) |
| payment | object\|null | — | `pay_on_request` 로 청구액이 있으면 만들어진 결제 건 ([ClaimPaymentResource](claims.md#claim-payment-resource)), 아니면 `null` |
| requires_pg_payment | boolean | `true` | 결제창을 열어야 하는지 (결제 건이 없거나 마일리지로 즉시 결제되면 `false`) |
| pg_provider | string\|null | `sirsoft-kginicis` | (결제 건이 있을 때) PG provider id — 결제 진입 페이로드는 `sirsoft-` 접두를 붙인다(`ClaimPaymentResource.pg_provider` 는 접두 없는 raw id) |
| pg_payment_handler | string\|null | `sirsoft-pay_kginicis.requestPayment` | (결제 건이 있을 때) 결제창 핸들러 |
| pg_payment_data | object\|null | — | (결제 건이 있을 때) 결제창 파라미터 — [claim-payments.md](claim-payments.md) |

**응답 예시**

```json
{
    "success": true,
    "message": "신청이 접수되었습니다.",
    "data": {
        "claim": {
            "claim_number": "EX20260823-1349207731",
            "claim_type": "exchange",
            "status": "payment_pending",
            "total_charge_amount": 8000,
            "additional_product_amount": 2000,
            "items": [ { "order_option_id": 310, "quantity": 1, "is_option_change": true, "new_option_label": "화이트 / L", "price_difference": 2000 } ],
            "images": [ { "id": 9, "claimable_type": "exchange", "claimable_id": 17 } ],
            "outstanding_amount": 8000,
            "pending_payment": { "payment_number": "CP20260823-1512087213" }
        },
        "payment": { "payment_number": "CP20260823-1512087213", "payment_status": "pending", "total_amount": 8000 },
        "requires_pg_payment": true,
        "pg_provider": "sirsoft-kginicis",
        "pg_payment_handler": "sirsoft-pay_kginicis.requestPayment",
        "pg_payment_data": { "order_number": "CP20260823-1512087213", "amount": 8000, "currency": "KRW", "claim_type": "exchange", "claim_number": "EX20260823-1349207731" }
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.request`)이 없는 경우 |
| 404 | Not Found | 주문이 없거나 본인 주문이 아닌 경우 |
| 422 | Unprocessable Entity | 검증 위반(사유 코드 부재, 재발송지 국가 변경 등), 미리보기와 같은 자격 위반, 교환 옵션 무효·재고 부족(`exchange_target_option_invalid` / `exchange_target_out_of_stock`), 첨부 부재·장수 초과(`claim_image_not_found` / `claim_image_limit_exceeded`), 결제 수단 불허(`claim_payment_method_not_allowed`), 청구가 발생하는데 주문의 거래 시점 통화 스냅샷을 읽을 수 없어 결제 통화 기준을 세울 수 없는 경우(`claim_payment_currency_unavailable` — 신청 시점 결제 진입이 실패할 건이므로 **결제행을 만들기 전에** 거절합니다) |
| 500 | Server Error | 접수 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 교환을 접수합니다(201). 접수되는 순간 대상 상품 행은 `exchange_in_progress` 로 바뀌고(부분 수량이면 분할), 첨부(`image_ids`)는 이 신청서에 귀속됩니다. 청구액이 있고 청구 방식이 `pay_on_request` 면 결제 건을 만들고 신청서를 `payment_pending` 으로 옮기며 응답 최상위에 결제 진입 4키를 싣습니다 — `payment_method=point` 면 마일리지로 즉시 결제되어 `requires_pg_payment=false`, 신청서는 `paid`(결제완료)로 넘어갑니다(자동 승인이 켜져 있으면 이어서 `approved`). `pay_on_approval` 은 운영자 승인 시점에 결제 건이 만들어지고, `deduct_from_refund` 는 환불액에서 차감합니다. 재발송지 국가는 원 배송지와 같아야 하며(D14), 바꾸려면 신청을 철회하고 새로 넣습니다.

---

### POST /api/modules/sirsoft-ecommerce/user/orders/{id}/returns
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.returns.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.returns.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@storeReturn`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.request`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 주문 ID |
| items | body | array | 예 | min 1, max 200 | 반품 대상 `[{order_option_id, quantity}]` |
| reason_code | body | string | 예 | `return` 유형의 활성·사용자 선택 가능 사유 | 반품 사유 |
| reason_detail | body | string | 아니오 | max 1000 | 사유 상세 |
| charge_method | body | string | 아니오 | 환경설정 `claim.allowed_charge_methods` | 반품 배송비 청구 방식. 청구액이 0 이면 무시 |
| payment_method | body | string | 아니오 | 환경설정 `claim.charge_payment_methods` | `pay_on_request` 일 때 결제 수단 |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 |
| refund_bank | body | object | 조건부 | `bank_code`(max 10) · `account_number`(max 50) · `holder`(max 50) | 환불 계좌. 계좌 이체로 환불되는 주문(`claimable-options` 의 `refund_account.required=true`)에서는 **필수**다 — 비면 `422 refund_bank`(주문 시점 등록 계좌가 있으면 생략 가능). 취소·환불 신청과 같은 게이트다. **세 값이 함께 오거나 하나도 오지 않아야 한다** — 일부만 오면 422 다. 값이 오면 **그 신청서 행**(`refund_bank_*`)에 저장된다. 주문 결제행은 신청 접수만으로는 바뀌지 않는다 — 실제 송금이 읽는 자리라, 그 환불을 **실행하는 시점**에 실행 대상 환불의 계좌로 갱신된다(즉시취소는 접수가 곧 실행이라 그 자리에서 반영된다) |
| image_ids | body | integer[] | 아니오 | max = 환경설정 `claim.max_images`(기본 5) | 귀속시킬 임시 첨부 ID |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders/120/returns HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [ { "order_option_id": 310, "quantity": 1 } ],
    "reason_code": "changed_mind",
    "charge_method": "deduct_from_refund"
}
```

**응답 필드** (`data` 내부)

교환 신청과 같은 형태 — `claim`(반품 [ClaimResource](claims.md#claim-resource), 결제 블록 포함) + `payment` + 결제 진입 4키.

**응답 예시**

```json
{
    "success": true,
    "message": "신청이 접수되었습니다.",
    "data": {
        "claim": {
            "claim_number": "RT20260823-1402331875",
            "claim_type": "return",
            "status": "requested",
            "charge_method": "deduct_from_refund",
            "claim_shipping_fee": 3000,
            "total_charge_amount": 3000,
            "requires_additional_payment": false,
            "items": [ { "order_option_id": 310, "quantity": 1, "status": "requested" } ],
            "payments": [],
            "outstanding_amount": 3000,
            "pending_payment": null,
            "payment_entry": null
        },
        "payment": null,
        "requires_pg_payment": false
    }
}
```

**오류 응답**

교환 신청과 같습니다 (401 / 403 / 404 / 422 / 500). 반품은 옵션 변경이 없으므로 `exchange_target_*` 계열은 발생하지 않습니다.

<!-- @generated:end -->

**설명** 반품을 접수합니다(201). 대상 상품 행은 `return_in_progress` 로 바뀌고(부분 수량이면 분할), 반품 배송비는 사유의 귀책 구분과 배송정책(고정비/정책/없음)으로 산정됩니다. `deduct_from_refund` 면 환불 시 환불액에서 배송비를 빼고(`outstanding_amount` 는 남지만 결제 건은 만들지 않는다), `pay_on_request` 면 교환과 같은 결제 흐름을 탑니다. 회수(택배사·송장)는 운영자가 승인 뒤 진행하며 `collection_*` 필드로 드러납니다.

---

### POST /api/modules/sirsoft-ecommerce/user/orders/{id}/refund-requests
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.refund-requests.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.refund-requests.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@storeRefund`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.request`
- **권한 메모(1.3.0)**: 선택 상품이 즉시 취소 분기(`auto`)면 `sirsoft-ecommerce.user-orders.cancel` 도 요구한다. 없으면 403(`messages.claims.instant_cancel_forbidden`).

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 주문 ID |
| items | body | array | 예 | min 1, max 200 | 대상 `[{order_option_id, quantity}]` — 전부 같은 분기(`auto` 또는 `request`)여야 한다 |
| reason_code | body | string | 예 | `refund` 유형의 활성·사용자 선택 가능 사유 | 취소·환불 사유 |
| reason_detail | body | string | 아니오 | max 1000 | 사유 상세 |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 |
| refund_bank | body | object | 아니오 | — | 무통장 주문의 환불 계좌 — `bank_code`(max 10), `account_number`(max 50), `holder`(max 50). 셋을 함께 보내거나 모두 생략 |
| image_ids | body | integer[] | 아니오 | max = 환경설정 `claim.max_images`(기본 5) | 귀속시킬 임시 첨부 ID (환불신청 분기에서만 귀속) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders/120/refund-requests HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [ { "order_option_id": 310, "quantity": 1 } ],
    "reason_code": "changed_mind",
    "reason_detail": "주문 착오"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| branch | string | `auto` | 실제로 탄 분기 — `auto`(접수 후 승인·완료까지 자동 전이) 또는 `request`(접수 후 운영자 승인 대기) |
| claim | object | — | **언제나 환불신청서**(`claim_type=refund`) — [ClaimResource](claims.md#claim-resource). 예외는 환불할 돈이 없는 주문(미결제·0원)으로, 그때만 취소신청서(`claim_type=cancel`)가 실린다. 화면은 이 값의 `claim_type` 으로 이동할 탭을 정한다 — 분기(`branch`)로 추측하면 분기가 섞인 주문에서 접수한 탭과 어긋난다 |

**응답 예시**

```json
{
    "success": true,
    "message": "주문이 취소되었습니다.",
    "data": {
        "branch": "auto",
        "claim": {
            "claim_number": "CN20260823-1421669305",
            "claim_type": "cancel",
            "status": "completed",
            "cancel_type": "full",
            "refund_amount": 29000,
            "refund_status": "completed",
            "items": [ { "order_option_id": 310, "quantity": 1 } ],
            "cancelled_at_formatted": "2026-08-23 13:41"
        }
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.request`)이 없는 경우 |
| 404 | Not Found | 주문이 없거나 본인 주문이 아닌 경우 |
| 422 | Unprocessable Entity | 검증 위반, 취소·환불 신청 불가 상태(`claim_refund_not_requestable` — 배송완료 이후는 반품으로), 분기 혼재(`claim_refund_branch_mixed`), 수량 잠금(`claim_quantity_locked`), 자동 전이 중 PG 환불 거절(`order_cancel_failed` — 이때 접수까지 **전체 롤백**되고 `errors.detail` 에 PG 사유가 실린다) |
| 500 | Server Error | 접수 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 취소·환불 신청을 하나의 입구로 받아 상품 상태로 분기합니다(D2). **두 분기 모두 환불신청서**(`refund_source_type=direct`)를 만들며, 다른 것은 전이 방식뿐입니다.

- 대상 상품이 전부 `claim.instant_cancel_statuses`(기본 미결제 2종 + 결제완료)면 `auto` — 접수 후 승인·환불완료까지 자동으로 진행합니다. 종착점은 **환불수단**이 가릅니다: PG·마일리지는 완료까지, 계좌 환불은 승인에서 멈추고 운영자의 [환불하기]를 기다립니다.
- 전부 `claim.refund_request_statuses`(기본 상품준비중~배송중)면 `request` — 접수에서 멈춰 운영자 승인을 기다립니다. `claim.auto_approve_refund_request` 가 켜져 있고 상품 상태가 `claim.auto_approve_refund_statuses` 에 들어 있으면 승인까지 자동으로 갑니다.
- 두 분기 상품이 섞이면 422 이므로 화면은 `claimable-options` 의 `refund_branch` 로 상품을 나눠 보냅니다.

**취소신청서는 이 API 가 만들지 않습니다.** 환불이 완료되는 순간 서버가 만듭니다 — 취소신청서는 "주문이 취소된 사실" 이 아니라 **"환불이 집행되어 주문에서 금액이 빠진 사실"** 의 기록이기 때문입니다. 그래서 계좌 환불처럼 승인에서 멈추는 건은 운영자가 송금을 마칠 때까지 취소신청서도 생기지 않습니다. 예외는 **환불할 돈이 없는 주문**(미결제·0원)으로, 환불신청서 없이 취소신청서만 즉시 생깁니다.

자동 전이는 단계를 건너뛰지 않습니다 — 한 트랜잭션 안이라도 `요청 → 승인 → 완료` 를 실제로 거치므로 활동 로그가 단계마다 남습니다. 그 도중 PG 가 환불을 거절하면 **접수까지 전체가 롤백**되고 422 의 `errors.detail` 에 사유가 실립니다.

환불신청서는 원 주문 옵션의 상태를 바꾸지 않는 대신 수량을 선점하므로, 같은 상품을 다시 신청하면 `claim_quantity_locked` 가 됩니다.

---

### POST /api/modules/sirsoft-ecommerce/user/orders/{id}/claims/images
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.orders.claim-images.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.orders.claim-images.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimImageController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.request`

**요청 파라미터** (`multipart/form-data`)

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 주문 ID |
| image | body | file | 예 | `jpeg`·`png`·`jpg`·`gif`·`webp`, 최대 환경설정 `claim.image_max_size_kb` KB | 첨부할 이미지 1장 (여러 장은 반복 호출) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/orders/120/claims/images HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----g7

------g7
Content-Disposition: form-data; name="image"; filename="defect.jpg"
Content-Type: image/jpeg

(binary)
------g7--
```

**응답 필드** (`data` 내부)

[ClaimImageResource](claims.md#claim-image-resource) — `claimable_type` 은 `pending`, `claimable_id` 는 주문 ID.

**응답 예시**

```json
{
    "success": true,
    "message": "이미지를 첨부했습니다.",
    "data": {
        "id": 9,
        "hash": "a1B2c3D4e5F6",
        "claimable_type": "pending",
        "claimable_id": 120,
        "original_name": "defect.jpg",
        "mime_type": "image/jpeg",
        "size": 183204,
        "width": 800,
        "height": 600,
        "download_url": "/api/modules/sirsoft-ecommerce/claim-image/a1B2c3D4e5F6?expires=1787000000&signature=0f3c"
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.request`)이 없는 경우 |
| 404 | Not Found | 주문이 없거나 본인 주문이 아닌 경우 |
| 422 | Unprocessable Entity | 파일 누락·이미지가 아님·크기 초과(검증), 주문당 임시 첨부 장수 초과(`claim_image_limit_exceeded`) |
| 500 | Server Error | 저장 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 신청서를 만들기 전에 이미지를 먼저 올려 두는 2단계 업로드의 1단계입니다(201). 첨부는 주문에 임시로 묶이고(`pending`), 신청 시 `image_ids` 로 넘기면 그 신청서에 귀속됩니다. 귀속 전 첨부는 `claimable-options` 의 `pending_images` 로 다시 받을 수 있고, `DELETE user/claims/images/{image}` 로 지웁니다. 다른 주문에 올린 첨부를 이 주문의 신청에 붙이려 하면 `claim_image_not_found` 로 거절됩니다.
