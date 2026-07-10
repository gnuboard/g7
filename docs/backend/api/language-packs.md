# Language Packs API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Language Packs 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/language-packs
<!-- @generated:start:api.admin.language-packs.index -->
- **라우트명**: `api.admin.language-packs.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| scope | query | string | 아니오 | — | 조회 범위 한정 키 |
| target_identifier | query | string | 아니오 | max 150 | 대상 확장 식별자 |
| locale | query | string | 아니오 | max 20 | 로케일 코드 (표시 언어/지역) |
| status | query | string | 아니오 | — | 상태 필터 (해당 상태의 항목만 조회) |
| vendor | query | string | 아니오 | max 100 | 벤더명 (확장 제작자 식별자) |
| search | query | string | 아니오 | max 150 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| exclude_protected | query | boolean | 아니오 | — | 보호 항목 제외 여부 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

**요청 예시**

```http
GET /api/admin/language-packs?scope=%EC%98%88%EC%8B%9C%EA%B0%92&target_identifier=example-key&locale=ko&status=%EC%98%88%EC%8B%9C%EA%B0%92&vendor=%EC%98%88%EC%8B%9C%EA%B0%92&search=%EC%98%88%EC%8B%9C%EA%B0%92&exclude_protected=1&per_page=1&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `16` | 기본 키 (내부 식별자) |
| identifier | string | `g7-core-en` | 언어팩 고유 식별자 ({vendor}-{scope}-{target?}-{locale}) |
| vendor | string | `g7` | 언어팩 제작자 식별자 |
| scope | string | `core` | 적용 대상 분류 |
| target_identifier | string | `gnuboard7-hello_module` | 대상 확장 식별자 (scope=core일 때 null) |
| locale | string | `en` | IETF BCP-47 locale 태그 |
| locale_name | string | `EN` | 영문 언어명 |
| locale_native_name | string | `English` | 원어 언어명 |
| text_direction | string | `ltr` | 텍스트 방향 |
| version | string | `7.0.1` | 언어팩 버전 |
| latest_version | string | `1.0.0` | 감지된 최신 배포 버전 |
| target_version_constraint | null | `null` | 대상 확장 버전 제약 (semver) |
| target_version_mismatch | boolean | `false` | 대상 버전 불일치 경고 플래그 |
| name | string | `API 문서 샘플 언어팩` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| license | string | `MIT` | 라이선스 |
| description | string | `문서 실측용 언어팩` | 언어팩 설명 (다국어) |
| status | string | `active` | 언어팩 상태 |
| is_protected | boolean | `true` | protected 여부 |
| source_type | string | `built_in` | 설치 소스 유형 (zip/github/url/bundled/bundled_with_extension) |
| origin | string | `built_in` | 출처 (설치/등록 원천 구분 값) |
| source_url | string | `lang/en` | 설치 소스 URL 또는 경로 |
| github_url | null | `null` | GitHub 저장소 URL (manifest 파생) |
| github_changelog_url | null | `null` | GitHub 변경 이력(CHANGELOG) URL (manifest 파생) |
| bundled_identifier | string | `g7-module-gnuboard7-hello_module-ja` | 대응하는 번들 확장 식별자 (번들 원본 매칭용) |
| install_blocked_reason | string | `target_not_installed` | 설치가 차단된 사유 (차단 없으면 null) |
| target_name | string | `게시판` | 대상 확장의 표시 이름 (scope+target_identifier 로 해석) |
| installed_at | string | `2026-07-03 19:20:23` | installed 일시 |
| activated_at | string | `2026-07-03 19:20:23` | activated 일시 |
| created_at | string | `2026-07-06 19:20:23` | 생성 일시 |
| updated_at | string | `2026-07-06 19:20:23` | 최종 수정 일시 |
| has_update | boolean | `false` | update 여부 |
| abilities | object | `{"can_activate":true,"can_deactivate":true,"can_uninstall…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어팩 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": null,
                "identifier": "g7-core-en",
                "vendor": "g7",
                "scope": "core",
                "target_identifier": null,
                "locale": "en",
                "locale_name": "EN",
                "locale_native_name": "English",
                "text_direction": "ltr",
                "version": "7.0.1",
                "latest_version": null,
                "target_version_constraint": null,
                "target_version_mismatch": false,
                "name": null,
                "license": null,
                "description": null,
                "status": "active",
                "is_protected": true,
                "source_type": "built_in",
                "origin": "built_in",
                "source_url": "lang/en",
                "github_url": null,
                "github_changelog_url": null,
                "bundled_identifier": null,
                "install_blocked_reason": null,
                "target_name": null,
                "installed_at": null,
                "activated_at": null,
                "created_at": null,
                "updated_at": null,
                "has_update": false,
                "abilities": {
                    "can_activate": true,
                    "can_deactivate": true,
                    "can_uninstall": true
                }
            },
            {
                "id": 1,
                "identifier": "apidoc-sample-lang",
                "vendor": "apidoc",
                "scope": "core",
                "target_identifier": null,
                "locale": "fr",
                "locale_name": "French",
                "locale_native_name": "Français",
                "text_direction": "ltr",
                "version": "1.0.0",
                "latest_version": "1.0.0",
                "target_version_constraint": null,
                "target_version_mismatch": false,
                "name": "API 문서 샘플 언어팩",
                "license": "MIT",
                "description": "문서 실측용 언어팩",
                "status": "active",
                "is_protected": false,
                "source_type": "bundled",
                "origin": "bundled",
                "source_url": null,
                "github_url": null,
                "github_changelog_url": null,
                "bundled_identifier": null,
                "install_blocked_reason": null,
                "target_name": null,
                "installed_at": "2026-07-05 10:41:24",
                "activated_at": "2026-07-05 10:41:24",
                "created_at": "2026-07-08 10:41:24",
                "updated_at": "2026-07-08 10:41:24",
                "has_update": false,
                "abilities": {
                    "can_activate": true,
                    "can_deactivate": true,
                    "can_uninstall": true
                }
            },
            "... (총 25건 중 2건 표시)"
        ],
        "meta": {
            "total": 43,
            "active": 15,
            "installed": 0,
            "inactive": 0,
            "error": 0,
            "uninstalled": 10,
            "current_page": 1,
            "last_page": 2,
            "per_page": 25
        },
        "abilities": {
            "can_install": true,
            "can_activate": true,
            "can_deactivate": true,
            "can_uninstall": true,
            "can_refresh_cache": true,
            "can_check_updates": true,
            "can_update": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 설치된 언어팩과 번들 소스로부터 노출되는 언어팩 목록을 페이지네이션으로 조회합니다. `core.language_packs.read` 권한이 필요합니다. `scope`/`locale`/`status`/`vendor`/`search` 등으로 필터링하고 `exclude_protected` 로 보호(protected) 팩을 제외할 수 있습니다. 관리자 언어팩 관리 화면의 목록/필터 표시에 사용합니다.


### POST /api/admin/language-packs/bulk-activate
<!-- @generated:start:api.admin.language-packs.bulk-activate -->
- **라우트명**: `api.admin.language-packs.bulk-activate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@bulkActivate`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.language_packs.bulk_activate_validation_rules`).

