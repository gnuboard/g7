# Products API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Products 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---

## 옵션의 무게·부피 (`options[].weight` / `options[].volume`)

옵션 리소스(`ProductOptionResource`)는 배송비 계산에 쓰이는 물성 두 개를 함께 내려보냅니다.

| 필드 | 타입 | 단위 | 설명 |
| --- | --- | --- | --- |
| weight | number\|null | g (그램) | 옵션 1개의 무게. 미입력이면 `null` |
| volume | number\|null | cm³ (세제곱센티미터) | 옵션 1개의 부피. 미입력이면 `null` |

단위는 저장 단위 그대로입니다. 배송정책의 구간·단위값은 kg / L 로 입력받으며, 환산은
배송비를 계산하는 시점에 한 번만 수행됩니다 (`docs/api/shipping-policies.md` 참고).

`null` 과 `0` 은 구분됩니다 — `null` 은 미입력, `0` 은 "무게 없음"입니다. 수정 요청은
전달된 필드만 반영하므로, 편집 화면이 이 값을 받지 못한 채 저장하면 기존 값이 그대로
유지되지만 화면에 `0` 으로 그려 놓고 저장하면 실측값이 0 으로 덮어써집니다.

주문 생성 시 이 값은 주문 옵션(`unit_weight`/`unit_volume`)에 g / cm³ 그대로 복사되고,
주문의 `total_weight`/`total_volume` 은 옵션 소계의 합입니다.

---

## 목록 응답의 총 건수와 페이지 이동

상품 목록(관리자·공개 양쪽)은 총 건수를 상한까지만 셉니다. 상한을 넘으면 응답의
`pagination` 에 정확도가 함께 실립니다 — `total_relation` 이 `at_least`, `total_is_exact`
가 `false`, `result_cap` 이 적용된 상한값입니다. 이때 `last_page` 는 `null` 이고,
`has_more_pages` 는 그대로 정확하므로 마지막 페이지 점프만 감춰지고 다음 페이지 이동은
끝까지 열려 있습니다. 상한 이하이면 종전과 동일하게 `total` 이 정확한 값이고
`total_relation` 은 `exact` 입니다.

같은 규약이 이 모듈의 다른 목록 응답(주문·쿠폰·마일리지·문의·리뷰 등)에도 적용됩니다.
상세 규약은 [pagination.md](../../../../../docs/backend/pagination.md) 를 참고하세요.

## 목록 응답의 리뷰 통계

`review_count` 와 `rating_avg` 는 상품 표에 저장된 값이 아니라 조회 시 함께 계산되는 집계입니다.
따라서 **그 계산을 수행하는 조회에서만 응답에 실립니다.**

| 조회 | 리뷰 통계 |
| --- | --- |
| 공개 상품 목록 · 인기 · 신상품 · 최근 본 상품 · 상품 검색 | 실림 |
| 관리자 상품 목록 | 실리지 않음 (화면이 사용하지 않습니다) |

리뷰가 한 건도 없는 상품은 계산이 수행된 조회에서 `review_count: 0` · `rating_avg: 0.0` 으로
실립니다. 계산하지 않은 조회에서 두 항목이 **아예 빠지는 것**과는 다릅니다 — 값이 0 인 것과
값이 없는 것을 구분해야 하므로, 항목이 없을 때를 0 으로 간주하지 마세요.

---


### GET /api/modules/sirsoft-ecommerce/admin/products
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search_field | query | string | 아니오 | `all`, `name`, `product_code`, `sku`, `barcode` | 검색 대상 필드명 (검색어를 적용할 컬럼) |
| search_keyword | query | string | 아니오 | max 200 | 검색 키워드 (부분 일치) |
| category_id | query | integer | 아니오 | — | category 식별자 |
| no_category | query | boolean | 아니오 | — | 카테고리 미지정 상품만 필터 (true 시 어떤 카테고리에도 속하지 않은 상품 조회) |
| date_type | query | string | 아니오 | — | 기간 필터 기준 날짜 컬럼 (created_at 등록일 / updated_at 수정일) |
| start_date | query | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| end_date | query | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |
| sales_status | query | array | 아니오 | — | 판매상태 다중 필터 (on_sale/suspended/sold_out/coming_soon 값 배열, 해당 상태만 조회) |
| display_status | query | string | 아니오 | — | 전시상태 필터 (visible 전시 / hidden 숨김) |
| brand_id | query | integer | 아니오 | — | brand 식별자 |
| no_brand | query | boolean | 아니오 | — | 브랜드 미지정 상품만 필터 (true 시 브랜드가 없는 상품 조회) |
| tax_status | query | string | 아니오 | — | 과세여부 필터 (taxable 과세 / tax_free 면세) |
| price_type | query | string | 아니오 | — | 가격 범위 필터의 기준 가격 종류 (selling_price 판매가 / supply_price 공급가 / list_price 정가) |
| min_price | query | integer | 아니오 | min 0 | 가격 범위 필터 하한 (price_type 기준 이 값 이상) |
| max_price | query | integer | 아니오 | min 0 | 가격 범위 필터 상한 (price_type 기준 이 값 이하) |
| min_stock | query | integer | 아니오 | — | 재고 범위 필터 하한 (재고 수량이 이 값 이상) |
| max_stock | query | integer | 아니오 | — | 재고 범위 필터 상한 (재고 수량이 이 값 이하) |
| shipping_policy_id | query | integer | 아니오 | — | shipping policy 식별자 |
| with_options | query | boolean | 아니오 | 기본 `false` | 옵션 배열(`options`)을 응답에 포함할지. 기본값에서는 옵션 상세 대신 집계(`options_count`/`options_total_count`/`option_stock_sum`)만 내려갑니다. 화면은 행을 펼칠 때 별도 배치 조회를 씁니다 |
| sort_by | query | string | 아니오 | `created_at`, `updated_at`, `selling_price`, `stock_quantity`, `name` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| per_page | query | integer | 아니오 | min 10, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.list_validation_rules`, `sirsoft-ecommerce.product.list_validation_messages`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/products?search_field=all&search_keyword=%EC%98%88%EC%8B%9C%EA%B0%92&category_id=1&no_category=1&date_type=%EC%98%88%EC%8B%9C%EA%B0%92&start_date=2026-01-01&end_date=2026-01-01&sales_status=%EC%98%88%EC%8B%9C%EA%B0%92&display_status=%EC%98%88%EC%8B%9C%EA%B0%92&brand_id=1&no_brand=1&tax_status=%EC%98%88%EC%8B%9C%EA%B0%92&price_type=%EC%98%88%EC%8B%9C%EA%B0%92&min_price=1&max_price=1&min_stock=1&max_stock=1&shipping_policy_id=1&with_options=1&sort_by=created_at&sort_order=asc&per_page=1&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `100` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `1831` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"겨울 패딩 점퍼 #100","en":"Winter Padded Jacket #100"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `겨울 패딩 점퍼 #100` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| product_code | string | `GGK6A9N8PXNR35OQ` | 상품코드 (상품 고유 관리 식별자) |
| sku | string | `JK-0100` | 재고관리코드(SKU) |
| thumbnail_url | string | `/api/modules/sirsoft-ecommerce/produc…` | thumbnail URL |
| list_price | integer | `200000` | 정가 (기본통화 자릿수로 정규화된 값) |
| list_price_formatted | string | `200,000원` | `list_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| selling_price | integer | `169000` | 판매가 (기본통화 자릿수로 정규화된 값) |
| selling_price_formatted | string | `169,000원` | `selling_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| discount_rate | number | `15.5` | 할인율(%) (정가 대비 판매가 할인 비율, (1 - 판매가/정가) × 100) |
| multi_currency_list_price | object | `{"KRW":{"price":200000,"formatted":"200,000원","is_default…` | 통화별 정가 맵 (통화코드 → {price, formatted, is_default, editable}, 설정된 모든 통화의 환산 정가) |
| multi_currency_selling_price | object | `{"KRW":{"price":169000,"formatted":"169,000원","is_default…` | 통화별 판매가 맵 (통화코드 → {price, formatted, is_default, editable}, 설정된 모든 통화의 환산 판매가) |
| stock_quantity | integer | `213` | 재고 수량 (옵션 사용 시 옵션 재고 합계) |
| safe_stock_quantity | integer | `18` | 안전재고 수량 (이 값 미만이면 재고 부족으로 표시) |
| is_below_safe_stock | boolean | `false` | below safe stock 여부 |
| option_stock_sum | integer | `213` | 활성 옵션의 재고 합계 (is_active 옵션들의 stock_quantity 총합) |
| sales_status | string | `on_sale` | 판매상태 값 (on_sale 판매중 / suspended 판매중지 / sold_out 품절 / coming_soon 출시예정) |
| sales_status_label | string | `판매중` | `sales_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| sales_status_variant | string | `success` | `sales_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| display_status | string | `visible` | 전시상태 값 (visible 전시 / hidden 숨김) |
| display_status_label | string | `전시` | `display_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| display_status_variant | string | `success` | `display_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| categories | array | `[{"id":56,"name":"스포츠","is_primary":0},{"id":57,"name":"축…` | 소속 카테고리 목록 (각 항목: id·현지화 이름·대표 여부. categories 관계 eager load 시에만 채워짐) |
| primary_category | string | `축구` | 대표 카테고리명 (is_primary 카테고리의 현지화 이름) |
| categories_with_path | array | `[{"id":56,"path":[{"id":56,"name":"스포츠","slug":"sports"}]…` | 소속 카테고리 목록 + 경로 (각 항목: id·breadcrumb path·대표 여부) |
| brand_name | string | `유니클로` | 브랜드명 (연관 브랜드의 현지화 이름) |
| shipping_policy_id | integer | `444` | shipping policy 식별자 (연관 리소스 참조) |
| shipping_policy_name | string | `국내 무료배송` | 배송정책명 (연관 배송정책의 현지화 이름) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 (1회 주문 시 이 수량 이상 구매) |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| has_options | boolean | `true` | options 여부 |
| options_count | integer | `9` | options 개수 (집계) |
| options_total_count | integer | `9` | options total 개수 (집계) |
| review_count | integer | `0` | review 개수 (집계) |
| rating_avg | integer | `0` | 평균 별점 (공개 리뷰 별점 평균, 소수 1자리 반올림) |
| created_at | string | `2026-07-30 23:36:17` | 생성 일시 |
| updated_at | string | `2026-08-05 07:27:04` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품 정보를 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 100,
                "id": 1831,
                "name": {
                    "ko": "겨울 패딩 점퍼 #100",
                    "en": "Winter Padded Jacket #100"
                },
                "name_localized": "겨울 패딩 점퍼 #100",
                "product_code": "GGK6A9N8PXNR35OQ",
                "...": "(36개 키 생략, 총 41개)"
            },
            {
                "number": 99,
                "id": 1830,
                "name": {
                    "ko": "무선 마우스 세트 #99",
                    "en": "Wireless Mouse Set #99"
                },
                "name_localized": "무선 마우스 세트 #99",
                "product_code": "J9HTLXFNIJZHI4B1",
                "...": "(36개 키 생략, 총 41개)"
            },
            "... (총 25건 중 2건 표시)"
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        },
        "statistics": {
            "total": 100,
            "sales_status": {
                "coming_soon": 2,
                "on_sale": 97,
                "sold_out": 1
            },
            "display_status": {
                "hidden": 2,
                "visible": 98
            },
            "low_stock_count": 4,
            "out_of_stock_count": 0
        },
        "pagination": {
            "current_page": 1,
            "last_page": 4,
            "per_page": 25,
            "total": 100,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자 상품 목록을 페이지네이션으로 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.products.read` 권한이 필요하며, `ProductService::getList()`가 검색어/카테고리/판매·전시상태/가격·재고 범위 등 다양한 필터를 적용해 목록을 반환하고 `getStatistics()`로 집계 통계를 함께 제공합니다. 응답은 `ProductCollection`으로 감싸져 `withStatistics()`로 통계가 병합됩니다. 확장은 `sirsoft-ecommerce.product.list_validation_rules` 훅으로 추가 필터 파라미터를 주입할 수 있습니다.


### POST /api/modules/sirsoft-ecommerce/admin/products
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| product_code | body | string | 예 | max 50 | 상품코드 (상품 고유 관리 식별자, 상품 간 중복 불가) |
| sales_product_code | body | string | 아니오 | max 50 | 판매자 상품코드 (판매자가 직접 입력하는 관리용 코드) |
| sku | body | string | 아니오 | max 100 | 재고관리코드(SKU) |
| category_ids | body | array | 예 | min 1, max 5 | category 식별자 배열 |
| primary_category_id | body | integer | 아니오 | — | primary category 식별자 |
| brand_id | body | integer | 아니오 | — | brand 식별자 |
| list_price | body | integer | 예 | min 0.01 | 정가 (기본통화 기준, 소수 통화는 소수 입력 허용) |
| selling_price | body | integer | 예 | min 0.01 | 판매가 (기본통화 기준, 정가 이하여야 함) |
| stock_quantity | body | integer | 예 | min 0 | 재고 수량 (옵션 사용 시 옵션 재고 합계로 관리) |
| safe_stock_quantity | body | integer | 아니오 | min 0 | 안전재고 수량 (이 값 미만이면 재고 부족 표시) |
| sales_status | body | string | 예 | — | 판매상태 (on_sale 판매중 / suspended 판매중지 / sold_out 품절 / coming_soon 출시예정) |
| display_status | body | string | 예 | — | 전시상태 (visible 전시 / hidden 숨김) |
| tax_status | body | string | 예 | — | 과세여부 (taxable 과세 / tax_free 면세) |
| tax_rate | body | number | 아니오 | min 0, max 100 | 세율(%) (과세 상품의 부가세 계산 비율) |
| shipping_policy_id | body | integer | 아니오 | — | shipping policy 식별자 |
| common_info_id | body | integer | 아니오 | — | common info 식별자 |
| description | body | array | 아니오 | — | 설명 |
| description_mode | body | string | 아니오 | `text`, `html` | 상세 설명 편집 모드 (text 일반 텍스트 / html HTML 에디터) |
| thumbnail_hash | body | string | 아니오 | max 64 | 대표 이미지로 지정할 이미지 해시 (업로드된 이미지 중 썸네일 선택) |
| image_temp_key | body | string | 아니오 | max 64 | 임시 업로드 세션 키 (사전 업로드한 이미지를 이 상품에 연결) |
| images | body | array | 아니오 | max 20 | 상품 이미지 목록 (각 항목: id/hash/url/alt_text/is_thumbnail/sort_order) |
| meta_title | body | array | 아니오 | — | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목) |
| meta_description | body | array | 아니오 | — | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약) |
| meta_keywords | body | array | 아니오 | — | SEO 메타 키워드 배열 (검색엔진 색인용 키워드 목록) |
| seo_sync_title | body | boolean | 아니오 | — | SEO 제목 자동 동기화 여부 (true 시 상품명으로 메타 제목 자동 채움) |
| seo_sync_description | body | boolean | 아니오 | — | SEO 설명 자동 동기화 여부 (true 시 상품 설명으로 메타 설명 자동 채움) |
| use_main_image_for_og | body | boolean | 아니오 | — | 대표 이미지를 OG(소셜 공유) 이미지로 사용할지 여부 |
| has_options | body | boolean | 아니오 | — | options 여부 |
| option_groups | body | array | 아니오 | — | 옵션 그룹 정의 (예: 색상/사이즈 등 옵션 축과 각 축의 선택값 목록) |
| options | body | array | 예 | min 1 | 옵션(SKU) 목록 (각 항목: 옵션코드·옵션명·옵션값·정가·판매가·재고 등, 최소 1건 필수) |
| additional_options | body | array | 아니오 | max 5 | 추가옵션 그룹 배열 (각 그룹당 선택지 1~20개, 필수 여부·추가금·직접입력 허용 등 설정) |
| notice_items | body | array | 아니오 | max 50 | 상품정보제공고시 항목 배열 (각 항목: 항목명·내용 다국어) |
| label_assignments | body | array | 아니오 | — | 라벨 할당 배열 (label_id + 노출 시작/종료일로 상품에 라벨 부착) |
| min_purchase_qty | body | integer | 아니오 | min 1 | 최소 구매 수량 (1회 주문 시 이 수량 이상 구매) |
| max_purchase_qty | body | integer | 아니오 | min 0 | 최대 구매 수량 (0=무제한) |
| purchase_restriction | body | string | 아니오 | `none`, `restricted` | 구매 대상 제한 (none 제한 없음 / restricted 특정 역할만 구매 허용) |
| allowed_roles | body | array | 아니오 | — | 구매 허용 역할 ID 배열 (purchase_restriction=restricted 시 필수) |
| barcode | body | string | 아니오 | max 50 | 바코드 |
| hs_code | body | string | 아니오 | max 20 | HS 코드 (수출입 관세 분류 코드) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.store_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/products HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "product_code": "예시값",
    "sales_product_code": "예시값",
    "sku": "예시값",
    "category_ids": [
        "예시값"
    ],
    "primary_category_id": 1,
    "brand_id": 1,
    "list_price": 1,
    "selling_price": 1,
    "stock_quantity": 1,
    "safe_stock_quantity": 1,
    "sales_status": "예시값",
    "display_status": "예시값",
    "tax_status": "예시값",
    "tax_rate": 1,
    "shipping_policy_id": 1,
    "common_info_id": 1,
    "description": [
        "예시 내용입니다."
    ],
    "description_mode": "text",
    "thumbnail_hash": "예시값",
    "image_temp_key": "예시값",
    "images": [
        "예시값"
    ],
    "meta_title": [
        "예시 제목"
    ],
    "meta_description": [
        "예시 내용입니다."
    ],
    "meta_keywords": [
        "예시값"
    ],
    "seo_sync_title": true,
    "seo_sync_description": true,
    "use_main_image_for_og": true,
    "has_options": true,
    "option_groups": [
        "예시값"
    ],
    "options": [
        "예시값"
    ],
    "additional_options": [
        "예시값"
    ],
    "notice_items": [
        "예시값"
    ],
    "label_assignments": [
        "예시값"
    ],
    "min_purchase_qty": 1,
    "max_purchase_qty": 1,
    "purchase_restriction": "none",
    "allowed_roles": [
        "예시값"
    ],
    "barcode": "예시값",
    "hs_code": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductResource`). 성공 시 HTTP 201._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 생성된 상품의 기본 키 (내부 식별자) |
