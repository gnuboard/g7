# Categories API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Categories 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/categories
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| parent_id | query | string | 아니오 | — | parent 식별자 |
| is_active | query | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| search | query | string | 아니오 | max 100 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| hierarchical | query | boolean | 아니오 | — | true 면 자식을 중첩한 트리 구조로 반환 |
| flat | query | boolean | 아니오 | — | true 면 깊이 들여쓰기를 포함한 평면 리스트로 반환 (TagInput 등에 사용) |
| max_depth | query | integer | 아니오 | min 1, max 10 | 조회할 최대 계층 깊이 제한 (1~10) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/categories?parent_id=%EC%98%88%EC%8B%9C%EA%B0%92&is_active=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&hierarchical=1&flat=1&max_depth=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `87` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"의류","en":"Clothing"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"다양한 스타일의 의류 제품","en":"Various styles of clothing p…` | 설명 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `의류` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| path | string | `87` | 조상부터 자기 자신까지의 ID를 `/`로 이은 materialized path (조상 조회·하위 일괄 선택에 사용) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | boolean | `true` | active 여부 |
| slug | string | `clothing` | URL 친화 식별자 (slug) |
| url | string | `clothing` | SortableMenuItem 표시용 URL (slug 값을 그대로 사용) |
| icon | string | `folder` | 아이콘 식별자 (아이콘 클래스/이름) |
| meta_title | null | `null` | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목, 미설정 시 null) |
| meta_description | null | `null` | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약, 미설정 시 null) |
| created_at | string | `2026-06-15 02:24:00` | 생성 일시 |
| updated_at | string | `2026-06-15 02:24:00` | 최종 수정 일시 |
| images | array | `[]` | 카테고리 이미지 배열 (images 관계 로드 시 — id/hash/download_url/alt_text 등) |
| products_count | integer | `22` | products 개수 (집계) |
| children_count | integer | `0` | children 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "카테고리 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "name": {
                    "ko": "API 문서 샘플 카테고리",
                    "en": "API Doc Sample Category"
                },
                "description": null,
                "localized_name": "API 문서 샘플 카테고리",
                "parent_id": null,
                "path": "0",
                "depth": 0,
                "sort_order": 0,
                "is_active": true,
                "slug": "apidoc-sample-category",
                "url": "apidoc-sample-category",
                "icon": "folder",
                "meta_title": null,
                "meta_description": null,
                "created_at": "2026-07-08 01:44:49",
                "updated_at": "2026-07-08 01:44:49",
                "images": [],
                "products_count": 0,
                "children_count": 0,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자용 카테고리 목록을 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.read` 권한이 필요하며, `CategoryService::getHierarchicalCategories()`가 검증된 필터(`parent_id`/`is_active`/`search`/`max_depth`)를 받아 조회합니다. `hierarchical=true`면 자식을 중첩한 트리, `flat=true`면 평면 리스트(TagInput 등에 사용), 둘 다 없으면 기본 계층 구조를 반환합니다. 각 항목에는 `products_count`·`children_count` 집계와 현재 사용자의 `abilities` 맵이 포함됩니다.


### POST /api/modules/sirsoft-ecommerce/admin/categories
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| description | body | array | 아니오 | — | 설명 |
| parent_id | body | string | 아니오 | — | parent 식별자 |
| slug | body | string | 예 | max 200 | URL 친화 식별자 (slug) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| meta_title | body | string | 아니오 | max 200 | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목) |
| meta_description | body | string | 아니오 | — | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약) |
| temp_key | body | string | 아니오 | max 64 | 저장 전 임시 업로드한 이미지를 이 카테고리에 연결하기 위한 FileUploader temp_key |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.category.create_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/categories HTTP/1.1
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
    "parent_id": "예시값",
    "slug": "example-key",
    "is_active": true,
    "meta_title": "예시 제목",
    "meta_description": "예시 내용입니다.",
    "temp_key": "예시값"
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 새 카테고리를 생성합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.create` 권한이 필요하며, `CategoryService::createCategory()`가 검증된 데이터로 저장한 뒤 `CategoryResource`를 201로 반환합니다. `name`(다국어 배열)과 `slug`는 필수이고, `parent_id`를 지정하면 해당 카테고리의 하위로 배치되어 path/depth가 계산됩니다. `temp_key`로 사전 업로드해 둔 임시 이미지를 이 시점에 카테고리에 연결할 수 있으며, `sirsoft-ecommerce.category.create_validation_rules` 필터로 확장이 추가 파라미터를 검증에 주입할 수 있습니다.


