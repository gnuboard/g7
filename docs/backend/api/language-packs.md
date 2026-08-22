# Language Packs API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Language Packs 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
| id | integer | `1` | 기본 키 (내부 식별자) |
| identifier | string | `g7-core-en` | 언어팩 고유 식별자 ({vendor}-{scope}-{target?}-{locale}) |
| vendor | string | `g7` | 언어팩 제작자 식별자 |
| scope | string | `core` | 적용 대상 분류 |
| target_identifier | string | `apidoc-sample-module` | 대상 확장 식별자 (scope=core일 때 null) |
| locale | string | `en` | IETF BCP-47 locale 태그 |
| locale_name | string | `EN` | 영문 언어명 |
| locale_native_name | string | `English` | 원어 언어명 |
| text_direction | string | `ltr` | 텍스트 방향 |
| version | string | `7.0.6` | 언어팩 버전 |
| latest_version | string | `1.0.0` | 감지된 최신 배포 버전 |
| target_version_constraint | null | `null` | 대상 확장 버전 제약 (semver) |
| target_version_mismatch | boolean | `false` | 대상 버전 불일치 경고 플래그 |
| name | string | `G7 코어 일본어 언어팩` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| license | string | `MIT` | 라이선스 |
| description | string | `G7 코어 일본어 언어팩 (번들)` | 언어팩 설명 (다국어) |
| status | string | `active` | 언어팩 상태 |
| is_protected | boolean | `true` | protected 여부 |
| source_type | string | `built_in` | 설치 소스 유형 (zip/github/url/bundled/bundled_with_extension) |
| origin | string | `built_in` | 출처 (설치/등록 원천 구분 값) |
| source_url | string | `lang/en` | 설치 소스 URL 또는 경로 |
| github_url | null | `null` | GitHub 저장소 URL (manifest 파생) |
| github_changelog_url | null | `null` | GitHub 변경 이력(CHANGELOG) URL (manifest 파생) |
| bundled_identifier | string | `g7-core-ja` | 대응하는 번들 확장 식별자 (번들 원본 매칭용) |
| install_blocked_reason | string | `core_locale_missing` | 설치가 차단된 사유 (차단 없으면 null) |
| files_missing | boolean | `false` | 드리프트 여부 — active 로 기록됐으나 설치본 파일이 부재해 런타임에 조용히 ko 로 폴백하는 상태이면 true |
| bundled_source_available | boolean | `false` | 번들 소스(`lang-packs/_bundled/{identifier}`)가 실재해 재설치로 복구 가능하면 true. 설치 경로(source_type)와 무관하게 소스 실재 여부로 판정 |
| target_name | string | `Hello 모듈` | 대상 확장의 표시 이름 (scope+target_identifier 로 해석) |
| installed_at | string | `2026-07-28 22:55:00` | installed 일시 |
| activated_at | string | `2026-08-01 11:36:31` | activated 일시 |
| created_at | string | `2026-07-31 22:55:00` | 생성 일시 |
| updated_at | string | `2026-07-31 22:55:00` | 최종 수정 일시 |
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
                "version": "7.0.6",
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
                "files_missing": false,
                "bundled_source_available": false,
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
                "id": null,
                "identifier": "g7-core-ja",
                "vendor": "sirsoft",
                "scope": "core",
                "target_identifier": null,
                "locale": "ja",
                "locale_name": "Japanese",
                "locale_native_name": "日本語",
                "text_direction": "ltr",
                "version": "1.0.9",
                "latest_version": null,
                "target_version_constraint": null,
                "target_version_mismatch": false,
                "name": "G7 코어 일본어 언어팩",
                "license": "MIT",
                "description": "G7 코어 일본어 언어팩 (번들)",
                "status": "uninstalled",
                "is_protected": false,
                "source_type": "bundled",
                "origin": "bundled",
                "source_url": "g7-core-ja",
                "github_url": null,
                "github_changelog_url": null,
                "bundled_identifier": "g7-core-ja",
                "install_blocked_reason": null,
                "files_missing": false,
                "bundled_source_available": true,
                "target_name": null,
                "installed_at": null,
                "activated_at": null,
                "created_at": null,
                "updated_at": null,
                "has_update": false,
                "abilities": {
                    "can_install": true
                }
            },
            "... (총 25건 중 2건 표시)"
        ],
        "meta": {
            "total": 57,
            "active": 15,
            "installed": 1,
            "inactive": 0,
            "error": 0,
            "uninstalled": 9,
            "current_page": 1,
            "last_page": 3,
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

_단건 응답: `data` 객체의 필드 (`LanguagePackService::bulkActivate()` 반환값)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| succeeded | array | `[3, 5]` | 활성화에 성공한 언어팩 ID 목록 |
| failed | array | `[{"id": 7, "reason": "not_found"}]` | 활성화에 실패한 항목 목록 (요소: `id`, `reason`) |
| failed[].id | integer\|string | `7` | 실패한 항목의 요청 ID (요청 `ids` 원본 값) |
| failed[].reason | string | `not_found` | 실패 사유. 대상 미존재 시 `not_found`, 그 외에는 발생한 예외 메시지 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "language_packs.bulk_activate_success",
    "data": {
        "succeeded": [
            3,
            5
        ],
        "failed": [
            {
                "id": 7,
                "reason": "not_found"
            }
        ]
    }
}
```

> `message` 는 `language_packs.bulk_activate_success` 키로 응답하지만, 현재 `lang/ko/language_packs.php` 에 해당 키가 정의되어 있지 않아 번역되지 않은 키 문자열이 그대로 내려갑니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
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

_단건 응답: `data` 객체의 필드 (`LanguagePackService::checkUpdates()` 반환값)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| checked | integer | `3` | 실제로 검사한 언어팩 수 (GitHub URL 해석 가능하거나 번들 매니페스트가 있는 팩만 대상) |
| updates | integer | `1` | 검사 결과 업데이트가 있는 언어팩 수 |
| details | array | `[{"identifier": "g7-core-ja", "current": "1.0.0", "latest": "1.1.0", "has_update": true, "error": null}]` | 팩별 검사 결과 목록 |
| details[].identifier | string | `g7-core-ja` | 검사 대상 언어팩 식별자 |
| details[].current | string | `1.0.0` | 현재 설치된 버전 |
| details[].latest | string\|null | `1.1.0` | 감지된 최신 버전 (GitHub 릴리스 태그 → 실패 시 번들 매니페스트 폴백, 둘 다 없으면 null) |
| details[].has_update | boolean | `true` | `latest > current` 여부 |
| details[].error | string\|null | `null` | GitHub 조회 중 발생한 오류 메시지 (성공 시 null) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "업데이트 확인을 완료했습니다.",
    "data": {
        "checked": 3,
        "updates": 1,
        "details": [
            {
                "identifier": "g7-core-ja",
                "current": "1.0.0",
                "latest": "1.1.0",
                "has_update": true,
                "error": null
            },
            {
                "identifier": "apidoc-sample-lang",
                "current": "1.0.0",
                "latest": "1.0.0",
                "has_update": false,
                "error": null
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 업데이트 확인 중 예외 발생 (`업데이트 확인에 실패했습니다.` — `language_packs.check_updates_failed`) |

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
| auto_activate | body | boolean | 아니오 | — | 설치 후 자동 활성화 여부. 이 값을 참으로 보내려면 `core.language_packs.manage` 권한이 함께 필요하며, 없으면 이 항목만 422 로 거부됩니다 — 네 설치 경로(파일·URL·GitHub·번들) 모두 동일한 경계를 적용합니다. 관리자 화면의 번들 재설치는 활성화 권한이 없는 운영자에게 이 값을 보내지 않으므로 재설치 자체는 그대로 진행되며, 다만 대상 언어팩이 `installed` 로 내려가 다시 켜려면 관리 권한이 필요합니다 |

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

_단건 응답: `data` 객체의 필드 (설치된 언어팩 = `LanguagePackResource::toArray()`, 상태코드 201)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| identifier | string | `g7-core-ja` | 언어팩 고유 식별자 ({vendor}-{scope}-{target?}-{locale}) |
| vendor | string | `g7` | 언어팩 제작자 식별자 |
| scope | string | `core` | 적용 대상 분류 (core/module/plugin/template) |
| target_identifier | string\|null | `null` | 대상 확장 식별자 (scope=core 일 때 null) |
| locale | string | `ja` | IETF BCP-47 locale 태그 |
| locale_name | string | `Japanese` | 영문 언어명 |
| locale_native_name | string | `日本語` | 원어 언어명 |
| text_direction | string | `ltr` | 텍스트 방향 (ltr/rtl) |
| version | string | `1.0.0` | 설치된 언어팩 버전 |
| latest_version | string\|null | `1.0.0` | 감지된 최신 배포 버전 |
| target_version_constraint | string\|null | `null` | 대상 확장 버전 제약 (semver) |
| target_version_mismatch | boolean | `false` | 대상 버전 불일치 경고 플래그 |
| name | string\|null | `일본어 언어팩` | manifest `name` 의 현재 로케일 해석값 |
| license | string\|null | `MIT` | 라이선스 |
| description | string\|null | `코어 일본어 번역` | 언어팩 설명 (다국어) |
| status | string | `active` | 언어팩 상태 (auto_activate=true 면 active, 아니면 installed) |
| is_protected | boolean | `false` | 보호(protected) 팩 여부 |
| source_type | string | `bundled` | 설치 소스 유형 (zip/github/url/bundled/bundled_with_extension/built_in) |
| origin | string\|null | `bundled` | source_type 에서 파생된 출처 값 (`LanguagePackOrigin`) |
| source_url | string\|null | `null` | 설치 소스 URL 또는 경로 |
| github_url | string\|null | `null` | GitHub 저장소 URL (manifest 파생) |
| github_changelog_url | string\|null | `null` | GitHub 변경 이력(CHANGELOG) URL (manifest 파생) |
| bundled_identifier | string\|null | `null` | 대응하는 번들 확장 식별자 (번들 원본 매칭용) |
| install_blocked_reason | string\|null | `null` | 설치가 차단된 사유 (차단 없으면 null) |
| target_name | string\|null | `null` | 대상 확장의 표시 이름 (scope+target_identifier 로 해석) |
| installed_at | string\|null | `2026-07-05 10:41:24` | 설치 일시 (사용자 타임존) |
| activated_at | string\|null | `2026-07-05 10:41:24` | 활성화 일시 (사용자 타임존) |
| created_at | string\|null | `2026-07-05 10:41:24` | 생성 일시 |
| updated_at | string\|null | `2026-07-05 10:41:24` | 최종 수정 일시 |
| has_update | boolean | `false` | `latest_version > version` 여부 |
| abilities | object | `{"can_activate":true,"can_deactivate":true,"can_uninstall":true}` | 현재 사용자가 이 언어팩에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "언어팩을 설치했습니다.",
    "data": {
        "id": 1,
        "identifier": "g7-core-ja",
        "vendor": "g7",
        "scope": "core",
        "target_identifier": null,
        "locale": "ja",
        "locale_name": "Japanese",
        "locale_native_name": "日本語",
        "text_direction": "ltr",
        "version": "1.0.0",
        "latest_version": "1.0.0",
        "target_version_constraint": null,
        "target_version_mismatch": false,
        "name": "일본어 언어팩",
        "license": "MIT",
        "description": "코어 일본어 번역",
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
        "created_at": "2026-07-05 10:41:24",
        "updated_at": "2026-07-05 10:41:24",
        "has_update": false,
        "abilities": {
            "can_activate": true,
            "can_deactivate": true,
            "can_uninstall": true
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

**설명** `lang-packs/_bundled/{identifier}` 디렉토리의 번들 소스에서 언어팩을 설치(또는 재설치)합니다. `core.language_packs.install` 권한이 필요합니다. 파일 형식·PHP 내용 제약은 ZIP 업로드와 동일합니다. `auto_activate` 가 true면 설치 후 곧바로 활성화하며, 이때는 `core.language_packs.manage` 권한이 함께 필요합니다. 활성 언어팩을 재설치하면서 이 값을 보내지 않으면 대상이 `installed` 로 내려갑니다 — 설치 트랜잭션이 항상 `installed` 로만 기록하고 활성화는 별도 단계로 분리되어 있기 때문입니다. 코어/확장에 선탑재된 번들 언어팩을 DB에 등록할 때 사용합니다.


### POST /api/admin/language-packs/install-from-file
<!-- @generated:start:api.admin.language-packs.install-from-file -->
- **라우트명**: `api.admin.language-packs.install-from-file`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@installFromFile`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 10240 | 업로드 파일 |
| auto_activate | body | boolean | 아니오 | — | 설치 후 자동 활성화 여부. 외부 소스(파일·URL·GitHub)에서 설치할 때 이 값을 참으로 보내려면 `core.language_packs.manage` 권한이 함께 필요하며, 없으면 이 항목만 422 로 거부됩니다 — 항목을 빼면 설치는 그대로 진행되고 상태는 `installed` 로 남습니다. 설치(install)와 활성화(manage)를 별도 권한으로 두는 정책을 따르기 위함입니다 |

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

