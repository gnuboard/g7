# Categories API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Categories 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
| id | integer | `29` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"의류","en":"Clothing"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"다양한 스타일의 의류 제품","en":"Various styles of clothing p…` | 설명 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `의류` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| path | string | `29` | 조상부터 자기 자신까지의 ID를 `/`로 이은 materialized path (조상 조회·하위 일괄 선택에 사용) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | boolean | `true` | active 여부 |
| slug | string | `clothing` | URL 친화 식별자 (slug) |
| url | string | `clothing` | SortableMenuItem 표시용 URL (slug 값을 그대로 사용) |
| icon | string | `folder` | 아이콘 식별자 (아이콘 클래스/이름) |
| meta_title | null | `null` | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목, 미설정 시 null) |
| meta_description | null | `null` | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약, 미설정 시 null) |
| created_at | string | `2026-07-30 14:35:46` | 생성 일시 |
| updated_at | string | `2026-07-30 14:35:46` | 최종 수정 일시 |
| images | array | `[]` | 카테고리 이미지 배열 (images 관계 로드 시 — id/hash/download_url/alt_text 등) |
| products_count | integer | `24` | products 개수 (집계) |
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
                "id": 29,
                "name": {
                    "ko": "의류",
                    "en": "Clothing"
                },
                "description": {
                    "ko": "다양한 스타일의 의류 제품",
                    "en": "Various styles of clothing products"
                },
                "localized_name": "의류",
                "parent_id": null,
                "path": "29",
                "depth": 0,
                "sort_order": 0,
                "is_active": true,
                "slug": "clothing",
                "url": "clothing",
                "icon": "folder",
                "meta_title": null,
                "meta_description": null,
                "created_at": "2026-07-30 14:35:46",
                "updated_at": "2026-07-30 14:35:46",
                "images": [],
                "products_count": 24,
                "children_count": 0,
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            {
                "id": 38,
                "name": {
                    "ko": "전자기기",
                    "en": "Electronics"
                },
                "description": {
                    "ko": "최신 전자제품과 기기",
                    "en": "Latest electronic products and devices"
                },
                "localized_name": "전자기기",
                "parent_id": null,
                "path": "38",
                "depth": 0,
                "sort_order": 1,
                "is_active": true,
                "slug": "electronics",
                "url": "electronics",
                "icon": "folder",
                "meta_title": null,
                "meta_description": null,
                "created_at": "2026-07-30 14:35:47",
                "updated_at": "2026-07-30 14:35:47",
                "images": [],
                "products_count": 15,
                "children_count": 0,
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            "... (총 8건 중 2건 표시)"
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

_단건 응답: `data` 객체의 필드 (`CategoryResource`). 생성 직후 `fresh(['images'])` 로 재조회하므로 `images` 관계만 포함되고 `parent`/`children` 키는 나타나지 않습니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"의류","en":"Clothing"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object\|null | `null` | 설명 (다국어 필드는 로케일별 값 객체, 미설정 시 null) |
| localized_name | string | `의류` | `name` 의 현재 로케일 해석 값 |
| parent_id | integer\|null | `null` | 상위 카테고리 ID (최상위면 null) |
| path | string | `1` | 조상부터 자기 자신까지의 ID를 `/`로 이은 materialized path |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위) |
| sort_order | integer | `0` | 표시 정렬 순서 값 (미지정 시 형제 마지막 순번 자동 부여) |
| is_active | boolean | `true` | 활성 여부 |
| slug | string | `clothing` | URL 친화 식별자 (slug) |
| url | string | `clothing` | SortableMenuItem 표시용 URL (slug 값을 그대로 사용) |
| icon | string | `folder` | SortableMenuList 표시용 아이콘 (리소스에서 고정값 `folder`) |
| meta_title | string\|null | `null` | SEO 메타 제목 (미설정 시 null) |
| meta_description | string\|null | `null` | SEO 메타 설명 (미설정 시 null) |
| created_at | string | `2026-07-08 01:44:49` | 생성 일시 (`Y-m-d H:i:s`) |
| updated_at | string | `2026-07-08 01:44:49` | 최종 수정 일시 (`Y-m-d H:i:s`) |
| images | array | `[]` | 카테고리 이미지 배열 (각 항목: id/hash/original_filename/mime_type/size/size_formatted/download_url/order/is_image/alt_text). `temp_key` 로 연결된 임시 이미지가 여기에 포함됨 |
| products_count | integer | `0` | 연결된 상품 개수 (집계) |
| children_count | integer | `0` | 하위 카테고리 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "카테고리가 등록되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 카테고리",
            "en": "API Doc Sample Category"
        },
        "description": null,
        "localized_name": "API 문서 샘플 카테고리",
        "parent_id": null,
        "path": "1",
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
}
```

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
| alt_text.ko | body | string | 아니오 | max 255 | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) — `ko` 로케일 값 |
| alt_text.en | body | string | 아니오 | max 255 | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) — `en` 로케일 값 |

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
------G7ExampleBoundary
Content-Disposition: form-data; name="alt_text.ko"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="alt_text.en"

예시값
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: FileUploader 컴포넌트가 `response.data?.data` 형식을 기대하므로 컨트롤러가 `data` 안에 한 번 더 `data` 객체를 감싸 반환합니다. 아래는 `data.data` 객체의 필드입니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 업로드된 카테고리 이미지의 기본 키 |
| hash | string | `9f2c1b8e4a...` | 이미지 고유 해시 (다운로드 URL 식별자) |
| original_filename | string | `banner.png` | 업로드 당시의 원본 파일명 |
| mime_type | string | `image/png` | 파일 MIME 타입 |
| size | integer | `102400` | 파일 크기 (바이트) |
| size_formatted | string | `100 KB` | 사람이 읽기 쉬운 형식으로 변환한 파일 크기 |
| download_url | string | `/api/modules/sirsoft-ecommerce/admin/categories/images/download/9f2c1b8e4a...` | 이미지 다운로드 URL |
| order | integer | `1` | 이미지 표시 순서 (`sort_order`, 미설정 시 1) |
| is_image | boolean | `true` | MIME 타입이 `image/` 로 시작하는지 여부 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.category_images.uploaded",
    "data": {
        "data": {
            "id": 1,
            "hash": "9f2c1b8e4a7d5c0361b2e8f4a9d7c015",
            "original_filename": "banner.png",
            "mime_type": "image/png",
            "size": 102400,
            "size_formatted": "100 KB",
            "download_url": "/api/modules/sirsoft-ecommerce/admin/categories/images/download/9f2c1b8e4a7d5c0361b2e8f4a9d7c015",
            "order": 1,
            "is_image": true
        }
    }
}
```