### POST /api/modules/sirsoft-ecommerce/admin/categories/images
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.images.upload-temp -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.images.upload-temp`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@uploadImage`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 10240 | 업로드 파일 |
| temp_key | body | string | 아니오 | max 64 | 사전 업로드한 임시 이미지를 이 카테고리에 연결하기 위한 FileUploader temp_key |
| collection | body | string | 아니오 | max 100 | 첨부 컬렉션 그룹명 (첨부를 용도별로 묶는 키, 미지정 시 default) |
| alt_text | body | array | 아니오 | — | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.category-image.filter_upload_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/categories/images HTTP/1.1
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
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 카테고리에 아직 귀속되지 않은 이미지를 임시로 업로드합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.update` 권한이 필요하며, path에 `categoryId`가 없으므로 `CategoryImageService::upload()`가 `temp_key` 기준의 임시 이미지로 저장합니다. 카테고리 생성/수정 폼에서 저장 전에 이미지를 먼저 올릴 때 사용하며, 이후 store/update 요청에 같은 `temp_key`를 전달하면 해당 카테고리에 연결됩니다. 응답은 FileUploader 컴포넌트가 기대하는 `data.data` 형식으로 업로드 이미지의 id/hash/download_url 등을 201로 반환합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/categories/images/reorder
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.images.reorder -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.images.reorder`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@reorderImages`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | body | array | 예 | min 1 | 이미지 순서 배열 (각 항목 `{id, order}` — 이미지 id별 새 정렬 순서) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.category-image.filter_reorder_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/categories/images/reorder HTTP/1.1
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 카테고리 이미지들의 표시 순서를 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.update` 권한이 필요하며, `order` 배열(각 항목 `{id, order}`)을 받아 컨트롤러가 `id => order` 맵으로 변환한 뒤 `CategoryImageService::reorder()`에 전달합니다. 여러 이미지를 등록한 카테고리에서 드래그로 순서를 재배열할 때 사용하며, `sirsoft-ecommerce.category-image.filter_reorder_validation_rules` 필터로 확장이 검증 규칙을 추가할 수 있습니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/categories/images/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.images.delete -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.images.delete`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@deleteImage`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/categories/images/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-404 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 카테고리 이미지 1건을 삭제합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.update` 권한이 필요하며, path의 이미지 `id`로 `CategoryImageService::delete()`를 호출해 레코드와 저장 파일을 제거합니다. 대상 이미지가 존재하지 않으면 404를 반환합니다. 카테고리 편집 화면에서 등록된 이미지나 임시 업로드 이미지를 개별 제거할 때 사용합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/categories/order
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.reorder -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.reorder`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@reorder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| parent_menus | body | array | 아니오 | — | 최상위 카테고리 순서 배열 (SortableMenuList — 각 항목 `{id, order}`, child_menus 없으면 필수) |
| child_menus | body | array | 아니오 | — | 부모 ID별 자식 카테고리 순서 맵 (`{부모id: [{id, order}, ...]}`, parent_menus 없으면 필수) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.category.reorder_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/categories/order HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "parent_menus": [
        "예시값"
    ],
    "child_menus": [
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 카테고리 트리 전체의 배치(부모-자식 관계와 정렬 순서)를 일괄 갱신합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.update` 권한이 필요하며, SortableMenuList 컴포넌트가 보내는 `parent_menus`/`child_menus` 형식을 컨트롤러가 `{id, parent_id, sort_order}` 목록으로 변환해 `CategoryService::reorder()`(트랜잭션)에 전달합니다. `parent_id`가 바뀐 항목은 depth와 materialized path가 함께 재계산됩니다. 관리자 카테고리 관리 화면에서 드래그 앤 드롭으로 계층 구조를 재정렬할 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/admin/categories/tree
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.tree -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.tree`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@tree`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/categories/tree HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `87` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"의류","en":"Clothing"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"다양한 스타일의 의류 제품","en":"Various styles of clothing p…` | 설명 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `의류` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| path | string | `87` | 조상부터 자기 자신까지의 ID를 `/`로 이은 materialized path (조상 조회·하위 일괄 선택에 사용) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | boolean | `true` | active 여부 |
| slug | string | `clothing` | URL 친화 식별자 (slug) |
| url | string | `clothing` | SortableMenuItem 표시용 URL (slug 값을 그대로 사용) |
| icon | string | `folder` | 아이콘 식별자 (아이콘 클래스/이름) |
| meta_title | null | `null` | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목, 미설정 시 null) |
| meta_description | null | `null` | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약, 미설정 시 null) |
| created_at | string | `2026-06-15 02:24:00` | 생성 일시 |
| updated_at | string | `2026-06-15 02:24:00` | 최종 수정 일시 |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| children | array | `[{"id":88,"name":{"ko":"남성","en":"Men"},"description":{"k…` | 하위 항목 배열 (계층 트리 — children 관계 파생) |
| images | array | `[]` | 카테고리 이미지 배열 (images 관계 로드 시 — id/hash/download_url/alt_text 등) |
| products_count | integer | `22` | products 개수 (집계) |
| children_count | integer | `2` | children 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "카테고리 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "name": {
                    "ko": "API 문서 샘플 카테고리",
                    "en": "API Doc Sample Category"
                },
                "description": null,
                "localized_name": "API 문서 샘플 카테고리",
                "parent_id": null,
                "path": "0",
                "depth": 0,
                "sort_order": 0,
                "is_active": true,
                "slug": "apidoc-sample-category",
                "url": "apidoc-sample-category",
                "icon": "folder",
                "meta_title": null,
                "meta_description": null,
                "created_at": "2026-07-08 01:44:49",
                "updated_at": "2026-07-08 01:44:49",
                "parent": null,
                "children": [],
                "images": [],
                "products_count": 0,
                "children_count": 0,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 상품 등록 폼용 카테고리 트리를 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.read` 권한이 필요하며, 별도 파라미터 없이 `CategoryService::getHierarchicalCategories(['hierarchical' => true, 'is_active' => true])`를 호출해 활성 카테고리만 자식을 중첩한 트리로 반환합니다. index 엔드포인트와 달리 필터를 받지 않고 항상 활성 트리를 반환하므로, 상품 작성/수정 시 카테고리 선택 UI를 채우는 용도로 사용됩니다.


### POST /api/modules/sirsoft-ecommerce/admin/categories/{categoryId}/images
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.images.upload -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.images.upload`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@uploadImage`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| categoryId | path | string | 예 | — | 대상 category의 식별자 |
| file | body | file | 예 | max 10240 | 업로드 파일 |
| temp_key | body | string | 아니오 | max 64 | 사전 업로드한 임시 이미지를 이 카테고리에 연결하기 위한 FileUploader temp_key |
| collection | body | string | 아니오 | max 100 | 첨부 컬렉션 그룹명 (첨부를 용도별로 묶는 키, 미지정 시 default) |
| alt_text | body | array | 아니오 | — | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.category-image.filter_upload_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/categories/{categoryId}/images HTTP/1.1
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
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 카테고리(path의 `categoryId`)에 이미지 1건을 업로드해 즉시 귀속시킵니다. `auth:sanctum` + `sirsoft-ecommerce.categories.update` 권한이 필요하며, `CategoryImageService::upload()`가 `categoryId`와 함께 파일을 저장하므로 임시 업로드와 달리 해당 카테고리에 바로 연결됩니다. 대상 카테고리가 없으면 404를 반환하고, 응답은 FileUploader가 기대하는 `data.data` 형식으로 업로드 이미지 정보를 201로 반환합니다. 이미 존재하는 카테고리를 편집하며 이미지를 추가할 때 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/categories/{category}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| category | path | string | 예 | — | 분류 필터 (해당 분류의 항목만 조회) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/categories/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| category_id | integer | `1` | category 식별자 (연관 리소스 참조) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "카테고리가 삭제되었습니다.",
    "data": {
        "category_id": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 카테고리 1건을 삭제합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.delete` 권한이 필요하며, `CategoryService::deleteCategory()`가 삭제 전 안전 검사를 수행합니다. 하위 카테고리가 있거나 연결된 상품이 존재하면 예외가 발생해 삭제가 차단되고 400 오류가 반환되므로, 자식과 상품을 먼저 정리해야 삭제할 수 있습니다. 대상이 없으면 404를 반환합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/categories/{category}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| category | path | string | 예 | — | 분류 필터 (해당 분류의 항목만 조회) |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| description | body | array | 아니오 | — | 설명 |