_단건 응답: `data` 객체의 필드 (설치된 언어팩 = `LanguagePackResource::toArray()`, 상태코드 201). 필드 구성은 [install-from-bundled](#post-apiadminlanguage-packsinstall-from-bundled) 응답과 동일합니다 — `source_type` 은 `zip`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `2` | 기본 키 (내부 식별자) |
| identifier | string | `acme-core-de` | 언어팩 고유 식별자 |
| vendor | string | `acme` | 언어팩 제작자 식별자 |
| scope | string | `core` | 적용 대상 분류 (core/module/plugin/template) |
| target_identifier | string\|null | `null` | 대상 확장 식별자 (scope=core 일 때 null) |
| locale | string | `de` | IETF BCP-47 locale 태그 |
| locale_name | string | `German` | 영문 언어명 |
| locale_native_name | string | `Deutsch` | 원어 언어명 |
| text_direction | string | `ltr` | 텍스트 방향 (ltr/rtl) |
| version | string | `1.0.0` | 설치된 언어팩 버전 |
| latest_version | string\|null | `null` | 감지된 최신 배포 버전 |
| target_version_constraint | string\|null | `null` | 대상 확장 버전 제약 (semver) |
| target_version_mismatch | boolean | `false` | 대상 버전 불일치 경고 플래그 |
| name | string\|null | `독일어 언어팩` | manifest `name` 의 현재 로케일 해석값 |
| license | string\|null | `MIT` | 라이선스 |
| description | string\|null | `—` | 언어팩 설명 (다국어) |
| status | string | `installed` | 언어팩 상태 (auto_activate=true 면 active) |
| is_protected | boolean | `false` | 보호(protected) 팩 여부 |
| source_type | string | `zip` | 설치 소스 유형 (파일 업로드 설치이므로 zip) |
| origin | string\|null | `zip` | source_type 에서 파생된 출처 값 |
| source_url | string\|null | `null` | 설치 소스 URL 또는 경로 |
| github_url | string\|null | `null` | GitHub 저장소 URL (manifest 파생) |
| github_changelog_url | string\|null | `null` | GitHub 변경 이력(CHANGELOG) URL (manifest 파생) |
| bundled_identifier | string\|null | `null` | 대응하는 번들 확장 식별자 |
| install_blocked_reason | string\|null | `null` | 설치가 차단된 사유 (차단 없으면 null) |
| target_name | string\|null | `null` | 대상 확장의 표시 이름 |
| installed_at | string\|null | `2026-07-05 10:41:24` | 설치 일시 (사용자 타임존) |
| activated_at | string\|null | `null` | 활성화 일시 (미활성 시 null) |
| created_at | string\|null | `2026-07-05 10:41:24` | 생성 일시 |
| updated_at | string\|null | `2026-07-05 10:41:24` | 최종 수정 일시 |
| has_update | boolean | `false` | `latest_version > version` 여부 |
| abilities | object | `{"can_activate":true,"can_deactivate":true,"can_uninstall":true}` | 현재 사용자가 이 언어팩에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "언어팩을 설치했습니다.",
    "data": {
        "id": 2,
        "identifier": "acme-core-de",
        "vendor": "acme",
        "scope": "core",
        "target_identifier": null,
        "locale": "de",
        "locale_name": "German",
        "locale_native_name": "Deutsch",
        "text_direction": "ltr",
        "version": "1.0.0",
        "latest_version": null,
        "target_version_constraint": null,
        "target_version_mismatch": false,
        "name": "독일어 언어팩",
        "license": "MIT",
        "description": null,
        "status": "installed",
        "is_protected": false,
        "source_type": "zip",
        "origin": "zip",
        "source_url": null,
        "github_url": null,
        "github_changelog_url": null,
        "bundled_identifier": null,
        "install_blocked_reason": null,
        "target_name": null,
        "installed_at": "2026-07-05 10:41:24",
        "activated_at": null,
        "created_at": "2026-07-05 10:41:24",
        "updated_at": "2026-07-05 10:41:24",
        "has_update": false,
        "abilities": {
            "can_activate": true,
            "can_deactivate": true,
            "can_uninstall": true
        }
    }
}
```

> manifest 검증 실패 시 422 (`language-pack.json 검증에 실패했습니다.`), 그 외 설치 실패 시 500 (`언어팩 설치에 실패했습니다.`) 으로 응답합니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 업로드된 ZIP 파일에서 언어팩을 설치합니다. `core.language_packs.install` 권한이 필요합니다. manifest 검증에 실패하면 422로 응답합니다. 패키지에 담을 수 있는 파일은 `.json`/`.php`/`.md` 뿐이며, PHP 파일은 `backend/{언어코드}/{그룹}.php` 위치에 번역 배열만 담고 있어야 합니다 — 그 밖의 코드(함수 호출·변수·닫는 태그 뒤 코드 등)가 있으면 위반 줄 번호와 함께 거부됩니다. 심볼릭 링크도 거부됩니다. `auto_activate` 가 true면 설치 후 즉시 활성화하며, 이때는 `core.language_packs.manage` 권한이 함께 필요합니다. 관리자가 로컬 ZIP 파일을 직접 업로드해 언어팩을 추가하는 화면에 사용합니다.


### POST /api/admin/language-packs/install-from-github
<!-- @generated:start:api.admin.language-packs.install-from-github -->
- **라우트명**: `api.admin.language-packs.install-from-github`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@installFromGithub`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| github_url | body | string | 예 | — | GitHub 저장소 URL |
| auto_activate | body | boolean | 아니오 | — | 설치 후 자동 활성화 여부. 외부 소스(파일·URL·GitHub)에서 설치할 때 이 값을 참으로 보내려면 `core.language_packs.manage` 권한이 함께 필요하며, 없으면 이 항목만 422 로 거부됩니다 — 항목을 빼면 설치는 그대로 진행되고 상태는 `installed` 로 남습니다. 설치(install)와 활성화(manage)를 별도 권한으로 두는 정책을 따르기 위함입니다 |

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

