# Modules API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Modules 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/modules
<!-- @generated:start:api.admin.modules.index -->
- **라우트명**: `api.admin.modules.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.read|core.menus.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search | query | string | 아니오 | max 255 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| filters | query | array | 아니오 | max 10 | 추가 필터 조건 맵 (필드별 조건) |
| status | query | string | 아니오 | `installed`, `not_installed`, `active`, `inactive` | 상태 필터 (해당 상태의 항목만 조회) |
| with | query | array | 아니오 | max 5 | 함께 포함할 추가 데이터 옵션 목록 (허용값 `custom_menus` — 각 모듈의 커스텀 메뉴 데이터 포함) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| include_hidden | query | boolean | 아니오 | — | manifest `hidden=true` 로 표시된 숨김 확장까지 목록에 포함할지 여부 (기본 제외) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.module.index_validation_rules`).

**요청 예시**

```http
GET /api/admin/modules?search=%EC%98%88%EC%8B%9C%EA%B0%92&filters=%EC%98%88%EC%8B%9C%EA%B0%92&status=installed&with=%EC%98%88%EC%8B%9C%EA%B0%92&per_page=1&page=1&include_hidden=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-board` | 모듈 고유 식별자 (vendor-module 형식) |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `게시판` | 모듈 이름 (다국어 JSON) |
| version | string | `1.0.3` | 모듈 버전 |
| description | string | `게시판 관리를 위한 모듈` | 모듈 설명 (다국어 JSON) |
| dependencies | array | `[]` | 의존하는 확장 맵 (manifest 파생 — {modules, plugins}) |
| status | string | `active` | 상태 (active: 활성화, inactive: 비활성화, installing: 설치 중, uninstalling: 제거 중, updating: 업데이트 중) |
| assets | object | `{"js":"\/api\/modules\/assets\/sirsoft-ecommerce?file=dis…` | 프론트엔드 에셋 매니페스트 (manifest 파생 — js/css 진입점·로딩 전략) |
| update_available | boolean | `false` | 최신 버전 대비 업데이트 가능 여부 |
| update_source | null | `null` | 업데이트 감지 출처 (github, bundled 등) |
| latest_version | string | `1.0.3` | 감지된 최신 배포 버전 |
| file_version | string | `1.0.3` | 설치된 파일의 manifest 버전 |
| github_url | string | `https://github.com/gnuboard/g7-module…` | GitHub 저장소 URL |
| github_changelog_url | string | `https://github.com/gnuboard/g7-module…` | GitHub 변경 내역 URL |
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
    "message": "모듈을 성공적으로 가져왔습니다.",
    "data": {
        "data": [
            {
                "identifier": "sirsoft-board",
                "vendor": "sirsoft",
                "name": "게시판",
                "version": "1.0.3",
                "description": "게시판 관리를 위한 모듈",
                "dependencies": [],
                "status": "active",
                "assets": null,
                "update_available": false,
                "update_source": null,
                "latest_version": "1.0.3",
                "file_version": "1.0.3",
                "github_url": "https://github.com/gnuboard/g7-module-sirsoft-board",
                "github_changelog_url": "https://github.com/gnuboard/g7-module-sirsoft-board/releases",
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
                "identifier": "sirsoft-ecommerce",
                "vendor": "sirsoft",
                "name": "이커머스",
                "version": "1.0.5",
                "description": "그누보드7 이커머스 모듈 - 상품, 주문, 결제 관리",
                "dependencies": [],
                "status": "active",
                "assets": {
                    "js": "/api/modules/assets/sirsoft-ecommerce?file=dist%2Fjs%2Fmodule.iife.js",
                    "css": "/api/modules/assets/sirsoft-ecommerce?file=dist%2Fcss%2Fmodule.css",
                    "priority": 100
                },
                "update_available": false,
                "update_source": null,
                "latest_version": "1.0.5",
                "file_version": "1.0.5",
                "github_url": "https://github.com/gnuboard/g7-module-sirsoft-ecommerce",
                "github_changelog_url": "https://github.com/gnuboard/g7-module-sirsoft-ecommerce/releases",
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
            "... (총 3건 중 2건 표시)"
        ],
        "pagination": {
            "total": 3,
            "current_page": 1,
            "last_page": 1,
            "per_page": 25
        },
        "meta": {
            "total_modules": 3,
            "active_modules": 0,
            "system_modules": 0,
            "user_modules": 3,
            "total_installs": 0,
            "average_rating": null,
            "latest_version": "1.0.5",
            "categories": [
                null
            ],
            "dependency_count": 0
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
| 403 | Forbidden | 요구 권한(`core.modules.read\|core.menus.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 설치된 모듈과 미설치 모듈을 모두 포함한 전체 모듈 목록을 페이지네이션으로 조회합니다. `search` 는 이름·식별자·설명·벤더에 대한 OR 검색이고 `filters` 는 AND 조건으로 적용되며, `with[]` 에 `custom_menus` 를 지정하면 커스텀 메뉴 데이터를 함께 포함합니다. `core.modules.read` 또는 `core.menus.read` 권한 중 하나가 필요하고, 응답의 `abilities` 는 현재 사용자의 수행 가능 작업 맵을 담습니다. 관리자 모듈 관리 화면의 목록 그리드를 구성하는 기본 엔드포인트입니다.


### POST /api/admin/modules/activate
<!-- @generated:start:api.admin.modules.activate -->
- **라우트명**: `api.admin.modules.activate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@activate`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.activate`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| module_name | body | string | 예 | max 255 | module 이름 (식별자) |
| force | body | boolean | 아니오 | — | 강제 실행 여부 (안전 확인/선행 검사 우회) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.module.activate_validation_rules`).

**요청 예시**

```http
POST /api/admin/modules/activate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "module_name": "예시 이름",
    "force": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`data.module` 은 목록과 동일한 `ModuleResource` 형태)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| module | object | `{"identifier":"sirsoft-board", ...}` | 활성화된 모듈 정보 (`ModuleResource` — 목록 응답 항목과 동일 필드 구성) |
| pending_language_packs | array | `[]` | 이전 비활성화 시 cascade 로 함께 비활성화됐던 번들 언어팩 목록 (재활성화 안내 모달용). 각 항목: `id`, `identifier`, `locale`, `locale_native_name`. 없으면 빈 배열 |

**응답 예시**

```json
{
    "success": true,
    "message": "모듈이 성공적으로 활성화되었습니다.",
    "data": {
        "module": {
            "identifier": "sirsoft-board",
            "vendor": "sirsoft",
            "name": "게시판",
            "version": "1.0.0",
            "description": "게시판 관리를 위한 모듈",
            "dependencies": [],
            "status": "active",
            "assets": null,
            "update_available": false,
            "update_source": null,
            "latest_version": null,
            "file_version": "1.0.0",
            "github_url": "https://github.com/gnuboard/g7-module-sirsoft-board",
            "github_changelog_url": "https://github.com/gnuboard/g7-module-sirsoft-board/releases",
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
        "pending_language_packs": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.activate`)이 없는 경우 |
| 409 | Conflict | `force` 없이 호출했고 필요한 의존 확장이 미충족인 경우 (`error` 에 `warning`, `missing_modules`, `missing_plugins` 포함) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 활성화 처리 중 예외 발생 (`module.activate_failed`) |

<!-- @generated:end -->

**설명** 설치된 모듈을 활성화합니다. `core.modules.activate` 권한이 필요합니다. `force` 없이 호출했을 때 필요한 의존 확장이 충족되지 않으면 409 응답으로 `missing_modules`·`missing_plugins` 목록과 함께 경고를 반환하므로, 사용자 확인 후 `force: true` 로 재요청해야 합니다. 재활성화 시 cascade 로 함께 비활성화됐던 번들 언어팩 목록이 `pending_language_packs` 로 응답에 포함됩니다.


### POST /api/admin/modules/check-updates
<!-- @generated:start:api.admin.modules.check-updates -->
- **라우트명**: `api.admin.modules.check-updates`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@checkUpdates`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.install`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/modules/check-updates HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `1` | 업데이트가 가능한 것으로 감지된 모듈 수 |
| details | array | `[{"identifier":"sirsoft-board", ...}]` | 업데이트 가능한 모듈만 담은 목록 (아래 하위 필드) |
| details[].identifier | string | `sirsoft-board` | 모듈 식별자 |
| details[].current_version | string | `1.0.0` | 현재 설치된 버전 |
| details[].latest_version | string | `1.0.1` | 감지된 최신 버전 |
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
                "identifier": "sirsoft-board",
                "current_version": "1.0.0",
                "latest_version": "1.0.1",
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
| 403 | Forbidden | 요구 권한(`core.modules.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 업데이트 확인 처리가 실패한 경우 (`modules.check_updates_failed`) |
| 500 | Internal Server Error | 업데이트 확인 중 예외 발생 |

<!-- @generated:end -->

**설명** 설치된 모든 모듈에 대해 GitHub·번들 소스를 조회하여 새 버전 배포 여부를 일괄 확인합니다. `core.modules.install` 권한이 필요합니다. 파라미터 없이 호출하며, 각 모듈의 업데이트 가능 여부와 감지된 최신 버전 정보를 반환합니다. 모듈 목록 화면 진입 시 업데이트 뱃지를 갱신하는 용도로 사용됩니다.


### POST /api/admin/modules/deactivate
<!-- @generated:start:api.admin.modules.deactivate -->
- **라우트명**: `api.admin.modules.deactivate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@deactivate`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.activate`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| module_name | body | string | 예 | max 255 | module 이름 (식별자) |
| force | body | boolean | 아니오 | — | 강제 실행 여부 (안전 확인/선행 검사 우회) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.module.deactivate_validation_rules`).

**요청 예시**

```http
POST /api/admin/modules/deactivate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "module_name": "예시 이름",
    "force": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 는 비활성화된 모듈의 `ModuleResource` 객체 (목록 응답 항목과 동일 필드 구성)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-board` | 모듈 고유 식별자 (vendor-module 형식) |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `게시판` | 모듈 이름 (현재 로케일로 해석) |
| version | string | `1.0.0` | 모듈 버전 |
| description | string | `게시판 관리를 위한 모듈` | 모듈 설명 (현재 로케일로 해석) |
| dependencies | array | `[]` | 의존 확장 목록 (manifest 파생) |
| status | string | `inactive` | 비활성화 후 상태 |
| assets | object\|null | `null` | 프론트엔드 에셋 매니페스트 (js/css/priority) |
| update_available | boolean | `false` | 최신 버전 대비 업데이트 가능 여부 |
| update_source | string\|null | `null` | 업데이트 감지 출처 |
| latest_version | string\|null | `null` | 감지된 최신 배포 버전 |
| file_version | string | `1.0.0` | 설치된 파일의 manifest 버전 |
| github_url | string\|null | `https://github.com/gnuboard/g7-module-sirsoft-board` | GitHub 저장소 URL |
| github_changelog_url | string\|null | `https://github.com/gnuboard/g7-module-sirsoft-board/releases` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소 항목 여부 |
| is_bundled | boolean | `false` | 코어 선탑재 번들 확장 여부 |
| deactivated_reason | string\|null | `manual` | 비활성화 사유 (`manual` \| `incompatible_core`) |
| deactivated_at | string\|null | `2026-07-14 10:00:00` | 비활성화 일시 |
| incompatible_required_version | string\|null | `null` | 요구 코어 버전 미충족 시 필요한 버전 |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자가 수행 가능한 작업 불리언 맵 |

**응답 예시**

```json
{
    "success": true,
    "message": "모듈이 성공적으로 비활성화되었습니다.",
    "data": {
        "identifier": "sirsoft-board",
        "vendor": "sirsoft",
        "name": "게시판",
        "version": "1.0.0",
        "description": "게시판 관리를 위한 모듈",
        "dependencies": [],
        "status": "inactive",
        "assets": null,
        "update_available": false,
        "update_source": null,
        "latest_version": null,
        "file_version": "1.0.0",
        "github_url": "https://github.com/gnuboard/g7-module-sirsoft-board",
        "github_changelog_url": "https://github.com/gnuboard/g7-module-sirsoft-board/releases",
        "is_pending": false,
        "is_bundled": false,
        "deactivated_reason": "manual",
        "deactivated_at": "2026-07-14 10:00:00",
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
| 403 | Forbidden | 요구 권한(`core.modules.activate`)이 없는 경우 |
| 409 | Conflict | `force` 없이 호출했고 이 모듈에 의존하는 활성 확장이 있는 경우 (`error` 에 `warning`, `dependent_templates`, `dependent_modules`, `dependent_plugins` 포함) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 비활성화 처리 중 예외 발생 (`module.deactivate_failed`) |

<!-- @generated:end -->

**설명** 활성 모듈을 비활성화합니다. `core.modules.activate` 권한이 필요합니다. `force` 없이 호출했을 때 이 모듈에 의존하는 템플릿·모듈·플러그인이 있으면 409 응답으로 `dependent_templates`·`dependent_modules`·`dependent_plugins` 목록과 함께 경고를 반환합니다. 의존 관계 확인 후 `force: true` 로 강제 비활성화할 수 있습니다.


### POST /api/admin/modules/install
<!-- @generated:start:api.admin.modules.install -->
- **라우트명**: `api.admin.modules.install`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@install`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| module_name | body | string | 예 | max 255 | module 이름 (식별자) |
| vendor_mode | body | string | 아니오 | `auto`, `composer`, `bundled` | 벤더 설치 모드 (auto/composer/bundled) |
| dependencies | body | array | 아니오 | — | 함께 설치할 의존 확장 목록 (cascade 1단계). 각 원소는 `type`(module\|plugin)·`identifier` 로 구성하며, install-preview 응답에서 사용자가 선택한 항목 |
| language_packs | body | array | 아니오 | — | 함께 설치할 번들 언어팩 식별자 목록 (cascade 2단계, best-effort). 원소는 언어팩 식별자 문자열 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.module.install_validation_rules`).

**요청 예시**

```http
POST /api/admin/modules/install HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "module_name": "예시 이름",
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

_단건 응답: `data` 는 설치된 모듈의 `ModuleResource` 필드 + `language_pack_failures` (HTTP 201)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-board` | 설치된 모듈 식별자 |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `게시판` | 모듈 이름 (현재 로케일로 해석) |
| version | string | `1.0.0` | 설치된 버전 |
| description | string | `게시판 관리를 위한 모듈` | 모듈 설명 |
| dependencies | array | `[]` | 의존 확장 목록 (enriched — identifier/name/type/required_version/is_met 등) |
| status | string | `inactive` | 설치 직후 상태 (설치만으로 활성화되지 않음) |
| assets | object\|null | `null` | 프론트엔드 에셋 매니페스트 (js/css/priority) |
| update_available | boolean | `false` | 업데이트 가능 여부 (설치 직후 기본 false) |
| update_source | string\|null | `null` | 업데이트 감지 출처 |
| latest_version | string\|null | `null` | 감지된 최신 배포 버전 |
| file_version | string\|null | `null` | 설치된 파일 manifest 버전 |
| github_url | string\|null | `https://github.com/gnuboard/g7-module-sirsoft-board` | GitHub 저장소 URL |
| github_changelog_url | string\|null | `null` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소 항목 여부 |
| is_bundled | boolean | `false` | 코어 선탑재 번들 확장 여부 |
| deactivated_reason | string\|null | `null` | 비활성화 사유 |
| deactivated_at | string\|null | `null` | 비활성화 일시 |
| incompatible_required_version | string\|null | `null` | 요구 코어 버전 미충족 시 필요한 버전 |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자가 수행 가능한 작업 불리언 맵 |
| language_pack_failures | array | `[]` | cascade 2단계(번들 언어팩 best-effort 설치)에서 실패한 항목. 각 항목: `identifier`, `reason`. 실패 없으면 빈 배열 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "모듈이 성공적으로 설치되었습니다.",
    "data": {
        "identifier": "sirsoft-board",
        "vendor": "sirsoft",
        "name": "게시판",
        "version": "1.0.0",
        "description": "게시판 관리를 위한 모듈",
        "dependencies": [],
        "status": "inactive",
        "assets": null,
        "update_available": false,
        "update_source": null,
        "latest_version": null,
        "file_version": null,
        "github_url": "https://github.com/gnuboard/g7-module-sirsoft-board",
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
        },
        "language_pack_failures": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패, 또는 설치 파이프라인이 던진 검증 오류 (의존 확장 cascade 설치 실패·이미 설치됨 등 — `error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 설치 처리 중 예외 발생 (`modules.installation_failed`) |

<!-- @generated:end -->

**설명** `_pending`·`_bundled` 대기소에 있는 모듈을 활성 디렉토리로 설치합니다. `core.modules.install` 권한이 필요합니다. `vendor_mode` 로 Composer 의존성 설치 방식을(auto/composer/bundled) 지정하며, 요청 본문의 `dependencies` 로 선택한 의존 확장을 먼저 설치(cascade 1단계, 실패 시 전체 중단)한 뒤 `language_packs` 로 지정한 번들 언어팩을 best-effort 로 함께 설치합니다(cascade 2단계). 성공 시 201 상태로 반환하고, 언어팩 설치 실패는 응답의 `language_pack_failures` 에 담깁니다.


### POST /api/admin/modules/install-from-file
<!-- @generated:start:api.admin.modules.install-from-file -->
- **라우트명**: `api.admin.modules.install-from-file`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@installFromFile`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 51200 | 업로드 파일 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.module.install_from_file_validation_rules`).

**요청 예시**

```http
POST /api/admin/modules/install-from-file HTTP/1.1
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

_단건 응답: `data` 는 설치된 모듈의 `ModuleResource` 객체 (HTTP 201 — 목록 응답 항목과 동일 필드 구성)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-board` | 설치된 모듈 식별자 |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `게시판` | 모듈 이름 (현재 로케일로 해석) |
| version | string | `1.0.0` | 설치된 버전 |
| description | string | `게시판 관리를 위한 모듈` | 모듈 설명 |
| dependencies | array | `[]` | 의존 확장 목록 (enriched) |
| status | string | `inactive` | 설치 직후 상태 |
| assets | object\|null | `null` | 프론트엔드 에셋 매니페스트 |
| update_available | boolean | `false` | 업데이트 가능 여부 |
| update_source | string\|null | `null` | 업데이트 감지 출처 |
| latest_version | string\|null | `null` | 감지된 최신 배포 버전 |
| file_version | string\|null | `null` | 설치된 파일 manifest 버전 |
| github_url | string\|null | `https://github.com/gnuboard/g7-module-sirsoft-board` | GitHub 저장소 URL |
| github_changelog_url | string\|null | `null` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소 항목 여부 |
| is_bundled | boolean | `false` | 코어 선탑재 번들 확장 여부 |
| deactivated_reason | string\|null | `null` | 비활성화 사유 |
| deactivated_at | string\|null | `null` | 비활성화 일시 |
| incompatible_required_version | string\|null | `null` | 요구 코어 버전 미충족 시 필요한 버전 |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자가 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "모듈이 성공적으로 설치되었습니다.",
    "data": {
        "identifier": "sirsoft-board",
        "vendor": "sirsoft",
        "name": "게시판",
        "version": "1.0.0",
        "description": "게시판 관리를 위한 모듈",
        "dependencies": [],
        "status": "inactive",
        "assets": null,
        "update_available": false,
        "update_source": null,
        "latest_version": null,
        "file_version": null,
        "github_url": "https://github.com/gnuboard/g7-module-sirsoft-board",
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
| 403 | Forbidden | 요구 권한(`core.modules.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 파일 검증 실패(ZIP 아님·50MB 초과), 또는 ZIP 처리 오류 (module.json 미존재/형식 오류·식별자 누락·이미 설치됨) |
| 500 | Internal Server Error | 설치 처리 중 예외 발생 (`module.install_failed`) |

<!-- @generated:end -->

**설명** 업로드된 ZIP 파일에서 모듈을 설치합니다. `core.modules.install` 권한이 필요하며, 파일은 최대 50MB(51200KB)까지 허용됩니다. ZIP 압축 해제 후 module.json 검증을 거쳐 설치하며, 성공 시 201 상태로 설치된 모듈 정보를 반환합니다. 설치 전 manifest 만 미리 확인하려면 `manifest-preview` 를 먼저 호출하는 것이 안전합니다.


### POST /api/admin/modules/install-from-github
<!-- @generated:start:api.admin.modules.install-from-github -->
- **라우트명**: `api.admin.modules.install-from-github`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@installFromGithub`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| github_url | body | string | 예 | — | GitHub 저장소 URL |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.module.install_from_github_validation_rules`).

**요청 예시**

```http
POST /api/admin/modules/install-from-github HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "github_url": "https://example.com"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 는 설치된 모듈의 `ModuleResource` 객체 (HTTP 201 — 목록 응답 항목과 동일 필드 구성)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-board` | 설치된 모듈 식별자 |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `게시판` | 모듈 이름 (현재 로케일로 해석) |
| version | string | `1.0.0` | 설치된 버전 |
| description | string | `게시판 관리를 위한 모듈` | 모듈 설명 |
| dependencies | array | `[]` | 의존 확장 목록 (enriched) |
| status | string | `inactive` | 설치 직후 상태 |
| assets | object\|null | `null` | 프론트엔드 에셋 매니페스트 |
| update_available | boolean | `false` | 업데이트 가능 여부 |
| update_source | string\|null | `null` | 업데이트 감지 출처 |
| latest_version | string\|null | `null` | 감지된 최신 배포 버전 |
| file_version | string\|null | `null` | 설치된 파일 manifest 버전 |
| github_url | string\|null | `https://github.com/gnuboard/g7-module-sirsoft-board` | GitHub 저장소 URL |
| github_changelog_url | string\|null | `null` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소 항목 여부 |
| is_bundled | boolean | `false` | 코어 선탑재 번들 확장 여부 |
| deactivated_reason | string\|null | `null` | 비활성화 사유 |
| deactivated_at | string\|null | `null` | 비활성화 일시 |
| incompatible_required_version | string\|null | `null` | 요구 코어 버전 미충족 시 필요한 버전 |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자가 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "모듈이 성공적으로 설치되었습니다.",
    "data": {
        "identifier": "sirsoft-board",
        "vendor": "sirsoft",
        "name": "게시판",
        "version": "1.0.0",
        "description": "게시판 관리를 위한 모듈",
        "dependencies": [],
        "status": "inactive",
        "assets": null,
        "update_available": false,
        "update_source": null,
        "latest_version": null,
        "file_version": null,
        "github_url": "https://github.com/gnuboard/g7-module-sirsoft-board",
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
| 403 | Forbidden | 요구 권한(`core.modules.install`)이 없는 경우 |
| 422 | Unprocessable Entity | URL 형식 검증 실패, 또는 GitHub 처리 오류 (저장소 미존재·다운로드 실패·module.json 형식 오류·이미 설치됨) |
| 500 | Internal Server Error | 설치 처리 중 예외 발생 (`module.install_failed`) |

<!-- @generated:end -->

**설명** GitHub 저장소 URL 에서 모듈을 내려받아 설치합니다. `core.modules.install` 권한이 필요합니다. `github_url` 로 지정한 공개 저장소의 릴리스/소스를 받아 압축 해제·검증 후 설치하며, 성공 시 201 상태로 설치된 모듈 정보를 반환합니다.


### GET /api/admin/modules/installed
<!-- @generated:start:api.admin.modules.installed -->
- **라우트명**: `api.admin.modules.installed`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@installed`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/modules/installed HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `gnuboard7-hello_module` | 모듈 고유 식별자 (vendor-module 형식) |
| vendor | string | `gnuboard7` | 벤더/개발자명 |
| name | string | `Hello 모듈` | 모듈 이름 (다국어 JSON) |
| version | string | `0.1.1` | 모듈 버전 |
| description | string | `학습용 최소 샘플 모듈 (Memo CRUD)` | 모듈 설명 (다국어 JSON) |
| dependencies | array | `[]` | 의존하는 확장 맵 (manifest 파생 — {modules, plugins}) |
| status | string | `active` | 상태 (active: 활성화, inactive: 비활성화, installing: 설치 중, uninstalling: 제거 중, updating: 업데이트 중) |
| assets | object | `{"js":"\/api\/modules\/assets\/sirsoft-ecommerce?file=dis…` | 프론트엔드 에셋 매니페스트 (manifest 파생 — js/css 진입점·로딩 전략) |
| update_available | boolean | `false` | 최신 버전 대비 업데이트 가능 여부 |
| update_source | null | `null` | 업데이트 감지 출처 (github, bundled 등) |
| latest_version | string | `0.1.1` | 감지된 최신 배포 버전 |
| file_version | string | `0.1.1` | 설치된 파일의 manifest 버전 |
| github_url | string | `https://github.com/gnuboard/g7-module…` | GitHub 저장소 URL |
| github_changelog_url | string | `https://github.com/gnuboard/g7-module…` | GitHub 변경 내역 URL |
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
    "message": "모듈을 성공적으로 가져왔습니다.",
    "data": {
        "data": [
            {
                "identifier": "gnuboard7-hello_module",
                "vendor": "gnuboard7",
                "name": "Hello 모듈",
                "version": "0.1.1",
                "description": "학습용 최소 샘플 모듈 (Memo CRUD)",
                "dependencies": [],
                "status": "active",
                "assets": null,
                "update_available": false,
                "update_source": null,
                "latest_version": "0.1.1",
                "file_version": "0.1.1",
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
                "identifier": "sirsoft-board",
                "vendor": "sirsoft",
                "name": "게시판",
                "version": "1.0.3",
                "description": "게시판 관리를 위한 모듈",
                "dependencies": [],
                "status": "active",
                "assets": null,
                "update_available": false,
                "update_source": null,
                "latest_version": "1.0.3",
                "file_version": "1.0.3",
                "github_url": "https://github.com/gnuboard/g7-module-sirsoft-board",
                "github_changelog_url": "https://github.com/gnuboard/g7-module-sirsoft-board/releases",
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
            "... (총 4건 중 2건 표시)"
        ],
        "meta": {
            "total_modules": 4,
            "active_modules": 0,
            "system_modules": 0,
            "user_modules": 4,
            "total_installs": 0,
            "average_rating": null,
            "latest_version": "1.0.5",
            "categories": [
                null
            ],
            "dependency_count": 0
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.read \| core.menus.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 현재 설치된 모듈만 조회합니다(미설치 항목 제외). 이 엔드포인트는 세부 권한 미들웨어 없이 `auth:sanctum` 인증만 요구하므로, 다른 화면이 활성/설치된 모듈 목록을 참조할 때 사용하는 경량 조회 API 입니다. 페이지네이션 없이 설치된 항목 배열을 반환합니다.


### POST /api/admin/modules/manifest-preview
<!-- @generated:start:api.admin.modules.manifest-preview -->
- **라우트명**: `api.admin.modules.manifest-preview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@manifestPreview`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 51200 | 업로드 파일 |

**요청 예시**

```http
POST /api/admin/modules/manifest-preview HTTP/1.1
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
| manifest | object\|null | `{"identifier":"sirsoft-board","version":"1.0.0", ...}` | ZIP 에서 추출한 module.json 원본 내용. 추출 실패 시 `null` |
| validation | object | `{"errors":[],"is_valid":true, ...}` | 검증 결과 묶음 (아래 하위 필드) |
| validation.errors | array | `[]` | 추출/검증 과정에서 발생한 오류 메시지 목록 (없으면 빈 배열) |
| validation.is_valid | boolean | `true` | 오류가 없고 manifest 추출에 성공했는지 여부 |
| validation.already_installed | boolean | `false` | 해당 식별자의 모듈이 이미 설치되어 있는지 여부 |
| validation.existing_version | string\|null | `1.0.0` | 이미 설치된 경우 그 버전 (미설치 시 `null`) |

**응답 예시**

```json
{
    "success": true,
    "message": "manifest 미리보기를 완료했습니다.",
    "data": {
        "manifest": {
            "identifier": "sirsoft-board",
            "vendor": "sirsoft",
            "name": "게시판",
            "version": "1.0.0",
            "g7_version": "7.0.0"
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
| 403 | Forbidden | 요구 권한(`core.modules.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 파일 검증 실패(ZIP 아님·50MB 초과), 또는 미리보기 처리 실패 (`module.preview_failed` — `error.error` 에 사유) |

<!-- @generated:end -->

**설명** 업로드된 ZIP 파일의 module.json manifest 와 검증 결과만 추출합니다(실제 설치는 수행하지 않음). `core.modules.install` 권한이 필요하며 파일은 최대 50MB 까지 허용됩니다. 설치 모달에서 사용자가 파일 선택 직후 manifest 유효성과 검증 실패 사유를 미리 확인하는 용도이며, 검증 오류 시 422 로 사유를 반환합니다.


### POST /api/admin/modules/refresh-layouts
<!-- @generated:start:api.admin.modules.refresh-layouts -->
- **라우트명**: `api.admin.modules.refresh-layouts`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@refreshLayouts`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.activate`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| module_name | body | string | 예 | max 255 | module 이름 (식별자) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.module.refresh_layouts_validation_rules`).

**요청 예시**

```http
POST /api/admin/modules/refresh-layouts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "module_name": "예시 이름"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 는 갱신된 모듈의 `ModuleResource` 객체 (목록 응답 항목과 동일 필드 구성)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-board` | 모듈 식별자 |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `게시판` | 모듈 이름 (현재 로케일로 해석) |
| version | string | `1.0.0` | 모듈 버전 |
| description | string | `게시판 관리를 위한 모듈` | 모듈 설명 |
| dependencies | array | `[]` | 의존 확장 목록 (enriched) |
| status | string | `active` | 모듈 상태 (레이아웃 갱신은 활성 모듈만 가능) |
| assets | object\|null | `null` | 프론트엔드 에셋 매니페스트 |
| update_available | boolean | `false` | 업데이트 가능 여부 |
| update_source | string\|null | `null` | 업데이트 감지 출처 |
| latest_version | string\|null | `null` | 감지된 최신 배포 버전 |
| file_version | string\|null | `null` | 설치된 파일 manifest 버전 |
| github_url | string\|null | `https://github.com/gnuboard/g7-module-sirsoft-board` | GitHub 저장소 URL |
| github_changelog_url | string\|null | `null` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소 항목 여부 |
| is_bundled | boolean | `false` | 코어 선탑재 번들 확장 여부 |
| deactivated_reason | string\|null | `null` | 비활성화 사유 |
| deactivated_at | string\|null | `null` | 비활성화 일시 |
| incompatible_required_version | string\|null | `null` | 요구 코어 버전 미충족 시 필요한 버전 |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자가 수행 가능한 작업 불리언 맵 |

**응답 예시**

```json
{
    "success": true,
    "message": "모듈 레이아웃이 성공적으로 갱신되었습니다.",
    "data": {
        "identifier": "sirsoft-board",
        "vendor": "sirsoft",
        "name": "게시판",
        "version": "1.0.0",
        "description": "게시판 관리를 위한 모듈",
        "dependencies": [],
        "status": "active",
        "assets": null,
        "update_available": false,
        "update_source": null,
        "latest_version": null,
        "file_version": null,
        "github_url": "https://github.com/gnuboard/g7-module-sirsoft-board",
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
| 403 | Forbidden | 요구 권한(`core.modules.activate`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패, 또는 레이아웃 갱신 실패 (모듈 미존재·비활성 상태 — `modules.refresh_layouts_failed`) |
| 500 | Internal Server Error | 갱신 처리 중 예외 발생 (`module.refresh_layouts_failed`) |

<!-- @generated:end -->

**설명** 모듈의 레이아웃 파일을 파일에서 다시 읽어 DB 에 동기화합니다. `core.modules.activate` 권한이 필요합니다. 파일에서 변경된 레이아웃은 갱신되고 삭제된 레이아웃은 DB 에서도 제거되며, 갱신된 모듈 정보를 반환합니다. 모듈의 `_bundled` 레이아웃 JSON 을 수정한 뒤 재빌드 없이 반영할 때 사용합니다.


### DELETE /api/admin/modules/uninstall
<!-- @generated:start:api.admin.modules.uninstall -->
- **라우트명**: `api.admin.modules.uninstall`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@uninstall`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.uninstall`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| module_name | query | string | 예 | max 255 | module 이름 (식별자) |
| delete_data | query | boolean | 아니오 | — | 제거 시 모듈이 생성한 DB 데이터까지 함께 삭제할지 여부 (기본 false — 데이터 보존) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.module.uninstall_validation_rules`).

**요청 예시**

```http
DELETE /api/admin/modules/uninstall?module_name=%EC%98%88%EC%8B%9C%20%EC%9D%B4%EB%A6%84&delete_data=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만). 컨트롤러가 `success('module.uninstall_success')` 를 데이터 인자 없이 호출합니다._

**응답 예시**

```json
{
    "success": true,
    "message": "모듈이 성공적으로 제거되었습니다."
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.uninstall`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 제거 처리 중 예외 발생 (`module.uninstall_failed`) |

<!-- @generated:end -->

**설명** 모듈을 시스템에서 제거합니다. `core.modules.uninstall` 권한이 필요합니다. 활성 디렉토리만 삭제하고 `_bundled` 원본은 보존합니다. `delete_data: true` 인 경우 모듈이 생성한 DB 데이터까지 함께 삭제하며, 기본값은 데이터 보존입니다. 삭제될 데이터 범위는 사전에 `uninstall-info` 로 확인할 수 있습니다.


### GET /api/admin/modules/uninstalled
<!-- @generated:start:api.admin.modules.uninstalled -->
- **라우트명**: `api.admin.modules.uninstalled`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@uninstalled`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/modules/uninstalled HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `gnuboard7-hello_module` | 모듈 고유 식별자 (vendor-module 형식) |
| vendor | string | `gnuboard7` | 벤더/개발자명 |
| name | string | `Hello 모듈` | 모듈 이름 (다국어 JSON) |
| version | string | `0.1.0` | 모듈 버전 |
| description | string | `학습용 최소 샘플 모듈 (Memo CRUD)` | 모듈 설명 (다국어 JSON) |
| dependencies | array | `[]` | 의존하는 확장 맵 (manifest 파생 — {modules, plugins}) |
| status | string | `uninstalled` | 상태 (active: 활성화, inactive: 비활성화, installing: 설치 중, uninstalling: 제거 중, updating: 업데이트 중) |
| assets | null | `null` | 프론트엔드 에셋 매니페스트 (manifest 파생 — js/css 진입점·로딩 전략) |
| update_available | boolean | `false` | 최신 버전 대비 업데이트 가능 여부 |
| update_source | null | `null` | 업데이트 감지 출처 (github, bundled 등) |
| latest_version | null | `null` | 감지된 최신 배포 버전 |
| file_version | null | `null` | 설치된 파일의 manifest 버전 |
| github_url | null | `null` | GitHub 저장소 URL |
| github_changelog_url | null | `null` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소에 있어 설치 대기 중인지 여부 |
| is_bundled | boolean | `true` | 코어에 선탑재된 번들 확장인지 여부 |
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
    "message": "모듈을 성공적으로 가져왔습니다.",
    "data": {
        "data": [],
        "meta": {
            "total_modules": 0,
            "active_modules": 0,
            "system_modules": 0,
            "user_modules": 0,
            "total_installs": 0,
            "average_rating": null,
            "latest_version": null,
            "categories": [],
            "dependency_count": 0
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 아직 설치되지 않은 모듈만 조회합니다(예: 번들로 제공되나 미설치 상태인 샘플 모듈). `core.modules.read` 권한이 필요합니다. 설치 가능한 모듈을 사용자에게 노출하는 화면에서 사용하며, 미설치 항목은 assets·latest_version 등 설치 후에만 채워지는 필드가 null 로 반환됩니다.


### GET /api/admin/modules/{identifier}/changelog
<!-- @generated:start:api.admin.modules.changelog -->
- **라우트명**: `api.admin.modules.changelog`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@changelog`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |
| source | query | string | 아니오 | `active`, `bundled`, `github` | 변경 내역 조회 출처 (active: 활성 설치본, bundled: 번들 원본, github: 원격 저장소) |
| from_version | query | string | 아니오 | — | 시작 버전 (범위 하한) |
| to_version | query | string | 아니오 | — | 대상 버전 (범위 상한) |

**요청 예시**

```http
GET /api/admin/modules/{identifier}/changelog?source=active&from_version=%EC%98%88%EC%8B%9C%EA%B0%92&to_version=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| changelog | array | `[{"version":"1.0.5","date":"2026-07-16","categories":[{"n…` | 변경 이력 텍스트 (원격/파일 CHANGELOG 본문) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "...": "(2개 키 생략, 총 3개)"
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 특정 모듈의 변경 내역(CHANGELOG)을 조회합니다. `core.modules.read` 권한이 필요합니다. `source` 로 조회 출처를(active: 활성 설치본, bundled: 번들 원본, github: 원격 저장소) 선택하고, `from_version`·`to_version` 으로 버전 구간을 좁힐 수 있습니다. 업데이트 전 사용자에게 변경 사항을 안내하는 데 사용됩니다.


### GET /api/admin/modules/{identifier}/dependent-templates
<!-- @generated:start:api.admin.modules.dependent-templates -->
- **라우트명**: `api.admin.modules.dependent-templates`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@dependentTemplates`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/admin/modules/{identifier}/dependent-templates HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `gnuboard7-hello_module` | 모듈 고유 식별자 (vendor-module 형식) |
| name | string | `Hello 모듈` | 모듈 이름 (다국어 JSON) |
| version | string | `0.1.0` | 모듈 버전 |
| type | string | `user` | 의존 템플릿의 타입 (admin: 관리자 템플릿 / user: 사용자 템플릿) |
| status | string | `uninstalled` | 상태 (active: 활성화, inactive: 비활성화, installing: 설치 중, uninstalling: 제거 중, updating: 업데이트 중) |
| required_version | string | `>=1.0.0` | 요구되는 최소 버전 |

**응답 예시**

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
                "required_version": ">=1.0.5"
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
| 403 | Forbidden | 요구 권한(`core.modules.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 이 모듈에 의존하는 템플릿 목록을 조회합니다. `core.modules.read` 권한이 필요합니다. 응답으로 의존 템플릿 배열과 총 개수를 반환하며, 모듈 비활성화·제거 전 영향을 받는 템플릿을 사용자에게 미리 알리는 데 사용됩니다.


### GET /api/admin/modules/{identifier}/license
<!-- @generated:start:api.admin.modules.license -->
- **라우트명**: `api.admin.modules.license`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@license`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/admin/modules/{identifier}/license HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| content | string | `프로그램 명칭 : 그누보드7용 이커머스 모듈 (sirsoft-eco…` | 본문 내용 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "모듈을 성공적으로 가져왔습니다.",
    "data": {
        "content": "프로그램 명칭 : 그누보드7용 이커머스 모듈 (sirsoft-ecommerce)\n\n저작자 : (주)에스아이알소프트\n\n----- MIT 라이선스 (한국어 번역) --------------------------------------------------------\n\nMIT 라이선스\n\nCopyright (c) 2026 (주)에스아이알소프트\n\n이 소프트웨어와 관련 문서 파일(이하 \"소프트웨어\")의 복사본을 취득하는 모든 사람에게\n소프트웨어를 제한 없이 사용, 복사, 수정, 병합, 출판, 배포, 서브라이선스 허여 및/또는\n판매할 수 있는 권리를 무상으로 부여합니다. 다만, 소프트웨어를 제공받은 사람은 다음\n조건을 따라야 합니다:\n\n위 저작권 고지와 본 허가 고지는 소프트웨어의 모든 복사본 또는 상당 부분에 포함되어야\n합니다.\n\n소프트웨어는 \"있는 그대로\" 제공되며, 명시적이든 묵시적이든 어떠한 종류의 보증도 하지\n않습니다. 여기에는 상품성, 특정 목적에의 적합성 및 비침해에 대한 보증이 포함되나 이에\n국한되지 않습니다. 어떠한 경우에도 저작자 또는 저작권자는 소프트웨어나 소프트웨어의\n사용 또는 기타 거래로 인해 발생하는 계약, 불법행위 또는 기타 청구, 손해 또는 기타\n책임에 대해 책임을 지지 않습니다.\n\n----- MIT License (English Original) --------------------------------------------------------\n\nThe MIT License (MIT)\n\nCopyright (c) 2026 SIRSOFT\n\nPermission is hereby granted, free of charge, to any person obtaining a copy\nof this software and associated documentation files (the \"Software\"), to deal\nin the Software without restriction, including without limitation the rights\nto use, copy, modify, merge, publish, distribute, sublicense, and/or sell\ncopies of the Software, and to permit persons to whom the Software is\nfurnished to do so, subject to the following conditions:\n\nThe above copyright notice and this permission notice shall be included in all\ncopies or substantial portions of the Software.\n\nTHE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR\nIMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,\nFITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE\nAUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER\nLIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,\nOUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE\nSOFTWARE.\n"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 모듈에 포함된 라이선스 파일의 원문 내용을 반환합니다. `core.modules.read` 권한이 필요합니다. `identifier` 는 소문자·숫자·하이픈·언더스코어 형식만 허용되며 형식에 맞지 않거나 라이선스 파일이 없으면 404 를 반환합니다. 라이선스 고지 화면에 전문을 표시하는 용도입니다.


### GET /api/admin/modules/{moduleName}
<!-- @generated:start:api.admin.modules.show -->
- **라우트명**: `api.admin.modules.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| moduleName | path | string | 예 | — | 대상 module의 이름 (식별자) |

**요청 예시**

```http
GET /api/admin/modules/{moduleName} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ModuleResource::toDetailArray()` + 주입된 `language_packs`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-board` | 모듈 고유 식별자 (vendor-module 형식) |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `게시판` | 모듈 이름 (현재 로케일로 해석) |
| version | string | `1.0.0` | 모듈 버전 |
| description | string | `게시판 관리를 위한 모듈` | 모듈 설명 |
| github_url | string\|null | `https://github.com/gnuboard/g7-module-sirsoft-board` | GitHub 저장소 URL |
| requires_core | string\|null | `7.0.0` | 요구하는 코어 최소 버전 (manifest `g7_version`) |
| dependencies | array | `[]` | 의존 확장 목록 (enriched — identifier/name/type/required_version/installed_version/is_active/is_met) |
| status | string | `active` | 상태 (`active` \| `inactive` \| `installing` \| `uninstalling` \| `updating` \| `uninstalled`) |
| is_installed | boolean | `true` | 활성 디렉토리에 설치되어 DB 레코드가 있는지 여부 |
| permissions | array | `[]` | manifest 가 선언한 권한 카테고리 목록 |
| roles | array | `[]` | manifest 가 선언한 역할 목록 |
| admin_menus | array | `[]` | manifest 가 선언한 관리자 메뉴 정의 |
| license | string\|null | `MIT` | 라이선스 식별자 |
| layouts_count | integer | `12` | 모듈이 제공하는 레이아웃 파일 수 |
| config | object | `{}` | manifest 의 config 블록 |
| metadata | object | `{"identifier":"sirsoft-board", ...}` | manifest 원본 메타데이터 |
| update_available | boolean | `false` | 최신 버전 대비 업데이트 가능 여부 |
| update_source | string\|null | `null` | 업데이트 감지 출처 (`github` \| `bundled`) |
| latest_version | string\|null | `null` | 감지된 최신 배포 버전 |
| file_version | string\|null | `null` | 설치된 파일의 manifest 버전 |
| github_changelog_url | string\|null | `null` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소 항목 여부 |
| is_bundled | boolean | `false` | 코어 선탑재 번들 확장 여부 |
| deactivated_reason | string\|null | `null` | 비활성화 사유 (`manual` \| `incompatible_core`) |
| deactivated_at | string\|null | `null` | 비활성화 일시 |
| incompatible_required_version | string\|null | `null` | 요구 코어 버전 미충족 시 필요한 버전 |
| created_at | string\|null | `2026-07-01 09:00:00` | 설치(레코드 생성) 일시 (사용자 타임존 변환) |
| updated_at | string\|null | `2026-07-10 11:20:00` | 최종 갱신 일시 (사용자 타임존 변환) |
| language_packs | array | `[]` | 이 모듈을 대상으로 하는 언어팩 목록 (`LanguagePackResource` — identifier/locale/version/status 등). 없으면 빈 배열 |

**응답 예시**

```json
{
    "success": true,
    "message": "모듈을 성공적으로 가져왔습니다.",
    "data": {
        "identifier": "sirsoft-board",
        "vendor": "sirsoft",
        "name": "게시판",
        "version": "1.0.0",
        "description": "게시판 관리를 위한 모듈",
        "github_url": "https://github.com/gnuboard/g7-module-sirsoft-board",
        "requires_core": "7.0.0",
        "dependencies": [],
        "status": "active",
        "is_installed": true,
        "permissions": [],
        "roles": [],
        "admin_menus": [],
        "license": "MIT",
        "layouts_count": 12,
        "config": {},
        "metadata": {
            "identifier": "sirsoft-board",
            "vendor": "sirsoft",
            "version": "1.0.0"
        },
        "update_available": false,
        "update_source": null,
        "latest_version": null,
        "file_version": null,
        "github_changelog_url": null,
        "is_pending": false,
        "is_bundled": false,
        "deactivated_reason": null,
        "deactivated_at": null,
        "incompatible_required_version": null,
        "created_at": "2026-07-01 09:00:00",
        "updated_at": "2026-07-10 11:20:00",
        "language_packs": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.read`)이 없는 경우 |
| 404 | Not Found | 해당 식별자의 모듈이 활성/_pending/_bundled 어디에도 없는 경우 (`module.not_found`) |
| 500 | Internal Server Error | 조회 중 예외 발생 (`module.fetch_failed`) |

<!-- @generated:end -->

**설명** 특정 모듈의 상세 정보를 조회합니다. `core.modules.read` 권한이 필요합니다. 목록보다 자세한 `toDetailArray()` 형태를 반환하며, 이 모듈이 지원하는 번들 언어팩 정보가 함께 주입됩니다. 모듈을 찾을 수 없으면 404 를 반환합니다.


### GET /api/admin/modules/{moduleName}/check-modified-layouts
<!-- @generated:start:api.admin.modules.check-modified-layouts -->
- **라우트명**: `api.admin.modules.check-modified-layouts`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@checkModifiedLayouts`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| moduleName | path | string | 예 | — | 대상 module의 이름 (식별자) |

**요청 예시**

```http
GET /api/admin/modules/{moduleName}/check-modified-layouts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| has_modified_layouts | boolean | `true` | 사용자가 수정한 레이아웃이 하나라도 있는지 여부 (원본 content 해시와 현재 해시 비교) |
| modified_count | integer | `2` | 수정된 레이아웃 수 |
| modified_layouts | array | `[{"id":31, ...}]` | 수정된 레이아웃 목록 (아래 하위 필드) |
| modified_layouts[].id | integer | `31` | 레이아웃 ID |
| modified_layouts[].name | string | `admin/board/index` | 레이아웃 이름 |
| modified_layouts[].updated_at | string\|null | `2026-07-10 11:20:00` | 최종 수정 일시 (`Y-m-d H:i:s`) |
| modified_layouts[].size_diff | integer | `128` | 원본 대비 현재 content 크기 차이(바이트, 음수 가능) |

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
                "id": 31,
                "name": "admin/board/index",
                "updated_at": "2026-07-10 11:20:00",
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
| 403 | Forbidden | 요구 권한(`core.modules.read`)이 없는 경우 |
| 404 | Not Found | 해당 식별자의 모듈이 활성/_pending/_bundled 어디에도 없는 경우 (`module.not_found`) |
| 422 | Unprocessable Entity | 수정 레이아웃 확인 실패 (`modules.check_modified_layouts_failed` — `error.errors.module_name`) |
| 500 | Internal Server Error | 확인 처리 중 예외 발생 |

<!-- @generated:end -->

**설명** 특정 모듈에서 사용자가 수정한 레이아웃이 있는지 확인합니다. `core.modules.read` 권한이 필요합니다. 업데이트 실행 전 이 정보를 조회하여 레이아웃 전략(overwrite: 새 버전으로 교체, keep: 사용자 수정본 유지) 선택을 안내하는 데 사용됩니다.


### GET /api/admin/modules/{moduleName}/install-preview
<!-- @generated:start:api.admin.modules.install-preview -->
- **라우트명**: `api.admin.modules.install-preview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@installPreview`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| moduleName | path | string | 예 | — | 대상 module의 이름 (식별자) |

**요청 예시**

```http
GET /api/admin/modules/{moduleName}/install-preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`target` + `dependencies[]` + `language_packs[]`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| target.identifier | string | `sirsoft-ecommerce` | 설치 대상 모듈 식별자 |
| target.name | string\|null | `이커머스` | 설치 대상 모듈 이름 (현재 로케일) |
| target.version | string\|null | `1.0.1` | 설치 대상 모듈 버전 |
| dependencies | array | `[{"type":"module", ...}]` | 의존 확장 cascade 후보 목록 (아래 하위 필드) |
| dependencies[].type | string | `module` | 의존 확장 유형 (`module` \| `plugin`) |
| dependencies[].identifier | string | `sirsoft-board` | 의존 확장 식별자 |
| dependencies[].name | string\|null | `게시판` | 의존 확장 이름 |
| dependencies[].required_version | string\|null | `^1.0` | manifest 가 요구하는 버전 제약 |
| dependencies[].installed_version | string\|null | `null` | 현재 설치된 버전 (미설치면 null) |
| dependencies[].is_installed | boolean | `false` | 설치 여부 |
| dependencies[].is_active | boolean | `false` | 활성 여부 |
| dependencies[].is_met | boolean | `false` | 의존 조건 충족 여부 |
| dependencies[].available | boolean | `true` | cascade 후보로 선택 가능한지 (미충족 의존성만 true) |
| dependencies[].default_selected | boolean | `true` | 체크리스트 기본 선택 여부 (미충족 + 미설치 시 true) |
| language_packs | array | `[{"bundled_identifier":"g7-module-sirsoft-ecommerce-ja", ...}]` | 함께 설치 가능한 미설치 번들 언어팩 목록 (아래 하위 필드) |
| language_packs[].bundled_identifier | string | `g7-module-sirsoft-ecommerce-ja` | 번들 언어팩 식별자 (install API 의 `language_packs[]` 값) |
| language_packs[].locale | string | `ja` | 언어 코드 |
| language_packs[].locale_native_name | string\|null | `日本語` | 현지 표기 언어명 |
| language_packs[].locale_name | string\|null | `일본어` | 언어명 |
| language_packs[].version | string | `1.0.0` | 언어팩 버전 |
| language_packs[].depends_on_extension | string\|null | `null` | 이 언어팩이 귀속된 의존 확장 식별자 (본 확장용이면 null) |
| language_packs[].available | boolean | `true` | 선택 가능 여부 (항상 true — 미설치 항목만 수집) |
| language_packs[].default_selected | boolean | `true` | 체크리스트 기본 선택 여부 |

**응답 예시**

```json
{
    "success": true,
    "message": "모듈을 성공적으로 가져왔습니다.",
    "data": {
        "target": {
            "identifier": "sirsoft-ecommerce",
            "name": "이커머스",
            "version": "1.0.1"
        },
        "dependencies": [
            {
                "type": "module",
                "identifier": "sirsoft-board",
                "name": "게시판",
                "required_version": "^1.0",
                "installed_version": null,
                "is_installed": false,
                "is_active": false,
                "is_met": false,
                "available": true,
                "default_selected": true
            }
        ],
        "language_packs": [
            {
                "bundled_identifier": "g7-module-sirsoft-ecommerce-ja",
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
| 403 | Forbidden | 요구 권한(`core.modules.install`)이 없는 경우 |
| 404 | Not Found | 해당 식별자의 모듈이 활성/_pending/_bundled 어디에도 없는 경우 (`module.not_found`) |
| 500 | Internal Server Error | 대상 확장을 찾을 수 없거나 프리뷰 빌드 중 예외 발생 (`module.fetch_failed`) |

<!-- @generated:end -->

**설명** 모듈 설치 시 함께 처리될 cascade 후보(의존 확장 + 동반 가능한 번들 언어팩) 트리를 반환합니다. `core.modules.install` 권한이 필요합니다. 설치 모달 오픈 시 호출되어 사용자가 함께 설치할 항목을 선택하도록 노출하며, ZIP 업로드 기반의 `manifest-preview` 와 달리 이미 알려진 식별자에 대한 GET 조회입니다.


### GET /api/admin/modules/{moduleName}/uninstall-info
<!-- @generated:start:api.admin.modules.uninstall-info -->
- **라우트명**: `api.admin.modules.uninstall-info`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@uninstallInfo`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.uninstall`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| moduleName | path | string | 예 | — | 대상 module의 이름 (식별자) |

**요청 예시**

```http
GET /api/admin/modules/{moduleName}/uninstall-info HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| tables | array | `[{"name":"board_posts", ...}]` | 모듈이 소유한 DB 테이블 목록 (마이그레이션 정적 추출 + 동적 테이블 병합). 각 항목: `name`, `size_bytes`(MySQL 외 드라이버는 null), `size_formatted` |
| storage_directories | array | `[{"name":"attachments", ...}]` | `storage/app/modules/{identifier}` 하위 1-depth 디렉토리 목록. 각 항목: `name`, `size_bytes`, `size_formatted` |
| vendor_directory | object\|null | `{"items":[...],"total_size_bytes":10485760, ...}` | Composer vendor 디렉토리·composer.lock 정보 (둘 다 없으면 null). `items[]` 각 항목: `name`, `size_bytes`, `size_formatted` |
| extension_directory | object\|null | `{"path":"modules/sirsoft-board", ...}` | 모듈 설치 디렉토리 정보 (`path`, `size_bytes`, `size_formatted`). 디렉토리 없으면 null |
| shared_records | array | `[{"table":"layouts","label_key":"layouts","count":12}]` | 코어 공유 테이블에 적재된 이 모듈의 레코드 수 (`delete_data=true` 시 정리 대상). 0건 항목은 제외 |
| total_table_size_bytes | integer | `2097152` | 모듈 테이블 용량 합계(바이트) |
| total_table_size_formatted | string | `2 MB` | 모듈 테이블 용량 합계(사람이 읽는 형식) |
| total_storage_size_bytes | integer | `524288` | 스토리지 디렉토리 용량 합계(바이트) |
| total_storage_size_formatted | string | `512 KB` | 스토리지 디렉토리 용량 합계(사람이 읽는 형식) |

**응답 예시**

```json
{
    "success": true,
    "message": "모듈 삭제 정보를 성공적으로 조회했습니다.",
    "data": {
        "tables": [
            {
                "name": "board_posts",
                "size_bytes": 2097152,
                "size_formatted": "2 MB"
            }
        ],
        "storage_directories": [
            {
                "name": "attachments",
                "size_bytes": 524288,
                "size_formatted": "512 KB"
            }
        ],
        "vendor_directory": null,
        "extension_directory": {
            "path": "modules/sirsoft-board",
            "size_bytes": 1048576,
            "size_formatted": "1 MB"
        },
        "shared_records": [
            {
                "table": "layouts",
                "label_key": "layouts",
                "count": 12
            }
        ],
        "total_table_size_bytes": 2097152,
        "total_table_size_formatted": "2 MB",
        "total_storage_size_bytes": 524288,
        "total_storage_size_formatted": "512 KB"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.uninstall`)이 없는 경우 |
| 404 | Not Found | 해당 식별자의 모듈을 찾을 수 없는 경우 (`module.not_found`) |
| 500 | Internal Server Error | 삭제 정보 조회 중 예외 발생 (`module.uninstall_info_failed`) |

<!-- @generated:end -->

**설명** 모듈 제거 시 삭제될 데이터 정보를 조회합니다. `core.modules.uninstall` 권한이 필요합니다. 제거 확인 모달에서 사용자에게 어떤 데이터가 사라지는지 미리 보여주는 용도이며, 모듈을 찾을 수 없으면 404 를 반환합니다.


### POST /api/admin/modules/{moduleName}/update
<!-- @generated:start:api.admin.modules.update -->
- **라우트명**: `api.admin.modules.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ModuleController@performUpdate`
- **인증/권한**: `auth:sanctum` + `permission:core.modules.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| moduleName | path | string | 예 | — | 대상 module의 이름 (식별자) |
| layout_strategy | body | string | 아니오 | `overwrite`, `keep` | 업데이트 시 레이아웃 처리 전략 (overwrite: 새 버전으로 교체, keep: 사용자 수정본 유지) |
| vendor_mode | body | string | 아니오 | `auto`, `composer`, `bundled` | 벤더 설치 모드 (auto/composer/bundled) |
| force | body | boolean | 아니오 | — | 강제 실행 여부 (안전 확인/선행 검사 우회) |
| rebuild_search_index | body | boolean | 아니오 | — | 업데이트 후 색인이 누락된 검색 인덱스를 재생성할지 여부 (기본 false). 재생성 중에는 대상 인덱스가 잠기거나 재색인되므로 운영 중인 사이트에서는 접속이 적은 시간에 별도로 수행하는 것을 권장 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.module.perform_update_validation_rules`).

**요청 예시**

```http
POST /api/admin/modules/{moduleName}/update HTTP/1.1
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

_단건 응답: `data` 는 업데이트된 모듈의 `ModuleResource` 객체 (목록 응답 항목과 동일 필드 구성)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-board` | 모듈 식별자 |
| vendor | string | `sirsoft` | 벤더/개발자명 |
| name | string | `게시판` | 모듈 이름 (현재 로케일로 해석) |
| version | string | `1.0.1` | 업데이트 후 버전 |
| description | string | `게시판 관리를 위한 모듈` | 모듈 설명 |
| dependencies | array | `[]` | 의존 확장 목록 (enriched) |
| status | string | `active` | 업데이트 후 복원된 상태 |
| assets | object\|null | `null` | 프론트엔드 에셋 매니페스트 |
| update_available | boolean | `false` | 업데이트 가능 여부 |
| update_source | string\|null | `null` | 업데이트 감지 출처 |
| latest_version | string\|null | `null` | 감지된 최신 배포 버전 |
| file_version | string\|null | `null` | 설치된 파일 manifest 버전 |
| github_url | string\|null | `https://github.com/gnuboard/g7-module-sirsoft-board` | GitHub 저장소 URL |
| github_changelog_url | string\|null | `null` | GitHub 변경 내역 URL |
| is_pending | boolean | `false` | _pending 대기소 항목 여부 |
| is_bundled | boolean | `false` | 코어 선탑재 번들 확장 여부 |
| deactivated_reason | string\|null | `null` | 비활성화 사유 |
| deactivated_at | string\|null | `null` | 비활성화 일시 |
| incompatible_required_version | string\|null | `null` | 요구 코어 버전 미충족 시 필요한 버전 |
| abilities | object | `{"can_install":true,"can_activate":true,"can_uninstall":true}` | 현재 사용자가 수행 가능한 작업 불리언 맵 |

**응답 예시**

```json
{
    "success": true,
    "message": "모듈이 성공적으로 업데이트되었습니다.",
    "data": {
        "identifier": "sirsoft-board",
        "vendor": "sirsoft",
        "name": "게시판",
        "version": "1.0.1",
        "description": "게시판 관리를 위한 모듈",
        "dependencies": [],
        "status": "active",
        "assets": null,
        "update_available": false,
        "update_source": null,
        "latest_version": null,
        "file_version": null,
        "github_url": "https://github.com/gnuboard/g7-module-sirsoft-board",
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
| 403 | Forbidden | 요구 권한(`core.modules.install`)이 없는 경우 |
| 404 | Not Found | 해당 식별자의 모듈을 찾을 수 없는 경우 (`module.not_found`) |
| 422 | Unprocessable Entity | 요청 파라미터 검증 실패, 또는 업데이트 실패 (업데이트 소스 없음·다운그레이드 차단·코어 버전 비호환 — `error.errors.module_name` 에 사유) |
| 500 | Internal Server Error | 업데이트 처리 중 예외 발생 (`modules.errors.update_failed`) |

<!-- @generated:end -->

**설명** 특정 모듈을 최신 버전으로 업데이트합니다. `core.modules.install` 권한이 필요합니다. `layout_strategy` 로 레이아웃 처리 방식을(overwrite: 새 버전으로 교체, keep: 사용자 수정본 유지) 지정하며, `vendor_mode` 로 Composer 의존성 처리 방식을 선택합니다. 버전 제약·호환성 문제로 막힐 경우 `force: true` 로 강제 진행할 수 있습니다. `keep` 을 지정하면 사용자가 수정한 레이아웃(원본 해시와 현재 내용이 다른 레이아웃)은 갱신 대상에서 제외되어 현재 내용이 그대로 유지되고, 나머지 레이아웃만 파일 기준으로 갱신됩니다. 성공 응답 메시지에는 대상 식별자와 적용된 버전이 채워집니다. `rebuild_search_index: true` 를 함께 보내면 업데이트 후 색인이 누락된 검색 인덱스를 재생성합니다 — 인덱스 잠금·재색인 비용이 있어 기본은 수행하지 않으며, 보내지 않아도 응답의 `search_index` 에 현재 누락 여부가 담깁니다.


### GET /api/modules/assets/{identifier}
<!-- @generated:start:api.public.modules.assets.extensionless -->
- **라우트명**: `api.public.modules.assets.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicModuleController@serveAsset`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |
| path | query | string | 예 | — | 경로 |

**요청 예시**

```http
GET /api/modules/assets/sirsoft-ecommerce?identifier=example-key&path=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 표준 JSON 봉투가 아니라 **모듈 에셋 파일 본문** 을 그대로 반환한다 — `data` 구조가 없다._

| 항목 | 값 | 설명 |
| --- | --- | --- |
| Content-Type | `파일 확장자에 따른 MIME (예: text/javascript, image/png)` | 서빙 대상의 MIME 타입 |
| Cache-Control | `public, max-age=31536000, immutable` (프로덕션) / `no-cache` (그 외) | 환경에 따라 갈린다 |
| ETag | `{md5(mtime+size)}` | `If-None-Match` 가 일치하면 본문 없이 `304` |

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/javascript
Cache-Control: public, max-age=31536000, immutable
ETag: "9f2c…"

(function(){ /* 모듈 에셋 본문 */ })();
```

> 같은 ETag 로 재요청하면 본문 없이 `304 Not Modified` 가 반환된다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/modules/assets/{identifier}/{path}
<!-- @generated:start:api.public.modules.assets -->
- **라우트명**: `api.public.modules.assets`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicModuleController@serveAsset`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |
| path | path | string | 예 | — | 경로 |

**요청 예시**

```http
GET /api/modules/assets/{identifier}/{path} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다 — 요청한 에셋 파일의 원본 바이트를 그대로 서빙합니다 (`Content-Type` 은 파일 MIME, `ETag` + `Cache-Control: max-age=31536000`). 에러 시에만 표준 JSON 에러 봉투를 반환합니다._

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/javascript
ETag: "a1b2c3d4e5f6"
Cache-Control: public, max-age=31536000

(에셋 파일 원본 내용)
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 허용되지 않은 파일 확장자를 요청한 경우 (`modules.errors.file_type_not_allowed`) |
| 404 | Not Found | 모듈이 없거나 비활성 상태인 경우, 또는 요청한 파일이 없는 경우 (`modules.errors.not_found` / `modules.errors.file_not_found`) |
| 422 | Unprocessable Entity | 경로 검증 실패 (경로 탈출 시도 등 — FormRequest 검증) |
| 500 | Internal Server Error | 알 수 없는 오류 (`modules.errors.unknown_error`) |

<!-- @generated:end -->

**설명** 모듈의 개별 프론트엔드 에셋 파일(JS/CSS/이미지 등)을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않으며, 경로·확장자 보안 검증은 FormRequest 에서 완료됩니다. 모듈 미존재·파일 미존재·허용되지 않은 파일 유형은 각각 404/404/403 으로 응답하고, 정상 파일은 ETag 와 1년 캐시 헤더를 붙여 반환합니다. 소스맵 등 개별 에셋을 직접 참조할 때 사용되며, 통합 로딩은 `bundle.js`/`bundle.css` 를 사용합니다.


### GET /api/modules/bundle.css
<!-- @generated:start:api.public.modules.bundle.css -->
- **라우트명**: `api.public.modules.bundle.css`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicModuleController@serveBundleCss`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/bundle.css HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다 — 활성 모듈 CSS 를 병합한 원본 텍스트를 `text/css` 로 서빙합니다 (`ETag` + 환경별 `Cache-Control`). 활성 global 모듈 에셋이 없으면 빈 본문의 200 응답._

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/css
ETag: "a1b2c3d4e5f6"
Cache-Control: public, max-age=31536000

(활성 모듈 CSS 병합 내용)
```

**에러 응답**

_에러 응답 없음 — 공개 엔드포인트이며, 병합 대상이 없어도 빈 본문의 200(text/css)을 반환합니다._

<!-- @generated:end -->

**설명** 활성 모듈들의 프론트엔드 CSS 를 서버에서 하나로 병합한 번들을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않습니다. 활성 global 모듈 에셋이 없으면 빈 200(text/css) 응답을 반환하고, 있으면 병합 파일을 ETag·환경별 Cache-Control 과 함께 서빙합니다. 페이지가 모듈 스타일을 요청 1건으로 로드하도록 합니다.


### GET /api/modules/bundle.js
<!-- @generated:start:api.public.modules.bundle.js -->
- **라우트명**: `api.public.modules.bundle.js`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicModuleController@serveBundleJs`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/bundle.js HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다 — 활성 모듈 IIFE JS 를 병합한 원본 텍스트를 `text/javascript` 로 서빙합니다 (`ETag` + 환경별 `Cache-Control`). 활성 global 모듈 에셋이 없으면 빈 본문의 200 응답._

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/javascript
ETag: "a1b2c3d4e5f6"
Cache-Control: public, max-age=31536000

(활성 모듈 IIFE JS 병합 내용)
```

**에러 응답**

_에러 응답 없음 — 공개 엔드포인트이며, 병합 대상이 없어도 빈 본문의 200(text/javascript)을 반환합니다._

<!-- @generated:end -->

**설명** 활성 모듈들의 프론트엔드 IIFE JS 를 서버에서 하나로 병합한 번들을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않습니다. 활성 global 모듈 에셋이 없으면 빈 200(text/javascript) 응답을 반환하고(프론트는 빈 스크립트 로드로 무해), 있으면 병합 파일을 ETag·환경별 Cache-Control 과 함께 서빙합니다. 프론트는 `G7Config.bundleUrls` 를 읽어 이 번들을 로드합니다.


### GET /api/modules/bundle/css
<!-- @generated:start:api.public.modules.bundle.css.extensionless -->
- **라우트명**: `api.public.modules.bundle.css.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicModuleController@serveBundleCss`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/bundle/css HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 표준 JSON 봉투가 아니라 **활성 모듈 CSS 를 병합한 번들 본문** 을 그대로 반환한다 — `data` 구조가 없다._

| 항목 | 값 | 설명 |
| --- | --- | --- |
| Content-Type | `text/css` | 서빙 대상의 MIME 타입 |
| Cache-Control | `public, max-age=31536000, immutable` (프로덕션) / `no-cache` (그 외) | 환경에 따라 갈린다 |
| ETag | `{md5(mtime+size)}` | `If-None-Match` 가 일치하면 본문 없이 `304` |

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/css
Cache-Control: public, max-age=31536000, immutable
ETag: "9f2c…"

/* module-a */ .a{}
/* module-b */ .b{}
```

> 같은 ETag 로 재요청하면 본문 없이 `304 Not Modified` 가 반환된다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 200 | OK (빈 본문) | 활성 확장이 없거나 병합할 에셋이 없는 경우 — 오류가 아니라 빈 번들이다 |

> 개별 확장의 병합이 실패하면 그 확장만 건너뛰고 나머지는 그대로 병합된다(실패 격리). 건너뛴 사실은 서버 로그(warning)에 남는다.

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/modules/bundle/js
<!-- @generated:start:api.public.modules.bundle.js.extensionless -->
- **라우트명**: `api.public.modules.bundle.js.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicModuleController@serveBundleJs`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/bundle/js HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 표준 JSON 봉투가 아니라 **활성 모듈 JS(IIFE)를 병합한 번들 본문** 을 그대로 반환한다 — `data` 구조가 없다._

| 항목 | 값 | 설명 |
| --- | --- | --- |
| Content-Type | `text/javascript` | 서빙 대상의 MIME 타입 |
| Cache-Control | `public, max-age=31536000, immutable` (프로덕션) / `no-cache` (그 외) | 환경에 따라 갈린다 |
| ETag | `{md5(mtime+size)}` | `If-None-Match` 가 일치하면 본문 없이 `304` |

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/javascript
Cache-Control: public, max-age=31536000, immutable
ETag: "9f2c…"

(function(){/* module-a */})()
;
(function(){/* module-b */})()
```

> 같은 ETag 로 재요청하면 본문 없이 `304 Not Modified` 가 반환된다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 200 | OK (빈 본문) | 활성 확장이 없거나 병합할 에셋이 없는 경우 — 오류가 아니라 빈 번들이다 |

> 개별 확장의 병합이 실패하면 그 확장만 건너뛰고 나머지는 그대로 병합된다(실패 격리). 건너뛴 사실은 서버 로그(warning)에 남는다.

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/modules/{identifier}/components
<!-- @generated:start:api.public.modules.components.extensionless -->
- **라우트명**: `api.public.modules.components.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicModuleController@serveComponents`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/components HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)



_`data` 는 모듈 의 `components.json` 내용을 그대로 담은 **컴포넌트 맵**이다 (고정 필드 집합이 아니라 컴포넌트명 → 정의 매핑)._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| (컴포넌트명) | object | `{"type":"composite","props":{…}}` | 컴포넌트 정의. 키는 레이아웃 JSON 의 `name` 과 일치한다 |

> 파일이 없거나 비어 있으면 `data` 는 빈 객체(`{}`)다 — 오류가 아니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "identifier": "sirsoft-ecommerce",
    "version": "1.0.3",
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

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/modules/{identifier}/components.json
<!-- @generated:start:api.public.modules.components -->
- **라우트명**: `api.public.modules.components`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicModuleController@serveComponents`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/{identifier}/components.json HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)



_이 엔드포인트는 표준 `success/message/data` 봉투를 사용하지 않습니다 — 모듈의 `components.json` 파일 내용을 최상위 JSON 으로 그대로 반환합니다 (1시간 캐시). 파일 미생성(구버전 모듈) 시 빈 객체로 폴백._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| $schema | string | `https://json-schema.org/draft/2020-12/schema` | 컴포넌트 매니페스트 JSON 스키마 URL |
| identifier | string | `sirsoft-ecommerce` | 컴포넌트를 제공하는 모듈 식별자 (네임스페이스 병합 키) |
| version | string | `1.0.0-beta.5` | 컴포넌트 매니페스트 버전 |
| components | object | `{"basic":[],"composite":[],"layout":[]}` | 타입별 컴포넌트 정의 묶음 |
| components.basic | array | `[]` | Basic 타입 컴포넌트 정의 목록 |
| components.composite | array | `[]` | Composite 타입 컴포넌트 정의 목록 |
| components.layout | array | `[]` | Layout 타입 컴포넌트 정의 목록 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정을 조회했습니다.",
    "data": {
        "ProductCard": {
            "type": "composite",
            "props": {
                "product": "object"
            }
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.read \| core.menus.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 모듈의 컴포넌트 정의 파일(components.json)을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않습니다. 편집 모드 부팅 시 ComponentRegistry 가 활성 확장 매니페스트를 네임스페이스 병합하기 위해 fetch 하며, 구버전 모듈처럼 파일이 없으면 빈 components 로 폴백합니다. 응답은 1시간 캐시됩니다. 모듈 미존재 시 404.


### GET /api/modules/{identifier}/editor-spec
<!-- @generated:start:api.public.modules.editor_spec -->
- **라우트명**: `api.public.modules.editor_spec`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicModuleController@serveEditorSpec`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/{identifier}/editor-spec HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `sirsoft-ecommerce` | 모듈 고유 식별자 (vendor-module 형식) |
| spec | object | `{"$schema":"https:\/\/json-schema.org\/draft\/2020-12\/sc…` | 스펙 정의 객체 (편집기/컴포넌트 선언 스키마 등) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "편집기 스펙을 조회했습니다.",
    "data": {
        "identifier": "sirsoft-ecommerce",
        "spec": {
            "$schema": "https://json-schema.org/draft/2020-12/schema",
            "moduleId": "sirsoft-ecommerce",
            "version": "1.0.0",
            "description": "레이아웃 편집기 스펙 — 이커머스 모듈 도메인 sampleData/sampleGlobal/states. admin 레이아웃 data_source ID 전수 스캔 기반 도메인 ID 28종(상품·주문·브랜드·쿠폰·배송정책·정산·설정 등) byDataSourceId + 사용자 페이지(템플릿 렌더) byEndpointPattern. 공용 인프라(roles/availableChannels/identityProviders/ecommerceIdentity*/ecommerceNotificationDefinitions)는 admin 템플릿 스펙·코어 프리셋 폴백이 커버.",
            "actionRecipes": {
                "comment": "이커머스 모듈 소유 친화 액션 레시피. 코어 시드 위에 module 단계로 병합(__source:{kind:module,id:sirsoft-ecommerce}, 편집기 〔이커머스〕 배지). 라벨은 모듈 격리 네임스페이스($t:sirsoft-ecommerce.editor.action.*) — 편집기 t()가 모듈 lang 통짜 ko.json/en.json 의 editor.action 을 sirsoft-ecommerce.editor.action 으로 해석한다(코어/템플릿 editor 와 격리). 결제(PG) 진입은 커머스 도메인이라 코어가 아닌 모듈이 소유한다(provider-agnostic — 핸들러명을 백엔드 응답값으로 받음).",
                "requestPgPayment": {
                    "comment": "결제(PG) 진입 — provider-agnostic. 백엔드 응답이 호출할 결제 진입 핸들러 풀네임(pg_payment_handler)을 데이터 칩으로 연결하면 그 핸들러를 dispatch(특정 PG 하드코딩 없음). build.handler 가 {{paymentHandler}} placeholder 라 matchAction 이 placeholder-aware 로 역매칭한다. onSuccess 컨텍스트(chipContext=response)에서 결제 응답 칩 노출.",
                    "label": "$t:sirsoft-ecommerce.editor.action.request_pg_payment.label",
                    "params": [
                        {
                            "key": "paymentHandler",
                            "label": "$t:sirsoft-ecommerce.editor.action.request_pg_payment.param_handler",
                            "widget": "data-chip",
                            "required": true
                        },
                        {
                            "key": "pgPaymentData",
                            "label": "$t:sirsoft-ecommerce.editor.action.request_pg_payment.param_data",
                            "widget": "data-chip",
                            "required": true
                        }
                    ],
                    "build": {
                        "handler": "{{paymentHandler}}",
                        "params": {
                            "pgPaymentData": "{{pgPaymentData}}"
                        }
                    }
                }
            },
            "...": "(4개 키 생략, 총 9개)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.modules.read \| core.menus.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 모듈의 레이아웃 편집기 스펙(editor-spec.json)을 서빙하는 공개 엔드포인트입니다. 인증이 필요하지 않습니다. 활성 모듈만 대상으로 하며 활성 디렉토리 → `_bundled` 폴백 순으로 읽어 `data.spec` 형태로 반환합니다. 비활성·미존재 모듈은 404 이고, 편집기 스펙 파일을 작성하지 않은 경우 spec=null 로 정상 응답합니다.


