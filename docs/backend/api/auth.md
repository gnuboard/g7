# Auth API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Auth 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/admin/auth/logout
<!-- @generated:start:api.admin.auth.logout -->
- **라우트명**: `api.admin.auth.logout`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AuthController@logout`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/auth/logout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `AuthController@logout` 이 `success('auth.logout_success')` 를 인자 없이 호출하므로 `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "로그아웃이 성공했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

현재 관리자의 Sanctum 토큰을 폐기해 로그아웃한다. `AuthService::logout()` 이 3단계(토큰 삭제 → 세션 무효화 → `Auth::logout()`)를 수행하며, `data` 는 없고 `message` 만 `auth.logout_success` 로 내려온다. 프론트는 응답 후 저장된 Bearer 토큰을 폐기하고 로그인 화면으로 전환한다.


### POST /api/admin/auth/refresh
<!-- @generated:start:api.admin.auth.refresh -->
- **라우트명**: `api.admin.auth.refresh`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AuthController@refresh`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/auth/refresh HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| user | object | `{"uuid":"a26219fc-94a0-4f63-9404-04c2a6ac99e4","name":"최고…` | 대상 사용자 정보 객체 (uuid/name/email 등 — user 관계 파생) |
| token | string | `380\|ZjZl46uRlhHt53MEhb7XAb0r5lYoDEsXF…` | 발급된 API 접근 토큰 평문 (Bearer 토큰으로 사용, 발급 시 1회만 노출) |
| token_type | string | `Bearer` | 토큰 타입 (일반적으로 Bearer) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "user": {
            "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
            "name": "최고관리자",
            "nickname": "최고관리자",
            "email": "heuristing@gmail.com",
            "avatar": null,
            "language": "ko",
            "language_label": "한국어",
            "country": null,
            "status": "active",
            "status_label": "활성",
            "status_variant": "success",
            "is_admin": true,
            "homepage": null,
            "mobile": null,
            "phone": null,
            "zipcode": null,
            "address": null,
            "address_detail": null,
            "signature": null,
            "bio": null,
            "last_login_at": "2026-08-04 19:00:10",
            "email_verified_at": "2026-07-30 23:37:44",
            "timezone": "Asia/Seoul",
            "created_at": "2026-07-30 23:37:44",
            "updated_at": "2026-08-04 19:00:10",
            "is_owner": true,
            "abilities": {
                "can_read": true,
                "can_create": true,
                "can_update": true,
                "can_delete": false,
                "can_assign_roles": true
            }
        },
        "token": "{MASKED}",
        "token_type": "Bearer"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

현재 관리자 토큰을 새 Sanctum 토큰으로 교체한다. `AuthService::refreshToken()` 이 기존 토큰을 폐기하고 새 토큰을 발급하며, `data` 에는 새 `token` 과 `user`(UserResource) 가 담긴다. 만료 임박 토큰을 재발급하는 용도로, 세션 만료로 재인증이 필요한 경우(토큰 무효)에는 `401 auth.unauthenticated` 를 반환한다.


### GET /api/admin/auth/user
<!-- @generated:start:api.admin.auth.user -->
- **라우트명**: `api.admin.auth.user`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AuthController@user`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/auth/user HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a26219fc-94a0-4f63-9404-04c2a6ac99e4` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `최고관리자` | 사용자 이름 |
| nickname | string | `최고관리자` | 닉네임 |
| email | string | `heuristing@gmail.com` | 이메일 주소 |
| avatar | null | `null` | 아바타 이미지 URL (User::getAvatarUrl() — 아바타 미설정 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (user.language.{code} 번역) |
| country | null | `null` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생) |
| homepage | null | `null` | 홈페이지 URL |
| mobile | null | `null` | 휴대폰 번호 |
| phone | null | `null` | 전화번호 |
| zipcode | null | `null` | 우편번호 |
| address | null | `null` | 기본 주소 |
| address_detail | null | `null` | 상세 주소 |
| signature | null | `null` | 서명 |
| bio | null | `null` | 자기소개 |
| last_login_at | string | `2026-08-04 19:00:10` | last login 일시 |
| email_verified_at | string | `2026-07-30 23:37:44` | email verified 일시 |
| timezone | string | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| roles | array | `[{"id":1,"identifier":"admin","name":"관리자"}]` | 사용자에게 부여된 역할 목록 (원소 id/identifier/name — roles 관계 파생, name 은 현지화 라벨) |
| permissions | array | `[{"id":2,"identifier":"sirsoft-ecommerce.user-products.re…` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| created_at | string | `2026-07-30 23:37:44` | 생성 일시 |
| updated_at | string | `2026-08-04 19:00:10` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
        "name": "최고관리자",
        "nickname": "최고관리자",
        "email": "heuristing@gmail.com",
        "avatar": null,
        "...": "(24개 키 생략, 총 29개)"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

관리자 레이아웃 전역 부트스트랩 엔드포인트. `_admin_base.json` 의 `data_source`(`current_user`)가 모든 관리자 페이지 진입 시 자동 호출해, 헤더/권한 게이트/is_admin 분기의 기준 사용자 정보를 채운다. 응답에는 `roles.permissions` 가 eager load 되어 `permissions` 배열이 함께 내려온다.

**인증 계약**: `auth:sanctum` 필요 — Bearer 토큰이 없거나 만료되면 `401` 을 반환한다(프론트 `data_source` 의 `auth_required: true` 에 대응). 이 계약이 프론트 소비의 SSoT 이므로 미들웨어 체인 변경 시 반드시 프론트 `auth_required`/`auth_mode` 와 함께 검토한다(이슈 #64).


### POST /api/auth/admin/login
<!-- @generated:start:api.auth.admin.login -->
- **라우트명**: `api.auth.admin.login`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AuthController@login`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| email | body | email | 예 | — | 이메일 주소 |
| password | body | string | 예 | — | 비밀번호 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.login_validation_rules`).

**요청 예시**

```http
POST /api/auth/admin/login HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`AuthService::login()` 이 반환한 배열 — `user` 만 `UserResource` 로 감싼다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| user | object | `{"uuid":"a234c2b1-…","name":"관리자","is_admin":true, …}` | 로그인한 관리자 정보 (`UserResource` — 필드 전수는 `GET /api/admin/auth/user` 응답 필드 표와 동일) |
| token | string | `75\|WgPUplvLGTv8YIj4507uIR6dEOHTXyNUed…` | 발급된 Sanctum 접근 토큰 평문 (이후 `Authorization: Bearer` 헤더로 사용, 발급 시 1회만 노출) |
| token_type | string | `Bearer` | 토큰 타입 (항상 `Bearer` — `AuthService::login()` 이 상수로 반환) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "관리자 로그인이 성공했습니다.",
    "data": {
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "status": "active",
            "is_admin": true,
            "roles": [
                { "id": 1, "identifier": "admin", "name": "관리자" }
            ],
            "is_owner": true,
            "abilities": {
                "can_read": true,
                "can_create": true,
                "can_update": true,
                "can_delete": true,
                "can_assign_roles": true
            }
        },
        "token": "{MASKED}",
        "token_type": "Bearer"
    }
}
```

> `user` 객체는 지면 절약을 위해 축약했습니다. 실제로는 `GET /api/admin/auth/user` 의 `data` 와 동일한 `UserResource` 필드 전수가 내려옵니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 이메일/비밀번호가 일치하지 않는 경우 (`auth.login_failed`) |
| 403 | Forbidden | 자격 증명은 맞지만 관리자 역할이 아닌 경우 (`auth.admin_required`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 423 | Locked | 로그인 실패 누적으로 계정이 잠긴 경우 (`auth.account_locked` — `error.locked_until`, `error.retry_after_seconds` 포함) |

<!-- @generated:end -->

**설명**

관리자 로그인. `email`/`password` 검증 후 `AuthService::login()` 이 인증하고, 인증 사용자가 `isAdmin()` 이 아니면 `403 auth.admin_required` 로 거부한다. 성공 시 `data.token`(Sanctum Bearer) 과 `data.user`(UserResource) 를 반환한다. 계정 잠금 시 `AccountLockedException`, 자격 불일치 시 `422` 검증 오류를 반환한다. 이후 모든 관리자 API 호출은 이 토큰을 `Authorization: Bearer` 헤더로 실어야 한다.


### POST /api/auth/forgot-password
<!-- @generated:start:api.auth.forgot-password -->
- **라우트명**: `api.auth.forgot-password`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@forgotPassword`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| email | body | email | 예 | — | 이메일 주소 |
| redirect_prefix | body | string | 아니오 | `admin` | 재설정 링크가 향할 화면 구분값 — `admin` 전달 시 관리자 재설정 화면, 미지정 시 사용자 화면 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.forgot_password_validation_rules`).

**요청 예시**

```http
POST /api/auth/forgot-password HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "email": "user@example.com",
    "redirect_prefix": "admin"
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `AuthController@forgotPassword` 가 `success('auth.password_reset_email_sent')` 를 인자 없이 호출하므로 `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "비밀번호 재설정 이메일이 발송되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 또는 등록되지 않은 이메일인 경우 (`auth.password_reset_failed` — `error.errors.email` 에 `auth.email_not_registered` 메시지) |

<!-- @generated:end -->

**설명**

비밀번호 재설정 메일 발송을 요청한다(공개). `email` 로 계정을 찾아 재설정 링크 메일을 보내고 `message: auth.password_reset_email_sent` 를 반환한다. `redirect_prefix` 는 재설정 링크가 향할 화면을 구분하는 값으로 관리자 흐름(`admin_forgot_password.json`)에서는 `admin` 을 전달해 링크가 관리자 재설정 화면을 가리키게 한다(미지정 시 사용자 화면). 계정 열거 방지를 위해 이메일 존재 여부와 무관하게 동일 응답을 주는 것이 원칙이다.


### POST /api/auth/login
<!-- @generated:start:api.auth.login -->
- **라우트명**: `api.auth.login`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@login`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| email | body | email | 예 | — | 이메일 주소 |
| password | body | string | 예 | — | 비밀번호 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.login_validation_rules`).