| name | object | `{"ko":"샘플 상품","en":"Sample Product"}` | 상품명 (로케일별 값 객체) |
| name_localized | string | `샘플 상품` | `name` 의 현재 로케일 해석 값 |
| product_code | string | `PROD-GJUX-1484` | 상품코드 (상품 고유 관리 식별자) |
| sku | string | `SKU-MRAD-9306` | 재고관리코드(SKU) |
| categories | array | `[]` | 소속 카테고리 목록 (categories 관계 로드 시) |
| category_ids | array | `[1]` | 소속 카테고리 ID 배열 (categories 관계 로드 시) |
| primary_category_id | integer | `1` | 대표 카테고리 ID (is_primary 카테고리) |
| brand_id | integer | `null` | 브랜드 식별자 |
| list_price | integer | `112594` | 정가 (기본통화 자릿수로 정규화) |
| selling_price | integer | `88949` | 판매가 (기본통화 자릿수로 정규화) |
| discount_rate | integer | `21` | 할인율(%) ((1 - 판매가/정가) × 100) |
| stock_quantity | integer | `22` | 재고 수량 (옵션 사용 시 옵션 재고 합계) |
| safe_stock_quantity | integer | `12` | 안전재고 수량 |
| is_below_safe_stock | boolean | `false` | 재고가 안전재고 미만인지 여부 |
| is_stock_consistent | boolean | `true` | 상품 재고와 옵션 재고 합계의 일치 여부 |
| sales_status | string | `on_sale` | 판매상태 (on_sale / suspended / sold_out / coming_soon) |
| sales_status_label | string | `판매중` | `sales_status` 의 현지화 라벨 |
| display_status | string | `visible` | 전시상태 (visible / hidden) |
| display_status_label | string | `전시` | `display_status` 의 현지화 라벨 |
| tax_status | string | `taxable` | 과세여부 (taxable / tax_free) |
| tax_status_label | string | `과세` | `tax_status` 의 현지화 라벨 |
| tax_rate | string | `10.00` | 세율(%) |
| shipping_policy_id | integer | `null` | 배송정책 식별자 |
| common_info_id | integer | `null` | 공통정보 식별자 |
| description | object | `{"ko":"상품 설명","en":"Description"}` | 상세 설명 (로케일별 값 객체) |
| description_localized | string | `상품 설명` | `description` 의 현재 로케일 해석 값 |
| description_mode | string | `text` | 상세 설명 편집 모드 (text / html) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| purchase_restriction | string | `none` | 구매 대상 제한 (none / restricted) |
| allowed_roles | array | `null` | 구매 허용 역할 ID 배열 |
| barcode | string | `null` | 바코드 |
| hs_code | string | `null` | HS 코드 (수출입 관세 분류 코드) |
| images | array | `[]` | 상품 이미지 목록 (images 관계 로드 시) |
| thumbnail_hash | string | `null` | 대표 이미지 해시 |
| thumbnail_url | string | `null` | 대표 이미지 다운로드 URL |
| meta_title | object | `null` | SEO 메타 제목 (다국어 JSON) |
| meta_description | object | `null` | SEO 메타 설명 (다국어 JSON) |
| meta_keywords | array | `null` | SEO 메타 키워드 배열 |
| seo_sync_title | boolean | `true` | SEO 제목 자동 동기화 여부 |
| seo_sync_description | boolean | `true` | SEO 설명 자동 동기화 여부 |
| has_options | boolean | `false` | 옵션 사용 여부 |
| option_groups | array | `[]` | 옵션 그룹 정의 (예: `[{"name":"색상","values":["빨강","파랑"]}]`) |
| options | array | `[]` | 생성된 옵션(SKU) 목록 (`ProductOptionResource`) |
| additional_options | array | `[]` | 추가옵션 그룹 목록. 각 그룹의 `values[]` 는 `id`, `name`, `price_adjustment`(기본 통화 기준 추가금), `price_adjustment_formatted`, `multi_currency_price_adjustment`(통화별 추가금 — 기본 통화가 아닌 통화로 표시할 때 사용), `is_default`, `allow_custom_text` 를 가집니다 |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 10:44:49` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 상품에 수행 가능한 작업 불리언 맵 |

> `categories` / `shipping_policy` / `label_assignments` / `notice_items` 는 해당 관계가 eager load 된 경우에만 포함됩니다 (`whenLoaded`).

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "상품이 등록되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "샘플 상품",
            "en": "Sample Product"
        },
        "name_localized": "샘플 상품",
        "product_code": "PROD-GJUX-1484",
        "sku": "SKU-MRAD-9306",
        "list_price": 112594,
        "selling_price": 88949,
        "discount_rate": 21,
        "stock_quantity": 22,
        "safe_stock_quantity": 12,
        "is_below_safe_stock": false,
        "is_stock_consistent": true,
        "sales_status": "on_sale",
        "sales_status_label": "판매중",
        "display_status": "visible",
        "display_status_label": "전시",
        "tax_status": "taxable",
        "tax_status_label": "과세",
        "tax_rate": "10.00",
        "shipping_policy_id": null,
        "common_info_id": null,
        "description": {
            "ko": "상품 설명",
            "en": "Description"
        },
        "description_localized": "상품 설명",
        "description_mode": "text",
        "min_purchase_qty": 1,
        "max_purchase_qty": 0,
        "purchase_restriction": "none",
        "allowed_roles": null,
        "barcode": null,
        "hs_code": null,
        "thumbnail_hash": null,
        "thumbnail_url": null,
        "meta_title": null,
        "meta_description": null,
        "meta_keywords": null,
        "seo_sync_title": true,
        "seo_sync_description": true,
        "has_options": false,
        "option_groups": [],
        "options": [],
        "additional_options": [],
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 10:44:49",
        "abilities": {
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 새 상품을 생성합니다. `auth:sanctum` + `sirsoft-ecommerce.products.create` 권한이 필요하며, `StoreProductRequest`로 검증된 데이터를 `ProductService::create()`에 넘겨 상품·옵션·카테고리·이미지·SEO 메타를 함께 저장하고 성공 시 201과 `ProductResource`를 반환합니다. 이미지는 사전에 `POST .../products/images`로 임시 업로드한 뒤 `image_temp_key`(또는 `images`/`thumbnail_hash`)로 연결하며, `options`는 최소 1건 필수입니다. 검증 실패는 422, 그 외 오류는 500으로 응답합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/products/bulk-price
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.bulk-price -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.bulk-price`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@bulkUpdatePrice`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| method | body | string | 예 | `increase`, `decrease`, `set` | 변경 방식 (increase 증가 / decrease 감소 / set 지정값으로 설정) |
| value | body | number | 예 | min 0 | 값 |
| unit | body | string | 예 | `won`, `percent` | 변경 단위 (won 금액 단위 / percent 판매가 대비 비율) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.bulk_price_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/products/bulk-price HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "method": "increase",
    "value": 1,
    "unit": "won"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductService::bulkUpdatePrice()` 반환값)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `3` | 실제로 가격이 변경된 상품 건수 |
| requested_count | integer | `3` | 요청한 `ids` 의 개수 (대상으로 지정한 건수) |

> 응답 `message` 의 `:count` 는 `updated_count` 로 치환됩니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "3개 상품의 가격이 수정되었습니다.",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 선택한 여러 상품의 판매가를 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, `ProductService::bulkUpdatePrice()`가 `ids` 목록에 대해 `method`(increase/decrease/set)와 `unit`(won/percent) 조합으로 가격을 재계산해 저장합니다. 응답의 `updated_count`가 실제 반영 건수로 메타에 담기며, 대량 변경은 상품별 개별 활동 로그로 기록됩니다. 확장은 `sirsoft-ecommerce.product.bulk_price_validation_rules` 훅으로 검증 규칙을 확장할 수 있습니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/products/bulk-status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.bulk-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.bulk-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@bulkUpdateStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| field | body | string | 예 | `sales_status`, `display_status` | 일괄 변경할 상태 필드 (sales_status 판매상태 / display_status 전시상태) |
| value | body | string | 예 | — | 값 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.bulk_status_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/products/bulk-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "field": "sales_status",
    "value": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductService::bulkUpdateStatus()` 반환값)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `3` | 실제로 상태가 변경된 상품 건수 |
| requested_count | integer | `3` | 요청한 `ids` 의 개수 (대상으로 지정한 건수) |

> 응답 `message` 의 `:count` 는 `updated_count` 로 치환됩니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "3개 상품이 수정되었습니다.",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 선택한 여러 상품의 상태를 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, `ProductService::bulkUpdateStatus()`가 `field`(sales_status 또는 display_status)를 지정한 `value`로 `ids` 대상에 일괄 적용합니다. 예를 들어 판매중지된 상품을 한 번에 판매중으로 전환하거나 노출/숨김을 일괄 조정할 때 사용하며, 반영 건수는 `updated_count`로 반환됩니다. 확장은 `sirsoft-ecommerce.product.bulk_status_validation_rules` 훅으로 검증 규칙을 확장할 수 있습니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/products/bulk-stock
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.bulk-stock -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.bulk-stock`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@bulkUpdateStock`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| method | body | string | 예 | `increase`, `decrease`, `set` | 변경 방식 (increase 증가 / decrease 감소 / set 지정값으로 설정) |
| value | body | integer | 예 | min 0 | 값 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.bulk_stock_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/products/bulk-stock HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "method": "increase",
    "value": 1
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductService::bulkUpdateStock()` 반환값)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `3` | 실제로 재고가 변경된 상품 건수 |
| requested_count | integer | `3` | 요청한 `ids` 의 개수 (대상으로 지정한 건수) |

> 응답 `message` 의 `:count` 는 `updated_count` 로 치환됩니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "3개 상품의 재고가 수정되었습니다.",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 선택한 여러 상품의 재고를 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, `ProductService::bulkUpdateStock()`가 `ids` 목록에 대해 `method`(increase/decrease/set)와 정수 `value`를 적용해 재고 수량을 조정합니다. 입고/재고 실사 반영 등 다건 재고 보정에 사용하며, 반영 건수는 `updated_count`로 반환됩니다. 확장은 `sirsoft-ecommerce.product.bulk_stock_validation_rules` 훅으로 검증 규칙을 확장할 수 있습니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/products/bulk-update
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.bulk-update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.bulk-update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@bulkUpdate`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| bulk_changes | body | array | 아니오 | — | 상품 조건 기반 일괄 변경값 (지정 시 ids 전체에 sales_status/display_status 일괄 적용) |
| bulk_changes.sales_status | body | string | 아니오 | — | `ids` 전체에 일괄 적용할 판매상태 (지정 시 items 의 개별 sales_status 보다 우선) |
| bulk_changes.display_status | body | string | 아니오 | — | `ids` 전체에 일괄 적용할 전시상태 (지정 시 items 의 개별 display_status 보다 우선) |
| items | body | array | 아니오 | — | 처리 대상 항목 배열 (각 항목: id + 개별 수정할 상품 필드 — name/list_price/selling_price/sales_status/display_status. bulk_changes 로 지정된 필드는 제외됨) |
| option_bulk_changes | body | array | 아니오 | — | 옵션 조건 기반 일괄 변경값 (price_adjustment/stock_quantity 를 method+value 로 일괄 조정) |
| option_bulk_changes.price_adjustment | body | array | 아니오 | — | 대상 상품들의 모든 옵션에 적용할 옵션 추가금 일괄 변경 블록 (method + value 쌍) |
| option_bulk_changes.price_adjustment.method | body | string | 아니오 | `set`, `add`, `percent` | 옵션 추가금 변경 방식 (set 지정값으로 설정 / add 기존값에 가산 / percent 비율 적용) |
| option_bulk_changes.price_adjustment.value | body | number | 아니오 | — | 옵션 추가금 변경 값 (method 에 따라 설정값·가산값·비율로 해석) |
| option_bulk_changes.stock_quantity | body | array | 아니오 | — | 대상 상품들의 모든 옵션에 적용할 옵션 재고 일괄 변경 블록 (method + value 쌍) |
| option_bulk_changes.stock_quantity.method | body | string | 아니오 | `set`, `add`, `subtract` | 옵션 재고 변경 방식 (set 지정값으로 설정 / add 증가 / subtract 감소) |
| option_bulk_changes.stock_quantity.value | body | integer | 아니오 | min 0 | 옵션 재고 변경 수량 (method 에 따라 설정 수량·증감 수량으로 해석) |
| option_items | body | array | 아니오 | — | 옵션 개별 인라인 수정 배열 (각 항목: product_id·option_id + 수정할 옵션 필드) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.bulk_update_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/products/bulk-update HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "bulk_changes": [
        "예시값"
    ],
    "bulk_changes.sales_status": "예시값",
    "bulk_changes.display_status": "예시값",
    "items": [
        "예시값"
    ],
    "option_bulk_changes": [
        "예시값"
    ],
    "option_bulk_changes.price_adjustment": [
        "예시값"
    ],
    "option_bulk_changes.price_adjustment.method": "set",
    "option_bulk_changes.price_adjustment.value": 1,
    "option_bulk_changes.stock_quantity": [
        "예시값"
    ],
    "option_bulk_changes.stock_quantity.method": "set",
    "option_bulk_changes.stock_quantity.value": 1,
    "option_items": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductService::bulkUpdate()` 반환값)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| products_updated | integer | `3` | 반영된 상품 건수 (bulk_changes 적용 건수 + items 개별 수정 건수) |
| options_updated | integer | `5` | 반영된 옵션 건수 (`ProductOptionService::bulkUpdate()` 결과) |

