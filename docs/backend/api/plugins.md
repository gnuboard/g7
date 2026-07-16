# Plugins API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Plugins 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/plugins
<!-- @generated:start:api.admin.plugins.index -->
- **라우트명**: `api.admin.plugins.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search | query | string | 아니오 | max 255 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| filters | query | array | 아니오 | max 10 | 추가 필터 조건 맵 (필드별 조건) |
| status | query | string | 아니오 | `installed`, `uninstalled`, `active`, `inactive` | 상태 필터 (해당 상태의 항목만 조회) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| include_hidden | query | boolean | 아니오 | — | 숨김 확장 포함 여부 (manifest `hidden=true` 로 목록에서 감춰진 플러그인까지 조회) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.plugin.index_validation_rules`).

**요청 예시**

```http
GET /api/admin/plugins?search=%EC%98%88%EC%8B%9C%EA%B0%92&filters=%EC%98%88%EC%8B%9C%EA%B0%92&status=installed&per_page=1&page=1&include_hidden=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | null | `null` | 기본 키 (내부 식별자) |
| identifier | string | `sirsoft-ckeditor5` | 플러그인 고유 식별자 (vendor-plugin 형식) |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `CKEditor 5 WYSIWYG 에디터` | 플러그인 이름 (다국어 JSON) |
| version | string | `1.0.0` | 플러그인 버전 |
| description | string | `CKEditor 5를 이용한 WYSIWYG 에디터 플러그인입니다. …` | 플러그인 설명 (다국어 JSON) |
| dependencies | array | `[]` | 의존하는 확장 맵 (manifest 파생 — {modules, plugins}) |
| permissions | array | `[]` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| roles | array | `[]` | 플러그인이 정의한 역할 목록 (manifest 파생 — 설치 시 시드되는 역할) |
| config | array | `[]` | 플러그인 설정 값 (manifest config 정의 기반 현재 설정 맵) |
| hooks | array | `[]` | 훅 설정 정보 |
| status | string | `active` | 상태 (active: 활성화, inactive: 비활성화, installing: 설치 중, uninstalling: 제거 중, updating: 업데이트 중) |
| is_installed | boolean | `false` | installed 여부 |
| has_settings | boolean | `true` | settings 여부 |
| settings_route | string | `/admin/plugins/sirsoft-ckeditor5/sett…` | 설정 페이지 경로 (설정 UI 진입 라우트, 설정 미제공 시 null) |
| assets | object | `{"js":"\/api\/plugins\/assets\/sirsoft-ckeditor5\/dist\/j…` | 프론트엔드 에셋 매니페스트 (manifest 파생 — js/css 진입점·로딩 전략) |
| update_available | boolean | `false` | 최신 버전 대비 업데이트 가능 여부 |
| update_source | null | `null` | 업데이트 감지 출처 (github, bundled 등) |
| latest_version | string | `1.0.0` | 감지된 최신 배포 버전 |
| file_version | string | `1.0.0` | 설치된 파일의 manifest 버전 |
| github_url | string | `https://github.com/gnuboard/g7-plugin…` | GitHub 저장소 URL |
| github_changelog_url | string | `https://github.com/gnuboard/g7-plugin…` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소에 있어 설치 대기 중인지 여부 |
| is_bundled | boolean | `false` | 코어에 선탑재된 번들 확장인지 여부 |
| deactivated_reason | null | `null` | 비활성화 사유: manual(사용자 수동) \| incompatible_core(코어 버전 호환성) \| null(active) |
| deactivated_at | null | `null` | deactivated 일시 |
| incompatible_required_version | null | `null` | 요구 코어 버전 미충족 시 필요한 버전 (호환되면 null) |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":t…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "플러그인 목록을 성공적으로 가져왔습니다.",
    "data": {
        "data": [
            {
                "id": null,
                "identifier": "sirsoft-ckeditor5",
                "vendor": "sirsoft",
                "name": "CKEditor 5 WYSIWYG 에디터",
                "version": "1.0.0",
                "description": "CKEditor 5를 이용한 WYSIWYG 에디터 플러그인입니다. 플러그인 설치만으로 기존 HtmlEditor가 교체됩니다.",
                "dependencies": [],
                "permissions": [],
                "roles": [],
                "config": [],
                "hooks": [],
                "status": "uninstalled",
                "is_installed": false,
                "has_settings": true,
                "settings_route": "/admin/plugins/sirsoft-ckeditor5/settings",
                "assets": {
                    "js": "/api/plugins/assets/sirsoft-ckeditor5/dist/js/plugin.iife.js",
                    "css": null,
                    "priority": 100
                },
                "update_available": false,
                "update_source": null,
                "latest_version": null,
                "file_version": null,
                "github_url": null,
                "github_changelog_url": null,
                "is_pending": false,
                "is_bundled": false,
                "deactivated_reason": null,
                "deactivated_at": null,
                "incompatible_required_version": null,
                "abilities": {
                    "can_install": true,
                    "can_activate": true,
                    "can_uninstall": true
                }
            },
            {
                "id": null,
                "identifier": "sirsoft-daum_postcode",
                "vendor": "sirsoft",
                "name": "Daum 우편번호",
                "version": "1.0.0",
                "description": "Daum 우편번호 서비스를 통한 주소 검색 기능을 제공하는 플러그인입니다. API 키 없이 무료로 사용할 수 있습니다.",
                "dependencies": [],
                "permissions": [],
                "roles": [],
                "config": [],
                "hooks": [],
                "status": "uninstalled",
                "is_installed": false,
                "has_settings": true,
                "settings_route": "/admin/plugins/sirsoft-daum_postcode/settings",
                "assets": {
                    "js": "/api/plugins/assets/sirsoft-daum_postcode/dist/js/plugin.iife.js",
                    "css": null,
                    "priority": 100
                },
                "update_available": false,
                "update_source": null,
                "latest_version": null,
                "file_version": null,
                "github_url": null,
                "github_changelog_url": null,
                "is_pending": false,
                "is_bundled": false,
                "deactivated_reason": null,
                "deactivated_at": null,
                "incompatible_required_version": null,
                "abilities": {
                    "can_install": true,
                    "can_activate": true,
                    "can_uninstall": true
                }
            },
            "... (총 9건 중 2건 표시)"
        ],
        "pagination": {
            "total": 9,
            "current_page": 1,
            "last_page": 1,
            "per_page": 25
        },
        "meta": {
            "total_plugins": 9,
            "active_plugins": 0,
            "inactive_plugins": 0,
            "installed_plugins": 0,
            "uninstalled_plugins": 9
        },
        "abilities": {
            "can_install": true,
            "can_activate": true,
            "can_uninstall": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 설치된 플러그인과 미설치 플러그인을 모두 포함한 전체 플러그인 목록을 페이지네이션으로 조회합니다. `search` 는 이름·식별자·설명·벤더에 대한 OR 검색이고 `filters` 는 AND 조건으로 적용됩니다. `core.plugins.read` 권한이 필요하며, 응답의 `abilities` 는 현재 사용자의 install/activate/uninstall 권한 보유 여부를 담습니다. 관리자 플러그인 관리 화면의 목록 그리드를 구성하는 기본 엔드포인트입니다.


### POST /api/admin/plugins/activate
<!-- @generated:start:api.admin.plugins.activate -->
- **라우트명**: `api.admin.plugins.activate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@activate`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.activate`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| plugin_name | body | string | 예 | max 255 | plugin 이름 (식별자) |
| force | body | boolean | 아니오 | — | 강제 실행 여부 (안전 확인/선행 검사 우회) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.plugin.activate_validation_rules`).

**요청 예시**

```http
POST /api/admin/plugins/activate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "plugin_name": "예시 이름",
    "force": true
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.activate`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 설치된 플러그인을 활성화합니다. `core.plugins.activate` 권한이 필요합니다. `force` 없이 호출했을 때 필요한 의존 확장이 충족되지 않으면 409 응답으로 `missing_modules`·`missing_plugins` 목록과 함께 경고를 반환하므로, 사용자 확인 후 `force: true` 로 재요청해야 합니다. 재활성화 시 cascade 로 함께 비활성화됐던 번들 언어팩 목록이 `pending_language_packs` 로 응답에 포함됩니다.


### POST /api/admin/plugins/check-updates
<!-- @generated:start:api.admin.plugins.check-updates -->
- **라우트명**: `api.admin.plugins.check-updates`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@checkUpdates`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.install`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/plugins/check-updates HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.install`)이 없는 경우 |

<!-- @generated:end -->

**설명** 설치된 모든 플러그인에 대해 GitHub·번들 소스를 조회하여 새 버전 배포 여부를 일괄 확인합니다. `core.plugins.install` 권한이 필요합니다. 파라미터 없이 호출하며, 각 플러그인의 업데이트 가능 여부와 감지된 최신 버전 정보를 반환합니다. 플러그인 목록 화면 진입 시 업데이트 뱃지를 갱신하는 용도로 사용됩니다.


### POST /api/admin/plugins/deactivate
<!-- @generated:start:api.admin.plugins.deactivate -->
- **라우트명**: `api.admin.plugins.deactivate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@deactivate`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.activate`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| plugin_name | body | string | 예 | max 255 | plugin 이름 (식별자) |
| force | body | boolean | 아니오 | — | 강제 실행 여부 (안전 확인/선행 검사 우회) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.plugin.deactivate_validation_rules`).

**요청 예시**

```http
POST /api/admin/plugins/deactivate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "plugin_name": "예시 이름",
    "force": true
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.activate`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 활성 플러그인을 비활성화합니다. `core.plugins.activate` 권한이 필요합니다. `force` 없이 호출했을 때 이 플러그인에 의존하는 템플릿·모듈·플러그인이 있으면 409 응답으로 `dependent_templates`·`dependent_modules`·`dependent_plugins` 목록과 함께 경고를 반환합니다. 의존 관계 확인 후 `force: true` 로 강제 비활성화할 수 있습니다.


### POST /api/admin/plugins/install
<!-- @generated:start:api.admin.plugins.install -->
- **라우트명**: `api.admin.plugins.install`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@install`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| plugin_name | body | string | 예 | max 255 | plugin 이름 (식별자) |
| vendor_mode | body | string | 아니오 | `auto`, `composer`, `bundled` | 벤더 설치 모드 (auto/composer/bundled) |
| dependencies | body | array | 아니오 | — | 함께 설치할 의존 확장 목록 (install-preview 응답 기반 사용자 선택 — 원소 type: module\|plugin, identifier) |
| language_packs | body | array | 아니오 | — | 함께 설치할 번들 언어팩 식별자 목록 (best-effort cascade 2단계) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.plugin.install_validation_rules`).

**요청 예시**

```http
POST /api/admin/plugins/install HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "plugin_name": "예시 이름",
    "vendor_mode": "auto",
    "dependencies": [
        "예시값"
    ],
    "language_packs": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** `_pending`·`_bundled` 대기소에 있는 플러그인을 활성 디렉토리로 설치합니다. `core.plugins.install` 권한이 필요합니다. `vendor_mode` 로 Composer 의존성 설치 방식을(auto/composer/bundled) 지정하며, 요청 본문의 `dependencies` 로 선택한 의존 확장을 먼저 설치(cascade 1단계, 실패 시 전체 중단)한 뒤 `language_packs` 로 지정한 번들 언어팩을 best-effort 로 함께 설치합니다(cascade 2단계). 언어팩 설치 실패는 응답의 `language_pack_failures` 에 담겨 반환됩니다.


### POST /api/admin/plugins/install-from-file
<!-- @generated:start:api.admin.plugins.install-from-file -->
- **라우트명**: `api.admin.plugins.install-from-file`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@installFromFile`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 51200 | 업로드 파일 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.plugin.install_from_file_validation_rules`).

**요청 예시**

```http
POST /api/admin/plugins/install-from-file HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 업로드된 ZIP 파일에서 플러그인을 설치합니다. `core.plugins.install` 권한이 필요하며, 파일은 최대 50MB(51200KB)까지 허용됩니다. ZIP 압축 해제 후 plugin.json 검증을 거쳐 설치하며, 성공 시 201 상태로 설치된 플러그인 정보를 반환합니다. 설치 전 manifest 만 미리 확인하려면 `manifest-preview` 를 먼저 호출하는 것이 안전합니다.


### POST /api/admin/plugins/install-from-github
<!-- @generated:start:api.admin.plugins.install-from-github -->
- **라우트명**: `api.admin.plugins.install-from-github`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@installFromGithub`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| github_url | body | string | 예 | — | GitHub 저장소 URL |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.plugin.install_from_github_validation_rules`).

**요청 예시**

```http
POST /api/admin/plugins/install-from-github HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "github_url": "https://example.com"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** GitHub 저장소 URL 에서 플러그인을 내려받아 설치합니다. `core.plugins.install` 권한이 필요합니다. `github_url` 로 지정한 공개 저장소의 릴리스/소스를 받아 압축 해제·검증 후 설치하며, 성공 시 201 상태로 설치된 플러그인 정보를 반환합니다.


### GET /api/admin/plugins/installed
<!-- @generated:start:api.admin.plugins.installed -->
- **라우트명**: `api.admin.plugins.installed`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@installed`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/plugins/installed HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | null | `null` | 기본 키 (내부 식별자) |
| identifier | string | `sirsoft-ckeditor5` | 플러그인 고유 식별자 (vendor-plugin 형식) |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `CKEditor 5 WYSIWYG 에디터` | 플러그인 이름 (다국어 JSON) |
| version | string | `1.0.0` | 플러그인 버전 |
| description | string | `CKEditor 5를 이용한 WYSIWYG 에디터 플러그인입니다. …` | 플러그인 설명 (다국어 JSON) |
| dependencies | array | `[]` | 의존하는 확장 맵 (manifest 파생 — {modules, plugins}) |
| permissions | array | `[]` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| roles | array | `[]` | 플러그인이 정의한 역할 목록 (manifest 파생 — 설치 시 시드되는 역할) |
| config | array | `[]` | 플러그인 설정 값 (manifest config 정의 기반 현재 설정 맵) |
| hooks | array | `[]` | 훅 설정 정보 |
| status | string | `active` | 상태 (active: 활성화, inactive: 비활성화, installing: 설치 중, uninstalling: 제거 중, updating: 업데이트 중) |
| is_installed | boolean | `false` | installed 여부 |
| has_settings | boolean | `true` | settings 여부 |
| settings_route | string | `/admin/plugins/sirsoft-ckeditor5/sett…` | 설정 페이지 경로 (설정 UI 진입 라우트, 설정 미제공 시 null) |
| assets | object | `{"js":"\/api\/plugins\/assets\/sirsoft-ckeditor5\/dist\/j…` | 프론트엔드 에셋 매니페스트 (manifest 파생 — js/css 진입점·로딩 전략) |
| update_available | boolean | `false` | 최신 버전 대비 업데이트 가능 여부 |
| update_source | null | `null` | 업데이트 감지 출처 (github, bundled 등) |
| latest_version | string | `1.0.0` | 감지된 최신 배포 버전 |
| file_version | string | `1.0.0` | 설치된 파일의 manifest 버전 |
| github_url | string | `https://github.com/gnuboard/g7-plugin…` | GitHub 저장소 URL |
| github_changelog_url | string | `https://github.com/gnuboard/g7-plugin…` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소에 있어 설치 대기 중인지 여부 |
| is_bundled | boolean | `false` | 코어에 선탑재된 번들 확장인지 여부 |
| deactivated_reason | null | `null` | 비활성화 사유: manual(사용자 수동) \| incompatible_core(코어 버전 호환성) \| null(active) |
| deactivated_at | null | `null` | deactivated 일시 |
| incompatible_required_version | null | `null` | 요구 코어 버전 미충족 시 필요한 버전 (호환되면 null) |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":t…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "플러그인 목록을 성공적으로 가져왔습니다.",
    "data": {
        "data": [],
        "meta": {
            "total_plugins": 0,
            "active_plugins": 0,
            "inactive_plugins": 0,
            "installed_plugins": 0,
            "uninstalled_plugins": 0
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 현재 설치된 플러그인만 조회합니다(미설치 항목 제외). 이 엔드포인트는 세부 권한 미들웨어 없이 `auth:sanctum` 인증만 요구하므로, 다른 화면이 활성/설치된 플러그인 목록을 참조할 때 사용하는 경량 조회 API 입니다. 페이지네이션 없이 설치된 항목 배열을 반환합니다.


### POST /api/admin/plugins/manifest-preview
<!-- @generated:start:api.admin.plugins.manifest-preview -->
- **라우트명**: `api.admin.plugins.manifest-preview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@manifestPreview`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 51200 | 업로드 파일 |

**요청 예시**

```http
POST /api/admin/plugins/manifest-preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 업로드된 ZIP 파일의 plugin.json manifest 와 검증 결과만 추출합니다(실제 설치는 수행하지 않음). `core.plugins.install` 권한이 필요하며 파일은 최대 50MB 까지 허용됩니다. 설치 모달에서 사용자가 파일 선택 직후 manifest 유효성과 검증 실패 사유를 미리 확인하는 용도입니다. 검증 오류 시 422 로 사유를 반환합니다.


### POST /api/admin/plugins/refresh-layouts
<!-- @generated:start:api.admin.plugins.refresh-layouts -->
- **라우트명**: `api.admin.plugins.refresh-layouts`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@refreshLayouts`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.activate`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| plugin_name | body | string | 예 | max 255 | plugin 이름 (식별자) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.plugin.refresh_layouts_validation_rules`).

**요청 예시**

```http
POST /api/admin/plugins/refresh-layouts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "plugin_name": "예시 이름"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.activate`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 플러그인의 레이아웃 파일을 다시 읽어 DB 에 동기화합니다. `core.plugins.activate` 권한이 필요합니다. 파일에서 변경된 레이아웃은 갱신되고 삭제된 레이아웃은 DB 에서도 제거되며, 응답으로 created/updated/deleted/unchanged 건수를 반환합니다. 플러그인의 `_bundled` 레이아웃 JSON 을 수정한 뒤 재빌드 없이 반영할 때 사용합니다.


### DELETE /api/admin/plugins/uninstall
<!-- @generated:start:api.admin.plugins.uninstall -->
- **라우트명**: `api.admin.plugins.uninstall`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@uninstall`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.uninstall`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| plugin_name | query | string | 예 | max 255 | plugin 이름 (식별자) |
| delete_data | query | boolean | 아니오 | — | 데이터 삭제 여부 (true 시 플러그인이 생성한 DB 데이터까지 함께 삭제, 미지정 시 데이터 보존) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.plugin.uninstall_validation_rules`).

**요청 예시**

```http
DELETE /api/admin/plugins/uninstall?plugin_name=%EC%98%88%EC%8B%9C%20%EC%9D%B4%EB%A6%84&delete_data=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.uninstall`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 플러그인을 시스템에서 제거합니다. `core.plugins.uninstall` 권한이 필요합니다. 활성 디렉토리만 삭제하고 `_bundled` 원본은 보존합니다. `delete_data: true` 인 경우 플러그인이 생성한 DB 데이터까지 함께 삭제하며, 기본값은 데이터 보존입니다. 삭제될 데이터 범위는 사전에 `uninstall-info` 로 확인할 수 있습니다.


### GET /api/admin/plugins/{identifier}/changelog
<!-- @generated:start:api.admin.plugins.changelog -->
- **라우트명**: `api.admin.plugins.changelog`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@changelog`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |
| source | query | string | 아니오 | `active`, `bundled`, `github` | 변경 내역 조회 출처 (active: 활성 설치본, bundled: 번들 원본, github: 원격 저장소) |
| from_version | query | string | 아니오 | — | 시작 버전 (범위 하한) |
| to_version | query | string | 아니오 | — | 대상 버전 (범위 상한) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.extension.changelog_rules`).

**요청 예시**

```http
GET /api/admin/plugins/{identifier}/changelog?source=active&from_version=%EC%98%88%EC%8B%9C%EA%B0%92&to_version=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 플러그인의 변경 내역(CHANGELOG)을 조회합니다. `core.plugins.read` 권한이 필요합니다. `source` 로 조회 출처를(active: 활성 설치본, bundled: 번들 원본, github: 원격 저장소) 선택하고, `from_version`·`to_version` 으로 버전 구간을 좁힐 수 있습니다. 업데이트 전 사용자에게 변경 사항을 안내하는 데 사용됩니다.


### GET /api/admin/plugins/{identifier}/dependent-templates
<!-- @generated:start:api.admin.plugins.dependent-templates -->
- **라우트명**: `api.admin.plugins.dependent-templates`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@dependentTemplates`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/admin/plugins/{identifier}/dependent-templates HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 이 플러그인에 의존하는 템플릿 목록을 조회합니다. `core.plugins.read` 권한이 필요합니다. 응답으로 의존 템플릿 배열과 총 개수를 반환하며, 플러그인 비활성화·제거 전 영향을 받는 템플릿을 사용자에게 미리 알리는 데 사용됩니다.


### GET /api/admin/plugins/{identifier}/license
<!-- @generated:start:api.admin.plugins.license -->
- **라우트명**: `api.admin.plugins.license`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@license`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/admin/plugins/{identifier}/license HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 플러그인에 포함된 라이선스 파일의 원문 내용을 반환합니다. `core.plugins.read` 권한이 필요합니다. `identifier` 는 소문자·숫자·하이픈·언더스코어 형식만 허용되며 형식에 맞지 않거나 라이선스 파일이 없으면 404 를 반환합니다. 라이선스 고지 화면에 전문을 표시하는 용도입니다.


### GET /api/admin/plugins/{identifier}/settings
<!-- @generated:start:api.admin.plugins.settings.show -->
- **라우트명**: `api.admin.plugins.settings.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginSettingsController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/admin/plugins/{identifier}/settings HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 플러그인의 현재 설정 값을 조회합니다. `core.plugins.read` 권한이 필요합니다. 저장된 설정이 없거나 플러그인을 찾을 수 없으면 404 를 반환합니다. 설정 페이지 진입 시 폼의 현재 값을 채우는 용도이며, 폼 스키마/UI 구성은 별도의 `settings/layout` 엔드포인트에서 조회합니다.


### PUT /api/admin/plugins/{identifier}/settings
<!-- @generated:start:api.admin.plugins.settings.update -->
- **라우트명**: `api.admin.plugins.settings.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginSettingsController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.plugin_settings.update_rules`).

**요청 예시**

```http
PUT /api/admin/plugins/{identifier}/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 플러그인의 설정 값을 저장합니다. `core.plugins.update` 권한이 필요합니다. 검증된 값을 우선 사용하되, PluginManager 에 등록되지 않아 검증 규칙이 없는 플러그인의 경우 요청 본문 전체(`all()`)를 저장합니다. 저장 실패 시 500 을 반환하고, 성공 시 갱신된 설정 값을 함께 반환합니다.


### GET /api/admin/plugins/{identifier}/settings/layout
<!-- @generated:start:api.admin.plugins.settings.layout -->
- **라우트명**: `api.admin.plugins.settings.layout`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginSettingsController@layout`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/admin/plugins/{identifier}/settings/layout HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 플러그인 설정 페이지의 UI 구성과 설정 스키마(레이아웃)를 조회합니다. `core.plugins.read` 권한이 필요합니다. 레이아웃이 정의되지 않았거나 플러그인을 찾을 수 없으면 404 를 반환합니다. 설정 값 조회(`settings`)와 짝을 이루어 설정 화면을 렌더링하는 데 사용됩니다.


### GET /api/admin/plugins/{pluginName}
<!-- @generated:start:api.admin.plugins.show -->
- **라우트명**: `api.admin.plugins.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pluginName | path | string | 예 | — | 대상 plugin의 이름 (식별자) |

**요청 예시**

```http
GET /api/admin/plugins/{pluginName} HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 플러그인의 상세 정보를 조회합니다. `core.plugins.read` 권한이 필요합니다. 목록보다 자세한 `toDetailArray()` 형태를 반환하며, 이 플러그인이 지원하는 번들 언어팩 정보가 함께 주입됩니다. 플러그인을 찾을 수 없으면 404 를 반환합니다.


### GET /api/admin/plugins/{pluginName}/check-modified-layouts
<!-- @generated:start:api.admin.plugins.check-modified-layouts -->
- **라우트명**: `api.admin.plugins.check-modified-layouts`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@checkModifiedLayouts`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pluginName | path | string | 예 | — | 대상 plugin의 이름 (식별자) |

**요청 예시**

```http
GET /api/admin/plugins/{pluginName}/check-modified-layouts HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 플러그인에서 사용자가 수정한 레이아웃이 있는지 확인합니다. `core.plugins.read` 권한이 필요합니다. 업데이트 실행 전 이 정보를 조회하여 레이아웃 전략(overwrite: 새 버전으로 교체, keep: 사용자 수정본 유지) 선택을 안내하는 데 사용됩니다.


### GET /api/admin/plugins/{pluginName}/install-preview
<!-- @generated:start:api.admin.plugins.install-preview -->
- **라우트명**: `api.admin.plugins.install-preview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@installPreview`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pluginName | path | string | 예 | — | 대상 plugin의 이름 (식별자) |

**요청 예시**

```http
GET /api/admin/plugins/{pluginName}/install-preview HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.plugins.install`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 플러그인 설치 시 함께 처리될 cascade 후보(의존 확장 + 동반 가능한 번들 언어팩) 트리를 반환합니다. `core.plugins.install` 권한이 필요합니다. 설치 모달 오픈 시 호출되어 사용자가 함께 설치할 항목을 선택하도록 노출하며, ZIP 업로드 기반의 `manifest-preview` 와 달리 이미 알려진 식별자에 대한 GET 조회입니다.


### GET /api/admin/plugins/{pluginName}/uninstall-info
<!-- @generated:start:api.admin.plugins.uninstall-info -->
- **라우트명**: `api.admin.plugins.uninstall-info`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@uninstallInfo`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.uninstall`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pluginName | path | string | 예 | — | 대상 plugin의 이름 (식별자) |

**요청 예시**

```http
GET /api/admin/plugins/{pluginName}/uninstall-info HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.plugins.uninstall`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 플러그인 제거 시 삭제될 데이터 정보를 조회합니다. `core.plugins.uninstall` 권한이 필요합니다. 제거 확인 모달에서 사용자에게 어떤 데이터가 사라지는지 미리 보여주는 용도이며, 플러그인을 찾을 수 없으면 404 를 반환합니다.


### POST /api/admin/plugins/{pluginName}/update
<!-- @generated:start:api.admin.plugins.update -->
- **라우트명**: `api.admin.plugins.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginController@performUpdate`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pluginName | path | string | 예 | — | 대상 plugin의 이름 (식별자) |
| layout_strategy | body | string | 아니오 | `overwrite`, `keep` | 레이아웃 처리 전략 (overwrite: 새 버전으로 교체, keep: 사용자 수정본 유지) |
| vendor_mode | body | string | 아니오 | `auto`, `composer`, `bundled` | 벤더 설치 모드 (auto/composer/bundled) |
| force | body | boolean | 아니오 | — | 강제 실행 여부 (안전 확인/선행 검사 우회) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.plugin.perform_update_validation_rules`).

**요청 예시**

```http
POST /api/admin/plugins/{pluginName}/update HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "layout_strategy": "overwrite",
    "vendor_mode": "auto",
    "force": true
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 플러그인을 최신 버전으로 업데이트합니다. `core.plugins.install` 권한이 필요합니다. `layout_strategy` 로 레이아웃 처리 방식을(overwrite: 새 버전으로 교체, keep: 사용자 수정본 유지) 지정하며, `vendor_mode` 로 Composer 의존성 처리 방식을 선택합니다. 버전 제약·호환성 문제로 막힐 경우 `force: true` 로 강제 진행할 수 있습니다.


### GET /api/plugins/assets/{identifier}/{path}
<!-- @generated:start:api.public.plugins.assets -->
- **라우트명**: `api.public.plugins.assets`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicPluginController@serveAsset`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |
| path | path | string | 예 | — | 경로 |

**요청 예시**

```http
GET /api/plugins/assets/{identifier}/{path} HTTP/1.1
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 플러그인의 개별 프론트엔드 에셋 파일(JS/CSS/이미지 등)을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않으며, 경로·확장자 보안 검증은 FormRequest 에서 완료됩니다. 플러그인 미존재·파일 미존재·허용되지 않은 파일 유형은 각각 404/404/403 으로 응답하고, 정상 파일은 ETag 와 1년 캐시 헤더를 붙여 반환합니다. 소스맵 등 개별 에셋을 직접 참조할 때 사용되며, 통합 로딩은 `bundle.js`/`bundle.css` 를 사용합니다.


### GET /api/plugins/bundle.css
<!-- @generated:start:api.public.plugins.bundle.css -->
- **라우트명**: `api.public.plugins.bundle.css`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicPluginController@serveBundleCss`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/bundle.css HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-200 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-200 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회 — 활성 에셋이 없으면 빈 200 응답)._

<!-- @generated:end -->

**설명** 활성 플러그인들의 프론트엔드 CSS 를 서버에서 하나로 병합한 번들을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않습니다. 활성 global 플러그인 에셋이 없으면 빈 200(text/css) 응답을 반환하고, 있으면 병합 파일을 ETag·환경별 Cache-Control 과 함께 서빙합니다. 페이지가 플러그인 스타일을 요청 1건으로 로드하도록 합니다.


### GET /api/plugins/bundle.js
<!-- @generated:start:api.public.plugins.bundle.js -->
- **라우트명**: `api.public.plugins.bundle.js`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicPluginController@serveBundleJs`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/bundle.js HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-200 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-200 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회 — 활성 에셋이 없으면 빈 200 응답)._

<!-- @generated:end -->

**설명** 활성 플러그인들의 프론트엔드 IIFE JS 를 서버에서 하나로 병합한 번들을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않습니다. 활성 global 플러그인 에셋이 없으면 빈 200(text/javascript) 응답을 반환하고, 있으면 병합 파일을 ETag·환경별 Cache-Control 과 함께 서빙합니다. 프론트는 `G7Config.bundleUrls` 를 읽어 이 번들을 로드합니다.


### GET /api/plugins/{identifier}/components.json
<!-- @generated:start:api.public.plugins.components -->
- **라우트명**: `api.public.plugins.components`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicPluginController@serveComponents`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/plugins/{identifier}/components.json HTTP/1.1
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

**설명** 플러그인의 컴포넌트 정의 파일(components.json)을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않습니다. 편집 모드 부팅 시 ComponentRegistry 가 활성 확장 매니페스트를 네임스페이스 병합하기 위해 fetch 하며, 구버전 플러그인처럼 파일이 없으면 빈 components 로 폴백합니다. 응답은 1시간 캐시됩니다. 플러그인 미존재 시 404.


### GET /api/plugins/{identifier}/editor-spec
<!-- @generated:start:api.public.plugins.editor_spec -->
- **라우트명**: `api.public.plugins.editor_spec`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicPluginController@serveEditorSpec`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/plugins/{identifier}/editor-spec HTTP/1.1
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

**설명** 플러그인의 레이아웃 편집기 스펙(editor-spec.json)을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않습니다. 활성 플러그인만 대상으로 하며 활성 디렉토리 → `_bundled` 폴백 순으로 읽어 `data.spec` 형태로 반환합니다. 비활성·미존재 플러그인은 404 이고, 편집기 스펙 파일을 작성하지 않은 경우 spec=null 로 정상 응답합니다.


