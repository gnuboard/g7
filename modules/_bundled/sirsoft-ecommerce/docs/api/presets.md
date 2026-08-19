# Presets API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Presets 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/presets
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.presets.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.presets.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\SearchPresetController@index`
- **인증/권한**: `auth:sanctum`, `admin`, `permission:admin,sirsoft-ecommerce.products.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| target_screen | query | string | 아니오 | `products`, `orders`, `coupons` | 대상 검색 화면 (해당 화면의 프리셋만 조회, 미지정 시 `products`) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.search_preset.list_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/presets?target_screen=products HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data[]` 배열 항목의 필드 (페이지네이션 없음 — 현재 사용자·해당 화면의 프리셋 전체를 배열로 반환)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 프리셋 ID (기본 키) |
| target_screen | string | `products` | 대상 화면 (`products` / `orders` / `customers`) |
| name | string | `배송 대기 상품` | 프리셋 이름 (DB 컬럼 `preset_name`, 최대 100자) |
| conditions | object | `{"status": "active"}` | 저장된 검색 조건 JSON (필터 필드 → 값) |
| filters | object | `{"preset_id": 1, "filters[status]": "active"}` | 프론트엔드가 그대로 붙여 쓰는 쿼리 파라미터 형태 (`SearchPreset::toQueryParams()` 생성, 배열 값은 콤마 결합) |
| sort_order | integer | `0` | 정렬 순서 (작을수록 우선) |
| is_default | boolean | `false` | 기본 프리셋 여부 |
| created_at | string | `2026-07-14 10:20:30` | 생성 일시 (사용자 타임존 기준 `Y-m-d H:i:s`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "검색 프리셋 목록을 조회했습니다.",
    "data": [
        {
            "id": 1,
            "target_screen": "products",
            "name": "배송 대기 상품",
            "conditions": {
                "status": "active"
            },
            "filters": {
                "preset_id": 1,
                "filters[status]": "active"
            },
            "sort_order": 0,
            "is_default": false,
            "created_at": "2026-07-14 10:20:30"
        }
    ]
}
```

> `message` 는 컨트롤러가 `sirsoft-ecommerce::messages.presets.fetch_success` 키를 넘기지만 모듈 lang 파일(`src/lang/ko/messages.php` 의 `presets` 배열)에 해당 키가 정의되어 있지 않아, 실측 응답에서 키 문자열이 그대로 노출됩니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자 검색 화면(상품/주문/쿠폰 등)에서 저장해 둔 검색 조건 프리셋 목록을 조회합니다. `auth:sanctum` 인증이 필요하며, `SearchPresetService::getPresets()`가 `target_screen`에 해당하는 프리셋을 반환합니다(미지정 시 `products` 화면 기준). 관리자가 자주 쓰는 필터 조합을 프리셋으로 관리해 검색 화면에서 빠르게 적용하기 위한 용도이며, 확장이 `list_validation_rules` 훅으로 조회 파라미터를 추가할 수 있습니다.


### POST /api/modules/sirsoft-ecommerce/admin/presets
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.presets.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.presets.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\SearchPresetController@store`
- **인증/권한**: `auth:sanctum`, `admin`, `permission:admin,sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| target_screen | body | string | 아니오 | — | 프리셋이 속할 검색 화면 (`products`/`orders`/`coupons`, 미지정 시 `products`) |
| name | body | string | 예 | max 100 | 대상의 이름/명칭 |
| conditions | body | array | 예 | — | 저장할 검색 조건 배열 (필터 필드/값 조합, 적용 시 그대로 복원됨) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.preset.store_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/presets HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "target_screen": "예시값",
    "name": "예시 이름",
    "conditions": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (생성된 프리셋 1건)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 생성된 프리셋 ID |