**요청 예시**

```http
POST /api/auth/login HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`AuthService::login()` 이 반환한 배열 — `user` 만 `UserResource` 로 감싼다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| user | object | `{"uuid":"a234c2b1-…","name":"홍길동","is_admin":false, …}` | 로그인한 사용자 정보 (`UserResource` — 필드 전수는 `GET /api/auth/user` 응답 필드 표의 기본(코어) 필드와 동일) |
| token | string | `75\|WgPUplvLGTv8YIj4507uIR6dEOHTXyNUed…` | 발급된 Sanctum 접근 토큰 평문 (이후 `Authorization: Bearer` 헤더로 사용, 발급 시 1회만 노출) |
| token_type | string | `Bearer` | 토큰 타입 (항상 `Bearer`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "로그인이 성공했습니다.",
    "data": {
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "language": "ko",
            "status": "active",
            "is_admin": false,
            "is_owner": true,
            "abilities": {
                "can_read": true,
                "can_create": true,
                "can_update": true,
                "can_delete": true,
                "can_assign_roles": true
            }
        },
        "token": "{MASKED}",
        "token_type": "Bearer"
    }
}
```

> `user` 객체는 지면 절약을 위해 축약했습니다. 실제로는 `UserResource` 필드 전수가 내려옵니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 이메일/비밀번호가 일치하지 않거나 계정 상태가 활성이 아닌 경우 (`auth.login_failed`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 423 | Locked | 로그인 실패 누적으로 계정이 잠긴 경우 (`auth.account_locked` — `error.locked_until`, `error.retry_after_seconds` 포함) |

<!-- @generated:end -->

**설명**

일반 사용자 로그인(공개). 관리자 로그인과 달리 `isAdmin()` 검사가 없다. 성공 시 `data.token`(Sanctum Bearer) 과 `data.user` 를 반환하며 `message: auth.login_success`. 계정 잠금 시 `423 auth.account_locked` 를 잠금 해제까지 남은 정보(`errors.locked_until`, `errors.retry_after_seconds`)와 함께 반환한다. 보안 환경설정의 잠금 시간이 `0`(무한대)이면 무기한 잠금이 되어 `423 auth.account_locked_permanently` 와 함께 `errors.permanent=true`, `errors.locked_until=null`, `errors.retry_after_seconds=null` 을 반환하며 `Retry-After` 헤더도 붙지 않는다. 이 경우 해제 수단은 관리자 해제 API(`POST /api/admin/users/{user}/unlock`) 뿐이다. 프론트 로그인 폼(`partials/auth/_register_form.json` 인접)에서 소비한다.

**2단계 인증이 켜져 있는 경우**: 보안 환경설정 `security.two_factor_auth` 가 켜져 있으면 비밀번호가 맞아도 **토큰을 발급하지 않는다**. 대신 `200` 과 함께 `message: auth.two_factor_required` 및 아래 필드를 반환하며, 클라이언트는 `POST /api/auth/login/two-factor` 로 코드를 확인해야 로그인이 완료된다.

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| `two_factor_required` | boolean | 항상 `true` — 이 응답이 추가 확인 단계임을 나타낸다 |
| `challenge_id` | string(uuid) | 확인 단계에 그대로 전달할 challenge 식별자 |
| `provider_id` | string | 코드를 발송한 본인인증 프로바이더 |
| `expires_at` | string(ISO8601)\|null | challenge 만료 시각 |

이 응답에는 `data.token` 과 `data.user` 가 없다. 토큰 존재 여부로 로그인 완료를 판정하는 클라이언트는 그대로 동작한다.


### POST /api/auth/login/two-factor
<!-- @generated:start:api.auth.login.two-factor -->
- **라우트명**: `api.auth.login.two-factor`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@verifyTwoFactor`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| challenge_id | body | string | 예 | — | 로그인 응답이 돌려준 challenge 식별자 |
| code | body | string | 예 | min 4, max 16 | 사용자가 받은 인증 코드 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.two_factor_validation_rules`).