> `message` 는 `sirsoft-ecommerce::messages.category_images.uploaded` 키를 사용하는데, 현재 모듈 `lang/ko/messages.php` 에 `category_images` 섹션이 정의되어 있지 않아 번역되지 않은 키 문자열이 그대로 반환됩니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 파일 저장 중 예외 발생 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `file` 은 필수이며 `jpeg,png,jpg,gif,svg,webp` 이미지만 허용, 최대 10MB |

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

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.category_images.reordered"
}
```

> `message` 는 `sirsoft-ecommerce::messages.category_images.reordered` 키를 사용하는데, 현재 모듈 `lang/ko/messages.php` 에 `category_images` 섹션이 정의되어 있지 않아 번역되지 않은 키 문자열이 그대로 반환됩니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 순서 갱신 처리 중 예외 발생 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `order` 는 1건 이상 필수이며 각 항목에 정수 `id` 와 0 이상의 정수 `order` 가 필요 |

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
DELETE /api/modules/sirsoft-ecommerce/admin/categories/images/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.category_images.deleted"
}
```

> `message` 는 `sirsoft-ecommerce::messages.category_images.deleted` 키를 사용하는데, 현재 모듈 `lang/ko/messages.php` 에 `category_images` 섹션이 정의되어 있지 않아 번역되지 않은 키 문자열이 그대로 반환됩니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 이미지 삭제 처리 중 예외 발생 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 404 | Not Found | path 의 이미지 `id` 에 해당하는 카테고리 이미지가 없는 경우 (`messages.category_images.not_found`) |

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

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.categories.order_updated"
}
```

> `message` 는 `sirsoft-ecommerce::messages.categories.order_updated` 키를 사용하는데, 현재 모듈 `lang/ko/messages.php` 의 `categories` 섹션에 `order_updated` 키가 정의되어 있지 않아 번역되지 않은 키 문자열이 그대로 반환됩니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 순서 갱신 트랜잭션 중 예외 발생 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `parent_menus` / `child_menus` 중 최소 하나가 필요하며, 각 항목의 `id` 는 실제 존재하는 카테고리여야 하고 `order` 는 0 이상의 정수 |

<!-- @generated:end -->

**설명** 카테고리 트리 전체의 배치(부모-자식 관계와 정렬 순서)를 일괄 갱신합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.update` 권한이 필요하며, SortableMenuList 컴포넌트가 보내는 `parent_menus`/`child_menus` 형식을 컨트롤러가 `{id, parent_id, sort_order}` 목록으로 변환해 `CategoryService::reorder()`(트랜잭션)에 전달합니다. `parent_id`가 바뀐 항목은 depth와 materialized path가 함께 재계산됩니다. 관리자 카테고리 관리 화면에서 드래그 앤 드롭으로 계층 구조를 재정렬할 때 사용합니다. `child_menus`의 각 항목은 지정한 부모의 조상이 될 수 없습니다 — 순환이 만들어지는 이동은 422(`child_menus.{parentId}.{index}.id`)로 거절됩니다.


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
| id | integer | `29` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"의류","en":"Clothing"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"다양한 스타일의 의류 제품","en":"Various styles of clothing p…` | 설명 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `의류` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| path | string | `29` | 조상부터 자기 자신까지의 ID를 `/`로 이은 materialized path (조상 조회·하위 일괄 선택에 사용) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | boolean | `true` | active 여부 |
| slug | string | `clothing` | URL 친화 식별자 (slug) |
| url | string | `clothing` | SortableMenuItem 표시용 URL (slug 값을 그대로 사용) |
| icon | string | `folder` | 아이콘 식별자 (아이콘 클래스/이름) |
| meta_title | null | `null` | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목, 미설정 시 null) |
| meta_description | null | `null` | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약, 미설정 시 null) |
| created_at | string | `2026-07-30 14:35:46` | 생성 일시 |
| updated_at | string | `2026-07-30 14:35:46` | 최종 수정 일시 |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| children | array | `[{"id":30,"name":{"ko":"남성","en":"Men"},"description":{"k…` | 하위 항목 배열 (계층 트리 — children 관계 파생) |
| images | array | `[]` | 카테고리 이미지 배열 (images 관계 로드 시 — id/hash/download_url/alt_text 등) |
| products_count | integer | `24` | products 개수 (집계) |
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
                "id": 29,
                "name": {
                    "ko": "의류",
                    "en": "Clothing"
                },
                "description": {
                    "ko": "다양한 스타일의 의류 제품",
                    "en": "Various styles of clothing products"
                },
                "localized_name": "의류",
                "parent_id": null,
                "...": "(17개 키 생략, 총 22개)"
            },
            {
                "id": 38,
                "name": {
                    "ko": "전자기기",
                    "en": "Electronics"
                },
                "description": {
                    "ko": "최신 전자제품과 기기",
                    "en": "Latest electronic products and devices"
                },
                "localized_name": "전자기기",
                "parent_id": null,
                "...": "(17개 키 생략, 총 22개)"
            },
            "... (총 8건 중 2건 표시)"
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
| alt_text.ko | body | string | 아니오 | max 255 | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) — `ko` 로케일 값 |
| alt_text.en | body | string | 아니오 | max 255 | 이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구) — `en` 로케일 값 |

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
------G7ExampleBoundary
Content-Disposition: form-data; name="alt_text.ko"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="alt_text.en"

