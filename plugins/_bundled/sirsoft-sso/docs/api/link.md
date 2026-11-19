# Link API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 기존 회원 연결 확인 대기 API 다. 콜백이 /login?sso_link={48자 토큰} 으로 넘긴 토큰을 로그인 화면 모달이 쓴다(손님)
2. IdP 의 이메일과 같은 이메일 회원이 있고 IdP 연결 방식 verify 가 켜졌을 때, 그 회원이 비밀번호나 메일 인증번호로 증명해야 연결된다
3. 대기 토큰은 시작 브라우저의 묶음 쿠키 g7_sso_bind 와 대조한다. 다르면 토큰을 폐기하고 403 bind_mismatch
4. 확인이 끝나면 handoff_code 를 돌려주고 같은 응답에 코어 인계 묶음 쿠키 g7_login_bind 를 싣는다(그 브라우저에서만 교환)
5. 비밀번호를 3번 틀리면 토큰이 폐기된다(404 link_expired). 비밀번호 실패는 코어 로그인 실패 횟수에도 들어간다
6. 응답은 미실측 — 실측 예시값 열은 —, 응답 예시는 코드에서 옮긴 모양(… 는 실제 값 자리)
```

---


### GET /api/plugins/sirsoft-sso/link/{token}
<!-- @generated:start:api.plugins.sirsoft-sso.link.show -->
- **라우트명**: `api.plugins.sirsoft-sso.link.show`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\LinkController@show`
- **인증/권한**: 공개 (인증 불필요) — 대기 토큰 + 묶음 쿠키 `g7_sso_bind`
- **요청 제한**: `throttle:60,1,sirsoft-sso.link-show`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | path | string | 예 | 영숫자 48자 | 연결 확인 대기 토큰(`sso_link` 쿼리 값) |
| g7_sso_bind | cookie | string | 예 | — | 로그인을 시작한 브라우저의 묶음 쿠키 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/link/{token} HTTP/1.1
Host: api.example.com
Accept: application/json
Cookie: g7_sso_bind={BIND}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| idp | object | — | `{key, label}` |
| guard | string | — | `user` 또는 `admin` |
| email | string | — | 연결할 기존 회원 이메일을 가린 값(`h***@example.com`) |
| methods | array | — | 쓸 수 있는 확인 방법. 비밀번호가 있으면 `password`, 메일을 받을 수 있으면 `email` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "계정 연결 확인 정보를 불러왔습니다.",
    "data": {
        "idp": { "key": "corp_oidc", "label": "…" },
        "guard": "user",
        "email": "h***@example.com",
        "methods": ["password", "email"]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | `errors.code` = `bind_mismatch`(시작한 브라우저가 아님) 또는 `admin_target_manual_only`(대상 회원이 그 사이 관리자가 됨). 둘 다 토큰을 폐기한다 |
| 404 | Not Found | `errors.code` = `link_expired`(토큰이 없거나 만료, 대상 회원이 사라졌거나 탈퇴) 또는 `idp_disabled`(IdP 가 꺼짐) |
| 429 | Too Many Requests | 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

토큰 수명은 정책 `pending_ttl_minutes`(기본 10분)다. 이 조회는 토큰을 지우지 않는다.


### POST /api/plugins/sirsoft-sso/link/{token}/email
<!-- @generated:start:api.plugins.sirsoft-sso.link.email -->
- **라우트명**: `api.plugins.sirsoft-sso.link.email`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\LinkController@email`
- **인증/권한**: 공개 (인증 불필요) — 대기 토큰 + 묶음 쿠키 `g7_sso_bind`
- **요청 제한**: `throttle:5,1,sirsoft-sso.link-email`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | path | string | 예 | 영숫자 48자 | 연결 확인 대기 토큰 |
| g7_sso_bind | cookie | string | 예 | — | 로그인을 시작한 브라우저의 묶음 쿠키 |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/link/{token}/email HTTP/1.1
Host: api.example.com
Accept: application/json
Cookie: g7_sso_bind={BIND}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| challenge_id | string | — | 코어 본인인증 요청 ID. 확인 API 의 `challenge_id` 로 보낸다 |
| expires_at | string | — | 인증번호 만료 시각(ISO 8601) |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "인증번호를 메일로 보냈습니다.",
    "data": { "challenge_id": "…", "expires_at": "…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | `errors.code` = `bind_mismatch` 또는 `admin_target_manual_only`(토큰 폐기) |
| 404 | Not Found | `errors.code` = `link_expired` 또는 `idp_disabled` |
| 422 | Unprocessable Entity | `errors.code` = `email_not_available` — 기존 회원이 메일을 받을 수 없는 대체 주소 |
| 429 | Too Many Requests | 분당 5회를 넘은 경우 |
| 503 | Service Unavailable | `errors.code` = `mail_failed` — 인증번호 메일을 보내지 못함(사이트 메일 설정 문제, 서버 로그에 남는다) |

<!-- @generated:end -->

**설명**

기존 회원 이메일로 코어 본인인증(목적 `sensitive_action`, 메일 수단 `g7:core.mail`) 인증번호를 보낸다. 다시 부르면 새 요청이 만들어지고 대기 값의 `challenge_id` 가 바뀐다(마지막 것만 확인된다).


### POST /api/plugins/sirsoft-sso/link/{token}/verify
<!-- @generated:start:api.plugins.sirsoft-sso.link.verify -->
- **라우트명**: `api.plugins.sirsoft-sso.link.verify`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\LinkController@verify`
- **인증/권한**: 공개 (인증 불필요) — 대기 토큰 + 묶음 쿠키 `g7_sso_bind`
- **요청 제한**: `throttle:10,1,sirsoft-sso.link-verify`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | path | string | 예 | 영숫자 48자 | 연결 확인 대기 토큰 |
| method | body | string | 예 | `password`, `email` | 확인 방법 |
| password | body | string | 조건부 | 최대 255자 | 기존 회원 비밀번호. `method = password` 면 필수 |
| challenge_id | body | string | 조건부 | 최대 64자 | 인증번호 요청 API 가 준 값. `method = email` 이면 필수 |
| code | body | string | 조건부 | 최대 20자 | 메일로 받은 인증번호. `method = email` 이면 필수 |
| g7_sso_bind | cookie | string | 예 | — | 로그인을 시작한 브라우저의 묶음 쿠키 |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/link/{token}/verify HTTP/1.1
Host: api.example.com
Accept: application/json
Cookie: g7_sso_bind={BIND}
Content-Type: application/json

{
    "method": "email",
    "challenge_id": "{CHALLENGE_ID}",
    "code": "123456"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| handoff_code | string | — | 코어 로그인 인계 코드(일회용, 120초) |
| guard | string | — | `user` 면 `POST /api/auth/login/handoff`, `admin` 이면 `POST /api/auth/admin/login/handoff` 로 교환 |
| redirect | string | — | 로그인 뒤 갈 같은 출처 경로(기본 `/`) |

응답 헤더에 `Set-Cookie: g7_login_bind=…; path=/api/auth; httponly; samesite=lax`(120초)가 실린다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Set-Cookie: g7_login_bind={MASKED}; path=/api/auth; httponly; samesite=lax
```

```json
{
    "success": true,
    "message": "로그인을 이어서 진행합니다.",
    "data": {
        "handoff_code": "{MASKED}",
        "guard": "user",
        "redirect": "/"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | `errors.code` = `bind_mismatch` 또는 `admin_target_manual_only`(토큰 폐기) |
| 404 | Not Found | `errors.code` = `link_expired`(토큰 만료, 또는 비밀번호 3번째 실패로 토큰 폐기) 또는 `idp_disabled` |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 또는 `errors.code` = `password_mismatch`, `password_not_available`(비밀번호 없는 회원), `challenge_mismatch`(이 대기에 발급한 인증 요청이 아님), `invalid_code`, 회원 결정 거절(`already_linked` 등) |
| 429 | Too Many Requests | 분당 10회를 넘은 경우 |

<!-- @generated:end -->

**설명**

확인이 끝나면 토큰을 지우고 연결(`via = verify`)을 붙인 뒤 인계 코드를 발급한다. 비밀번호 실패는 코어 훅 `core.auth.login_failed`(출처 `sirsoft-sso:link_verify`)로 알려 코어 로그인 잠금 계산에 들어간다. 메일 확인에 쓴 본인인증 토큰은 여기서 소비해 다른 민감 작업에 다시 쓰지 못한다. 화면은 받은 `handoff_code` 로 엔진 `loginHandoff` 액션을 실행한다. 코드는 이 응답을 받은 브라우저에서만 교환된다.