**요청 예시**

```http
POST /api/auth/login/two-factor HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "challenge_id": "예시값",
    "code": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`AuthService::completeTwoFactor()` 가 로그인 세션을 발급해 반환한 배열 — `user` 만 `UserResource` 로 감싼다). 성공 시 페이로드는 일반 로그인(`POST /api/auth/login`)과 동일하다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| user | object | `{"uuid":"a234c2b1-…","name":"홍길동","is_admin":false, …}` | 로그인한 사용자 정보 (`UserResource` — 필드 전수는 `GET /api/auth/user` 응답 필드 표의 기본(코어) 필드와 동일) |
| token | string | `75\|WgPUplvLGTv8YIj4507uIR6dEOHTXyNUed…` | 발급된 Sanctum 접근 토큰 평문 (이후 `Authorization: Bearer` 헤더로 사용, 발급 시 1회만 노출) |
| token_type | string | `Bearer` | 토큰 타입 (항상 `Bearer`) |

> 위 문서의 실측이 `422` 로 관측된 것은 유효한 challenge 없이 프로브가 호출됐기 때문이다. 정상 흐름(비밀번호 단계가 돌려준 `challenge_id` + 올바른 코드)에서는 `200` 과 위 페이로드가 반환된다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "로그인이 성공했습니다.",
    "data": {
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "language": "ko",
            "status": "active",
            "is_admin": false,
            "is_owner": true,
            "abilities": {
                "can_read": true,
                "can_create": true,
                "can_update": true,
                "can_delete": true,
                "can_assign_roles": true
            }
        },
        "token": "{MASKED}",
        "token_type": "Bearer"
    }
}
```

