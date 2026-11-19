# Signup API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Signup 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/sirsoft-social_login/signup/complete
<!-- @generated:start:api.plugins.sirsoft-social_login.signup.complete -->
- **라우트명**: `api.plugins.sirsoft-social_login.signup.complete`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\SignupController@complete`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pending_token | body | string | 예 | — | 가입 대기 토큰(48자 영숫자, `size:48` `alpha_num`). 콜백이 `/register?social_pending=…&social_step=…` 으로 넘긴 값이며, 이 토큰이 곧 권한이다 |
| name | body | string | 아니오 | max 255 | 대상의 이름/명칭 |
| nickname | body | string | 아니오 | max 50 | 닉네임. 비우면 제공자가 준 닉네임을 쓴다. 사이트의 닉네임 정책(환경설정 > 회원의 중복 금지, 금지어)에 걸리면 422 이고 `errors.nickname` 에 사유가 실린다. 가입 대기는 남으므로 닉네임을 바꿔 다시 보낼 수 있다 |
| email | body | string | 아니오 | max 255 | 이메일 주소 |
| agree_terms | body | boolean | 예 | — | 이용약관 동의. `accepted` 규칙이라 `true`, `1`, `"yes"`, `"on"` 가운데 하나여야 하며 빠지면 422 |
| agree_privacy | body | boolean | 예 | — | 개인정보 처리방침 동의. `accepted` 규칙이라 `true`, `1`, `"yes"`, `"on"` 가운데 하나여야 하며 빠지면 422 |
| verification_token | body | string | 아니오 | max 255 | 코어 본인인증 확인 토큰. 본인인증 정책 `core.auth.signup_before_submit` 이 켜진 사이트에서 428 응답 뒤 본인인증을 마치고 받은 값을 넣는다 |
| fields_version | body | string | 아니오 | max 64 | 가입 창이 받은 회원 항목의 정의 버전(코어 `GET /api/auth/register/fields` 의 `fields_version`). 실으면 운영자가 정한 가입 항목 규칙이 걸리고 항목 값이 저장된다. 지금 버전과 다르면 409(`error_code: fields_changed`). 아래 「회원 항목을 받는 가입 창」 참조 |
| (가입 항목) | body | mixed | 항목마다 다름 | — | `fields_version` 을 실은 요청만 받는다. 가입 항목 조회가 준 폼 경로(`mobile`, `custom_fields.{키}` 등)로 보낸다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/signup/complete HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "pending_token": "{YOUR_TOKEN}",
    "name": "예시 이름",
    "nickname": "예시 이름",
    "email": "user@example.com",
    "agree_terms": "예시값",
    "agree_privacy": "예시값",
    "verification_token": "{YOUR_TOKEN}"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| handoff_url | string | `/login?login_handoff=…` | 코어 로그인 인계 주소(같은 출처 상대 경로). 화면이 이 주소로 이동하면 코어가 로그인시킨다(가입 직후 로그인은 2단계 인증을 거치지 않는다). 묶음 쿠키 `g7_login_bind` 가 `Set-Cookie` 응답 헤더로 함께 오며 본문에는 없다 |
| redirect_url | string | `/login?social_notice=pending_verification` | 코어 가입 이메일 인증이 켜져 회원이 인증 대기 상태로 만들어진 경우에만 `handoff_url` 대신 실린다. 이때는 로그인하지 않는다 |

**응답 예시**

```http
HTTP/1.1 200
Set-Cookie: g7_login_bind=…; path=/api/auth; httponly; samesite=lax
```

```json
{
    "success": true,
    "message": "가입을 마쳤습니다.",
    "data": {
        "handoff_url": "/login?login_handoff=…&redirect=%2Fmypage"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | `errors.code` = `email_not_verified`(입력 모드에서 인증번호로 확인한 이메일과 다른 이메일을 보냈거나 확인 전), `name_required`(제공자 이름이 없는데 `name` 도 비었다), `email_taken`(그 이메일을 다른 회원이 쓴다) |
| 403 | Forbidden | `errors.code` = `registration_closed`: 가입 대기를 만든 뒤 사이트 회원가입이 닫혔다 |
| 404 | Not Found | `errors.code` = `expired`: 가입 대기 토큰이 없거나 유효 시간(설정 `pending_signup_ttl_minutes`, 기본 30분)이 지났다 |
| 409 | Conflict | `errors.code` = `existing_account_pending`: 같은 이메일의 기존 계정이 있는 단계다. 기존 계정 연결이나 `discard-existing` 을 먼저 거친다 |
| 409 | Conflict | `errors.code` = `already_linked_other_user` 또는 `provider_already_linked`: 연결을 붙이는 단계에서 제공자 계정이 이미 다른 회원에 연결돼 있다 |
| 428 | Precondition Required | 본인인증 정책 `core.auth.signup_before_submit` 이 켜졌는데 `verification_token` 이 없다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:20,1,sirsoft-social_login.signup-complete`) |

<!-- @generated:end -->

**설명**

소셜 가입 모달의 [가입] 단추가 부른다. 비밀번호 없이 회원을 만들고 제공자 계정을 연결한다. 회원 생성은 코어 `AuthService::register(…, ['issue_token' => false])` 로 하므로 코어 가입 규칙(약관 동의, 가입 허용, 이메일 인증 정책)을 그대로 따른다.

- 이메일은 모드에 따라 정해진다. 고정 모드와 대체 주소 모드는 서버가 가진 주소를 쓰고 본문 `email` 은 보지 않는다. 입력 모드는 `signup/email/verify` 로 확인한 이메일과 대소문자 무시로 같아야 한다.
- 인증번호로 확인한 이메일과 제공자가 검증했다고 한 고정 이메일만 회원의 이메일 인증으로 기록한다.
- 확장은 필터 훅 `sirsoft-social_login.signup.filter_registration_data` 로 가입 데이터를 바꿀 수 있고, 가입 뒤 액션 훅 `sirsoft-social_login.auth.after_social_signup` 이 발행된다.
- 성공하면 가입 대기 토큰은 지워진다.

**회원 항목을 받는 가입 창 (`fields_version`)**

가입 창이 코어 가입 화면과 같은 회원 항목(환경설정 > 회원 > 가입 항목)을 그렸으면 본문에 `fields_version` 을 싣는다. 이 값이 있고 없음에 따라 규칙과 회원이 달라진다.

| 본문 | 규칙 | 만들어지는 회원 |
| --- | --- | --- |
| `fields_version` 있음 | 위 표의 규칙에서 `name`, `nickname` 이 빠지고 운영자가 정한 가입 항목 규칙(필수 포함)이 붙는다. 코어 가입 필터 `core.auth.register_validation_rules` 도 탄다. 운영자 추가 항목은 `custom_fields.{키}`, 그 밖의 항목은 가입 항목 조회가 준 폼 경로(`name`)로 받는다 | 받은 항목 값을 저장한다. `name`, `nickname` 은 보낸 값이 원본이다(비워 보내면 비운 채 저장하고 제공자 값으로 채우지 않는다) |
| `fields_version` 없음 | 위 표 그대로(종전과 같다) | 운영자가 필수로 둔 다른 회원 항목은 건너뛰고 `users.required_fields_deferred_at` 에 시각을 남긴다. 그 회원이 처음 로그인하면 추가 정보 입력 화면이 빠진 항목을 받는다 |

- 항목과 정의 버전은 코어 `GET /api/auth/register/fields?pending_source=sirsoft-social_login&pending_token={가입 대기 토큰}` 에서 받는다. 토큰을 넘기면 제공자가 준 이름과 닉네임이 그 항목의 초기값(`default`)으로 온다.
- 실어 보낸 `fields_version` 이 지금의 정의 버전과 다르면(창을 연 뒤 운영자가 항목을 바꿨다) 검증보다 먼저 409 로 답하고 본문에 `error_code: "fields_changed"` 가 실린다. 가입 대기는 남으므로 항목을 다시 받아 이어 간다.
- 필수 항목이 비면 422 이고 `errors` 의 키가 그 항목의 폼 경로다(예: `custom_fields.job`).
- `GET signup/pending` 의 `missing` 에는 운영자가 가입 필수로 둔 회원 항목 가운데 제공자 값으로 채울 수 없는 항목의 키도 실린다.
- 필터 훅 `sirsoft-social_login.signup.filter_registration_data` 의 이름과 인자는 그대로다. 항목을 받은 요청이면 가입 데이터에 항목 값이 함께 실린다.


### POST /api/plugins/sirsoft-social_login/signup/discard-existing
<!-- @generated:start:api.plugins.sirsoft-social_login.signup.discard-existing -->
- **라우트명**: `api.plugins.sirsoft-social_login.signup.discard-existing`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\LinkExistingController@discard`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pending_token | body | string | 예 | — | 가입 대기 토큰(48자 영숫자, `size:48` `alpha_num`). 콜백이 `/register?social_pending=…&social_step=…` 으로 넘긴 값이며, 이 토큰이 곧 권한이다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/signup/discard-existing HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "pending_token": "{YOUR_TOKEN}"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| provider | object | `{"key":"kakao","label":"카카오","brand":"kakao"}` | 제공자 키, 표시 이름(현재 로케일), 버튼 모양 브랜드 |
| step | string | `complete` | 이 호출 뒤에는 언제나 `complete` 다 |
| name | string\|null | `홍길동` | 제공자가 준 이름. 없으면 별명, 둘 다 없으면 `null` |
| nickname | string\|null | `길동` | 제공자가 준 별명 |
| email | object | `{"mode":"input","value":null,"verified":false,"requires_code":true,"challenge_id":null}` | 이메일 칸 상태. `mode` 는 `locked`(제공자가 준 이메일로 고정), `input`(입력하고 인증번호로 확인), `placeholder`(메일을 받을 수 없는 대체 주소 `{제공자}_{해시}@social.invalid`). `value` 는 고정 모드면 그 주소, 입력 모드면 확인한 이메일이나 미리 채울 이메일. `verified` 는 입력 모드에서 인증번호 확인을 마쳤거나 입력 모드가 아니면 `true`. `requires_code` 는 입력 모드일 때 `true`. `challenge_id` 는 마지막으로 보낸 인증 요청 ID |
| missing | array | `["email"]` | 가입 전에 채워야 할 칸. 입력 모드면 `email`, 제공자가 이름과 별명을 모두 주지 않았으면 `name` |
| show_profile_fields | boolean | `false` | 이름과 별명 입력 칸을 보일지. 설정 `extra_info_mode` 가 `always` 이거나 이름이 비었으면 `true` |
| existing_account | null | `null` | 기존 계정 단계를 버렸으므로 언제나 `null` |
| expires_at | string | `2026-10-20T12:30:00+09:00` | 가입 대기 만료 시각(시간대가 붙은 ISO 8601) |

**응답 예시**

```json
{
    "success": true,
    "message": "가입 대기 정보입니다.",
    "data": {
        "provider": { "key": "kakao", "label": "카카오", "brand": "kakao" },
        "step": "complete",
        "name": "홍길동",
        "nickname": "길동",
        "email": { "mode": "input", "value": null, "verified": false, "requires_code": true, "challenge_id": null },
        "missing": ["email"],
        "show_profile_fields": false,
        "existing_account": null,
        "expires_at": "2026-10-20T12:30:00+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 404 | Not Found | `errors.code` = `expired`: 가입 대기 토큰이 없거나 유효 시간(설정 `pending_signup_ttl_minutes`, 기본 30분)이 지났다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:10,1,sirsoft-social_login.discard-existing`) |

<!-- @generated:end -->

**설명**

「이미 가입된 이메일」 모달에서 [새 계정으로 가입]을 고를 때 부른다. 기존 계정에 연결하지 않고 새 계정 가입 단계로 바꾼 뒤, `signup/pending` 과 같은 모양의 화면 데이터를 돌려준다.

겹친 이메일은 쓸 수 없으므로 이메일 칸은 제공자의 「이메일 미제공」 정책과 관계없이 입력 모드(`mode: input`, 미리 채운 값 없음)로 바뀐다. 이후 `signup/email/request` 로 다른 이메일을 확인하고 `signup/complete` 로 가입한다.


### POST /api/plugins/sirsoft-social_login/signup/email/request
<!-- @generated:start:api.plugins.sirsoft-social_login.signup.email.request -->
- **라우트명**: `api.plugins.sirsoft-social_login.signup.email.request`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\SignupController@requestEmailCode`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pending_token | body | string | 예 | — | 가입 대기 토큰(48자 영숫자, `size:48` `alpha_num`). 콜백이 `/register?social_pending=…&social_step=…` 으로 넘긴 값이며, 이 토큰이 곧 권한이다 |
| email | body | string | 예 | max 255 | 이메일 주소 |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/signup/email/request HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "pending_token": "{YOUR_TOKEN}",
    "email": "user@example.com"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| challenge_id | string | `9f1c2a6e-3b7d-4e8a-a0c5-6d2f4b8e1a37` | 코어 본인인증 요청 ID. 인증번호 확인 요청의 `challenge_id` 로 보낸다 |
| expires_at | string | `2026-10-20T12:15:00+09:00` | 인증번호 만료 시각(시간대가 붙은 ISO 8601). 화면이 남은 시간을 계산한다 |

**응답 예시**

```json
{
    "success": true,
    "message": "인증번호를 보냈습니다.",
    "data": {
        "challenge_id": "9f1c2a6e-3b7d-4e8a-a0c5-6d2f4b8e1a37",
        "expires_at": "2026-10-20T12:15:00+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | `errors.code` = `email_locked`(이메일 칸이 입력 모드가 아니다), `email_taken`(그 이메일을 쓰는 회원이 있다) |
| 404 | Not Found | `errors.code` = `expired`: 가입 대기 토큰이 없거나 유효 시간(설정 `pending_signup_ttl_minutes`, 기본 30분)이 지났다 |
| 503 | Service Unavailable | `errors.code` = `mail_failed`: 인증번호 메일을 보내지 못했다(사이트 메일 설정 문제). 시도 기록에 실패로 남는다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:10,1,sirsoft-social_login.signup-email-request`) |

<!-- @generated:end -->

**설명**

가입 모달의 이메일 칸이 입력 모드일 때 [인증번호 받기]가 부른다. 코어 본인인증(목적 `signup`, 메일 수단 `g7:core.mail`)으로 입력한 주소에 인증번호를 보낸다.

다시 부르면 새 인증 요청으로 바뀌고, 이미 확인한 이메일은 지워진다(새 주소로 다시 확인해야 한다). 응답의 `challenge_id` 를 `signup/email/verify` 에 보낸다.


### POST /api/plugins/sirsoft-social_login/signup/email/verify
<!-- @generated:start:api.plugins.sirsoft-social_login.signup.email.verify -->
- **라우트명**: `api.plugins.sirsoft-social_login.signup.email.verify`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\SignupController@verifyEmailCode`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pending_token | body | string | 예 | — | 가입 대기 토큰(48자 영숫자, `size:48` `alpha_num`). 콜백이 `/register?social_pending=…&social_step=…` 으로 넘긴 값이며, 이 토큰이 곧 권한이다 |
| challenge_id | body | string | 예 | max 64 | challenge 식별자 |
| code | body | string | 예 | max 10 | 메일로 받은 인증번호(최대 10자) |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/signup/email/verify HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "pending_token": "{YOUR_TOKEN}",
    "challenge_id": "예시값",
    "code": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| verified_email | string | `user@example.com` | 인증번호로 확인한 이메일. `signup/complete` 의 `email` 로 같은 값을 보낸다 |

**응답 예시**

```json
{
    "success": true,
    "message": "이메일을 확인했습니다.",
    "data": {
        "verified_email": "user@example.com"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | `errors.code` = `challenge_mismatch`(이 가입 대기에 마지막으로 발급한 인증 요청이 아니다), `invalid_code`(인증번호가 틀렸거나 만료됐다) |
| 404 | Not Found | `errors.code` = `expired`: 가입 대기 토큰이 없거나 유효 시간(설정 `pending_signup_ttl_minutes`, 기본 30분)이 지났다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:10,1,sirsoft-social_login.signup-email-verify`) |

<!-- @generated:end -->

**설명**

`signup/email/request` 로 받은 인증번호를 확인한다. 확인이 끝나면 그 이메일이 가입 대기에 기록되고, 가입 모달은 `signup/complete` 로 넘어간다.

`challenge_id` 는 이 가입 대기에 마지막으로 발급한 값이어야 한다. 다른 가입이나 다른 화면에서 받은 인증 요청 ID 로는 확인할 수 없다.


### POST /api/plugins/sirsoft-social_login/signup/link-existing/request
<!-- @generated:start:api.plugins.sirsoft-social_login.signup.link-existing.request -->
- **라우트명**: `api.plugins.sirsoft-social_login.signup.link-existing.request`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\LinkExistingController@request`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pending_token | body | string | 예 | — | 가입 대기 토큰(48자 영숫자, `size:48` `alpha_num`). 콜백이 `/register?social_pending=…&social_step=…` 으로 넘긴 값이며, 이 토큰이 곧 권한이다 |
| method | body | string | 예 | `password`, `email` | 기존 계정 확인 방법. `password` 면 `password` 로 바로 확인하고 연결, `email` 이면 기존 계정 이메일로 인증번호를 보낸다 |
| password | body | string | 아니오 | max 255 | 기존 계정 비밀번호. `method` 가 `password` 일 때 필수(`required_if:method,password`) |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/signup/link-existing/request HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "pending_token": "{YOUR_TOKEN}",
    "method": "password",
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. `method` 에 따라 모양이 다르다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| handoff_url | string | `/login?login_handoff=…` | `method=password` 일 때. 연결을 마친 뒤의 코어 로그인 인계 주소. 묶음 쿠키 `g7_login_bind` 가 `Set-Cookie` 응답 헤더로 함께 온다 |
| challenge_id | string | `9f1c2a6e-3b7d-4e8a-a0c5-6d2f4b8e1a37` | `method=email` 일 때. 코어 본인인증 요청 ID(목적 `sensitive_action`). `signup/link-existing/verify` 로 보낸다 |
| expires_at | string | `2026-10-20T12:15:00+09:00` | `method=email` 일 때. 인증번호 만료 시각(ISO 8601) |

**응답 예시**

비밀번호 방법(`method=password`):

```http
HTTP/1.1 200
Set-Cookie: g7_login_bind=…; path=/api/auth; httponly; samesite=lax
```

```json
{
    "success": true,
    "message": "기존 계정에 소셜 계정을 연결했습니다.",
    "data": {
        "handoff_url": "/login?login_handoff=…&redirect=%2Fmypage"
    }
}
```

이메일 방법(`method=email`):

```json
{
    "success": true,
    "message": "인증번호를 보냈습니다.",
    "data": {
        "challenge_id": "9f1c2a6e-3b7d-4e8a-a0c5-6d2f4b8e1a37",
        "expires_at": "2026-10-20T12:15:00+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | `errors.code` = `password_mismatch`(비밀번호가 틀렸다, 실패 횟수에 센다), `password_not_available`(비밀번호가 없는 계정, 실패 횟수에 세지 않는다) |
| 403 | Forbidden | `errors.code` = `admin_blocked`: 기존 계정이 관리자이고 관리자 소셜 로그인이 꺼져 있다(설정 `allow_admin_social_login`) |
| 404 | Not Found | `errors.code` = `expired`: 가입 대기 토큰이 없거나 만료됐다. 기존 계정이 사라졌거나 탈퇴한 경우에도 토큰을 지우고 같은 코드로 답한다 |
| 404 | Not Found | `errors.code` = `too_many_attempts`: 비밀번호와 인증번호 확인을 합쳐 5번 틀렸다. 가입 대기 토큰이 폐기된다 |
| 409 | Conflict | `errors.code` = `not_existing_step`(기존 계정 연결 단계가 아니다), `already_linked_other_user`, `provider_already_linked` |
| 503 | Service Unavailable | `errors.code` = `mail_failed`: 기존 계정 이메일로 인증번호를 보내지 못했다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:10,1,sirsoft-social_login.link-existing-request`) |

<!-- @generated:end -->

**설명**

「이미 가입된 이메일」 모달에서 기존 계정에 연결할 때 부른다. 가입 대기의 `step` 이 `existing` 이어야 한다.

- 비밀번호 방법은 바로 확인하고 연결한 뒤 로그인 인계 주소를 돌려준다.
- 이메일 방법은 기존 계정 주소로 인증번호를 보낸다(코어 본인인증 목적 `sensitive_action`). 이어서 `signup/link-existing/verify` 를 부른다.
- 비밀번호 오답과 인증번호 오답은 같은 실패 횟수로 센다. 5번째 실패에서 가입 대기를 지우고 404 `too_many_attempts` 로 답하므로, 화면은 소셜 로그인을 처음부터 다시 하게 한다.


### POST /api/plugins/sirsoft-social_login/signup/link-existing/verify
<!-- @generated:start:api.plugins.sirsoft-social_login.signup.link-existing.verify -->
- **라우트명**: `api.plugins.sirsoft-social_login.signup.link-existing.verify`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\LinkExistingController@verify`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pending_token | body | string | 예 | — | 가입 대기 토큰(48자 영숫자, `size:48` `alpha_num`). 콜백이 `/register?social_pending=…&social_step=…` 으로 넘긴 값이며, 이 토큰이 곧 권한이다 |
| challenge_id | body | string | 예 | max 64 | challenge 식별자 |
| code | body | string | 예 | max 10 | 기존 계정 이메일로 받은 인증번호(최대 10자) |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/signup/link-existing/verify HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "pending_token": "{YOUR_TOKEN}",
    "challenge_id": "예시값",
    "code": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| handoff_url | string | `/login?login_handoff=…` | 코어 로그인 인계 주소(같은 출처 상대 경로). 화면이 이 주소로 이동하면 코어가 로그인시킨다. 시작할 때 받은 복귀 경로가 `/` 가 아니면 `redirect` 쿼리가 붙는다. 인계 코드의 묶음 쿠키 `g7_login_bind`(HttpOnly, SameSite=Lax, Path `/api/auth`, 120초)가 `Set-Cookie` 응답 헤더로 함께 오며 본문에는 없다 |

**응답 예시**

```http
HTTP/1.1 200
Set-Cookie: g7_login_bind=…; path=/api/auth; httponly; samesite=lax
```

```json
{
    "success": true,
    "message": "기존 계정에 소셜 계정을 연결했습니다.",
    "data": {
        "handoff_url": "/login?login_handoff=…&redirect=%2Fmypage"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | `errors.code` = `challenge_mismatch`(이 가입 대기에 발급한 인증 요청이 아니다), `invalid_code`(인증번호가 틀렸거나 다른 회원의 확인이다, 실패 횟수에 센다) |
| 403 | Forbidden | `errors.code` = `admin_blocked`: 기존 계정이 관리자이고 관리자 소셜 로그인이 꺼져 있다 |
| 404 | Not Found | `errors.code` = `expired`(가입 대기가 없거나 만료, 기존 계정이 사라졌거나 탈퇴), `too_many_attempts`(확인 5번 실패, 가입 대기 폐기) |
| 409 | Conflict | `errors.code` = `not_existing_step`, `already_linked_other_user`, `provider_already_linked` |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:10,1,sirsoft-social_login.link-existing-verify`) |

<!-- @generated:end -->

**설명**

`signup/link-existing/request`(`method=email`)로 받은 인증번호를 확인하고, 맞으면 기존 계정에 제공자 계정을 연결한 뒤 로그인 인계 주소를 돌려준다.

확인에 쓴 본인인증 토큰은 이 자리에서 소진된다. 같은 확인으로 다른 민감 작업을 할 수 없다.


### GET /api/plugins/sirsoft-social_login/signup/pending
<!-- @generated:start:api.plugins.sirsoft-social_login.signup.pending -->
- **라우트명**: `api.plugins.sirsoft-social_login.signup.pending`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\SignupController@pending`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| pending_token | query | string | 예 | — | 가입 대기 토큰(48자 영숫자, `size:48` `alpha_num`). 콜백이 `/register?social_pending=…&social_step=…` 으로 넘긴 값이며, 이 토큰이 곧 권한이다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/signup/pending?pending_token=%7BYOUR_TOKEN%7D HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| provider | object | `{"key":"kakao","label":"카카오","brand":"kakao"}` | 제공자 키, 표시 이름(현재 로케일), 버튼 모양 브랜드 |
| step | string | `complete` | `complete`(새 계정 가입) 또는 `existing`(같은 이메일의 기존 계정에 연결하는 단계) |
| name | string\|null | `홍길동` | 제공자가 준 이름. 없으면 별명, 둘 다 없으면 `null` |
| nickname | string\|null | `길동` | 제공자가 준 별명 |
| email | object | `{"mode":"input","value":null,"verified":false,"requires_code":true,"challenge_id":null}` | 이메일 칸 상태. `mode` 는 `locked`(제공자가 준 이메일로 고정), `input`(입력하고 인증번호로 확인), `placeholder`(메일을 받을 수 없는 대체 주소 `{제공자}_{해시}@social.invalid`). `value` 는 고정 모드면 그 주소, 입력 모드면 확인한 이메일이나 미리 채울 이메일. `verified` 는 입력 모드에서 인증번호 확인을 마쳤거나 입력 모드가 아니면 `true`. `requires_code` 는 입력 모드일 때 `true`. `challenge_id` 는 마지막으로 보낸 인증 요청 ID |
| missing | array | `["email"]` | 가입 전에 채워야 할 칸. 입력 모드면 `email`, 제공자가 이름과 별명을 모두 주지 않았으면 `name` |
| show_profile_fields | boolean | `false` | 이름과 별명 입력 칸을 보일지. 설정 `extra_info_mode` 가 `always` 이거나 이름이 비었으면 `true` |
| existing_account | object\|null | `null` | `step` 이 `existing` 일 때 기존 계정 정보 `{ "email_masked": "ho***@example.com", "has_password": true }`. 이메일은 앞 2자만 보인다. 그 밖에는 `null` |
| expires_at | string | `2026-10-20T12:30:00+09:00` | 가입 대기 만료 시각(시간대가 붙은 ISO 8601) |

**응답 예시**

```json
{
    "success": true,
    "message": "가입 대기 정보입니다.",
    "data": {
        "provider": { "key": "kakao", "label": "카카오", "brand": "kakao" },
        "step": "complete",
        "name": "홍길동",
        "nickname": "길동",
        "email": { "mode": "input", "value": null, "verified": false, "requires_code": true, "challenge_id": null },
        "missing": ["email"],
        "show_profile_fields": false,
        "existing_account": null,
        "expires_at": "2026-10-20T12:30:00+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 404 | Not Found | `errors.code` = `expired`: 가입 대기 토큰이 없거나 유효 시간(설정 `pending_signup_ttl_minutes`, 기본 30분)이 지났다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:60,1,sirsoft-social_login.signup-pending`) |

<!-- @generated:end -->

**설명**

가입 모달이 열릴 때 그릴 내용을 받는다. 콜백이 `/register?social_pending={토큰}&social_step={complete|existing}` 으로 보내면 화면이 쿼리의 토큰으로 이 API 를 부른다.

- `step` 이 `existing` 이면 「이미 가입된 이메일」 모달을 그리고, `existing_account.has_password` 로 비밀번호 방법을 보일지 정한다.
- `email.mode` 가 `input` 이면 이메일 입력과 인증번호 확인 단계를 보인다.
- 조회는 가입 대기를 바꾸지 않는다.