| parent_id | body | string | 아니오 | — | parent 식별자 |
| slug | body | string | 예 | max 200 | URL 친화 식별자 (slug) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| meta_title | body | string | 아니오 | max 200 | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목) |
| meta_description | body | string | 아니오 | — | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약) |
| temp_key | body | string | 아니오 | max 64 | 저장 전 임시 업로드한 이미지를 이 카테고리에 연결하기 위한 FileUploader temp_key |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.category.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/categories/1 HTTP/1.1
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
    "parent_id": "예시값",
    "slug": "example-key",
    "is_active": true,
    "meta_title": "예시 제목",
    "meta_description": "예시 내용입니다.",
    "temp_key": "예시값"
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 기존 카테고리(path의 `category`)를 수정합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.update` 권한이 필요하며, `CategoryService::updateCategory()`가 검증된 데이터로 갱신한 뒤 `CategoryResource`를 반환합니다. `name`과 `slug`는 필수이고, `parent_id`를 변경하면 계층 위치(path/depth)가 재계산됩니다. `temp_key`로 임시 업로드한 이미지를 이 시점에 연결할 수 있으며, 대상이 없거나 처리 중 예외가 발생하면 404 또는 400을 반환합니다. `sirsoft-ecommerce.category.update_validation_rules` 필터로 확장이 검증 규칙을 추가할 수 있습니다.


### GET /api/modules/sirsoft-ecommerce/admin/categories/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/categories/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 카테고리","en":"API Doc Sample Category"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | null | `null` | 설명 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 카테고리` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| path | string | `0` | Materialized Path: 1/5/23 |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | boolean | `true` | active 여부 |
| slug | string | `apidoc-sample-category` | URL 친화 식별자 (slug) |
| url | string | `apidoc-sample-category` | SortableMenuItem 표시용 URL (slug 값을 그대로 사용) |
| icon | string | `folder` | 아이콘 식별자 (아이콘 클래스/이름) |
| meta_title | null | `null` | SEO 제목 (다국어 JSON) |
| meta_description | null | `null` | SEO 설명 (다국어 JSON) |
| created_at | string | `2026-07-08 01:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 01:44:49` | 최종 수정 일시 |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| children | array | `[]` | 하위 항목 배열 (계층 트리 — children 관계 파생) |
| images | array | `[]` | 카테고리 이미지 배열 (images 관계 로드 시 — id/hash/download_url/alt_text 등) |
| products_count | integer | `0` | products 개수 (집계) |
| children_count | integer | `0` | children 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "카테고리 정보를 조회했습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 카테고리",
            "en": "API Doc Sample Category"
        },
        "description": null,
        "localized_name": "API 문서 샘플 카테고리",
        "parent_id": null,
        "path": "0",
        "depth": 0,
        "sort_order": 0,
        "is_active": true,
        "slug": "apidoc-sample-category",
        "url": "apidoc-sample-category",
        "icon": "folder",
        "meta_title": null,
        "meta_description": null,
        "created_at": "2026-07-08 01:44:49",
        "updated_at": "2026-07-08 01:44:49",
        "parent": null,
        "children": [],
        "images": [],
        "products_count": 0,
        "children_count": 0,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 카테고리 1건의 상세 정보를 조회합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.read` 권한이 필요하며, path의 `id`로 `CategoryService::getCategory()`를 호출해 단건을 `CategoryResource`로 반환합니다. 응답에는 부모(`parent`)·자식(`children`)·이미지(`images`) 관계와 `products_count`·`children_count` 집계, 현재 사용자의 `abilities` 맵이 포함됩니다. 대상 카테고리가 없으면 404를 반환합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/categories/{id}/toggle-status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.categories.toggle-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.categories.toggle-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController@toggleStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.categories.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/categories/1/toggle-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 카테고리","en":"API Doc Sample Category"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | null | `null` | 설명 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 카테고리` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| path | string | `0` | Materialized Path: 1/5/23 |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | boolean | `false` | active 여부 |
