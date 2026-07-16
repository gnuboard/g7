# Product Notice Templates API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Product Notice Templates 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/product-notice-templates
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-notice-templates.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-notice-templates.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductNoticeTemplateController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-notice-templates.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search | query | string | 아니오 | max 200 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| active_only | query | boolean | 아니오 | — | true 시 활성(is_active) 템플릿만 조회 |
| per_page | query | string | 아니오 | — | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/product-notice-templates?search=%EC%98%88%EC%8B%9C%EA%B0%92&active_only=1&per_page=%EC%98%88%EC%8B%9C%EA%B0%92&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `172` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 고시템플릿","en":"API Doc Sample Notice Templ…` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 고시템플릿` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| category | string | `clothing` | 이 템플릿이 적용되는 품목 카테고리 식별자 |
| fields | array | `[{"label":"품명","value":"샘플"}]` | 고시 항목 정의 배열 (항목별 name/content 다국어 — 상품 등록 시 고시 항목 자동 채움에 사용) |
| fields_count | integer | `1` | fields 개수 (집계) |
| is_active | boolean | `true` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| icon | string | `file-alt` | 아이콘 식별자 (아이콘 클래스/이름) |
| created_at | string | `2026-07-07 14:47:31` | 생성 일시 |
| updated_at | string | `2026-07-07 14:47:31` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품정보제공고시 목록을 불러왔습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "name": {
                    "ko": "API 문서 샘플 고시템플릿",
                    "en": "API Doc Sample Notice Template"
                },
                "localized_name": "API 문서 샘플 고시템플릿",
                "category": null,
                "fields": [
                    {
                        "label": "품명",
                        "value": "샘플"
                    }
                ],
                "fields_count": 1,
                "is_active": true,
                "sort_order": 0,
                "icon": "file-alt",
                "created_at": "2026-07-08 10:44:49",
                "updated_at": "2026-07-08 10:44:49",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-notice-templates.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 상품정보제공고시 템플릿(전자상거래법상 품목별 필수 고지 항목 세트) 목록을 조회합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-notice-templates.read` 권한이 필요하며, `ProductNoticeTemplateController@index`가 `search`·`active_only` 필터를 조립합니다. `per_page`가 0 이하이거나 `all`이면 `ProductNoticeTemplateService::getAllTemplates()`로 전체를, 그 외에는 `getPaginatedTemplates()`로 페이지네이션 조회(`data.pagination` 포함)합니다. 각 항목은 다국어 템플릿명, 품목 `category`, 고시 항목 배열 `fields`(및 `fields_count`)를 포함합니다.


### POST /api/modules/sirsoft-ecommerce/admin/product-notice-templates
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-notice-templates.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-notice-templates.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductNoticeTemplateController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-notice-templates.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| category | body | string | 아니오 | max 100 | 이 템플릿이 적용되는 품목 카테고리 식별자 |
| fields | body | array | 예 | min 1 | 고시 항목 정의 배열 (항목별 name/content 다국어, 최소 1개) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product-notice-template.create_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/product-notice-templates HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "category": "예시값",
    "fields": [
        "예시값"
    ],
    "is_active": true,
    "sort_order": 1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-notice-templates.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 새 상품정보제공고시 템플릿을 생성합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-notice-templates.create` 권한이 필요하며, `ProductNoticeTemplateController@store`가 `ProductNoticeTemplateService::createTemplate()`에 검증된 데이터를 전달해 저장합니다. `name`(다국어 배열)과 `fields`(고시 항목 정의, 최소 1개)는 필수이고, `category`(품목 카테고리), `is_active`, `sort_order`는 선택입니다. 확장이 `sirsoft-ecommerce.product-notice-template.create_validation_rules` 필터로 파라미터를 추가할 수 있으며, 성공 시 HTTP 201을, 처리 실패 시 400 에러 응답을 반환합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/product-notice-templates/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-notice-templates.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-notice-templates.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductNoticeTemplateController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-notice-templates.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/product-notice-templates/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| template_id | integer | `1` | template 식별자 (연관 리소스 참조) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품정보제공고시가 삭제되었습니다.",
    "data": {
        "template_id": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-notice-templates.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품정보제공고시 템플릿 1건을 삭제합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-notice-templates.delete` 권한이 필요하며, `ProductNoticeTemplateController@destroy`가 `ProductNoticeTemplateService::deleteTemplate()`를 호출해 삭제합니다. path의 `id`에 해당하는 템플릿이 없거나 삭제 처리 중 오류가 발생하면 각각 404/400 에러 응답을 반환합니다. 삭제된 템플릿은 이후 신규 상품의 고시 항목 자동 채움에 더 이상 사용되지 않습니다.


### GET /api/modules/sirsoft-ecommerce/admin/product-notice-templates/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-notice-templates.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-notice-templates.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductNoticeTemplateController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-notice-templates.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/product-notice-templates/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 고시템플릿","en":"API Doc Sample Notice Templ…` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 고시템플릿` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| category | null | `null` | 품목 카테고리 |
| fields | array | `[{"label":"품명","value":"샘플"}]` | 필드 정의 JSON |
| fields_count | integer | `1` | fields 개수 (집계) |
| is_active | boolean | `true` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| icon | string | `file-alt` | 아이콘 식별자 (아이콘 클래스/이름) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 10:44:49` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품정보제공고시 목록을 불러왔습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 고시템플릿",
            "en": "API Doc Sample Notice Template"
        },
        "localized_name": "API 문서 샘플 고시템플릿",
        "category": null,
        "fields": [
            {
                "label": "품명",
                "value": "샘플"
            }
        ],
        "fields_count": 1,
        "is_active": true,
        "sort_order": 0,
        "icon": "file-alt",
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 10:44:49",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-notice-templates.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품정보제공고시 템플릿 1건의 상세를 조회합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-notice-templates.read` 권한이 필요하며, `ProductNoticeTemplateController@show`가 `ProductNoticeTemplateService::getTemplate()`로 단건을 조회합니다. 다국어 템플릿명(`name`, `localized_name`), 품목 `category`, 고시 항목 배열 `fields`, 활성 여부·정렬 순서를 반환하며, 해당 `id`의 템플릿이 없으면 404를 반환합니다. 주로 템플릿 수정 화면 진입 시 기존 고시 항목을 불러오는 데 사용됩니다.


