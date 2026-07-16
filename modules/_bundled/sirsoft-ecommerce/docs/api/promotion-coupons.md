# Promotion Coupons API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Promotion Coupons 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/promotion-coupons
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.promotion-coupons.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.promotion-coupons.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CouponController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.promotion-coupon.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort_by | query | string | 아니오 | `created_at`, `name`, `discount_value`, `issued_count` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| search_field | query | string | 아니오 | `all`, `name`, `description`, `created_by` | 검색 대상 필드명 (검색어를 적용할 컬럼) |
| search_keyword | query | string | 아니오 | max 255 | 검색 키워드 (부분 일치) |
| target_type | query | string | 아니오 | `all`, `product_amount`, `order_amount`, `shipping_fee` | 적용대상(할인 기준) 필터: 상품금액/주문금액/배송비 (`all`=전체) |
| discount_type | query | string | 아니오 | `all`, `fixed`, `rate` | 혜택유형 필터: fixed(정액), rate(정률%) (`all`=전체) |
| issue_status | query | string | 아니오 | `all`, `issuing`, `stopped` | 발급상태 필터: issuing(발급중), stopped(발급중단) (`all`=전체) |
| issue_method | query | string | 아니오 | `all`, `direct`, `download`, `auto` | 발급방법 필터: direct(직접발급), download(다운로드), auto(자동발급) (`all`=전체) |
| issue_condition | query | string | 아니오 | `all`, `manual`, `signup`, `first_purchase`, `birthday` | 발급조건 필터: manual(수동), signup(회원가입), first_purchase(첫구매), birthday(생일) (`all`=전체) |
| min_benefit_amount | query | number | 아니오 | min 0 | 혜택값(할인 금액/율) 하한 필터 |
| max_benefit_amount | query | number | 아니오 | min 0 | 혜택값(할인 금액/율) 상한 필터 |
| min_order_amount | query | number | 아니오 | min 0 | 최소 주문금액 하한 필터 |
| created_start_date | query | date | 아니오 | — | 생성일시 범위 시작 |
| created_end_date | query | date | 아니오 | — | 생성일시 범위 종료 (시작일 이후) |
| valid_start_date | query | date | 아니오 | — | 유효기간 범위 시작 |
| valid_end_date | query | date | 아니오 | — | 유효기간 범위 종료 (시작일 이후) |
| issue_start_date | query | date | 아니오 | — | 발급기간 범위 시작 |
| issue_end_date | query | date | 아니오 | — | 발급기간 범위 종료 (시작일 이후) |
| created_by | query | uuid | 아니오 | — | 등록자(생성한 관리자) UUID 필터 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/promotion-coupons?page=1&per_page=1&sort_by=created_at&sort_order=asc&search_field=all&search_keyword=%EC%98%88%EC%8B%9C%EA%B0%92&target_type=all&discount_type=all&issue_status=all&issue_method=all&issue_condition=all&min_benefit_amount=1&max_benefit_amount=1&min_order_amount=1&created_start_date=2026-01-01&created_end_date=2026-01-01&valid_start_date=2026-01-01&valid_end_date=2026-01-01&issue_start_date=2026-01-01&issue_end_date=2026-01-01&created_by=9f8b2c1a-4d3e-4a2b-8c1d-0e1f2a3b4c5d HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `157` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 쿠폰","en":"API Doc Sample Coupon"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 쿠폰` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| description | object | `{"ko":null,"en":null}` | 설명 (다국어 필드는 로케일별 값 객체) |
| localized_description | string | `설날 특별 무료배송 예정` | `description` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| target_type | string | `order_amount` | 적용대상(할인 기준): product_amount(상품금액), order_amount(주문금액), shipping_fee(배송비) |
| target_type_label | string | `주문금액` | `target_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| target_type_badge_color | string | `blue` | `target_type` 배지 색상 (상품금액=teal, 주문금액=blue, 배송비=orange) |
| discount_type | string | `fixed` | 혜택유형: fixed(정액 금액 할인), rate(정률 % 할인) |
| discount_type_label | string | `정액할인` | `discount_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| discount_value | integer | `1000` | 혜택값 (정액이면 할인 금액, 정률이면 할인율 %). 정액은 기본 통화 자릿수로 정규화 |
| discount_max_amount | integer | `2000` | 최대 할인액 (정률 할인 시 상한 금액, 미설정 시 null) |
| min_order_amount | integer | `0` | 쿠폰 적용 최소 주문금액 (0=제한 없음) |
| benefit_formatted | string | `1,000원 할인` | `benefit` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| multi_currency_discount_value | object | `{"KRW":{"price":1000,"formatted":"1,000원","is_default":tr…` | 정액 할인 금액의 통화별 환산 맵 (정률은 통화 무관이라 null) |
| multi_currency_min_order_amount | object | `{"KRW":{"price":10000,"formatted":"10,000원","is_default":…` | 최소 주문금액의 통화별 환산 맵 (0이면 null) |
| multi_currency_discount_max_amount | object | `{"KRW":{"price":2000,"formatted":"2,000원","is_default":tr…` | 최대 할인액의 통화별 환산 맵 (미설정 시 null) |
| issue_method | string | `download` | 발급방법: direct(직접발급), download(다운로드), auto(자동발급) |
| issue_method_label | string | `다운로드` | `issue_method` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| issue_method_badge_color | string | `teal` | `issue_method` 배지 색상 (직접발급=gray, 다운로드=teal, 자동발급=blue) |
| issue_condition | string | `manual` | 발급조건: manual(수동), signup(회원가입), first_purchase(첫구매), birthday(생일) |
| issue_condition_label | string | `수동발급` | `issue_condition` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| issue_condition_badge_color | string | `orange` | `issue_condition` 배지 색상 (수동=orange, 회원가입=blue, 첫구매=teal, 생일=pink) |
| issue_status | string | `issuing` | 발급상태: issuing(발급중), stopped(발급중단) |
| issue_status_label | string | `발급중` | `issue_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| issue_status_badge_color | string | `blue` | `issue_status` 배지 색상 (발급중=blue, 발급중단=orange) |
| total_quantity | integer | `1` | 총 발급 수량 (null=무제한) |
| issued_count | integer | `0` | issued 개수 (집계) |
| per_user_limit | integer | `1` | 회원 1인당 발급 제한 수량 (0=무제한) |
| issue_count_formatted | string | `0/무제한` | `issue_count` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| valid_type | string | `period` | 유효기간 유형: period(기간 지정), days_from_issue(발급일로부터 N일) |
| valid_days | integer | `1` | 발급일로부터 유효 일수 (valid_type=days_from_issue 일 때) |
| valid_from | string | `2026-06-30` | 유효기간 시작일 (사이트 타임존 기준 날짜 문자열) |
| valid_to | string | `2026-07-30` | 유효기간 종료일 (사이트 타임존 기준 날짜 문자열) |
| valid_period_formatted | string | `-` | `valid_period` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| issue_from | string | `2026-06-30T11:24` | 발급기간 시작 일시 (datetime-local 입력 호환 문자열) |
| issue_to | string | `2026-07-15T11:24` | 발급기간 종료 일시 (datetime-local 입력 호환 문자열) |
| issue_period_formatted | string | `상시발급` | `issue_period` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_combinable | boolean | `false` | combinable 여부 |
| target_scope | string | `all` | 적용 범위: all(전체 상품), products(특정 상품), categories(특정 카테고리) |
| target_scope_label | string | `전체상품` | `target_scope` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_issuable | boolean | `true` | issuable 여부 |
| created_at | string | `2026-07-07 14:47:31` | 생성 일시 |
| updated_at | string | `2026-07-07 14:47:31` | 최종 수정 일시 |
| created_by | string | `a1e0a91a-fba6-491c-a53e-7285a5686857` | 등록자(생성한 관리자) UUID (creator 관계 로드 시) |
| created_by_name | string | `-` | 등록자 이름 (creator 미로드/미설정 시 `-`) |
| created_by_email | string | `heuristing@gmail.com` | 등록자 이메일 (creator 관계 파생) |
| creator | object | `{"uuid":"a1e0a91a-fba6-491c-a53e-7285a5686857","name":"관리자"}` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| issues_count | integer | `0` | issues 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "쿠폰 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "name": {
                    "ko": "API 문서 샘플 쿠폰",
                    "en": "API Doc Sample Coupon"
                },
                "localized_name": "API 문서 샘플 쿠폰",
                "description": null,
                "localized_description": null,
                "target_type": "order_amount",
                "target_type_label": "주문금액",
                "target_type_badge_color": "blue",
                "discount_type": "fixed",
                "discount_type_label": "정액할인",
                "discount_value": 1000,
                "discount_max_amount": null,
                "min_order_amount": 0,
                "benefit_formatted": "1,000원 할인",
                "multi_currency_discount_value": {
                    "KRW": {
                        "price": 1000,
                        "formatted": "1,000원",
                        "is_default": true,
                        "editable": true
                    },
                    "USD": {
                        "price": 0.85,
                        "formatted": "$0.85",
                        "is_default": false,
                        "editable": false,
                        "exchange_rate": 0.85
                    },
                    "JPY": {
                        "price": 115,
                        "formatted": "¥115",
                        "is_default": false,
                        "editable": false,
                        "exchange_rate": 115
                    },
                    "CNY": {
                        "price": 5.8,
                        "formatted": "元5.80",
                        "is_default": false,
                        "editable": false,
                        "exchange_rate": 5.8
                    },
                    "EUR": {
                        "price": 0.78,
                        "formatted": "€0.78",
                        "is_default": false,
                        "editable": false,
                        "exchange_rate": 0.78
                    }
                },
                "multi_currency_min_order_amount": null,
                "multi_currency_discount_max_amount": null,
                "issue_method": "download",
                "issue_method_label": "다운로드",
                "issue_method_badge_color": "teal",
                "issue_condition": "manual",
                "issue_condition_label": "수동발급",
                "issue_condition_badge_color": "orange",
                "issue_status": "issuing",
                "issue_status_label": "발급중",
                "issue_status_badge_color": "blue",
                "total_quantity": null,
                "issued_count": 0,
                "per_user_limit": 1,
                "issue_count_formatted": "0/무제한",
                "valid_type": "period",
                "valid_days": null,
                "valid_from": null,
                "valid_to": null,
                "valid_period_formatted": "-",
                "issue_from": null,
                "issue_to": null,
                "issue_period_formatted": "상시발급",
                "is_combinable": false,
                "target_scope": "all",
                "target_scope_label": "전체상품",
                "is_issuable": true,
                "created_at": "2026-07-08 10:44:49",
                "updated_at": "2026-07-08 10:44:49",
                "created_by": null,
                "created_by_name": "-",
                "created_by_email": null,
                "creator": null,
                "issues_count": 0,
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            }
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        },
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 1,
            "from": 1,
            "to": 1,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.promotion-coupon.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 프로모션 쿠폰(쿠폰 정의/마스터) 목록을 검색·필터·정렬·페이지네이션으로 조회합니다. `permission:sirsoft-ecommerce.promotion-coupon.read` 권한이 필요하며, 이름/설명/생성자 검색, 적용대상·혜택유형·발급상태·발급방법·발급조건 필터, 혜택금액·주문금액·생성/유효/발급 기간 범위 필터를 지원합니다. `CouponService::getCoupons()`가 조회하고 `CouponCollection`으로 직렬화하며, 각 항목은 다국어 라벨·배지 색상·다중통화 혜택값 등 관리자 UI 표시용 파생 필드를 포함합니다.


### POST /api/modules/sirsoft-ecommerce/admin/promotion-coupons
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.promotion-coupons.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.promotion-coupons.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CouponController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.promotion-coupon.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| description | body | array | 아니오 | — | 설명 |
| target_type | body | string | 예 | `product_amount`, `order_amount`, `shipping_fee` | 적용대상(할인 기준): 상품금액/주문금액/배송비 |
| discount_type | body | string | 예 | `fixed`, `rate` | 혜택유형: fixed(정액 금액), rate(정률 %) |
| discount_value | body | number | 예 | min 1 | 혜택값 (정액이면 할인 금액, 정률이면 1~100 할인율 %) |
| discount_max_amount | body | number | 아니오 | min 0 | 최대 할인액 (정률 할인 시 상한 금액) |
| min_order_amount | body | number | 아니오 | min 0 | 쿠폰 적용 최소 주문금액 (미입력 시 0=제한 없음) |
| issue_method | body | string | 예 | `direct`, `download`, `auto` | 발급방법: direct(직접발급), download(다운로드), auto(자동발급) |
| issue_condition | body | string | 예 | `manual`, `signup`, `first_purchase`, `birthday` | 발급조건: manual(수동), signup(회원가입), first_purchase(첫구매), birthday(생일) |
| issue_status | body | string | 예 | `issuing`, `stopped` | 발급상태: issuing(발급중), stopped(발급중단) |
| total_quantity | body | integer | 아니오 | min 1 | 총 발급 수량 (미입력 시 무제한) |
| per_user_limit | body | integer | 예 | min 0 | 회원 1인당 발급 제한 수량 (0=무제한) |
| valid_type | body | string | 예 | `period`, `days_from_issue` | 유효기간 유형: period(기간 지정, valid_from/valid_to 필수), days_from_issue(발급일로부터 N일, valid_days 필수) |
| valid_days | body | integer | 아니오 | min 1 | 발급일로부터 유효 일수 (valid_type=days_from_issue 시 필수) |
| valid_from | body | date | 아니오 | — | 유효기간 시작일 (valid_type=period 시 필수) |
| valid_to | body | date | 아니오 | — | 유효기간 종료일 (valid_type=period 시 필수, valid_from 이후) |
| issue_from | body | date | 아니오 | — | 발급기간 시작 일시 (미입력 시 상시발급) |
| issue_to | body | date | 아니오 | — | 발급기간 종료 일시 (issue_from 이후) |
| is_combinable | body | boolean | 아니오 | — | combinable 여부 |
| target_scope | body | string | 아니오 | `all`, `products`, `categories` | 적용 범위: all(전체 상품), products(특정 상품), categories(특정 카테고리) |
| products | body | array | 아니오 | — | 적용 상품 목록 (`target_scope=products`), 항목별 `{id, type: include\|exclude}` |
| categories | body | array | 아니오 | — | 적용 카테고리 목록 (`target_scope=categories`), 항목별 `{id, type: include\|exclude}` |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.coupon.create_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/promotion-coupons HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "description": [
        "예시 내용입니다."
    ],
    "target_type": "product_amount",
    "discount_type": "fixed",
    "discount_value": 1,
    "discount_max_amount": 1,
    "min_order_amount": 1,
    "issue_method": "direct",
    "issue_condition": "manual",
    "issue_status": "issuing",
    "total_quantity": 1,
    "per_user_limit": 1,
    "valid_type": "period",
    "valid_days": 1,
    "valid_from": "2026-01-01",
    "valid_to": "2026-01-01",
    "issue_from": "2026-01-01",
    "issue_to": "2026-01-01",
    "is_combinable": true,
    "target_scope": "all",
    "products": [
        "예시값"
    ],
    "categories": [
        "예시값"
    ]
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.promotion-coupon.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 새 프로모션 쿠폰(정의)을 생성합니다. `permission:sirsoft-ecommerce.promotion-coupon.create` 권한이 필요하며, 다국어 쿠폰명(`name`), 적용대상·혜택유형·혜택값, 발급방법/조건/상태, 유효기간·발급기간, 회원당 한도, 적용 범위(`target_scope`: all·products·categories)와 그에 따른 상품/카테고리 배열을 받아 `CouponService::createCoupon()`이 저장하고 생성된 쿠폰을 201로 반환합니다. `target_scope` 가 products/categories 일 때만 각 배열이 의미를 가지며, 확장은 `sirsoft-ecommerce.coupon.create_validation_rules` 필터로 검증 규칙을 추가할 수 있습니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/promotion-coupons/bulk-status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.promotion-coupons.bulk-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.promotion-coupons.bulk-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CouponController@bulkUpdateStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.promotion-coupon.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| issue_status | body | string | 예 | `issuing`, `stopped` | 일괄 적용할 발급상태: issuing(발급중), stopped(발급중단) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/promotion-coupons/bulk-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "issue_status": "issuing"
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.promotion-coupon.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 여러 쿠폰의 발급상태(`issue_status`: issuing·stopped)를 한 번에 변경합니다. `permission:sirsoft-ecommerce.promotion-coupon.update` 권한이 필요하고 `ids` 로 대상 쿠폰들을 지정하며, `CouponService::bulkUpdateIssueStatus()`가 일괄 갱신 후 변경된 건수(`updated_count`)를 반환합니다. 쿠폰 목록에서 여러 항목을 선택해 발급을 일괄 중단/재개할 때 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/promotion-coupons/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.promotion-coupons.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.promotion-coupons.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CouponController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.promotion-coupon.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/promotion-coupons/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| coupon_id | integer | `1` | coupon 식별자 (연관 리소스 참조) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "쿠폰이 삭제되었습니다.",
    "data": {
        "coupon_id": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.promotion-coupon.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 프로모션 쿠폰(정의) 1건을 삭제합니다. `permission:sirsoft-ecommerce.promotion-coupon.delete` 권한이 필요하며, `CouponService::deleteCoupon()`이 삭제를 수행합니다(쿠폰 모델은 소프트 삭제 대상). 이미 발급된 내역이 있는 등 도메인 제약으로 삭제가 실패하면 400 오류로 응답합니다.


### GET /api/modules/sirsoft-ecommerce/admin/promotion-coupons/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.promotion-coupons.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.promotion-coupons.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CouponController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.promotion-coupon.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/promotion-coupons/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 쿠폰","en":"API Doc Sample Coupon"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 쿠폰` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| description | null | `null` | 설명 (다국어 필드는 로케일별 값 객체) |
| localized_description | null | `null` | `description` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| target_type | string | `order_amount` | 적용대상: product_amount(상품금액), order_amount(주문금액), shipping_fee(배송비) |
| target_type_label | string | `주문금액` | `target_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| target_type_badge_color | string | `blue` | `target_type` 배지 색상 (상품금액=teal, 주문금액=blue, 배송비=orange) |
| discount_type | string | `fixed` | 혜택유형: fixed(정액), rate(정률) |
| discount_type_label | string | `정액할인` | `discount_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| discount_value | integer | `1000` | 혜택값 (정액: 금액, 정률: %) |
| discount_max_amount | null | `null` | 최대 할인액 (정률 시) |
| min_order_amount | integer | `0` | 최소 주문금액 |
| benefit_formatted | string | `1,000원 할인` | `benefit` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| multi_currency_discount_value | object | `{"KRW":{"price":1000,"formatted":"1,000원","is_default":tr…` | 정액 할인 금액의 통화별 환산 맵 (정률은 통화 무관이라 null) |
| multi_currency_min_order_amount | null | `null` | 최소 주문금액의 통화별 환산 맵 (0이면 null) |
| multi_currency_discount_max_amount | null | `null` | 최대 할인액의 통화별 환산 맵 (미설정 시 null) |
| issue_method | string | `download` | 발급방법: direct(직접발급), download(다운로드), auto(자동발급) |
| issue_method_label | string | `다운로드` | `issue_method` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| issue_method_badge_color | string | `teal` | `issue_method` 배지 색상 (직접발급=gray, 다운로드=teal, 자동발급=blue) |
| issue_condition | string | `manual` | 발급조건: manual(수동), signup(회원가입), first_purchase(첫구매), birthday(생일) |
| issue_condition_label | string | `수동발급` | `issue_condition` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| issue_condition_badge_color | string | `orange` | `issue_condition` 배지 색상 (수동=orange, 회원가입=blue, 첫구매=teal, 생일=pink) |
| issue_status | string | `issuing` | 발급상태: issuing(발급중), stopped(발급중단) |
| issue_status_label | string | `발급중` | `issue_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| issue_status_badge_color | string | `blue` | `issue_status` 배지 색상 (발급중=blue, 발급중단=orange) |
| total_quantity | null | `null` | 총 발급 수량 (NULL=무제한) |
| issued_count | integer | `0` | issued 개수 (집계) |
| per_user_limit | integer | `1` | 회원당 발급 제한 |
| issue_count_formatted | string | `0/무제한` | `issue_count` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| valid_type | string | `period` | 유효기간 유형: period(기간지정), days_from_issue(발급일로부터) |
| valid_days | null | `null` | 발급일로부터 N일 (valid_type=days_from_issue) |
| valid_from | null | `null` | 유효기간 시작 |
| valid_to | null | `null` | 유효기간 종료 |
| valid_period_formatted | string | `-` | `valid_period` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| issue_from | null | `null` | 발급기간 시작 |
| issue_to | null | `null` | 발급기간 종료 |
| issue_period_formatted | string | `상시발급` | `issue_period` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_combinable | boolean | `false` | combinable 여부 |
| target_scope | string | `all` | 적용 범위: all(전체), products(특정상품), categories(특정카테고리) |
| target_scope_label | string | `전체상품` | `target_scope` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_issuable | boolean | `true` | issuable 여부 |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 10:44:49` | 최종 수정 일시 |
| created_by | null | `null` | 쿠폰 등록자(관리자) 식별자 (users 참조, 삭제 시 null) |
| created_by_name | string | `-` | 등록자 이름 (creator 미로드/미설정 시 `-`) |
| created_by_email | null | `null` | 등록자 이메일 (creator 관계 파생) |
| creator | null | `null` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| included_products | array | `[]` | 적용 대상 포함 상품 목록 (target_scope=products 시 이 상품에만 적용) |
| excluded_products | array | `[]` | 적용 제외 상품 목록 (해당 상품은 쿠폰 적용에서 제외) |
| included_categories | array | `[]` | 적용 대상 포함 카테고리 목록 (target_scope=categories 시 이 카테고리에만 적용) |
| excluded_categories | array | `[]` | 적용 제외 카테고리 목록 (해당 카테고리는 쿠폰 적용에서 제외) |
| issues_count | integer | `0` | issues 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "쿠폰 정보를 조회했습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 쿠폰",
            "en": "API Doc Sample Coupon"
        },
        "localized_name": "API 문서 샘플 쿠폰",
        "description": null,
        "localized_description": null,
        "target_type": "order_amount",
        "target_type_label": "주문금액",
        "target_type_badge_color": "blue",
        "discount_type": "fixed",
        "discount_type_label": "정액할인",
        "discount_value": 1000,
        "discount_max_amount": null,
        "min_order_amount": 0,
        "benefit_formatted": "1,000원 할인",
        "multi_currency_discount_value": {
            "KRW": {
                "price": 1000,
                "formatted": "1,000원",
                "is_default": true,
                "editable": true
            },
            "USD": {
                "price": 0.85,
                "formatted": "$0.85",
                "is_default": false,
                "editable": false,
                "exchange_rate": 0.85
            },
            "JPY": {
                "price": 115,
                "formatted": "¥115",
                "is_default": false,
                "editable": false,
                "exchange_rate": 115
            },
            "CNY": {
                "price": 5.8,
                "formatted": "元5.80",
                "is_default": false,
                "editable": false,
                "exchange_rate": 5.8
            },
            "EUR": {
                "price": 0.78,
                "formatted": "€0.78",
                "is_default": false,
                "editable": false,
                "exchange_rate": 0.78
            }
        },
        "multi_currency_min_order_amount": null,
        "multi_currency_discount_max_amount": null,
        "issue_method": "download",
        "issue_method_label": "다운로드",
        "issue_method_badge_color": "teal",
        "issue_condition": "manual",
        "issue_condition_label": "수동발급",
        "issue_condition_badge_color": "orange",
        "issue_status": "issuing",
        "issue_status_label": "발급중",
        "issue_status_badge_color": "blue",
        "total_quantity": null,
        "issued_count": 0,
        "per_user_limit": 1,
        "issue_count_formatted": "0/무제한",
        "valid_type": "period",
        "valid_days": null,
        "valid_from": null,
        "valid_to": null,
        "valid_period_formatted": "-",
        "issue_from": null,
        "issue_to": null,
        "issue_period_formatted": "상시발급",
        "is_combinable": false,
        "target_scope": "all",
        "target_scope_label": "전체상품",
        "is_issuable": true,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 10:44:49",
        "created_by": null,
        "created_by_name": "-",
        "created_by_email": null,
        "creator": null,
        "included_products": [],
        "excluded_products": [],
        "included_categories": [],
        "excluded_categories": [],
        "issues_count": 0,
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.promotion-coupon.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 프로모션 쿠폰 1건의 상세 정보를 조회합니다. `permission:sirsoft-ecommerce.promotion-coupon.read` 권한이 필요하며, `CouponService::getCoupon()`이 쿠폰 정의와 함께 적용 범위(included/excluded products·categories)까지 로드해 `CouponResource`로 반환합니다. 목록(index)보다 상세한 필드(적용 대상 상품/카테고리 목록 등)를 포함하며, 해당 쿠폰이 없으면 404 를 반환합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/promotion-coupons/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.promotion-coupons.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.promotion-coupons.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CouponController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.promotion-coupon.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| description | body | array | 아니오 | — | 설명 |
| target_type | body | string | 예 | `product_amount`, `order_amount`, `shipping_fee` | 적용대상(할인 기준): 상품금액/주문금액/배송비 |
| discount_type | body | string | 예 | `fixed`, `rate` | 혜택유형: fixed(정액 금액), rate(정률 %) |
| discount_value | body | number | 예 | min 1 | 혜택값 (정액이면 할인 금액, 정률이면 1~100 할인율 %) |
| discount_max_amount | body | number | 아니오 | min 0 | 최대 할인액 (정률 할인 시 상한 금액) |
| min_order_amount | body | number | 아니오 | min 0 | 쿠폰 적용 최소 주문금액 (미입력 시 0=제한 없음) |
| issue_method | body | string | 예 | `direct`, `download`, `auto` | 발급방법: direct(직접발급), download(다운로드), auto(자동발급) |
| issue_condition | body | string | 예 | `manual`, `signup`, `first_purchase`, `birthday` | 발급조건: manual(수동), signup(회원가입), first_purchase(첫구매), birthday(생일) |
| issue_status | body | string | 예 | `issuing`, `stopped` | 발급상태: issuing(발급중), stopped(발급중단) |
| total_quantity | body | integer | 아니오 | min 1 | 총 발급 수량 (미입력 시 무제한) |
| per_user_limit | body | integer | 예 | min 0 | 회원 1인당 발급 제한 수량 (0=무제한) |
| valid_type | body | string | 예 | `period`, `days_from_issue` | 유효기간 유형: period(기간 지정, valid_from/valid_to 필수), days_from_issue(발급일로부터 N일, valid_days 필수) |
| valid_days | body | integer | 아니오 | min 1 | 발급일로부터 유효 일수 (valid_type=days_from_issue 시 필수) |
| valid_from | body | date | 아니오 | — | 유효기간 시작일 (valid_type=period 시 필수) |
| valid_to | body | date | 아니오 | — | 유효기간 종료일 (valid_type=period 시 필수, valid_from 이후) |
| issue_from | body | date | 아니오 | — | 발급기간 시작 일시 (미입력 시 상시발급) |
| issue_to | body | date | 아니오 | — | 발급기간 종료 일시 (issue_from 이후) |
| is_combinable | body | boolean | 아니오 | — | combinable 여부 |
| target_scope | body | string | 아니오 | `all`, `products`, `categories` | 적용 범위: all(전체 상품), products(특정 상품), categories(특정 카테고리) |
| products | body | array | 아니오 | — | 적용 상품 목록 (`target_scope=products`), 항목별 `{id, type: include\|exclude}` |
| categories | body | array | 아니오 | — | 적용 카테고리 목록 (`target_scope=categories`), 항목별 `{id, type: include\|exclude}` |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.coupon.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/promotion-coupons/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "description": [
        "예시 내용입니다."
    ],
    "target_type": "product_amount",
    "discount_type": "fixed",
    "discount_value": 1,
    "discount_max_amount": 1,
    "min_order_amount": 1,
    "issue_method": "direct",
    "issue_condition": "manual",
    "issue_status": "issuing",
    "total_quantity": 1,
    "per_user_limit": 1,
    "valid_type": "period",
    "valid_days": 1,
    "valid_from": "2026-01-01",
    "valid_to": "2026-01-01",
    "issue_from": "2026-01-01",
    "issue_to": "2026-01-01",
    "is_combinable": true,
    "target_scope": "all",
    "products": [
        "예시값"
    ],
    "categories": [
        "예시값"
    ]
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.promotion-coupon.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 기존 프로모션 쿠폰(정의)의 내용을 수정합니다. `permission:sirsoft-ecommerce.promotion-coupon.update` 권한이 필요하며, 생성과 동일한 필드 집합(쿠폰명·혜택·발급 조건·유효/발급 기간·적용 범위 등)을 받아 `CouponService::updateCoupon()`이 전체 갱신합니다. `target_scope` 변경 시 그에 맞는 상품/카테고리 배열을 함께 보내야 하며, 대상 쿠폰이 없으면 404, 갱신 실패 시 400 을 반환합니다. 확장은 `sirsoft-ecommerce.coupon.update_validation_rules` 필터로 검증 규칙을 추가할 수 있습니다.