> 응답 `message` 의 `:count` 는 `products_updated + options_updated` 합산값으로 치환됩니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "8개 상품이 수정되었습니다.",
    "data": {
        "products_updated": 3,
        "options_updated": 5
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 상품과 옵션을 통합 일괄 수정합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, `ProductService::bulkUpdate()`가 조건 기반 일괄 변경(`bulk_changes`/`option_bulk_changes`)과 행별 인라인 수정(`items`/`option_items`)을 함께 처리합니다. 일괄 변경 조건이 지정된 필드는 우선 적용되고 나머지는 개별 수정값이 반영되며, 응답 메타의 `count`는 상품 반영 건수와 옵션 반영 건수를 합산한 값입니다. 관리자 목록 화면의 인라인 편집·일괄 편집을 한 요청으로 저장하는 데 사용됩니다.


### GET /api/modules/sirsoft-ecommerce/admin/products/by-code/{code}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.show-by-code -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.show-by-code`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@showByCode`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| code | path | string | 예 | — | 대상 리소스의 코드 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/products/by-code/{code} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| (ProductResource) | object | `{"id":201,"product_code":"P-201", …}` | 상품 상세 1건. 필드 구성은 `GET /admin/products/{identifier}` 와 동일하며, 목록·옵션·이미지·상품고시·공통정보·추가옵션을 포함합니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품을 조회했습니다.",
    "data": {
        "id": 201,
        "product_code": "P-201",
        "name": { "ko": "티셔츠", "en": "T-Shirt" },
        "selling_price": 19000,
        "selling_price_formatted": "19,000원",
        "sales_status": "on_sale",
        "display_status": "visible",
        "options": [],
        "images": [],
        "additional_options": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 상품코드로 단일 상품의 상세 정보를 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.products.read` 권한이 필요하며, `ProductService::findByCode()`로 `code`에 해당하는 상품을 찾아 `ProductResource`로 반환합니다. ID가 아닌 판매/관리용 상품코드로 상세를 열람할 때 사용하며, 일치하는 상품이 없으면 404를 반환합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/products/by-code/{code}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.update-by-code -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.update-by-code`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@updateByCode`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| code | path | string | 예 | — | 대상 리소스의 코드 |
| name | body | array | 아니오 | — | 대상의 이름/명칭 |
| product_code | body | string | 예 | max 50 | 상품코드 (상품 고유 관리 식별자, 상품 간 중복 불가) |
| sales_product_code | body | string | 아니오 | max 50 | 판매자 상품코드 (판매자가 직접 입력하는 관리용 코드) |
| sku | body | string | 아니오 | max 100 | 재고관리코드(SKU) |
| category_ids | body | array | 아니오 | min 1, max 5 | category 식별자 배열 |
| primary_category_id | body | integer | 아니오 | — | primary category 식별자 |
| brand_id | body | integer | 아니오 | — | brand 식별자 |
| list_price | body | integer | 아니오 | min 0.01 | 정가 (기본통화 기준, 소수 통화는 소수 입력 허용) |
| selling_price | body | integer | 아니오 | min 0.01 | 판매가 (기본통화 기준, 정가 이하여야 함) |
| stock_quantity | body | integer | 아니오 | min 0 | 재고 수량 (옵션 사용 시 옵션 재고 합계로 관리) |
| safe_stock_quantity | body | integer | 아니오 | min 0 | 안전재고 수량 (이 값 미만이면 재고 부족 표시) |
| sales_status | body | string | 아니오 | — | 판매상태 (on_sale 판매중 / suspended 판매중지 / sold_out 품절 / coming_soon 출시예정) |
| display_status | body | string | 아니오 | — | 전시상태 (visible 전시 / hidden 숨김) |
| tax_status | body | string | 아니오 | — | 과세여부 (taxable 과세 / tax_free 면세) |
| tax_rate | body | number | 아니오 | min 0, max 100 | 세율(%) (과세 상품의 부가세 계산 비율) |
| shipping_policy_id | body | integer | 아니오 | — | shipping policy 식별자 |
| common_info_id | body | integer | 아니오 | — | common info 식별자 |
| description | body | array | 아니오 | — | 설명 |
| description_mode | body | string | 아니오 | `text`, `html` | 상세 설명 편집 모드 (text 일반 텍스트 / html HTML 에디터) |
| thumbnail_hash | body | string | 아니오 | max 64 | 대표 이미지로 지정할 이미지 해시 (업로드된 이미지 중 썸네일 선택) |
| image_temp_key | body | string | 아니오 | max 64 | 임시 업로드 세션 키 (사전 업로드한 이미지를 이 상품에 연결) |
| images | body | array | 아니오 | max 20 | 상품 이미지 목록 (각 항목: id/hash/url/alt_text/is_thumbnail/sort_order) |
| meta_title | body | array | 아니오 | — | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목) |
| meta_description | body | array | 아니오 | — | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약) |
| meta_keywords | body | array | 아니오 | — | SEO 메타 키워드 배열 (검색엔진 색인용 키워드 목록) |
| seo_sync_title | body | boolean | 아니오 | — | SEO 제목 자동 동기화 여부 (true 시 상품명으로 메타 제목 자동 채움) |
| seo_sync_description | body | boolean | 아니오 | — | SEO 설명 자동 동기화 여부 (true 시 상품 설명으로 메타 설명 자동 채움) |
| use_main_image_for_og | body | boolean | 아니오 | — | 대표 이미지를 OG(소셜 공유) 이미지로 사용할지 여부 |
| has_options | body | boolean | 아니오 | — | options 여부 |
| option_groups | body | array | 아니오 | — | 옵션 그룹 정의 (예: 색상/사이즈 등 옵션 축과 각 축의 선택값 목록) |
| options | body | array | 아니오 | min 1 | 옵션(SKU) 목록 (각 항목: 옵션코드·옵션명·옵션값·정가·판매가·재고 등) |
| additional_options | body | array | 아니오 | max 5 | 추가옵션 그룹 배열 (각 그룹당 선택지 1~20개, 필수 여부·추가금·직접입력 허용 등 설정) |
| notice_items | body | array | 아니오 | max 50 | 상품정보제공고시 항목 배열 (각 항목: 항목명·내용 다국어) |
| label_assignments | body | array | 아니오 | — | 라벨 할당 배열 (label_id + 노출 시작/종료일로 상품에 라벨 부착) |
| min_purchase_qty | body | integer | 아니오 | min 1 | 최소 구매 수량 (1회 주문 시 이 수량 이상 구매) |
| max_purchase_qty | body | integer | 아니오 | min 0 | 최대 구매 수량 (0=무제한) |
| purchase_restriction | body | string | 아니오 | `none`, `restricted` | 구매 대상 제한 (none 제한 없음 / restricted 특정 역할만 구매 허용) |
| allowed_roles | body | array | 아니오 | — | 구매 허용 역할 ID 배열 (purchase_restriction=restricted 시 필수) |
| barcode | body | string | 아니오 | max 50 | 바코드 |
| hs_code | body | string | 아니오 | max 20 | HS 코드 (수출입 관세 분류 코드) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/products/by-code/{code} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "product_code": "예시값",
    "sales_product_code": "예시값",
    "sku": "예시값",
    "category_ids": [
        "예시값"
    ],
    "primary_category_id": 1,
    "brand_id": 1,
    "list_price": 1,
    "selling_price": 1,
    "stock_quantity": 1,
    "safe_stock_quantity": 1,
    "sales_status": "예시값",
    "display_status": "예시값",
    "tax_status": "예시값",
    "tax_rate": 1,
    "shipping_policy_id": 1,
    "common_info_id": 1,
    "description": [
        "예시 내용입니다."
    ],
    "description_mode": "text",
    "thumbnail_hash": "예시값",
    "image_temp_key": "예시값",
    "images": [
        "예시값"
    ],
    "meta_title": [
        "예시 제목"
    ],
    "meta_description": [
        "예시 내용입니다."
    ],
    "meta_keywords": [
        "예시값"
    ],
    "seo_sync_title": true,
    "seo_sync_description": true,
    "use_main_image_for_og": true,
    "has_options": true,
    "option_groups": [
        "예시값"
    ],
    "options": [
        "예시값"
    ],
    "additional_options": [
        "예시값"
    ],
    "notice_items": [
        "예시값"
    ],
    "label_assignments": [
        "예시값"
    ],
    "min_purchase_qty": 1,
    "max_purchase_qty": 1,
    "purchase_restriction": "none",
    "allowed_roles": [
        "예시값"
    ],
    "barcode": "예시값",
    "hs_code": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductResource` — 수정 후 상품 전체). 필드 구성은 `GET /admin/products/by-code/{code}` 의 응답 필드 표와 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 수정된 상품의 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 상품","en":"API Doc Sample Product"}` | 상품명 (로케일별 값 객체) |
| name_localized | string | `API 문서 샘플 상품` | `name` 의 현재 로케일 해석 값 |
| product_code | string | `APIDOCSAMPLE01` | 상품코드 |
| sku | string | `SKU-DKOK-1319` | 재고관리코드(SKU) |
| categories | array | `[]` | 소속 카테고리 목록 (categories 관계 로드 시) |
| category_ids | array | `[]` | 소속 카테고리 ID 배열 |
| primary_category_id | integer | `null` | 대표 카테고리 ID |
| brand_id | integer | `null` | 브랜드 식별자 |
| list_price | integer | `703155` | 정가 (기본통화 기준) |
| selling_price | integer | `597682` | 판매가 (기본통화 기준) |
| discount_rate | integer | `15` | 할인율(%) |
| stock_quantity | integer | `509` | 재고 수량 |
| safe_stock_quantity | integer | `38` | 안전재고 수량 |
| is_below_safe_stock | boolean | `false` | 재고가 안전재고 미만인지 여부 |
| is_stock_consistent | boolean | `true` | 상품 재고와 옵션 재고 합계 일치 여부 |
| sales_status | string | `on_sale` | 판매상태 (on_sale / suspended / sold_out / coming_soon) |
| sales_status_label | string | `판매중` | `sales_status` 의 현지화 라벨 |
| display_status | string | `visible` | 전시상태 (visible / hidden) |
| display_status_label | string | `전시` | `display_status` 의 현지화 라벨 |
| tax_status | string | `taxable` | 과세여부 (taxable / tax_free) |
| tax_status_label | string | `과세` | `tax_status` 의 현지화 라벨 |
| tax_rate | string | `10.00` | 세율(%) |
| shipping_policy_id | integer | `null` | 배송정책 식별자 |
| common_info_id | integer | `null` | 공통정보 식별자 |
| description | object | `{"ko":"상품 설명","en":"Description"}` | 상세 설명 (로케일별 값 객체) |
| description_localized | string | `상품 설명` | `description` 의 현재 로케일 해석 값 |
| description_mode | string | `text` | 상세 설명 편집 모드 (text / html) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| purchase_restriction | string | `none` | 구매 대상 제한 (none / restricted) |
| allowed_roles | array | `null` | 구매 허용 역할 ID 배열 |
| barcode | string | `null` | 바코드 |
| hs_code | string | `null` | HS 코드 |
| images | array | `[]` | 상품 이미지 목록 (images 관계 로드 시) |
| thumbnail_hash | string | `null` | 대표 이미지 해시 |
| thumbnail_url | string | `null` | 대표 이미지 다운로드 URL |
| meta_title | object | `null` | SEO 메타 제목 (다국어 JSON) |
| meta_description | object | `null` | SEO 메타 설명 (다국어 JSON) |
| meta_keywords | array | `null` | SEO 메타 키워드 배열 |
| seo_sync_title | boolean | `true` | SEO 제목 자동 동기화 여부 |
| seo_sync_description | boolean | `true` | SEO 설명 자동 동기화 여부 |
| has_options | boolean | `false` | 옵션 사용 여부 |
| option_groups | array | `[]` | 옵션 그룹 정의 |
| options | array | `[]` | 옵션(SKU) 목록 (`ProductOptionResource`) |
| additional_options | array | `[]` | 추가옵션 그룹 목록. 각 그룹의 `values[]` 는 `id`, `name`, `price_adjustment`(기본 통화 기준 추가금), `price_adjustment_formatted`, `multi_currency_price_adjustment`(통화별 추가금 — 기본 통화가 아닌 통화로 표시할 때 사용), `is_default`, `allow_custom_text` 를 가집니다 |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 11:02:13` | 최종 수정 일시 (이번 수정 시각으로 갱신) |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 상품에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품이 수정되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 상품",
            "en": "API Doc Sample Product"
        },
        "name_localized": "API 문서 샘플 상품",
        "product_code": "APIDOCSAMPLE01",
        "sku": "SKU-DKOK-1319",
        "category_ids": [],
        "primary_category_id": null,
        "brand_id": null,
        "list_price": 703155,
        "selling_price": 597682,
        "discount_rate": 15,
        "stock_quantity": 509,
        "safe_stock_quantity": 38,
        "is_below_safe_stock": false,
        "is_stock_consistent": true,
        "sales_status": "on_sale",
        "sales_status_label": "판매중",
        "display_status": "visible",
        "display_status_label": "전시",
        "tax_status": "taxable",
        "tax_status_label": "과세",
        "tax_rate": "10.00",
        "shipping_policy_id": null,
        "common_info_id": null,
        "description_mode": "text",
        "min_purchase_qty": 1,
        "max_purchase_qty": 0,
        "purchase_restriction": "none",
        "allowed_roles": null,
        "barcode": null,
        "hs_code": null,
        "thumbnail_hash": null,
        "thumbnail_url": null,
        "meta_title": null,
        "meta_description": null,
        "meta_keywords": null,
        "seo_sync_title": true,
        "seo_sync_description": true,
        "has_options": false,
        "option_groups": [],
        "options": [],
        "additional_options": [],
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 11:02:13",
        "abilities": {
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 상품코드로 기존 상품을 수정합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, `ProductService::findByCode()`로 `code` 대상 상품을 찾은 뒤 `UpdateProductRequest`로 검증된 값을 `ProductService::update()`에 넘겨 상품·옵션·이미지·SEO 등을 갱신하고 `ProductResource`를 반환합니다. `{product}` ID 경로 대신 상품코드 기반으로 수정할 때 사용하며, 대상 상품이 없으면 404, 검증 실패는 422로 응답합니다.


### POST /api/modules/sirsoft-ecommerce/admin/products/generate-code
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.generate-code -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.generate-code`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@generateCode`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.create`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/products/generate-code HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| product_code | string | `PROD-GJUX-1484` | `ProductService::generateUniqueCode()` 가 발급한, 기존 상품과 중복되지 않는 신규 상품코드 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품코드가 생성되었습니다.",
    "data": {
        "product_code": "PROD-GJUX-1484"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.create`)이 없는 경우 |

<!-- @generated:end -->

**설명** 중복되지 않는 신규 상품코드를 생성해 반환합니다. `auth:sanctum` + `sirsoft-ecommerce.products.create` 권한이 필요하며, `ProductService::generateUniqueCode()`가 기존 상품과 충돌하지 않는 코드를 발급해 `product_code` 필드로 응답합니다. 상품 등록 폼에서 코드 자동 채움 버튼을 눌렀을 때 사용하며, 요청 본문은 없습니다.


### POST /api/modules/sirsoft-ecommerce/admin/products/images
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.images.upload-temp -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.images.upload-temp`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@uploadImage`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 10240 | 업로드 파일 |
| temp_key | body | string | 아니오 | max 64 | 임시 업로드 세션 키 (같은 상품의 여러 이미지를 한 세션으로 묶음, 생략 시 서버가 UUID 자동 발급) |
| collection | body | string | 아니오 | — | 첨부 컬렉션 그룹명 (첨부를 용도별로 묶는 키, 미지정 시 default) |
| alt_text | body | array | 아니오 | — | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) |
| alt_text.ko | body | string | 아니오 | max 255 | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) — `ko` 로케일 값 |
| alt_text.en | body | string | 아니오 | max 255 | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) — `en` 로케일 값 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product-image.filter_upload_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/products/images HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary
Content-Disposition: form-data; name="temp_key"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="collection"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="alt_text"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="alt_text.ko"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="alt_text.en"

예시값
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. FileUploader 컴포넌트 규약에 맞춰 실제 이미지 정보는 `data.data` 로 한 단계 더 감싸져 있습니다. 성공 시 HTTP 201._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | object | `{ ... }` | 업로드된 이미지 정보 객체 (아래 하위 필드) |
| data.id | integer | `1` | 업로드된 이미지 레코드의 기본 키 |
| data.hash | string | `3f7a1c9e…` | 이미지 해시 (상품 생성/수정 시 `thumbnail_hash` 지정에 사용) |
| data.original_filename | string | `product-main.jpg` | 업로드한 원본 파일명 |
| data.mime_type | string | `image/jpeg` | 파일 MIME 타입 |
| data.size | integer | `204800` | 파일 크기 (바이트) |
| data.size_formatted | string | `200 KB` | 사람이 읽는 파일 크기 문자열 (B/KB/MB/GB) |
| data.download_url | string | `/api/modules/sirsoft-ecommerce/products/images/3f7a1c9e/download` | 이미지 다운로드/표시 URL |
| data.order | integer | `1` | 이미지 노출 순서 (`sort_order`, 미지정 시 1) |
| data.is_image | boolean | `true` | MIME 타입이 `image/` 로 시작하는지 여부 |
| data.is_thumbnail | boolean | `true` | 대표 이미지 여부 (컬렉션의 첫 이미지는 자동 지정) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "이미지가 업로드되었습니다.",
    "data": {
        "data": {
            "id": 1,
            "hash": "3f7a1c9e",
            "original_filename": "product-main.jpg",
            "mime_type": "image/jpeg",
            "size": 204800,
            "size_formatted": "200 KB",
            "download_url": "/api/modules/sirsoft-ecommerce/products/images/3f7a1c9e/download",
            "order": 1,
            "is_image": true,
            "is_thumbnail": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 파일 저장 등 업로드 처리 중 오류가 발생한 경우 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) 또는 상품 이미지 개수 상한 초과 (`ProductImageUploadLimitException`) |
| 500 | Internal Server Error | 서버 내부 오류 — 도메인 규칙 위반이 아닌 예외(인프라 장애·코드 결함)는 4xx 로 뭉개지 않고 500 으로 구분한다 |

<!-- @generated:end -->

**설명** 상품 등록 전 이미지를 임시로 업로드합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, `productId` 경로가 없어 `ProductImageService::upload()`가 상품에 귀속되지 않은 임시 이미지로 저장합니다. `temp_key`를 넘기면 같은 업로드 세션으로 묶이고 생략 시 서버가 UUID를 자동 발급해 응답에 포함하므로, 이후 상품 생성/수정 요청의 `image_temp_key`로 전달해 실제 상품에 연결합니다. 컬렉션 내 첫 이미지는 자동으로 대표 이미지(`is_thumbnail`)로 지정되며, 개수 상한 초과 시 422를 반환합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/products/images/reorder
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.images.reorder -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.images.reorder`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@reorderImages`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | body | array | 예 | min 1 | 이미지 순서 배열 (각 항목: id + 부여할 order 값, 이미지별 노출 순서 갱신) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product-image.filter_reorder_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/products/images/reorder HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "order": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 데이터를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "이미지 순서가 변경되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 순서 갱신 처리 중 오류가 발생한 경우 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 서버 내부 오류 — 도메인 규칙 위반이 아닌 예외(인프라 장애·코드 결함)는 4xx 로 뭉개지 않고 500 으로 구분한다 |

<!-- @generated:end -->

**설명** 상품 이미지의 노출 순서를 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, 컨트롤러가 `order` 배열을 `id => order` 맵으로 변환해 `ProductImageService::reorder()`에 넘겨 각 이미지의 `sort_order`를 갱신합니다. 이미지 갤러리에서 드래그로 순서를 재배치한 결과를 저장할 때 사용합니다. 확장은 `sirsoft-ecommerce.product-image.filter_reorder_validation_rules` 훅으로 검증 규칙을 확장할 수 있습니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/products/images/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.images.delete -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.images.delete`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@deleteImage`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/products/images/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 데이터를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "이미지가 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 삭제 처리 중 오류가 발생한 경우 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 404 | Not Found | `id` 에 해당하는 이미지가 없는 경우 (`messages.product_images.not_found`) |
| 500 | Internal Server Error | 서버 내부 오류 — 도메인 규칙 위반이 아닌 예외(인프라 장애·코드 결함)는 4xx 로 뭉개지 않고 500 으로 구분한다 |

<!-- @generated:end -->

**설명** 상품 이미지 1건을 삭제합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, `ProductImageService::delete()`가 `id`에 해당하는 이미지 레코드와 저장 파일을 제거합니다. 임시 업로드 이미지와 상품에 귀속된 이미지 모두 삭제할 수 있으며, 해당 이미지가 없으면 404를 반환합니다.


### GET /api/modules/sirsoft-ecommerce/admin/products/options
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.options -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.options`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@options`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product_ids | query | array | 예 | min 1, max 100 | product 식별자 배열 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/products/options?product_ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| options | object | `{"201":[{"id":1086, …}]}` | 상품 ID(문자열 키) ⇒ 해당 상품의 옵션 목록(ProductOptionResource 배열). 조회 결과가 없어도 JSON 객체(`{}`)로 나갑니다 |
| product_ids | array | `[201, 202]` | 요청한 상품 ID 목록 (응답의 `options` 키 순서 기준) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품 옵션을 조회했습니다.",
    "data": {
        "options": {
            "201": [
                {
                    "id": 1086,
                    "option_code": "OPT-1086",
                    "option_name": "색상/사이즈",
                    "option_name_localized": "색상/사이즈",
                    "selling_price": 19000,
                    "selling_price_formatted": "19,000원",
                    "stock_quantity": 30,
                    "is_active": true
                }
            ],
            "202": []
        },
        "product_ids": [201, 202]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 여러 상품의 옵션을 한 번에 조회합니다. 관리자 상품 목록이 옵션을 기본 적재하지 않게 되면서(`GET /admin/products` 는 개수·재고합계 집계만 반환), 행을 펼칠 때 이 엔드포인트가 옵션 상세를 공급합니다. 단건 라우트만 두면 한 페이지를 전부 펼쳤을 때 100요청이 되므로 배치를 기본형으로 합니다. 상품 수와 무관하게 쿼리 3개(허용 ID 확정 → 옵션 조회 → 옵션 가격 계산용 상품 로드)로 처리됩니다.

이 엔드포인트는 **부분 성공**을 계약으로 합니다. 존재하지 않거나 권한 스코프 밖인 상품 ID 는 404 를 만들지 않고 응답의 `product_ids` 에서 빠집니다 — 하나가 없다고 나머지의 정상 조회를 버릴 이유가 없고, 배치에서 "무엇이 없었는지" 는 상태코드로 표현할 수 없기 때문입니다. 단건 404 semantics 가 필요하면 `GET /admin/products/{identifier}` 를 사용합니다. 권한 스코프는 옵션 테이블이 아니라 상품 쿼리에서 먼저 적용되므로, `scope=self` 사용자가 타인 상품 ID 를 실어 보내도 옵션이 노출되지 않습니다.

라우트는 `/{identifier}` 보다 위에 등록해야 합니다 — identifier 제약이 `[0-9a-zA-Z]+` 라 리터럴 `options` 를 삼켜 상품 상세로 잘못 라우팅됩니다.


### GET /api/modules/sirsoft-ecommerce/admin/products/{identifier}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/products/{identifier} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| (ProductResource) | object | `{"id":201,"product_code":"P-201", …}` | 상품 상세 1건. 필드 구성은 `GET /admin/products/{identifier}` 와 동일하며, 목록·옵션·이미지·상품고시·공통정보·추가옵션을 포함합니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품을 조회했습니다.",
    "data": {
        "id": 201,
        "product_code": "P-201",
        "name": { "ko": "티셔츠", "en": "T-Shirt" },
        "selling_price": 19000,
        "selling_price_formatted": "19,000원",
        "sales_status": "on_sale",
        "display_status": "visible",
        "options": [],
        "images": [],
        "additional_options": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자 화면용 단일 상품 상세를 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.products.read` 권한이 필요하며, `ProductService::findByIdOrCode()`로 숫자 ID 우선, 없으면 상품코드로 상품을 찾은 뒤 `getDetail($id, includeInactive: true)`로 비활성(숨김/판매중지) 상품까지 포함해 상세를 로드하고 `ProductResource`로 반환합니다. 공개 상세와 달리 전시상태에 관계없이 조회되므로 관리자 편집/열람에 사용하며, 대상이 없으면 404를 반환합니다.


### POST /api/modules/sirsoft-ecommerce/admin/products/{productId}/images
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.images.upload -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.images.upload`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@uploadImage`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| productId | path | string | 예 | — | 대상 product의 식별자 |
| file | body | file | 예 | max 10240 | 업로드 파일 |
| temp_key | body | string | 아니오 | max 64 | 임시 업로드 세션 키 (같은 상품의 여러 이미지를 한 세션으로 묶음, 생략 시 서버가 UUID 자동 발급) |
| collection | body | string | 아니오 | — | 첨부 컬렉션 그룹명 (첨부를 용도별로 묶는 키, 미지정 시 default) |
| alt_text | body | array | 아니오 | — | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) |
| alt_text.ko | body | string | 아니오 | max 255 | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) — `ko` 로케일 값 |
| alt_text.en | body | string | 아니오 | max 255 | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) — `en` 로케일 값 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product-image.filter_upload_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/products/{productId}/images HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary
Content-Disposition: form-data; name="temp_key"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="collection"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="alt_text"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="alt_text.ko"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="alt_text.en"

