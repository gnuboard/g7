# Logs API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 관리자 「SSO 연동 → 이력」의 로그인 시도와 SCIM 수신 목록이다(권한 sirsoft-sso.logs.view)
2. 둘 다 같은 조건 칸(LogListRequest)을 받는다. 로그인 시도는 outcome, intent, SCIM 은 status_class 를 쓴다
3. 이력에는 토큰, 어설션 원문, SCIM 요청 본문을 남기지 않는다
4. 응답은 미실측 — 실측 예시값 열은 —, 응답 예시는 코드에서 옮긴 모양(… 는 실제 값 자리)
```

---


### GET /api/plugins/sirsoft-sso/admin/logs/attempts
<!-- @generated:start:api.plugins.sirsoft-sso.admin.logs.attempts -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.logs.attempts`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\LogController@attempts`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.logs.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp_id | query | integer | 아니오 | 1 이상 | 이 IdP 의 시도만 |
| outcome | query | string | 아니오 | 영문 소문자와 밑줄, 20자 | 결과 `handoff`, `pending`, `link_pending`, `linked`, `reauth`, `test`, `failed`, `denied` |
| intent | query | string | 아니오 | `login`, `link`, `reauth`, `test` | 시작 의도 |
| status_class | query | string | 아니오 | `success`, `error` | 이 목록에서는 쓰지 않는다(SCIM 수신 목록 전용) |
| from | query | date | 아니오 | `Y-m-d` | 이 날짜 0시부터 |
| to | query | date | 아니오 | `Y-m-d`, `from` 이후 | 이 날짜 23:59:59 까지 |
| page | query | integer | 아니오 | 1 이상 | 페이지 번호 |
| per_page | query | integer | 아니오 | 1~100 | 페이지당 건수(기본 20) |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/admin/logs/attempts?outcome=failed&from=2026-10-01&to=2026-10-25&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | array | — | 시도 행(최신순) |
| data[].id | integer | — | 시도 ID |
| data[].idp_id | integer\|null | — | IdP ID(IdP 를 찾지 못한 실패는 null) |
| data[].user_id | integer\|null | — | 확정된 회원 ID(로그인 전 실패는 null) |
| data[].intent | string | — | `login`, `link`, `reauth`, `test` |
| data[].guard | string | — | `user`, `admin` |
| data[].outcome | string | — | `handoff`(로그인 인계), `pending`(약관 동의 대기), `link_pending`(연결 확인 대기), `linked`, `reauth`, `test`, `failed`(IdP 나 검증 실패), `denied`(회원 결정에서 거절) |
| data[].reason_code | string\|null | — | 실패 코드(README 「로그인 흐름 오류 코드」) |
| data[].ip_address | string\|null | — | 요청 IP |
| data[].user_agent | string\|null | — | 브라우저 User-Agent |
| data[].created_at | string | — | 시도 시각 |
| pagination | object | — | `{current_page, last_page, per_page, total}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "이력을 불러왔습니다.",
    "data": {
        "data": [
            { "id": "…", "idp_id": "…", "user_id": null, "intent": "login", "guard": "user", "outcome": "failed", "reason_code": "state_expired", "ip_address": "…", "user_agent": "…", "created_at": "…" }
        ],
        "pagination": { "current_page": 1, "last_page": "…", "per_page": 20, "total": "…" }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.logs.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

로그인 왕복의 시작과 끝, 대기 API, 관리자 테스트가 남긴 시도 기록이다. 보존 기간은 정책 `attempts_retention_days`(기본 90일)다. `failed` 와 `denied` 기록 직후 훅 `sirsoft-sso.auth.login_failed` 가 나간다.


### GET /api/plugins/sirsoft-sso/admin/logs/scim
<!-- @generated:start:api.plugins.sirsoft-sso.admin.logs.scim -->
- **라우트명**: `api.plugins.sirsoft-sso.admin.logs.scim`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Admin\LogController@scim`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.logs.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp_id | query | integer | 아니오 | 1 이상 | 이 IdP 의 수신만 |
| status_class | query | string | 아니오 | `success`, `error` | `success` 는 상태 400 미만, `error` 는 400 이상 |
| outcome | query | string | 아니오 | 영문 소문자와 밑줄, 20자 | 이 목록에서는 쓰지 않는다(로그인 시도 목록 전용) |
| intent | query | string | 아니오 | `login`, `link`, `reauth`, `test` | 이 목록에서는 쓰지 않는다(로그인 시도 목록 전용) |
| from | query | date | 아니오 | `Y-m-d` | 이 날짜 0시부터 |
| to | query | date | 아니오 | `Y-m-d`, `from` 이후 | 이 날짜 23:59:59 까지 |
| page | query | integer | 아니오 | 1 이상 | 페이지 번호 |
| per_page | query | integer | 아니오 | 1~100 | 페이지당 건수(기본 20) |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/admin/logs/scim?status_class=error&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | array | — | 수신 행(최신순) |
| data[].id | integer | — | 수신 ID |
| data[].idp_id | integer\|null | — | 경로의 IdP 키로 찾은 IdP ID(없는 키면 null) |
| data[].method | string | — | HTTP 메서드 |
| data[].resource | string | — | `Users`, `Groups`, `ServiceProviderConfig`, `ResourceTypes`, `Schemas` |
| data[].target_id | string\|null | — | 경로의 자원 `{id}` |
| data[].status | integer | — | 응답 상태 코드 |
| data[].error_code | string\|null | — | 오류 응답의 `scimType`(없으면 null) |
| data[].created_at | string | — | 수신 시각 |
| pagination | object | — | `{current_page, last_page, per_page, total}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "이력을 불러왔습니다.",
    "data": {
        "data": [
            { "id": "…", "idp_id": "…", "method": "PATCH", "resource": "Users", "target_id": "…", "status": 400, "error_code": "invalidSyntax", "created_at": "…" }
        ],
        "pagination": { "current_page": 1, "last_page": "…", "per_page": 20, "total": "…" }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-sso.logs.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

SCIM 요청 한 건마다 인증 실패(401, 403)를 포함해 한 줄이 남는다. 요청 본문과 응답 본문은 남기지 않는다. 보존 기간은 정책 `scim_retention_days`(기본 90일)다. 수신 표가 아직 없으면(설치 직후) 빈 목록을 돌려준다.
