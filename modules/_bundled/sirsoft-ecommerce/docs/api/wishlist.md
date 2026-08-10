# Wishlist API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Wishlist 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/wishlist
<!-- @generated:start:api.modules.sirsoft-ecommerce.wishlist.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.wishlist.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\WishlistController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/wishlist?page=1&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 찜(위시리스트) 레코드 식별자 (DELETE `/wishlist/{id}` 의 `id`) |
| product_id | integer | `10` | 찜한 상품의 식별자 |
| created_at | string | `2026-07-14T09:12:33.000000Z` | 찜한 일시 (목록은 최신순 정렬) |
| product | object | `{ "id": 10, ... }` | 찜한 상품 정보 (`ProductListResource` 형식, 아래 하위 필드) |

`product` 하위 필드 (찜 목록에서는 상품의 브랜드·카테고리·라벨 관계만 로드되므로 옵션/배송정책 관련 필드는 응답에 포함되지 않습니다):

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `10` | 상품 식별자 |
| name | object | `{"ko":"상품명","en":"Product"}` | 상품명 다국어 원본 |
| name_localized | string | `상품명` | 현재 로케일로 해석된 상품명 |
| product_code | string | `P0000001` | 상품 코드 |
| sku | string \| null | `SKU-001` | 재고 관리 코드 |
| thumbnail_url | string \| null | `/storage/products/thumb.jpg` | 대표 썸네일 이미지 URL |
| list_price | number | `30000` | 정가 (기본 통화 기준 반올림) |
| list_price_formatted | string | `30,000원` | 정가 표시용 포맷 문자열 |
| selling_price | number | `24000` | 판매가 (기본 통화 기준 반올림) |
| selling_price_formatted | string | `24,000원` | 판매가 표시용 포맷 문자열 |
| discount_rate | integer | `20` | 정가 대비 할인율(%) |
| multi_currency_list_price | object | `{"KRW":30000,"USD":22.5}` | 통화별 정가 |
| multi_currency_selling_price | object | `{"KRW":24000,"USD":18}` | 통화별 판매가 |
| stock_quantity | integer | `100` | 재고 수량 |
| safe_stock_quantity | integer | `10` | 안전 재고 수량 |
| is_below_safe_stock | boolean | `false` | 재고가 안전 재고 미만인지 여부 |
| sales_status | string | `on_sale` | 판매 상태 값 (`on_sale`/`sold_out`/`stop_sale` 등) |
| sales_status_label | string | `판매중` | 판매 상태 라벨 (다국어) |
| sales_status_variant | string | `success` | 판매 상태 배지 색상 변형 |
| display_status | string | `visible` | 진열 상태 값 |
| display_status_label | string | `진열` | 진열 상태 라벨 (다국어) |
| display_status_variant | string | `success` | 진열 상태 배지 색상 변형 |
| categories | array | `[{"id":3,"name":"의류","is_primary":true}]` | 소속 카테고리 목록 (id/name/is_primary) |
| primary_category | string \| null | `의류` | 대표 카테고리명 |
| categories_with_path | array | `[{"id":3,"path":[...],"path_string":"패션 > 의류","is_primary":true}]` | 카테고리 경로(브레드크럼) 포함 목록 |
| brand_name | string \| null | `나이키` | 브랜드명 (현재 로케일) |
| shipping_policy_id | integer \| null | `1` | 배송 정책 식별자 |
| min_purchase_qty | integer | `1` | 최소 구매 수량 |
| max_purchase_qty | integer \| null | `10` | 최대 구매 수량 (없으면 `null`) |
| has_options | boolean | `true` | 옵션 보유 여부 |
| options | array | `[]` | 옵션 목록 (찜 목록에서는 옵션 관계 미로드로 빈 배열) |
| labels | array | `[{"name":"NEW","color":"#ff0000"}]` | 활성 라벨 목록 (정렬순) |
| review_count | integer | `0` | 리뷰 수 (리뷰 집계 미로드 시 `0`) |
| rating_avg | number | `0.0` | 평균 평점 (리뷰 집계 미로드 시 `0.0`) |
| created_at | string | `2026-07-01 10:00:00` | 상품 등록 일시 (사용자 타임존 기준) |
| updated_at | string | `2026-07-10 15:30:00` | 상품 수정 일시 (사용자 타임존 기준) |
| is_owner | boolean | `false` | 요청자가 상품 등록자(`created_by`)인지 여부 |
| abilities | object | `{"can_update":false,"can_delete":false}` | 상품 수정/삭제 권한 (권한 없으면 키 자체가 생략될 수 있음) |

