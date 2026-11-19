# Mock API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Mock 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /plugins/sirsoft-social_login/mock/.well-known/openid-configuration
<!-- @generated:start:web.plugins.sirsoft-social_login.mock.discovery -->
- **라우트명**: `web.plugins.sirsoft-social_login.mock.discovery`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Mock\MockController@discovery`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/sirsoft-social_login/mock/.well-known/openid-configuration HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_응답 봉투를 쓰지 않는다: OIDC discovery 문서 본문의 최상위 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| issuer | string | `https://example.com/plugins/sirsoft-social_login/mock` | 발급자. 사이트 주소(`APP_URL`) 기준 모의 엔드포인트 주소 |
| authorization_endpoint | string | `https://example.com/plugins/sirsoft-social_login/mock/authorize?provider=oidc` | 인가 주소. 일반 OIDC 모양(`provider=oidc`)으로 고정 |
| token_endpoint | string | `https://example.com/plugins/sirsoft-social_login/mock/token` | 토큰 교환 주소 |
| userinfo_endpoint | string | `https://example.com/plugins/sirsoft-social_login/mock/userinfo` | 사용자 정보 주소 |
| jwks_uri | string | `https://example.com/plugins/sirsoft-social_login/mock/jwks` | 서명 공개키 목록 주소 |
| response_types_supported | array | `["code"]` | 지원 응답 유형. 인가 코드 방식만 |
| id_token_signing_alg_values_supported | array | `["RS256"]` | `id_token` 서명 알고리즘 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "issuer": "https://example.com/plugins/sirsoft-social_login/mock",
    "authorization_endpoint": "https://example.com/plugins/sirsoft-social_login/mock/authorize?provider=oidc",
    "token_endpoint": "https://example.com/plugins/sirsoft-social_login/mock/token",
    "userinfo_endpoint": "https://example.com/plugins/sirsoft-social_login/mock/userinfo",
    "jwks_uri": "https://example.com/plugins/sirsoft-social_login/mock/jwks",
    "response_types_supported": ["code"],
    "id_token_signing_alg_values_supported": ["RS256"]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 모의 제공자가 꺼져 있는 경우. 라우트군 미들웨어 `MockGateMiddleware` 가 핸들러 전에 끝낸다(`php artisan sirsoft-social_login:mock --enable` 전이거나 활성 시간이 지남) |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.mock` 초과 |

<!-- @generated:end -->

**설명**

모의 일반 OIDC 제공자(`mock_oidc`)의 discovery 문서다. `mock_oidc` 설정의 `discovery_url` 이 이 주소라 일반 OIDC 드라이버가 실제 제공자처럼 읽어 인가, 토큰, 사용자 정보, JWKS 주소를 얻는다. 다른 모의 제공자(`mock_kakao`, `mock_naver`, `mock_google`, `mock_facebook`)는 discovery 를 쓰지 않고 주소를 설정에 직접 둔다.

모의 제공자는 개발과 검수 환경에서 실제 제공자 앱 없이 로그인 왕복을 재기 위한 것이다. 관리자 화면이나 API 로는 켤 수 없고 서버에서 `php artisan sirsoft-social_login:mock --enable --minutes=N`(1~240분) 으로만 켠다. 꺼져 있으면 이 경로는 404 이고 제공자 목록에도 모의 키가 나타나지 않는다.


### GET /plugins/sirsoft-social_login/mock/authorize
<!-- @generated:start:web.plugins.sirsoft-social_login.mock.authorize::get -->
- **라우트명**: `web.plugins.sirsoft-social_login.mock.authorize`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Mock\MockController@authorize`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/sirsoft-social_login/mock/authorize HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_JSON 이 아니라 HTML 동의 화면이다(`Content-Type: text/html; charset=UTF-8`, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow`)._

화면의 폼은 같은 주소로 POST 하며, 받은 요청 파라미터(`provider`, `client_id`, `redirect_uri`, `state`, `nonce`, `code_challenge`, `code_challenge_method`, `scope`)를 숨은 칸으로 넘긴다. 입력 칸은 응답 변종(`variant`, 쿼리 `variant` 가 있으면 미리 선택), 이메일(기본 `meas40.{임의 8자}@example.test`), 제공자 사용자 ID(기본 임의 9자리 숫자)이고 단추는 「동의하고 계속」(`decision=approve`)과 「거부」(`decision=deny`)다.

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/html; charset=UTF-8
Cache-Control: no-store, private
X-Robots-Tag: noindex, nofollow
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `redirect_uri` 가 이 사이트의 이 플러그인 콜백(`/plugins/sirsoft-social_login/oauth/.../callback`)이 아닌 경우. 열린 리다이렉트를 막는다 |
| 404 | Not Found | 모의 제공자가 꺼져 있는 경우. 라우트군 미들웨어 `MockGateMiddleware` 가 핸들러 전에 끝낸다(`php artisan sirsoft-social_login:mock --enable` 전이거나 활성 시간이 지남) |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.mock` 초과 |

<!-- @generated:end -->

**설명**

모의 제공자의 인가 화면이다. 모의 제공자 드라이버가 인가 주소를 이 경로(`?provider={모양}`)로 바꿔 부르므로 브라우저는 실제 제공자 대신 이 동의 화면을 본다. 검수자는 여기서 응답 변종(`full_verified`, `full_unverified`, `no_email`, `masked_email`, `nickname_only`, `string_bool`, `existing_email`, `error_body`, `deny`), 이메일, 제공자 사용자 ID 를 골라 가입, 연결, 실패 흐름을 재현한다.

받는 파라미터:

| 이름 | 위치 | 필수 | 용도 |
| --- | --- | --- | --- |
| redirect_uri | query/body | 예 | 돌아갈 콜백 주소. 사이트 주소 기준 `/plugins/sirsoft-social_login/oauth/` 아래이고 경로가 `/callback` 으로 끝나야 한다. 아니면 400 |
| provider | query/body | 아니오 | 응답 모양. `kakao`, `naver`, `google`, `facebook`, `oidc` 가운데 하나이고 그 밖의 값이나 생략은 `oidc` |
| client_id | query/body | 아니오 | 드라이버가 보낸 클라이언트 ID. 모의 `id_token` 의 `aud` 가 된다 |
| state | query/body | 아니오 | 콜백에 그대로 돌려줄 state |
| nonce | query/body | 아니오 | 모의 `id_token` 의 `nonce` 로 실린다 |
| code_challenge | query/body | 아니오 | PKCE challenge. 있으면 토큰 교환 때 `code_verifier` 를 대조한다 |
| code_challenge_method | query/body | 아니오 | 동의 화면이 다음 POST 로 넘긴다(대조는 S256 고정) |
| scope | query/body | 아니오 | 동의 화면이 다음 POST 로 넘긴다 |

모의 제공자는 개발과 검수 환경에서 실제 제공자 앱 없이 로그인 왕복을 재기 위한 것이다. 관리자 화면이나 API 로는 켤 수 없고 서버에서 `php artisan sirsoft-social_login:mock --enable --minutes=N`(1~240분) 으로만 켠다. 꺼져 있으면 이 경로는 404 이고 제공자 목록에도 모의 키가 나타나지 않는다.


### POST /plugins/sirsoft-social_login/mock/authorize
<!-- @generated:start:web.plugins.sirsoft-social_login.mock.authorize::post -->
- **라우트명**: `web.plugins.sirsoft-social_login.mock.authorize`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Mock\MockController@authorize`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /plugins/sirsoft-social_login/mock/authorize HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_JSON 이 아니라 302 리다이렉트다. 본문 필드 없음._

| 위치 | 값 | 조건 |
| --- | --- | --- |
| `Location` | `{redirect_uri}?code={40자}&state={state}` | 동의(`decision=approve`)이고 `variant` 가 `deny` 가 아님 |
| `Location` | `{redirect_uri}?error=access_denied&state={state}` | 거부(`decision` 이 `approve` 가 아님) 또는 `variant=deny` |

**응답 예시**

```http
HTTP/1.1 302 Found
Location: https://example.com/plugins/sirsoft-social_login/oauth/mock_kakao/callback?code=Q7bX2...&state=8f3a...
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `redirect_uri` 가 이 사이트의 이 플러그인 콜백이 아닌 경우 |
| 404 | Not Found | 모의 제공자가 꺼져 있는 경우. 라우트군 미들웨어 `MockGateMiddleware` 가 핸들러 전에 끝낸다(`php artisan sirsoft-social_login:mock --enable` 전이거나 활성 시간이 지남) |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.mock` 초과 |

<!-- @generated:end -->

**설명**

동의 화면의 제출을 받아 인가 코드를 발급하고 콜백으로 보낸다. 받는 값은 GET 과 같은 파라미터(본문)에 더해 `decision`(`approve` 이면 동의), `variant`(응답 변종, 목록에 없으면 `full_verified`), `email`(형식이 틀리면 임의 `@example.test` 주소), `subject`(제공자 사용자 ID, 비었거나 64자를 넘으면 모양별 임의 값: 카카오와 페이스북은 9자리 숫자, 네이버는 `Nv` + 20자, 그 밖은 `mock-` + 16자)다.

인가 코드는 40자 임의 문자열이고 5분 동안 플러그인 캐시에 모양, 변종, 사용자 ID, 이메일, nonce, client_id, redirect_uri, code_challenge 와 함께 보관된다. 사이트 CSRF 검사는 이 라우트군에서 빠져 있다.

모의 제공자는 개발과 검수 환경에서 실제 제공자 앱 없이 로그인 왕복을 재기 위한 것이다. 관리자 화면이나 API 로는 켤 수 없고 서버에서 `php artisan sirsoft-social_login:mock --enable --minutes=N`(1~240분) 으로만 켠다. 꺼져 있으면 이 경로는 404 이고 제공자 목록에도 모의 키가 나타나지 않는다.


### GET /plugins/sirsoft-social_login/mock/jwks
<!-- @generated:start:web.plugins.sirsoft-social_login.mock.jwks -->
- **라우트명**: `web.plugins.sirsoft-social_login.mock.jwks`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Mock\MockController@jwks`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/sirsoft-social_login/mock/jwks HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_응답 봉투를 쓰지 않는다: JWKS 본문. `keys[]` 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| kty | string | `RSA` | 키 종류 |
| kid | string | `mock-3f9a1c0b7d2e4a65` | 키 ID. `mock-` + 공개키 모듈러스 SHA-256 앞 16자. `id_token` 헤더의 `kid` 와 같다 |
| use | string | `sig` | 용도(서명) |
| alg | string | `RS256` | 서명 알고리즘 |
| n | string | `xGq3...` | 공개키 모듈러스(base64url) |
| e | string | `AQAB` | 공개키 지수(base64url) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "keys": [
        {
            "kty": "RSA",
            "kid": "mock-3f9a1c0b7d2e4a65",
            "use": "sig",
            "alg": "RS256",
            "n": "xGq3...",
            "e": "AQAB"
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 모의 제공자가 꺼져 있는 경우. 라우트군 미들웨어 `MockGateMiddleware` 가 핸들러 전에 끝낸다(`php artisan sirsoft-social_login:mock --enable` 전이거나 활성 시간이 지남) |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.mock` 초과 |

<!-- @generated:end -->

**설명**

모의 `id_token` 서명 공개키 목록이다. 구글 모양과 일반 OIDC 모양의 토큰 응답에 실리는 `id_token` 을 드라이버가 이 키로 검증한다. 키는 처음 필요할 때 만들어 플러그인 캐시에 최대 240분 보관하고, 모의 제공자를 끄면(`--disable` 또는 만료 정리) 함께 지운다. 그래서 다시 켜면 `kid` 가 바뀐다.

모의 제공자는 개발과 검수 환경에서 실제 제공자 앱 없이 로그인 왕복을 재기 위한 것이다. 관리자 화면이나 API 로는 켤 수 없고 서버에서 `php artisan sirsoft-social_login:mock --enable --minutes=N`(1~240분) 으로만 켠다. 꺼져 있으면 이 경로는 404 이고 제공자 목록에도 모의 키가 나타나지 않는다.


### DELETE /plugins/sirsoft-social_login/mock/revoke
<!-- @generated:start:web.plugins.sirsoft-social_login.mock.revoke::delete -->
- **라우트명**: `web.plugins.sirsoft-social_login.mock.revoke`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Mock\MockController@revoke`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
DELETE /plugins/sirsoft-social_login/mock/revoke HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_응답 봉투를 쓰지 않는다: 본문 최상위 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| success | boolean | `true` | 언제나 `true` |
| result | string | `success` | 언제나 `success` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "result": "success"
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 모의 제공자가 꺼져 있는 경우. 라우트군 미들웨어 `MockGateMiddleware` 가 핸들러 전에 끝낸다(`php artisan sirsoft-social_login:mock --enable` 전이거나 활성 시간이 지남) |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.mock` 초과 |

<!-- @generated:end -->

**설명**

모의 제공자의 권한 회수(연결 해제) 주소다. 페이스북 드라이버는 회수를 DELETE 로 부른다. 요청 내용을 보지 않고 언제나 성공으로 답해, 모의 계정의 연결 해제가 실제 드라이버의 회수 경로를 끝까지 타되 가짜 실패를 남기지 않게 한다.

모의 제공자는 개발과 검수 환경에서 실제 제공자 앱 없이 로그인 왕복을 재기 위한 것이다. 관리자 화면이나 API 로는 켤 수 없고 서버에서 `php artisan sirsoft-social_login:mock --enable --minutes=N`(1~240분) 으로만 켠다. 꺼져 있으면 이 경로는 404 이고 제공자 목록에도 모의 키가 나타나지 않는다.


### POST /plugins/sirsoft-social_login/mock/revoke
<!-- @generated:start:web.plugins.sirsoft-social_login.mock.revoke::post -->
- **라우트명**: `web.plugins.sirsoft-social_login.mock.revoke`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Mock\MockController@revoke`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /plugins/sirsoft-social_login/mock/revoke HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_응답 봉투를 쓰지 않는다: 본문 최상위 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| success | boolean | `true` | 언제나 `true` |
| result | string | `success` | 언제나 `success` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "result": "success"
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 모의 제공자가 꺼져 있는 경우. 라우트군 미들웨어 `MockGateMiddleware` 가 핸들러 전에 끝낸다(`php artisan sirsoft-social_login:mock --enable` 전이거나 활성 시간이 지남) |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.mock` 초과 |

<!-- @generated:end -->

**설명**

모의 제공자의 권한 회수(연결 해제) 주소다. 카카오(어드민 키), 네이버, 구글 드라이버는 회수를 POST 로 부른다. 요청 내용을 보지 않고 언제나 성공으로 답한다. 모의 카카오 설정에는 어드민 키 `mock-admin-key` 가 들어 있어 카카오 드라이버가 회수를 건너뛰지 않는다.

모의 제공자는 개발과 검수 환경에서 실제 제공자 앱 없이 로그인 왕복을 재기 위한 것이다. 관리자 화면이나 API 로는 켤 수 없고 서버에서 `php artisan sirsoft-social_login:mock --enable --minutes=N`(1~240분) 으로만 켠다. 꺼져 있으면 이 경로는 404 이고 제공자 목록에도 모의 키가 나타나지 않는다.


### GET /plugins/sirsoft-social_login/mock/token
<!-- @generated:start:web.plugins.sirsoft-social_login.mock.token::get -->
- **라우트명**: `web.plugins.sirsoft-social_login.mock.token`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Mock\MockController@token`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/sirsoft-social_login/mock/token HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_응답 봉투를 쓰지 않는다: OAuth 토큰 응답 본문의 최상위 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| access_token | string | `k3Lm...` | 접근 토큰(48자). 1시간 동안 사용자 정보 조회에 쓴다 |
| token_type | string | `bearer` | 토큰 유형 |
| expires_in | integer | `3600` | 유효 시간(초). 네이버 모양은 실제 네이버처럼 문자열 `"3600"` |
| refresh_token | string | `Zp9w...` | 갱신 토큰(48자). 페이스북 모양에는 없다 |
| id_token | string | `eyJhbGciOiJSUzI1NiIs...` | 구글 모양과 일반 OIDC 모양에만 있다. RS256 JWT(`iss` 발급자, `aud` client_id, `sub` 사용자 ID, `iat`, `exp` 1시간, `nonce`), 서명 키는 `/mock/jwks` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "access_token": "k3Lm...",
    "token_type": "bearer",
    "expires_in": 3600,
    "refresh_token": "Zp9w...",
    "id_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6Im1vY2stM2Y5YTFjMGI3ZDJlNGE2NSJ9..."
}
```

실패 응답(400) 본문:

```json
{ "error": "invalid_grant", "error_code": "KOE320", "error_description": "authorization code not found" }
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 인가 코드가 없거나 만료됐거나 이미 썼거나, `redirect_uri` 가 인가 때와 다른 경우. 본문 `{"error":"invalid_grant","error_code":"KOE320","error_description":"authorization code not found"}` |
| 400 | Bad Request | 인가 때 `code_challenge` 를 받았는데 `code_verifier` 의 S256 값이 맞지 않는 경우. 본문 `{"error":"invalid_grant","error_description":"code_verifier mismatch"}` |
| 404 | Not Found | 모의 제공자가 꺼져 있는 경우. 라우트군 미들웨어 `MockGateMiddleware` 가 핸들러 전에 끝낸다(`php artisan sirsoft-social_login:mock --enable` 전이거나 활성 시간이 지남) |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.mock` 초과 |

<!-- @generated:end -->

**설명**

인가 코드를 접근 토큰으로 바꾼다. 받는 값은 `code`, `redirect_uri`(인가 때와 같아야 한다), `code_verifier`(PKCE 를 썼을 때)다. 페이스북 드라이버는 토큰 교환을 GET 쿼리로 부르므로 GET 도 받는다.

인가 코드는 조회하자마자 지우므로 한 번만 쓸 수 있다. 실패 본문은 카카오의 `KOE320` 모양을 흉내 낸다.

모의 제공자는 개발과 검수 환경에서 실제 제공자 앱 없이 로그인 왕복을 재기 위한 것이다. 관리자 화면이나 API 로는 켤 수 없고 서버에서 `php artisan sirsoft-social_login:mock --enable --minutes=N`(1~240분) 으로만 켠다. 꺼져 있으면 이 경로는 404 이고 제공자 목록에도 모의 키가 나타나지 않는다.


### POST /plugins/sirsoft-social_login/mock/token
<!-- @generated:start:web.plugins.sirsoft-social_login.mock.token::post -->
- **라우트명**: `web.plugins.sirsoft-social_login.mock.token`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Mock\MockController@token`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /plugins/sirsoft-social_login/mock/token HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_응답 봉투를 쓰지 않는다: OAuth 토큰 응답 본문의 최상위 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| access_token | string | `k3Lm...` | 접근 토큰(48자). 1시간 동안 사용자 정보 조회에 쓴다 |
| token_type | string | `bearer` | 토큰 유형 |
| expires_in | integer | `3600` | 유효 시간(초). 네이버 모양은 실제 네이버처럼 문자열 `"3600"` |
| refresh_token | string | `Zp9w...` | 갱신 토큰(48자). 페이스북 모양에는 없다 |
| id_token | string | `eyJhbGciOiJSUzI1NiIs...` | 구글 모양과 일반 OIDC 모양에만 있다. RS256 JWT(`iss` 발급자, `aud` client_id, `sub` 사용자 ID, `iat`, `exp` 1시간, `nonce`), 서명 키는 `/mock/jwks` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "access_token": "k3Lm...",
    "token_type": "bearer",
    "expires_in": 3600,
    "refresh_token": "Zp9w...",
    "id_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6Im1vY2stM2Y5YTFjMGI3ZDJlNGE2NSJ9..."
}
```

실패 응답(400) 본문:

```json
{ "error": "invalid_grant", "error_code": "KOE320", "error_description": "authorization code not found" }
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 인가 코드가 없거나 만료됐거나 이미 썼거나, `redirect_uri` 가 인가 때와 다른 경우. 본문 `{"error":"invalid_grant","error_code":"KOE320","error_description":"authorization code not found"}` |
| 400 | Bad Request | 인가 때 `code_challenge` 를 받았는데 `code_verifier` 의 S256 값이 맞지 않는 경우. 본문 `{"error":"invalid_grant","error_description":"code_verifier mismatch"}` |
| 404 | Not Found | 모의 제공자가 꺼져 있는 경우. 라우트군 미들웨어 `MockGateMiddleware` 가 핸들러 전에 끝낸다(`php artisan sirsoft-social_login:mock --enable` 전이거나 활성 시간이 지남) |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.mock` 초과 |

<!-- @generated:end -->

**설명**

인가 코드를 접근 토큰으로 바꾼다. 받는 값은 `code`, `redirect_uri`(인가 때와 같아야 한다), `code_verifier`(PKCE 를 썼을 때)다. 페이스북을 뺀 드라이버는 토큰 교환을 POST 본문으로 부른다.

인가 코드는 조회하자마자 지우므로 한 번만 쓸 수 있다. 실패 본문은 카카오의 `KOE320` 모양을 흉내 낸다.

모의 제공자는 개발과 검수 환경에서 실제 제공자 앱 없이 로그인 왕복을 재기 위한 것이다. 관리자 화면이나 API 로는 켤 수 없고 서버에서 `php artisan sirsoft-social_login:mock --enable --minutes=N`(1~240분) 으로만 켠다. 꺼져 있으면 이 경로는 404 이고 제공자 목록에도 모의 키가 나타나지 않는다.


### GET /plugins/sirsoft-social_login/mock/userinfo
<!-- @generated:start:web.plugins.sirsoft-social_login.mock.userinfo -->
- **라우트명**: `web.plugins.sirsoft-social_login.mock.userinfo`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Mock\MockController@userinfo`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/sirsoft-social_login/mock/userinfo HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_응답 봉투를 쓰지 않는다: 본문은 인가 때 고른 응답 모양(`provider`)을 따른다._

| 모양 | 본문 |
| --- | --- |
| `kakao` | `id`(숫자 문자열이면 정수), `kakao_account`(`profile.nickname`, `profile.profile_image_url`, `email_needs_agreement`, 이메일이 있으면 `email`, `is_email_valid`, `is_email_verified`) |
| `naver` | `resultcode` `00`, `message` `success`, `response`(`id`, `email`, `name`, `nickname`, `profile_image`) |
| `facebook` | `id`, `name`(이름이 없으면 별명), `email`, `picture.data.url` |
| `google`, `oidc` | `sub`, `email`, `email_verified`, `name`, `preferred_username`, `picture` |

값이 없는 키는 빠진다. 변종별로 `no_email` 과 `nickname_only` 는 이메일이 없고(카카오는 `email_needs_agreement: true`), `nickname_only` 는 이름도 없고, `full_unverified` 는 이메일 미확인, `masked_email` 은 카카오 모양에서 가린 이메일(`ka***@kakao.com`, `is_email_valid: false`), `string_bool` 은 `email_verified` 를 문자열 `"true"` 로 싣는다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "sub": "mock-a1b2c3d4e5f6g7h8",
    "email": "meas40.k2m9x7qa@example.test",
    "email_verified": true,
    "name": "Mock User",
    "preferred_username": "mocknick",
    "picture": "https://example.test/mock-avatar.png"
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | 접근 토큰이 없거나 모르는(만료된) 토큰인 경우. 본문 `{"error":"invalid_token"}` |
| 200, 400, 401 | 변종 `error_body` | 제공자 오류 본문을 흉내 낸다. 네이버 200 `{"resultcode":"024","message":"Authentication failed"}`, 카카오 401 `{"msg":"this access token does not exist","code":-401}`, 페이스북 400 `{"error":{"message":"Invalid OAuth access token.","type":"OAuthException","code":190}}`, 그 밖 401 `{"error":"invalid_token","error_description":"mock error body"}` |
| 404 | Not Found | 모의 제공자가 꺼져 있는 경우. 라우트군 미들웨어 `MockGateMiddleware` 가 핸들러 전에 끝낸다(`php artisan sirsoft-social_login:mock --enable` 전이거나 활성 시간이 지남) |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.mock` 초과 |

<!-- @generated:end -->

**설명**

접근 토큰으로 모의 사용자 정보를 돌려준다. 토큰은 `Authorization: Bearer {access_token}` 헤더 또는 쿼리 `access_token`(페이스북 드라이버 방식)으로 받는다. 인가 화면에서 고른 변종과 이메일, 사용자 ID 가 그대로 실려 드라이버의 정규화(이메일 신뢰, 문자열 불리언, 가린 이메일 처리)를 재현한다.

모의 제공자는 개발과 검수 환경에서 실제 제공자 앱 없이 로그인 왕복을 재기 위한 것이다. 관리자 화면이나 API 로는 켤 수 없고 서버에서 `php artisan sirsoft-social_login:mock --enable --minutes=N`(1~240분) 으로만 켠다. 꺼져 있으면 이 경로는 404 이고 제공자 목록에도 모의 키가 나타나지 않는다.

