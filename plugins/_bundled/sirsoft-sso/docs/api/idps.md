# Idps API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 관리자 IdP 설정 API(admin/idps 8개)와 로그인 화면 버튼용 공개 IdP 목록(GET idps)이다
2. 조회는 settings.view, 추가, 수정, 삭제, 연결 테스트, SP 키, SCIM 토큰은 settings.update
3. 비밀값은 응답에서 마스크(••••••••) 또는 null — 마스크나 빈 값을 보내면 유지, null 이면 삭제
4. 켜려면(enabled: true) 드라이버를 쓸 수 있고 연결 정보가 검증을 통과해야 한다(422 driver_unavailable, config_invalid)
5. SCIM 토큰은 발급 응답에 한 번만 평문으로 실린다(저장은 sha256 해시만)
6. 응답은 미실측 — 실측 예시값 열은 —, 응답 예시는 코드에서 옮긴 모양(… 는 실제 값 자리)
```

---


### GET /api/plugins/sirsoft-sso/admin/idps
<!-- @generated:start:api.plugins.sirsoft-sso.admin.idps.index -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.idps.index`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\IdpController@index`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/admin/idps HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| idps | array | — | IdP 요약(순서대로) |
| idps[].id | integer | — | IdP ID |
| idps[].key | string | — | IdP 키(로그인 주소와 SCIM 주소에 쓰인다) |
| idps[].protocol | string | — | `oidc`, `saml`, `ldap` |
| idps[].protocol_label | string | — | 프로토콜 표시 이름 |
| idps[].label | object | — | 로케일별 이름 `{ko, en, …}` |
| idps[].display_label | string | — | 현재 로케일 이름 |
| idps[].enabled | boolean | — | 사용 여부 |
| idps[].available | boolean | — | 이 서버에서 드라이버를 쓸 수 있는지(예: LDAP 은 PHP `ldap` 확장 필요) |
| idps[].is_mock | boolean | — | 모의 IdP(키 접두 `mock_`)인지 |
| idps[].sort_order | integer | — | 표시 순서 |
| idps[].button_visible | boolean | — | 로그인 화면에 버튼을 보이는지 |
| idps[].owned_domains | array | — | 소유 이메일 도메인 |
| idps[].enforce_owned_domains | boolean | — | 소유 도메인 이메일의 비밀번호 로그인을 막는지 |
| idps[].last_success_at | string\|null | — | 마지막 로그인 성공 시각(ISO 8601) |
| idps[].last_error_at | string\|null | — | 마지막 오류 시각(ISO 8601) |
| idps[].last_error_code | string\|null | — | 마지막 오류 코드(README 「로그인 흐름 오류 코드」) |
| idps[].accounts_active | integer | — | 활성 연결 수 |
| idps[].accounts_total | integer | — | 전체 연결 수 |
| protocols | array | — | 추가할 수 있는 프로토콜 `{key, label, available, capabilities}`. `capabilities` 는 `{redirect, credential, slo, backchannel, idp_initiated, mfa_signal, groups, periodic_check, metadata}` 참거짓 |
| limits | object | — | `{max: 20, count}` |
| abilities | object | — | `{can_update, is_super}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "IdP 목록을 불러왔습니다.",
    "data": {
        "idps": [
            {
                "id": "…", "key": "…", "protocol": "oidc", "protocol_label": "…",
                "label": { "ko": "…", "en": "…" }, "display_label": "…",
                "enabled": false, "available": true, "is_mock": false, "sort_order": 0, "button_visible": true,
                "owned_domains": [], "enforce_owned_domains": false,
                "last_success_at": null, "last_error_at": null, "last_error_code": null,
                "accounts_active": 0, "accounts_total": 0
            }
        ],
        "protocols": [{ "key": "oidc", "label": "…", "available": true, "capabilities": { "redirect": true, "credential": false, "slo": true, "backchannel": true, "idp_initiated": false, "mfa_signal": true, "groups": true, "periodic_check": true, "metadata": true } }],
        "limits": { "max": 20, "count": "…" },
        "abilities": { "can_update": true, "is_super": false }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.view`)이 없는 경우 |

<!-- @generated:end -->

**설명**

설정 화면 「IdP」 탭의 목록이다. 예시의 `capabilities` 값은 모양을 보이기 위한 것이고 실제 값은 드라이버가 정한다.


### POST /api/plugins/sirsoft-sso/admin/idps
<!-- @generated:start:api.plugins.sirsoft-sso.admin.idps.store -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.idps.store`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\IdpController@store`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.update`

**요청 파라미터**

수정 API(`PUT admin/idps/{id}`)의 칸을 모두 받고, 키와 프로토콜이 더해지며 이름(`label`)은 필수다. 보내지 않은 칸은 표의 기본값으로 만든다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | body | string | 예 | `^[a-z][a-z0-9_]{1,49}$`, `mock_` 접두 불가 | IdP 키. 로그인 주소(`/plugins/sirsoft-sso/login/{key}`)와 SCIM 주소에 쓰이며 나중에 바꿀 수 없다 |
| protocol | body | string | 예 | max 10 | 프로토콜 드라이버 |
| label | body | object | 예 | 로케일별 문자열, 100자, 기본 로케일 필수 | 표시 이름 |
| enabled | body | boolean | 아니오 | — | 사용 여부. 기본 false(꺼진 채 만든다). true 로 만들려면 드라이버를 쓸 수 있고 연결 정보가 검증을 통과해야 한다 |
| sort_order | body | integer | 아니오 | 0~9999 | 표시 순서. 기본 0 |
| button_visible | body | boolean | 아니오 | — | 로그인 화면에 버튼을 보이는지. 기본 true. 끄면 버튼 없이 이메일 먼저 입력과 자동 이동으로만 들어온다 |
| button_style | body | object\|null | 아니오 | `bg`, `fg`, `border` 각각 `#` + 16진 3~8자 | 버튼 색 |
| button_style.bg | body | string\|null | 아니오 | `#` + 16진 3~8자 | 버튼 배경색. 비어 있으면 로그인 화면 버튼은 기본 색으로 그려지고 `fg`, `border` 도 쓰이지 않는다 |
| button_style.fg | body | string\|null | 아니오 | `#` + 16진 3~8자 | 버튼 글자색. 비어 있으면 `#ffffff` |
| button_style.border | body | string\|null | 아니오 | `#` + 16진 3~8자 | 버튼 테두리색. 비어 있으면 배경색과 같다 |
| config | body | object | 아니오 | 드라이버 스키마의 키 | 연결 정보. 키는 수정 API 와 같다 |
| secrets | body | object | 아니오 | 값마다 문자열 20000자 이하 또는 null | 비밀값. 비어 있지 않은 문자열만 저장되고 마스크 `••••••••`, 빈 문자열, null 은 저장되지 않는다 |
| owned_domains | body | array | 아니오 | 50개, 도메인 형식 각 191자 | 소유 이메일 도메인(소문자로 저장). 다른 IdP 가 소유한 도메인은 넣을 수 없다 |
| enforce_owned_domains | body | boolean | 아니오 | — | 소유 도메인 이메일의 비밀번호 로그인을 막는다. 기본 false |
| provisioning_mode | body | string | 아니오 | `jit`, `preregistered` | 처음 온 사람을 자동 생성할지, 사전 등록된 회원만 받을지. 기본 `preregistered` |
| link_methods | body | object | 아니오 | `domain_auto`, `verify`, `admin` 참거짓 | 기존 회원 연결 방식. 보내지 않은 키는 기본값(`domain_auto` false, `verify` true, `admin` true)을 따른다 |
| link_methods.domain_auto | body | boolean | 아니오 | — | 소유 도메인 자동 연결. IdP 가 알려 준 이메일이 소유 도메인이고 같은 이메일 회원이 있으면 바로 연결한다(관리자 계정 제외, OIDC 는 IdP 가 이메일을 검증했다고 알려 줄 때만). 기본 false |
| link_methods.verify | body | boolean | 아니오 | — | 본인 확인 후 연결. 같은 이메일 회원이 있으면 그 회원이 비밀번호나 메일 인증번호로 확인한 뒤 연결한다. 기본 true |
| link_methods.admin | body | boolean | 아니오 | — | 관리자 연결(회원 연결 화면, CSV 가져오기). 기본 true. 값은 저장만 되고 관리자 연결 API 는 이 값을 보지 않는다 |
| require_consent | body | boolean | 아니오 | — | 자동 생성 회원에게 약관 동의를 받는다. 기본 true |
| identity_policy | body | string | 아니오 | `skip`, `apply` | 사이트 본인인증 가입 정책 적용 여부. 기본 `skip` |
| attribute_map | body | array | 아니오 | 50개 | 속성 규칙. `field` 는 `name`, `nickname`, `mobile`, `phone`, `language`, `email`, `attr:{키}`, `source` 는 IdP 속성 이름(191자), `mode` 는 `sync_lock`, `sync`, `first_only`, `ignore` |
| role_mapping | body | array | 아니오 | 200개 | 역할 규칙. `group`(IdP 그룹, 255자), `role`(있는 역할 식별자) |
| role_sync_mode | body | string | 아니오 | `managed_only`, `authoritative`, `first_only` | 역할 동기화 방식. 기본 `managed_only` |
| group_source | body | string | 아니오 | `assertion`, `scim`, `ldap` | 그룹 출처. 기본 `assertion` |
| two_factor_mode | body | string | 아니오 | `always_skip`, `skip_if_mfa`, `always` | G7 2단계 인증. 기본 `skip_if_mfa` |
| logout_mode | body | string | 아니오 | `local`, `rp`, `both` | 로그아웃 범위(G7 만, G7 다음 IdP, 양방향). 기본 `local` |
| idp_initiated | body | boolean | 아니오 | — | IdP 가 시작한 로그인 받기. 기본 false |
| allow_local_password | body | boolean | 아니오 | — | 이 사이트 비밀번호 만들기 허용. 기본 false |
| max_session_minutes | body | integer\|null | 아니오 | 1~10080 | 세션 수명 상한(분). null 이면 사이트 기본값 |
| deprovision | body | object | 아니오 | — | 퇴사 반영. `scim`(참거짓), `scim_match`(`userName`, `externalId`), `periodic`(참거짓), `periodic_minutes`(5~10080), `on_login`(참거짓), `withdraw_after_days`(1~3650 또는 null) |
| deprovision.scim | body | boolean | 아니오 | — | SCIM 사용. 꺼져 있으면 이 IdP 의 SCIM 요청은 403 으로 거절된다 |
| deprovision.scim_match | body | string | 아니오 | `userName`, `externalId` | SCIM 사용자를 로그인 고유 식별자와 맞출 키. 기본 `userName` |
| deprovision.periodic | body | boolean | 아니오 | — | 주기 점검. 켜면 IdP 에 정기적으로 물어 비활성 계정을 퇴사 처리한다(OIDC 는 저장한 갱신 토큰, LDAP 은 디렉터리) |
| deprovision.periodic_minutes | body | integer | 아니오 | 5~10080 | 주기 점검 간격(분). 기본 60 |
| deprovision.on_login | body | boolean | 아니오 | — | 다음 로그인 때 비활성 계정 막기. 값은 저장만 되고 동작은 늘 켜져 있다(퇴사 처리로 꺼진 연결은 다음 로그인 때 거절된다) |
| deprovision.withdraw_after_days | body | integer\|null | 아니오 | 1~3650 | 퇴사 처리 뒤 탈퇴까지 날 수. null 이면 탈퇴하지 않는다. 관리자 계정은 탈퇴하지 않고 알린다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/admin/idps HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "key": "corp_oidc",
    "protocol": "oidc",
    "label": { "ko": "회사 계정", "en": "Company account" }
}
```

**응답 필드** (`data` 내부)

상세 조회(`GET admin/idps/{id}`)와 같은 모양이다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | — | 만든 IdP ID |
| key | string | — | IdP 키 |
| enabled | boolean | — | 보내지 않으면 false(꺼진 채 만든다) |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "IdP 를 추가했습니다.",
    "data": { "id": "…", "key": "corp_oidc", "protocol": "oidc", "enabled": false, "…": "상세 조회와 같은 칸" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.update`)이 없는 경우, 또는 `errors.code` = `super_only` — 최고관리자가 아닌데 관리자 역할(`admin`)로 가는 역할 매핑을 넣음 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 또는 `errors.code` = `idp_limit_reached`(20개), `idp_key_reserved`(`mock_` 접두), `idp_key_taken`, `domain_owned_by_other_idp`(다른 IdP 가 소유한 도메인 — `errors.domains`), `driver_unavailable`, `config_invalid`(`errors.fields` 에 칸별 문구) |

