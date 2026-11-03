# Policy API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. SSO 정책(사이트 1행) 조회, 저장, 영향 미리보기다. 조회와 미리보기는 settings.view, 저장은 settings.update
2. enforce_site, enforce_roles, emergency_user_ids 는 권한과 별개로 최고관리자만 바꿀 수 있다(403 super_only)
3. 비상 계정 0명이면 422 emergency_required, 사용 중인 IdP 없이 사이트 전체 강제를 켜면 422 site_enforce_requires_idp
4. 저장 뒤 새로 강제 대상이 된 회원(비상 계정 제외)의 비밀번호 로그인 토큰을 회수하고 그 수를 revoked_tokens 로 준다
5. 응답은 미실측 — 실측 예시값 열은 —, 응답 예시는 코드에서 옮긴 모양(… 는 실제 값 자리)
```

---


### GET /api/plugins/sirsoft-sso/admin/policy
<!-- @generated:start:api.plugins.sirsoft-sso.admin.policy.show -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.policy.show`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\PolicyController@show`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/admin/policy HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| policy | object | — | 정책 값(아래 `policy.*`) |
| policy.enforce_site | boolean | — | 사이트 전체 강제(비상 계정 밖 모든 회원의 비밀번호 로그인 막기, 셀프 가입도 닫힌다) |
| policy.enforce_roles | array | — | 강제할 역할 식별자 |
| policy.enforce_linked | boolean | — | 회사 계정에 연결된 회원 강제 |
| policy.emergency_user_ids | array | — | 비상 계정 회원 ID(강제 대상에서 빠진다) |
| policy.enforcement_backup | object\|null | — | `sirsoft-sso:enforcement --disable` 이 보관한 이전 강제 값. 저장 API 로는 바꿀 수 없다 |
| policy.auto_redirect_idp | string\|null | — | 로그인 화면 자동 이동 IdP 키(사이트 전체 강제가 켜졌을 때만, LDAP 제외) |
| policy.session_max_minutes | integer | — | 사이트 세션 수명 상한(분, 0 이면 끔). IdP 값과 작은 쪽을 쓴다 |
| policy.discovery_enabled | boolean | — | 이메일 먼저 입력 판정 |
| policy.state_ttl_minutes | integer | — | 로그인 요청(state) 수명(분) |
| policy.pending_ttl_minutes | integer | — | 동의 대기, 연결 확인 대기 토큰 수명(분) |
| policy.attempts_retention_days | integer | — | 로그인 시도 이력 보존(일) |
| policy.scim_retention_days | integer | — | SCIM 수신 이력 보존(일) |
| policy.session_retention_days | integer | — | 끝난 세션 기록 보존(일) |
| policy.failure_alert_count | integer | — | IdP 연속 실패 알림 기준 횟수 |
| policy.failure_alert_window_minutes | integer | — | IdP 연속 실패 알림 기준 시간(분) |
| policy.cert_expiry_notice_days | array | — | 인증서 만료 알림 날짜(만료 며칠 전) |
| emergency_users | array | — | 비상 계정 회원 `{id, uuid, name, email, has_password}` |
| abilities | object | — | `{is_super, can_update}` — 화면이 최고관리자 전용 칸과 저장 단추를 잠근다 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "SSO 정책을 불러왔습니다.",
    "data": {
        "policy": {
            "enforce_site": false,
            "enforce_roles": [],
            "enforce_linked": false,
            "emergency_user_ids": ["…"],
            "enforcement_backup": null,
            "auto_redirect_idp": null,
            "session_max_minutes": 0,
            "discovery_enabled": true,
            "state_ttl_minutes": 10,
            "pending_ttl_minutes": 10,
            "attempts_retention_days": 90,
            "scim_retention_days": 90,
            "session_retention_days": 30,
            "failure_alert_count": 5,
            "failure_alert_window_minutes": 10,
            "cert_expiry_notice_days": [30, 7]
        },
        "emergency_users": [{ "id": "…", "uuid": "…", "name": "…", "email": "…", "has_password": true }],
        "abilities": { "is_super": true, "can_update": true }
    }
}
```