| target_screen | string | `products` | 대상 화면 (`products` / `orders` / `customers`, 미지정 시 `products`) |
| name | string | `배송 대기 상품` | 프리셋 이름 (DB 컬럼 `preset_name`) |
| conditions | object | `{"status": "active"}` | 저장된 검색 조건 |
| filters | object | `{"preset_id": 1, "filters[status]": "active"}` | 프론트엔드용 쿼리 파라미터 형태 |
| sort_order | integer | `0` | 정렬 순서 (생성 시 기본값 `0`) |
| is_default | boolean | `false` | 기본 프리셋 여부 (생성 시 기본값 `false`) |
| created_at | string | `2026-07-14 10:20:30` | 생성 일시 (사용자 타임존 기준 `Y-m-d H:i:s`) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "검색 프리셋이 저장되었습니다.",
    "data": {
        "id": 1,
        "target_screen": "products",
        "name": "배송 대기 상품",
        "conditions": {
            "status": "active"
        },
        "filters": {
            "preset_id": 1,
            "filters[status]": "active"
        },
        "sort_order": 0,
        "is_default": false,
        "created_at": "2026-07-14 10:20:30"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 동일 사용자·동일 화면에 같은 이름의 프리셋이 이미 있으면 `name` 에 "동일한 이름의 프리셋이 이미 존재합니다." |

<!-- @generated:end -->

**설명** 관리자 검색 화면의 새 검색 조건 프리셋을 생성합니다. `auth:sanctum` 인증이 필요하며, `SearchPresetService::create()`가 `target_screen`(미지정 시 `products`)·`name`(최대 100자)·`conditions`(검색 조건 배열)을 받아 프리셋을 저장하고 성공 시 `201`로 생성된 프리셋을 반환합니다. 자주 쓰는 필터 조합을 이름 붙여 저장해 재사용하기 위한 용도이며, 확장이 `preset.store_validation_rules` 훅으로 파라미터를 추가할 수 있습니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/presets/{preset}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.presets.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.presets.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\SearchPresetController@destroy`
- **인증/권한**: `auth:sanctum`, `admin`, `permission:admin,sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| preset | path | string | 예 | — | 대상 preset의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/presets/{preset} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (삭제 결과 플래그만 반환하며, 삭제된 프리셋 본문은 반환하지 않습니다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 삭제 완료 여부 (컨트롤러가 `['deleted' => true]` 를 고정 반환) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "검색 프리셋이 삭제되었습니다.",
    "data": {
        "deleted": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 500 | UnauthorizedPresetAccessException | 다른 사용자 소유의 프리셋을 삭제하려는 경우 (`SearchPresetService::delete()` 의 소유권 검사 — "프리셋(ID: :preset_id)에 대한 접근 권한이 없습니다."). 이 예외는 전용 status 나 `render()` 를 갖지 않아 기본 예외 처리로 500 이 됩니다 (테스트 하네스 `ModuleTestCase` 에서만 403 으로 매핑) |

<!-- @generated:end -->

**설명** 지정한 검색 조건 프리셋 1건을 삭제합니다. `auth:sanctum` 인증이 필요하며, path의 `{preset}`은 라우트 모델 바인딩으로 `SearchPreset` 모델이 주입되고 `SearchPresetService::delete()`가 삭제를 수행합니다. 삭제 성공 시 `{ "deleted": true }`를 반환하며, 존재하지 않는 프리셋이면 `404`를 반환합니다. 더 이상 사용하지 않는 저장 검색 조합을 정리하는 용도입니다.


### PUT /api/modules/sirsoft-ecommerce/admin/presets/{preset}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.presets.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.presets.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\SearchPresetController@update`
- **인증/권한**: `auth:sanctum`, `admin`, `permission:admin,sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| preset | path | string | 예 | — | 대상 preset의 식별자 |
| name | body | string | 예 | max 100 | 대상의 이름/명칭 |
| conditions | body | array | 예 | — | 저장할 검색 조건 배열 (필터 필드/값 조합, 적용 시 그대로 복원됨) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.preset.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/presets/{preset} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": "예시 이름",
    "conditions": [
        "예시값"
    ],
    "sort_order": 1
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (수정된 프리셋 1건)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 프리셋 ID |
| target_screen | string | `products` | 대상 화면 (수정 대상이 아님 — 생성 시 값 유지) |
| name | string | `배송 대기 상품 (수정)` | 수정된 프리셋 이름 (DB 컬럼 `preset_name`) |
| conditions | object | `{"status": "inactive"}` | 수정된 검색 조건 |
| filters | object | `{"preset_id": 1, "filters[status]": "inactive"}` | 프론트엔드용 쿼리 파라미터 형태 |
| sort_order | integer | `1` | 정렬 순서 (작을수록 우선) |
| is_default | boolean | `false` | 기본 프리셋 여부 |
| created_at | string | `2026-07-14 10:20:30` | 생성 일시 (사용자 타임존 기준 `Y-m-d H:i:s`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "검색 프리셋이 수정되었습니다.",
    "data": {
        "id": 1,
        "target_screen": "products",
        "name": "배송 대기 상품 (수정)",
        "conditions": {
            "status": "inactive"
        },
        "filters": {
            "preset_id": 1,
            "filters[status]": "inactive"
        },
        "sort_order": 1,
        "is_default": false,
        "created_at": "2026-07-14 10:20:30"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 동일 사용자·동일 화면에 같은 이름의 다른 프리셋이 있으면 `name` 에 "동일한 이름의 프리셋이 이미 존재합니다." |
| 500 | UnauthorizedPresetAccessException | 다른 사용자 소유의 프리셋을 수정하려는 경우 (`SearchPresetService::update()` 의 소유권 검사). 전용 status/`render()` 가 없어 기본 예외 처리로 500 (테스트 하네스에서만 403 매핑) |

<!-- @generated:end -->

**설명** 기존 검색 조건 프리셋 1건을 수정합니다. `auth:sanctum` 인증이 필요하며, path의 `{preset}`은 라우트 모델 바인딩으로 주입되고 `SearchPresetService::update()`가 `name`(최대 100자)·`conditions`(검색 조건 배열)·선택 `sort_order`(정렬 순서, 0 이상)를 반영해 저장 후 갱신된 프리셋을 반환합니다. 저장해 둔 필터 조합의 이름·조건·노출 순서를 변경하는 용도이며, 확장이 `preset.update_validation_rules` 훅으로 파라미터를 추가할 수 있습니다.


