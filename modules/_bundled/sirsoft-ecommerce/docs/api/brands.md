# Brands API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Brands 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/brands
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.brands.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.brands.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\BrandController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.brands.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| is_active | query | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| search | query | string | 아니오 | max 100 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| sort | query | string | 아니오 | `name_asc`, `name_desc`, `created_asc`, `created_desc` | 정렬 기준 (필드명, `-` 접두 시 내림차순) |
| sort_by | query | string | 아니오 | `name`, `sort_order` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| locale | query | string | 아니오 | `ko`, `en`, `fr`, `ja` | 로케일 코드 (표시 언어/지역) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/brands?is_active=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&sort=name_asc&sort_by=name&sort_order=asc&locale=ko HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `43` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `127` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 브랜드","en":"API Doc Sample Brand"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 브랜드` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| slug | string | `apidoc-sample-brand` | URL 친화 식별자 (slug) |
| url | string | `apidoc-sample-brand` | SortableMenuItem 표시용 URL (slug 값을 그대로 노출) |
| website | string | `https://www.asus.com` | 브랜드 공식 웹사이트 URL |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | boolean | `true` | active 여부 |
| icon | string | `tag` | 아이콘 식별자 (아이콘 클래스/이름) |
| created_at | string | `2026-07-07 14:47:31` | 생성 일시 |
| updated_at | string | `2026-07-07 14:47:31` | 최종 수정 일시 |
| creator | array | `[]` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| updater | array | `[]` | 수정자 정보 객체 (id/name — updater 관계 파생, 로드 시에만 포함) |
| products_count | integer | `0` | products 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "브랜드 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 1,
                "id": 1,
                "name": {
                    "ko": "API 문서 샘플 브랜드",
                    "en": "API Doc Sample Brand"
                },
                "localized_name": "API 문서 샘플 브랜드",
                "slug": "apidoc-sample-brand",
                "url": "apidoc-sample-brand",
                "website": null,
                "sort_order": 0,
                "is_active": true,
                "icon": "tag",
                "created_at": "2026-07-08 10:44:49",
                "updated_at": "2026-07-08 10:44:49",
                "creator": [],
                "updater": [],
                "products_count": 0,
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
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.brands.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자용 브랜드 목록을 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.brands.read` 권한이 필요하며, `BrandService::getAllBrands()`가 검증된 필터를 받아 조회합니다. `is_active`·`search`로 필터링하고 `sort`(name_asc/desc, created_asc/desc) 또는 `sort_by`+`sort_order` 조합으로 정렬하며, `locale`로 표시 언어를 지정할 수 있습니다. 각 항목에는 `products_count` 집계와 현재 사용자의 `abilities` 맵이 포함됩니다.


### POST /api/modules/sirsoft-ecommerce/admin/brands
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.brands.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.brands.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\BrandController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.brands.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| slug | body | string | 예 | max 200 | URL 친화 식별자 (slug) |
| website | body | string | 아니오 | max 500 | 브랜드 공식 웹사이트 URL |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.brand.create_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/brands HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "slug": "example-key",
    "website": "예시값",
    "sort_order": 1,
    "is_active": true
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.brands.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 새 브랜드를 생성합니다. `auth:sanctum` + `sirsoft-ecommerce.brands.create` 권한이 필요하며, `BrandService::createBrand()`가 트랜잭션 내에서 저장하고 생성자/수정자(`created_by`/`updated_by`)를 현재 사용자로 기록한 뒤 `BrandResource`를 201로 반환합니다. `name`(다국어 배열)과 `slug`가 필수이며 `website`·`sort_order`·`is_active`는 선택입니다. `sirsoft-ecommerce.brand.create_validation_rules` 필터로 확장이 검증 규칙을 추가할 수 있습니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/brands/{brand}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.brands.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.brands.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\BrandController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.brands.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| brand | path | string | 예 | — | 대상 brand의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/brands/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| brand_id | integer | `1` | brand 식별자 (연관 리소스 참조) |
| products_count | integer | `0` | products 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "브랜드가 삭제되었습니다.",
    "data": {
        "brand_id": 1,
        "products_count": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.brands.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 브랜드 1건을 삭제합니다. `auth:sanctum` + `sirsoft-ecommerce.brands.delete` 권한이 필요하며, `BrandService::deleteBrand()`가 삭제 전 연결된 상품 수를 확인합니다. 연결된 상품이 1개 이상이면 예외가 발생해 삭제가 차단되고 400 오류가 반환되므로, 해당 브랜드의 상품을 먼저 정리하거나 다른 브랜드로 이전해야 삭제할 수 있습니다. 대상이 없으면 404를 반환합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/brands/{brand}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.brands.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.brands.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\BrandController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.brands.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| brand | path | string | 예 | — | 대상 brand의 식별자 |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| slug | body | string | 아니오 | — | URL 친화 식별자 (slug) |
| website | body | string | 아니오 | max 500 | 브랜드 공식 웹사이트 URL |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.brand.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/brands/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "slug": "example-key",
    "website": "예시값",
    "sort_order": 1,
    "is_active": true
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.brands.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 기존 브랜드(path의 `brand`)를 수정합니다. `auth:sanctum` + `sirsoft-ecommerce.brands.update` 권한이 필요하며, `BrandService::updateBrand()`가 검증된 데이터로 갱신한 뒤 `BrandResource`를 반환합니다. `name`은 필수이고 `slug`·`website`·`sort_order`·`is_active`는 선택입니다. 대상이 없거나 처리 중 예외가 발생하면 404 또는 400을 반환하며, `sirsoft-ecommerce.brand.update_validation_rules` 필터로 확장이 검증 규칙을 추가할 수 있습니다.


### GET /api/modules/sirsoft-ecommerce/admin/brands/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.brands.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.brands.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\BrandController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.brands.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/brands/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 브랜드","en":"API Doc Sample Brand"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 브랜드` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| slug | string | `apidoc-sample-brand` | URL 친화 식별자 (slug) |
| url | string | `apidoc-sample-brand` | SortableMenuItem 표시용 URL (slug 값을 그대로 노출) |
| website | null | `null` | 브랜드 웹사이트 URL |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | boolean | `true` | active 여부 |
| icon | string | `tag` | 아이콘 식별자 (아이콘 클래스/이름) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 10:44:49` | 최종 수정 일시 |
| products_count | integer | `0` | products 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "브랜드 정보를 조회했습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 브랜드",
            "en": "API Doc Sample Brand"
        },
        "localized_name": "API 문서 샘플 브랜드",
        "slug": "apidoc-sample-brand",
        "url": "apidoc-sample-brand",
        "website": null,
        "sort_order": 0,
        "is_active": true,
        "icon": "tag",
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 10:44:49",
        "products_count": 0,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.brands.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 브랜드 1건의 상세 정보를 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.brands.read` 권한이 필요하며, path의 `id`로 `BrandService::getBrand()`를 호출해 단건을 `BrandResource`로 반환합니다. 응답에는 다국어 이름, 로컬라이즈된 이름(`localized_name`), `website`, `products_count` 집계, 현재 사용자의 `abilities` 맵이 포함됩니다. 대상 브랜드가 없으면 404를 반환합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/brands/{id}/toggle-status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.brands.toggle-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.brands.toggle-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\BrandController@toggleStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.brands.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/brands/1/toggle-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 브랜드","en":"API Doc Sample Brand"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 브랜드` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| slug | string | `apidoc-sample-brand` | URL 친화 식별자 (slug) |