> `user` 객체는 지면 절약을 위해 축약했습니다. 실제로는 `UserResource` 필드 전수가 내려옵니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | 코드가 틀렸거나(`auth.two_factor_failed`), challenge 의 `purpose` 가 `login` 이 아니거나, 확인된 사용자가 없거나 `active` 상태가 아닌 경우. **세 사유를 같은 응답으로 뭉뚱그린다** — 구분해 내보내면 challenge 유효성 탐색에 쓰인다 |
| 422 | Unprocessable Entity | `challenge_id`/`code` 형식 위반 |
| 429 | Too Many Requests | `throttle:auth-login` 초과 (로그인과 같은 제한을 공유하므로 코드 대입 시도도 함께 억제된다) |

<!-- @generated:end -->

**설명**

비밀번호 단계가 돌려준 challenge 를 확인해 로그인을 완료한다. 로그인과 동일한 요청 제한(`throttle:auth-login`)이 걸려 코드 대입 시도도 함께 억제된다.

challenge 의 `purpose` 가 `login` 인지 먼저 대조한다 — 대조하지 않으면 회원가입·비밀번호 재설정 등 다른 흐름에서 발급된 challenge 로 로그인할 수 있다. 코드 확인에 성공하기 전에는 어떤 경우에도 토큰이 발급되지 않는다.


### POST /api/auth/logout
<!-- @generated:start:api.auth.logout -->
- **라우트명**: `api.auth.logout`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@logout`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/auth/logout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `AuthController@logout` 이 `success('auth.logout_success')` 를 인자 없이 호출하므로 `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "로그아웃이 성공했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

현재 사용자 토큰을 폐기해 로그아웃한다(`message: auth.logout_success`). 현재 요청에 사용된 토큰만 폐기하며, 모든 기기에서 로그아웃하려면 `/api/user/auth/logout-all-devices` 를 사용한다.


### POST /api/auth/register
<!-- @generated:start:api.auth.register -->
- **라우트명**: `api.auth.register`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@register`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | string | 예 | max 255 | 대상의 이름/명칭 |
| nickname | body | string | 아니오 | max 50 | 닉네임 |
| email | body | string | 예 | max 255 | 이메일 주소 |
| password | body | string | 예 | — | 비밀번호 |
| mobile | body | string | 아니오 | max 20 | 휴대폰번호 (선택) |
| phone | body | string | 아니오 | max 20 | 전화번호 (선택) |
| language | body | string | 아니오 | `ko`, `en` | 언어 코드 |
| agree_terms | body | string | 아니오 | — | 이용약관 동의 (코어 필수 동의 — accepted 규칙, 미동의 시 가입 거부) |
| agree_privacy | body | string | 아니오 | — | 개인정보 처리방침 동의 (코어 필수 동의 — accepted 규칙, 미동의 시 가입 거부) |
| agree_email_subscription | body | boolean | 아니오 | — | 광고성 이메일 수신 동의 (marketing 플러그인 주입, 선택 항목) |
| agree_marketing_consent | body | boolean | 아니오 | — | 마케팅 정보 수신 전체 동의 (marketing 플러그인 주입, 선택 항목) |
| agree_third_party_consent | body | boolean | 아니오 | — | 제3자 정보 제공 동의 (marketing 플러그인 주입, 선택 항목) |
| agree_info_disclosure | body | boolean | 아니오 | — | 개인정보 이용 안내 동의 (marketing 플러그인 주입, 선택 항목) |
| preferred_currency | body | string | 아니오 | — | 선호 결제 통화 (ecommerce 모듈 주입, 가입 시 계정 기본 통화로 저장) |
| preferred_shipping_country | body | string | 아니오 | — | 선호 배송 국가 코드 (ecommerce 모듈 주입, 가입 시 계정 기본 배송 국가로 저장) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.register_validation_rules`).

**요청 예시**

```http
POST /api/auth/register HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "name": "예시 이름",
    "nickname": "예시 이름",
    "email": "user@example.com",
    "password": "Password123!",
    "mobile": "010-1234-5678",
    "phone": "010-1234-5678",
    "language": "ko",
    "agree_terms": "예시값",
    "agree_privacy": "예시값",
    "agree_email_subscription": true,
    "agree_marketing_consent": true,
    "agree_third_party_consent": true,
    "agree_info_disclosure": true,
    "preferred_currency": "예시값",
    "preferred_shipping_country": "KR"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`AuthService::register()` 이 반환한 배열 — `user` 만 `UserResource` 로 감싼다). 성공 시 HTTP 201._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| user | object | `{"uuid":"a234c2b1-…","name":"홍길동","status":"active", …}` | 생성된 사용자 정보 (`UserResource`. `status` 는 `active`, 가입 후 본인인증 정책이 걸린 경우 `pending_verification`) |
| token | string | `75\|WgPUplvLGTv8YIj4507uIR6dEOHTXyNUed…` | 가입 즉시 발급되는 Sanctum 접근 토큰 평문 (가입 직후 로그인 상태로 이어짐) |
| token_type | string | `Bearer` | 토큰 타입 (항상 `Bearer`) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "회원가입이 성공했습니다.",
    "data": {
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "nickname": null,
            "email": "apidoc-sample-user@example.com",
            "language": "ko",
            "status": "active",
            "is_admin": false,
            "is_owner": true,
            "abilities": {
                "can_read": true,
                "can_create": true,
                "can_update": true,
                "can_delete": true,
                "can_assign_roles": true
            }
        },
        "token": "{MASKED}",
        "token_type": "Bearer"
    }
}
```

