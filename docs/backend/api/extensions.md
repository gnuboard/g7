# Extensions API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Extensions 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/extensions/auto-deactivated
<!-- @generated:start:api.admin.extensions.auto-deactivated -->
- **라우트명**: `api.admin.extensions.auto-deactivated`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ExtensionRecoveryController@autoDeactivated`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.activate`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/extensions/auto-deactivated HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| items | object | `{"plugins":[],"modules":[],"templates":[]}` | 코어 비호환으로 자동 비활성화된 확장을 타입별(`plugins`/`modules`/`templates`)로 묶은 목록. 각 원소는 식별자(`identifier`), 비호환 요구 버전(`incompatible_required_version`), 비활성화 시각(`deactivated_at`)을 가지며, 사용자가 dismiss했거나 hidden(학습용 샘플) 확장은 제외됨 |
| current_core_version | string | `7.0.6` | 현재 설치된 코어 버전 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "자동 비활성화된 확장 목록입니다.",
    "data": {
        "items": {
            "plugins": [],
            "modules": [],
            "templates": []
        },
        "current_core_version": "7.0.6"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.activate`)이 없는 경우 |

<!-- @generated:end -->

**설명** 코어 버전 비호환으로 자동 비활성화된 확장 목록을 타입별(`plugins`/`modules`/`templates`)로 반환합니다. 각 항목에는 식별자, 비호환 요구 버전, 비활성화 시각과 함께 현재 코어 버전이 담깁니다. 사용자가 dismiss한 알림과 hidden(학습용 샘플) 확장은 결과에서 제외됩니다. `core.plugins.activate` 권한이 필요하며, 상단 배너·대시보드 카드의 데이터 소스로 사용됩니다.


### POST /api/admin/extensions/{type}/{identifier}/dismiss
<!-- @generated:start:api.admin.extensions.dismiss -->
- **라우트명**: `api.admin.extensions.dismiss`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ExtensionRecoveryController@dismiss`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.activate`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | path | string | 예 | — | 대상 확장의 타입 (module: 모듈, plugin: 플러그인, template: 템플릿). 타입에 맞는 Repository/Manager를 해석하는 데 사용되며 그 외 값은 422 |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/admin/extensions/{type}/{identifier}/dismiss HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| alert_id | string | `compat_plugins_sirsoft-tosspayments` | dismiss 처리된 자동 비활성화 알림의 식별자 (`compat_{type}s_{identifier}` 형식). 재호환 알림(`recover_{type}s_{identifier}`)도 함께 dismiss 되지만 응답에는 포함되지 않음 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림을 닫았습니다.",
    "data": {
        "alert_id": "compat_plugins_sirsoft-tosspayments"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.activate`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 확장의 호환성 알림을 현재 사용자 기준으로 dismiss(닫기) 처리합니다. 경로의 `{type}`(module|plugin|template)과 `{identifier}`로 대상을 지정하며, 해당 확장의 자동 비활성화 알림과 재호환 알림을 함께 dismiss합니다. `core.plugins.activate` 권한이 필요합니다. dismiss는 사용자별로 저장되므로, 캐시 만료나 감지 갱신 시 재호환 상태가 바뀌면 다시 노출될 수 있습니다.


### POST /api/admin/extensions/{type}/{identifier}/recover
<!-- @generated:start:api.admin.extensions.recover -->
- **라우트명**: `api.admin.extensions.recover`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ExtensionRecoveryController@recover`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.activate`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | path | string | 예 | — | 대상 확장의 타입 (module: 모듈, plugin: 플러그인, template: 템플릿). 타입에 맞는 Repository/Manager를 해석하는 데 사용되며 그 외 값은 422 |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/admin/extensions/{type}/{identifier}/recover HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| extension_type | string | `plugin` | 복구(재활성화)된 확장의 타입 (`module` \| `plugin` \| `template`) — 요청 path의 `{type}` 을 그대로 반환 |
| identifier | string | `sirsoft-tosspayments` | 복구된 확장의 식별자 — 요청 path의 `{identifier}` 를 그대로 반환 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "확장이 다시 활성화되었습니다.",
    "data": {
        "extension_type": "plugin",
        "identifier": "sirsoft-tosspayments"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.activate`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 (`extensions.errors.not_found`) |
| 422 | Unprocessable Entity | 재검증 결과 여전히 코어 버전이 요구 버전에 미달하는 경우 — 글로벌 핸들러가 `core_version_mismatch` 로 변환 |

<!-- @generated:end -->

**설명** 코어와 재호환된 확장을 원클릭으로 복구(재활성화)합니다. 경로의 `{type}`/`{identifier}`로 대상을 지정하며, 대상이 `IncompatibleCore` 사유로 자동 비활성화된 상태인지 검증한 뒤 코어 버전 재검증을 거쳐 활성화합니다. 잘못된 타입은 422, 미존재 확장은 404, hidden 확장이나 자동 비활성화가 아닌 경우는 error_code와 함께 422를 반환하고, 재검증 실패 시 글로벌 핸들러가 core_version_mismatch로 변환합니다. `core.plugins.activate` 권한이 필요합니다.

### GET /api/admin/extensions/{type}/{identifier}/custom-assets
<!-- @generated:start:api.admin.extensions.custom-assets.index -->
- **라우트명**: `api.admin.extensions.custom-assets.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AdminExtensionCustomAssetController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.extensions.custom_assets.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | path | string | 예 | `module` \| `plugin` \| `template` | 대상 확장 타입 |
| identifier | path | string | 예 | — | 대상 확장 식별자 |

**요청 예시**

```http
GET /api/admin/extensions/template/sirsoft-admin_basic/custom-assets HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| files | array | `[{"path":"10-overrides.css", …}]` | `custom/` 이하 파일 목록 (상대 경로 오름차순) |
| files[].path | string | `10-overrides.css` | `custom/` 기준 상대 경로 |
| files[].name | string | `10-overrides.css` | 파일명 |
| files[].extension | string | `css` | 소문자 확장자 |
| files[].size | integer | `42` | 바이트 크기 |
| files[].modified_at | string | `2026-08-26T10:00:00+09:00` | 최종 수정 시각 (ISO 8601) |
| files[].editable | boolean | `true` | 본문을 직접 편집할 수 있는지 (텍스트 형식만 true) |
| files[].loaded | boolean | `true` | 실제로 페이지에 실리는지 (규약 스캔·선언 파일 판정 결과) |
| editable_extensions | array | `["css","js","mjs","json"]` | 본문 편집이 가능한 확장자 |
| uploadable_extensions | array | `["js","css","png","woff2", …]` | 업로드 허용 확장자 (자산 서빙 화이트리스트와 동일) |
| max_text_bytes | integer | `524288` | 본문 편집 최대 크기 |
| max_upload_bytes | integer | `5242880` | 업로드 최대 크기 |

**응답 예시**

```json
{
    "success": true,
    "message": "목록을 불러왔습니다.",
    "data": {
        "files": [
            {
                "path": "10-overrides.css",
                "name": "10-overrides.css",
                "extension": "css",
                "size": 42,
                "modified_at": "2026-08-26T10:00:00+09:00",
                "editable": true,
                "loaded": true
            }
        ],
        "editable_extensions": ["css", "js", "mjs", "json"],
        "uploadable_extensions": ["js", "mjs", "css", "json", "png", "jpg", "jpeg", "svg", "webp", "gif", "woff", "woff2", "ttf", "otf", "eot"],
        "max_text_bytes": 524288,
        "max_upload_bytes": 5242880
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `core.extensions.custom_assets.manage` 권한이 없는 경우 (레이아웃 편집 권한만으로는 통과하지 못한다) |
| 404 | Not Found | `type` 이 `module`·`plugin`·`template` 이 아닌 경우 (라우트 정규식이 거부) |
| 500 | Server Error | 디렉토리 조회 실패 |

<!-- @generated:end -->

**설명** 운영자가 확장의 `custom/` 디렉토리에 넣은 파일 목록과 편집기 메타(허용 확장자·크기 상한)를 반환합니다. 규약 스캔이 자동으로 싣지 않는 폰트·이미지도 목록에 포함됩니다 — 목록에서 빠지면 지울 방법이 없어지기 때문입니다. 권한은 레이아웃 편집(`core.templates.layouts.edit`)과 분리되어 있습니다: 여기서 올린 스크립트는 레이아웃 한 장이 아니라 사이트 전 화면에서 실행됩니다.


### GET /api/admin/extensions/{type}/{identifier}/custom-assets/content
<!-- @generated:start:api.admin.extensions.custom-assets.show -->
- **라우트명**: `api.admin.extensions.custom-assets.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AdminExtensionCustomAssetController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.extensions.custom_assets.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | path | string | 예 | `module` \| `plugin` \| `template` | 대상 확장 타입 |
| identifier | path | string | 예 | — | 대상 확장 식별자 |
| path | query | string | 예 | 최대 255자 | `custom/` 기준 상대 경로 (상위 이동·절대 경로 불가) |

**요청 예시**

```http
GET /api/admin/extensions/template/sirsoft-admin_basic/custom-assets/content?path=10-overrides.css HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| path | string | `10-overrides.css` | 요청한 상대 경로 |
| content | string | `body { color: red; }` | 파일 본문 |
| size | integer | `20` | 바이트 크기 |

**응답 예시**

```json
{
    "success": true,
    "message": "목록을 불러왔습니다.",
    "data": {
        "path": "10-overrides.css",
        "content": "body { color: red; }",
        "size": 20
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `core.extensions.custom_assets.manage` 권한이 없는 경우 |
| 422 | Unprocessable Entity | 경로 검증 실패 / 파일 부재 / 편집 불가 형식 / 크기 초과 |
| 500 | Server Error | 파일 읽기 실패 |

<!-- @generated:end -->

**설명** 텍스트 형식(`css`·`js`·`mjs`·`json`) 파일의 본문을 반환합니다. 그 밖의 형식은 422 로 거부합니다 — 바이너리를 텍스트 편집기에 열면 저장 시 내용이 손상되기 때문입니다.


### PUT /api/admin/extensions/{type}/{identifier}/custom-assets/content
<!-- @generated:start:api.admin.extensions.custom-assets.store -->
- **라우트명**: `api.admin.extensions.custom-assets.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AdminExtensionCustomAssetController@store`
- **인증/권한**: `auth:sanctum` + `permission:core.extensions.custom_assets.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | path | string | 예 | `module` \| `plugin` \| `template` | 대상 확장 타입 |
| identifier | path | string | 예 | — | 대상 확장 식별자 |
| path | body | string | 예 | 최대 255자, 확장자 `css`\|`js`\|`mjs`\|`json` | 저장할 상대 경로 (없으면 새로 만든다) |
| content | body | string | 예 (빈 문자열 허용) | 최대 524288바이트 | 파일 본문 |

**요청 예시**

```http
PUT /api/admin/extensions/template/sirsoft-admin_basic/custom-assets/content HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json
Authorization: Bearer {YOUR_TOKEN}

{
    "path": "10-overrides.css",
    "content": "body { color: red; }"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| path | string | `10-overrides.css` | 저장한 상대 경로 |
| size | integer | `20` | 저장된 바이트 크기 |
| modified_at | string | `2026-08-26T10:00:00+09:00` | 저장 시각 (ISO 8601) |

**응답 예시**

```json
{
    "success": true,
    "message": "저장했습니다.",
    "data": {
        "path": "10-overrides.css",
        "size": 20,
        "modified_at": "2026-08-26T10:00:00+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `core.extensions.custom_assets.manage` 권한이 없는 경우 |
| 422 | Unprocessable Entity | 경로 검증 실패(상위 이동·절대 경로·빈 세그먼트) / 편집 불가 형식 / 크기 초과 |
| 500 | Server Error | 파일 쓰기 실패 |

<!-- @generated:end -->

**설명** 텍스트 파일을 저장합니다(없으면 생성). 빈 본문 저장을 허용합니다 — 운영자가 CSS 를 통째로 비우는 것은 정당한 조작이고, 그것을 막으면 파일을 지우는 것 말고는 되돌릴 방법이 없어집니다. 저장 성공 시 확장 캐시 버전이 올라 정적 게시본이 다시 만들어지므로, 편집 결과는 다음 화면부터 반영됩니다.


### POST /api/admin/extensions/{type}/{identifier}/custom-assets/upload
<!-- @generated:start:api.admin.extensions.custom-assets.upload -->
- **라우트명**: `api.admin.extensions.custom-assets.upload`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AdminExtensionCustomAssetController@upload`
- **인증/권한**: `auth:sanctum` + `permission:core.extensions.custom_assets.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | path | string | 예 | `module` \| `plugin` \| `template` | 대상 확장 타입 |
| identifier | path | string | 예 | — | 대상 확장 식별자 |
| file | body (multipart) | file | 예 | 최대 5MB, 자산 서빙 허용 확장자 | 올릴 파일 |
| directory | body (multipart) | string | 아니오 | 최대 200자 | `custom/` 기준 하위 디렉토리 (없으면 바로 아래) |

**요청 예시**

```http
POST /api/admin/extensions/template/sirsoft-admin_basic/custom-assets/upload HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: multipart/form-data; boundary=----g7
Authorization: Bearer {YOUR_TOKEN}

------g7
Content-Disposition: form-data; name="file"; filename="brand.woff2"
Content-Type: font/woff2

(binary)
------g7
Content-Disposition: form-data; name="directory"

fonts
------g7--
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| path | string | `fonts/brand.woff2` | 저장된 상대 경로 |
| size | integer | `18240` | 바이트 크기 |
| modified_at | string | `2026-08-26T10:00:00+09:00` | 저장 시각 (ISO 8601) |

**응답 예시**

```json
{
    "success": true,
    "message": "파일을 올렸습니다.",
    "data": {
        "path": "fonts/brand.woff2",
        "size": 18240,
        "modified_at": "2026-08-26T10:00:00+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `core.extensions.custom_assets.manage` 권한이 없는 경우 |
| 422 | Unprocessable Entity | 허용되지 않는 확장자 / 크기 초과 / 디렉토리 경로 검증 실패 |
| 500 | Server Error | 파일 저장 실패 |

<!-- @generated:end -->

**설명** 폰트·이미지 등 바이너리를 포함한 파일을 올립니다. 파일명은 안전한 문자(`A-Z a-z 0-9 . _ -`)로 정규화되며, 허용 확장자는 자산 서빙 화이트리스트(`AllowedTemplateFileType`)와 동일합니다 — 여기만 넓히면 올릴 수는 있는데 서빙되지 않는 파일이 생기고, 여기만 좁히면 서빙 규칙이 사문화됩니다.


### DELETE /api/admin/extensions/{type}/{identifier}/custom-assets
<!-- @generated:start:api.admin.extensions.custom-assets.destroy -->
- **라우트명**: `api.admin.extensions.custom-assets.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AdminExtensionCustomAssetController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.extensions.custom_assets.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | path | string | 예 | `module` \| `plugin` \| `template` | 대상 확장 타입 |
| identifier | path | string | 예 | — | 대상 확장 식별자 |
| path | query | string | 예 | 최대 255자 | 삭제할 상대 경로 |

**요청 예시**

```http
DELETE /api/admin/extensions/template/sirsoft-admin_basic/custom-assets?path=10-overrides.css HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| path | string | `10-overrides.css` | 삭제한 상대 경로 |

**응답 예시**

```json
{
    "success": true,
    "message": "파일을 삭제했습니다.",
    "data": {
        "path": "10-overrides.css"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `core.extensions.custom_assets.manage` 권한이 없는 경우 |
| 422 | Unprocessable Entity | 경로 검증 실패 / 파일 부재 / 삭제 실패 |
| 500 | Server Error | 파일시스템 오류 |

<!-- @generated:end -->

**설명** 파일을 삭제합니다. 편집 불가 형식(폰트·이미지)도 삭제 대상입니다 — 삭제까지 편집 확장자로 좁히면 올린 폰트를 지울 방법이 없어집니다. 삭제 후 확장 캐시 버전이 올라 정적 게시본에서도 제거됩니다.


