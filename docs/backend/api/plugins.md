# Plugins API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Plugins 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
| assets | object | `{"js":"\/api\/plugins\/assets\/sirsoft-ckeditor5?file=dis…` | 프론트엔드 에셋 매니페스트 (manifest 파생 — js/css 진입점·로딩 전략) |
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
                "status": "active",
                "is_installed": false,
                "has_settings": true,
                "settings_route": "/admin/plugins/sirsoft-ckeditor5/settings",
                "assets": {
                    "js": "/api/plugins/assets/sirsoft-ckeditor5?file=dist%2Fjs%2Fplugin.iife.js",
                    "css": null,
                    "priority": 100
                },
                "update_available": false,
                "update_source": null,
                "latest_version": "1.0.0",
                "file_version": "1.0.0",
                "github_url": "https://github.com/gnuboard/g7-plugin-sirsoft-ckeditor5",
                "github_changelog_url": "https://github.com/gnuboard/g7-plugin-sirsoft-ckeditor5/releases",
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
                "version": "1.0.1",
                "description": "Daum 우편번호 서비스를 통한 주소 검색 기능을 제공하는 플러그인입니다. API 키 없이 무료로 사용할 수 있습니다.",
                "dependencies": [],
                "permissions": [],
                "roles": [],
                "config": [],
                "hooks": [],
                "status": "active",
                "is_installed": false,
                "has_settings": true,
                "settings_route": "/admin/plugins/sirsoft-daum_postcode/settings",
                "assets": {
                    "js": "/api/plugins/assets/sirsoft-daum_postcode?file=dist%2Fjs%2Fplugin.iife.js",
                    "css": null,
                    "priority": 100
                },
                "update_available": false,
                "update_source": null,
                "latest_version": "1.0.1",
                "file_version": "1.0.1",
                "github_url": "https://github.com/gnuboard/g7-plugin-sirsoft-daum_postcode",
                "github_changelog_url": "https://github.com/gnuboard/g7-plugin-sirsoft-daum_postcode/releases",
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
            "... (총 10건 중 2건 표시)"
        ],
        "pagination": {
            "total": 10,
            "current_page": 1,
            "last_page": 1,
            "per_page": 25
        },
        "meta": {
            "total_plugins": 10,
            "active_plugins": 8,
            "inactive_plugins": 0,
            "installed_plugins": 8,
            "uninstalled_plugins": 2
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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| plugin | object | `{"identifier":"sirsoft-daum_postcode","status":"active", …}` | 활성화된 플러그인 리소스 (PluginResource — 목록 항목과 동일 필드 구성) |
| pending_language_packs | array | `[]` | 이 플러그인 비활성화 시 cascade 로 함께 비활성화됐던 번들 언어팩 목록 (재활성화 대기 후보) |

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인이 성공적으로 활성화되었습니다.",
    "data": {
        "plugin": {
            "id": 3,
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
            "status": "active",
            "is_installed": true,
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
            "file_version": "1.0.0",
            "github_url": null,
            "github_changelog_url": null,
            "is_pending": false,
            "is_bundled": true,
            "deactivated_reason": null,
            "deactivated_at": null,
            "incompatible_required_version": null,
            "abilities": {
                "can_install": true,
                "can_activate": true,
                "can_uninstall": true
            }
        },
        "pending_language_packs": []
    }
}
```

의존 확장이 미충족인 상태에서 `force` 없이 호출하면 409 로 경고가 반환됩니다 (`error.warning`, `error.missing_modules`, `error.missing_plugins`, `error.message`).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 409 | Conflict | 필요한 의존 확장(모듈/플러그인)이 설치·활성화되지 않은 경우 (`force: true` 로 우회 가능) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Server Error | 활성화 처리 중 예외 발생 (`plugins.activate_error`) |

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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `1` | 업데이트 가능으로 감지된 플러그인 개수 |
| details | array | `[{"identifier":"sirsoft-daum_postcode", …}]` | 업데이트 가능한 플러그인별 상세 배열 (아래 필드) |
| details[].identifier | string | `sirsoft-daum_postcode` | 플러그인 고유 식별자 |
| details[].current_version | string | `1.0.0` | 현재 설치된 버전 |
| details[].latest_version | string | `1.1.0` | 감지된 최신 배포 버전 |
| details[].update_source | string | `github` | 업데이트 감지 출처 (`github` \| `bundled`) |

**응답 예시**

```json
{
    "success": true,
    "message": "업데이트 확인이 완료되었습니다.",
    "data": {
        "updated_count": 1,
        "details": [
            {
                "identifier": "sirsoft-daum_postcode",
                "current_version": "1.0.0",
                "latest_version": "1.1.0",
                "update_source": "github"
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Server Error | 업데이트 확인 중 예외 발생 (`plugins.check_updates_failed`) |

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

_단건 응답: `data` 객체의 필드 — 비활성화된 플러그인 리소스(PluginResource) 를 그대로 반환합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer\|null | `3` | 기본 키 (내부 식별자) |
| identifier | string | `sirsoft-daum_postcode` | 플러그인 고유 식별자 (vendor-plugin 형식) |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `Daum 우편번호` | 플러그인 이름 (현재 로케일로 해석) |
| version | string | `1.0.0` | 플러그인 버전 |
| status | string | `inactive` | 비활성화 후 상태 (`inactive`) |
| is_installed | boolean | `true` | 설치 여부 |
| deactivated_reason | string\|null | `manual` | 비활성화 사유 (manual: 사용자 수동 \| incompatible_core: 코어 버전 비호환) |
| deactivated_at | string\|null | `2026-07-14T05:12:33.000000Z` | 비활성화 일시 |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자의 수행 가능 작업 맵 |

나머지 필드는 목록(`GET /api/admin/plugins`) 응답 항목과 동일합니다 (PluginResource 단일 정의).

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인이 성공적으로 비활성화되었습니다.",
    "data": {
        "id": 3,
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
        "status": "inactive",
        "is_installed": true,
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
        "file_version": "1.0.0",
        "github_url": null,
        "github_changelog_url": null,
        "is_pending": false,
        "is_bundled": true,
        "deactivated_reason": "manual",
        "deactivated_at": "2026-07-14T05:12:33.000000Z",
        "incompatible_required_version": null,
        "abilities": {
            "can_install": true,
            "can_activate": true,
            "can_uninstall": true
        }
    }
}
```

의존 확장이 있는 상태에서 `force` 없이 호출하면 409 로 경고가 반환됩니다 (`error.warning`, `error.dependent_templates`, `error.dependent_modules`, `error.dependent_plugins`, `error.message`).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 409 | Conflict | 이 플러그인에 의존하는 활성 템플릿/모듈/플러그인이 있는 경우 (`force: true` 로 우회 가능) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Server Error | 비활성화 처리 중 예외 발생 (`plugins.deactivate_error`) |

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

_단건 응답: `data` 객체의 필드 — 설치된 플러그인 리소스(PluginResource) + `language_pack_failures`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-daum_postcode` | 설치된 플러그인 고유 식별자 |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `Daum 우편번호` | 플러그인 이름 (현재 로케일로 해석) |
| version | string | `1.0.0` | 설치된 버전 |
| status | string | `inactive` | 설치 직후 상태 (설치만 수행 — 활성화는 별도 호출) |
| is_installed | boolean | `true` | 설치 여부 |
| has_settings | boolean | `true` | 설정 UI 제공 여부 |
| settings_route | string\|null | `/admin/plugins/sirsoft-daum_postcode/settings` | 설정 페이지 경로 |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자의 수행 가능 작업 맵 |
| language_pack_failures | array | `[]` | cascade 2단계(번들 언어팩 best-effort 설치) 에서 실패한 항목 목록 (성공 시 빈 배열) |

나머지 필드는 목록(`GET /api/admin/plugins`) 응답 항목과 동일합니다 (PluginResource 단일 정의).

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인이 성공적으로 설치되었습니다.",
    "data": {
        "id": 3,
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
        "status": "inactive",
        "is_installed": true,
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
        "file_version": "1.0.0",
        "github_url": null,
        "github_changelog_url": null,
        "is_pending": false,
        "is_bundled": true,
        "deactivated_reason": null,
        "deactivated_at": null,
        "incompatible_required_version": null,
        "abilities": {
            "can_install": true,
            "can_activate": true,
            "can_uninstall": true
        },
        "language_pack_failures": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패 또는 설치 실패 (`plugin_name` 에 번역된 설치 실패 사유 — 이미 설치됨/대기소 미존재/의존성 미충족 등) |
| 500 | Server Error | 설치 처리 중 예외 발생 (`plugins.installation_failed`) |

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

_단건 응답: `data` 객체의 필드 — 설치된 플러그인 리소스(PluginResource). 성공 시 HTTP 201._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-daum_postcode` | 설치된 플러그인 고유 식별자 (ZIP 내 plugin.json 기준) |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `Daum 우편번호` | 플러그인 이름 |
| version | string | `1.0.0` | 설치된 버전 |
| status | string | `inactive` | 설치 직후 상태 |
| is_installed | boolean | `true` | 설치 여부 |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자의 수행 가능 작업 맵 |

나머지 필드는 목록(`GET /api/admin/plugins`) 응답 항목과 동일합니다 (PluginResource 단일 정의).

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인이 성공적으로 설치되었습니다.",
    "data": {
        "id": 3,
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
        "status": "inactive",
        "is_installed": true,
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
        "file_version": "1.0.0",
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
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 파일 검증 실패(ZIP 아님/50MB 초과) 또는 ZIP 처리 실패 (plugin.json 미존재/형식 오류/식별자 누락/이미 설치됨) |
| 500 | Server Error | 설치 처리 중 예외 발생 (`plugins.install_failed`) |

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

_단건 응답: `data` 객체의 필드 — 설치된 플러그인 리소스(PluginResource). 성공 시 HTTP 201._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-daum_postcode` | 설치된 플러그인 고유 식별자 (저장소 plugin.json 기준) |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `Daum 우편번호` | 플러그인 이름 |
| version | string | `1.0.0` | 설치된 버전 (GitHub 릴리스 기준) |
| status | string | `inactive` | 설치 직후 상태 |
| is_installed | boolean | `true` | 설치 여부 |
| github_url | string\|null | `https://github.com/gnuboard/g7-plugin-daum_postcode` | 설치 출처 GitHub 저장소 URL |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자의 수행 가능 작업 맵 |

나머지 필드는 목록(`GET /api/admin/plugins`) 응답 항목과 동일합니다 (PluginResource 단일 정의).

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인이 성공적으로 설치되었습니다.",
    "data": {
        "id": 3,
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
        "status": "inactive",
        "is_installed": true,
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
        "file_version": "1.0.0",
        "github_url": "https://github.com/gnuboard/g7-plugin-daum_postcode",
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
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 422 | Unprocessable Entity | URL 형식 오류(GitHub 저장소 URL 아님) 또는 다운로드/압축 해제/검증 실패 (저장소 미존재·ZIP URL 미발견·plugin.json 오류·이미 설치됨) |
| 500 | Server Error | 설치 처리 중 예외 발생 (`plugins.install_failed`) |

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
| assets | object | `{"js":"\/api\/plugins\/assets\/sirsoft-ckeditor5?file=dis…` | 프론트엔드 에셋 매니페스트 (manifest 파생 — js/css 진입점·로딩 전략) |
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
                "status": "active",
                "is_installed": false,
                "has_settings": true,
                "settings_route": "/admin/plugins/sirsoft-ckeditor5/settings",
                "assets": {
                    "js": "/api/plugins/assets/sirsoft-ckeditor5?file=dist%2Fjs%2Fplugin.iife.js",
                    "css": null,
                    "priority": 100
                },
                "update_available": false,
                "update_source": null,
                "latest_version": "1.0.0",
                "file_version": "1.0.0",
                "github_url": "https://github.com/gnuboard/g7-plugin-sirsoft-ckeditor5",
                "github_changelog_url": "https://github.com/gnuboard/g7-plugin-sirsoft-ckeditor5/releases",
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
                "version": "1.0.1",
                "description": "Daum 우편번호 서비스를 통한 주소 검색 기능을 제공하는 플러그인입니다. API 키 없이 무료로 사용할 수 있습니다.",
                "dependencies": [],
                "permissions": [],
                "roles": [],
                "config": [],
                "hooks": [],
                "status": "active",
                "is_installed": false,
                "has_settings": true,
                "settings_route": "/admin/plugins/sirsoft-daum_postcode/settings",
                "assets": {
                    "js": "/api/plugins/assets/sirsoft-daum_postcode?file=dist%2Fjs%2Fplugin.iife.js",
                    "css": null,
                    "priority": 100
                },
                "update_available": false,
                "update_source": null,
                "latest_version": "1.0.1",
                "file_version": "1.0.1",
                "github_url": "https://github.com/gnuboard/g7-plugin-sirsoft-daum_postcode",
                "github_changelog_url": "https://github.com/gnuboard/g7-plugin-sirsoft-daum_postcode/releases",
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
            "... (총 8건 중 2건 표시)"
        ],
        "meta": {
            "total_plugins": 8,
            "active_plugins": 8,
            "inactive_plugins": 0,
            "installed_plugins": 8,
            "uninstalled_plugins": 0
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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| manifest | object\|null | `{"identifier":"sirsoft-daum_postcode","version":"1.0.0", …}` | ZIP 내 plugin.json 원문 (파싱 실패 시 null) |
| validation | object | `{"errors":[],"is_valid":true, …}` | 검증 결과 객체 (아래 필드) |
| validation.errors | array | `[]` | 압축 해제/manifest 검증 실패 사유 문자열 목록 (성공 시 빈 배열) |
| validation.is_valid | boolean | `true` | 검증 통과 여부 (errors 가 비었고 manifest 파싱 성공) |
| validation.already_installed | boolean | `false` | 동일 식별자 플러그인이 이미 설치되어 있는지 여부 |
| validation.existing_version | string\|null | `1.0.0` | 이미 설치된 경우 그 버전 (미설치 시 null) |

**응답 예시**

```json
{
    "success": true,
    "message": "manifest 미리보기를 완료했습니다.",
    "data": {
        "manifest": {
            "identifier": "sirsoft-daum_postcode",
            "vendor": "sirsoft",
            "name": "Daum 우편번호",
            "version": "1.0.0",
            "description": "Daum 우편번호 서비스를 통한 주소 검색 기능을 제공하는 플러그인입니다. API 키 없이 무료로 사용할 수 있습니다."
        },
        "validation": {
            "errors": [],
            "is_valid": true,
            "already_installed": false,
            "existing_version": null
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 파일 검증 실패(ZIP 아님/50MB 초과) 또는 미리보기 처리 실패 (`plugins.preview_failed`) |

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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| layouts_refreshed | integer | `3` | 갱신 처리된 레이아웃 총 건수 |
| created | integer | `1` | 신규 등록된 레이아웃 건수 |
| updated | integer | `2` | 내용이 변경되어 갱신된 레이아웃 건수 |
| deleted | integer | `0` | 파일에서 사라져 DB 에서도 삭제된 레이아웃 건수 |
| unchanged | integer | `0` | 변경 없이 그대로 유지된 레이아웃 건수 |

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인 레이아웃이 성공적으로 갱신되었습니다.",
    "data": {
        "layouts_refreshed": 3,
        "created": 1,
        "updated": 2,
        "deleted": 0,
        "unchanged": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패 또는 레이아웃 갱신 실패 (`plugin_name` 에 레이아웃 JSON 파싱/검증 오류 사유) |
| 500 | Server Error | 갱신 처리 중 예외 발생 (`plugins.refresh_layouts_error`) |

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

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만)._

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인이 성공적으로 제거되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패 또는 제거 실패 (`plugin_name` 에 번역된 제거 실패 사유 — 미설치/진행 중 상태 등) |
| 500 | Server Error | 제거 처리 중 예외 발생 (`plugins.uninstall_error`) |

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

**요청 예시**

```http
GET /api/admin/plugins/sirsoft-daum_postcode/changelog?source=active&from_version=%EC%98%88%EC%8B%9C%EA%B0%92&to_version=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| changelog | array | `[{"version":"1.0.1","date":"2026-07-22","categories":[{"n…` | 변경 이력 텍스트 (원격/파일 CHANGELOG 본문) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "플러그인 정보를 성공적으로 가져왔습니다.",
    "data": {
        "changelog": [
            {
                "version": "1.0.1",
                "date": "2026-07-22",
                "categories": [
                    {
                        "name": "Fixed",
                        "items": [
                            "오류 안내가 뜨기는 하지만 내용이 비어 있던 문제를 수정했습니다. 설정 저장에 실패하면 서버가 알려 준 사유가 그대로 표시됩니다.",
                            "설정 화면의 아이콘이 의도한 크기보다 크거나 작게 보이던 문제를 수정했습니다."
                        ]
                    }
                ]
            },
            {
                "version": "1.0.0",
                "date": "2026-07-01",
                "categories": [
                    {
                        "name": "Added",
                        "items": [
                            "레이아웃 편집기 데이터 소스 목록에서 이 확장이 제공하는 데이터 소스가 친화 명칭으로 표시되고, 어느 확장이 제공했는지 출처가 함께 표시됩니다."
                        ]
                    },
                    {
                        "name": "Changed",
                        "items": [
                            "플러그인 환경설정 화면의 하단 저장 버튼이 스크롤 중에도 화면에 고정되도록 개선."
                        ]
                    }
                ]
            },
            {
                "version": "1.0.0-beta.2",
                "date": "2026-04-20",
                "categories": [
                    {
                        "name": "Changed",
                        "items": [
                            "주소 검색 영역의 콘텐츠 카드 / 세로 정렬 컨테이너 외형을 sirsoft-admin_basic 표준 시맨틱과 정합 — 다른 화면과 같은 결로 통일.",
                            "코어 최소 요구 버전을 7.0.0-beta.2 로 상향",
                            "extension JSON: `extensionPointProps.onAddressSelect` → `extensionPointCallbacks.onAddressSelect` 참조 변경 (extension_point props/callbacks 분리)",
                            "플러그인 환경설정 화면의 하단 저장 버튼이 스크롤 중에도 화면에 고정되도록 개선.",
                            "플러그인 환경설정 화면의 폼 라벨 / 보조 설명 / 에러 메시지 시각 시맨틱을 sirsoft-admin_basic 표준 시맨틱과 정합 — 다른 관리자 화면과 같은 결로 통일.",
                            "플러그인 환경설정 화면 곳곳의 텍스트 톤 (보조 설명 · 라벨 · 본문 · 강조 · 작은 보조) 시각 시맨틱을 관리자 표준 시맨틱과 정합 — 같은 결의 글자 톤이 한 곳에서 일괄 조정 가능.",
                            "플러그인 환경설정 화면의 세로 정렬 컨테이너 / 입력 박스를 sirsoft-admin_basic 표준 시맨틱 (.stack / .input) 과 정합 — 표준 간격과 외형이 일관 표시되도록 정리."
                        ]
                    }
                ]
            },
            {
                "version": "1.0.0-beta.1",
                "date": "2026-04-01",
                "categories": [
                    {
                        "name": "Changed",
                        "items": [
                            "오픈 베타 릴리즈"
                        ]
                    }
                ]
            },
            {
                "version": "0.1.3",
                "date": "2026-03-16",
                "categories": [
                    {
                        "name": "Changed",
                        "items": [
                            "라이선스 프로그램 명칭 정비"
                        ]
                    }
                ]
            },
            {
                "version": "0.1.2",
                "date": "2026-03-13",
                "categories": [
                    {
                        "name": "Added",
                        "items": [
                            "manifest에 license 필드 및 LICENSE 파일 추가"
                        ]
                    },
                    {
                        "name": "Changed",
                        "items": [
                            "설정 레이아웃 경로를 `resources/layouts/settings.json` → `resources/layouts/admin/plugin_settings.json`으로 이동 (모듈과 동일한 구조 통일)"
                        ]
                    }
                ]
            },
            {
                "version": "0.1.1",
                "date": "2026-02-24",
                "categories": [
                    {
                        "name": "Changed",
                        "items": [
                            "버전 체계 조정 (정식 출시 전 0.x 체계로 변경)"
                        ]
                    }
                ]
            },
            {
                "version": "0.1.0",
                "date": "2026-02-23",
                "categories": [
                    {
                        "name": "Added",
                        "items": [
                            "Daum 우편번호 검색 플러그인 초기 구현",
                            "Daum 우편번호 서비스 API 연동 (API 키 불필요)",
                            "주소 검색 팝업/레이어 표시 모드 설정",
                            "커스텀 핸들러 (openPostcode, setFieldReadOnly)",
                            "이커머스 주소 검색 레이아웃 확장 (ecommerce-address-search)",
                            "플러그인 설정 UI (표시 모드, 테마 설정)",
                            "다국어 지원 (ko, en)"
                        ]
                    }
                ]
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
GET /api/admin/plugins/sirsoft-daum_postcode/dependent-templates HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-ckeditor5` | 플러그인 고유 식별자 (vendor-plugin 형식) |
| name | string | `CKEditor 5 WYSIWYG 에디터` | 플러그인 이름 (다국어 JSON) |
| version | string | `1.0.0` | 플러그인 버전 |
| type | string | `user` | 의존 템플릿의 타입 (admin: 관리자 템플릿 / user: 사용자 템플릿) |
| status | string | `active` | 상태 (active: 활성화, inactive: 비활성화, installing: 설치 중, uninstalling: 제거 중, updating: 업데이트 중) |
| required_version | string | `>=1.0.0` | 요구되는 최소 버전 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "의존 템플릿 정보를 성공적으로 조회했습니다.",
    "data": {
        "data": [
            {
                "identifier": "sirsoft-basic",
                "name": "Basic",
                "version": "1.0.4",
                "type": "user",
                "status": "active",
                "required_version": ">=1.0.0"
            }
        ],
        "total": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
GET /api/admin/plugins/sirsoft-daum_postcode/license HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| content | string | `프로그램 명칭 : 그누보드7용 Daum 우편번호 플러그인 (sirs…` | 본문 내용 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "플러그인 정보를 성공적으로 가져왔습니다.",
    "data": {
        "content": "프로그램 명칭 : 그누보드7용 Daum 우편번호 플러그인 (sirsoft-daum_postcode)\n\n저작자 : (주)에스아이알소프트\n\n----- MIT 라이선스 (한국어 번역) --------------------------------------------------------\n\nMIT 라이선스\n\nCopyright (c) 2026 (주)에스아이알소프트\n\n이 소프트웨어와 관련 문서 파일(이하 \"소프트웨어\")의 복사본을 취득하는 모든 사람에게\n소프트웨어를 제한 없이 사용, 복사, 수정, 병합, 출판, 배포, 서브라이선스 허여 및/또는\n판매할 수 있는 권리를 무상으로 부여합니다. 다만, 소프트웨어를 제공받은 사람은 다음\n조건을 따라야 합니다:\n\n위 저작권 고지와 본 허가 고지는 소프트웨어의 모든 복사본 또는 상당 부분에 포함되어야\n합니다.\n\n소프트웨어는 \"있는 그대로\" 제공되며, 명시적이든 묵시적이든 어떠한 종류의 보증도 하지\n않습니다. 여기에는 상품성, 특정 목적에의 적합성 및 비침해에 대한 보증이 포함되나 이에\n국한되지 않습니다. 어떠한 경우에도 저작자 또는 저작권자는 소프트웨어나 소프트웨어의\n사용 또는 기타 거래로 인해 발생하는 계약, 불법행위 또는 기타 청구, 손해 또는 기타\n책임에 대해 책임을 지지 않습니다.\n\n----- MIT License (English Original) --------------------------------------------------------\n\nThe MIT License (MIT)\n\nCopyright (c) 2026 SIRSOFT\n\nPermission is hereby granted, free of charge, to any person obtaining a copy\nof this software and associated documentation files (the \"Software\"), to deal\nin the Software without restriction, including without limitation the rights\nto use, copy, modify, merge, publish, distribute, sublicense, and/or sell\ncopies of the Software, and to permit persons to whom the Software is\nfurnished to do so, subject to the following conditions:\n\nThe above copyright notice and this permission notice shall be included in all\ncopies or substantial portions of the Software.\n\nTHE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR\nIMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,\nFITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE\nAUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER\nLIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,\nOUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE\nSOFTWARE.\n"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
GET /api/admin/plugins/sirsoft-daum_postcode/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| display_mode | string | `layer` | 표시 방식 (렌더/노출 모드 구분 값) |
| popup_width | integer | `500` | 팝업 창 너비 (px) |
| popup_height | integer | `600` | 팝업 창 높이 (px) |
| theme_color | string | `#1D4ED8` | 테마 대표 색상 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "display_mode": "layer",
        "popup_width": 500,
        "popup_height": 600,
        "theme_color": "#1D4ED8"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 플러그인의 현재 설정 값을 조회합니다. `core.plugins.read` 권한이 필요합니다. 저장된 설정이 없거나 플러그인을 찾을 수 없으면 404 를 반환합니다. 설정 페이지 진입 시 폼의 현재 값을 채우는 용도이며, 폼 스키마/UI 구성은 별도의 `settings/layout` 엔드포인트에서 조회합니다.

설정 스키마(getSettingsSchema)에 `public_asset_disk` 키를 선언한 플러그인(예: `sirsoft-ckeditor5`)은 응답 `data` 에 공개 자산 디스크 카탈로그 `available_public_asset_disks` 가 함께 부착됩니다 — 각 항목은 `{id, label(로케일별 맵), provider?}` 구조입니다. 설정 화면이 코어 환경설정 API(`core.settings.read`)를 따로 조회하지 않고 이 응답 하나로 선택지를 구성하게 하기 위한 것으로, 이 키는 저장 대상이 아니며 PUT 시 검증 whitelist 에서 걸러집니다.


### PUT /api/admin/plugins/{identifier}/settings
<!-- @generated:start:api.admin.plugins.settings.update -->
- **라우트명**: `api.admin.plugins.settings.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PluginSettingsController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PUT /api/admin/plugins/sirsoft-daum_postcode/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 는 저장 후 다시 조회한 설정 값 맵입니다. 필드 구성은 플러그인마다 다르며 각 플러그인의 설정 스키마(`GET /api/admin/plugins/{identifier}/settings/layout` 의 `schema`)를 따릅니다. 아래는 `sirsoft-daum_postcode` 예._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| display_mode | string | `layer` | 주소 검색창 표시 방식 (popup: 팝업 창 \| layer: 레이어) |
| popup_width | integer | `500` | 팝업 창 너비 (px) |
| popup_height | integer | `600` | 팝업 창 높이 (px) |
| theme_color | string | `#1D4ED8` | 검색창 테마 대표 색상 |

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인 설정이 성공적으로 업데이트되었습니다.",
    "data": {
        "display_mode": "layer",
        "popup_width": 500,
        "popup_height": 600,
        "theme_color": "#1D4ED8"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Server Error | 설정 저장 실패 (`plugins.settings.update_failed`) |

<!-- @generated:end -->

**설명** 플러그인의 설정 값을 저장합니다. `core.plugins.update` 권한이 필요합니다. 저장 대상은 검증을 통과한 값뿐이며, 검증 규칙은 해당 플러그인이 선언한 설정 스키마에서 생성됩니다. 스키마에 없는 필드를 요청에 포함해도 저장되지 않습니다. PluginManager 에 등록되지 않은 플러그인은 저장 자체가 수행되지 않아 500 을 반환합니다. 성공 시 갱신된 설정 값을 함께 반환합니다. 설정 스키마에 `public_asset_disk` 키를 선언한 플러그인은 저장 응답에도 `available_public_asset_disks` 카탈로그가 재부착됩니다 (GET 응답과 동형 — 저장 직후 화면 폼 상태 갱신용).


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
GET /api/admin/plugins/sirsoft-daum_postcode/settings/layout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| version | string | `1.0.0` | 플러그인 버전 |
| layout_name | string | `plugin_settings` | 레이아웃 식별자 (파일 경로 기반 — 예: board/popular) |
| permissions | array | `[]` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| extends | string | `_admin_base` | 상속하는 베이스 레이아웃 이름 (미상속 시 null) |
| meta | object | `{"title":"$t:sirsoft-daum_postcode.settings.title","descr…` | 메타 정보 객체 (title/description/seo 등) |
| data_sources | array | `[{"id":"settings","label_key":"$t:sirsoft-daum_postcode.e…` | API 데이터 소스 정의 배열 (id/endpoint/method) |
| slots | object | `{"content":[{"id":"plugin_settings_content","type":"basic…` | 슬롯별 삽입 콘텐츠 맵 (베이스 레이아웃의 slot 위치에 주입) |
| pageConfig | object | `{"notice":"$t:sirsoft-daum_postcode.settings.notice","gui…` | 페이지 단위 설정 객체 |
| schema | object | `{"display_mode":{"type":"enum","options":["popup","layer"…` | 스키마 정의 객체 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "version": "1.0.0",
        "layout_name": "plugin_settings",
        "permissions": [
            "core.plugins.update"
        ],
        "extends": "_admin_base",
        "meta": {
            "title": "$t:sirsoft-daum_postcode.settings.title",
            "description": "$t:sirsoft-daum_postcode.settings.description"
        },
        "...": "(4개 키 생략, 총 9개)"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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

_단건 응답: `data` 객체의 필드 (PluginResource::toDetailArray + 언어팩 주입)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer\|null | `3` | 기본 키 (내부 식별자, 미설치 시 null) |
| identifier | string | `sirsoft-daum_postcode` | 플러그인 고유 식별자 (vendor-plugin 형식) |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `Daum 우편번호` | 플러그인 이름 (현재 로케일로 해석) |
| version | string | `1.0.0` | 플러그인 버전 |
| description | string | `Daum 우편번호 서비스를 통한 주소 검색 기능을 …` | 플러그인 설명 (현재 로케일로 해석) |
| github_url | string\|null | `https://github.com/gnuboard/g7-plugin-daum_postcode` | GitHub 저장소 URL |
| requires_core | string\|null | `>=7.0.0-beta.2` | 요구되는 코어 최소 버전 (manifest `requires.g7_version`) |
| dependencies | array | `[]` | 의존하는 확장 목록 (manifest 파생) |
| status | string | `active` | 상태 (active/inactive/uninstalled/installing/uninstalling/updating) |
| is_installed | boolean | `true` | 설치 여부 |
| has_settings | boolean | `true` | 설정 UI 제공 여부 |
| settings_route | string\|null | `/admin/plugins/sirsoft-daum_postcode/settings` | 설정 페이지 경로 |
| permissions | array | `[]` | 연결된 권한 목록 |
| roles | array | `[]` | 플러그인이 정의한 역할 목록 |
| hooks | array | `[]` | 훅 설정 정보 |
| config | array | `[]` | 플러그인 설정 값 맵 |
| license | string\|null | `MIT` | 라이선스 식별자 (manifest `license`) |
| metadata | array | `[]` | manifest 부가 메타데이터 |
| update_available | boolean | `false` | 업데이트 가능 여부 |
| update_source | string\|null | `github` | 업데이트 감지 출처 (github \| bundled) |
| latest_version | string\|null | `1.0.0` | 감지된 최신 배포 버전 |
| file_version | string\|null | `1.0.0` | 설치된 파일의 manifest 버전 |
| github_changelog_url | string\|null | `https://github.com/gnuboard/g7-plugin-daum_postcode/releases` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소에 있어 설치 대기 중인지 여부 |
| is_bundled | boolean | `true` | 코어에 선탑재된 번들 확장인지 여부 |
| deactivated_reason | string\|null | `null` | 비활성화 사유 (manual \| incompatible_core \| null) |
| deactivated_at | string\|null | `null` | 비활성화 일시 |
| incompatible_required_version | string\|null | `null` | 코어 버전 미충족 시 필요한 버전 (호환되면 null) |
| created_at | string\|null | `2026-07-01T02:11:03.000000Z` | 설치(레코드 생성) 일시 |
| updated_at | string\|null | `2026-07-10T08:24:51.000000Z` | 최종 갱신 일시 |
| language_packs | array | `[]` | 이 플러그인이 지원하는 번들 언어팩 목록 (설치/설치가능 슬롯) |

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인 정보를 성공적으로 가져왔습니다.",
    "data": {
        "id": 3,
        "identifier": "sirsoft-daum_postcode",
        "vendor": "sirsoft",
        "name": "Daum 우편번호",
        "version": "1.0.0",
        "description": "Daum 우편번호 서비스를 통한 주소 검색 기능을 제공하는 플러그인입니다. API 키 없이 무료로 사용할 수 있습니다.",
        "github_url": null,
        "requires_core": ">=7.0.0-beta.2",
        "dependencies": [],
        "status": "active",
        "is_installed": true,
        "has_settings": true,
        "settings_route": "/admin/plugins/sirsoft-daum_postcode/settings",
        "permissions": [],
        "roles": [],
        "hooks": [],
        "config": [],
        "license": "MIT",
        "metadata": [],
        "update_available": false,
        "update_source": null,
        "latest_version": null,
        "file_version": "1.0.0",
        "github_changelog_url": null,
        "is_pending": false,
        "is_bundled": true,
        "deactivated_reason": null,
        "deactivated_at": null,
        "incompatible_required_version": null,
        "created_at": "2026-07-01T02:11:03.000000Z",
        "updated_at": "2026-07-10T08:24:51.000000Z",
        "language_packs": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | 해당 식별자의 플러그인이 존재하지 않는 경우 (`plugins.not_found`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Server Error | 조회 처리 중 예외 발생 (`plugins.fetch_failed`) |

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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| has_modified_layouts | boolean | `true` | 사용자가 수정한 레이아웃이 하나라도 있는지 여부 |
| modified_count | integer | `1` | 수정된 레이아웃 건수 |
| modified_layouts | array | `[{"id":12,"name":"plugin_settings", …}]` | 수정된 레이아웃 상세 배열 (아래 필드) |
| modified_layouts[].id | integer | `12` | 레이아웃 레코드 기본 키 |
| modified_layouts[].name | string | `plugin_settings` | 레이아웃 이름 (파일 경로 기반) |
| modified_layouts[].updated_at | string\|null | `2026-07-10 08:24:51` | 최종 수정 일시 (Y-m-d H:i:s) |
| modified_layouts[].size_diff | integer | `128` | 원본 대비 콘텐츠 크기 증감 (바이트, 음수 가능) |

**응답 예시**

```json
{
    "success": true,
    "message": "수정된 레이아웃 확인이 완료되었습니다.",
    "data": {
        "has_modified_layouts": true,
        "modified_count": 1,
        "modified_layouts": [
            {
                "id": 12,
                "name": "plugin_settings",
                "updated_at": "2026-07-10 08:24:51",
                "size_diff": 128
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 확인 처리 실패 (`plugin_name` 에 실패 사유 — `plugins.check_modified_layouts_failed`) |
| 500 | Server Error | 확인 처리 중 예외 발생 |

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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| target | object | `{"identifier":"sirsoft-daum_postcode","name":"Daum 우편번호","version":"1.0.0"}` | 설치 대상 플러그인 요약 (identifier/name/version) |
| dependencies | array | `[]` | 의존 확장 cascade 후보 목록 (아래 필드) |
| dependencies[].type | string | `module` | 의존 확장 유형 (module \| plugin) |
| dependencies[].identifier | string | `sirsoft-ecommerce` | 의존 확장 식별자 |
| dependencies[].name | string\|null | `이커머스` | 의존 확장 이름 |
| dependencies[].required_version | string\|null | `>=1.0.0` | 요구되는 최소 버전 |
| dependencies[].installed_version | string\|null | `null` | 현재 설치된 버전 (미설치 시 null) |
| dependencies[].is_installed | boolean | `false` | 설치 여부 |
| dependencies[].is_active | boolean | `false` | 활성화 여부 |
| dependencies[].is_met | boolean | `false` | 의존 조건 충족 여부 |
| dependencies[].available | boolean | `true` | cascade 선택 후보 여부 (미충족 항목만 true) |
| dependencies[].default_selected | boolean | `true` | 체크리스트 기본 선택 여부 (미충족 + 미설치) |
| language_packs | array | `[]` | 함께 설치 가능한 미설치 번들 언어팩 목록 (아래 필드) |
| language_packs[].bundled_identifier | string | `g7-plugin-daum_postcode-ja` | 번들 언어팩 식별자 |
| language_packs[].locale | string | `ja` | 로케일 코드 |
| language_packs[].locale_native_name | string | `日本語` | 로케일 원어 표기 |
| language_packs[].locale_name | string | `일본어` | 로케일 이름 (현재 로케일 표기) |
| language_packs[].version | string | `1.0.0` | 언어팩 버전 |
| language_packs[].depends_on_extension | string\|null | `null` | 이 언어팩이 귀속된 의존 확장 식별자 (본 확장 소유이면 null) |
| language_packs[].available | boolean | `true` | 함께 설치 가능한지 여부 |
| language_packs[].default_selected | boolean | `true` | 체크리스트 기본 선택 여부 |

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인 정보를 성공적으로 가져왔습니다.",
    "data": {
        "target": {
            "identifier": "sirsoft-daum_postcode",
            "name": "Daum 우편번호",
            "version": "1.0.0"
        },
        "dependencies": [],
        "language_packs": [
            {
                "bundled_identifier": "g7-plugin-daum_postcode-ja",
                "locale": "ja",
                "locale_native_name": "日本語",
                "locale_name": "일본어",
                "version": "1.0.0",
                "depends_on_extension": null,
                "available": true,
                "default_selected": true
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Server Error | 대상 확장을 찾을 수 없거나 프리뷰 빌드 중 예외 발생 (`plugins.fetch_failed`) |

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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| tables | array | `[{"name":"plugin_daum_logs","size_bytes":16384, …}]` | 삭제 대상 DB 테이블 목록 (마이그레이션 정적 테이블 + 동적 테이블) |
| tables[].name | string | `plugin_daum_logs` | 테이블명 (prefix 제외) |
| tables[].size_bytes | integer\|null | `16384` | 테이블 용량 (바이트). 용량 조회 미지원 DB 에서는 null |
| tables[].size_formatted | string | `16 KB` | 사람이 읽는 용량 표기 |
| storage_directories | array | `[]` | `storage/app/plugins/{identifier}` 하위 1-depth 디렉토리 목록 (name/size_bytes/size_formatted) |
| vendor_directory | object\|null | `{"items":[…],"total_size_bytes":1048576,"total_size_formatted":"1 MB"}` | Composer vendor/ 및 composer.lock 정보 (없으면 null) |
| extension_directory | object\|null | `{"path":"plugins/sirsoft-daum_postcode","size_bytes":524288,"size_formatted":"512 KB"}` | 확장 설치 디렉토리 경로와 용량 (없으면 null) |
| shared_records | array | `[{"table":"permissions","label_key":"permissions","count":3}]` | 코어 공유 테이블에 적재된 이 플러그인 소유 레코드 (permissions/menus/notification_definitions/identity_policies/identity_message_definitions, 0건 항목은 제외) |
| total_table_size_bytes | integer | `16384` | 삭제 대상 테이블 총 용량 (바이트) |
| total_table_size_formatted | string | `16 KB` | 테이블 총 용량 표기 |
| total_storage_size_bytes | integer | `0` | 스토리지 디렉토리 총 용량 (바이트) |
| total_storage_size_formatted | string | `0 B` | 스토리지 총 용량 표기 |

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인 삭제 정보를 성공적으로 조회했습니다.",
    "data": {
        "tables": [],
        "storage_directories": [],
        "vendor_directory": null,
        "extension_directory": {
            "path": "plugins/sirsoft-daum_postcode",
            "size_bytes": 524288,
            "size_formatted": "512 KB"
        },
        "shared_records": [
            {
                "table": "permissions",
                "label_key": "permissions",
                "count": 3
            }
        ],
        "total_table_size_bytes": 0,
        "total_table_size_formatted": "0 B",
        "total_storage_size_bytes": 0,
        "total_storage_size_formatted": "0 B"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | 해당 식별자의 플러그인이 존재하지 않는 경우 (`plugins.not_found`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Server Error | 삭제 정보 조회 중 예외 발생 (`plugins.uninstall_info_failed`) |

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
| rebuild_search_index | body | boolean | 아니오 | — | 업데이트 후 색인이 누락된 검색 인덱스를 재생성할지 여부 (기본 false). 재생성 중에는 대상 인덱스가 잠기거나 재색인되므로 운영 중인 사이트에서는 접속이 적은 시간에 별도로 수행하는 것을 권장 |

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
    "force": true,
    "rebuild_search_index": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 는 업데이트된 플러그인 리소스(PluginResource) 입니다. 플러그인 정보를 얻을 수 없는 예외 경로에서는 업데이트 결과 맵(success/from_version/to_version/message) 이 반환됩니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-daum_postcode` | 업데이트된 플러그인 고유 식별자 |
| version | string | `1.1.0` | 업데이트 후 버전 |
| file_version | string\|null | `1.1.0` | 설치된 파일의 manifest 버전 |
| status | string | `active` | 업데이트 후 상태 (업데이트 이전 상태로 복원) |
| update_available | boolean | `false` | 업데이트 후 재계산된 업데이트 가능 여부 |
| latest_version | string\|null | `1.1.0` | 감지된 최신 배포 버전 |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자의 수행 가능 작업 맵 |

나머지 필드는 목록(`GET /api/admin/plugins`) 응답 항목과 동일합니다 (PluginResource 단일 정의). 업데이트할 내용이 없고 `force` 도 없는 경우에는 `{"success": false, "from_version": …, "to_version": …, "message": "업데이트할 내용이 없습니다."}` 형태가 `data` 로 반환됩니다.

**응답 예시**

```json
{
    "success": true,
    "message": "플러그인 \"sirsoft-daum_postcode\"이(가) 1.1.0 버전으로 업데이트되었습니다.",
    "data": {
        "id": 3,
        "identifier": "sirsoft-daum_postcode",
        "vendor": "sirsoft",
        "name": "Daum 우편번호",
        "version": "1.1.0",
        "description": "Daum 우편번호 서비스를 통한 주소 검색 기능을 제공하는 플러그인입니다. API 키 없이 무료로 사용할 수 있습니다.",
        "dependencies": [],
        "permissions": [],
        "roles": [],
        "config": [],
        "hooks": [],
        "status": "active",
        "is_installed": true,
        "has_settings": true,
        "settings_route": "/admin/plugins/sirsoft-daum_postcode/settings",
        "assets": {
            "js": "/api/plugins/assets/sirsoft-daum_postcode/dist/js/plugin.iife.js",
            "css": null,
            "priority": 100
        },
        "update_available": false,
        "update_source": null,
        "latest_version": "1.1.0",
        "file_version": "1.1.0",
        "github_url": null,
        "github_changelog_url": null,
        "is_pending": false,
        "is_bundled": true,
        "deactivated_reason": null,
        "deactivated_at": null,
        "incompatible_required_version": null,
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
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 업데이트 실패 (`plugin_name` 에 번역된 사유 — 다운그레이드 차단, 강제 업데이트 소스 없음, 코어 버전 비호환, 진행 중 상태 등) |
| 500 | Server Error | 업데이트 처리 중 예외 발생 (`plugins.errors.update_failed`) |

<!-- @generated:end -->

**설명** 특정 플러그인을 최신 버전으로 업데이트합니다. `core.plugins.install` 권한이 필요합니다. `layout_strategy` 로 레이아웃 처리 방식을(overwrite: 새 버전으로 교체, keep: 사용자 수정본 유지) 지정하며, `vendor_mode` 로 Composer 의존성 처리 방식을 선택합니다. 버전 제약·호환성 문제로 막힐 경우 `force: true` 로 강제 진행할 수 있습니다. `keep` 을 지정하면 사용자가 수정한 레이아웃(원본 해시와 현재 내용이 다른 레이아웃)은 갱신 대상에서 제외되어 현재 내용이 그대로 유지되고, 나머지 레이아웃만 파일 기준으로 갱신됩니다. 성공 응답 메시지에는 대상 식별자와 적용된 버전이 채워집니다. `rebuild_search_index: true` 를 함께 보내면 업데이트 후 색인이 누락된 검색 인덱스를 재생성합니다 — 인덱스 잠금·재색인 비용이 있어 기본은 수행하지 않으며, 보내지 않아도 응답의 `search_index` 에 현재 누락 여부가 담깁니다.


### GET /api/plugins/assets/{identifier}
<!-- @generated:start:api.public.plugins.assets.extensionless -->
- **라우트명**: `api.public.plugins.assets.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicPluginController@serveAsset`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |
| path | query | string | 예 | — | 경로 |

**요청 예시**

```http
GET /api/plugins/assets/sirsoft-daum_postcode?identifier=example-key&path=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다 — `?file=` 쿼리로 받은 경로의 에셋
파일 원본 바이트를 그대로 서빙합니다 (성공 시 200 또는 304). 파일 경로 미전달
(`file` 쿼리 부재)은 422 로 응답합니다._

| 응답 헤더 | 예시값 | 용도/설명 |
| --- | --- | --- |
| Content-Type | `text/javascript` | 파일 확장자에서 감지한 MIME 타입 |
| ETag | `"a1b2c3d4…"` | 파일 내용 기반 검증자 (재요청 시 304 판정) |
| Cache-Control | `public, max-age=31536000` | 1년 캐시 (환경별로 달라짐 — 비프로덕션은 no-cache) |

**응답 예시**

```http
GET /api/plugins/assets/sirsoft-daum_postcode?file=dist%2Fjs%2Fplugin.iife.js HTTP/1.1
```

```http
HTTP/1.1 200
Content-Type: text/javascript
Cache-Control: public, max-age=31536000
ETag: "a1b2c3d4e5f6"

(function(){/* 플러그인 IIFE 번들 원본 바이트 */})();
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 플러그인 에셋 서빙(`GET /api/plugins/assets/{identifier}/{path}`)의 확장자 없는 이중 모드 변형입니다. 파일 경로를 경로 세그먼트 대신 `?file=` 쿼리로 받으며, 검증·에러·캐시 동작은 경로 세그먼트 형태와 동일합니다. `.js`·`.css` 주소를 가로채는 정적 파일 최적화 서버 설정에서 확장자 붙은 주소가 404 가 될 때 프론트가 이 형태로 자동 전환합니다 (자산 URL 이중 모드).


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
GET /api/plugins/assets/sirsoft-daum_postcode/{path}?identifier=example-key HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다 — 요청한 에셋 파일의 원본 바이트를 그대로 서빙합니다 (성공 시 200 또는 304)._

| 응답 헤더 | 예시값 | 용도/설명 |
| --- | --- | --- |
| Content-Type | `text/javascript` | 파일 확장자에서 감지한 MIME 타입 |
| ETag | `"a1b2c3d4…"` | 파일 내용 기반 검증자 (재요청 시 304 판정) |
| Cache-Control | `public, max-age=31536000` | 1년 캐시 (환경별로 달라짐 — 비프로덕션은 no-cache) |

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/javascript
Cache-Control: public, max-age=31536000
ETag: "a1b2c3d4e5f6"

(function(){/* 플러그인 IIFE 번들 원본 바이트 */})();
```

에러 시에는 JSON 봉투로 응답합니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 허용되지 않은 파일 형식 (`plugins.errors.file_type_not_allowed`) |
| 404 | Not Found | 플러그인이 없거나 비활성 (`plugins.errors.not_found`) 또는 파일 미존재 (`plugins.errors.file_not_found`) |
| 422 | Unprocessable Entity | 경로/확장자 보안 검증 실패 (ServePluginAssetRequest) |
| 500 | Server Error | 알 수 없는 오류 (`plugins.errors.unknown_error`) |

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

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다 — 활성 플러그인 CSS 를 병합한 텍스트를 그대로 서빙합니다 (성공 시 200 또는 304)._

| 응답 헤더 | 예시값 | 용도/설명 |
| --- | --- | --- |
| Content-Type | `text/css` | 항상 CSS |
| ETag | `"a1b2c3d4…"` | 병합 파일 내용 기반 검증자 (병합 파일이 있는 경우) |
| Cache-Control | `public, max-age=31536000` | 1년 캐시 (환경별로 달라짐 — 비프로덕션은 매 요청 재병합) |

활성 global 플러그인 에셋이 하나도 없으면 본문이 빈 200 응답(`text/css`) 입니다.

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/css
Cache-Control: public, max-age=31536000

/* sirsoft-gdpr */
.g7-gdpr-banner{position:fixed;bottom:0}
/* sirsoft-ckeditor5 */
.ck-editor__editable{min-height:240px}
```

**에러 응답**

_에러 응답 없음 — 공개 조회이며 요청 파라미터가 없고, 활성 에셋이 하나도 없는 경우에도 빈 200 을 반환합니다._

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

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다 — 활성 플러그인 IIFE JS 를 병합한 텍스트를 그대로 서빙합니다 (성공 시 200 또는 304)._

| 응답 헤더 | 예시값 | 용도/설명 |
| --- | --- | --- |
| Content-Type | `text/javascript` | 항상 JavaScript |
| ETag | `"a1b2c3d4…"` | 병합 파일 내용 기반 검증자 (병합 파일이 있는 경우) |
| Cache-Control | `public, max-age=31536000` | 1년 캐시 (환경별로 달라짐 — 비프로덕션은 매 요청 재병합) |

활성 global 플러그인 에셋이 하나도 없으면 본문이 빈 200 응답(`text/javascript`) 입니다. 병합 시 각 IIFE 사이는 `\n;\n` 구분자로 연결됩니다.

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/javascript
Cache-Control: public, max-age=31536000

(function(){/* sirsoft-gdpr plugin.iife.js */})();
;
(function(){/* sirsoft-ckeditor5 plugin.iife.js */})();
```

**에러 응답**

_에러 응답 없음 — 공개 조회이며 요청 파라미터가 없고, 활성 에셋이 하나도 없는 경우에도 빈 200 을 반환합니다._

<!-- @generated:end -->

**설명** 활성 플러그인들의 프론트엔드 IIFE JS 를 서버에서 하나로 병합한 번들을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않습니다. 활성 global 플러그인 에셋이 없으면 빈 200(text/javascript) 응답을 반환하고, 있으면 병합 파일을 ETag·환경별 Cache-Control 과 함께 서빙합니다. 프론트는 `G7Config.bundleUrls` 를 읽어 이 번들을 로드합니다.


### GET /api/plugins/bundle/css
<!-- @generated:start:api.public.plugins.bundle.css.extensionless -->
- **라우트명**: `api.public.plugins.bundle.css.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicPluginController@serveBundleCss`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/bundle/css HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다 — 활성 플러그인 CSS 를 병합한
텍스트를 그대로 서빙합니다 (성공 시 200 또는 304). `GET /api/plugins/bundle.css`
와 동일 응답입니다._

| 응답 헤더 | 예시값 | 용도/설명 |
| --- | --- | --- |
| Content-Type | `text/css` | 항상 CSS |
| ETag | `"a1b2c3d4…"` | 병합 파일 내용 기반 검증자 (병합 파일이 있는 경우) |
| Cache-Control | `public, max-age=31536000` | 1년 캐시 (환경별로 달라짐 — 비프로덕션은 매 요청 재병합) |

활성 global 플러그인 에셋이 하나도 없으면 본문이 빈 200 응답(`text/css`) 입니다.

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/css
Cache-Control: public, max-age=31536000

/* sirsoft-gdpr */
.g7-gdpr-banner{position:fixed;bottom:0}
```

**에러 응답**

_에러 응답 없음 — 공개 조회이며 요청 파라미터가 없고, 활성 에셋이 하나도 없는 경우에도 빈 200 을 반환합니다._

<!-- @generated:end -->

**설명** 플러그인 CSS 번들(`GET /api/plugins/bundle.css`)의 확장자 없는 이중 모드 변형입니다. 동작·응답이 확장자 형태와 동일하며, `.css` 주소를 가로채는 정적 파일 최적화 서버 설정에서 프론트가 이 형태로 자동 전환합니다 (자산 URL 이중 모드).


### GET /api/plugins/bundle/js
<!-- @generated:start:api.public.plugins.bundle.js.extensionless -->
- **라우트명**: `api.public.plugins.bundle.js.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicPluginController@serveBundleJs`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/bundle/js HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다 — 활성 플러그인 IIFE JS 를 병합한
텍스트를 그대로 서빙합니다 (성공 시 200 또는 304). `GET /api/plugins/bundle.js`
와 동일 응답입니다._

| 응답 헤더 | 예시값 | 용도/설명 |
| --- | --- | --- |
| Content-Type | `text/javascript` | 항상 JavaScript |
| ETag | `"a1b2c3d4…"` | 병합 파일 내용 기반 검증자 (병합 파일이 있는 경우) |
| Cache-Control | `public, max-age=31536000` | 1년 캐시 (환경별로 달라짐 — 비프로덕션은 매 요청 재병합) |

활성 global 플러그인 에셋이 하나도 없으면 본문이 빈 200 응답(`text/javascript`) 입니다. 병합 시 각 IIFE 사이는 `\n;\n` 구분자로 연결됩니다.

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/javascript
Cache-Control: public, max-age=31536000

(function(){/* sirsoft-gdpr plugin.iife.js */})();
;
(function(){/* sirsoft-ckeditor5 plugin.iife.js */})();
```

**에러 응답**

_에러 응답 없음 — 공개 조회이며 요청 파라미터가 없고, 활성 에셋이 하나도 없는 경우에도 빈 200 을 반환합니다._

<!-- @generated:end -->

**설명** 플러그인 JS 번들(`GET /api/plugins/bundle.js`)의 확장자 없는 이중 모드 변형입니다. 동작·응답이 확장자 형태와 동일하며, `.js` 주소를 가로채는 정적 파일 최적화 서버 설정에서 프론트가 이 형태로 자동 전환합니다 (자산 URL 이중 모드).


### GET /api/plugins/{identifier}/components
<!-- @generated:start:api.public.plugins.components.extensionless -->
- **라우트명**: `api.public.plugins.components.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicPluginController@serveComponents`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-daum_postcode/components HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `success/message/data` 봉투를 사용하지 않습니다 — 플러그인의
`components.json` 원문을 그대로 JSON body 로 반환합니다 (`Cache-Control: public,
max-age=3600`). 파일이 없는 구버전 플러그인은 빈 객체(`{}`) 로 폴백합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| $schema | string | `https://json-schema.org/draft/2020-12/schema` | 매니페스트 JSON 스키마 참조 |
| identifier | string | `sirsoft-daum_postcode` | 이 매니페스트를 소유한 플러그인 식별자 |
| version | string | `1.0.0` | 매니페스트(플러그인) 버전 |
| components | object | `{"basic":[],"composite":[],"layout":[]}` | 타입별 컴포넌트 정의 맵 |
| components.basic | array | `[]` | Basic 컴포넌트 정의 목록 (HTML 래핑 계층) |
| components.composite | array | `[]` | Composite 컴포넌트 정의 목록 (집합 컴포넌트) |
| components.layout | array | `[]` | Layout 컴포넌트 정의 목록 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "identifier": "sirsoft-daum_postcode",
    "version": "1.0.0",
    "components": {
        "basic": [],
        "composite": [],
        "layout": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 플러그인 컴포넌트 매니페스트(`GET /api/plugins/{identifier}/components.json`)의 확장자 없는 이중 모드 변형입니다. 응답·캐시·폴백 동작이 확장자 형태와 동일하며, `.json` 주소를 가로채는 정적 파일 최적화 서버 설정에서 프론트가 이 형태로 자동 전환합니다 (자산 URL 이중 모드).


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
GET /api/plugins/sirsoft-daum_postcode/components.json HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `success/message/data` 봉투를 사용하지 않습니다 — 플러그인의 `components.json` 원문을 그대로 JSON body 로 반환합니다 (`Cache-Control: public, max-age=3600`). 파일이 없는 구버전 플러그인은 빈 객체(`{}`) 로 폴백합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| $schema | string | `https://json-schema.org/draft/2020-12/schema` | 매니페스트 JSON 스키마 참조 |
| identifier | string | `sirsoft-ckeditor5` | 이 매니페스트를 소유한 플러그인 식별자 |
| version | string | `1.0.0-beta.4` | 매니페스트(플러그인) 버전 |
| components | object | `{"basic":[],"composite":[],"layout":[]}` | 타입별 컴포넌트 정의 맵 |
| components.basic | array | `[]` | Basic 컴포넌트 정의 목록 (HTML 래핑 계층) |
| components.composite | array | `[]` | Composite 컴포넌트 정의 목록 (집합 컴포넌트) |
| components.layout | array | `[]` | Layout 컴포넌트 정의 목록 |

**응답 예시**

```http
HTTP/1.1 200
Content-Type: application/json
Cache-Control: public, max-age=3600
```

```json
{
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "identifier": "sirsoft-ckeditor5",
    "version": "1.0.0-beta.4",
    "components": {
        "basic": [],
        "composite": [],
        "layout": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 해당 식별자의 플러그인이 없거나 비활성 상태인 경우 (`plugins.errors.not_found`) |

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
GET /api/plugins/sirsoft-daum_postcode/editor-spec HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-daum_postcode` | 플러그인 고유 식별자 (vendor-plugin 형식) |
| spec | null | `null` | 스펙 정의 객체 (편집기/컴포넌트 선언 스키마 등) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "편집기 스펙이 비어 있습니다.",
    "data": {
        "identifier": "sirsoft-daum_postcode",
        "spec": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 플러그인의 레이아웃 편집기 스펙(editor-spec.json)을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않습니다. 활성 플러그인만 대상으로 하며 활성 디렉토리 → `_bundled` 폴백 순으로 읽어 `data.spec` 형태로 반환합니다. 비활성·미존재 플러그인은 404 이고, 편집기 스펙 파일을 작성하지 않은 경우 spec=null 로 정상 응답합니다.