> `user` 객체는 지면 절약을 위해 축약했습니다. 실제로는 `UserResource` 필드 전수가 내려옵니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`auth.register_failed` — `error.errors` 에 필드별 메시지) |
| 428 | Precondition Required | 가입 전 본인인증 정책이 매칭되었으나 유효한 `verification_token` 이 없는 경우 (`core.auth.before_register` 훅) |

<!-- @generated:end -->

**설명**

회원가입(공개). 성공 시 `201 auth.register_success` 와 `data.token`/`data.user` 를 반환해 가입 직후 로그인 상태로 이어진다. `agree_*` 동의 파라미터(약관/개인정보/이메일수신/마케팅/제3자제공/정보공개)는 가입 시점의 동의 이력으로 기록된다 — 그중 `agree_email_subscription`/`agree_marketing_consent`/`agree_third_party_consent`/`agree_info_disclosure` 및 `preferred_currency`/`preferred_shipping_country` 는 marketing·ecommerce 확장이 훅(`core.auth.register_validation_rules`)으로 주입하는 파라미터로, 해당 확장 비활성 시 무시된다. 검증 실패 시 `422 auth.register_failed`.


### POST /api/auth/reset-password
<!-- @generated:start:api.auth.reset-password -->
- **라우트명**: `api.auth.reset-password`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@resetPassword`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | body | string | 예 | — | 인증/검증 토큰 |
| email | body | email | 예 | — | 이메일 주소 |
| password | body | string | 예 | — | 비밀번호 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.reset_password_validation_rules`).

**요청 예시**

```http
POST /api/auth/reset-password HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "token": "{YOUR_TOKEN}",
    "email": "user@example.com",
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `AuthController@resetPassword` 가 `success('auth.password_reset_success')` 를 인자 없이 호출하므로 `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "비밀번호가 성공적으로 재설정되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반했거나, 토큰이 유효하지 않거나 만료된 경우 (`auth.password_reset_failed` — `error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

비밀번호 재설정을 실제 반영한다(공개). 재설정 메일의 `token` 과 `email`, 새 `password` 를 받아 비밀번호를 갱신하고 `message: auth.password_reset_success`. 토큰 만료/불일치 등 검증 실패 시 `422 auth.password_reset_failed`. 반영 전 토큰 유효성만 먼저 확인하려면 `/api/auth/validate-reset-token` 을 사용한다.