_단건 응답: `data` 객체의 필드 (설치된 언어팩 = `LanguagePackResource::toArray()`, 상태코드 201). 필드 구성은 install-from-bundled 응답과 동일하며 `source_type` 이 `github`, `source_url`/`github_url` 에 저장소 URL 이 들어갑니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `3` | 기본 키 (내부 식별자) |
| identifier | string | `acme-core-es` | 언어팩 고유 식별자 |
| vendor | string | `acme` | 언어팩 제작자 식별자 |
| scope | string | `core` | 적용 대상 분류 (core/module/plugin/template) |
| target_identifier | string\|null | `null` | 대상 확장 식별자 (scope=core 일 때 null) |
| locale | string | `es` | IETF BCP-47 locale 태그 |
| locale_name | string | `Spanish` | 영문 언어명 |
| locale_native_name | string | `Español` | 원어 언어명 |
| text_direction | string | `ltr` | 텍스트 방향 (ltr/rtl) |
| version | string | `1.0.0` | 설치된 언어팩 버전 (GitHub 최신 릴리스 태그 기준) |
| latest_version | string\|null | `1.0.0` | 감지된 최신 배포 버전 |
| target_version_constraint | string\|null | `null` | 대상 확장 버전 제약 (semver) |
| target_version_mismatch | boolean | `false` | 대상 버전 불일치 경고 플래그 |
| name | string\|null | `스페인어 언어팩` | manifest `name` 의 현재 로케일 해석값 |
| license | string\|null | `MIT` | 라이선스 |
| description | string\|null | `—` | 언어팩 설명 (다국어) |
| status | string | `active` | 언어팩 상태 (auto_activate=true 면 active, 아니면 installed) |
| is_protected | boolean | `false` | 보호(protected) 팩 여부 |
| source_type | string | `github` | 설치 소스 유형 |
| origin | string\|null | `github` | source_type 에서 파생된 출처 값 |
| source_url | string\|null | `https://github.com/acme/g7-core-es` | 설치에 사용한 GitHub 저장소 URL |
| github_url | string\|null | `https://github.com/acme/g7-core-es` | GitHub 저장소 URL (manifest 파생) |
| github_changelog_url | string\|null | `null` | GitHub 변경 이력(CHANGELOG) URL (manifest 파생) |
| bundled_identifier | string\|null | `null` | 대응하는 번들 확장 식별자 |
| install_blocked_reason | string\|null | `null` | 설치가 차단된 사유 (차단 없으면 null) |
| target_name | string\|null | `null` | 대상 확장의 표시 이름 |
| installed_at | string\|null | `2026-07-05 10:41:24` | 설치 일시 (사용자 타임존) |
| activated_at | string\|null | `2026-07-05 10:41:24` | 활성화 일시 |
| created_at | string\|null | `2026-07-05 10:41:24` | 생성 일시 |
| updated_at | string\|null | `2026-07-05 10:41:24` | 최종 수정 일시 |
| has_update | boolean | `false` | `latest_version > version` 여부 |
| abilities | object | `{"can_activate":true,"can_deactivate":true,"can_uninstall":true}` | 현재 사용자가 이 언어팩에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "언어팩을 설치했습니다.",
    "data": {
        "id": 3,
        "identifier": "acme-core-es",
        "vendor": "acme",
        "scope": "core",
        "target_identifier": null,
        "locale": "es",
        "locale_name": "Spanish",
        "locale_native_name": "Español",
        "text_direction": "ltr",
        "version": "1.0.0",
        "latest_version": "1.0.0",
        "target_version_constraint": null,
        "target_version_mismatch": false,
        "name": "스페인어 언어팩",
        "license": "MIT",
        "description": null,
        "status": "active",
        "is_protected": false,
        "source_type": "github",
        "origin": "github",
        "source_url": "https://github.com/acme/g7-core-es",
        "github_url": "https://github.com/acme/g7-core-es",
        "github_changelog_url": null,
        "bundled_identifier": null,
        "install_blocked_reason": null,
        "target_name": null,
        "installed_at": "2026-07-05 10:41:24",
        "activated_at": "2026-07-05 10:41:24",
        "created_at": "2026-07-05 10:41:24",
        "updated_at": "2026-07-05 10:41:24",
        "has_update": false,
        "abilities": {
            "can_activate": true,
            "can_deactivate": true,
            "can_uninstall": true
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

**설명** GitHub 저장소 URL에서 언어팩을 다운로드해 설치합니다. `core.language_packs.install` 권한이 필요합니다. 외부 GitHub 호출을 동반하며 manifest 검증 실패 시 422로 응답합니다. 파일 형식·PHP 내용 제약은 ZIP 업로드와 동일합니다. `auto_activate` 가 true면 설치 후 즉시 활성화하며, 이때는 `core.language_packs.manage` 권한이 함께 필요합니다. GitHub로 배포되는 언어팩을 URL 만으로 설치할 때 사용하며, 이후 check-updates/update 로 갱신을 추적할 수 있습니다.


### POST /api/admin/language-packs/install-from-url
<!-- @generated:start:api.admin.language-packs.install-from-url -->
- **라우트명**: `api.admin.language-packs.install-from-url`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LanguagePackController@installFromUrl`
- **인증/권한**: `auth:sanctum` + `permission:core.language_packs.install`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| url | body | string | 예 | max 500 | 언어팩 ZIP 다운로드 URL. `https` 만 허용하며, 내부 네트워크 주소(사설 IP·루프백·`localhost`·`*.internal` 등)와 userinfo(`https://a@b/`) 위장 주소는 422 로 거부됩니다 — 서버가 대신 내려받는 요청이므로 내부망 접근을 막기 위함(SSRF). 원격 코드를 가져오는 경로라 `security.allow_internal_outbound_urls` 설정을 켜도 내부 주소는 계속 차단됩니다 |
| checksum | body | string | 아니오 | — | 무결성 검증 체크섬 (SHA-256) |
| auto_activate | body | boolean | 아니오 | — | 설치 후 자동 활성화 여부. 외부 소스(파일·URL·GitHub)에서 설치할 때 이 값을 참으로 보내려면 `core.language_packs.manage` 권한이 함께 필요하며, 없으면 이 항목만 422 로 거부됩니다 — 항목을 빼면 설치는 그대로 진행되고 상태는 `installed` 로 남습니다. 설치(install)와 활성화(manage)를 별도 권한으로 두는 정책을 따르기 위함입니다 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.language_packs.install_from_url_validation_rules`).

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

_단건 응답: `data` 객체의 필드 (설치된 언어팩 = `LanguagePackResource::toArray()`, 상태코드 201). 필드 구성은 install-from-bundled 응답과 동일하며 `source_type` 이 `url`, `source_url` 에 다운로드 URL 이 들어갑니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `4` | 기본 키 (내부 식별자) |
| identifier | string | `acme-core-it` | 언어팩 고유 식별자 |
| vendor | string | `acme` | 언어팩 제작자 식별자 |
| scope | string | `core` | 적용 대상 분류 (core/module/plugin/template) |
| target_identifier | string\|null | `null` | 대상 확장 식별자 (scope=core 일 때 null) |
| locale | string | `it` | IETF BCP-47 locale 태그 |
| locale_name | string | `Italian` | 영문 언어명 |
| locale_native_name | string | `Italiano` | 원어 언어명 |
| text_direction | string | `ltr` | 텍스트 방향 (ltr/rtl) |
| version | string | `1.0.0` | 설치된 언어팩 버전 |
| latest_version | string\|null | `null` | 감지된 최신 배포 버전 |
| target_version_constraint | string\|null | `null` | 대상 확장 버전 제약 (semver) |
| target_version_mismatch | boolean | `false` | 대상 버전 불일치 경고 플래그 |
| name | string\|null | `이탈리아어 언어팩` | manifest `name` 의 현재 로케일 해석값 |
| license | string\|null | `MIT` | 라이선스 |
| description | string\|null | `—` | 언어팩 설명 (다국어) |
| status | string | `active` | 언어팩 상태 (auto_activate=true 면 active, 아니면 installed) |
| is_protected | boolean | `false` | 보호(protected) 팩 여부 |
| source_type | string | `url` | 설치 소스 유형 |
| origin | string\|null | `url` | source_type 에서 파생된 출처 값 |
| source_url | string\|null | `https://cdn.example.com/acme-core-it.zip` | 다운로드에 사용한 URL |
| github_url | string\|null | `null` | GitHub 저장소 URL (manifest 파생) |
| github_changelog_url | string\|null | `null` | GitHub 변경 이력(CHANGELOG) URL (manifest 파생) |
| bundled_identifier | string\|null | `null` | 대응하는 번들 확장 식별자 |
| install_blocked_reason | string\|null | `null` | 설치가 차단된 사유 (차단 없으면 null) |
| target_name | string\|null | `null` | 대상 확장의 표시 이름 |
| installed_at | string\|null | `2026-07-05 10:41:24` | 설치 일시 (사용자 타임존) |
| activated_at | string\|null | `2026-07-05 10:41:24` | 활성화 일시 |
| created_at | string\|null | `2026-07-05 10:41:24` | 생성 일시 |
| updated_at | string\|null | `2026-07-05 10:41:24` | 최종 수정 일시 |
| has_update | boolean | `false` | `latest_version > version` 여부 |
| abilities | object | `{"can_activate":true,"can_deactivate":true,"can_uninstall":true}` | 현재 사용자가 이 언어팩에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "언어팩을 설치했습니다.",
    "data": {
        "id": 4,
        "identifier": "acme-core-it",
        "vendor": "acme",
        "scope": "core",
        "target_identifier": null,
        "locale": "it",
        "locale_name": "Italian",
        "locale_native_name": "Italiano",
        "text_direction": "ltr",
        "version": "1.0.0",
        "latest_version": null,
        "target_version_constraint": null,
        "target_version_mismatch": false,
        "name": "이탈리아어 언어팩",
        "license": "MIT",
        "description": null,
        "status": "active",
        "is_protected": false,
        "source_type": "url",
        "origin": "url",
        "source_url": "https://cdn.example.com/acme-core-it.zip",
        "github_url": null,
        "github_changelog_url": null,
        "bundled_identifier": null,
        "install_blocked_reason": null,
        "target_name": null,
        "installed_at": "2026-07-05 10:41:24",
        "activated_at": "2026-07-05 10:41:24",
        "created_at": "2026-07-05 10:41:24",
        "updated_at": "2026-07-05 10:41:24",
        "has_update": false,
        "abilities": {
            "can_activate": true,
            "can_deactivate": true,
            "can_uninstall": true
        }
    }
}
```

> `checksum` 불일치 시 500 (`체크섬이 일치하지 않습니다.`), manifest 검증 실패 시 422 로 응답합니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
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

_단건 응답: `data` 객체의 필드 (`LanguagePackService::previewManifest()` 반환값)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| manifest | object | `{"identifier":"acme-core-de","locale":"de","version":"1.0.0"}` | ZIP 안 `language-pack.json` 원본 전체 (파싱된 객체) |
| validation | object | `{"errors":[],"is_valid":true,...}` | manifest 검증 결과 요약 |
| validation.errors | array | `[]` | 검증 실패 메시지 목록 (통과 시 빈 배열) |
| validation.is_valid | boolean | `true` | 검증 통과 여부 (`errors` 가 비어 있으면 true) |
| validation.already_installed | boolean | `false` | 동일 `identifier` 언어팩이 이미 설치되어 있는지 여부 |
| validation.existing_version | string\|null | `null` | 이미 설치되어 있다면 그 버전 (없으면 null) |
| validation.target_version_mismatch | boolean | `false` | 대상 확장 버전이 manifest 요구 조건과 어긋나는지 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "manifest 미리보기를 완료했습니다.",
    "data": {
        "manifest": {
            "identifier": "acme-core-de",
            "vendor": "acme",
            "scope": "core",
            "locale": "de",
            "version": "1.0.0",
            "name": {
                "ko": "독일어 언어팩",
                "en": "German Language Pack"
            },
            "license": "MIT"
        },
        "validation": {
            "errors": [],
            "is_valid": true,
            "already_installed": false,
            "existing_version": null,
            "target_version_mismatch": false
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

**설명** 업로드된 ZIP을 실제로 설치하지 않고 manifest 와 검증 결과만 미리 조회합니다. `core.language_packs.install` 권한이 필요합니다. 부수 효과 없이 읽기만 수행하며 검증 실패 시 422로 응답합니다. 설치 확인 모달에서 대상 언어팩의 메타데이터와 유효성을 미리 보여줄 때 사용합니다.

검증 실패 응답에는 예외 원문이 실리지 않습니다. 상세 진단 정보는 `app.debug` 가 켜진 환경에서만 `debug` 필드로 노출됩니다 — 이 규칙은 이 컨트롤러의 모든 실패 응답(설치·활성화·비활성화·삭제·업데이트 확인·업데이트 수행·캐시 갱신·목록 조회)에 동일하게 적용됩니다.


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

_단건 응답: `data` 객체의 필드 (`LanguagePackService::refreshCache()` 반환값 — 각 캐시 계층의 무효화 성공 여부)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| registry | boolean | `true` | 언어팩 레지스트리 캐시 무효화 성공 여부 |
| translator | boolean | `true` | Laravel 번역기(loaded 번역 배열) 초기화 성공 여부 |
| template | boolean | `true` | 템플릿 언어(`template-lang`) 캐시 플러시 성공 여부 |
| version | boolean | `true` | 프론트엔드 cache busting 용 확장 캐시 버전 증가 성공 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어팩 캐시를 갱신했습니다.",
    "data": {
        "registry": true,
        "translator": true,
        "template": true,
        "version": true
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
DELETE /api/admin/language-packs/{id}?cascade=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — 컨트롤러가 `success('language_packs.uninstall_success')` 만 호출하므로 `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어팩을 제거했습니다.",
    "data": null
}
```

> 보호된 언어팩(`is_protected`)은 제거할 수 없으며, 이 경우 500 (`언어팩 제거에 실패했습니다: 보호된 언어팩은 비활성화/제거할 수 없습니다.`) 으로 응답합니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
GET /api/admin/language-packs/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`LanguagePackResource::toDetailArray`). 목록 응답(`GET /api/admin/language-packs`)의 항목 필드에 아래 상세 전용 필드가 더해집니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| manifest | object\|null | `{"identifier":"g7-core-ja","locale":"ja","version":"1.0.6"}` | 언어팩 `language-pack.json` 원문 |
| validation_summary | object | — | 설치 정합성 요약 |
| validation_summary.target_version_mismatch | boolean | `false` | 대상(코어/확장) 버전과 언어팩이 맞지 않는지 여부 |
| validation_summary.depends_on_core_locale | string\|null | `ja` | manifest `requires.depends_on_core_locale` — 이 팩이 전제하는 코어 로케일 |
| source_meta | object | — | 설치 출처 메타 |
| source_meta.type | string | `zip` | 설치 방식 (`zip`, `github`, `url`, `bundled`) |
| source_meta.url | string\|null | `null` | 원격 설치 시 원본 주소 |
| source_meta.installed_by | integer\|null | `1` | 설치를 수행한 사용자 ID |
| source_meta.latest_version | string\|null | `1.0.6` | 업데이트 확인으로 파악된 최신 버전 |
| source_meta.directory_path | string\|null | `lang-packs/g7-core-ja` | 프로젝트 루트 기준 설치 디렉토리 경로 (해석 실패 시 `null`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어팩 목록을 조회했습니다.",
    "data": {
        "identifier": "g7-core-ja",
        "locale": "ja",
        "version": "1.0.6",
        "status": "active",
        "manifest": {
            "identifier": "g7-core-ja",
            "locale": "ja",
            "version": "1.0.6"
        },
        "validation_summary": {
            "target_version_mismatch": false,
            "depends_on_core_locale": null
        },
        "source_meta": {
            "type": "bundled",
            "url": null,
            "installed_by": 1,
            "latest_version": "1.0.6",
            "directory_path": "lang-packs/g7-core-ja"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
POST /api/admin/language-packs/{id}/activate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (활성화된 언어팩 = `LanguagePackResource::toArray()`). 필드 구성은 [GET /api/admin/language-packs/{id}](#get-apiadminlanguage-packsid) 의 `manifest`/`validation_summary`/`source_meta`/`changelog_entries` 4개를 제외한 나머지와 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| identifier | string | `apidoc-sample-lang` | 언어팩 고유 식별자 |
| vendor | string | `apidoc` | 언어팩 제작자 식별자 |
| scope | string | `core` | 적용 대상 분류 (core/module/plugin/template) |
| target_identifier | string\|null | `null` | 대상 확장 식별자 (scope=core 일 때 null) |
| locale | string | `fr` | IETF BCP-47 locale 태그 |
| locale_name | string | `French` | 영문 언어명 |
| locale_native_name | string | `Français` | 원어 언어명 |
| text_direction | string | `ltr` | 텍스트 방향 (ltr/rtl) |
| version | string | `1.0.0` | 언어팩 버전 |
| latest_version | string\|null | `1.0.0` | 감지된 최신 배포 버전 |
| target_version_constraint | string\|null | `null` | 대상 확장 버전 제약 (semver) |
| target_version_mismatch | boolean | `false` | 대상 버전 불일치 경고 플래그 |
| name | string\|null | `API 문서 샘플 언어팩` | manifest `name` 의 현재 로케일 해석값 |
| license | string\|null | `MIT` | 라이선스 |
| description | string\|null | `문서 실측용 언어팩` | 언어팩 설명 (다국어) |
| status | string | `active` | 활성화 후 상태 (`active`) |
| is_protected | boolean | `false` | 보호(protected) 팩 여부 |
| source_type | string | `bundled` | 설치 소스 유형 |
| origin | string\|null | `bundled` | source_type 에서 파생된 출처 값 |
| source_url | string\|null | `null` | 설치 소스 URL 또는 경로 |
| github_url | string\|null | `null` | GitHub 저장소 URL (manifest 파생) |
| github_changelog_url | string\|null | `null` | GitHub 변경 이력(CHANGELOG) URL (manifest 파생) |
| bundled_identifier | string\|null | `null` | 대응하는 번들 확장 식별자 |
| install_blocked_reason | string\|null | `null` | 설치가 차단된 사유 (차단 없으면 null) |
| target_name | string\|null | `null` | 대상 확장의 표시 이름 |
| installed_at | string\|null | `2026-07-05 10:41:24` | 설치 일시 (사용자 타임존) |
| activated_at | string\|null | `2026-07-05 10:41:24` | 활성화 일시 (사용자 타임존) |
| created_at | string\|null | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string\|null | `2026-07-08 10:41:24` | 최종 수정 일시 |
| has_update | boolean | `false` | `latest_version > version` 여부 |
| abilities | object | `{"can_activate":true,"can_deactivate":true,"can_uninstall":true}` | 현재 사용자가 이 언어팩에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어팩을 활성화했습니다.",
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
        }
    }
}
```

