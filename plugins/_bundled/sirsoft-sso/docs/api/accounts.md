# Accounts API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 관리자 「회원 연결」(admin/accounts)과 마이페이지 「연결된 회사 계정」(me/accounts) API 다
2. 관리자: 목록, 연결 추가(회원이 없으면 비밀번호 없이 사전 등록), CSV 미리보기와 실행, 해제
3. 관리자 해제는 마지막 로그인 수단이면 409 last_method — confirm_last_method: true 로 다시 보낸다
4. 마이페이지 해제는 강제 대상(422 unlink_enforced)과 마지막 수단(422 last_method)을 막는다
5. 응답은 미실측 — 실측 예시값 열은 —, 응답 예시는 코드에서 옮긴 모양(… 는 실제 값 자리)
```

---


### GET /api/plugins/sirsoft-sso/admin/accounts
<!-- @generated:start:api.plugins.sirsoft-sso.admin.accounts.index -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.accounts.index`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\AccountController@index`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.accounts.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp_id | query | integer | 아니오 | 1 이상 | 이 IdP 의 연결만 |
| status | query | string | 아니오 | `active`, `inactive` | 활성 연결 또는 퇴사 처리된(비활성) 연결만 |
| via | query | string | 아니오 | `jit`, `domain`, `verify`, `admin`, `csv`, `scim` | 연결 방식으로 거른다 |
| q | query | string | 아니오 | 최대 100자 | 회원 이름 또는 이메일 부분 일치 |
| page | query | integer | 아니오 | 1 이상 | 페이지 번호 |
| per_page | query | integer | 아니오 | 1~100 | 페이지당 건수(기본 20) |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/admin/accounts?status=active&page=1&per_page=20 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| idps | array | — | 필터 선택지. 등록된 IdP 전부 `{id, key, label, protocol}` |
| data | array | — | 연결 행(최근 연결 순) |
| data[].id | integer | — | 연결 ID. 해제 API 의 `{id}` |
| data[].user | object\|null | — | 회원 `{id, uuid, name, email, has_password, is_privileged}`. `is_privileged` 는 관리자 또는 최고관리자 |
| data[].idp | object\|null | — | IdP `{id, key, label, protocol}` |
| data[].subject_masked | string | — | IdP 고유 식별자를 앞뒤 2자만 남기고 가린 값 |
| data[].linked_via | string | — | 연결 방식 `jit`(자동 생성), `domain`(소유 도메인 자동 연결), `verify`(본인 확인 연결), `admin`, `csv`, `scim` |
| data[].active | boolean | — | 연결 활성 여부(퇴사 처리면 false) |
| data[].deprovisioned_at | string\|null | — | 퇴사 처리 시각(ISO 8601) |
| data[].deprovision_reason | string\|null | — | 퇴사 처리 출처 `scim`, `periodic`, `login`, `admin` |
| data[].linked_at | string\|null | — | 연결 시각(ISO 8601) |
| data[].last_login_at | string\|null | — | 이 연결로 마지막 로그인한 시각(ISO 8601) |
| pagination | object | — | `{current_page, last_page, per_page, total}` |
| abilities | object | — | `{can_provision, can_unlink, is_super}` — 화면이 추가, 해제 단추와 관리자 대상 연결을 잠근다 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "회원 연결 목록을 불러왔습니다.",
    "data": {
        "idps": [{ "id": "…", "key": "…", "label": "…", "protocol": "oidc" }],
        "data": [
            {
                "id": "…",
                "user": { "id": "…", "uuid": "…", "name": "…", "email": "…", "has_password": false, "is_privileged": false },
                "idp": { "id": "…", "key": "…", "label": "…", "protocol": "oidc" },
                "subject_masked": "…",
                "linked_via": "jit",
                "active": true,
                "deprovisioned_at": null,
                "deprovision_reason": null,
                "linked_at": "…",
                "last_login_at": "…"
            }
        ],
        "pagination": { "current_page": 1, "last_page": "…", "per_page": 20, "total": "…" },
        "abilities": { "can_provision": true, "can_unlink": true, "is_super": false }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.accounts.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

관리자 메뉴 「SSO 연동 → 회원 연결」의 목록이다. 고유 식별자(subject)는 원문을 싣지 않고 가린 값만 싣는다.


### POST /api/plugins/sirsoft-sso/admin/accounts
<!-- @generated:start:api.plugins.sirsoft-sso.admin.accounts.store -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.accounts.store`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\AccountController@store`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.accounts.provision`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp_id | body | integer | 예 | 있는 IdP ID | 연결할 IdP |
| subject | body | string | 예 | 최대 191자 | IdP 가 보내는 고유 식별자(OIDC `sub` 나 `oid`, SAML NameID, LDAP 고유 속성) |
| user_id | body | integer | 조건부 | `new_user` 와 함께 보낼 수 없음 | 연결을 붙일 기존 회원 ID. `new_user` 가 없으면 필수 |
| new_user | body | object | 조건부 | — | 회원이 없을 때 비밀번호 없이 사전 등록할 회원. `user_id` 가 없으면 필수 |
| new_user.name | body | string | 아니오 | 최대 50자 | 이름. 비우면 이메일 앞부분 |
| new_user.email | body | email | 조건부 | 이메일, 최대 255자 | 사전 등록 이메일. `new_user` 를 보내면 필수 |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/admin/accounts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "idp_id": 1,
    "subject": "00u1a2b3c4d5e6f7g8h9",
    "new_user": {
        "name": "홍길동",
        "email": "hong@example.com"
    }
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | — | 만든 연결 ID |
| user | object | — | 연결된 회원 `{id, uuid, name, email, has_password, is_privileged}` |
| idp | object | — | IdP `{id, key, label, protocol}` |
| subject_masked | string | — | 가린 고유 식별자 |
| linked_via | string | — | 언제나 `admin` |
| active | boolean | — | 언제나 true |
| deprovisioned_at | null | — | 새 연결이라 비어 있다 |
| deprovision_reason | null | — | 새 연결이라 비어 있다 |
| linked_at | string | — | 연결 시각(ISO 8601) |
| last_login_at | null | — | 아직 로그인 전 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "연결을 추가했습니다.",
    "data": {
        "id": "…",
        "user": { "id": "…", "uuid": "…", "name": "…", "email": "…", "has_password": false, "is_privileged": false },
        "idp": { "id": "…", "key": "…", "label": "…", "protocol": "oidc" },
        "subject_masked": "…",
        "linked_via": "admin",
        "active": true,
        "deprovisioned_at": null,
        "deprovision_reason": null,
        "linked_at": "…",
        "last_login_at": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.accounts.provision`)이 없는 경우, 또는 `errors.code` = `admin_target_manual_only` — 관리자 계정에 붙이려는데 행위자가 최고관리자가 아님 |
| 404 | Not Found | `errors.code` = `user_not_found`(`user_id` 회원 없음) 또는 `idp_not_found` |
| 409 | Conflict | `errors.code` = `already_linked`(같은 IdP 와 subject 의 연결이 있거나 그 회원이 이 IdP 에 이미 연결됨) 또는 `email_in_use`(사전 등록 이메일을 다른 회원이 씀) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지), 또는 `errors.code` = `email_required` |

<!-- @generated:end -->

**설명**

기존 회원(`user_id`)에 연결을 붙이거나, 회원이 없으면 `new_user` 로 비밀번호 없는 회원을 만든 뒤 붙인다. 사전 등록은 회사가 만드는 계정이라 가입 허용 설정, 가입 본인인증 정책, 약관 동의 기록과 무관하다. 연결 직후 훅 `sirsoft-sso.account.after_link` 가 `via = admin` 으로 나간다.


### POST /api/plugins/sirsoft-sso/admin/accounts/import
<!-- @generated:start:api.plugins.sirsoft-sso.admin.accounts.import -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.accounts.import`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\AccountController@import`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.accounts.provision`
- **요청 제한**: `throttle:20,1,sirsoft-sso.accounts-import`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| csv | body | string | 예 | 최대 1048576자 | CSV 본문. 첫 줄은 `email,name,idp_key,subject` 열을 포함해야 하고(순서 무관, 대소문자 무관, UTF-8 BOM 허용), 데이터는 1000줄까지 |
| mode | body | string | 예 | `preview`, `execute` | `preview` 는 판정만, `execute` 는 `create` 와 `link` 행을 실제로 처리 |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/admin/accounts/import HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "csv": "email,name,idp_key,subject\nhong@example.com,홍길동,corp_oidc,00u1a2b3c4d5e6f7g8h9",
    "mode": "preview"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| rows | array | — | 행별 판정 |
| rows[].line | integer | — | CSV 줄 번호(머리 행이 1) |
| rows[].email | string | — | 소문자로 맞춘 이메일 |
| rows[].name | string | — | 이름 |
| rows[].idp_key | string | — | IdP 키 |
| rows[].subject | string | — | 고유 식별자(관리자가 보낸 값) |
| rows[].outcome | string | — | `create`(사전 등록 후 연결), `link`(기존 회원에 연결), `exists`(이미 같은 연결), `conflict`, `admin_target_manual_only`, `error` |
| rows[].reason | string\|null | — | `email_invalid`, `subject_invalid`, `idp_not_found`, `duplicate_in_file`, `subject_linked_to_other`, `user_linked_other_subject`, `admin_target_manual_only`, `user_not_found` 또는 실행 중 실패한 오류 코드 |
| counts | object | — | 결과 종류별 건수 `{create, link, exists, conflict, admin_target_manual_only, error}` |
| executed | boolean | — | `mode = execute` 였는지 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "CSV 미리보기를 만들었습니다.",
    "data": {
        "rows": [
            { "line": 2, "email": "…", "name": "…", "idp_key": "…", "subject": "…", "outcome": "create", "reason": null }
        ],
        "counts": { "create": 1, "link": 0, "exists": 0, "conflict": 0, "admin_target_manual_only": 0, "error": 0 },
        "executed": false
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.accounts.provision`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 또는 `errors.code` = `csv_empty`, `csv_header_invalid`, `csv_too_many_rows` |
| 429 | Too Many Requests | 분당 20회를 넘은 경우 |

<!-- @generated:end -->

**설명**

같은 CSV 를 `preview` 로 먼저 보내 판정을 보여 주고, 확인하면 `execute` 로 다시 보낸다. 실행 때 행 하나가 실패해도 그 행만 `conflict` 또는 `error` 로 남고 나머지는 처리된다. 관리자 계정은 CSV 로 연결하지 않는다(`admin_target_manual_only`). 실행하면 활동 로그 `sso.csv_imported` 가 남는다. 성공 `message` 는 실행이면 「CSV 가져오기를 마쳤습니다.」다.


### DELETE /api/plugins/sirsoft-sso/admin/accounts/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.admin.accounts.destroy -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.accounts.destroy`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\AccountController@destroy`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.accounts.unlink`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | 연결 ID |
| confirm_last_method | query | boolean | 아니오 | `1`, `0` (JSON 본문이면 `true`, `false`) | 마지막 로그인 수단이어도 해제한다(409 `last_method` 를 받은 뒤 확인하고 다시 보낼 때) |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-sso/admin/accounts/{id}?confirm_last_method=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | — | 해제한 연결 ID |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "연결을 해제했습니다.",
    "data": { "id": "…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.accounts.unlink`)이 없는 경우 |
| 404 | Not Found | `errors.code` = `account_not_found` — 연결이 없음 |
| 409 | Conflict | `errors.code` = `last_method` — 비밀번호가 없는 회원의 마지막 연결이고 `confirm_last_method` 가 참이 아님 |
| 422 | Unprocessable Entity | `confirm_last_method` 가 참거짓 값이 아닌 경우 |

<!-- @generated:end -->

**설명**

연결 행을 지운다(퇴사 처리와 다르다 — 회원 차단이나 토큰 회수를 하지 않는다). 해제 전후로 훅 `sirsoft-sso.account.before_unlink`, `sirsoft-sso.account.after_unlink` 가 사유 `admin` 으로 나간다. 409 를 받으면 화면은 「이 회원은 로그인할 수 없게 됩니다」를 확인받고 `confirm_last_method=1` 로 다시 보낸다. 본문(JSON `{"confirm_last_method": true}`)으로 보내도 받는다.


### GET /api/plugins/sirsoft-sso/me/accounts
<!-- @generated:start:api.plugins.sirsoft-sso.me.accounts.index -->
- **라우트명**: `api.plugins.sirsoft-sso.me.accounts.index`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\MeController@index`
- **인증/권한**: `auth:sanctum`
- **요청 제한**: `throttle:60,1,sirsoft-sso.me`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/me/accounts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| accounts | array | — | 내 연결 |
| accounts[].id | integer | — | 연결 ID. 해제 API 의 `{id}` |
| accounts[].idp_key | string\|null | — | IdP 키 |
| accounts[].idp_label | string | — | 현재 로케일의 IdP 이름 |
| accounts[].active | boolean | — | 연결 활성 여부 |
| accounts[].linked_at | string\|null | — | 연결 시각(ISO 8601) |
| accounts[].last_login_at | string\|null | — | 마지막 로그인 시각(ISO 8601) |
| accounts[].unlink_block | string\|null | — | 해제를 막는 사유 `unlink_enforced`, `last_method`. null 이면 해제할 수 있다 |
| linkable | array | — | 아직 연결하지 않은, 로그인 버튼이 보이는 IdP `{key, label, initial, button_style}` |
| enforced | boolean | — | 이 회원이 회사 계정으로만 로그인해야 하는지(강제 대상) |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "연결된 회사 계정을 불러왔습니다.",
    "data": {
        "accounts": [
            { "id": "…", "idp_key": "…", "idp_label": "…", "active": true, "linked_at": "…", "last_login_at": "…", "unlink_block": null }
        ],
        "linkable": [
            { "key": "…", "label": "…", "initial": "…", "button_style": null }
        ],
        "enforced": false
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 429 | Too Many Requests | 이 버킷(`sirsoft-sso.me`)에서 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

마이페이지 「연결된 회사 계정」 카드의 데이터다. `linkable` 은 공개 IdP 목록(`GET /api/plugins/sirsoft-sso/idps`)에서 이미 연결한 IdP 를 뺀 것이다. 연결은 `POST /api/plugins/sirsoft-sso/me/links/start`(links.md)로 시작한다.


### DELETE /api/plugins/sirsoft-sso/me/accounts/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.me.accounts.destroy -->
- **라우트명**: `api.plugins.sirsoft-sso.me.accounts.destroy`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\MeController@destroy`
- **인증/권한**: `auth:sanctum`
- **요청 제한**: `throttle:60,1,sirsoft-sso.me`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | 내 연결 ID |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-sso/me/accounts/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | — | 해제한 연결 ID |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "회사 계정 연결을 해제했습니다.",
    "data": { "id": "…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | `errors.code` = `account_not_found` — 없는 연결이거나 내 연결이 아님 |
| 422 | Unprocessable Entity | `errors.code` = `unlink_enforced`(회사 계정으로만 로그인해야 하는 회원) 또는 `last_method`(비밀번호가 없고 연결이 하나뿐) |

<!-- @generated:end -->

**설명**

본인 연결을 지운다. 관리자 해제와 달리 마지막 로그인 수단 확인 우회(`confirm_last_method`)가 없고 상태도 409 가 아니라 422 다. 해제 훅은 사유 `user` 로 나간다.