### GET /api/auth/user
<!-- @generated:start:api.auth.user -->
- **라우트명**: `api.auth.user`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@user`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/auth/user HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a26219fc-94a0-4f63-9404-04c2a6ac99e4` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `최고관리자` | 사용자 이름 |
| nickname | string | `최고관리자` | 닉네임 |
| email | string | `heuristing@gmail.com` | 이메일 주소 |
| avatar | null | `null` | 아바타 이미지 URL (User::getAvatarUrl() — 아바타 미설정 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (user.language.{code} 번역) |
| country | null | `null` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생) |
| homepage | null | `null` | 홈페이지 URL |
| mobile | null | `null` | 휴대폰 번호 |
| phone | null | `null` | 전화번호 |
| zipcode | null | `null` | 우편번호 |
| address | null | `null` | 기본 주소 |
| address_detail | null | `null` | 상세 주소 |
| signature | null | `null` | 서명 |
| bio | null | `null` | 자기소개 |
| last_login_at | string | `2026-08-04 19:00:10` | last login 일시 |
| email_verified_at | string | `2026-07-30 23:37:44` | email verified 일시 |
| timezone | string | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| modules_count | array | `[]` | 접근 가능 모듈 수 (modules_count 속성이 로드된 경우에만 포함 — whenLoaded 성격의 조건부 필드) |
| plugins_count | array | `[]` | 접근 가능 플러그인 수 (plugins_count 속성이 로드된 경우에만 포함) |
| menus_count | array | `[]` | 접근 가능 메뉴 수 (menus_count 속성이 로드된 경우에만 포함) |
| modules | array | `[]` | 접근 가능 모듈 목록 (원소 id/name/slug/is_active — modules 관계 로드 시에만 포함) |
| plugins | array | `[]` | 접근 가능 플러그인 목록 (원소 id/name/slug/is_active — plugins 관계 로드 시에만 포함) |
| menus | array | `[]` | 접근 가능 메뉴 목록 (원소 id/title/url/is_active — menus 관계 로드 시에만 포함) |
| roles | array | `[{"id":1,"identifier":"admin","name":"관리자"}]` | 사용자에게 부여된 역할 목록 (원소 id/identifier/name — roles 관계 파생, name 은 현지화 라벨) |
| permissions | array | `[{"id":2,"identifier":"sirsoft-ecommerce.user-products.re…` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| consents | array | `[]` | 전체 약관 동의 이력 (원소 consent_type/agreed_at/revoked_at — consents 관계 로드 시 포함, 플러그인 참조용) |
| terms_consent | array | `[]` | 이용약관 동의 정보 (agreed_at — ConsentType::Terms 동의 이력에서 파생, 미동의 시 null) |
| privacy_consent | array | `[]` | 개인정보 처리방침 동의 정보 (agreed_at — ConsentType::Privacy 동의 이력에서 파생, 미동의 시 null) |
| created_at | string | `2026-07-30 23:37:44` | 생성 일시 |
| updated_at | string | `2026-08-04 19:00:10` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |
| notify_post_complete | boolean | `false` | 게시판 새 글 작성 완료 알림 수신 설정 (marketing 플러그인 주입) |
| notify_post_reply | boolean | `false` | 내 게시글에 답글 달림 알림 수신 설정 (marketing 플러그인 주입) |
| notify_comment | boolean | `false` | 내 게시글에 댓글 달림 알림 수신 설정 (marketing 플러그인 주입) |
| notify_reply_comment | boolean | `false` | 내 댓글에 답글 달림 알림 수신 설정 (marketing 플러그인 주입) |
| email_subscription | boolean | `false` | 광고성 이메일 수신 동의 여부 (marketing 플러그인 주입) |
| email_subscription_at | null | `null` | email subscription 일시 (광고성 이메일 수신 동의 시각, 미동의 시 null) |
| marketing_consent | boolean | `false` | 마케팅 정보 수신 전체 동의 마스터 키 (marketing 플러그인 주입) |
| marketing_consent_at | null | `null` | marketing consent 일시 (마케팅 정보 수신 동의 시각, 미동의 시 null) |
| third_party_consent | boolean | `false` | 제3자 정보 제공 동의 여부 (법적 항목 — marketing 플러그인 주입) |
| third_party_consent_at | null | `null` | third party consent 일시 (제3자 정보 제공 동의 시각, 미동의 시 null) |
| info_disclosure | boolean | `false` | 개인정보 이용 안내 동의 여부 (법적 항목 — marketing 플러그인 주입) |
| info_disclosure_at | null | `null` | info disclosure 일시 (개인정보 이용 안내 동의 시각, 미동의 시 null) |
| marketing_consent_enabled | boolean | `true` | 마케팅 정보 수신 동의 항목 UI 노출 여부 (관리자 활성화 플래그) |
| marketing_consent_terms_slug | string | `marketing-terms` | 마케팅 정보 수신 동의에 연결된 약관 slug (미설정 시 null) |
| marketing_consent_terms_slug_set | boolean | `true` | 마케팅 정보 수신 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| third_party_consent_enabled | boolean | `true` | 제3자 정보 제공 동의 항목 UI 노출 여부 (관리자 활성화 플래그) |
| third_party_consent_terms_slug | null | `null` | 제3자 정보 제공 동의에 연결된 약관 slug (미설정 시 null) |
| third_party_consent_terms_slug_set | boolean | `false` | 제3자 정보 제공 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| info_disclosure_enabled | boolean | `true` | 개인정보 이용 안내 동의 항목 UI 노출 여부 (관리자 활성화 플래그) |
| info_disclosure_terms_slug | null | `null` | 개인정보 이용 안내 동의에 연결된 약관 slug (미설정 시 null) |
| info_disclosure_terms_slug_set | boolean | `false` | 개인정보 이용 안내 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| email_subscription_enabled | boolean | `true` | 광고성 이메일 수신 동의 항목 UI 노출 여부 (관리자 활성화 플래그) |
| email_subscription_terms_slug | null | `null` | 광고성 이메일 수신 동의에 연결된 약관 slug (미설정 시 null) |
| email_subscription_terms_slug_set | boolean | `false` | 광고성 이메일 수신 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| channels | array | `[{"key":"email_subscription","label":"광고성 이메일 수신","enable…` | 관리자 정의 전체 마케팅 채널 목록 (원소 key/label/enabled/terms_slug — marketing 플러그인 주입) |
| consent_histories | array | `[]` | 동의 변경 이력 (원소 channel_key/action/source/created_at — marketing 플러그인 주입) |
| ecommerce_mileage | object | `{"enabled":false}` | 마일리지 정보 (enabled/잔액 — ecommerce 모듈 주입, 모듈 비활성 시 enabled=false) |
| ecommerce_preferred_currency | string | `KRW` | 선호 결제 통화 (ecommerce 모듈 주입, 미설정 시 null) |
| ecommerce_preferred_shipping_country | null | `null` | 선호 배송 국가 코드 (ecommerce 모듈 주입, 미설정 시 null) |
| ecommerce_preferred_shipping_country_name | null | `null` | 선호 배송 국가 이름 (국가 코드에서 현지화 파생 — ecommerce 모듈 주입, 미설정 시 null) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
        "name": "최고관리자",
        "nickname": "최고관리자",
        "email": "heuristing@gmail.com",
        "avatar": null,
        "...": "(63개 키 생략, 총 68개)"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