예시의 정책 값은 기본값(`PolicyService::DEFAULTS`)이다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.view`)이 없는 경우 |

<!-- @generated:end -->

**설명**

정책 행이 없으면(설치 직후 첫 읽기) 가장 먼저 만든 최고관리자 1명을 비상 계정으로 둔 기본 행을 만든 뒤 돌려준다.


### PUT /api/plugins/sirsoft-sso/admin/policy
<!-- @generated:start:api.plugins.sirsoft-sso.admin.policy.update -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.policy.update`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\PolicyController@update`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.update`

**요청 파라미터**

보낸 칸만 바뀐다. 이 표에 없는 칸(`enforcement_backup` 포함)은 무시한다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| enforce_site | body | boolean | 아니오 | — | 사이트 전체 강제(최고관리자 전용) |
| enforce_roles | body | array | 아니오 | 50개, 있는 역할 식별자 | 강제할 역할(최고관리자 전용) |
| enforce_linked | body | boolean | 아니오 | — | 연결된 회원 강제 |
| emergency_user_ids | body | array | 아니오 | 20개, 있는 회원 ID | 비상 계정(최고관리자 전용, 1명 이상) |
| auto_redirect_idp | body | string\|null | 아니오 | `^[a-z][a-z0-9_]{1,49}$` | 자동 이동 IdP 키. 없는 키는 null 로 저장 |
| session_max_minutes | body | integer | 아니오 | 0~10080 | SSO 세션 수명 상한(분) |
| discovery_enabled | body | boolean | 아니오 | — | 이메일 먼저 입력 |
| state_ttl_minutes | body | integer | 아니오 | 1~60 | 로그인 요청 수명(분) |
| pending_ttl_minutes | body | integer | 아니오 | 1~60 | 대기 토큰 수명(분) |
| attempts_retention_days | body | integer | 아니오 | 1~3650 | 로그인 시도 이력 보존(일) |
| scim_retention_days | body | integer | 아니오 | 1~3650 | SCIM 수신 이력 보존(일) |
| session_retention_days | body | integer | 아니오 | 1~3650 | 세션 기록 보존(일) |
| failure_alert_count | body | integer | 아니오 | 1~1000 | 실패 알림 기준 횟수 |
| failure_alert_window_minutes | body | integer | 아니오 | 1~1440 | 실패 알림 기준 시간(분) |
| cert_expiry_notice_days | body | array | 아니오 | 5개, 각 1~365 | 인증서 만료 알림 날짜 |

**요청 예시**

```http
PUT /api/plugins/sirsoft-sso/admin/policy HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "enforce_linked": true,
    "session_max_minutes": 480,
    "cert_expiry_notice_days": [30, 7]
}
```

**응답 필드** (`data` 내부)