예시값
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. FileUploader 컴포넌트 규약에 맞춰 실제 이미지 정보는 `data.data` 로 한 단계 더 감싸져 있습니다. 성공 시 HTTP 201._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | object | `{ ... }` | 업로드된 이미지 정보 객체 (아래 하위 필드) |
| data.id | integer | `1` | 업로드된 이미지 레코드의 기본 키 |
| data.hash | string | `3f7a1c9e…` | 이미지 해시 (상품 수정 시 `thumbnail_hash` 지정에 사용) |
| data.original_filename | string | `product-main.jpg` | 업로드한 원본 파일명 |
| data.mime_type | string | `image/jpeg` | 파일 MIME 타입 |
| data.size | integer | `204800` | 파일 크기 (바이트) |
| data.size_formatted | string | `200 KB` | 사람이 읽는 파일 크기 문자열 (B/KB/MB/GB) |
| data.download_url | string | `/api/modules/sirsoft-ecommerce/products/images/3f7a1c9e/download` | 이미지 다운로드/표시 URL |
| data.order | integer | `1` | 이미지 노출 순서 (`sort_order`) |
| data.is_image | boolean | `true` | MIME 타입이 `image/` 로 시작하는지 여부 |
| data.is_thumbnail | boolean | `true` | 대표 이미지 여부 (컬렉션의 첫 이미지는 자동 지정) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "이미지가 업로드되었습니다.",
    "data": {
        "data": {
            "id": 1,
            "hash": "3f7a1c9e",
            "original_filename": "product-main.jpg",
            "mime_type": "image/jpeg",
            "size": 204800,
            "size_formatted": "200 KB",
            "download_url": "/api/modules/sirsoft-ecommerce/products/images/3f7a1c9e/download",
            "order": 1,
            "is_image": true,
            "is_thumbnail": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 파일 저장 등 업로드 처리 중 오류가 발생한 경우 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) 또는 상품 이미지 개수 상한 초과 (`ProductImageUploadLimitException`) |
| 500 | Internal Server Error | 서버 내부 오류 — 도메인 규칙 위반이 아닌 예외(인프라 장애·코드 결함)는 4xx 로 뭉개지 않고 500 으로 구분한다 |

<!-- @generated:end -->

**설명** 기존 상품에 이미지 1건을 업로드해 즉시 귀속시킵니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, 경로의 `productId`가 있어 `ProductImageService::upload()`가 임시가 아닌 해당 상품 소유 이미지로 저장하고 `collection`별 마지막 순서에 추가합니다. 상품 편집 화면에서 이미지를 추가할 때 사용하며, 컬렉션의 첫 이미지는 자동으로 대표 이미지로 지정됩니다. 개수 상한 초과 시 422, 상품이 없으면 404를 반환합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/products/{productId}/images/{imageId}/thumbnail
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.images.set-thumbnail -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.images.set-thumbnail`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@setThumbnail`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| productId | path | string | 예 | — | 대상 product의 식별자 |
| imageId | path | string | 예 | — | 대상 image의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/products/{productId}/images/{imageId}/thumbnail HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 데이터를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "대표 이미지가 설정되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 대표 이미지 설정 처리 중 오류가 발생한 경우 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 500 | Internal Server Error | 서버 내부 오류 — 도메인 규칙 위반이 아닌 예외(인프라 장애·코드 결함)는 4xx 로 뭉개지 않고 500 으로 구분한다 |

<!-- @generated:end -->

**설명** 상품의 대표(썸네일) 이미지를 지정합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, `ProductImageService::setThumbnail()`가 같은 상품의 기존 대표 이미지의 `is_thumbnail`을 해제하고 `imageId` 이미지에 대표 플래그를 부여합니다. 목록·상세에서 노출될 기본 이미지를 교체할 때 사용하며, 지정 대상 상품/이미지가 없으면 404를 반환합니다. `{imageId}`는 경로의 `{productId}`에 속한 이미지여야 합니다. 다른 상품의 이미지 ID를 지정하면 400을 반환하며, 두 상품의 대표 이미지 상태는 모두 그대로 유지됩니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/products/{product}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/products/{product} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 삭제 성공 여부. 주문 이력이 있는 상품은 삭제되지 않고 409 로 차단됩니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품이 삭제되었습니다.",
    "data": {
        "deleted": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 상품 1건을 삭제합니다. `auth:sanctum` + `sirsoft-ecommerce.products.delete` 권한이 필요하며, 먼저 `ProductService::checkCanDelete()`로 주문 이력을 선행 검사해 이력이 있으면 관련 주문 수(`count`)와 함께 409 Conflict로 차단하고, 통과 시 `ProductService::delete()`가 상품과 하위 데이터(옵션/이미지 등)를 명시적으로 제거합니다. 서비스 계층의 도메인 가드가 경합/우회 상황에서도 `ProductHasOrderHistoryException`으로 재차 409를 반환하며, 대상이 없으면 404를 반환합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/products/{product}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |
| name | body | array | 아니오 | — | 대상의 이름/명칭 |
| product_code | body | string | 예 | max 50 | 상품코드 (상품 고유 관리 식별자, 상품 간 중복 불가) |
| sales_product_code | body | string | 아니오 | max 50 | 판매자 상품코드 (판매자가 직접 입력하는 관리용 코드) |
| sku | body | string | 아니오 | max 100 | 재고관리코드(SKU) |
| category_ids | body | array | 아니오 | min 1, max 5 | category 식별자 배열 |
| primary_category_id | body | integer | 아니오 | — | primary category 식별자 |
| brand_id | body | integer | 아니오 | — | brand 식별자 |
| list_price | body | integer | 아니오 | min 0.01 | 정가 (기본통화 기준, 소수 통화는 소수 입력 허용) |
| selling_price | body | integer | 아니오 | min 0.01 | 판매가 (기본통화 기준, 정가 이하여야 함) |
| stock_quantity | body | integer | 아니오 | min 0 | 재고 수량 (옵션 사용 시 옵션 재고 합계로 관리) |
| safe_stock_quantity | body | integer | 아니오 | min 0 | 안전재고 수량 (이 값 미만이면 재고 부족 표시) |
| sales_status | body | string | 아니오 | — | 판매상태 (on_sale 판매중 / suspended 판매중지 / sold_out 품절 / coming_soon 출시예정) |
| display_status | body | string | 아니오 | — | 전시상태 (visible 전시 / hidden 숨김) |
| tax_status | body | string | 아니오 | — | 과세여부 (taxable 과세 / tax_free 면세) |
| tax_rate | body | number | 아니오 | min 0, max 100 | 세율(%) (과세 상품의 부가세 계산 비율) |
| shipping_policy_id | body | integer | 아니오 | — | shipping policy 식별자 |
| common_info_id | body | integer | 아니오 | — | common info 식별자 |
| description | body | array | 아니오 | — | 설명 |
| description_mode | body | string | 아니오 | `text`, `html` | 상세 설명 편집 모드 (text 일반 텍스트 / html HTML 에디터) |
| thumbnail_hash | body | string | 아니오 | max 64 | 대표 이미지로 지정할 이미지 해시 (업로드된 이미지 중 썸네일 선택) |
| image_temp_key | body | string | 아니오 | max 64 | 임시 업로드 세션 키 (사전 업로드한 이미지를 이 상품에 연결) |
| images | body | array | 아니오 | max 20 | 상품 이미지 목록 (각 항목: id/hash/url/alt_text/is_thumbnail/sort_order) |
| meta_title | body | array | 아니오 | — | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목) |
| meta_description | body | array | 아니오 | — | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약) |
| meta_keywords | body | array | 아니오 | — | SEO 메타 키워드 배열 (검색엔진 색인용 키워드 목록) |
| seo_sync_title | body | boolean | 아니오 | — | SEO 제목 자동 동기화 여부 (true 시 상품명으로 메타 제목 자동 채움) |
| seo_sync_description | body | boolean | 아니오 | — | SEO 설명 자동 동기화 여부 (true 시 상품 설명으로 메타 설명 자동 채움) |
| use_main_image_for_og | body | boolean | 아니오 | — | 대표 이미지를 OG(소셜 공유) 이미지로 사용할지 여부 |
| has_options | body | boolean | 아니오 | — | options 여부 |
| option_groups | body | array | 아니오 | — | 옵션 그룹 정의 (예: 색상/사이즈 등 옵션 축과 각 축의 선택값 목록) |
| options | body | array | 아니오 | min 1 | 옵션(SKU) 목록 (각 항목: 옵션코드·옵션명·옵션값·정가·판매가·재고 등) |
| additional_options | body | array | 아니오 | max 5 | 추가옵션 그룹 배열 (각 그룹당 선택지 1~20개, 필수 여부·추가금·직접입력 허용 등 설정) |
| notice_items | body | array | 아니오 | max 50 | 상품정보제공고시 항목 배열 (각 항목: 항목명·내용 다국어) |
| label_assignments | body | array | 아니오 | — | 라벨 할당 배열 (label_id + 노출 시작/종료일로 상품에 라벨 부착) |
| min_purchase_qty | body | integer | 아니오 | min 1 | 최소 구매 수량 (1회 주문 시 이 수량 이상 구매) |
| max_purchase_qty | body | integer | 아니오 | min 0 | 최대 구매 수량 (0=무제한) |
| purchase_restriction | body | string | 아니오 | `none`, `restricted` | 구매 대상 제한 (none 제한 없음 / restricted 특정 역할만 구매 허용) |
| allowed_roles | body | array | 아니오 | — | 구매 허용 역할 ID 배열 (purchase_restriction=restricted 시 필수) |
| barcode | body | string | 아니오 | max 50 | 바코드 |
| hs_code | body | string | 아니오 | max 20 | HS 코드 (수출입 관세 분류 코드) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/products/{product} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "product_code": "예시값",
    "sales_product_code": "예시값",
    "sku": "예시값",
    "category_ids": [
        "예시값"
    ],
    "primary_category_id": 1,
    "brand_id": 1,
    "list_price": 1,
    "selling_price": 1,
    "stock_quantity": 1,
    "safe_stock_quantity": 1,
    "sales_status": "예시값",
    "display_status": "예시값",
    "tax_status": "예시값",
    "tax_rate": 1,
    "shipping_policy_id": 1,
    "common_info_id": 1,
    "description": [
        "예시 내용입니다."
    ],
    "description_mode": "text",
    "thumbnail_hash": "예시값",
    "image_temp_key": "예시값",
    "images": [
        "예시값"
    ],
    "meta_title": [
        "예시 제목"
    ],
    "meta_description": [
        "예시 내용입니다."
    ],
    "meta_keywords": [
        "예시값"
    ],
    "seo_sync_title": true,
    "seo_sync_description": true,
    "use_main_image_for_og": true,
    "has_options": true,
    "option_groups": [
        "예시값"
    ],
    "options": [
        "예시값"
    ],
    "additional_options": [
        "예시값"
    ],
    "notice_items": [
        "예시값"
    ],
    "label_assignments": [
        "예시값"
    ],
    "min_purchase_qty": 1,
    "max_purchase_qty": 1,
    "purchase_restriction": "none",
    "allowed_roles": [
        "예시값"
    ],
    "barcode": "예시값",
    "hs_code": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductResource` — 수정 후 상품 전체). 필드 구성은 `GET /admin/products/by-code/{code}` 의 응답 필드 표와 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 수정된 상품의 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 상품","en":"API Doc Sample Product"}` | 상품명 (로케일별 값 객체) |