프론트(사용자) 레이아웃 전역 부트스트랩 엔드포인트. `_user_base.json` 의 `current_user` data_source 가 모든 페이지 진입 시 호출한다. 관리자 `user` 와 달리 응답을 `UserResource::toAuthArray()` 로 만들어 `core.user.filter_resource_data` 필터를 적용하므로, marketing 플러그인·ecommerce 모듈이 훅으로 병합한 필드(`notify_*`, `marketing_consent*`, `channels`, `ecommerce_*` 등)가 함께 내려온다. 이 필드들은 확장 소유이므로 상세 설명은 각 확장 문서를 따른다. 로그인 시 이 응답이 계정 영속 통화를 덮어쓰는 계약(D-LOGIN-CUR)의 출처다.

**인증 계약**: 이 경로(`api.auth.user`)는 `auth:sanctum` 으로 인증이 필수다. 인증 여부와 무관하게 게스트 컨텍스트가 필요한 화면은 `optional.sanctum` 이 걸린 `/api/user/auth/user`(`api.user.auth.user`)를 사용해야 한다 — 프론트 `data_source` 의 `auth_mode: "optional"` 이 이 경로에 대응한다. 두 경로의 미들웨어 차이가 곧 `auth_required`/`auth_mode` 계약이므로 변경 시 프론트와 함께 검토한다(이슈 #64).


### POST /api/auth/validate-reset-token
<!-- @generated:start:api.auth.validate-reset-token -->
- **라우트명**: `api.auth.validate-reset-token`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@validateResetToken`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | body | string | 예 | — | 인증/검증 토큰 |
| email | body | email | 예 | — | 이메일 주소 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.validate_reset_token_messages`).

**요청 예시**

```http
POST /api/auth/validate-reset-token HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "token": "{YOUR_TOKEN}",
    "email": "user@example.com"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`AuthService::validateResetToken()` 의 반환 배열)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| valid | boolean | `true` | 토큰 유효 여부. 성공 응답에서는 항상 `true` (유효하지 않으면 컨트롤러가 422 로 전환하므로 이 필드가 `false` 인 200 응답은 없음) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "valid": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터 검증 위반, 또는 토큰이 유효하지 않거나 만료된 경우 / 미등록 이메일 (`auth.reset_token_invalid` — `error.errors.token` 에 사유 메시지) |

<!-- @generated:end -->

**설명**

비밀번호 재설정 토큰의 유효성만 사전 확인한다(공개, 비밀번호 미변경). 재설정 화면(`admin_reset_password.json`/`auth/reset_password.json`) 진입 시 토큰/이메일이 유효한지 먼저 검사해, 만료·위조 링크면 즉시 오류 화면을 보이고 유효하면 새 비밀번호 입력 폼을 노출하는 용도다. 실제 반영은 `/api/auth/reset-password` 가 담당한다.


### POST /api/user/auth/logout
<!-- @generated:start:api.user.auth.logout -->
- **라우트명**: `api.user.auth.logout`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@logout`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.auth.logout`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/user/auth/logout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `AuthController@logout` 이 `success('auth.logout_success')` 를 인자 없이 호출하므로 `data` 는 `null`). 공용 `POST /api/auth/logout` 과 동일한 컨트롤러 메서드._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "로그아웃이 성공했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.auth.logout`)이 없는 경우 |

<!-- @generated:end -->

**설명**

`/user` prefix 그룹의 사용자 로그아웃. 공용 경로 `/api/auth/logout` 과 동일하게 현재 토큰을 폐기하되, `permission:core.auth.logout` 권한 게이트를 추가로 통과해야 한다. 세션 시작(`start.api.session`)이 걸린 공용 경로와 달리 권한 기반 접근 제어가 필요한 흐름에서 사용한다.


### POST /api/user/auth/logout-all-devices
<!-- @generated:start:api.user.auth.logout-all-devices -->
- **라우트명**: `api.user.auth.logout-all-devices`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@logoutFromAllDevices`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.auth.logout`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/user/auth/logout-all-devices HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `AuthController@logoutFromAllDevices` 가 `success('auth.logout_all_devices_success')` 를 인자 없이 호출하므로 `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "모든 디바이스에서 로그아웃되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.auth.logout`)이 없는 경우 |

<!-- @generated:end -->

**설명**

현재 사용자의 **모든** Sanctum 토큰을 폐기해 전 기기에서 로그아웃한다(`message: auth.logout_all_devices_success`). 비밀번호 변경 후 기존 세션 무효화, 계정 도용 대응 등에 사용한다.


### POST /api/user/auth/refresh
<!-- @generated:start:api.user.auth.refresh -->
- **라우트명**: `api.user.auth.refresh`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@refresh`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.auth.refresh`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/user/auth/refresh HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`AuthService::refreshToken()` 반환 배열 — 공용 `POST /api/admin/auth/refresh` 와 동일 shape)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| user | object | `{"uuid":"a234c2b1-…","name":"홍길동", …}` | 토큰을 갱신한 사용자 정보 (`UserResource`) |
| token | string | `75\|WgPUplvLGTv8YIj4507uIR6dEOHTXyNUed…` | 새로 발급된 Sanctum 접근 토큰 평문 (기존 토큰은 폐기됨) |
| token_type | string | `Bearer` | 토큰 타입 (항상 `Bearer`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "status": "active",
            "is_admin": false,
            "is_owner": true,
            "abilities": {
                "can_read": true,
                "can_create": true,
                "can_update": true,
                "can_delete": true,
                "can_assign_roles": true
            }
        },
        "token": "{MASKED}",
        "token_type": "Bearer"
    }
}
```

> `user` 객체는 지면 절약을 위해 축약했습니다. 실제로는 `UserResource` 필드 전수가 내려옵니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.auth.refresh`)이 없는 경우 |

