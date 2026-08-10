# Product Common Infos API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Product Common Infos 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/product-common-infos
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-common-infos.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-common-infos.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductCommonInfoController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-common-infos.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search | query | string | 아니오 | max 255 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| active_only | query | boolean | 아니오 | — | <!-- TODO: 용도 --> |
| default_only | query | boolean | 아니오 | — | <!-- TODO: 용도 --> |
| per_page | query | string | 아니오 | — | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/product-common-infos?search=%EC%98%88%EC%8B%9C%EA%B0%92&active_only=1&default_only=1&per_page=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"일반 배송 안내","en":"Standard Shipping"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `일반 배송 안내` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| content | object | `{"ko":"• 배송 기간: 결제 완료 후 1~3일 이내 출고 (영업일 기준)\n• 배송 업체: CJ대…` | 본문 내용 |
| localized_content | string | `• 배송 기간: 결제 완료 후 1~3일 이내 출고 (영업일 기준) …` | `content` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| content_mode | string | `text` | 내용 표시 모드 (`text` 일반 텍스트 / `html` HTML, 기본값 `text`) |
| is_default | boolean | `true` | default 여부 |
| is_active | boolean | `true` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| icon | string | `info-circle` | 아이콘 식별자 (아이콘 클래스/이름) |
| created_at | string | `2026-07-30 23:35:46` | 생성 일시 |
| updated_at | string | `2026-07-30 23:35:46` | 최종 수정 일시 |
| products_count | integer | `85` | products 개수 (집계) |
| language_count | integer | `2` | language 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "공통정보 목록을 불러왔습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "name": {
                    "ko": "일반 배송 안내",
                    "en": "Standard Shipping"
                },
                "localized_name": "일반 배송 안내",
                "content": {
                    "ko": "• 배송 기간: 결제 완료 후 1~3일 이내 출고 (영업일 기준)\n• 배송 업체: CJ대한통운, 한진택배, 롯데택배\n• 배송 지역: 전국 배송 가능 (일부 도서산간 지역 제외)\n• 배송 조회: 마이페이지 > 주문내역에서 실시간 조회 가능\n• 배송비: 3만원 이상 구매 시 무료, 미만 시 3,000원",
                    "en": "• Delivery Time: Ships within 1-3 business days after payment\n• Carriers: CJ Logistics, Hanjin, Lotte\n• Coverage: Nationwide (some remote areas excluded)\n• Tracking: Available in My Page > Order History\n• Shipping Fee: Free for orders over 30,000 KRW, otherwise 3,000 KRW"
                },
                "localized_content": "• 배송 기간: 결제 완료 후 1~3일 이내 출고 (영업일 기준)\n• 배송 업체: CJ대한통운, 한진택배, 롯데택배\n• 배송 지역: 전국 배송 가능 (일부 도서산간 지역 제외)\n• 배송 조회: 마이페이지 > 주문내역에서 실시간 조회 가능\n• 배송비: 3만원 이상 구매 시 무료, 미만 시 3,000원",
                "content_mode": "text",
                "is_default": true,
                "is_active": true,
                "sort_order": 0,
                "icon": "info-circle",
                "created_at": "2026-07-30 23:35:46",
                "updated_at": "2026-07-30 23:35:46",
                "products_count": 85,
                "language_count": 2,
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            {
                "id": 2,
                "name": {
                    "ko": "당일 배송 안내",
                    "en": "Same-Day Delivery"
                },
                "localized_name": "당일 배송 안내",
                "content": {
                    "ko": "• 주문 마감: 오전 11시까지 결제 완료 시 당일 출고\n• 배송 지역: 서울, 경기, 인천 일부 지역\n• 배송비: 7만원 이상 무료, 미만 시 4,000원\n• 수령 시간: 오후 6시~9시 사이 도착\n• 주의: 재고 상황에 따라 익일 배송으로 변경될 수 있습니다",
                    "en": "• Order Deadline: Complete payment by 11 AM for same-day shipping\n• Coverage: Parts of Seoul, Gyeonggi, Incheon\n• Fee: Free for orders over 70,000 KRW, otherwise 4,000 KRW\n• Arrival: Between 6 PM - 9 PM\n• Note: May change to next-day delivery based on stock availability"
                },
                "localized_content": "• 주문 마감: 오전 11시까지 결제 완료 시 당일 출고\n• 배송 지역: 서울, 경기, 인천 일부 지역\n• 배송비: 7만원 이상 무료, 미만 시 4,000원\n• 수령 시간: 오후 6시~9시 사이 도착\n• 주의: 재고 상황에 따라 익일 배송으로 변경될 수 있습니다",
                "content_mode": "text",
                "is_default": false,
                "is_active": true,
                "sort_order": 1,
                "icon": "info-circle",
                "created_at": "2026-07-30 23:35:46",
                "updated_at": "2026-07-30 23:35:46",
                "products_count": 0,
                "language_count": 2,
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
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
            "last_page": 5,
            "per_page": 25,
            "total": 103,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-common-infos.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 상품 공통정보(배송·교환·반품 안내 등 여러 상품에 재사용되는 안내문) 목록을 조회합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-common-infos.read` 권한이 필요하며, `ProductCommonInfoController@index`가 `search`·`active_only`·`default_only` 필터를 조립합니다. `per_page`가 0 이하이거나 `all`이면 `ProductCommonInfoService::getAllCommonInfos()`로 전체를 조회하고, 그 외에는 `getPaginatedCommonInfos()`로 페이지네이션 조회(`data.pagination` 포함)합니다. `localized_name`·`localized_content`는 현재 로케일로 해석된 값, `products_count`는 이 공통정보를 사용하는 상품 수입니다.


### POST /api/modules/sirsoft-ecommerce/admin/product-common-infos
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-common-infos.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-common-infos.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductCommonInfoController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-common-infos.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| content | body | array | 아니오 | — | 본문 내용 |
| content_mode | body | string | 아니오 | `text`, `html` | 내용 표시 모드 (`text` 일반 텍스트 / `html` HTML, 미지정 시 `text`) |
| is_default | body | boolean | 아니오 | — | 기본값 지정 여부 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product-common-info.create_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/product-common-infos HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "content": [
        "예시 내용입니다."
    ],
    "content_mode": "text",
    "is_default": true,
    "is_active": true,
    "sort_order": 1
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductCommonInfoResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 생성된 공통정보의 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 공통정보","en":"API Doc Sample Common Info"}` | 공통정보 이름 (로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 공통정보` | `name` 의 현재 로케일 해석 값 |
| content | object\|array | `[]` | 안내 본문 내용 (로케일별 값 객체, 미입력 시 빈 배열) |
| localized_content | string | `` | `content` 의 현재 로케일 해석 값 |
| content_mode | string | `text` | 내용 표시 모드 (`text` 일반 텍스트 / `html` HTML, 기본값 `text`) |
| is_default | boolean | `false` | 신규 상품에 기본으로 사용할 공통정보인지 여부 |
| is_active | boolean | `true` | 활성 여부 (false 이면 상품에 노출되지 않음) |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| icon | string | `info-circle` | 아이콘 식별자 (목록 UI 표시용 고정값) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 10:44:49` | 최종 수정 일시 |
| products_count | integer | `0` | 이 공통정보를 사용 중인 상품 수 (생성 직후 0) |
| language_count | integer | `0` | 값이 입력된 로케일 개수 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "공통정보가 생성되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 공통정보",
            "en": "API Doc Sample Common Info"
        },
        "localized_name": "API 문서 샘플 공통정보",
        "content": [],
        "localized_content": "",
        "content_mode": "text",
        "is_default": false,
        "is_active": true,
        "sort_order": 0,
        "icon": "info-circle",
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 10:44:49",
        "products_count": 0,
        "language_count": 0,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-common-infos.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 새 상품 공통정보를 생성합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-common-infos.create` 권한이 필요하며, `ProductCommonInfoController@store`가 `ProductCommonInfoService::createCommonInfo()`에 검증된 데이터를 전달해 저장합니다. `name`은 다국어 배열(필수), `content`는 다국어 안내 내용, `content_mode`는 `text`/`html` 중 하나이며, `is_default`·`is_active`·`sort_order`로 기본 사용 여부·활성 여부·정렬을 지정합니다. 확장이 `sirsoft-ecommerce.product-common-info.create_validation_rules` 필터로 추가 파라미터를 붙일 수 있고, 성공 시 HTTP 201을, 처리 실패 시 400 에러 응답을 반환합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-common-infos.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-common-infos.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductCommonInfoController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-common-infos.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-common-infos.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품 공통정보 1건을 삭제합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-common-infos.delete` 권한이 필요하며, `ProductCommonInfoController@destroy`가 `ProductCommonInfoService::deleteCommonInfo()`를 호출해 삭제합니다. path의 `id`에 해당하는 공통정보가 없거나 삭제 처리 중 오류가 발생하면 각각 404/400 에러 응답을 반환합니다. 여러 상품에서 참조 중인 공통정보를 삭제할 경우 노출에 영향을 줄 수 있으므로 `products_count`를 먼저 확인하는 것이 좋습니다.


### GET /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-common-infos.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-common-infos.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductCommonInfoController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-common-infos.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-common-infos.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품 공통정보 1건의 상세를 조회합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-common-infos.read` 권한이 필요하며, `ProductCommonInfoController@show`가 `ProductCommonInfoService::getCommonInfo()`로 단건을 조회합니다. 다국어 원본(`name`, `content`)과 현재 로케일 해석값(`localized_name`, `localized_content`), `content_mode`, 기본/활성 여부를 함께 반환하며, 해당 `id`의 공통정보가 없으면 404를 반환합니다. 주로 수정 화면 진입 시 기존 값을 불러오는 데 사용됩니다.


### PUT /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-common-infos.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-common-infos.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductCommonInfoController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-common-infos.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| content | body | array | 아니오 | — | 본문 내용 |
| content_mode | body | string | 아니오 | `text`, `html` | 내용 표시 모드 (`text` 일반 텍스트 / `html` HTML, 미지정 시 `text`) |
| is_default | body | boolean | 아니오 | — | 기본값 지정 여부 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product-common-info.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "content": [
        "예시 내용입니다."
    ],
    "content_mode": "text",
    "is_default": true,
    "is_active": true,
    "sort_order": 1
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductCommonInfoResource` — 수정 반영 후 값)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 수정된 공통정보의 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 공통정보(수정)","en":"API Doc Sample Common Info (updated)"}` | 공통정보 이름 (로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 공통정보(수정)` | `name` 의 현재 로케일 해석 값 |
| content | object\|array | `{"ko":"배송은 결제 후 1~3일 이내 출고됩니다."}` | 안내 본문 내용 (로케일별 값 객체) |
| localized_content | string | `배송은 결제 후 1~3일 이내 출고됩니다.` | `content` 의 현재 로케일 해석 값 |
| content_mode | string | `text` | 내용 표시 모드 (`text` 일반 텍스트 / `html` HTML, 기본값 `text`) |
| is_default | boolean | `false` | 신규 상품에 기본으로 사용할 공통정보인지 여부 |
| is_active | boolean | `true` | 활성 여부 (false 이면 상품에 노출되지 않음) |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| icon | string | `info-circle` | 아이콘 식별자 (목록 UI 표시용 고정값) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:26` | 최종 수정 일시 (이번 수정 시각) |
| products_count | integer | `0` | 이 공통정보를 사용 중인 상품 수 |
| language_count | integer | `1` | 값이 입력된 로케일 개수 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "공통정보가 수정되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 공통정보(수정)",
            "en": "API Doc Sample Common Info (updated)"
        },
        "localized_name": "API 문서 샘플 공통정보(수정)",
        "content": {
            "ko": "배송은 결제 후 1~3일 이내 출고됩니다."
        },
        "localized_content": "배송은 결제 후 1~3일 이내 출고됩니다.",
        "content_mode": "text",
        "is_default": false,
        "is_active": true,
        "sort_order": 0,
        "icon": "info-circle",
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:00:26",
        "products_count": 0,
        "language_count": 1,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-common-infos.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 상품 공통정보 1건을 수정합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-common-infos.update` 권한이 필요하며, `ProductCommonInfoController@update`가 `ProductCommonInfoService::updateCommonInfo()`에 검증된 데이터를 전달해 갱신합니다. `name`(다국어 배열)은 필수이고 `content`·`content_mode`·`is_default`·`is_active`·`sort_order`를 함께 변경할 수 있습니다. 확장이 `sirsoft-ecommerce.product-common-info.update_validation_rules` 필터로 파라미터를 추가할 수 있으며, 대상이 없거나 처리 실패 시 각각 404/400 에러 응답을 반환합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id}/toggle-active
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-common-infos.toggle-active -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-common-infos.toggle-active`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductCommonInfoController@toggleActive`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-common-infos.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id}/toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-common-infos.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품 공통정보의 활성/비활성 상태를 한 번의 요청으로 토글합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-common-infos.update` 권한이 필요하며, `ProductCommonInfoController@toggleActive`가 `ProductCommonInfoService::toggleActive()`를 호출해 현재 `is_active` 값을 반전시킵니다. 반전 결과에 따라 활성화/비활성화 메시지를 구분해 응답하므로 목록 화면의 스위치 조작에 적합합니다. 대상이 없거나 처리 실패 시 각각 404/400 에러 응답을 반환합니다.


