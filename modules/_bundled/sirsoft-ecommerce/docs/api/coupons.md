# Coupons API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Coupons 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/user/coupons
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.coupons.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.coupons.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserCouponController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| status | query | string | 아니오 | `available`, `used`, `expired` | 상태 필터 (해당 상태의 항목만 조회) |
| per_page | query | integer | 아니오 | min 1, max 50 | 페이지당 항목 수 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.coupon.user_list_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/coupons?status=available&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| coupons | object | `{"data":[{"id":8611,"coupon_id":156,"user_id":"a1e0a91a-f…` | 회원이 발급받은 쿠폰(발급 내역) 페이지네이션 객체 (`data[]` 발급 건 + `pagination` — CouponIssueCollection 직렬화, 쿠폰 정의가 아닌 회원별 발급 건) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "쿠폰 목록을 조회했습니다.",
    "data": {
        "coupons": {
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
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 로그인한 회원이 마이페이지 쿠폰함에서 자신이 발급받은 쿠폰(발급 내역)을 페이지네이션으로 조회합니다. `auth:sanctum` 인증이 필요하며, `status` 필터(available·used·expired)로 사용 가능/사용 완료/만료 쿠폰을 구분합니다. `UserCouponService::getUserCoupons()`가 조회하고 `CouponIssueCollection`으로 직렬화되므로, 여기의 항목은 쿠폰 정의(마스터)가 아니라 회원별 발급 건입니다.


### GET /api/modules/sirsoft-ecommerce/user/coupons/available
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.coupons.available -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.coupons.available`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserCouponController@available`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product_ids | query | array | 아니오 | — | product 식별자 배열 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.coupon.user_available_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/coupons/available?product_ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| coupons | array | `[{"id":4702,"coupon_id":106,"user_id":1,"coupon_code":nul…` | 현재 장바구니 상품에 적용 가능한 보유 쿠폰(발급 건) 배열 (상품/카테고리 범위·최소 주문금액·유효기간을 만족해 주문에 곧바로 선택 가능한 후보만) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "사용 가능한 쿠폰 목록을 조회했습니다.",
    "data": {
        "coupons": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 체크아웃 화면에서 회원이 현재 장바구니 상품에 실제로 적용할 수 있는 보유 쿠폰만 추려서 반환합니다. `auth:sanctum` 인증이 필요하고 `product_ids` 로 대상 상품을 전달하면, `UserCouponService::getAvailableCoupons()`가 보유 쿠폰 중 상품/카테고리 적용 범위·최소 주문금액·유효기간 등을 만족하는 것만 필터링해 내려줍니다. 쿠폰함 목록(index)과 달리 주문에 곧바로 선택 가능한 후보만 반환하는 점이 다릅니다.


### GET /api/modules/sirsoft-ecommerce/user/coupons/downloadable
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.coupons.downloadable -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.coupons.downloadable`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserCouponController@downloadable`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| per_page | query | integer | 아니오 | min 1, max 50 | 페이지당 항목 수 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.coupon.user_downloadable_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/coupons/downloadable?per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `157` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 쿠폰","en":"API Doc Sample Coupon"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"특정 카테고리 배송비 할인","en":"Shipping discount on categor…` | 설명 (다국어 필드는 로케일별 값 객체) |
| target_type | string | `order_amount` | 할인 적용 대상 (product_amount=상품금액, order_amount=주문금액, shipping_fee=배송비) |
| discount_type | string | `fixed` | 혜택 유형 (fixed=정액 할인, rate=정률(%) 할인) |
| discount_value | string | `1000.00` | 혜택값 (정액이면 할인 금액, 정률이면 할인율 %) |
| discount_max_amount | string | `3000.00` | 정률 할인 시 최대 할인 금액 상한 (없으면 null) |
| min_order_amount | string | `0.00` | 쿠폰 적용 최소 주문금액 (미만 주문에는 사용 불가) |
| issue_method | string | `download` | 발급 방법 (direct=직접발급, download=다운로드, auto=자동발급) |
| issue_condition | string | `manual` | 발급 조건 (manual=수동, signup=회원가입, first_purchase=첫구매, birthday=생일) |
| issue_status | string | `issuing` | 발급 상태 (issuing=발급중, stopped=발급중단) |
| total_quantity | integer | `300` | 총 발급 수량 상한 (null=무제한) |
| issued_count | integer | `0` | issued 개수 (집계) |
| per_user_limit | integer | `1` | 회원 1인당 발급 제한 수량 |
| valid_type | string | `period` | 유효기간 유형 (period=기간지정, days_from_issue=발급일로부터 N일) |
| valid_days | integer | `14` | 발급일로부터 유효한 일수 (valid_type=days_from_issue 인 경우) |
| valid_from | string | `2026-06-08T02:24:18.000000Z` | 유효기간 시작일 (쿠폰 사용 가능 시작 시각) |
| valid_to | string | `2026-08-07T02:24:18.000000Z` | 유효기간 종료일 (쿠폰 사용 가능 종료 시각) |
| issue_from | string | `2026-06-08T02:24:18.000000Z` | 발급기간 시작일 (다운로드 가능 시작 시각) |
| issue_to | string | `2026-07-15T02:24:18.000000Z` | 발급기간 종료일 (다운로드 가능 종료 시각) |
| is_combinable | boolean | `false` | combinable 여부 |
| target_scope | string | `all` | 적용 범위 (all=전체 상품, products=특정 상품, categories=특정 카테고리) |
| created_by | integer | `1` | 쿠폰 등록자(관리자) 식별자 (users 참조, 삭제 시 null) |
| created_at | string | `2026-07-07T05:47:31.000000Z` | 생성 일시 |
| updated_at | string | `2026-07-07T05:47:31.000000Z` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| is_downloaded | boolean | `false` | downloaded 여부 |
| user_issued_count | integer | `0` | user issued 개수 (집계) |
| coupon_id | integer | `157` | coupon 식별자 (연관 리소스 참조) |
| localized_name | string | `API 문서 샘플 쿠폰` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| target_type_short_label | string | `주문` | `target_type_short` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| valid_period_formatted | string | `-` | `valid_period` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| min_order_amount_formatted | string | `0원` | `min_order_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| remaining_quantity | integer | `300` | 잔여 발급 가능 수량 (total_quantity − issued_count, 무제한이면 null) |
| benefit_formatted | string | `1,000원 할인` | `benefit` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| included_products | array | `[]` | 적용 대상 포함 상품 목록 (target_scope=products 시 이 상품에만 적용) |
| excluded_products | array | `[]` | 적용 제외 상품 목록 (해당 상품은 쿠폰 적용에서 제외) |
| included_categories | array | `[]` | 적용 대상 포함 카테고리 목록 (target_scope=categories 시 이 카테고리에만 적용) |
| excluded_categories | array | `[]` | 적용 제외 카테고리 목록 (해당 카테고리는 쿠폰 적용에서 제외) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "다운로드 가능한 쿠폰 목록을 불러왔습니다.",
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "name": {
                    "ko": "API 문서 샘플 쿠폰",
                    "en": "API Doc Sample Coupon"
                },
                "description": null,
                "target_type": "order_amount",
                "discount_type": "fixed",
                "discount_value": "1000.00",
                "discount_max_amount": null,
                "min_order_amount": "0.00",
                "issue_method": "download",
                "issue_condition": "manual",
                "issue_status": "issuing",
                "total_quantity": null,
                "issued_count": 0,
                "per_user_limit": 1,
                "valid_type": "period",
                "valid_days": null,
                "valid_from": null,
                "valid_to": null,
                "issue_from": null,
                "issue_to": null,
                "is_combinable": false,
                "target_scope": "all",
                "created_by": null,
                "created_at": "2026-07-08T01:44:49.000000Z",
                "updated_at": "2026-07-08T01:44:49.000000Z",
                "deleted_at": null,
                "is_downloaded": false,
                "user_issued_count": 0,
                "coupon_id": 1,
                "localized_name": "API 문서 샘플 쿠폰",
                "target_type_short_label": "주문",
                "valid_period_formatted": "-",
                "min_order_amount_formatted": "0원",
                "remaining_quantity": null,
                "benefit_formatted": "1,000원 할인",
                "included_products": [],
                "excluded_products": [],
                "included_categories": [],
                "excluded_categories": []
            }
        ],
        "first_page_url": "https://api.example.com/api/modules/sirsoft-ecommerce/user/coupons/downloadable?page=1",
        "from": 1,
        "last_page": 1,
        "last_page_url": "https://api.example.com/api/modules/sirsoft-ecommerce/user/coupons/downloadable?page=1",
        "links": [
            {
                "url": null,
                "label": "pagination.previous",
                "page": null,
                "active": false
            },
            {
                "url": "https://api.example.com/api/modules/sirsoft-ecommerce/user/coupons/downloadable?page=1",
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
        "next_page_url": null,
        "path": "https://api.example.com/api/modules/sirsoft-ecommerce/user/coupons/downloadable",
        "per_page": 25,
        "prev_page_url": null,
        "to": 1,
        "total": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 로그인한 회원이 지금 다운로드해 발급받을 수 있는 쿠폰(다운로드형) 목록을 페이지네이션으로 조회합니다. `auth:sanctum` 인증이 필요하며, `UserCouponService::getDownloadableCoupons()`가 발급기간·수량·회원당 한도를 만족하는 다운로드형 쿠폰을 반환하고 각 항목에 `is_downloaded`·`user_issued_count`로 이미 받았는지 여부를 표시합니다. 여기 항목은 아직 발급 전이므로 쿠폰 정의(마스터) 기준이며, 실제 발급은 download 엔드포인트로 수행합니다.


### POST /api/modules/sirsoft-ecommerce/user/coupons/{couponId}/download
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.coupons.download -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.coupons.download`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserCouponController@download`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| couponId | path | string | 예 | — | 대상 coupon의 식별자 |
| coupon_id | body | integer | 예 | — | coupon 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/coupons/{couponId}/download HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "coupon_id": 1
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 로그인한 회원이 다운로드형 쿠폰 1건을 실제로 발급받습니다. `auth:sanctum` 인증이 필요하고 대상 쿠폰은 `couponId`(경로)로 지정하며, `UserCouponService::downloadCoupon()`이 발급기간·수량·회원당 한도·중복 발급 여부를 검증한 뒤 발급 내역을 생성해 201로 반환합니다. 한도 초과·발급기간 종료 등 발급 불가 사유는 서비스 예외의 메시지와 코드가 그대로 오류 응답에 전달됩니다.