| slug | string | `apidoc-sample-category` | URL 친화 식별자 (slug) |
| url | string | `apidoc-sample-category` | SortableMenuItem 표시용 URL (slug 값을 그대로 사용) |
| icon | string | `folder` | 아이콘 식별자 (아이콘 클래스/이름) |
| meta_title | null | `null` | SEO 제목 (다국어 JSON) |
| meta_description | null | `null` | SEO 설명 (다국어 JSON) |
| created_at | string | `2026-07-08 01:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 06:00:17` | 최종 수정 일시 |
| images | array | `[]` | 카테고리 이미지 배열 (images 관계 로드 시 — id/hash/download_url/alt_text 등) |
| products_count | integer | `0` | products 개수 (집계) |
| children_count | integer | `0` | children 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.categories.status_changed",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 카테고리",
            "en": "API Doc Sample Category"
        },
        "description": null,
        "localized_name": "API 문서 샘플 카테고리",
        "parent_id": null,
        "path": "0",
        "depth": 0,
        "sort_order": 0,
        "is_active": false,
        "slug": "apidoc-sample-category",
        "url": "apidoc-sample-category",
        "icon": "folder",
        "meta_title": null,
        "meta_description": null,
        "created_at": "2026-07-08 01:44:49",
        "updated_at": "2026-07-08 06:00:17",
        "images": [],
        "products_count": 0,
        "children_count": 0,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 카테고리의 활성 상태를 토글합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.update` 권한이 필요하며, path의 `id`로 `CategoryService::toggleStatus()`를 호출해 현재 `is_active` 값을 반전시킨 뒤 갱신된 `CategoryResource`를 반환합니다. 관리자 목록에서 노출/비노출을 빠르게 전환할 때 사용하며, 대상이 없거나 처리 중 예외가 발생하면 404 또는 400을 반환합니다.


### GET /api/modules/sirsoft-ecommerce/categories
<!-- @generated:start:api.modules.sirsoft-ecommerce.categories.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.categories.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CategoryController@index`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/categories HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `87` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"의류","en":"Clothing"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `의류` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| slug | string | `clothing` | URL 친화 식별자 (slug) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| products_count | integer | `22` | products 개수 (집계) |
| children | array | `[{"id":88,"name":{"ko":"남성","en":"Men"},"name_localized":…` | 하위 항목 배열 (계층 트리 — children 관계 파생) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "카테고리 목록을 조회했습니다.",
    "data": [
        {
            "id": 1,
            "name": {
                "ko": "API 문서 샘플 카테고리",
                "en": "API Doc Sample Category"
            },
            "name_localized": "API 문서 샘플 카테고리",
            "slug": "apidoc-sample-category",
            "depth": 0,
            "parent_id": null,
            "products_count": 0,
            "children": []
        }
    ]
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** 공개 카테고리 트리를 조회합니다. 인증이 필요 없는 공개 엔드포인트이며, `Public\CategoryController@index`가 `CategoryService::getPublicCategoryTree()`를 호출해 활성 카테고리만 자식을 중첩한 트리로 반환합니다. 각 항목에는 로컬라이즈된 이름(`name_localized`)과 공개 상품 수(`products_count`)가 포함됩니다. 스토어프론트의 카테고리 내비게이션/메뉴를 렌더링하는 데 사용하며, 조회 실패 시 500을 반환합니다.