<!-- @generated:end -->

**설명**

`/user` prefix 그룹의 토큰 갱신. 공용 `refresh` 와 동작은 같으나 `permission:core.auth.refresh` 권한 게이트를 추가로 통과해야 한다. 이 그룹(`routes/api.php:271`)은 `optional.sanctum` + `RefreshTokenExpiration` 미들웨어 아래 있어 토큰 만료 정책 갱신과 함께 동작한다.


### GET /api/user/auth/user
<!-- @generated:start:api.user.auth.user -->
- **라우트명**: `api.user.auth.user`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@user`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.auth.user`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/user/auth/user HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. 컨트롤러 메서드가 공용 `GET /api/auth/user` 와 동일한 `AuthController@user` 이므로, 응답은 `UserResource::toAuthArray()` 산물(코어 필드 + `core.user.filter_resource_data` 로 확장이 병합한 필드)로 그 문서의 응답 필드 표와 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `API 문서 샘플 사용자` | 사용자 이름 |
| nickname | string \| null | `song.hyunji` | 닉네임 (미설정 시 null) |
| email | string | `apidoc-sample-user@example.com` | 이메일 주소 |
| language | string | `ko` | 사용자 언어 설정 |
| status | string | `active` | 계정 상태 (active / inactive / blocked / withdrawn / pending_verification) |
| is_admin | boolean | `false` | 관리자 역할 보유 여부 |
| roles | array | `[{"id":2,"identifier":"user","name":"일반회원"}]` | 부여된 역할 목록 (id/identifier/name) |
| permissions | array | `[{"id":81,"identifier":"sirsoft-ecommerce.user-products.read","name":"상품 조회"}]` | 역할 경유 권한 목록 (id/identifier/name) |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_update":true, …}` | 이 리소스에 수행 가능한 작업 불리언 맵 |
| (확장 병합 필드) | — | `notify_*`, `marketing_consent*`, `ecommerce_*` 등 | marketing 플러그인·ecommerce 모듈이 `core.user.filter_resource_data` 필터로 병합 — 전수는 `GET /api/auth/user` 응답 필드 표 참조 |

> 필드 전수(코어 + 확장 병합)는 `GET /api/auth/user` 의 응답 필드 표와 동일하므로 그 표를 SSoT 로 참조합니다. 게스트(비인증) 요청은 `optional.sanctum` 을 통과하지만 `request()->user()` 가 없으므로 이 경로는 인증 사용자 컨텍스트에서만 사용자 객체를 반환합니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "name": "API 문서 샘플 사용자",
        "nickname": "song.hyunji",
        "email": "apidoc-sample-user@example.com",
        "language": "ko",
        "status": "active",
        "is_admin": false,
        "roles": [
            { "id": 2, "identifier": "user", "name": "일반회원" }
        ],
        "permissions": [],
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_assign_roles": true
        },
        "notify_post_complete": false,
        "notify_post_reply": false,
        "notify_comment": false,
        "notify_reply_comment": false,
        "ecommerce_mileage": {
            "enabled": false
        },
        "ecommerce_preferred_currency": null,
        "ecommerce_preferred_shipping_country": null,
        "ecommerce_preferred_shipping_country_name": null
    }
}
```

> 지면 절약을 위해 축약했습니다. 실제 응답은 `GET /api/auth/user` 의 응답 예시와 동일한 필드 전수를 포함합니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.auth.user`)이 없는 경우 |

<!-- @generated:end -->

**설명**

`/user` prefix 그룹(`routes/api.php:271`)의 현재 사용자 정보. 이 그룹은 `optional.sanctum` 미들웨어 아래 있어 **비인증(게스트) 요청도 통과**하며, 게스트 컨텍스트가 필요한 프론트 화면의 `data_source`(`auth_mode: "optional"`)가 이 경로를 소비한다. 응답 필드는 인증된 경우 공용 `/api/auth/user` 와 동일 형태(`toAuthArray` 병합 포함)이며, 이 경로에는 추가로 `permission:core.auth.user` 권한 게이트가 걸린다. 실측이 `403` 으로 제외된 것은 샘플 사용자에 해당 권한이 없었기 때문으로, 응답 shape 은 공용 `user` 경로를 참조한다.

> **인증 계약 요약(이슈 #64)**: `api.auth.user`(`auth:sanctum`, 필수) ↔ `api.user.auth.user`(`optional.sanctum`, 선택). 프론트 `data_source` 의 `auth_required: true` 는 전자에, `auth_mode: "optional"` 은 후자에 대응한다. 어느 한쪽 미들웨어를 바꾸면 프론트 소비 계약이 침묵 속에서 깨지므로 반드시 양쪽 문서를 함께 갱신한다.