### PUT /api/modules/sirsoft-ecommerce/admin/product-notice-templates/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-notice-templates.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-notice-templates.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductNoticeTemplateController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-notice-templates.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| category | body | string | 아니오 | max 100 | 이 템플릿이 적용되는 품목 카테고리 식별자 |
| fields | body | array | 예 | min 1 | 고시 항목 정의 배열 (항목별 name/content 다국어, 최소 1개) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product-notice-template.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/product-notice-templates/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "category": "예시값",
    "fields": [
        "예시값"
    ],
    "is_active": true,
    "sort_order": 1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-notice-templates.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품정보제공고시 템플릿 1건을 수정합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-notice-templates.update` 권한이 필요하며, `ProductNoticeTemplateController@update`가 `ProductNoticeTemplateService::updateTemplate()`에 검증된 데이터를 전달해 갱신합니다. `name`(다국어 배열)과 `fields`(최소 1개)는 필수이고, `category`·`is_active`·`sort_order`도 함께 변경할 수 있습니다. 확장이 `sirsoft-ecommerce.product-notice-template.update_validation_rules` 필터로 파라미터를 추가할 수 있으며, 대상이 없거나 처리 실패 시 각각 404/400 에러 응답을 반환합니다.


### POST /api/modules/sirsoft-ecommerce/admin/product-notice-templates/{id}/copy
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-notice-templates.copy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-notice-templates.copy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductNoticeTemplateController@copy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-notice-templates.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/product-notice-templates/1/copy HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `2` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 고시템플릿 (복사)","en":"API Doc Sample Notice …` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 고시템플릿 (복사)` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| category | null | `null` | 품목 카테고리 |
| fields | array | `[{"label":"품명","value":"샘플"}]` | 필드 정의 JSON |
| fields_count | integer | `1` | fields 개수 (집계) |
| is_active | boolean | `true` | active 여부 |
| sort_order | integer | `1` | 표시 정렬 순서 값 (작을수록 우선) |
| icon | string | `file-alt` | 아이콘 식별자 (아이콘 클래스/이름) |
| created_at | string | `2026-07-08 15:00:27` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:27` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "상품정보제공고시가 복사되었습니다.",
    "data": {
        "id": 2,
        "name": {
            "ko": "API 문서 샘플 고시템플릿 (복사)",
            "en": "API Doc Sample Notice Template (Copy)"
        },
        "localized_name": "API 문서 샘플 고시템플릿 (복사)",
        "category": null,
        "fields": [
            {
                "label": "품명",
                "value": "샘플"
            }
        ],
        "fields_count": 1,
        "is_active": true,
        "sort_order": 1,
        "icon": "file-alt",
        "created_at": "2026-07-08 15:00:27",
        "updated_at": "2026-07-08 15:00:27",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-notice-templates.create`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 기존 상품정보제공고시 템플릿을 원본 삼아 복제본을 생성합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-notice-templates.create` 권한이 필요하며(생성 계열이므로 create 권한 사용), `ProductNoticeTemplateController@copy`가 `ProductNoticeTemplateService::copyTemplate()`를 호출해 path의 `id` 템플릿을 복사합니다. 별도 본문 없이 원본 `id`만으로 동작하며, 복제된 새 템플릿을 HTTP 201로 반환합니다. 유사한 고시 항목 세트를 반복 작성하지 않고 빠르게 파생 템플릿을 만들 때 사용하며, 원본이 없거나 처리 실패 시 각각 404/400 에러 응답을 반환합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/product-notice-templates/{id}/toggle-active
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-notice-templates.toggle-active -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-notice-templates.toggle-active`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductNoticeTemplateController@toggleActive`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-notice-templates.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/product-notice-templates/1/toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 고시템플릿","en":"API Doc Sample Notice Templ…` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 고시템플릿` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| category | null | `null` | 품목 카테고리 |
| fields | array | `[{"label":"품명","value":"샘플"}]` | 필드 정의 JSON |
| fields_count | integer | `1` | fields 개수 (집계) |
| is_active | boolean | `false` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| icon | string | `file-alt` | 아이콘 식별자 (아이콘 클래스/이름) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:27` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품정보제공고시가 비활성화되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 고시템플릿",
            "en": "API Doc Sample Notice Template"
        },
        "localized_name": "API 문서 샘플 고시템플릿",
        "category": null,
        "fields": [
            {
                "label": "품명",
                "value": "샘플"
            }
        ],
        "fields_count": 1,
        "is_active": false,
        "sort_order": 0,
        "icon": "file-alt",
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:00:27",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-notice-templates.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품정보제공고시 템플릿의 활성/비활성 상태를 한 번의 요청으로 토글합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-notice-templates.update` 권한이 필요하며, `ProductNoticeTemplateController@toggleActive`가 `ProductNoticeTemplateService::toggleActive()`를 호출해 현재 `is_active` 값을 반전시킵니다. 반전 결과에 따라 활성화/비활성화 메시지를 구분해 응답하므로 목록 화면의 스위치 조작에 적합합니다. 대상이 없거나 처리 실패 시 각각 404/400 에러 응답을 반환합니다.