`data.pagination` 필드:

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| current_page | integer | `1` | 현재 페이지 번호 |
| last_page | integer | `1` | 마지막 페이지 번호 |
| per_page | integer | `20` | 페이지당 항목 수 (기본 20, 최대 100) |
| total | integer | `0` | 전체 찜 항목 수 |
| from | integer \| null | `null` | 현재 페이지 첫 항목의 순번 (없으면 `null`) |
| to | integer \| null | `null` | 현재 페이지 마지막 항목의 순번 (없으면 `null`) |
| has_more_pages | boolean | `false` | 다음 페이지 존재 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "찜 목록을 불러왔습니다.",
    "data": {
        "data": [
            {
                "id": 2,
                "product_id": 1732,
                "created_at": "2026-07-31T12:45:03.000000Z",
                "product": {
                    "id": 1732,
                    "name": {
                        "ko": "면 손수건 3매입 #1",
                        "en": "Cotton Handkerchief 3pcs #1"
                    },
                    "name_localized": "면 손수건 3매입 #1",
                    "product_code": "CJTFHBL8SLRQ8ILM",
                    "sku": "HK-0001",
                    "...": "(32개 키 생략, 총 37개)"
                }
            },
            {
                "id": 1,
                "product_id": 1733,
                "created_at": "2026-07-31T12:44:43.000000Z",
                "product": {
                    "id": 1733,
                    "name": {
                        "ko": "기본 양말 5족 #2",
                        "en": "Basic Socks 5 Pairs #2"
                    },
                    "name_localized": "기본 양말 5족 #2",
                    "product_code": "S0SO3A6SJFYLAKSF",
                    "sku": "SK-0002",
                    "...": "(32개 키 생략, 총 37개)"
                }
            },
            "... (총 25건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 2,
            "per_page": 25,
            "total": 45,
            "from": 1,
            "...": "(2개 키 생략, 총 7개)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | `page` < 1, `per_page` 가 1~100 범위를 벗어나거나 정수가 아닌 경우 |

<!-- @generated:end -->

**요청 파라미터**

| 이름 | 타입 | 필수 | 기본값 | 용도 |
| --- | --- | :---: | --- | --- |
| `page` | integer | - | 1 | 페이지 번호 (최소 1) |
| `per_page` | integer | - | 20 | 페이지당 건수 (1~100). 범위를 벗어나면 422 |

**설명** 로그인한 회원의 찜(위시리스트) 목록을 페이지네이션으로 조회합니다. `auth:sanctum` 인증이 필요하며, `WishlistController@index`가 `ProductWishlistService::getByUser()`로 본인 찜 목록만 가져와 `WishlistCollection`으로 반환합니다. 페이지네이션 파라미터는 `WishlistListRequest` 가 검증하며 `per_page` 는 기본 20건, 허용 범위 1~100건입니다(범위를 벗어나면 422). 소프트 삭제된 상품의 찜 행은 목록에서 제외되므로 `total` 은 찜 테이블의 행 수보다 작을 수 있습니다. 마이페이지의 찜 목록 화면에서 사용합니다.


### POST /api/modules/sirsoft-ecommerce/wishlist/toggle
<!-- @generated:start:api.modules.sirsoft-ecommerce.wishlist.toggle -->
- **라우트명**: `api.modules.sirsoft-ecommerce.wishlist.toggle`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\WishlistController@toggle`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product_id | body | integer | 예 | — | product 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.wishlist.toggle_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/wishlist/toggle HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "product_id": 1
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-422 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 특정 상품(`product_id`)의 찜 상태를 토글합니다. `auth:sanctum` 인증이 필요하며, `WishlistController@toggle`이 `ProductWishlistService::toggle()`을 호출해 이미 찜한 상품이면 제거하고 아니면 추가한 뒤 `added` 불리언을 반환합니다. 상품 상세/목록의 찜 하트 버튼이 이 하나의 엔드포인트로 추가·제거를 모두 처리합니다. 응답의 `added` 값으로 현재 찜 여부를 즉시 갱신할 수 있습니다.


### DELETE /api/modules/sirsoft-ecommerce/wishlist/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.wishlist.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.wishlist.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\WishlistController@destroy`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/wishlist/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
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
    "message": "상품이 찜 목록에서 제거되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 찜 목록에서 특정 찜 항목(`id`)을 삭제합니다. `auth:sanctum` 인증이 필요하며, `WishlistController@destroy`가 `ProductWishlistService::destroy()`로 본인 소유 찜만 삭제합니다. 상품 ID가 아니라 찜 레코드 ID로 삭제하며, 해당 항목이 본인 것이 아니거나 존재하지 않으면 404를 반환합니다. 상품 상세의 하트 토글과 달리 마이페이지 찜 목록에서 특정 항목을 명시적으로 제거할 때 사용합니다.