조회 응답(`policy`, `emergency_users`, `abilities`)에 아래가 더해진다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| policy | object | — | 저장된 정책 |
| emergency_users | array | — | 비상 계정 회원 |
| abilities | object | — | `{is_super, can_update}` |
| revoked_tokens | integer | — | 이번 저장으로 새로 강제 대상이 된 회원에게서 회수한 비밀번호 로그인 토큰 수 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "SSO 정책을 저장했습니다.",
    "data": {
        "policy": { "enforce_linked": true, "session_max_minutes": 480, "…": "조회와 같은 칸" },
        "emergency_users": [{ "id": "…", "uuid": "…", "name": "…", "email": "…", "has_password": true }],
        "abilities": { "is_super": false, "can_update": true },
        "revoked_tokens": "…"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.update`)이 없는 경우, 또는 `errors.code` = `super_only` — 최고관리자가 아닌데 `enforce_site`, `enforce_roles`, `emergency_user_ids` 의 값을 바꿈(같은 값을 다시 보내는 것은 된다) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 또는 `errors.code` = `emergency_required`(저장 결과 비상 계정 0명 — 지운 회원 ID 는 빠진다), `site_enforce_requires_idp`(사용 중인 IdP 없이 사이트 전체 강제) |

<!-- @generated:end -->

**설명**

검사 순서는 최고관리자 전용 칸, 비상 계정, 사이트 전체 강제다. 저장 뒤 바뀐 칸이 있으면 활동 로그 `sso.enforcement_changed` 에 바뀐 칸과 전후 값, 회수한 토큰 수가 남는다. 강제를 모두 풀어야 하는 장애 때는 서버 명령 `php artisan sirsoft-sso:enforcement --disable` 을 쓴다(이 API 의 검사를 거치지 않는다).

`super_only` 응답 예:

```json
{
    "success": false,
    "message": "최고관리자만 바꿀 수 있는 항목입니다.",
    "errors": { "code": "super_only" }
}
```


### GET /api/plugins/sirsoft-sso/admin/policy/impact
<!-- @generated:start:api.plugins.sirsoft-sso.admin.policy.impact -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.policy.impact`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\PolicyController@impact`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.settings.view`

**요청 파라미터**

저장 API 와 같은 칸을 쿼리로 받는다(바꿀 값만). 배열은 `enforce_roles[]=…` 꼴이다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| enforce_site | query | boolean | 아니오 | `1`, `0` | 바꿀 사이트 전체 강제 값 |
| enforce_roles | query | array | 아니오 | 50개, 있는 역할 식별자 | 바꿀 강제 역할 |
| enforce_linked | query | boolean | 아니오 | `1`, `0` | 바꿀 연결 회원 강제 값 |
| emergency_user_ids | query | array | 아니오 | 20개, 있는 회원 ID | 바꿀 비상 계정(계산에서 빠진다) |
| auto_redirect_idp | query | string | 아니오 | `^[a-z][a-z0-9_]{1,49}$` | 계산에 쓰지 않는다 |
| session_max_minutes | query | integer | 아니오 | 0~10080 | 계산에 쓰지 않는다 |
| discovery_enabled | query | boolean | 아니오 | `1`, `0` | 계산에 쓰지 않는다 |
| state_ttl_minutes | query | integer | 아니오 | 1~60 | 계산에 쓰지 않는다 |
| pending_ttl_minutes | query | integer | 아니오 | 1~60 | 계산에 쓰지 않는다 |
| attempts_retention_days | query | integer | 아니오 | 1~3650 | 계산에 쓰지 않는다 |
| scim_retention_days | query | integer | 아니오 | 1~3650 | 계산에 쓰지 않는다 |
| session_retention_days | query | integer | 아니오 | 1~3650 | 계산에 쓰지 않는다 |
| failure_alert_count | query | integer | 아니오 | 1~1000 | 계산에 쓰지 않는다 |
| failure_alert_window_minutes | query | integer | 아니오 | 1~1440 | 계산에 쓰지 않는다 |
| cert_expiry_notice_days | query | array | 아니오 | 5개, 각 1~365 | 계산에 쓰지 않는다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/admin/policy/impact?enforce_site=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| password_users | integer | — | 바꾸면 새로 강제 대상이 되는, 비밀번호가 있는 회원 수(비상 계정 제외) |
| password_sessions | integer | — | 그 회원들의 비밀번호 로그인 토큰 수(저장하면 회수된다) |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "영향 범위를 계산했습니다.",
    "data": { "password_users": "…", "password_sessions": "…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.settings.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

저장하지 않는다. 설정 화면이 강제 칸을 바꿀 때 「저장하면 N명의 비밀번호 로그인이 끝납니다」를 보이려고 부른다. 소유 도메인 강제(IdP 의 `enforce_owned_domains`)는 바뀌지 않는 것으로 보고 앞뒤 범위에 같이 넣는다. 그래서 이미 도메인 강제 대상인 회원은 세지 않는다. 최고관리자 전용 칸 검사는 하지 않는다(저장 때 한다).
