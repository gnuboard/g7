# Pending API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 처음 로그인 약관 동의 대기 API 다. 콜백이 /login?sso_pending={48자 토큰} 으로 넘긴 토큰을 로그인 화면 모달이 쓴다(손님)
2. 권한은 대기 토큰과 브라우저 묶음 쿠키 g7_sso_bind 다. 시작한 브라우저가 아니면 토큰을 폐기하고 403 bind_mismatch
3. 동의 완료는 회원을 만들고 handoff_code 를 돌려준다. 같은 응답에 코어 인계 묶음 쿠키 g7_login_bind 가 실린다
4. 코드는 그 응답을 받은 브라우저에서만 코어 인계 API(POST /api/auth/login/handoff, 관리자는 /api/auth/admin/login/handoff)로 교환된다
5. 응답은 미실측 — 실측 예시값 열은 —, 응답 예시는 코드에서 옮긴 모양(… 는 실제 값 자리)
```

---


### GET /api/plugins/sirsoft-sso/pending/{token}
<!-- @generated:start:api.plugins.sirsoft-sso.pending.show -->
- **라우트명**: `api.plugins.sirsoft-sso.pending.show`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\PendingController@show`
- **인증/권한**: 공개 (인증 불필요) — 대기 토큰 + 묶음 쿠키 `g7_sso_bind`
- **요청 제한**: `throttle:60,1,sirsoft-sso.pending-show`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | path | string | 예 | 영숫자 48자 | 동의 대기 토큰(`sso_pending` 쿼리 값) |
| g7_sso_bind | cookie | string | 예 | — | 로그인을 시작한 브라우저의 묶음 쿠키(브라우저가 자동으로 보낸다) |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/pending/{token} HTTP/1.1
Host: api.example.com
Accept: application/json
Cookie: g7_sso_bind={BIND}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| idp | object | — | `{key, label}` |
| guard | string | — | `user` 또는 `admin` — 완료 뒤 교환할 인계 경로 |
| name | string\|null | — | IdP 가 준 이름 |
| email | string\|null | — | 가입에 쓸 이메일을 가린 값(`h***@example.com`). 받을 수 없는 대체 주소면 null |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "가입 확인 정보를 불러왔습니다.",
    "data": {
        "idp": { "key": "corp_oidc", "label": "…" },
        "guard": "user",
        "name": "…",
        "email": "h***@example.com"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | `errors.code` = `bind_mismatch` — 묶음 쿠키가 없거나 시작한 브라우저와 다름. 토큰은 이때 폐기된다 |
| 404 | Not Found | `errors.code` = `pending_expired`(토큰이 없거나 만료) 또는 `idp_disabled`(그 사이 IdP 가 꺼짐 — 토큰 폐기). 토큰 형식이 아니면 라우트가 404 |
| 429 | Too Many Requests | 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

IdP 의 `require_consent` 가 켜진 상태에서 처음 온 사람을 자동 생성하려 할 때 콜백이 대기 토큰을 만든다. 토큰 수명은 정책 `pending_ttl_minutes`(기본 10분)다. 이 조회는 토큰을 지우지 않는다.


### POST /api/plugins/sirsoft-sso/pending/{token}/complete
<!-- @generated:start:api.plugins.sirsoft-sso.pending.complete -->
- **라우트명**: `api.plugins.sirsoft-sso.pending.complete`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\PendingController@complete`
- **인증/권한**: 공개 (인증 불필요) — 대기 토큰 + 묶음 쿠키 `g7_sso_bind`
- **요청 제한**: `throttle:20,1,sirsoft-sso.pending-complete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | path | string | 예 | 영숫자 48자 | 동의 대기 토큰 |
| agree_terms | body | boolean | 예 | `accepted` (`true`, `1`, `"yes"`, `"on"`) | 이용약관 동의 |
| agree_privacy | body | boolean | 예 | `accepted` (`true`, `1`, `"yes"`, `"on"`) | 개인정보 처리방침 동의 |
| fields_version | body | string | 아니오 | 최대 64자 | 동의 창이 받은 회원 항목의 정의 버전(코어 `GET /api/auth/register/fields` 의 `fields_version`). 실으면 운영자가 정한 가입 항목 규칙이 걸리고 항목 값이 저장된다. 지금 버전과 다르면 409(`error_code: fields_changed`). 아래 「회원 항목을 받는 동의 창」 참조 |
| (가입 항목) | body | mixed | 항목마다 다름 | — | `fields_version` 을 실은 요청만 받는다. 가입 항목 조회가 준 폼 경로(`mobile`, `custom_fields.{키}` 등)로 보낸다 |
| g7_sso_bind | cookie | string | 예 | — | 로그인을 시작한 브라우저의 묶음 쿠키 |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/pending/{token}/complete HTTP/1.1
Host: api.example.com
Accept: application/json
Cookie: g7_sso_bind={BIND}
Content-Type: application/json

{
    "agree_terms": true,
    "agree_privacy": true
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| handoff_code | string | — | 코어 로그인 인계 코드(일회용, 120초). 같은 브라우저에서 코어 인계 API 로 교환한다 |
| guard | string | — | `user` 면 `POST /api/auth/login/handoff`, `admin` 이면 `POST /api/auth/admin/login/handoff` 로 교환 |
| redirect | string | — | 로그인 뒤 갈 같은 출처 경로(기본 `/`) |

응답 헤더에 `Set-Cookie: g7_login_bind=…; path=/api/auth; httponly; samesite=lax`(120초)가 실린다. 본문에는 없다.

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
| 403 | Forbidden | `errors.code` = `bind_mismatch` — 시작한 브라우저가 아님(토큰 폐기) |
| 404 | Not Found | `errors.code` = `pending_expired` 또는 `idp_disabled` |
| 422 | Unprocessable Entity | 동의 칸이 `accepted` 가 아닌 경우 (`errors` 에 필드별 메시지), 또는 회원 결정 거절 `errors.code` = `already_linked`(같은 IdP 와 subject 연결이 이미 있음), `email_in_use`(같은 이메일 회원이 있어 새로 만들 수 없음) 등 |
| 429 | Too Many Requests | 분당 20회를 넘은 경우 |

<!-- @generated:end -->

**설명**

토큰을 먼저 지운 뒤 회원을 만든다. 그래서 같은 토큰으로 두 번 완료할 수 없고, 회원 결정이 거절돼도 토큰은 다시 쓸 수 없다(처음부터 다시 로그인). 회원은 비밀번호 없이 만들고 약관 동의를 기록한 뒤 연결(`via = jit`)을 붙인다. 화면(로그인 화면 모달)은 받은 `handoff_code` 로 엔진 `loginHandoff` 액션을 실행해 사용자 또는 관리자 로그인 결과로 분기한다. 묶음 쿠키 `g7_login_bind` 는 이 응답을 받은 브라우저에만 있으므로 코드를 다른 브라우저로 옮기면 교환되지 않는다.

**회원 항목을 받는 동의 창 (`fields_version`)**

동의 창이 코어 가입 화면과 같은 회원 항목(환경설정 > 회원 > 가입 항목)을 그렸으면 본문에 `fields_version` 을 싣는다. 이 값이 있고 없음에 따라 규칙과 회원이 달라진다.

| 본문 | 규칙 | 만들어지는 회원 |
| --- | --- | --- |
| `fields_version` 있음 | 약관 둘에 운영자가 정한 가입 항목 규칙(필수 포함)이 붙는다. 코어 가입 필터 `core.auth.register_validation_rules` 도 타되 본인인증 확인 토큰(`verification_token`) 규칙은 뺀다. 운영자 추가 항목은 `custom_fields.{키}`, 그 밖의 항목은 가입 항목 조회가 준 폼 경로(`name`)로 받는다 | 받은 항목 값을 저장한다 |
| `fields_version` 없음 | 약관 둘(종전과 같다) | 운영자가 필수로 둔 회원 항목은 건너뛰고 `users.required_fields_deferred_at` 에 시각을 남긴다. 그 회원이 사용자 화면에 처음 로그인하면 추가 정보 입력 화면이 빠진 항목을 받는다 |

- 항목과 정의 버전은 코어 `GET /api/auth/register/fields?pending_source=sirsoft-sso&pending_token={동의 대기 토큰}` 에서 받는다(묶음 쿠키 `g7_sso_bind` 가 같이 가야 한다). IdP 속성 규칙이 값을 주는 칸은 그 값이 초기값(`default`)으로, 읽기 전용(`readonly: true`, `locked_reason: "sso"`)으로 온다. 속성 규칙이 없는 이름 칸은 자동 생성 이름이 초기값이고 고칠 수 있다. 다른 브라우저의 조회에는 값을 주지 않고 대기도 폐기하지 않는다.
- IdP 가 값을 주는 칸은 규칙에서 빠진다. 본문에 다른 값을 실어도 무시하고 IdP 값으로 저장한다.
- 실어 보낸 `fields_version` 이 지금의 정의 버전과 다르면 검증보다 먼저 409 로 답하고 본문에 `error_code: "fields_changed"` 가 실린다. 409 와 422(검증 오류)는 토큰을 지우기 전에 답하므로 같은 토큰으로 다시 보낼 수 있다.
- 관리자 로그인 화면에서 시작한 대기(`guard = admin`)는 `fields_version` 을 실어도 항목 값을 저장하지 않고 건너뛴 것으로 표시한다(관리자 화면에는 추가 정보 입력으로 이끄는 화면이 없다).
- 필터 훅 `sirsoft-sso.jit.filter_registration_data` 의 이름과 인자는 그대로다. 항목을 받은 요청이면 가입 데이터에 항목 값이 함께 실린다.