### GET /api/modules/sirsoft-ecommerce/categories/{slug}
<!-- @generated:start:api.modules.sirsoft-ecommerce.categories.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.categories.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CategoryController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/categories/apidoc-sample-category HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 카테고리","en":"API Doc Sample Category"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `API 문서 샘플 카테고리` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| description | null | `null` | 설명 (다국어 필드는 로케일별 값 객체) |
| description_localized | null | `null` | `description` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| slug | string | `apidoc-sample-category` | URL 친화 식별자 (slug) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| products_count | integer | `0` | products 개수 (집계) |
| breadcrumb | array | `[{"id":1,"name":"API 문서 샘플 카테고리","slug":"apidoc-sample-ca…` | 최상위부터 현재 카테고리까지의 상위 경로 배열 (각 항목에 id·현지화 name·slug — 스토어프론트 breadcrumb 표시용) |
| images | array | `[]` | 카테고리 이미지 배열 (images 관계 로드 시 — id/hash/download_url/alt_text 등) |
| children | array | `[]` | 하위 항목 배열 (계층 트리 — children 관계 파생) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.categories.fetch_success",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 카테고리",
            "en": "API Doc Sample Category"
        },
        "name_localized": "API 문서 샘플 카테고리",
        "description": null,
        "description_localized": null,
        "slug": "apidoc-sample-category",
        "depth": 0,
        "parent_id": null,
        "products_count": 0,
        "breadcrumb": [
            {
                "id": 1,
                "name": "API 문서 샘플 카테고리",
                "slug": "apidoc-sample-category"
            }
        ],
        "images": [],
        "children": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** slug로 단일 공개 카테고리와 직계 자식을 조회합니다. 인증이 필요 없는 공개 엔드포인트이며, `Public\CategoryController@show`가 `CategoryService::getPublicCategoryBySlug()`를 호출해 활성 자식(`activeChildren`)과 이미지를 함께 로드합니다. 조회된 카테고리가 비활성(`is_active=false`)이면 없는 것으로 간주해 404를 반환하며, 응답에는 상위 경로를 나타내는 `breadcrumb` 배열과 `products_count` 집계가 포함됩니다. 스토어프론트 카테고리 상세/목록 페이지 진입 시 사용합니다.