<!-- @generated:end -->

**설명**

보통 키, 프로토콜, 이름만 보내 꺼진 IdP 를 만들고, 연결 정보는 수정 API 로 채운 뒤 연결 테스트를 거쳐 켠다. `enabled: true` 로 만들면 그 자리에서 드라이버 사용 가능 여부와 연결 정보 검증을 한다. 소유 도메인 강제(`enforce_owned_domains`)를 켠 채 만들면 그 도메인 회원의 비밀번호 로그인 토큰을 회수한다. 활동 로그 `sso.idp_created` 가 남는다.


### DELETE /api/plugins/sirsoft-sso/admin/idps/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.admin.idps.destroy -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.idps.destroy`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\IdpController@destroy`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | IdP ID |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-sso/admin/idps/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | — | 지운 IdP ID |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "IdP 를 삭제했습니다.",
    "data": { "id": "…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.update`)이 없는 경우 |
| 404 | Not Found | `errors.code` = `idp_not_found` |
| 409 | Conflict | `errors.code` = `idp_has_accounts` — 연결(활성, 비활성 모두)이 남아 있음 |

<!-- @generated:end -->

**설명**

연결이 하나라도 남은 IdP 는 지울 수 없다. 연결을 먼저 해제하거나 IdP 를 사용 안 함으로 둔다. 활동 로그 `sso.idp_deleted` 가 남는다.


### GET /api/plugins/sirsoft-sso/admin/idps/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.admin.idps.show -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.idps.show`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\IdpController@show`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | IdP ID |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/admin/idps/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

목록의 IdP 요약 칸(`id` ~ `accounts_total`)에 아래가 더해진다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| button_style | object\|null | — | 버튼 색 `{bg, fg, border}`(16진 색) |
| config | object | — | 프로토콜 설정(비밀 아님). 키는 `schema` 가 정한다 |
| secrets | object | — | 비밀값. 값이 있으면 마스크 `••••••••`, 없으면 null. 이전 SP 키(`sp_previous_*`)는 싣지 않는다 |
| provisioning_mode | string | — | 처음 온 사람 처리 `jit`(자동 생성), `preregistered`(사전 등록된 회원만) |
| link_methods | object | — | 기존 회원 연결 방식 `{domain_auto, verify, admin}` 참거짓 |
| require_consent | boolean | — | 자동 생성 회원에게 처음 로그인 때 약관 동의를 받는지 |
| identity_policy | string | — | 사이트 본인인증 가입 정책을 자동 생성에 걸지 `skip`, `apply` |
| attribute_map | array | — | 속성 규칙 `{field, source, mode}` |
| role_mapping | array | — | 역할 규칙 `{group, role}` |
| role_sync_mode | string | — | `managed_only`, `authoritative`, `first_only` |
| group_source | string | — | 그룹 출처 `assertion`, `scim`, `ldap` |
| two_factor_mode | string | — | G7 2단계 인증 `skip_if_mfa`, `always_skip`, `always` |
| logout_mode | string | — | 로그아웃 범위 `local`, `rp`, `both` |
| idp_initiated | boolean | — | IdP 가 시작한 로그인(SAML)을 받는지 |
| allow_local_password | boolean | — | 연결된 회원이 이 사이트 비밀번호를 만들 수 있는지 |
| max_session_minutes | integer\|null | — | 이 IdP 로 받은 로그인의 세션 수명 상한(분) |
| deprovision | object | — | 퇴사 반영 `{scim, scim_match, periodic, periodic_minutes, on_login, withdraw_after_days}` |
| scim | object | — | `{endpoint, token_issued_at, has_token}`. `endpoint` 는 사이트 주소 기준 SCIM 기준 주소 |
| periodic_checked_at | string\|null | — | 마지막 주기 점검 시각 |
| metadata_refreshed_at | string\|null | — | SAML IdP 메타데이터를 마지막으로 받은 시각 |
| cert_expires_at | string\|null | — | IdP 서명 인증서 만료 시각 |
| schema | array | — | 연결 정보 칸 정의(드라이버의 설정 스키마 + 현재 로케일 `label`, `hint`) |
| sp_info | array | — | IdP 콘솔에 넣을 값 `{key, value, label}`. OIDC 는 `redirect_uri`, `post_logout_redirect_uri`, `backchannel_logout_uri`, `scopes`, SAML 은 `entity_id`, `acs_url`, `sls_url`, `metadata_url`, `name_id_format` |
| capabilities | object | — | 드라이버 기능 참거짓 |
| recent_attempts | array | — | 최근 시도 10건 `{id, intent, guard, outcome, reason_code, created_at}` |
| abilities | object | — | `{is_super, role_rules_locked, can_update}`. `role_rules_locked` 는 관리자 계정이 연결된 IdP 라 최고관리자가 아니면 역할 동기화 방식과 그룹 출처를 못 바꾸는 상태 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "IdP 설정을 불러왔습니다.",
    "data": {
        "id": "…", "key": "…", "protocol": "oidc", "enabled": false,
        "button_style": null,
        "config": { "discovery_url": "https://idp.example.com/.well-known/openid-configuration", "client_id": "…" },
        "secrets": { "client_secret": "••••••••" },
        "provisioning_mode": "jit",
        "link_methods": { "domain_auto": false, "verify": true, "admin": true },
        "scim": { "endpoint": "https://www.example.com/api/plugins/sirsoft-sso/scim/v2/…", "token_issued_at": null, "has_token": false },
        "schema": [{ "key": "discovery_url", "type": "url", "required": true, "label": "…", "hint": "…" }],
        "sp_info": [{ "key": "redirect_uri", "value": "https://www.example.com/plugins/sirsoft-sso/oidc/…/callback", "label": "…" }],
        "recent_attempts": [],
        "abilities": { "is_super": false, "role_rules_locked": false, "can_update": true }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.view`)이 없는 경우 |
| 404 | Not Found | `errors.code` = `idp_not_found` |

<!-- @generated:end -->

**설명**

IdP 편집 화면의 데이터다. 예시는 칸 일부만 보였다. `link_methods` 의 기본값은 코드 기본값(`IdentityProvider::DEFAULT_LINK_METHODS`)과 저장값을 합친 것이다.


### PUT /api/plugins/sirsoft-sso/admin/idps/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.admin.idps.update -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.idps.update`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\IdpController@update`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.update`

**요청 파라미터**

보낸 칸만 바뀐다. `config` 와 `deprovision` 은 저장값에 합쳐진다(보낸 키만 바뀐다).

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | IdP ID |
| enabled | body | boolean | 아니오 | — | 사용 여부. 켜려면 드라이버를 쓸 수 있고 연결 정보가 검증을 통과해야 한다 |
| sort_order | body | integer | 아니오 | 0~9999 | 표시 순서 |
| label | body | object | 아니오 | 로케일별 문자열, 100자 | 표시 이름 |
| button_visible | body | boolean | 아니오 | — | 끄면 버튼 없이 이메일 먼저 입력과 자동 이동으로만 들어온다 |
| button_style | body | object\|null | 아니오 | `bg`, `fg`, `border` 각각 `#` + 16진 3~8자 | 버튼 색 |
| button_style.bg | body | string\|null | 아니오 | `#` + 16진 3~8자 | 버튼 배경색. 비어 있으면 로그인 화면 버튼은 기본 색으로 그려지고 `fg`, `border` 도 쓰이지 않는다 |
| button_style.fg | body | string\|null | 아니오 | `#` + 16진 3~8자 | 버튼 글자색. 비어 있으면 `#ffffff` |
| button_style.border | body | string\|null | 아니오 | `#` + 16진 3~8자 | 버튼 테두리색. 비어 있으면 배경색과 같다 |
| config | body | object | 아니오 | 드라이버 스키마의 키 | 연결 정보. OIDC `discovery_url`, `client_id`, `scopes`, `subject_claim`(`sub`, `oid`, `custom`) 등, SAML `idp_metadata_url`, `idp_metadata_xml`, `name_id_format` 등, LDAP `host`, `port`, `encryption`, `base_dn`, `user_filter` 등 |
| secrets | body | object | 아니오 | 값마다 문자열 20000자 이하 또는 null | 비밀값(OIDC `client_secret`, SAML `sp_certificate` `sp_private_key`, LDAP `bind_password`). 마스크 `••••••••` 나 빈 문자열은 유지, null 은 삭제, 그 밖의 값은 교체 |
| owned_domains | body | array | 아니오 | 50개, 도메인 형식 각 191자 | 소유 이메일 도메인(소문자로 저장) |
| enforce_owned_domains | body | boolean | 아니오 | — | 소유 도메인 이메일의 비밀번호 로그인을 막는다 |
| provisioning_mode | body | string | 아니오 | `jit`, `preregistered` | 처음 온 사람을 자동 생성할지, 사전 등록된 회원만 받을지 |
| link_methods | body | object | 아니오 | `domain_auto`, `verify`, `admin` 참거짓 | 기존 회원 연결 방식 |
| link_methods.domain_auto | body | boolean | 아니오 | — | 소유 도메인 자동 연결. IdP 가 알려 준 이메일이 소유 도메인이고 같은 이메일 회원이 있으면 바로 연결한다(관리자 계정 제외, OIDC 는 IdP 가 이메일을 검증했다고 알려 줄 때만). 기본 false |
| link_methods.verify | body | boolean | 아니오 | — | 본인 확인 후 연결. 같은 이메일 회원이 있으면 그 회원이 비밀번호나 메일 인증번호로 확인한 뒤 연결한다. 기본 true |
| link_methods.admin | body | boolean | 아니오 | — | 관리자 연결(회원 연결 화면, CSV 가져오기). 기본 true. 값은 저장만 되고 관리자 연결 API 는 이 값을 보지 않는다 |
| require_consent | body | boolean | 아니오 | — | 자동 생성 회원에게 약관 동의를 받는다 |
| identity_policy | body | string | 아니오 | `skip`, `apply` | 사이트 본인인증 가입 정책 적용 여부 |
| attribute_map | body | array | 아니오 | 50개 | 속성 규칙. `field` 는 `name`, `nickname`, `mobile`, `phone`, `language`, `email`, `attr:{키}`, `source` 는 IdP 속성 이름(191자), `mode` 는 `sync_lock`, `sync`, `first_only`, `ignore` |
| role_mapping | body | array | 아니오 | 200개 | 역할 규칙. `group`(IdP 그룹, 255자), `role`(있는 역할 식별자) |
| role_sync_mode | body | string | 아니오 | `managed_only`, `authoritative`, `first_only` | 역할 동기화 방식 |
| group_source | body | string | 아니오 | `assertion`, `scim`, `ldap` | 그룹 출처 |
| two_factor_mode | body | string | 아니오 | `always_skip`, `skip_if_mfa`, `always` | G7 2단계 인증 |
| logout_mode | body | string | 아니오 | `local`, `rp`, `both` | 로그아웃 범위(G7 만, G7 다음 IdP, 양방향) |
| idp_initiated | body | boolean | 아니오 | — | IdP 가 시작한 로그인 받기 |
| allow_local_password | body | boolean | 아니오 | — | 이 사이트 비밀번호 만들기 허용 |
| max_session_minutes | body | integer\|null | 아니오 | 1~10080 | 세션 수명 상한(분). null 이면 사이트 기본값 |
| deprovision | body | object | 아니오 | — | 퇴사 반영. `scim`(참거짓), `scim_match`(`userName`, `externalId`), `periodic`(참거짓), `periodic_minutes`(5~10080), `on_login`(참거짓), `withdraw_after_days`(1~3650 또는 null) |
| deprovision.scim | body | boolean | 아니오 | — | SCIM 사용. 꺼져 있으면 이 IdP 의 SCIM 요청은 403 으로 거절된다 |
| deprovision.scim_match | body | string | 아니오 | `userName`, `externalId` | SCIM 사용자를 로그인 고유 식별자와 맞출 키. 기본 `userName` |
| deprovision.periodic | body | boolean | 아니오 | — | 주기 점검. 켜면 IdP 에 정기적으로 물어 비활성 계정을 퇴사 처리한다(OIDC 는 저장한 갱신 토큰, LDAP 은 디렉터리) |
| deprovision.periodic_minutes | body | integer | 아니오 | 5~10080 | 주기 점검 간격(분). 기본 60 |
| deprovision.on_login | body | boolean | 아니오 | — | 다음 로그인 때 비활성 계정 막기. 값은 저장만 되고 동작은 늘 켜져 있다(퇴사 처리로 꺼진 연결은 다음 로그인 때 거절된다) |
| deprovision.withdraw_after_days | body | integer\|null | 아니오 | 1~3650 | 퇴사 처리 뒤 탈퇴까지 날 수. null 이면 탈퇴하지 않는다. 관리자 계정은 탈퇴하지 않고 알린다 |

**요청 예시**

```http
PUT /api/plugins/sirsoft-sso/admin/idps/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "enabled": true,
    "config": {
        "discovery_url": "https://idp.example.com/.well-known/openid-configuration",
        "client_id": "g7-site"
    },
    "secrets": { "client_secret": "••••••••" },
    "owned_domains": ["example.com"],
    "provisioning_mode": "jit",
    "deprovision": { "scim": true, "scim_match": "userName" }
}
```

**응답 필드** (`data` 내부)

상세 조회(`GET admin/idps/{id}`)와 같은 모양이다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | — | IdP ID |
| enabled | boolean | — | 저장 뒤 사용 여부 |
| secrets | object | — | 저장 뒤 비밀값 마스크 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "IdP 설정을 저장했습니다.",
    "data": { "id": "…", "enabled": true, "secrets": { "client_secret": "••••••••" }, "…": "상세 조회와 같은 칸" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.update`)이 없는 경우, 또는 `errors.code` = `super_only` — 최고관리자가 아닌데 관리자 역할로 가는 역할 매핑을 넣었거나, 관리자 계정이 연결된 IdP 의 역할 동기화 방식이나 그룹 출처를 바꿈 |
| 404 | Not Found | `errors.code` = `idp_not_found` |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 또는 `errors.code` = `domain_owned_by_other_idp`(다른 IdP 가 소유한 도메인 — `errors.domains`), `driver_unavailable`(드라이버 없음 또는 이 서버에서 못 씀), `config_invalid`(켜려는데 연결 정보 검증 실패 — `errors.fields` 에 칸별 문구) |

<!-- @generated:end -->

**설명**

꺼 둔 채 저장은 언제나 된다. 소유 도메인 강제를 새로 켜거나 도메인을 더하면 그 도메인 회원(비상 계정 제외)의 비밀번호 로그인 토큰을 회수한다. 바뀐 칸이 있으면 활동 로그 `sso.idp_updated` 에 칸 이름이 남는다(비밀값 원문은 남지 않는다).

`config_invalid` 응답 예:

```json
{
    "success": false,
    "message": "연결 정보가 올바르지 않아 사용으로 바꿀 수 없습니다. 표시된 칸을 확인해 주세요.",
    "errors": { "code": "config_invalid", "fields": { "discovery_url": "…" } }
}
```


### POST /api/plugins/sirsoft-sso/admin/idps/{id}/scim-token
<!-- @generated:start:api.plugins.sirsoft-sso.admin.idps.scim-token -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.idps.scim-token`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\IdpController@scimToken`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | IdP ID |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/admin/idps/{id}/scim-token HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| token | string | — | SCIM Bearer 토큰 평문(64자). 이 응답에서 한 번만 보인다 |
| issued_at | string | — | 발급 시각(ISO 8601) |
| endpoint | string | — | IdP 에 넣을 SCIM 기준 주소 `{사이트 주소}/api/plugins/sirsoft-sso/scim/v2/{키}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "SCIM 토큰을 발급했습니다. 이 화면을 벗어나면 다시 볼 수 없습니다.",
    "data": {
        "token": "{MASKED}",
        "issued_at": "…",
        "endpoint": "https://www.example.com/api/plugins/sirsoft-sso/scim/v2/…"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.update`)이 없는 경우 |
| 404 | Not Found | `errors.code` = `idp_not_found` |

<!-- @generated:end -->

**설명**

새 토큰을 만들고 sha256 해시만 저장한다. 이전 토큰은 바로 쓸 수 없으므로 IdP 쪽 토큰도 함께 바꿔야 한다. SCIM 요청은 이 IdP 가 켜져 있고 `deprovision.scim` 이 켜져 있을 때만 통과한다(scim.md). 활동 로그 `sso.scim_token_issued` 가 남는다(토큰은 남지 않는다).


### POST /api/plugins/sirsoft-sso/admin/idps/{id}/sp-keys
<!-- @generated:start:api.plugins.sirsoft-sso.admin.idps.sp-keys -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.idps.sp-keys`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\IdpController@spKeys`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | SAML IdP ID |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/admin/idps/{id}/sp-keys HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| certificate | string | — | 새 SP 서명 인증서(PEM, 공개 정보) |
| expires_at | string\|null | — | 인증서 만료 시각 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "SP 키를 만들었습니다. 새 SP 메타데이터를 IdP 에 다시 등록해 주세요.",
    "data": { "certificate": "-----BEGIN CERTIFICATE-----\n…\n-----END CERTIFICATE-----", "expires_at": "…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.update`)이 없는 경우 |
| 404 | Not Found | `errors.code` = `idp_not_found` |
| 422 | Unprocessable Entity | `errors.code` = `sp_keys_saml_only`(SAML 이 아닌 IdP) 또는 `driver_unavailable` |

<!-- @generated:end -->

**설명**

SAML SP 서명 키와 자체 서명 인증서를 새로 만든다. 개인키는 응답에 싣지 않고 암호화해 저장한다. 이전 키와 인증서는 30일 동안 함께 둔다(`sp_previous_*`, 응답과 상세 조회에는 싣지 않는다). 새 SP 메타데이터(`GET /plugins/sirsoft-sso/saml/{idp}/metadata`)를 IdP 에 다시 등록한다. 활동 로그 `sso.sp_keys_generated` 가 남는다.


### POST /api/plugins/sirsoft-sso/admin/idps/{id}/test
<!-- @generated:start:api.plugins.sirsoft-sso.admin.idps.test -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.idps.test`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\IdpController@test`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.update`
- **요청 제한**: `throttle:20,1,sirsoft-sso.idp-test`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | IdP ID(꺼진 IdP 도 된다) |
| browser | body | boolean | 아니오 | — | true 면 서버 점검 대신 「브라우저로 로그인 테스트」 시작 주소를 돌려준다 |
| account_identifier | body | string\|null | 아니오 | 최대 255자 | (LDAP) 이 아이디로 디렉터리 바인드를 시험한다 |
| account_password | body | string\|null | 조건부 | 최대 1024자 | (LDAP) `account_identifier` 를 보내면 필수. 저장하지 않는다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/admin/idps/{id}/test HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "browser": false
}
```

**응답 필드** (`data` 내부)

서버 점검(`browser` 가 거짓)일 때:

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| ok | boolean | — | 모든 점검을 통과했는지 |
| checks | array | — | 항목별 결과 `{key, ok, message_key, detail, message}`. `message` 는 현재 로케일 문구, `detail` 은 진단 요약(비밀값 없음) |

브라우저 테스트(`browser: true`)일 때:

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| start_url | string | — | `/plugins/sirsoft-sso/login/{키}?ticket={48자}` — 1회용 시작 주소(60초, 이 브라우저 전용) |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "연결 테스트를 마쳤습니다.",
    "data": {
        "ok": false,
        "checks": [
            { "key": "discovery", "ok": true, "message_key": "sirsoft-sso::oidc.test.discovery_ok", "detail": "…", "message": "…" }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.update`)이 없는 경우 |
| 404 | Not Found | `errors.code` = `idp_not_found` |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 또는 `errors.code` = `driver_unavailable`, `browser_test_unsupported`(LDAP 에 `browser: true`) |
| 429 | Too Many Requests | 분당 20회를 넘은 경우 |

<!-- @generated:end -->

**설명**

서버 점검은 회원 동의 없이 연결 정보만 확인한다. 항목 `key` 는 OIDC `discovery`, `metadata`, `jwks`, `redirect_uri`, SAML `site_url`, `metadata`, `certificate`, `sso_url`, `sp_key`, `sp_metadata`, LDAP `extension`, `connect`, `tls`, `bind`, `base_dn`, `search` 다(앞 항목이 실패하면 뒤 항목이 빠질 수 있다). 브라우저 테스트는 같은 브라우저에 묶음 쿠키 `g7_sso_bind` 를 구워 1회용 시작 주소를 준다. 관리자 화면이 그 주소를 새 창으로 열면 IdP 로그인 뒤 결과 화면 `/plugins/sirsoft-sso/test-result/{40자}`(test-result.md)로 간다. 테스트는 회원을 만들거나 연결하지 않는다.


### GET /api/plugins/sirsoft-sso/idps
<!-- @generated:start:api.plugins.sirsoft-sso.idps -->
- **라우트명**: `api.plugins.sirsoft-sso.idps`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\PublicIdpController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)
- **요청 제한**: `throttle:120,1,sirsoft-sso.idps`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| guard | query | string | 아니오 | `user`, `admin` | 시작 주소에 실을 로그인 화면 구분(기본 `user`) |
| redirect | query | string | 아니오 | 최대 2000자 | 로그인 뒤 돌아갈 같은 출처 경로. 외부 주소는 버리고 `/` 로 본다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/idps?guard=user&redirect=%2Fmypage HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

`data` 는 배열이다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| [].key | string | — | IdP 키 |
| [].label | string | — | 현재 로케일 이름 |
| [].initial | string | — | 이름 첫 글자(버튼 아이콘 자리) |
| [].button_style | object\|null | — | 버튼 색 `{bg, fg, border}` |
| [].start_url | string | — | 로그인 시작 주소 `/plugins/sirsoft-sso/login/{키}?guard=…&redirect=…` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "회사 계정 목록을 불러왔습니다.",
    "data": [
        { "key": "corp_oidc", "label": "…", "initial": "…", "button_style": null, "start_url": "/plugins/sirsoft-sso/login/corp_oidc?guard=user&redirect=%2Fmypage" }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | `guard` 가 `user`, `admin` 이 아니거나 `redirect` 가 2000자를 넘는 경우 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

로그인 화면과 관리자 로그인 화면의 「회사 계정으로 로그인」 버튼 목록이다. 켜져 있고 버튼 표시가 켜진 IdP 만 순서대로 싣는다. 이 서버에서 쓸 수 없는 드라이버의 IdP 와 리디렉션 로그인이 없는 LDAP IdP(코어 로그인 칸으로 들어온다)는 뺀다. 모의 IdP(`mock_` 키)는 모의 게이트가 켜져 있을 때만 보인다. 연결 정보와 비밀값은 싣지 않는다. 켜진 IdP 가 없으면 `data` 는 빈 배열이다.