**슬롯 충돌(409) 응답**

동일 슬롯(scope·target·locale)에 다른 활성 팩이 있고 `force=true` 가 아니면 409 로 응답하며, `errors` 에 현재 팩(`current`)과 대상 팩(`target`)이 위 필드 구성 그대로 담깁니다.

```json
{
    "success": false,
    "message": "같은 슬롯에 이미 활성 언어팩(:current)이 있습니다. (:target)으로 교체하려면 확인이 필요합니다.",
    "errors": {
        "current": { "id": 1, "identifier": "apidoc-sample-lang", "status": "active" },
        "target": { "id": 2, "identifier": "acme-core-fr", "status": "installed" }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
GET /api/admin/language-packs/{id}/changelog HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. `{id}` 가 정수면 설치된 언어팩을, 문자열(번들 식별자)이면 `lang-packs/_bundled/{id}` 를 대상으로 합니다. `CHANGELOG.md` 가 없으면 예외가 아니라 빈 결과로 응답합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| identifier | string | `g7-core-ja` | 대상 언어팩 식별자 |
| entries | array | `[{"version":"1.0.6","date":"2026-08-12","changes":{"Added":["..."]}}]` | `CHANGELOG.md` 를 파싱한 버전별 항목 목록 (Keep a Changelog 기준). 파일이 없으면 빈 배열 |
| changelog | string | `"# Changelog\n\n## [1.0.6] - 2026-08-12\n..."` | `CHANGELOG.md` 원문. 파일이 없으면 빈 문자열 |
| has_changelog | boolean | `true` | 표시할 변경 이력이 있는지 여부 (`entries` 가 비어 있지 않거나 원문이 빈 문자열이 아니면 `true`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어팩 목록을 조회했습니다.",
    "data": {
        "identifier": "g7-core-ja",
        "entries": [
            {
                "version": "1.0.6",
                "date": "2026-08-12",
                "changes": {
                    "Added": ["배송지 변경 오류 안내 문구의 일본어 번역을 추가했습니다."]
                }
            }
        ],
        "changelog": "# Changelog\n\n## [1.0.6] - 2026-08-12\n\n### Added\n\n- ...\n",
        "has_changelog": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
POST /api/admin/language-packs/{id}/deactivate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (비활성화된 언어팩 = `LanguagePackResource::toArray()`). 필드 구성은 activate 응답과 동일하며 `status` 만 `inactive` 로 바뀝니다 (`activated_at` 은 마지막 활성화 시각 그대로 유지)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| identifier | string | `apidoc-sample-lang` | 언어팩 고유 식별자 |
| vendor | string | `apidoc` | 언어팩 제작자 식별자 |
| scope | string | `core` | 적용 대상 분류 (core/module/plugin/template) |
| target_identifier | string\|null | `null` | 대상 확장 식별자 (scope=core 일 때 null) |
| locale | string | `fr` | IETF BCP-47 locale 태그 |
| locale_name | string | `French` | 영문 언어명 |
| locale_native_name | string | `Français` | 원어 언어명 |
| text_direction | string | `ltr` | 텍스트 방향 (ltr/rtl) |
| version | string | `1.0.0` | 언어팩 버전 |
| latest_version | string\|null | `1.0.0` | 감지된 최신 배포 버전 |
| target_version_constraint | string\|null | `null` | 대상 확장 버전 제약 (semver) |
| target_version_mismatch | boolean | `false` | 대상 버전 불일치 경고 플래그 |
| name | string\|null | `API 문서 샘플 언어팩` | manifest `name` 의 현재 로케일 해석값 |
| license | string\|null | `MIT` | 라이선스 |
| description | string\|null | `문서 실측용 언어팩` | 언어팩 설명 (다국어) |
| status | string | `inactive` | 비활성화 후 상태 |
| is_protected | boolean | `false` | 보호(protected) 팩 여부 (보호 팩은 비활성화 불가) |
| source_type | string | `bundled` | 설치 소스 유형 |
| origin | string\|null | `bundled` | source_type 에서 파생된 출처 값 |
| source_url | string\|null | `null` | 설치 소스 URL 또는 경로 |
| github_url | string\|null | `null` | GitHub 저장소 URL (manifest 파생) |
| github_changelog_url | string\|null | `null` | GitHub 변경 이력(CHANGELOG) URL (manifest 파생) |
| bundled_identifier | string\|null | `null` | 대응하는 번들 확장 식별자 |
| install_blocked_reason | string\|null | `null` | 설치가 차단된 사유 (차단 없으면 null) |
| target_name | string\|null | `null` | 대상 확장의 표시 이름 |
| installed_at | string\|null | `2026-07-05 10:41:24` | 설치 일시 (사용자 타임존) |
| activated_at | string\|null | `2026-07-05 10:41:24` | 마지막 활성화 일시 (비활성화 시에도 초기화되지 않음) |
| created_at | string\|null | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string\|null | `2026-07-08 10:41:24` | 최종 수정 일시 |
| has_update | boolean | `false` | `latest_version > version` 여부 |
| abilities | object | `{"can_activate":true,"can_deactivate":true,"can_uninstall":true}` | 현재 사용자가 이 언어팩에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어팩을 비활성화했습니다.",
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
        "status": "inactive",
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
    }
}
```

