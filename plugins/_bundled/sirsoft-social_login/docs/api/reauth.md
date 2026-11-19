# Reauth API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Reauth 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/sirsoft-social_login/me/reauth/claim
<!-- @generated:start:api.plugins.sirsoft-social_login.me.reauth.claim -->
- **라우트명**: `api.plugins.sirsoft-social_login.me.reauth.claim`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\MyAccountsController@reauthClaim`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| code | body | string | 예 | — | 수령 코드(40자 영숫자, `size:40` `alpha_num`). 재확인 콜백이 돌아갈 주소에 `social_reauth={코드}` 로 실어 보낸 값 |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/me/reauth/claim HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "code": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| verification_token | string | `{MASKED}` | 코어 본인인증 확인 토큰(목적 `sensitive_action`). 코어 `POST /api/me/verify-password` 의 `verification_token` 으로 넘겨 정보 수정 진입과 비밀번호 첫 설정 관문을 통과한다 |

**응답 예시**

```json
{
    "success": true,
    "message": "본인을 확인했습니다.",
    "data": {
        "verification_token": "{MASKED}"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 403 | Forbidden | 회원 상태가 활성이 아니다(`check.user_status`) |
| 404 | Not Found | `errors.code` = `reauth_claim_invalid`: 수령 코드가 없거나, 만료됐거나(5분), 이미 썼거나, 다른 회원의 것이다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:60,1,sirsoft-social_login.me`, 마이페이지 연결 관리 API 가 함께 센다) |

<!-- @generated:end -->

**설명**

소셜 재확인 왕복이 끝나 `{return}?social_reauth={수령 코드}` 로 돌아온 화면이, 로그인한 상태로 코드를 확인 토큰으로 바꿀 때 부른다. 확인 토큰은 주소에 싣지 않고 이 API 로만 내준다.

수령 코드는 한 번만, 그 왕복을 시작한 회원만 쓸 수 있다. 조회하는 순간 지워지므로 실패한 코드로 다시 부를 수 없다.


### POST /api/plugins/sirsoft-social_login/me/reauth/{provider}/start
<!-- @generated:start:api.plugins.sirsoft-social_login.me.reauth.start -->
- **라우트명**: `api.plugins.sirsoft-social_login.me.reauth.start`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\MyAccountsController@reauthStart`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | path | string | 예 | — | 대상 provider의 식별자 |
| return | body | string | 아니오 | max 2000 | 확인 뒤 돌아갈 같은 출처 경로. `/` 로 시작하지 않거나 `//`, 역슬래시, 스킴, 제어문자가 든 값은 `/` 로 바뀐다(기본 `/`) |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/me/reauth/{provider}/start HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "return": "예시값"
}
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | 제공자 설정을 읽지 못했다(일반 OIDC discovery 장애 등). `errors.code` 에 제공자 코드, `message` 는 공통 문구. 만든 본인인증 요청은 취소된다 |
| 404 | Not Found | `errors.code` = `not_linked`(그 제공자가 이 회원에 연결돼 있지 않다), `provider_disabled`(제공자가 꺼졌거나 없다) |
| 403 | Forbidden | `errors.code` = `reauth_not_available`: 소셜 재확인 대상이 아니다(비밀번호가 있거나, 메일을 받을 수 있거나, 연결이 없음). 회원 상태가 활성이 아닐 때도 403(`check.user_status`) |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:60,1,sirsoft-social_login.me`, 마이페이지 연결 관리 API 가 함께 센다) |

<!-- @generated:end -->

**설명**

대체 이메일 회원(비밀번호가 없고 `…@social.invalid` 주소라 메일 인증번호를 받을 수 없는 회원)이 정보 수정 진입이나 비밀번호 첫 설정 앞에서 본인 확인을 요구받을 때, 화면의 제공자 버튼이 부른다. 코어 본인인증 요청(수단 `sirsoft-social_login`, 목적 `sensitive_action`)을 만들고, 연결된 제공자로 다시 로그인하는 인가 주소를 돌려준다.

콜백은 돌아온 제공자 계정이 이 회원의 그 제공자 연결과 같으면 `{return}?social_reauth={수령 코드 40자}` 로, 다르면 `?social_error=reauth_mismatch` 로 돌아간다. 수령 코드는 `POST /me/reauth/claim` 으로 확인 토큰과 바꾼다.


### GET /plugins/sirsoft-social_login/reauth/{ticket}
<!-- @generated:start:web.plugins.sirsoft-social_login.reauth.continue -->
- **라우트명**: `web.plugins.sirsoft-social_login.reauth.continue`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\OAuthController@reauthContinue`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ticket | path | string | 예 | — | 1회용 시작 티켓(40자 영숫자, 10분 유효). 코어 재확인 요청 응답의 `redirect_url` 에만 실려 나간다. 형식이 맞지 않으면 라우트가 맞지 않아 404 |

**요청 예시**

```http
GET /plugins/sirsoft-social_login/reauth/{ticket} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_JSON 응답이 아니다. 언제나 302 이동이며 본문 필드가 없다._

**응답 예시**

티켓이 유효할 때(제공자 재확인 인가 화면으로 이동):

```http
HTTP/1.1 302 Found
Location: https://kauth.kakao.com/oauth/authorize?response_type=code&client_id=…&redirect_uri=…&state=…
```

티켓이 없거나 이미 썼을 때:

```http
HTTP/1.1 302 Found
Location: https://example.com/mypage/profile/edit?social_error=reauth_failed
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 티켓이 40자 영숫자 형식이 아니다(라우트 제약). 형식은 맞지만 없거나 이미 쓴 티켓은 404 가 아니라 정보 수정 화면으로 302 이동한다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:30,1,sirsoft-social_login.oauth-start`, 로그인 왕복 시작과 같은 버킷) |

<!-- @generated:end -->

**설명**

화면의 「본인 확인 계속하기」(코어 `POST /api/identity/challenges`, 수단 비움)가 소셜 재확인 수단으로 정해지면 응답 `redirect_url` 이 이 주소다. 티켓을 열면 회원이 연결한 제공자 가운데 연결 순서 첫째이면서 켜진 제공자의 재확인 인가 화면으로 보낸다.

이후 왕복은 `POST /me/reauth/{provider}/start` 로 시작한 것과 같다(같은 본인인증 요청, 같은 콜백 대조, 같은 수령 코드). 티켓은 꺼내는 순간 지워진다.