| url | string | `apidoc-sample-brand` | SortableMenuItem 표시용 URL (slug 값을 그대로 노출) |
| website | null | `null` | 브랜드 웹사이트 URL |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | boolean | `false` | active 여부 |
| icon | string | `tag` | 아이콘 식별자 (아이콘 클래스/이름) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:16` | 최종 수정 일시 |
| products_count | integer | `0` | products 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.brands.status_changed",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 브랜드",
            "en": "API Doc Sample Brand"
        },
        "localized_name": "API 문서 샘플 브랜드",
        "slug": "apidoc-sample-brand",
        "url": "apidoc-sample-brand",
        "website": null,
        "sort_order": 0,
        "is_active": false,
        "icon": "tag",
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:00:16",
        "products_count": 0,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.brands.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 브랜드의 활성 상태를 토글합니다. `auth:sanctum` + `sirsoft-ecommerce.brands.update` 권한이 필요하며, path의 `id`로 `BrandService::toggleStatus()`를 호출해 트랜잭션 내에서 현재 `is_active` 값을 반전시킨 뒤 갱신된 `BrandResource`를 반환합니다. 관리자 목록에서 브랜드 노출/비노출을 빠르게 전환할 때 사용하며, 대상이 없거나 처리 중 예외가 발생하면 404 또는 400을 반환합니다.


