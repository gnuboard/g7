# Product Labels API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Product Labels 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/product-labels
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-labels.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-labels.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductLabelController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-labels.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| is_active | query | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| active_only | query | boolean | 아니오 | — | 활성 라벨만 필터 (true 시 내부적으로 `is_active=true` 로 변환 — 기존 호환용) |
| search | query | string | 아니오 | max 100 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| sort | query | string | 아니오 | `name_asc`, `name_desc`, `created_asc`, `created_desc` | 정렬 기준 (필드명, `-` 접두 시 내림차순) |
| locale | query | string | 아니오 | `ko`, `en`, `fr`, `ja` | 로케일 코드 (표시 언어/지역) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/product-labels?is_active=1&active_only=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&sort=name_asc&locale=ko HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `37` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 라벨","en":"API Doc Sample Label"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| color | string | `#6B7280` | 라벨 색상 코드 (`#RRGGBB` 6자리 HEX, 뱃지 배경/글자색 등 표시에 사용) |
| is_active | boolean | `true` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-07 14:47:31` | 생성 일시 |
| updated_at | string | `2026-07-07 14:47:31` | 최종 수정 일시 |
| assignments_count | integer | `0` | assignments 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "라벨 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "name": {
                    "ko": "API 문서 샘플 라벨",
                    "en": "API Doc Sample Label"
                },
                "color": null,
                "is_active": true,
                "sort_order": 0,
                "created_at": "2026-07-08 10:44:49",
                "updated_at": "2026-07-08 10:44:49",
                "assignments_count": 0,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-labels.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 상품 라벨(예: "신상품", "베스트") 목록을 조회합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-labels.read` 권한이 필요하며, `ProductLabelController@index`가 `ProductLabelService::getAllLabels()`에 검증된 필터를 전달해 조회합니다. `is_active` 또는 `active_only` 로 활성 라벨만 필터링하고, `search`(라벨명 검색), `sort`(이름/생성일 정렬), `locale`(다국어 정렬 기준)을 지원합니다. `active_only=true` 는 내부적으로 `is_active=true` 로 변환되어 기존 호환성을 유지하며, 각 항목의 `assignments_count` 로 라벨이 몇 개 상품에 부여됐는지 확인할 수 있습니다.


### POST /api/modules/sirsoft-ecommerce/admin/product-labels
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-labels.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-labels.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductLabelController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-labels.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| color | body | string | 예 | max 20 | 라벨 색상 코드 (필수, `#RRGGBB` 6자리 HEX 형식만 허용) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/product-labels HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "color": "#4F46E5",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-labels.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 새 상품 라벨을 생성합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-labels.create` 권한이 필요하며, `ProductLabelController@store`가 `ProductLabelService::createLabel()`에 검증된 데이터를 넘겨 저장합니다. `name`은 다국어 배열({ko, en, ...}), `color`는 라벨 색상 코드(최대 20자)이고, `is_active`·`sort_order`로 활성 여부와 정렬 순서를 지정합니다. 성공 시 HTTP 201과 함께 생성된 라벨 리소스를 반환합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/product-labels/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-labels.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-labels.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductLabelController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-labels.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/product-labels/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| label_id | integer | `1` | label 식별자 (연관 리소스 참조) |
| products_count | integer | `0` | products 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "라벨이 삭제되었습니다.",
    "data": {
        "label_id": 1,
        "products_count": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-labels.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품 라벨 1건을 삭제합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-labels.delete` 권한이 필요하며, `ProductLabelController@destroy`가 `ProductLabelService::deleteLabel()`을 호출해 삭제합니다. path의 `id`에 해당하는 라벨이 없으면 404를 반환하고, 삭제 중 오류가 발생하면 400 에러 응답을 반환합니다. 삭제 시 해당 라벨과 상품 간의 부여(assignment) 관계도 함께 정리됩니다.


### GET /api/modules/sirsoft-ecommerce/admin/product-labels/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-labels.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-labels.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductLabelController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-labels.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/product-labels/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 라벨","en":"API Doc Sample Label"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| color | null | `null` | 색상 코드 (예: #FF5733) |
| is_active | boolean | `true` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 10:44:49` | 최종 수정 일시 |
| assignments_count | integer | `0` | assignments 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "라벨 정보를 조회했습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 라벨",
            "en": "API Doc Sample Label"
        },
        "color": null,
        "is_active": true,
        "sort_order": 0,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 10:44:49",
        "assignments_count": 0,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-labels.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품 라벨 1건의 상세 정보를 조회합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-labels.read` 권한이 필요하며, `ProductLabelController@show`가 `ProductLabelService::getLabel()`로 단건을 조회합니다. 다국어 라벨명(`name`), 색상, 활성 여부, 정렬 순서와 함께 `assignments_count`를 반환하며, 해당 `id`의 라벨이 없으면 404를 반환합니다. 주로 라벨 수정 화면 진입 시 기존 값을 불러오는 데 사용됩니다.


### PUT /api/modules/sirsoft-ecommerce/admin/product-labels/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-labels.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-labels.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductLabelController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-labels.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| color | body | string | 예 | max 20 | 라벨 색상 코드 (필수, `#RRGGBB` 6자리 HEX 형식만 허용) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/product-labels/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "color": "#4F46E5",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-labels.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 기존 상품 라벨 1건을 수정합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-labels.update` 권한이 필요하며, `ProductLabelController@update`가 `ProductLabelService::updateLabel()`에 검증된 데이터를 전달해 갱신합니다. `name`(다국어 배열)과 `color`는 필수이며, `is_active`·`sort_order`도 함께 변경할 수 있습니다. 대상 라벨이 없으면 404, 갱신 처리 중 오류가 발생하면 400 에러 응답을 반환합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/product-labels/{id}/toggle-status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.product-labels.toggle-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.product-labels.toggle-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductLabelController@toggleStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.product-labels.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/product-labels/1/toggle-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 라벨","en":"API Doc Sample Label"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| color | null | `null` | 색상 코드 (예: #FF5733) |
| is_active | boolean | `false` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:27` | 최종 수정 일시 |
| assignments_count | integer | `0` | assignments 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "라벨 상태가 변경되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 라벨",
            "en": "API Doc Sample Label"
        },
        "color": null,
        "is_active": false,
        "sort_order": 0,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:00:27",
        "assignments_count": 0,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.product-labels.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품 라벨의 활성/비활성 상태를 한 번의 요청으로 토글합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.product-labels.update` 권한이 필요하며, `ProductLabelController@toggleStatus`가 `ProductLabelService::toggleStatus()`를 호출해 현재 `is_active` 값을 반전시킵니다. 별도의 본문 없이 path의 `id`만으로 동작하므로 목록 화면에서 스위치 조작으로 즉시 노출 여부를 바꾸는 데 적합합니다. 대상 라벨이 없으면 404, 처리 중 오류가 발생하면 400 에러 응답을 반환합니다.