| name_localized | string | `API 문서 샘플 상품` | `name` 의 현재 로케일 해석 값 |
| product_code | string | `APIDOCSAMPLE01` | 상품코드 |
| sku | string | `SKU-DKOK-1319` | 재고관리코드(SKU) |
| categories | array | `[]` | 소속 카테고리 목록 (categories 관계 로드 시) |
| category_ids | array | `[]` | 소속 카테고리 ID 배열 |
| primary_category_id | integer | `null` | 대표 카테고리 ID |
| brand_id | integer | `null` | 브랜드 식별자 |
| list_price | integer | `703155` | 정가 (기본통화 기준) |
| selling_price | integer | `597682` | 판매가 (기본통화 기준) |
| discount_rate | integer | `15` | 할인율(%) |
| stock_quantity | integer | `509` | 재고 수량 |
| safe_stock_quantity | integer | `38` | 안전재고 수량 |
| is_below_safe_stock | boolean | `false` | 재고가 안전재고 미만인지 여부 |
| is_stock_consistent | boolean | `true` | 상품 재고와 옵션 재고 합계 일치 여부 |
| sales_status | string | `on_sale` | 판매상태 (on_sale / suspended / sold_out / coming_soon) |
| sales_status_label | string | `판매중` | `sales_status` 의 현지화 라벨 |
| display_status | string | `visible` | 전시상태 (visible / hidden) |
| display_status_label | string | `전시` | `display_status` 의 현지화 라벨 |
| tax_status | string | `taxable` | 과세여부 (taxable / tax_free) |
| tax_status_label | string | `과세` | `tax_status` 의 현지화 라벨 |
| tax_rate | string | `10.00` | 세율(%) |
| shipping_policy_id | integer | `null` | 배송정책 식별자 |
| common_info_id | integer | `null` | 공통정보 식별자 |
| description | object | `{"ko":"상품 설명","en":"Description"}` | 상세 설명 (로케일별 값 객체) |
| description_localized | string | `상품 설명` | `description` 의 현재 로케일 해석 값 |
| description_mode | string | `text` | 상세 설명 편집 모드 (text / html) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| purchase_restriction | string | `none` | 구매 대상 제한 (none / restricted) |
| allowed_roles | array | `null` | 구매 허용 역할 ID 배열 |
| barcode | string | `null` | 바코드 |
| hs_code | string | `null` | HS 코드 |
| images | array | `[]` | 상품 이미지 목록 (images 관계 로드 시) |
| thumbnail_hash | string | `null` | 대표 이미지 해시 |
| thumbnail_url | string | `null` | 대표 이미지 다운로드 URL |
| meta_title | object | `null` | SEO 메타 제목 (다국어 JSON) |
| meta_description | object | `null` | SEO 메타 설명 (다국어 JSON) |
| meta_keywords | array | `null` | SEO 메타 키워드 배열 |
| seo_sync_title | boolean | `true` | SEO 제목 자동 동기화 여부 |
| seo_sync_description | boolean | `true` | SEO 설명 자동 동기화 여부 |
| has_options | boolean | `false` | 옵션 사용 여부 |
| option_groups | array | `[]` | 옵션 그룹 정의 |
| options | array | `[]` | 옵션(SKU) 목록 (`ProductOptionResource`) |
| additional_options | array | `[]` | 추가옵션 그룹 목록. 각 그룹의 `values[]` 는 `id`, `name`, `price_adjustment`(기본 통화 기준 추가금), `price_adjustment_formatted`, `multi_currency_price_adjustment`(통화별 추가금 — 기본 통화가 아닌 통화로 표시할 때 사용), `is_default`, `allow_custom_text` 를 가집니다 |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 11:02:13` | 최종 수정 일시 (이번 수정 시각으로 갱신) |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 상품에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품이 수정되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 상품",
            "en": "API Doc Sample Product"
        },
        "name_localized": "API 문서 샘플 상품",
        "product_code": "APIDOCSAMPLE01",
        "sku": "SKU-DKOK-1319",
        "category_ids": [],
        "primary_category_id": null,
        "brand_id": null,
        "list_price": 703155,
        "selling_price": 597682,
        "discount_rate": 15,
        "stock_quantity": 509,
        "safe_stock_quantity": 38,
        "is_below_safe_stock": false,
        "is_stock_consistent": true,
        "sales_status": "on_sale",
        "sales_status_label": "판매중",
        "display_status": "visible",
        "display_status_label": "전시",
        "tax_status": "taxable",
        "tax_status_label": "과세",
        "tax_rate": "10.00",
        "shipping_policy_id": null,
        "common_info_id": null,
        "description_mode": "text",
        "min_purchase_qty": 1,
        "max_purchase_qty": 0,
        "purchase_restriction": "none",
        "allowed_roles": null,
        "barcode": null,
        "hs_code": null,
        "thumbnail_hash": null,
        "thumbnail_url": null,
        "meta_title": null,
        "meta_description": null,
        "meta_keywords": null,
        "seo_sync_title": true,
        "seo_sync_description": true,
        "has_options": false,
        "option_groups": [],
        "options": [],
        "additional_options": [],
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 11:02:13",
        "abilities": {
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** ID 경로로 지정한 상품을 수정합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, 라우트 모델 바인딩된 `Product`에 `UpdateProductRequest`로 검증된 값을 `ProductService::update()`로 반영해 상품 기본정보·옵션·이미지·SEO 메타를 갱신하고 `ProductResource`를 반환합니다. `by-code` 변형과 동일 서비스 메서드를 쓰지만 상품코드 조회 단계 없이 바로 대상 모델을 받습니다. 검증 실패는 422, 대상이 없으면 404로 응답합니다.


### GET /api/modules/sirsoft-ecommerce/admin/products/{product}/can-delete
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.can-delete -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.can-delete`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@canDelete`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/products/{product}/can-delete HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| canDelete | boolean | `false` | 삭제 가능 여부 (true 삭제 가능 / false 주문 이력 등으로 삭제 불가) |
| reason | string | `이 상품은 8건의 주문 이력이 있어 삭제할 수 없습니다.` | 삭제 불가 사유 (canDelete=false 일 때 안내 문구) |
| relatedData | object | `{"orders":8,"images":4,"options":3,"additionalOptions":0,…` | 연관 데이터 건수 (orders/images/options 등 상품에 연결된 하위 데이터 수) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "삭제 가능 여부를 확인했습니다.",
    "data": {
        "canDelete": false,
        "reason": "이 상품은 8건의 주문 이력이 있어 삭제할 수 없습니다.",
        "relatedData": {
            "orders": 8,
            "images": 4,
            "options": 3,
            "additionalOptions": 0,
            "labelAssignments": 2
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 상품 삭제 가능 여부를 사전 확인합니다. `auth:sanctum` + `sirsoft-ecommerce.products.delete` 권한이 필요하며, `ProductService::checkCanDelete()`가 주문 이력 등 연관 데이터를 검사해 `canDelete` 불리언과 차단 `reason`, 그리고 `relatedData`(orders/images/options 등 연관 건수)를 반환합니다. 삭제 버튼을 누르기 전 확인 다이얼로그에서 삭제 가능 여부와 연관 데이터를 안내하는 데 사용하며, 실제 삭제는 DELETE 엔드포인트가 수행합니다.


### GET /api/modules/sirsoft-ecommerce/admin/products/{product}/copy
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.show-for-copy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.show-for-copy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@showForCopy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |
| copy_images | query | boolean | 아니오 | — | 상품 이미지 복사 여부 |
| copy_options | query | boolean | 아니오 | — | 옵션 복사 여부 |
| copy_categories | query | boolean | 아니오 | — | 카테고리 연결 복사 여부 |
| copy_sales_info | query | boolean | 아니오 | — | 판매 정보(가격·재고·상태) 복사 여부 |
| copy_description | query | boolean | 아니오 | — | 상세 설명 복사 여부 |
| copy_notice | query | boolean | 아니오 | — | 상품 공지 복사 여부 |
| copy_common_info | query | boolean | 아니오 | — | 상품 공통정보 복사 여부 |
| copy_other_info | query | boolean | 아니오 | — | 기타 정보 복사 여부 |
| copy_shipping | query | boolean | 아니오 | — | 배송 정책 복사 여부 |
| copy_seo | query | boolean | 아니오 | — | SEO 메타 복사 여부 — 메타 제목·설명은 상품마다 달라야 검색 결과에서 구분되므로 기본 제외 |
| copy_identification | query | boolean | 아니오 | — | 식별 정보(SKU·상품코드 등) 복사 여부 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.show_for_copy_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/products/{product}/copy HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| name | object | `{"ko":"면 손수건 3매입 #1","en":"Cotton Handkerchief 3pcs #1"}` | 상품명 (다국어 JSON: {ko: "...", en: "..."}) |
| product_code | string | `4D6NNDBJOL08X291` | 상품코드 |
| sales_product_code | null | `null` | 판매자 상품코드 (사용자 입력용) |
| sku | string | `HK-0001` | SKU |
| brand_id | integer | `7` | 브랜드 ID |
| category_ids | array | `[29,30,32]` | category 식별자 배열 (연관 리소스 참조) |
| primary_category_id | integer | `32` | primary category 식별자 (연관 리소스 참조) |
| list_price | string | `5000.00` | 정가 (기본통화 기준) |
| selling_price | string | `3000.00` | 판매가 (기본통화 기준) |
| stock_quantity | integer | `26` | 재고 수량 (옵션 있으면 옵션 합계) |
| safe_stock_quantity | integer | `5` | 안전재고 수량 |
| tax_status | string | `taxable` | 과세여부: taxable(과세), tax_free(면세) |
| sales_status | string | `on_sale` | 판매상태: on_sale(판매중), suspended(판매중지), sold_out(품절), coming_soon(출시예정) |
| display_status | string | `visible` | 전시상태: visible(전시), hidden(숨김) |
| options | array | `[{"option_code":"CJTFHBL8SLRQ8ILM-001","option_name":{"ko…` | 복사 대상 옵션(SKU) 목록 (신규 등록 폼에 채울 옵션 정의, 다중통화 가격 포함) |
| additional_options | array | `[]` | 복사 대상 추가옵션 그룹 목록 (그룹명·선택지·추가금 등) |
| images | array | `[{"hash":"7df7761cdf16","url":null,"original_filename":"p…` | 복사 대상 이미지 목록 (각 항목: hash·원본파일명 등, copy_images 선택 시 포함) |
| thumbnail_hash | string | `7df7761cdf16` | 대표 이미지 해시 (썸네일로 지정된 이미지의 hash) |
| description | object | `{"ko":"<p>부드러운 면 100% 손수건 3매 세트입니다.<\/p>","en":"<p>A set …` | 상세 설명 (다국어 JSON, HTML 포함) |
| description_mode | string | `text` | 설명 모드: text(텍스트), html(HTML) |
| notice_items | array | `[{"name":{"ko":"항목1","en":"Field 1"},"content":{"ko":"상세페…` | 상품정보제공고시 항목 목록 (각 항목: 항목명·내용 다국어) |
| shipping_policy_id | integer | `444` | 배송정책 ID |
| shipping_policy | object | `{"id":444,"name":{"ko":"국내 무료배송","en":"Domestic Free Ship…` | 현재 부여된 배송정책 객체 (비활성 포함 — 수정폼 활성 목록에 없을 때 union 표시용) |
| common_info_id | integer | `11` | 공통정보 템플릿 ID |
| label_assignments | array | `[{"label_id":31,"start_date":null,"end_date":null},{"labe…` | 라벨 할당 목록 (각 항목: label_id + 노출 시작/종료일) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| purchase_restriction | string | `none` | 구매 제한: none(없음), restricted(제한) |
| allowed_roles | array | `[]` | 구매 허용 역할 ID 배열 |
| meta_title | null | `null` | SEO 제목 (다국어 JSON) |
| meta_description | null | `null` | SEO 설명 (다국어 JSON) |
| seo_tags | array | `[]` | SEO 태그 목록 (메타 키워드 등 검색엔진 노출용 태그) |
| seo_sync_title | boolean | `true` | SEO 제목 동기화 여부 (1: 상품명으로 자동 채움, 0: 직접 입력 보존) |
| seo_sync_description | boolean | `true` | SEO 설명 동기화 여부 (1: 상품 설명으로 자동 채움, 0: 직접 입력 보존) |
| barcode | null | `null` | 바코드 |
| hs_code | null | `null` | HS 코드 (관세 분류) |
| thumbnail_url | string | `/api/modules/sirsoft-ecommerce/produc…` | thumbnail URL |
| categories | array | `[{"id":29,"name":{"ko":"의류","en":"Clothing"},"name_locali…` | 소속 카테고리 목록 (breadcrumb 포함 — 복사 폼의 카테고리 표시용) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품 정보를 조회했습니다.",
    "data": {
        "name": {
            "ko": "면 손수건 3매입 #1",
            "en": "Cotton Handkerchief 3pcs #1"
        },
        "product_code": "4D6NNDBJOL08X291",
        "sales_product_code": null,
        "sku": "HK-0001",
        "brand_id": 7,
        "...": "(33개 키 생략, 총 38개)"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 상품 복사(복제) 등록 폼을 채우기 위한 원본 데이터를 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.products.read` 권한이 필요하며, `copy_images`/`copy_options`/`copy_categories`/`copy_seo` 등 쿼리 불리언으로 복사 항목을 선택하면 `ProductService::getDetailForCopy()`가 해당 항목만 담아 반환합니다. 컨트롤러가 대표 이미지의 `thumbnail_url`, 카테고리 breadcrumb, 옵션별 다중통화 가격(`ProductOptionResource`)을 추가로 보강하며, SEO는 기본적으로 복사 제외(false)입니다. 대상 상품이 없으면 404를 반환합니다.


### GET /api/modules/sirsoft-ecommerce/admin/products/{product}/form
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.show-for-form -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.show-for-form`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@showForForm`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/products/{product}/form HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1732` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"면 손수건 3매입 #1","en":"Cotton Handkerchief 3pcs #1"}` | 상품명 (다국어 JSON: {ko: "...", en: "..."}) |
| product_code | string | `CJTFHBL8SLRQ8ILM` | 상품코드 |
| sales_product_code | null | `null` | 판매자 상품코드 (사용자 입력용) |
| sku | string | `HK-0001` | SKU |
| brand_id | integer | `7` | 브랜드 ID |
| category_ids | array | `[29,30,32]` | category 식별자 배열 (연관 리소스 참조) |
| primary_category_id | integer | `32` | primary category 식별자 (연관 리소스 참조) |
| created_at | string | `2026-07-30 14:36:13` | 생성 일시 |
| updated_at | string | `2026-07-30 14:36:13` | 최종 수정 일시 |
| list_price | string | `5000.00` | 정가 (기본통화 기준) |
| selling_price | string | `3000.00` | 판매가 (기본통화 기준) |
| stock_quantity | integer | `26` | 재고 수량 (옵션 있으면 옵션 합계) |
| safe_stock_quantity | integer | `5` | 안전재고 수량 |
| tax_status | string | `taxable` | 과세여부: taxable(과세), tax_free(면세) |
| sales_status | string | `on_sale` | 판매상태: on_sale(판매중), suspended(판매중지), sold_out(품절), coming_soon(출시예정) |
| display_status | string | `visible` | 전시상태: visible(전시), hidden(숨김) |
| options | array | `[{"id":948,"option_code":"CJTFHBL8SLRQ8ILM-001","option_n…` | 옵션(SKU) 목록 (수정 폼 바인딩용, 각 옵션의 id·코드·옵션값·가격·재고 등) |
| additional_options | array | `[]` | 추가옵션 그룹 목록 (수정 폼 바인딩용, 그룹명·선택지·추가금 등) |
| images | array | `[{"id":7,"hash":"7df7761cdf16","url":null,"original_filen…` | 이미지 목록 (각 항목: id·hash·원본파일명 등) |
| thumbnail_hash | string | `7df7761cdf16` | 대표 이미지 해시 (썸네일로 지정된 이미지의 hash) |
| description | object | `{"ko":"<p>부드러운 면 100% 손수건 3매 세트입니다.<\/p>","en":"<p>A set …` | 상세 설명 (다국어 JSON, HTML 포함) |
| description_mode | string | `text` | 설명 모드: text(텍스트), html(HTML) |
| notice_items | array | `[{"name":{"ko":"항목1","en":"Field 1"},"content":{"ko":"상세페…` | 상품정보제공고시 항목 목록 (각 항목: 항목명·내용 다국어) |
| shipping_policy_id | integer | `444` | 배송정책 ID |
| shipping_policy | object | `{"id":444,"name":{"ko":"국내 무료배송","en":"Domestic Free Ship…` | 현재 부여된 배송정책 객체 (비활성 포함 — 수정폼 활성 목록에 없을 때 union 표시용) |
| common_info_id | integer | `11` | 공통정보 템플릿 ID |
| label_assignments | array | `[{"label_id":31,"start_date":null,"end_date":null},{"labe…` | 라벨 할당 목록 (각 항목: label_id + 노출 시작/종료일) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| purchase_restriction | string | `none` | 구매 제한: none(없음), restricted(제한) |
| allowed_roles | array | `[]` | 구매 허용 역할 ID 배열 |
| meta_title | null | `null` | SEO 제목 (다국어 JSON) |
| meta_description | object | `{"ko":"면 손수건 3매입 #1 의 직접 입력 SEO 설명입니다.","en":"Custom SEO …` | SEO 설명 (다국어 JSON) |
| seo_tags | array | `[]` | SEO 태그 목록 (메타 키워드 등 검색엔진 노출용 태그) |
| seo_sync_title | boolean | `true` | SEO 제목 동기화 여부 (1: 상품명으로 자동 채움, 0: 직접 입력 보존) |
| seo_sync_description | boolean | `true` | SEO 설명 동기화 여부 (1: 상품 설명으로 자동 채움, 0: 직접 입력 보존) |
| barcode | null | `null` | 바코드 |
| hs_code | null | `null` | HS 코드 (관세 분류) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품 정보를 조회했습니다.",
    "data": {
        "id": 1732,
        "name": {
            "ko": "면 손수건 3매입 #1",
            "en": "Cotton Handkerchief 3pcs #1"
        },
        "product_code": "CJTFHBL8SLRQ8ILM",
        "sales_product_code": null,
        "sku": "HK-0001",
        "...": "(34개 키 생략, 총 39개)"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 상품 수정 폼을 채우기 위한 상세 데이터를 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.products.read` 권한이 필요하며, `ProductService::getDetailForForm()`가 폼 입력 필드에 맞춘 형태(카테고리 ID 배열, 옵션/추가옵션, 이미지, SEO 태그 등)로 데이터를 반환합니다. 관리자 상품 편집 화면 진입 시 폼 초기값을 로드하는 데 사용하며, 대상 상품이 없으면 404를 반환합니다.


### GET /api/modules/sirsoft-ecommerce/admin/products/{product}/logs
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.products.logs -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.products.logs`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController@logs`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 로그 수 |
| sort_order | query | string | 아니오 | — | 작성 시각 정렬 방향 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.logs_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/products/{product}/logs HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `45291` | 기본 키 (내부 식별자) |
| log_type | string | `user` | 로그 구분 값 (admin 관리자 작업 / user 사용자 작업 등 활동 로그 채널) |
| log_type_label | string | `사용자` | `log_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| loggable_type | string | `Modules\Sirsoft\Ecommerce\Models\Product` | 로그 대상 모델의 전체 클래스명 (상품 또는 상품옵션 모델) |
| loggable_type_display | string | `Product` | 로그 대상 모델의 표시용 짧은 이름 (클래스 basename) |
| loggable_id | integer | `1732` | loggable 식별자 (연관 리소스 참조) |
| action | string | `wishlist.add` | 활동 액션 키 (product.create/update 등 수행된 작업 식별자) |
| action_label | string | `추가` | `action` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| localized_description | string | `위시리스트 추가 (면 손수건 3매입 #1)` | `description` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| description_key | string | `sirsoft-ecommerce::activity_log.descr…` | 설명 번역 키 (localized_description 을 생성하는 다국어 키) |
| properties | null | `null` | 로그 부가 속성 (액션에 첨부된 임의 메타데이터, 없으면 null) |
| changes | array | `[{"field":"selling_price","label_key":"sirsoft-ecommerce:…` | 단일 수정 변경 내역 (각 항목: field·label·old·new, 일괄 수정 로그면 null) |
| bulk_changes | null | `null` | 일괄 수정 변경 내역 (각 항목: model_id·changes 배열, 단일 수정 로그면 null) |
| has_changes | boolean | `false` | changes 여부 |
| actor_name | string | `시스템` | 행위를 수행한 주체(사용자/시스템)의 이름 |
| user | object | `{"name":"시스템"}` | 행위 수행 사용자 정보 (uuid·name·email, 시스템 작업이면 name 만 '시스템') |
| ip_address | string | `192.168.1.10` | 요청/행위가 발생한 IP 주소 |
| created_at | string | `2026-07-29 14:31:19` | 생성 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품 처리 이력을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 45291,
                "log_type": "user",
                "log_type_label": "사용자",
                "loggable_type": "Modules\\Sirsoft\\Ecommerce\\Models\\Product",
                "loggable_type_display": "Product",
                "loggable_id": 1732,
                "action": "wishlist.add",
                "action_label": "추가",
                "localized_description": "위시리스트 추가 (면 손수건 3매입 #1)",
                "description_key": "sirsoft-ecommerce::activity_log.description.wishlist_add",
                "properties": null,
                "changes": null,
                "bulk_changes": null,
                "has_changes": false,
                "actor_name": "시스템",
                "user": {
                    "name": "시스템"
                },
                "ip_address": "192.168.1.10",
                "created_at": "2026-07-29 14:31:19",
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_delete": true
                }
            },
            {
                "id": 170,
                "log_type": "admin",
                "log_type_label": "관리자",
                "loggable_type": "Modules\\Sirsoft\\Ecommerce\\Models\\Product",
                "loggable_type_display": "Product",
                "loggable_id": 1732,
                "action": "product.bulk_price_update",
                "action_label": "일괄 가격 수정",
                "localized_description": "상품 일괄 가격 수정 (1건)",
                "description_key": "sirsoft-ecommerce::activity_log.description.product_bulk_price_update",
                "properties": null,
                "changes": [
                    {
                        "field": "selling_price",
                        "label_key": "sirsoft-ecommerce::activity_log.fields.selling_price",
                        "old": 2553,
                        "new": "3000.00",
                        "type": "currency",
                        "label": "판매가"
                    }
                ],
                "bulk_changes": null,
                "has_changes": true,
                "actor_name": "시스템",
                "user": {
                    "name": "시스템"
                },
                "ip_address": "10.0.0.5",
                "created_at": "2026-07-29 13:06:43",
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_delete": true
                }
            },
            "... (총 25건 중 2건 표시)"
        ],
        "links": {
            "first": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/products/1732/logs?page=1",
            "last": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/products/1732/logs?page=4",
            "prev": null,
            "next": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/products/1732/logs?page=2"
        },
        "meta": {
            "current_page": 1,
            "from": 1,
            "last_page": 4,
            "links": [
                {
                    "url": null,
                    "label": "pagination.previous",
                    "page": null,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/products/1732/logs?page=1",
                    "label": "1",
                    "page": 1,
                    "active": true
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/products/1732/logs?page=2",
                    "label": "2",
                    "page": 2,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/products/1732/logs?page=3",
                    "label": "3",
                    "page": 3,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/products/1732/logs?page=4",
                    "label": "4",
                    "page": 4,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/products/1732/logs?page=2",
                    "label": "pagination.next",
                    "page": 2,
                    "active": false
                }
            ],
            "path": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/products/1732/logs",
            "per_page": 25,
            "to": 25,
            "total": 100
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 상품의 처리로그(활동 로그) 목록을 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.products.read` 권한이 필요하며, 컨트롤러가 해당 상품과 그 하위 옵션(`ProductOption`)의 `ActivityLog` 레코드를 `loggable_type`/`loggable_id` 기준으로 합쳐 `created_at` 정렬(기본 desc)로 페이지네이션합니다. `per_page`/`sort_order` 쿼리로 조회 범위를 조정하며, 상품 상세의 처리 이력 탭에서 생성/수정/재고 변경 등 감사 로그를 표시하는 데 사용합니다. 대상 상품이 없으면 404를 반환합니다.


### GET /api/modules/sirsoft-ecommerce/products
<!-- @generated:start:api.modules.sirsoft-ecommerce.products.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.products.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| category_id | query | integer | 아니오 | — | category 식별자 |
| category_slug | query | string | 아니오 | max 100 | 카테고리 slug 필터 (URL 친화 식별자로 카테고리 지정, category_id 대체 가능) |
| brand_id | query | integer | 아니오 | — | brand 식별자 |
| search | query | string | 아니오 | max 200 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| sort | query | string | 아니오 | `latest`, `sales`, `price_asc`, `price_desc` | 정렬 기준 (필드명, `-` 접두 시 내림차순) |
| min_price | query | integer | 아니오 | min 0 | 판매가 범위 필터 하한 (판매가가 이 값 이상인 상품) |
| max_price | query | integer | 아니오 | min 0 | 판매가 범위 필터 상한 (판매가가 이 값 이하인 상품) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.public_list_validation_rules`, `sirsoft-ecommerce.product.public_list_validation_messages`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/products?category_id=1&category_slug=example-key&brand_id=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&sort=latest&min_price=1&max_price=1&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `98` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `1831` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"겨울 패딩 점퍼 #100","en":"Winter Padded Jacket #100"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `겨울 패딩 점퍼 #100` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| product_code | string | `GGK6A9N8PXNR35OQ` | 상품코드 (상품 고유 관리 식별자) |
| sku | string | `JK-0100` | 재고관리코드(SKU) |
| thumbnail_url | string | `/api/modules/sirsoft-ecommerce/produc…` | thumbnail URL |
| list_price | integer | `200000` | 정가 (기본통화 자릿수로 정규화된 값) |
| list_price_formatted | string | `200,000원` | `list_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| selling_price | integer | `169000` | 판매가 (기본통화 자릿수로 정규화된 값) |
| selling_price_formatted | string | `169,000원` | `selling_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| discount_rate | number | `15.5` | 할인율(%) (정가 대비 판매가 할인 비율, (1 - 판매가/정가) × 100) |
| multi_currency_list_price | object | `{"KRW":{"price":200000,"formatted":"200,000원","is_default…` | 통화별 정가 맵 (통화코드 → {price, formatted, is_default, editable}, 설정된 모든 통화의 환산 정가) |
| multi_currency_selling_price | object | `{"KRW":{"price":169000,"formatted":"169,000원","is_default…` | 통화별 판매가 맵 (통화코드 → {price, formatted, is_default, editable}, 설정된 모든 통화의 환산 판매가) |
| stock_quantity | integer | `213` | 재고 수량 (옵션 사용 시 옵션 재고 합계) |
| safe_stock_quantity | integer | `18` | 안전재고 수량 (이 값 미만이면 재고 부족으로 표시) |
| is_below_safe_stock | boolean | `false` | below safe stock 여부 |
| sales_status | string | `on_sale` | 판매상태 값 (on_sale 판매중 / suspended 판매중지 / sold_out 품절 / coming_soon 출시예정) |
| sales_status_label | string | `판매중` | `sales_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| sales_status_variant | string | `success` | `sales_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| display_status | string | `visible` | 전시상태 값 (visible 전시 / hidden 숨김) |
| display_status_label | string | `전시` | `display_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| display_status_variant | string | `success` | `display_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| categories | array | `[{"id":56,"name":"스포츠","is_primary":0},{"id":57,"name":"축…` | 소속 카테고리 목록 (각 항목: id·현지화 이름·대표 여부. categories 관계 eager load 시에만 채워짐) |
| primary_category | string | `축구` | 대표 카테고리명 (is_primary 카테고리의 현지화 이름) |
| categories_with_path | array | `[{"id":56,"path":[{"id":56,"name":"스포츠","slug":"sports"}]…` | 소속 카테고리 목록 + 경로 (각 항목: id·breadcrumb path·대표 여부) |
| brand_name | string | `유니클로` | 브랜드명 (연관 브랜드의 현지화 이름) |
| shipping_policy_id | integer | `444` | shipping policy 식별자 (연관 리소스 참조) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 (1회 주문 시 이 수량 이상 구매) |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| has_options | boolean | `true` | options 여부 |
| labels | array | `[{"name":"베스트","color":"#F59E0B"}]` | 노출 중인 상품 라벨 목록 (각 항목: 라벨명·색상, 활성 라벨을 sort_order 순으로 정렬) |
| review_count | integer | `0` | review 개수 (집계) |
| rating_avg | integer | `0` | 평균 별점 (공개 리뷰 별점 평균, 소수 1자리 반올림) |
| created_at | string | `2026-07-30 23:36:17` | 생성 일시 |
| updated_at | string | `2026-08-05 07:27:04` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품 정보를 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 98,
                "id": 1831,
                "name": {
                    "ko": "겨울 패딩 점퍼 #100",
                    "en": "Winter Padded Jacket #100"
                },
                "name_localized": "겨울 패딩 점퍼 #100",
                "product_code": "GGK6A9N8PXNR35OQ",
                "...": "(33개 키 생략, 총 38개)"
            },
            {
                "number": 97,
                "id": 1830,
                "name": {
                    "ko": "무선 마우스 세트 #99",
                    "en": "Wireless Mouse Set #99"
                },
                "name_localized": "무선 마우스 세트 #99",
                "product_code": "J9HTLXFNIJZHI4B1",
                "...": "(33개 키 생략, 총 38개)"
            },
            "... (총 25건 중 2건 표시)"
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        },
        "pagination": {
            "current_page": 1,
            "last_page": 4,
            "per_page": 25,
            "total": 98,
            "from": 1,
            "...": "(2개 키 생략, 총 7개)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-products.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 쇼핑몰 프런트용 공개 상품 목록을 조회합니다. `optional.sanctum`(회원/비회원 모두 접근) + `sirsoft-ecommerce.user-products.read` 권한이 적용되며, `ProductService::getPublicList()`가 전시상태 visible이고 판매상태가 on_sale 또는 coming_soon인 상품만 반환합니다. 카테고리/브랜드/검색어/가격 범위 필터와 `sort`(latest/sales/price_asc/price_desc) 정렬을 지원하고 결과는 `ProductCollection`으로 페이지네이션됩니다. 확장은 `sirsoft-ecommerce.product.public_list_validation_rules` 훅으로 필터를 추가할 수 있습니다.


### GET /api/modules/sirsoft-ecommerce/products/new
<!-- @generated:start:api.modules.sirsoft-ecommerce.products.new -->
- **라우트명**: `api.modules.sirsoft-ecommerce.products.new`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductController@new`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| limit | query | integer | 아니오 | min 1, max 50 | 반환할 최대 항목 수 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.public_new_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/products/new?limit=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1831` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"겨울 패딩 점퍼 #100","en":"Winter Padded Jacket #100"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `겨울 패딩 점퍼 #100` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| product_code | string | `GGK6A9N8PXNR35OQ` | 상품코드 (상품 고유 관리 식별자) |
| sku | string | `JK-0100` | 재고관리코드(SKU) |
| thumbnail_url | string | `/api/modules/sirsoft-ecommerce/produc…` | thumbnail URL |
| list_price | integer | `200000` | 정가 (기본통화 자릿수로 정규화된 값) |
| list_price_formatted | string | `200,000원` | `list_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| selling_price | integer | `169000` | 판매가 (기본통화 자릿수로 정규화된 값) |
| selling_price_formatted | string | `169,000원` | `selling_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| discount_rate | number | `15.5` | 할인율(%) (정가 대비 판매가 할인 비율, (1 - 판매가/정가) × 100) |
| multi_currency_list_price | object | `{"KRW":{"price":200000,"formatted":"200,000원","is_default…` | 통화별 정가 맵 (통화코드 → {price, formatted, is_default, editable}, 설정된 모든 통화의 환산 정가) |
| multi_currency_selling_price | object | `{"KRW":{"price":169000,"formatted":"169,000원","is_default…` | 통화별 판매가 맵 (통화코드 → {price, formatted, is_default, editable}, 설정된 모든 통화의 환산 판매가) |
| stock_quantity | integer | `213` | 재고 수량 (옵션 사용 시 옵션 재고 합계) |
| safe_stock_quantity | integer | `18` | 안전재고 수량 (이 값 미만이면 재고 부족으로 표시) |
| is_below_safe_stock | boolean | `false` | below safe stock 여부 |
| sales_status | string | `on_sale` | 판매상태 값 (on_sale 판매중 / suspended 판매중지 / sold_out 품절 / coming_soon 출시예정) |
| sales_status_label | string | `판매중` | `sales_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| sales_status_variant | string | `success` | `sales_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| display_status | string | `visible` | 전시상태 값 (visible 전시 / hidden 숨김) |
| display_status_label | string | `전시` | `display_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| display_status_variant | string | `success` | `display_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| categories | array | `[{"id":56,"name":"스포츠","is_primary":0},{"id":57,"name":"축…` | 소속 카테고리 목록 (각 항목: id·현지화 이름·대표 여부. categories 관계 eager load 시에만 채워짐) |
| primary_category | string | `축구` | 대표 카테고리명 (is_primary 카테고리의 현지화 이름) |
| categories_with_path | array | `[{"id":56,"path":[{"id":56,"name":"스포츠","slug":"sports"}]…` | 소속 카테고리 목록 + 경로 (각 항목: id·breadcrumb path·대표 여부) |
| shipping_policy_id | integer | `444` | shipping policy 식별자 (연관 리소스 참조) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 (1회 주문 시 이 수량 이상 구매) |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| has_options | boolean | `true` | options 여부 |
| labels | array | `[{"name":"베스트","color":"#F59E0B"}]` | 노출 중인 상품 라벨 목록 (각 항목: 라벨명·색상, 활성 라벨을 sort_order 순으로 정렬) |
| review_count | integer | `0` | review 개수 (집계) |
| rating_avg | integer | `0` | 평균 별점 (공개 리뷰 별점 평균, 소수 1자리 반올림) |
| created_at | string | `2026-07-30 23:36:17` | 생성 일시 |
| updated_at | string | `2026-08-05 07:27:04` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품 정보를 조회했습니다.",
    "data": [
        {
            "id": 1831,
            "name": {
                "ko": "겨울 패딩 점퍼 #100",
                "en": "Winter Padded Jacket #100"
            },
            "name_localized": "겨울 패딩 점퍼 #100",
            "product_code": "GGK6A9N8PXNR35OQ",
            "sku": "JK-0100",
            "...": "(31개 키 생략, 총 36개)"
        },
        {
            "id": 1830,
            "name": {
                "ko": "무선 마우스 세트 #99",
                "en": "Wireless Mouse Set #99"
            },
            "name_localized": "무선 마우스 세트 #99",
            "product_code": "J9HTLXFNIJZHI4B1",
            "sku": "MS-0099",
            "...": "(31개 키 생략, 총 36개)"
        },
        {
            "id": 1829,
            "name": {
                "ko": "가죽 크로스백 #98",
                "en": "Leather Crossbody Bag #98"
            },
            "name_localized": "가죽 크로스백 #98",
            "product_code": "ZVNPXKW88D0ESYDK",
            "sku": "BG-0098",
            "...": "(31개 키 생략, 총 36개)"
        },
        {
            "id": 1828,
            "name": {
                "ko": "스테인리스 텀블러 #97",
                "en": "Stainless Tumbler #97"
            },
            "name_localized": "스테인리스 텀블러 #97",
            "product_code": "G6TWL4RFBTWHJD71",
            "sku": "TB-0097",
            "...": "(31개 키 생략, 총 36개)"
        },
        {
            "id": 1827,
            "name": {
                "ko": "코튼 후드티 #96",
                "en": "Cotton Hoodie #96"
            },
            "name_localized": "코튼 후드티 #96",
            "product_code": "E8CDGTL7BHAPXRHA",
            "sku": "HD-0096",
            "...": "(31개 키 생략, 총 36개)"
        },
        "... (총 10건 중 5건 표시)"
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-products.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 공개 신상품 목록을 조회합니다. `optional.sanctum`(회원/비회원 모두 접근) + `sirsoft-ecommerce.user-products.read` 권한이 적용되며, `ProductService::getNewProducts()`가 최신 등록순으로 상품을 정렬해 `ProductListResource` 컬렉션으로 반환합니다. `limit`(기본 10, 최대 50) 쿼리로 개수를 제한하며, 메인 페이지의 신상품 섹션 등에 사용됩니다. 확장은 `sirsoft-ecommerce.product.public_new_validation_rules` 훅으로 검증 규칙을 확장할 수 있습니다.


### GET /api/modules/sirsoft-ecommerce/products/popular
<!-- @generated:start:api.modules.sirsoft-ecommerce.products.popular -->
- **라우트명**: `api.modules.sirsoft-ecommerce.products.popular`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductController@popular`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| limit | query | integer | 아니오 | min 1, max 50 | 반환할 최대 항목 수 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.public_popular_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/products/popular?limit=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1736` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"프리미엄 브이넥 티셔츠 #5","en":"Premium V-Neck T-Shirt #5"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `프리미엄 브이넥 티셔츠 #5` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| product_code | string | `4R5OG8VYO0JKG1WQ` | 상품코드 (상품 고유 관리 식별자) |
| sku | string | `TS-0005` | 재고관리코드(SKU) |
| thumbnail_url | string | `/api/modules/sirsoft-ecommerce/produc…` | thumbnail URL |
| list_price | integer | `34000` | 정가 (기본통화 자릿수로 정규화된 값) |
| list_price_formatted | string | `34,000원` | `list_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| selling_price | integer | `27000` | 판매가 (기본통화 자릿수로 정규화된 값) |
| selling_price_formatted | string | `27,000원` | `selling_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| discount_rate | number | `20.6` | 할인율(%) (정가 대비 판매가 할인 비율, (1 - 판매가/정가) × 100) |
| multi_currency_list_price | object | `{"KRW":{"price":34000,"formatted":"34,000원","is_default":…` | 통화별 정가 맵 (통화코드 → {price, formatted, is_default, editable}, 설정된 모든 통화의 환산 정가) |
| multi_currency_selling_price | object | `{"KRW":{"price":27000,"formatted":"27,000원","is_default":…` | 통화별 판매가 맵 (통화코드 → {price, formatted, is_default, editable}, 설정된 모든 통화의 환산 판매가) |
| stock_quantity | integer | `137` | 재고 수량 (옵션 사용 시 옵션 재고 합계) |
| safe_stock_quantity | integer | `14` | 안전재고 수량 (이 값 미만이면 재고 부족으로 표시) |
| is_below_safe_stock | boolean | `false` | below safe stock 여부 |
| sales_status | string | `on_sale` | 판매상태 값 (on_sale 판매중 / suspended 판매중지 / sold_out 품절 / coming_soon 출시예정) |
| sales_status_label | string | `판매중` | `sales_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| sales_status_variant | string | `success` | `sales_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| display_status | string | `visible` | 전시상태 값 (visible 전시 / hidden 숨김) |
| display_status_label | string | `전시` | `display_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| display_status_variant | string | `success` | `display_status` 값의 표시 변형 키 (UI 배지 색상/스타일) |
| categories | array | `[{"id":51,"name":"식품","is_primary":0},{"id":52,"name":"과일…` | 소속 카테고리 목록 (각 항목: id·현지화 이름·대표 여부) |
| primary_category | string | `과일` | 대표 카테고리명 (is_primary 카테고리의 현지화 이름) |
| categories_with_path | array | `[{"id":51,"path":[{"id":51,"name":"식품","slug":"food"}],"p…` | 소속 카테고리 목록 + 경로 (각 항목: id·breadcrumb path·대표 여부) |
| shipping_policy_id | integer | `444` | shipping policy 식별자 (연관 리소스 참조) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 (1회 주문 시 이 수량 이상 구매) |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| has_options | boolean | `true` | options 여부 |
| labels | array | `[{"name":"품절임박","color":"#F97316"}]` | 노출 중인 상품 라벨 목록 (각 항목: 라벨명·색상, 활성 라벨을 sort_order 순으로 정렬) |
| review_count | integer | `0` | review 개수 (집계) |
| rating_avg | integer | `0` | 평균 별점 (공개 리뷰 별점 평균, 소수 1자리 반올림) |
| created_at | string | `2026-07-30 23:36:13` | 생성 일시 |
| updated_at | string | `2026-07-30 23:36:13` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품 정보를 조회했습니다.",
    "data": [
        {
            "id": 1736,
            "name": {
                "ko": "프리미엄 브이넥 티셔츠 #5",
                "en": "Premium V-Neck T-Shirt #5"
            },
            "name_localized": "프리미엄 브이넥 티셔츠 #5",
            "product_code": "4R5OG8VYO0JKG1WQ",
            "sku": "TS-0005",
            "...": "(31개 키 생략, 총 36개)"
        },
        {
            "id": 1743,
            "name": {
                "ko": "코튼 후드티 #12",
                "en": "Cotton Hoodie #12"
            },
            "name_localized": "코튼 후드티 #12",
            "product_code": "5D0E22LJLQVSKI1G",
            "sku": "HD-0012",
            "...": "(31개 키 생략, 총 36개)"
        },
        {
            "id": 1747,
            "name": {
                "ko": "겨울 패딩 점퍼 #16",
                "en": "Winter Padded Jacket #16"
            },
            "name_localized": "겨울 패딩 점퍼 #16",
            "product_code": "5E1WSBY0CHFX7UJU",
            "sku": "JK-0016",
            "...": "(31개 키 생략, 총 36개)"
        },
        {
            "id": 1735,
            "name": {
                "ko": "베이직 라운드 티셔츠 #4",
                "en": "Basic Round T-Shirt #4"
            },
            "name_localized": "베이직 라운드 티셔츠 #4",
            "product_code": "Y3Y792BFFW3HRW7A",
            "sku": "TS-0004",
            "...": "(31개 키 생략, 총 36개)"
        },
        {
            "id": 1733,
            "name": {
                "ko": "기본 양말 5족 #2",
                "en": "Basic Socks 5 Pairs #2"
            },
            "name_localized": "기본 양말 5족 #2",
            "product_code": "S0SO3A6SJFYLAKSF",
            "sku": "SK-0002",
            "...": "(31개 키 생략, 총 36개)"
        },
        "... (총 10건 중 5건 표시)"
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-products.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 공개 인기 상품 목록을 조회합니다. `optional.sanctum`(회원/비회원 모두 접근) + `sirsoft-ecommerce.user-products.read` 권한이 적용되며, `ProductService::getPopularProducts()`가 최근 30일 판매량 기준으로 정렬한 상품을 `ProductListResource` 컬렉션으로 반환합니다. `limit`(기본 10, 최대 50) 쿼리로 개수를 제한하며, 베스트/인기 상품 위젯에 사용됩니다. 확장은 `sirsoft-ecommerce.product.public_popular_validation_rules` 훅으로 검증 규칙을 확장할 수 있습니다.


### GET /api/modules/sirsoft-ecommerce/products/recent
<!-- @generated:start:api.modules.sirsoft-ecommerce.products.recent -->
- **라우트명**: `api.modules.sirsoft-ecommerce.products.recent`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductController@recent`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | string | 아니오 | max 500 | 대상 리소스 식별자 배열 (대량 작업 대상) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product.public_recent_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/products/recent?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_목록 응답: `data[]` 배열 항목의 필드 (`ProductListResource` — `GET /products/new` 와 동일 구성). `ids` 가 비어 있으면 `data` 는 빈 배열입니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `322` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"eum et quia","en":"tenetur id quae"}` | 상품명 (로케일별 값 객체) |
| name_localized | string | `eum et quia` | `name` 의 현재 로케일 해석 값 |
| product_code | string | `PROD-GJUX-1484` | 상품코드 (상품 고유 관리 식별자) |
| sku | string | `SKU-MRAD-9306` | 재고관리코드(SKU) |
| thumbnail_url | string | `/api/modules/sirsoft-ecommerce/products/images/3f7a1c9e/download` | 대표 이미지 URL |
| list_price | integer | `112594` | 정가 (기본통화 자릿수로 정규화) |
| list_price_formatted | string | `112,594원` | `list_price` 의 표시용 통화 포맷 문자열 |
| selling_price | integer | `88949` | 판매가 (기본통화 자릿수로 정규화) |
| selling_price_formatted | string | `88,949원` | `selling_price` 의 표시용 통화 포맷 문자열 |
| discount_rate | integer | `21` | 할인율(%) ((1 - 판매가/정가) × 100) |
| multi_currency_list_price | object | `{"KRW":{"price":112594,"formatted":"112,594원","is_default":true,"editable":true}}` | 통화별 정가 맵 |
| multi_currency_selling_price | object | `{"KRW":{"price":88949,"formatted":"88,949원","is_default":true,"editable":true}}` | 통화별 판매가 맵 |
| stock_quantity | integer | `22` | 재고 수량 |
| safe_stock_quantity | integer | `12` | 안전재고 수량 |
| is_below_safe_stock | boolean | `false` | 재고가 안전재고 미만인지 여부 |
| option_stock_sum | integer | `51` | 활성 옵션의 재고 합계 (options 관계 로드 시) |
| sales_status | string | `on_sale` | 판매상태 (on_sale / suspended / sold_out / coming_soon) |
| sales_status_label | string | `판매중` | `sales_status` 의 현지화 라벨 |
| sales_status_variant | string | `success` | `sales_status` 의 UI 배지 변형 키 |
| display_status | string | `visible` | 전시상태 (visible / hidden) |
| display_status_label | string | `전시` | `display_status` 의 현지화 라벨 |
| display_status_variant | string | `success` | `display_status` 의 UI 배지 변형 키 |
| categories | array | `[]` | 소속 카테고리 목록 (categories 관계 로드 시) |
| primary_category | string | `스마트폰` | 대표 카테고리명 (categories 관계 로드 시) |
| categories_with_path | array | `[]` | 소속 카테고리 + 경로(breadcrumb) 목록 |
| brand_name | string | `ASUS` | 브랜드명 (brand 관계 로드 시) |
| shipping_policy_id | integer | `31` | 배송정책 식별자 |
| shipping_policy_name | string | `국내 무료배송` | 배송정책명 (shippingPolicy 관계 로드 시) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| has_options | boolean | `false` | 옵션 사용 여부 |
| options_count | integer | `1` | 활성 옵션 개수 (options 관계 로드 시) |
| options | array | `[]` | 활성 옵션(SKU) 목록 (`ProductOptionResource`) |
| labels | array | `[]` | 노출 중인 상품 라벨 목록 (activeLabelAssignments 관계 로드 시) |
| review_count | integer | `0` | 리뷰 개수 |
| rating_avg | number | `0` | 평균 별점 (소수 1자리 반올림) |
| created_at | string | `2026-07-07 14:47:31` | 생성 일시 |
| updated_at | string | `2026-07-07 14:47:31` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 |
| abilities | object | `{"can_update":false,"can_delete":false}` | 현재 사용자가 이 상품에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품 정보를 조회했습니다.",
    "data": []
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-products.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 최근 본 상품 목록을 조회합니다. `optional.sanctum`(회원/비회원 모두 접근) + `sirsoft-ecommerce.user-products.read` 권한이 적용되며, 클라이언트가 로컬에 보관한 조회 이력 상품 ID들을 쉼표 구분 문자열(`ids`)로 전달하면 컨트롤러가 정수 배열로 파싱해 `ProductService::getProductsByIds()`로 조회한 뒤 `ProductListResource` 컬렉션을 반환합니다. `ids`가 비어 있으면 빈 배열을 반환하며, 확장은 `sirsoft-ecommerce.product.public_recent_validation_rules` 훅으로 검증 규칙을 확장할 수 있습니다.


### GET /api/modules/sirsoft-ecommerce/products/{product}
<!-- @generated:start:api.modules.sirsoft-ecommerce.products.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.products.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/products/{product} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1732` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"면 손수건 3매입 #1","en":"Cotton Handkerchief 3pcs #1"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `면 손수건 3매입 #1` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| product_code | string | `CJTFHBL8SLRQ8ILM` | 상품코드 |
| sku | string | `HK-0001` | SKU |
| categories | array | `[{"id":29,"name":{"ko":"의류","en":"Clothing"},"name_locali…` | 소속 카테고리 목록 (각 항목: id·현지화 이름·대표 여부. categories 관계 eager load 시에만 채워짐) |
| category_name | string | `바지` | 대표 카테고리명 (is_primary 카테고리의 현지화 이름) |
| list_price | integer | `5000` | 정가 (기본통화 기준) |
| list_price_formatted | string | `5,000원` | `list_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| selling_price | integer | `3000` | 판매가 (기본통화 기준) |
| selling_price_formatted | string | `3,000원` | `selling_price` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| discount_rate | integer | `40` | 할인율(%) (정가 대비 판매가 할인 비율, (1 - 판매가/정가) × 100) |
| multi_currency_list_price | object | `{"KRW":{"price":5000,"formatted":"5,000원","is_default":tr…` | 통화별 정가 맵 (통화코드 → {price, formatted, is_default, editable}, 설정된 모든 통화의 환산 정가) |
| multi_currency_selling_price | object | `{"KRW":{"price":3000,"formatted":"3,000원","is_default":tr…` | 통화별 판매가 맵 (통화코드 → {price, formatted, is_default, editable}, 설정된 모든 통화의 환산 판매가) |
| stock_quantity | integer | `26` | 재고 수량 (옵션 있으면 옵션 합계) |
| min_purchase_qty | integer | `1` | 최소 구매 수량 |
| max_purchase_qty | integer | `0` | 최대 구매 수량 (0=무제한) |
| sales_status | string | `on_sale` | 판매상태: on_sale(판매중), suspended(판매중지), sold_out(품절), coming_soon(출시예정) |
| sales_status_label | string | `판매중` | `sales_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| brand_name | string | `유니클로` | 브랜드명 (연관 브랜드의 현지화 이름) |
| labels | array | `[{"name":"할인","color":"#EF4444"},{"name":"이벤트","color":"#…` | 노출 중인 상품 라벨 목록 (각 항목: 라벨명·색상, 활성 라벨을 sort_order 순으로 정렬) |
| additional_options | array | `[]` | 추가옵션 그룹 목록 (각 그룹: 그룹명·필수 여부·선택지 목록, 활성 옵션을 sort_order 순으로 정렬) |
| shipping_policy_id | integer | `444` | shipping policy 식별자 (연관 리소스 참조) |
| is_shippable_to_selected_country | boolean | `true` | shippable to selected country 여부 |
| selected_shipping_country | string | `KR` | 배송비 계산에 적용된 배송 국가 코드 (ResolveShippingCountry 해석 결과) |
| free_shipping | boolean | `false` | 무료배송 여부 (적용 배송정책의 청구방식이 무료(FREE)인 경우 true) |
| shipping_fee_formatted | string | `KR: 무료배송` | `shipping_fee` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| shipping_policy | object | `{"name":"국내 무료배송","charge_policy":null,"charge_policy_lab…` | 적용 배송정책 요약 객체 (정책명·청구방식·기본배송비·무료배송 기준액 등, 상품 정책 없으면 기본 정책으로 폴백) |
| short_description | null | `null` | 짧은 설명 (상품 목록/카드용 요약 설명, 다국어 필드는 로케일별 값 객체) |
| short_description_localized | null | `null` | `short description` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| description | object | `{"ko":"<p>부드러운 면 100% 손수건 3매 세트입니다.<\/p>","en":"<p>A set …` | 설명 (다국어 필드는 로케일별 값 객체) |
| description_localized | string | `<p>부드러운 면 100% 손수건 3매 세트입니다.</p>` | `description` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| description_mode | string | `text` | 설명 모드: text(텍스트), html(HTML) |
| images | array | `[{"id":7,"hash":"7df7761cdf16","original_filename":"produ…` | 상품 이미지 목록 (각 항목: hash·url·alt_text·is_thumbnail·sort_order 등, images 관계 로드 시) |
| thumbnail_url | string | `/api/modules/sirsoft-ecommerce/produc…` | thumbnail URL |
| meta_title | null | `null` | SEO 제목 (다국어 JSON) |
| meta_description | object | `{"ko":"면 손수건 3매입 #1 의 직접 입력 SEO 설명입니다.","en":"Custom SEO …` | SEO 설명 (다국어 JSON) |
| meta_keywords | null | `null` | SEO 키워드 (배열) |
| has_options | boolean | `true` | options 여부 |
| option_groups | array | `[{"name":{"ko":"색상","en":"Color"},"name_localized":"색상","…` | 옵션 그룹 정의: [{name: "색상", values: ["빨강", "파랑"]}] |
| options | array | `[{"id":948,"option_code":"CJTFHBL8SLRQ8ILM-001","option_v…` | 활성 옵션(SKU) 목록 (각 옵션의 코드·옵션값·가격·재고 등, ProductOptionResource) |
| notice | object | `{"template_name":null,"values":[{"label":"항목1","value":"상…` | 상품정보제공고시 (템플릿명 + 항목별 라벨/내용 목록, notice 관계 로드 시) |
| common_info | object | `{"name":"부분 배송 안내","content":"• 적용 조건: 주문 상품의 재고 상황이 다른 경…` | 공통정보 (name·content·content_mode, commonInfo 관계 로드 시 — 여러 상품이 공유하는 공통 안내문) |
| is_wishlisted | boolean | `true` | wishlisted 여부 |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품 정보를 조회했습니다.",
    "data": {
        "id": 1732,
        "name": {
            "ko": "면 손수건 3매입 #1",
            "en": "Cotton Handkerchief 3pcs #1"
        },
        "name_localized": "면 손수건 3매입 #1",
        "product_code": "CJTFHBL8SLRQ8ILM",
        "sku": "HK-0001",
        "...": "(40개 키 생략, 총 45개)"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-products.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 쇼핑몰 프런트용 공개 상품 상세를 조회합니다. `optional.sanctum`(회원/비회원 모두 접근) + `sirsoft-ecommerce.user-products.read` 권한이 적용되며, `ProductService::getDetail()`로 상품을 로드하되 전시상태가 visible이 아니면 404를 반환합니다. 상세 페이지에 필요한 배송정책·상품고시·공통정보·브랜드·라벨·추가옵션·현재 사용자 위시리스트 관계를 추가 로드하고 `PublicProductResource`로 반환하며, 응답에는 다중통화 가격·배송비 안내(`shipping_fee_formatted`)·`is_wishlisted` 등 프런트 표시용 파생 필드가 포함됩니다.


### GET /api/modules/sirsoft-ecommerce/products/{product}/downloadable-coupons
<!-- @generated:start:api.modules.sirsoft-ecommerce.products.downloadable-coupons -->
- **라우트명**: `api.modules.sirsoft-ecommerce.products.downloadable-coupons`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\PublicCouponController@downloadableCoupons`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/products/{product}/downloadable-coupons HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| coupon_id | integer | `107` | coupon 식별자 (연관 리소스 참조) |
| localized_name | string | `카테고리 배송비 3,000원 할인` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| benefit_formatted | string | `¥3,000 할인` | `benefit` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| multi_currency_benefit_formatted | object | `{"KRW":"3,000원 할인","USD":"$25.50 할인","JPY":"¥3,000 할인","C…` | `multi_currency_benefit` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| target_type | string | `shipping_fee` | 적용대상(할인 기준): product_amount(상품금액), order_amount(주문금액), shipping_fee(배송비) |
| target_type_short_label | string | `배송비` | `target_type_short` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| valid_period_formatted | string | `2026-07-23 ~ 2026-09-21` | `valid_period` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| min_order_amount | string | `20000.00` | 쿠폰 적용 최소 주문금액 (0=제한 없음) |
| min_order_amount_formatted | string | `¥20,000` | `min_order_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| multi_currency_min_order_amount | object | `{"KRW":{"price":20000,"formatted":"20,000원","is_default":…` | 최소 주문금액의 통화별 환산 맵 (0이면 null) |
| total_quantity | integer | `300` | 총 발급 수량 (null=무제한) |
| remaining_quantity | integer | `299` | 잔여 발급 가능 수량 (total_quantity − issued_count, 무제한이면 null) |
| is_downloaded | boolean | `true` | downloaded 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "다운로드 가능한 쿠폰 목록을 불러왔습니다.",
    "data": {
        "data": [
            {
                "coupon_id": 107,
                "localized_name": "카테고리 배송비 3,000원 할인",
                "benefit_formatted": "¥3,000 할인",
                "multi_currency_benefit_formatted": {
                    "KRW": "3,000원 할인",
                    "USD": "$25.50 할인",
                    "JPY": "¥3,000 할인",
                    "CNY": "元174.00 할인",
                    "EUR": "€23.40 할인"
                },
                "target_type": "shipping_fee",
                "target_type_short_label": "배송비",
                "valid_period_formatted": "2026-07-23 ~ 2026-09-21",
                "min_order_amount": "20000.00",
                "min_order_amount_formatted": "¥20,000",
                "multi_currency_min_order_amount": {
                    "KRW": {
                        "price": 20000,
                        "formatted": "20,000원",
                        "is_default": true
                    },
                    "USD": {
                        "price": 170,
                        "formatted": "$170.00",
                        "is_default": false,
                        "exchange_rate": 0.85
                    },
                    "JPY": {
                        "price": 20000,
                        "formatted": "¥20,000",
                        "is_default": true
                    },
                    "CNY": {
                        "price": 1160,
                        "formatted": "元1,160.00",
                        "is_default": false,
                        "exchange_rate": 5.8
                    },
                    "EUR": {
                        "price": 156,
                        "formatted": "€156.00",
                        "is_default": false,
                        "exchange_rate": 0.78
                    }
                },
                "total_quantity": 300,
                "remaining_quantity": 299,
                "is_downloaded": true
            },
            {
                "coupon_id": 103,
                "localized_name": "배송비 50% 할인",
                "benefit_formatted": "50.00% 할인 (최대 ¥3,000)",
                "multi_currency_benefit_formatted": {
                    "KRW": "50.00% 할인 (최대 3,000원)",
                    "USD": "50.00% 할인 (최대 $25.50)",
                    "JPY": "50.00% 할인 (최대 ¥3,000)",
                    "CNY": "50.00% 할인 (최대 元174.00)",
                    "EUR": "50.00% 할인 (최대 €23.40)"
                },
                "target_type": "shipping_fee",
                "target_type_short_label": "배송비",
                "valid_period_formatted": "발급일로부터 14일",
                "min_order_amount": "20000.00",
                "min_order_amount_formatted": "¥20,000",
                "multi_currency_min_order_amount": {
                    "KRW": {
                        "price": 20000,
                        "formatted": "20,000원",
                        "is_default": true
                    },
                    "USD": {
                        "price": 170,
                        "formatted": "$170.00",
                        "is_default": false,
                        "exchange_rate": 0.85
                    },
                    "JPY": {
                        "price": 20000,
                        "formatted": "¥20,000",
                        "is_default": true
                    },
                    "CNY": {
                        "price": 1160,
                        "formatted": "元1,160.00",
                        "is_default": false,
                        "exchange_rate": 5.8
                    },
                    "EUR": {
                        "price": 156,
                        "formatted": "€156.00",
                        "is_default": false,
                        "exchange_rate": 0.78
                    }
                },
                "total_quantity": 1000,
                "remaining_quantity": 938,
                "is_downloaded": true
            },
            "... (총 8건 중 2건 표시)"
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-products.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 상품에서 다운로드 가능한 쿠폰 목록을 조회합니다. `optional.sanctum`(회원/비회원 모두 접근) + `sirsoft-ecommerce.user-products.read` 권한이 적용되며, `UserCouponService::getProductDownloadableCoupons()`가 해당 상품에 적용 가능한 발급 대기 쿠폰을 반환합니다. 로그인 상태면 사용자 ID를 함께 넘겨 각 쿠폰의 `is_downloaded`(이미 받았는지) 여부를 채워주고, 다중통화 혜택·최소주문금액(`multi_currency_benefit_formatted` 등)이 포함됩니다. 상품 상세의 쿠폰 받기 영역에 사용됩니다.


### GET /api/modules/sirsoft-ecommerce/products/{product}/inquiries
<!-- @generated:start:api.modules.sirsoft-ecommerce.products.inquiries.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.products.inquiries.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductInquiryController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| exclude_secret | query | boolean | 아니오 | — | 비밀글 제외 여부 (기본 `false` — 포함). `true` 면 비밀 문의를 목록에서 제외합니다. 쿼리 문자열 `"true"`/`"false"` 도 해석되며, 해석할 수 없는 값은 그대로 검증되어 422 가 됩니다. **보안 경계가 아니라 단순 표시 필터입니다** — 비밀 문의의 노출/마스킹은 이 값과 무관하게 서버가 요청자 신원으로 결정합니다 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/products/{product}/inquiries HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| items | array | `[{"id":60,"post_id":284,"user_id":1116,"author_name":null…` | 상품문의 항목 배열 (각 항목: id·작성자·작성일·답변 여부·게시판 연동 시 제목/내용/비밀글 여부/답변/첨부) |
| meta | object | `{"board_settings":{"secret_mode":"always","categories":[]…` | 문의 목록 메타 (board_settings 게시판 설정, inquiry_available 문의 게시판 연동 여부, current_page/per_page/total/last_page 페이지네이션, abilities 답변·삭제 권한) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "문의 목록을 조회했습니다.",
    "data": {
        "items": [
            {
                "id": 60,
                "post_id": 284,
                "user_id": 1116,
                "author_name": null,
                "title": "[S34-OTHER-3] 타인 문의",
                "...": "(9개 키 생략, 총 14개)"
            },
            {
                "id": 59,
                "post_id": 283,
                "user_id": 1116,
                "author_name": null,
                "title": "[S34-OTHER-2] 타인 문의",
                "...": "(9개 키 생략, 총 14개)"
            },
            {
                "id": 58,
                "post_id": 282,
                "user_id": 1116,
                "author_name": null,
                "title": "[S34-OTHER-1] 타인 문의",
                "...": "(9개 키 생략, 총 14개)"
            },
            {
                "id": 19,
                "post_id": 223,
                "user_id": 1209,
                "author_name": "최***자",
                "title": "[S33-T4-01] 배송 기간 문의드립니다",
                "...": "(9개 키 생략, 총 14개)"
            },
            {
                "id": 20,
                "post_id": 224,
                "user_id": 1209,
                "author_name": "최***자",
                "title": "[S33-2] 재입고 관련 문의",
                "...": "(9개 키 생략, 총 14개)"
            },
            "... (총 25건 중 5건 표시)"
        ],
        "meta": {
            "board_settings": {
                "secret_mode": "{MASKED}",
                "categories": [],
                "use_file_upload": true,
                "max_file_count": 3,
                "max_file_size": 5,
                "...": "(7개 키 생략, 총 12개)"
            },
            "inquiry_available": true,
            "current_page": 1,
            "per_page": 25,
            "total": 36,
            "...": "(2개 키 생략, 총 7개)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-products.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 상품의 1:1 문의 목록을 조회합니다. `optional.sanctum`(회원/비회원 모두 접근) + `sirsoft-ecommerce.user-products.read` 권한이 적용되며, `ProductInquiryService::getProductInquiries()`가 게시판 모듈과 연동된 문의 글을 페이지네이션해 `items`와 `board_settings`(비밀글 모드·카테고리 등) 메타를 반환합니다. `per_page`/`page`/`exclude_secret` 쿼리로 조회 범위를 조정합니다. 비밀 문의는 요청자 신원(작성자 본인·게시판 비밀글 열람 권한)에 따라 서버가 마스킹하며, 열람 권한이 없으면 `title`은 "비밀글" 플레이스홀더로 치환되고 `content`·`reply`는 `null`, `attachments`는 빈 배열로 내려갑니다(`is_secret`·작성자·답변 여부 등 메타는 유지). 이 마스킹은 게시판 모듈이 실어 보내는 권위 플래그(`can_view_secret`)를 신뢰하며, 플래그가 없으면 fail-closed 로 마스킹합니다. `exclude_secret` 쿼리는 표시 필터일 뿐 이 판정에 관여하지 않습니다. 상품 상세의 문의 탭에 사용됩니다.


### POST /api/modules/sirsoft-ecommerce/products/{product}/inquiries
<!-- @generated:start:api.modules.sirsoft-ecommerce.products.inquiries.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.products.inquiries.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductInquiryController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |
| title | body | string | 아니오 | min 2, max 200 | 제목 |
| category | body | string | 아니오 | — | 문의 분류 (게시판 설정에 정의된 카테고리, 미지정 시 기본값) |
| content | body | string | 예 | min 10, max 10000 | 본문 내용 |
| is_secret | body | boolean | 아니오 | — | secret 여부 |
| temp_key | body | string | 아니오 | — | 첨부파일 임시 업로드 키 (사전 업로드한 첨부를 이 문의에 연결) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.inquiry.store_validation_rules`, `sirsoft-ecommerce.inquiry.store_validation_messages`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/products/{product}/inquiries HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "title": "예시 제목",
    "category": "예시값",
    "content": "예시 내용입니다.",
    "is_secret": true,
    "temp_key": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. 성공 시 HTTP 201._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 생성된 상품 문의 글의 기본 키 (게시판 모듈에 생성된 게시글 ID) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "문의가 등록되었습니다.",
    "data": {
        "id": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-products.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) 또는 도메인 규칙 위반(문의 게시판 미설정·게시판 모듈 불가 등, `ProductInquiryOperationException` → `messages.inquiries.operation_failed_reason` 에 사유가 담김) |

<!-- @generated:end -->

**설명** 상품에 1:1 문의를 작성합니다. `optional.sanctum` + `sirsoft-ecommerce.user-products.read` 권한이 적용되며(선택적 인증 표면이지만 실제 작성은 인증 사용자를 전제), `ProductInquiryService::createInquiry()`가 게시판 모듈과 연동해 문의 글을 생성하고 성공 시 201과 생성된 `id`를 반환합니다. `content`는 필수, `title`/`category`/`is_secret`은 선택이며, 첨부는 사전 업로드한 `temp_key`로 연결됩니다. 도메인 규칙 위반(문의 게시판 미설정·게시판 모듈 불가 등)은 `ProductInquiryOperationException` 으로 422 를 반환하며, 예외가 들고 있는 다국어 키로 해석한 사유가 응답 문구에 포함됩니다. 그 밖의 예외는 서버 결함으로 보아 500 입니다.


### GET /api/modules/sirsoft-ecommerce/products/{product}/reviews
<!-- @generated:start:api.modules.sirsoft-ecommerce.products.reviews.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.products.reviews.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductReviewController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product | path | string | 예 | — | 대상 product의 식별자 |
| sort | query | string | 아니오 | `created_at_desc`, `created_at_asc`, `rating_desc`, `rating_asc` | 정렬 기준 (필드명, `-` 접두 시 내림차순) |
| photo_only | query | string | 아니오 | `0`, `1`, `true`, `false` | 포토리뷰만 필터 (true 시 사진이 첨부된 리뷰만 조회) |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 50 | 페이지당 항목 수 |
| rating | query | integer | 아니오 | `1`, `2`, `3`, `4`, `5` | 별점 필터 (지정한 평점의 리뷰만 조회) |
| option_filters | query | string | 아니오 | — | 옵션 조건 필터 (JSON 문자열로 전달, 서버에서 배열로 파싱해 특정 옵션 구매 리뷰만 조회) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.review.public_list_validation_rules`, `sirsoft-ecommerce.review.public_list_validation_messages`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/products/{product}/reviews?sort=created_at_desc&photo_only=0&page=1&per_page=1&rating=1&option_filters=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| reviews | object | `{"data":[],"links":{"first":"https:\/\/g7_2.dev\/api\/mod…` | 리뷰 목록 (data 배열 + 페이지네이션 메타, 공개 상태 리뷰만 정렬/필터 적용해 반환) |
| rating_stats | object | `{"5":{"count":0,"percent":0},"4":{"count":0,"percent":0},…` | 별점 분포 통계 (별점(1~5)별 count·percent 맵 + avg 평균 별점, 공개 리뷰 기준) |
| option_filters | array | `[{"key":"색상","values":[{"value":"화이트","count":0},{"value"…` | 옵션 조건 필터 후보 (구매 옵션 키·값별 리뷰 건수 집계, 옵션별 리뷰 필터 UI용) |
| total_count | integer | `0` | total 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "리뷰 목록을 조회했습니다.",
    "data": {
        "reviews": {
            "data": [],
            "links": {
                "first": "https://api.example.com/api/modules/sirsoft-ecommerce/products/1732/reviews?page=1",
                "last": "https://api.example.com/api/modules/sirsoft-ecommerce/products/1732/reviews?page=1",
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
                        "url": "https://api.example.com/api/modules/sirsoft-ecommerce/products/1732/reviews?page=1",
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
                "path": "https://api.example.com/api/modules/sirsoft-ecommerce/products/1732/reviews",
                "per_page": 25,
                "to": null,
                "total": 0
            }
        },
        "rating_stats": {
            "5": {
                "count": 0,
                "percent": 0
            },
            "4": {
                "count": 0,
                "percent": 0
            },
            "3": {
                "count": 0,
                "percent": 0
            },
            "2": {
                "count": 0,
                "percent": 0
            },
            "1": {
                "count": 0,
                "percent": 0
            },
            "avg": 0
        },
        "option_filters": [
            {
                "key": "색상",
                "values": [
                    {
                        "value": "화이트",
                        "count": 0
                    },
                    {
                        "value": "블루",
                        "count": 0
                    },
                    {
                        "value": "핑크",
                        "count": 0
                    }
                ]
            }
        ],
        "total_count": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-products.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 상품의 공개 리뷰 목록과 별점 통계를 조회합니다. `optional.sanctum`(회원/비회원 모두 접근) + `sirsoft-ecommerce.user-products.read` 권한이 적용되며, `ProductReviewService::getProductReviews()`가 정렬(`sort`)·포토리뷰만(`photo_only`)·별점(`rating`)·옵션(`option_filters`) 필터를 적용해 리뷰를 페이지네이션하고 별점 분포(`rating_stats`)와 선택 가능한 옵션 필터, 총 개수를 함께 반환합니다. `option_filters`는 JSON 문자열로 전달되면 서버에서 배열로 파싱되며, 상품 상세의 리뷰 탭에 사용됩니다. 확장은 `sirsoft-ecommerce.review.public_list_validation_rules` 훅으로 필터를 추가할 수 있습니다.