**요청 예시**

```http
POST /api/admin/language-packs/bulk-activate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
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
| 403 | Forbidden | 요구 권한(`core.language_packs.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 전달된 `ids` 배열의 언어팩을 일괄 활성화합니다. `core.language_packs.manage` 권한이 필요합니다. 성공/실패를 분리한 결과를 반환하므로 일부만 실패해도 전체가 롤백되지 않습니다. 비활성 팩을 한 번에 재활성화하는 reactivate 모달의 "활성화" 동작에 사용합니다.


### POST /api/admin/language-packs/check-updates
<!-- @generated:start:api.admin.language-packs.check-updates -->
- **라우트명**: `api.admin.language-packs.check-updates`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@checkUpdates`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/language-packs/check-updates HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.language_packs.update`)이 없는 경우 |

<!-- @generated:end -->

**설명** GitHub 소스로 설치된 언어팩들의 원격 최신 버전을 조회해 업데이트 가능 여부를 확인합니다. `core.language_packs.update` 권한이 필요합니다. 실제 업데이트를 수행하지 않고 검사 결과(checked, updates, details)만 반환하며, 외부 GitHub 호출을 동반합니다. 언어팩 목록의 업데이트 배지 표시에 사용합니다.


### POST /api/admin/language-packs/install-from-bundled
<!-- @generated:start:api.admin.language-packs.install-from-bundled -->
- **라우트명**: `api.admin.language-packs.install-from-bundled`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@installFromBundled`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | body | string | 예 | max 200 | 대상 확장/리소스의 식별자 |
| auto_activate | body | boolean | 아니오 | — | 설치 후 자동 활성화 여부 |

**요청 예시**

```http
POST /api/admin/language-packs/install-from-bundled HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "identifier": "example-key",
    "auto_activate": true
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
| 403 | Forbidden | 요구 권한(`core.language_packs.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** `lang-packs/_bundled/{identifier}` 디렉토리의 번들 소스에서 언어팩을 설치(또는 재설치)합니다. `core.language_packs.install` 권한이 필요합니다. `auto_activate` 가 true면 설치 후 곧바로 활성화합니다. 코어/확장에 선탑재된 번들 언어팩을 DB에 등록할 때 사용합니다.


### POST /api/admin/language-packs/install-from-file
<!-- @generated:start:api.admin.language-packs.install-from-file -->
- **라우트명**: `api.admin.language-packs.install-from-file`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@installFromFile`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 10240 | 업로드 파일 |
| auto_activate | body | boolean | 아니오 | — | 설치 후 자동 활성화 여부 |

**요청 예시**

```http
POST /api/admin/language-packs/install-from-file HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary
Content-Disposition: form-data; name="auto_activate"

1
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
| 403 | Forbidden | 요구 권한(`core.language_packs.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 업로드된 ZIP 파일에서 언어팩을 설치합니다. `core.language_packs.install` 권한이 필요합니다. manifest 검증에 실패하면 422로 응답하며, `auto_activate` 가 true면 설치 후 즉시 활성화합니다. 관리자가 로컬 ZIP 파일을 직접 업로드해 언어팩을 추가하는 화면에 사용합니다.


### POST /api/admin/language-packs/install-from-github
<!-- @generated:start:api.admin.language-packs.install-from-github -->
- **라우트명**: `api.admin.language-packs.install-from-github`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@installFromGithub`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| github_url | body | string | 예 | — | GitHub 저장소 URL |
| auto_activate | body | boolean | 아니오 | — | 설치 후 자동 활성화 여부 |

**요청 예시**

```http
POST /api/admin/language-packs/install-from-github HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "github_url": "https://example.com",
    "auto_activate": true
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
| 403 | Forbidden | 요구 권한(`core.language_packs.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** GitHub 저장소 URL에서 언어팩을 다운로드해 설치합니다. `core.language_packs.install` 권한이 필요합니다. 외부 GitHub 호출을 동반하며 manifest 검증 실패 시 422로 응답합니다. `auto_activate` 가 true면 설치 후 즉시 활성화합니다. GitHub로 배포되는 언어팩을 URL 만으로 설치할 때 사용하며, 이후 check-updates/update 로 갱신을 추적할 수 있습니다.


### POST /api/admin/language-packs/install-from-url
<!-- @generated:start:api.admin.language-packs.install-from-url -->
- **라우트명**: `api.admin.language-packs.install-from-url`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@installFromUrl`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| url | body | string | 예 | max 500 | URL |
| checksum | body | string | 아니오 | — | 무결성 검증 체크섬 (SHA-256) |
| auto_activate | body | boolean | 아니오 | — | 설치 후 자동 활성화 여부 |

**요청 예시**

```http
POST /api/admin/language-packs/install-from-url HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "url": "https://example.com",
    "checksum": "예시값",
    "auto_activate": true
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
| 403 | Forbidden | 요구 권한(`core.language_packs.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 임의의 URL에서 언어팩 ZIP을 내려받아 설치합니다. `core.language_packs.install` 권한이 필요합니다. `checksum` 을 함께 전달하면 다운로드 무결성을 검증하며, manifest 검증 실패 시 422로 응답합니다. `auto_activate` 가 true면 설치 후 즉시 활성화합니다. GitHub 외 임의 호스팅에 배포된 언어팩을 설치할 때 사용합니다.


### POST /api/admin/language-packs/manifest-preview
<!-- @generated:start:api.admin.language-packs.manifest-preview -->
- **라우트명**: `api.admin.language-packs.manifest-preview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@manifestPreview`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 5120 | 업로드 파일 |

**요청 예시**

```http
POST /api/admin/language-packs/manifest-preview HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.language_packs.install`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 업로드된 ZIP을 실제로 설치하지 않고 manifest 와 검증 결과만 미리 조회합니다. `core.language_packs.install` 권한이 필요합니다. 부수 효과 없이 읽기만 수행하며 검증 실패 시 422로 응답합니다. 설치 확인 모달에서 대상 언어팩의 메타데이터와 유효성을 미리 보여줄 때 사용합니다.


### POST /api/admin/language-packs/refresh-cache
<!-- @generated:start:api.admin.language-packs.refresh-cache -->
- **라우트명**: `api.admin.language-packs.refresh-cache`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@refreshCache`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.manage`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/language-packs/refresh-cache HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.language_packs.manage`)이 없는 경우 |

<!-- @generated:end -->

**설명** 번역/레지스트리/템플릿 언어 캐시를 무효화합니다. `core.language_packs.manage` 권한이 필요합니다. 언어팩 파일을 직접 수정했거나 활성 상태가 프론트에 반영되지 않을 때 캐시를 강제로 갱신하는 용도로 사용합니다.


### DELETE /api/admin/language-packs/{id}
<!-- @generated:start:api.admin.language-packs.uninstall -->
- **라우트명**: `api.admin.language-packs.uninstall`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@uninstall`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| cascade | query | boolean | 아니오 | — | 연쇄 처리 여부 (의존 항목 함께 처리) |

**요청 예시**

```http
DELETE /api/admin/language-packs/1?cascade=1 HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.language_packs.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 언어팩을 제거하고 설치된 파일을 삭제합니다. `core.language_packs.manage` 권한이 필요합니다. `cascade` 가 true면 연관 자원까지 함께 제거합니다. 대상이 존재하지 않으면 404로 응답합니다. 관리자 언어팩 관리 화면의 삭제(제거) 동작에 사용합니다.


### GET /api/admin/language-packs/{id}
<!-- @generated:start:api.admin.language-packs.show -->
- **라우트명**: `api.admin.language-packs.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/admin/language-packs/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| identifier | string | `apidoc-sample-lang` | 언어팩 고유 식별자 ({vendor}-{scope}-{target?}-{locale}) |
| vendor | string | `apidoc` | 언어팩 제작자 식별자 |
| scope | string | `core` | 적용 대상 분류 |
| target_identifier | null | `null` | 대상 확장 식별자 (scope=core일 때 null) |
| locale | string | `fr` | IETF BCP-47 locale 태그 |
| locale_name | string | `French` | 영문 언어명 |
| locale_native_name | string | `Français` | 원어 언어명 |
| text_direction | string | `ltr` | 텍스트 방향 |
| version | string | `1.0.0` | 언어팩 버전 |
| latest_version | string | `1.0.0` | 감지된 최신 배포 버전 |
| target_version_constraint | null | `null` | 대상 확장 버전 제약 (semver) |
| target_version_mismatch | boolean | `false` | 대상 버전 불일치 경고 플래그 |
| name | string | `API 문서 샘플 언어팩` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| license | string | `MIT` | 라이선스 |
| description | string | `문서 실측용 언어팩` | 설명 (다국어 필드는 로케일별 값 객체) |
| status | string | `active` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| is_protected | boolean | `false` | protected 여부 |
| source_type | string | `bundled` | 설치 소스 유형 (zip/github/url/bundled/bundled_with_extension) |
| origin | string | `bundled` | 출처 (설치/등록 원천 구분 값) |
| source_url | null | `null` | source URL |
| github_url | null | `null` | GitHub 저장소 URL (manifest 파생) |
| github_changelog_url | null | `null` | GitHub 변경 이력(CHANGELOG) URL (manifest 파생) |
| bundled_identifier | null | `null` | 대응하는 번들 확장 식별자 (번들 원본 매칭용) |
| install_blocked_reason | null | `null` | 설치가 차단된 사유 (차단 없으면 null) |
| target_name | null | `null` | 대상 확장의 표시 이름 (scope+target_identifier 로 해석) |
| installed_at | string | `2026-07-05 10:41:24` | installed 일시 |
| activated_at | string | `2026-07-05 10:41:24` | activated 일시 |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string | `2026-07-08 10:41:24` | 최종 수정 일시 |
| has_update | boolean | `false` | update 여부 |
| abilities | object | `{"can_activate":true,"can_deactivate":true,"can_uninstall…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |
| manifest | object | `{"name":{"en":"API Doc Sample Pack","ko":"API 문서 샘플 언어팩"}…` | language-pack.json 전체 스냅샷 |
| validation_summary | object | `{"target_version_mismatch":false,"depends_on_core_locale"…` | 검증 결과 요약 객체 (통과/실패 건수 등) |
| source_meta | object | `{"type":"bundled","url":null,"installed_by":6,"latest_ver…` | 원천 메타데이터 객체 (출처·경로·해석 정보) |
| changelog_entries | array | `[]` | 변경 이력 항목 목록 (버전별 변경 내용) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어팩 목록을 조회했습니다.",
    "data": {
        "id": 1,
        "identifier": "apidoc-sample-lang",
        "vendor": "apidoc",
        "scope": "core",
        "target_identifier": null,
        "locale": "fr",
        "locale_name": "French",
        "locale_native_name": "Français",
        "text_direction": "ltr",
        "version": "1.0.0",
        "latest_version": "1.0.0",
        "target_version_constraint": null,
        "target_version_mismatch": false,
        "name": "API 문서 샘플 언어팩",
        "license": "MIT",
        "description": "문서 실측용 언어팩",
        "status": "active",
        "is_protected": false,
        "source_type": "bundled",
        "origin": "bundled",
        "source_url": null,
        "github_url": null,
        "github_changelog_url": null,
        "bundled_identifier": null,
        "install_blocked_reason": null,
        "target_name": null,
        "installed_at": "2026-07-05 10:41:24",
        "activated_at": "2026-07-05 10:41:24",
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 10:41:24",
        "has_update": false,
        "abilities": {
            "can_activate": true,
            "can_deactivate": true,
            "can_uninstall": true
        },
        "manifest": {
            "name": {
                "en": "API Doc Sample Pack",
                "ko": "API 문서 샘플 언어팩"
            },
            "locale": "fr",
            "version": "1.0.0"
        },
        "validation_summary": {
            "target_version_mismatch": false,
            "depends_on_core_locale": null
        },
        "source_meta": {
            "type": "bundled",
            "url": null,
            "installed_by": 6,
            "latest_version": "1.0.0",
            "directory_path": "lang-packs/apidoc-sample-lang"
        },
        "changelog_entries": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 단일 언어팩의 상세 정보를 조회합니다. `core.language_packs.read` 권한이 필요합니다. `{id}` 가 정수면 DB 레코드를, 문자열(번들 식별자)이면 `lang-packs/_bundled/{id}` manifest 로 합성된 가상 행을 반환합니다. 미설치 번들 언어팩까지 상세 모달로 열람할 수 있도록 하며, 없으면 404로 응답합니다.


### POST /api/admin/language-packs/{id}/activate
<!-- @generated:start:api.admin.language-packs.activate -->
- **라우트명**: `api.admin.language-packs.activate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@activate`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/admin/language-packs/1/activate HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.language_packs.manage`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 언어팩을 활성화합니다(슬롯 스위칭). `core.language_packs.manage` 권한이 필요합니다. 동일 슬롯(scope·target·locale)에 이미 다른 활성 팩이 있으면 409(slot_conflict)로 현재/대상 팩을 함께 반환하며, 프론트는 확인 모달을 띄운 뒤 `force=true` 로 재호출해 교체합니다. 대상이 없으면 404로 응답합니다.


### GET /api/admin/language-packs/{id}/changelog
<!-- @generated:start:api.admin.language-packs.changelog -->
- **라우트명**: `api.admin.language-packs.changelog`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@changelog`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/admin/language-packs/1/changelog HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `apidoc-sample-lang` | 언어팩 고유 식별자 ({vendor}-{scope}-{target?}-{locale}) |
| entries | array | `[]` | 항목 목록 (각 원소는 대상 도메인 레코드) |
| changelog | string | `` | 변경 이력 텍스트 (원격/파일 CHANGELOG 본문) |
| has_changelog | boolean | `false` | changelog 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어팩 목록을 조회했습니다.",
    "data": {
        "identifier": "apidoc-sample-lang",
        "entries": [],
        "changelog": "",
        "has_changelog": false
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 언어팩의 CHANGELOG.md 내용을 반환합니다. `core.language_packs.read` 권한이 필요합니다. `{id}` 가 정수면 DB 레코드를, 문자열이면 번들 가상 행을 사용합니다. 파싱된 항목(entries), 원문(changelog), 존재 여부(has_changelog)를 함께 반환하며, CHANGELOG 파일이 없어도 빈 값으로 정상 응답합니다. 상세 모달의 변경 이력 탭에 사용합니다.


### POST /api/admin/language-packs/{id}/deactivate
<!-- @generated:start:api.admin.language-packs.deactivate -->
- **라우트명**: `api.admin.language-packs.deactivate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@deactivate`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/admin/language-packs/1/deactivate HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.language_packs.manage`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 활성 언어팩을 비활성화합니다. `core.language_packs.manage` 권한이 필요합니다. 해당 슬롯의 번역 적용이 해제되며, 대상이 없으면 404로 응답합니다. 관리자 언어팩 관리 화면에서 활성 팩을 끄는 동작에 사용합니다.


### POST /api/admin/language-packs/{id}/update
<!-- @generated:start:api.admin.language-packs.update -->
- **라우트명**: `api.admin.language-packs.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@performUpdate`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/admin/language-packs/1/update HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.language_packs.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** GitHub 소스 언어팩을 최신 버전으로 다시 내려받아 적용합니다. `core.language_packs.update` 권한이 필요합니다. 외부 GitHub 재다운로드와 파일 교체를 동반하며, 갱신된 언어팩 정보를 반환합니다. 대상이 없으면 404로 응답합니다. check-updates 로 업데이트가 감지된 팩을 실제로 갱신할 때 사용합니다.