### POST /api/modules/sirsoft-ecommerce/admin/promotion-coupons/{id}/issue-direct
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.promotion-coupons.issue-direct -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.promotion-coupons.issue-direct`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CouponController@issueDirect`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.promotion-coupon.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| user_uuids | body | array | 예 | min 1 | 쿠폰을 직접 발급할 대상 회원 UUID 배열 (내부 회원 ID 로 해석 후 발급) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/promotion-coupons/1/issue-direct HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "user_uuids": [
        "예시값"
    ]
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.promotion-coupon.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 지정한 회원들에게 특정 쿠폰을 즉시 직접 발급합니다. `permission:sirsoft-ecommerce.promotion-coupon.update` 권한이 필요하고 대상 회원은 `user_uuids`(uuid 배열)로 지정하며, FormRequest 가 uuid 를 내부 회원 ID 로 해석한 뒤 `CouponService::issueDirectly()`가 발급합니다. 응답에는 실제 발급 건수(`issued`)와 이미 보유/한도 초과 등으로 건너뛴 목록(`skipped`)이 포함되고, 건너뛴 건이 있으면 별도 안내 메시지 키가 사용됩니다.


### GET /api/modules/sirsoft-ecommerce/admin/promotion-coupons/{id}/issues
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.promotion-coupons.issues -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.promotion-coupons.issues`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CouponController@issues`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.promotion-coupon.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| user_id | query | uuid | 아니오 | — | user 식별자 |
| status | query | string | 아니오 | `available`, `used`, `expired`, `cancelled` | 상태 필터 (해당 상태의 항목만 조회) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.coupon.issues_list_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/promotion-coupons/1/issues?user_id=9f8b2c1a-4d3e-4a2b-8c1d-0e1f2a3b4c5d&status=available&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "쿠폰 발급 내역을 조회했습니다.",
    "data": {
        "data": [],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 0,
            "from": null,
            "to": null,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.promotion-coupon.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 쿠폰의 회원별 발급 내역을 회원(`user_id`)·상태(available·used·expired·cancelled)로 필터링해 페이지네이션으로 조회합니다. `permission:sirsoft-ecommerce.promotion-coupon.read` 권한이 필요하며, `CouponService::getCouponIssues()`가 조회하고 `CouponIssueCollection`으로 직렬화합니다. 어떤 회원이 이 쿠폰을 받아 언제 사용/만료/취소했는지 추적하는 발급 원장 화면에 사용됩니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/promotion-coupons/{id}/issues/{issueId}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.promotion-coupons.issues.cancel -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.promotion-coupons.issues.cancel`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CouponController@cancelIssue`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.promotion-coupon.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| issueId | path | string | 예 | — | 대상 issue의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/promotion-coupons/1/issues/{issueId} HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.promotion-coupon.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 쿠폰의 발급 내역 1건을 취소 처리합니다. 대상은 쿠폰 ID(`id`)와 발급 내역 ID(`issueId`) 조합으로 지정하고 `permission:sirsoft-ecommerce.promotion-coupon.update` 권한이 필요하며, `CouponService::cancelIssue()`가 미사용 발급 건만 취소합니다. 이미 사용된 발급 건 등 취소 불가 사유는 예외 메시지(`detail`)로 관리자에게 그대로 노출되어 400 으로 응답합니다.


