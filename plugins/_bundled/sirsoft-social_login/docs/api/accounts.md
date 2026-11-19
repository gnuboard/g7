# Accounts API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Accounts 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-social_login/me/accounts
<!-- @generated:start:api.plugins.sirsoft-social_login.me.accounts.index -->
- **라우트명**: `api.plugins.sirsoft-social_login.me.accounts.index`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\MyAccountsController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/me/accounts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| accounts | array | `[]` | 이 회원에 연결된 소셜 계정 목록. 항목은 `provider_key`, `label`, `brand`, `email`(앞 2자만 보이는 마스킹 값, 없으면 `null`), `linked_at`, `last_login_at`(보는 사람 시간대의 `YYYY-MM-DD HH:mm:ss`) |
| available | array | `[{"provider_key":"kakao","label":"카카오","brand":"kakao","s…` | 아직 연결하지 않은 켜진 제공자. 항목은 `provider_key`, `label`, `brand`, `style`(커스텀 제공자의 버튼 색 `{ "bg", "fg", "border" }`, 16진 색만, 없으면 `null`) |
| has_password | boolean | `true` | 회원에게 비밀번호가 있는지. 없고 연결이 하나뿐이면 해제가 막힌다 |
| can_unlink | array | `[]` | 연결된 제공자 키별 해제 가능 여부 `{ "kakao": { "allowed": false, "reason": "last_method" } }`. 비밀번호가 없고 연결이 하나뿐이면 `last_method` 로 막힌다(설정 `allow_unlink_last_method` 가 켜져 있으면 허용). 연결이 없으면 비어 있다 |
| reauth_via_social | boolean | `false` | 민감 작업의 본인 재확인이 실제로 소셜 재확인으로 가는지. 비밀번호가 없고 메일을 받을 수 없는 회원(대체 이메일)이 연결을 가졌고, 코어가 그 회원의 재확인 수단으로 이 플러그인을 고를 때만 `true` |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "연결된 소셜 계정 목록입니다.",
    "data": {
        "accounts": [],
        "available": [
            {
                "provider_key": "kakao",
                "label": "카카오",
                "brand": "kakao",
                "style": null
            }
        ],
        "has_password": true,
        "can_unlink": [],
        "reauth_via_social": false
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 회원 상태가 활성이 아니다(`check.user_status`) |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:60,1,sirsoft-social_login.me`, 마이페이지 연결 관리 API 가 함께 센다) |

<!-- @generated:end -->

**설명**

마이페이지 「소셜 계정 연결」 카드가 부른다. 연결된 계정, 새로 연결할 수 있는 제공자, 해제 가능 여부를 한 번에 돌려준다.

`reauth_via_social` 은 화면의 소셜 재확인 안내와 제공자 버튼을 보일지 정하는 값이다. 대상 회원이어도 코어가 다른 수단(예: 회사 계정 다시 로그인)을 먼저 고르면 `false` 다. 제공자 버튼으로 직접 시작하는 재확인(`POST /me/reauth/{provider}/start`)은 이 값과 관계없이 받는다.


### DELETE /api/plugins/sirsoft-social_login/me/accounts/{provider}
<!-- @generated:start:api.plugins.sirsoft-social_login.me.accounts.destroy -->
- **라우트명**: `api.plugins.sirsoft-social_login.me.accounts.destroy`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\MyAccountsController@destroy`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | path | string | 예 | — | 제공자 키(`^[a-z][a-z0-9_]{1,49}$`). 형식이 맞지 않으면 라우트가 맞지 않아 404 |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-social_login/me/accounts/{provider} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| provider_key | string | `kakao` | 연결을 해제한 제공자 키 |

**응답 예시**

```json
{
    "success": true,
    "message": "소셜 계정 연결을 해제했습니다.",
    "data": {
        "provider_key": "kakao"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | `errors.code` = `not_linked`: 그 제공자가 이 회원에 연결돼 있지 않다 |
| 403 | Forbidden | 회원 상태가 활성이 아니다(`check.user_status`) |
| 409 | Conflict | `errors.code` = `last_method`: 비밀번호가 없고 연결이 하나뿐이라 해제하면 로그인할 수 없다(설정 `allow_unlink_last_method` 로 허용) |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:60,1,sirsoft-social_login.me`, 마이페이지 연결 관리 API 가 함께 센다) |

<!-- @generated:end -->

**설명**

마이페이지에서 [연결 해제]를 누를 때 부른다. 설정 `revoke_on_unlink`(기본 켜짐)이고 제공자가 권한 회수를 지원하면 제공자 쪽 권한 회수를 시도한다. 회수에 실패해도 이 사이트의 연결은 끊고, 실패는 시도 기록에 남긴다.

`last_method` 로 막히면 화면은 비밀번호를 설정하거나 다른 계정을 연결한 뒤 해제하라고 안내한다.


### POST /api/plugins/sirsoft-social_login/me/accounts/{provider}/link-start
<!-- @generated:start:api.plugins.sirsoft-social_login.me.accounts.link-start -->
- **라우트명**: `api.plugins.sirsoft-social_login.me.accounts.link-start`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\MyAccountsController@linkStart`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | path | string | 예 | — | 제공자 키(`^[a-z][a-z0-9_]{1,49}$`). 형식이 맞지 않으면 라우트가 맞지 않아 404 |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/me/accounts/{provider}/link-start HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| authorize_url | string | `https://kauth.kakao.com/oauth/authorize?response_type=code&client_id=…&redirect_uri=…&state=…` | 제공자 인가 화면 주소. 화면이 이 주소로 이동한다. `state` 는 1회용이고 서버에 보관된다 |

**응답 예시**

```json
{
    "success": true,
    "message": "제공자 화면으로 이동합니다.",
    "data": {
        "authorize_url": "https://kauth.kakao.com/oauth/authorize?response_type=code&client_id=…&redirect_uri=…&state=…"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | `errors.code` = `provider_disabled`: 제공자가 꺼졌거나 없다 |
| 403 | Forbidden | 회원 상태가 활성이 아니다(`check.user_status`) |
| 409 | Conflict | `errors.code` = `provider_already_linked`: 이 회원은 이 제공자에 이미 계정을 연결했다 |
| 422 | Unprocessable Entity | 제공자 설정을 읽지 못했다(일반 OIDC discovery 장애 등). `errors.code` 에 제공자 코드, `message` 는 공통 문구 「소셜 계정 제공자에 연결하지 못했습니다.」 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:60,1,sirsoft-social_login.me`, 마이페이지 연결 관리 API 가 함께 센다) |

<!-- @generated:end -->

**설명**

마이페이지에서 [연결]을 누를 때 부른다. 연결 왕복을 시작하고 제공자 인가 주소를 돌려준다. 화면이 그 주소로 이동하면, 콜백이 연결을 만든 뒤 `/mypage/profile?social=linked&provider={키}` 로 돌아온다. 실패하면 `/mypage/profile?social_error={코드}&provider={키}` 로 돌아온다(다른 회원에 연결된 제공자 계정은 `already_linked_other_user`).