> 보호된 언어팩은 비활성화할 수 없으며, 이 경우 500 (`언어팩 비활성화에 실패했습니다: 보호된 언어팩은 비활성화/제거할 수 없습니다.`) 으로 응답합니다. 같은 슬롯에 다른 후보 팩이 있으면 가장 최근 inactive/installed 팩이 자동으로 활성 승격됩니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
POST /api/admin/language-packs/{id}/update HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (업데이트된 언어팩 = `LanguagePackResource::toArray()`). 필드 구성은 activate/deactivate 응답과 동일하며 `version` 이 새 버전으로 갱신되고 `has_update` 가 `false` 가 됩니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `3` | 기본 키 (내부 식별자) |
| identifier | string | `acme-core-es` | 언어팩 고유 식별자 |
| vendor | string | `acme` | 언어팩 제작자 식별자 |
| scope | string | `core` | 적용 대상 분류 (core/module/plugin/template) |
| target_identifier | string\|null | `null` | 대상 확장 식별자 (scope=core 일 때 null) |
| locale | string | `es` | IETF BCP-47 locale 태그 |
| locale_name | string | `Spanish` | 영문 언어명 |
| locale_native_name | string | `Español` | 원어 언어명 |
| text_direction | string | `ltr` | 텍스트 방향 (ltr/rtl) |
| version | string | `1.1.0` | 업데이트 후 적용된 버전 |
| latest_version | string\|null | `1.1.0` | 감지된 최신 배포 버전 |
| target_version_constraint | string\|null | `null` | 대상 확장 버전 제약 (semver) |
| target_version_mismatch | boolean | `false` | 대상 버전 불일치 경고 플래그 |
| name | string\|null | `스페인어 언어팩` | manifest `name` 의 현재 로케일 해석값 |
| license | string\|null | `MIT` | 라이선스 |
| description | string\|null | `—` | 언어팩 설명 (다국어) |
| status | string | `active` | 언어팩 상태 (업데이트 전 상태 유지) |
| is_protected | boolean | `false` | 보호(protected) 팩 여부 |
| source_type | string | `github` | 설치 소스 유형 (업데이트는 GitHub/번들 소스만 가능) |
| origin | string\|null | `github` | source_type 에서 파생된 출처 값 |
| source_url | string\|null | `https://github.com/acme/g7-core-es` | 설치 소스 URL 또는 경로 |
| github_url | string\|null | `https://github.com/acme/g7-core-es` | GitHub 저장소 URL (manifest 파생) |
| github_changelog_url | string\|null | `null` | GitHub 변경 이력(CHANGELOG) URL (manifest 파생) |
| bundled_identifier | string\|null | `null` | 대응하는 번들 확장 식별자 |
| install_blocked_reason | string\|null | `null` | 설치가 차단된 사유 (차단 없으면 null) |
| target_name | string\|null | `null` | 대상 확장의 표시 이름 |
| installed_at | string\|null | `2026-07-05 10:41:24` | 설치 일시 (사용자 타임존) |
| activated_at | string\|null | `2026-07-05 10:41:24` | 활성화 일시 (사용자 타임존) |
| created_at | string\|null | `2026-07-05 10:41:24` | 생성 일시 |
| updated_at | string\|null | `2026-07-09 09:12:00` | 최종 수정 일시 (업데이트 시각으로 갱신) |
| has_update | boolean | `false` | `latest_version > version` 여부 (업데이트 직후 false) |
| abilities | object | `{"can_activate":true,"can_deactivate":true,"can_uninstall":true}` | 현재 사용자가 이 언어팩에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어팩을 업데이트했습니다.",
    "data": {
        "id": 3,
        "identifier": "acme-core-es",
        "vendor": "acme",
        "scope": "core",
        "target_identifier": null,
        "locale": "es",
        "locale_name": "Spanish",
        "locale_native_name": "Español",
        "text_direction": "ltr",
        "version": "1.1.0",
        "latest_version": "1.1.0",
        "target_version_constraint": null,
        "target_version_mismatch": false,
        "name": "스페인어 언어팩",
        "license": "MIT",
        "description": null,
        "status": "active",
        "is_protected": false,
        "source_type": "github",
        "origin": "github",
        "source_url": "https://github.com/acme/g7-core-es",
        "github_url": "https://github.com/acme/g7-core-es",
        "github_changelog_url": null,
        "bundled_identifier": null,
        "install_blocked_reason": null,
        "target_name": null,
        "installed_at": "2026-07-05 10:41:24",
        "activated_at": "2026-07-05 10:41:24",
        "created_at": "2026-07-05 10:41:24",
        "updated_at": "2026-07-09 09:12:00",
        "has_update": false,
        "abilities": {
            "can_activate": true,
            "can_deactivate": true,
            "can_uninstall": true
        }
    }
}
```

> 업데이트 소스 정보가 없거나(`업데이트 소스 정보가 없습니다 (GitHub 소스 언어팩만 업데이트 가능).`) 이미 최신 버전이면(`이미 최신 버전입니다.`) 500 (`언어팩 업데이트에 실패했습니다.`) 으로 응답합니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.language_packs.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** GitHub 소스 언어팩을 최신 버전으로 다시 내려받아 적용합니다. `core.language_packs.update` 권한이 필요합니다. 외부 GitHub 재다운로드와 파일 교체를 동반하며, 갱신된 언어팩 정보를 반환합니다. 대상이 없으면 404로 응답합니다. check-updates 로 업데이트가 감지된 팩을 실제로 갱신할 때 사용합니다.