예시값
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: FileUploader 컴포넌트가 `response.data?.data` 형식을 기대하므로 컨트롤러가 `data` 안에 한 번 더 `data` 객체를 감싸 반환합니다. 아래는 `data.data` 객체의 필드입니다 (임시 업로드 엔드포인트와 동일 형식, 다만 이미지가 path 의 `categoryId` 에 즉시 귀속됩니다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 업로드된 카테고리 이미지의 기본 키 |
| hash | string | `9f2c1b8e4a...` | 이미지 고유 해시 (다운로드 URL 식별자) |
| original_filename | string | `banner.png` | 업로드 당시의 원본 파일명 |
| mime_type | string | `image/png` | 파일 MIME 타입 |
| size | integer | `102400` | 파일 크기 (바이트) |
| size_formatted | string | `100 KB` | 사람이 읽기 쉬운 형식으로 변환한 파일 크기 |
| download_url | string | `/api/modules/sirsoft-ecommerce/admin/categories/images/download/9f2c1b8e4a...` | 이미지 다운로드 URL |
| order | integer | `1` | 이미지 표시 순서 (`sort_order`, 미설정 시 1) |
| is_image | boolean | `true` | MIME 타입이 `image/` 로 시작하는지 여부 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.category_images.uploaded",
    "data": {
        "data": {
            "id": 2,
            "hash": "1c47e9a5b60d38f27ea4c9d015b7e8f3",
            "original_filename": "banner.png",
            "mime_type": "image/png",
            "size": 102400,
            "size_formatted": "100 KB",
            "download_url": "/api/modules/sirsoft-ecommerce/admin/categories/images/download/1c47e9a5b60d38f27ea4c9d015b7e8f3",
            "order": 1,
            "is_image": true
        }
    }
}
```

> `message` 는 `sirsoft-ecommerce::messages.category_images.uploaded` 키를 사용하는데, 현재 모듈 `lang/ko/messages.php` 에 `category_images` 섹션이 정의되어 있지 않아 번역되지 않은 키 문자열이 그대로 반환됩니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 파일 저장 중 예외 발생 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `file` 은 필수이며 `jpeg,png,jpg,gif,svg,webp` 이미지만 허용, 최대 10MB |

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
DELETE /api/modules/sirsoft-ecommerce/admin/categories/{category} HTTP/1.1
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
PUT /api/modules/sirsoft-ecommerce/admin/categories/{category} HTTP/1.1
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

_단건 응답: `data` 객체의 필드 (`CategoryResource`). 수정 직후 `fresh(['images'])` 로 재조회하므로 `images` 관계만 포함되고 `parent`/`children` 키는 나타나지 않습니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"의류","en":"Clothing"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object\|null | `null` | 설명 (다국어 필드는 로케일별 값 객체, 미설정 시 null) |
| localized_name | string | `의류` | `name` 의 현재 로케일 해석 값 |
| parent_id | integer\|null | `null` | 상위 카테고리 ID (변경 시 depth/path 재계산) |
| path | string | `1` | 조상부터 자기 자신까지의 ID를 `/`로 이은 materialized path |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위) |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| is_active | boolean | `true` | 활성 여부 |
| slug | string | `clothing` | URL 친화 식별자 (slug) |
| url | string | `clothing` | SortableMenuItem 표시용 URL (slug 값을 그대로 사용) |
| icon | string | `folder` | SortableMenuList 표시용 아이콘 (리소스에서 고정값 `folder`) |
| meta_title | string\|null | `null` | SEO 메타 제목 (미설정 시 null) |
| meta_description | string\|null | `null` | SEO 메타 설명 (미설정 시 null) |
| created_at | string | `2026-07-08 01:44:49` | 생성 일시 (`Y-m-d H:i:s`) |
| updated_at | string | `2026-07-08 06:00:17` | 최종 수정 일시 (`Y-m-d H:i:s`) |
| images | array | `[]` | 카테고리 이미지 배열 (각 항목: id/hash/original_filename/mime_type/size/size_formatted/download_url/order/is_image/alt_text) |
| products_count | integer | `0` | 연결된 상품 개수 (집계) |
| children_count | integer | `0` | 하위 카테고리 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "카테고리가 수정되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 카테고리",
            "en": "API Doc Sample Category"
        },
        "description": null,
        "localized_name": "API 문서 샘플 카테고리",
        "parent_id": null,
        "path": "1",
        "depth": 0,
        "sort_order": 0,
        "is_active": true,
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
| 400 | Bad Request | 대상 카테고리가 없거나 수정 트랜잭션 중 예외 발생 (컨트롤러가 예외를 잡아 `exceptions.operation_failed` 로 응답) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.categories.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 기존 카테고리(path의 `category`)를 수정합니다. `auth:sanctum` + `sirsoft-ecommerce.categories.update` 권한이 필요하며, `CategoryService::updateCategory()`가 검증된 데이터로 갱신한 뒤 `CategoryResource`를 반환합니다. `name`과 `slug`는 필수이고, `parent_id`를 변경하면 계층 위치(path/depth)가 재계산됩니다. `temp_key`로 임시 업로드한 이미지를 이 시점에 연결할 수 있으며, 대상이 없거나 처리 중 예외가 발생하면 404 또는 400을 반환합니다. `sirsoft-ecommerce.category.update_validation_rules` 필터로 확장이 검증 규칙을 추가할 수 있습니다. `parent_id`에는 자기 자신이나 자신의 하위 카테고리를 지정할 수 없습니다 — 순환이 만들어지는 요청은 422(`parent_id`)로 거절됩니다.


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
GET /api/modules/sirsoft-ecommerce/admin/categories/{id} HTTP/1.1
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
PATCH /api/modules/sirsoft-ecommerce/admin/categories/{id}/toggle-status HTTP/1.1
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
| id | integer | `29` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"의류","en":"Clothing"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `의류` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| slug | string | `clothing` | URL 친화 식별자 (slug) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| products_count | integer | `24` | products 개수 (집계) |
| children | array | `[{"id":30,"name":{"ko":"남성","en":"Men"},"name_localized":…` | 하위 항목 배열 (계층 트리 — children 관계 파생) |

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
            "id": 29,
            "name": {
                "ko": "의류",
                "en": "Clothing"
            },
            "name_localized": "의류",
            "slug": "clothing",
            "depth": 0,
            "...": "(3개 키 생략, 총 8개)"
        },
        {
            "id": 38,
            "name": {
                "ko": "전자기기",
                "en": "Electronics"
            },
            "name_localized": "전자기기",
            "slug": "electronics",
            "depth": 0,
            "...": "(3개 키 생략, 총 8개)"
        },
        {
            "id": 46,
            "name": {
                "ko": "가구",
                "en": "Furniture"
            },
            "name_localized": "가구",
            "slug": "furniture",
            "depth": 0,
            "...": "(3개 키 생략, 총 8개)"
        },
        {
            "id": 51,
            "name": {
                "ko": "식품",
                "en": "Food"
            },
            "name_localized": "식품",
            "slug": "food",
            "depth": 0,
            "...": "(3개 키 생략, 총 8개)"
        },
        {
            "id": 56,
            "name": {
                "ko": "스포츠",
                "en": "Sports"
            },
            "name_localized": "스포츠",
            "slug": "sports",
            "depth": 0,
            "...": "(3개 키 생략, 총 8개)"
        },
        "... (총 8건 중 5건 표시)"
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 500 | Internal Server Error | 카테고리 트리 조회 중 예외가 발생한 경우 (컨트롤러가 예외를 잡아 `messages.categories.fetch_failed` 로 응답) |

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
GET /api/modules/sirsoft-ecommerce/categories/{slug} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** slug로 단일 공개 카테고리와 직계 자식을 조회합니다. 인증이 필요 없는 공개 엔드포인트이며, `Public\CategoryController@show`가 `CategoryService::getPublicCategoryBySlug()`를 호출해 활성 자식(`activeChildren`)과 이미지를 함께 로드합니다. 조회된 카테고리가 비활성(`is_active=false`)이면 없는 것으로 간주해 404를 반환하며, 응답에는 상위 경로를 나타내는 `breadcrumb` 배열과 `products_count` 집계가 포함됩니다. 스토어프론트 카테고리 상세/목록 페이지 진입 시 사용합니다.


