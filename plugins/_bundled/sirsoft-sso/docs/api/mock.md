# Mock API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 개발과 검수 전용 모의 IdP(OIDC 6개, SAML 6개 주소)다. 실제 IdP 없이 로그인, 로그아웃, 검증 실패 변종을 재현한다
2. 서버 명령 php artisan sirsoft-sso:mock --enable --minutes=N(기본 60, 최대 240)으로 켠 동안만 열린다. 꺼져 있으면 모든 주소가 404
3. 운영 환경(APP_ENV=production)에서는 명령이 거절해 켜지지 않는다. 화면, API, 설정으로 켜는 경로는 없다
4. 모의 IdP 행은 sirsoft-sso:mock --seed-idp(mock_oidc), sirsoft-sso:mock-saml --seed-idp(mock_saml_a)로 만든다. 키 접두 mock_ 는 실제 IdP 에 쓸 수 없다
5. 웹 라우트다(G7 JSON API 아님). OIDC 주소는 OIDC 규격 JSON, SAML 주소는 XML, HTML 화면, 302 로 답한다
```

---

## 공통 사항

- **게이트**: `MockGateMiddleware` 가 라우트명 `web.plugins.sirsoft-sso.mock.*` 전체에 붙는다. 게이트는 플러그인 캐시에 만료 시각으로 저장되고, 시간이 지나면 저절로 꺼진다. `sirsoft-sso:mock --disable` 은 게이트와 모의 서명 키를 함께 지운다.
- **꺼졌을 때**: 이 문서의 모든 주소가 404, 로그인 화면에 모의 IdP 버튼이 보이지 않고, 모의 IdP(`mock_` 키)로 로그인을 시작하면 `/login?sso_error=idp_disabled`, 모의 SAML IdP 의 SP 메타데이터와 SLS 도 404 다.
- **안전 장치**: 모의 IdP 는 이 사이트의 SSO 콜백(`/plugins/sirsoft-sso/oidc/{키}/callback`)과 모의 SAML IdP 의 ACS(`/plugins/sirsoft-sso/saml/mock_…/acs`)로만 코드와 응답을 보낸다. 다른 주소면 400 이다. CSRF 토큰을 쓰지 않는다(교차 사이트 요청을 흉내 낸다).
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`(모든 모의 주소가 한 버킷).
- **서버 명령 보조**: `sirsoft-sso:mock --backchannel={IdP 키} --sub={subject}` 는 모의 IdP 이름으로 서명한 back-channel 로그아웃을 보낸다. `sirsoft-sso:mock-saml --logout --name-id=… --session-index=…` 는 서명한 LogoutRequest 를 SP 의 SLS 로 보낸다.

| 에러 | 조건 |
| --- | --- |
| 404 | 게이트가 꺼짐(모든 주소) |
| 429 | 분당 120회 초과 |


### GET /plugins/sirsoft-sso/mock/oidc/.well-known/openid-configuration
<!-- @generated:start:web.plugins.sirsoft-sso.mock.oidc.discovery -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.oidc.discovery`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController@discovery`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/sirsoft-sso/mock/oidc/.well-known/openid-configuration HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (OIDC discovery 문서 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| issuer | string | — | `{사이트 주소}/plugins/sirsoft-sso/mock/oidc` |
| authorization_endpoint | string | — | `{issuer}/authorize` |
| token_endpoint | string | — | `{issuer}/token` |
| jwks_uri | string | — | `{issuer}/jwks` |
| end_session_endpoint | string | — | `{issuer}/end-session` |
| response_types_supported | array | — | `["code"]` |
| subject_types_supported | array | — | `["public"]` |
| id_token_signing_alg_values_supported | array | — | `["RS256"]` |
| code_challenge_methods_supported | array | — | `["S256"]` |
| token_endpoint_auth_methods_supported | array | — | `["client_secret_post", "client_secret_basic"]` |
| scopes_supported | array | — | `["openid", "email", "profile", "offline_access"]` |
| backchannel_logout_supported | boolean | — | true |
| backchannel_logout_session_supported | boolean | — | true |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/json
```

```json
{
    "issuer": "https://www.example.com/plugins/sirsoft-sso/mock/oidc",
    "authorization_endpoint": "https://www.example.com/plugins/sirsoft-sso/mock/oidc/authorize",
    "token_endpoint": "https://www.example.com/plugins/sirsoft-sso/mock/oidc/token",
    "jwks_uri": "https://www.example.com/plugins/sirsoft-sso/mock/oidc/jwks",
    "end_session_endpoint": "https://www.example.com/plugins/sirsoft-sso/mock/oidc/end-session",
    "response_types_supported": ["code"],
    "subject_types_supported": ["public"],
    "id_token_signing_alg_values_supported": ["RS256"],
    "code_challenge_methods_supported": ["S256"],
    "token_endpoint_auth_methods_supported": ["client_secret_post", "client_secret_basic"],
    "scopes_supported": ["openid", "email", "profile", "offline_access"],
    "backchannel_logout_supported": true,
    "backchannel_logout_session_supported": true
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

모의 OIDC IdP 행(`mock_oidc`)의 `discovery_url` 이 이 주소다.


### GET /plugins/sirsoft-sso/mock/oidc/authorize
<!-- @generated:start:web.plugins.sirsoft-sso.mock.oidc.authorize::get -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.oidc.authorize`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController@authorize`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| redirect_uri | query | string | 예 | 이 사이트의 `/plugins/sirsoft-sso/oidc/{키}/callback` | 코드를 돌려보낼 주소. 다르면 400 |
| client_id | query | string | 아니오 | — | 토큰 교환 때 같은 값인지 본다 |
| state | query | string | 아니오 | — | 그대로 돌려준다 |
| nonce | query | string | 아니오 | — | id_token 에 싣는다 |
| code_challenge | query | string | 아니오 | — | PKCE(S256) |
| code_challenge_method | query | string | 아니오 | — | 화면 숨은 칸으로 넘긴다 |
| scope | query | string | 아니오 | — | 화면 숨은 칸으로 넘긴다 |

**요청 예시**

```http
GET /plugins/sirsoft-sso/mock/oidc/authorize?response_type=code&client_id=mock-client&redirect_uri=https%3A%2F%2Fwww.example.com%2Fplugins%2Fsirsoft-sso%2Foidc%2Fmock_oidc%2Fcallback&state={STATE}&nonce={NONCE} HTTP/1.1
Host: api.example.com
Accept: text/html
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. 승인 화면 HTML(`Cache-Control: no-store`)을 돌려준다. 화면에서 변종(variant), 이메일, sub, 이름을 고르고 [Approve] 또는 [Deny] 를 누르면 같은 주소로 POST 한다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: text/html; charset=utf-8
Cache-Control: no-store
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `redirect_uri` 가 이 사이트의 SSO 콜백이 아님 |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

로그인 화면의 모의 IdP 버튼을 누르면 SP 가 이 주소로 보낸다.


### POST /plugins/sirsoft-sso/mock/oidc/authorize
<!-- @generated:start:web.plugins.sirsoft-sso.mock.oidc.authorize::post -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.oidc.authorize`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController@authorize`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만, CSRF 면제
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| redirect_uri | body | string | 예 | 이 사이트의 SSO 콜백 | 코드를 돌려보낼 주소 |
| decision | body | string | 예 | `approve`, `deny` | 승인 또는 거절 |
| variant | body | string | 아니오 | 아래 변종(기본 `full`) | id_token 과 토큰 응답을 바꿀 변종 |
| email | body | string | 아니오 | — | id_token 의 `email` |
| sub | body | string | 아니오 | 191자 | id_token 의 `sub`. 비우면 이메일에서 만든 고정 값 `mock-{24자}` |
| name | body | string | 아니오 | 100자 | id_token 의 `name`(기본 「모의 직원」) |
| client_id, state, nonce, code_challenge | body | string | 아니오 | — | 승인 화면 숨은 칸 |

변종: `full`, `mfa`, `acr_mfa`, `no_email`, `email_unverified`, `email_verified_string`, `groups_string`, `nonce_mismatch`, `aud_other`, `azp_missing_multi_aud`, `alg_none`, `alg_hs256`, `expired`, `iat_future`, `kid_rotated`, `deny`, `token_error`, `refresh_invalid`. 정상 로그인(`full`), 다중 인증 신호(`mfa`, `acr_mfa`), 클레임 모양 차이, 서명과 클레임 검증 실패, 토큰 교환 실패, 갱신 토큰 무효(주기 점검의 퇴사 판정)를 재현한다.

**요청 예시**

```http
POST /plugins/sirsoft-sso/mock/oidc/authorize HTTP/1.1
Host: api.example.com
Content-Type: application/x-www-form-urlencoded

redirect_uri=https%3A%2F%2Fwww.example.com%2Fplugins%2Fsirsoft-sso%2Foidc%2Fmock_oidc%2Fcallback&state={STATE}&nonce={NONCE}&client_id=mock-client&variant=full&email=hong%40example.com&decision=approve
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. `redirect_uri` 로 **302 리다이렉트**한다. 승인이면 `?code={40자}&state=…`(코드 5분), 거절이나 `deny` 변종이면 `?error=access_denied&state=…`.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 302 Found
Location: https://www.example.com/plugins/sirsoft-sso/oidc/mock_oidc/callback?code={CODE}&state={STATE}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `redirect_uri` 가 이 사이트의 SSO 콜백이 아님 |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

같은 이메일은 언제나 같은 `sub` 가 되므로 같은 모의 직원으로 여러 번 로그인할 수 있다.


### GET /plugins/sirsoft-sso/mock/oidc/end-session
<!-- @generated:start:web.plugins.sirsoft-sso.mock.oidc.end-session -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.oidc.end-session`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController@endSession`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| post_logout_redirect_uri | query | string | 아니오 | 같은 출처 주소 | 로그아웃 뒤 돌아갈 주소 |
| client_id | query | string | 아니오 | — | 읽지 않는다 |

**요청 예시**

```http
GET /plugins/sirsoft-sso/mock/oidc/end-session?client_id=mock-client&post_logout_redirect_uri=https%3A%2F%2Fwww.example.com%2Fplugins%2Fsirsoft-sso%2Flogout%2Freturn HTTP/1.1
Host: api.example.com
Accept: text/html
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. `post_logout_redirect_uri` 가 같은 출처면 그리로 302, 아니면 「signed out」 HTML(200).

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 302 Found
Location: https://www.example.com/plugins/sirsoft-sso/logout/return
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

IdP 로그아웃(`logout_mode` 가 `rp`, `both`)을 시험할 때 SP 가 이 주소로 보낸다.


### GET /plugins/sirsoft-sso/mock/oidc/jwks
<!-- @generated:start:web.plugins.sirsoft-sso.mock.oidc.jwks -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.oidc.jwks`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController@jwks`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/sirsoft-sso/mock/oidc/jwks HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (JWK Set — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| keys | array | — | 모의 서명 공개키(RS256 JWK). 키 교체 변종(`kid_rotated`)으로 교체 키를 한 번 만든 뒤에는 그 키도 함께 실린다 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/json
```

```json
{
    "keys": [
        { "kty": "RSA", "use": "sig", "alg": "RS256", "kid": "…", "n": "…", "e": "AQAB" }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

모의 서명 키는 처음 쓸 때 만들어 플러그인 캐시에 두고, 게이트를 끌 때 지운다.


### POST /plugins/sirsoft-sso/mock/oidc/token
<!-- @generated:start:web.plugins.sirsoft-sso.mock.oidc.token -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.oidc.token`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController@token`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만, CSRF 면제(서버 간 요청)
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| grant_type | body | string | 예 | `authorization_code`, `refresh_token` | 교환 종류 |
| code | body | string | 조건부 | — | 인가 코드(1회, 5분) |
| redirect_uri | body | string | 조건부 | — | 인가 때와 같아야 한다 |
| code_verifier | body | string | 조건부 | — | 인가 때 `code_challenge` 를 보냈으면 필수(S256) |
| refresh_token | body | string | 조건부 | — | `grant_type=refresh_token` 일 때 |
| client_id | body | string | 아니오 | — | `client_secret_post` 방식. HTTP Basic 사용자 이름으로도 받는다. 인가 때와 같아야 한다 |
| client_secret | body | string | 아니오 | — | 확인하지 않는다 |

**요청 예시**

```http
POST /plugins/sirsoft-sso/mock/oidc/token HTTP/1.1
Host: api.example.com
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code&code={CODE}&redirect_uri=https%3A%2F%2Fwww.example.com%2Fplugins%2Fsirsoft-sso%2Foidc%2Fmock_oidc%2Fcallback&client_id=mock-client&client_secret={SECRET}&code_verifier={VERIFIER}
```

**응답 필드** (OAuth 토큰 응답 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| access_token | string | — | 모의 액세스 토큰(48자, 쓰이지 않는다) |
| token_type | string | — | `Bearer` |
| expires_in | integer | — | 3600 |
| refresh_token | string | — | 갱신 토큰(하루, 쓸 때마다 새로 발급) |
| id_token | string | — | 변종대로 서명한 ID 토큰(`authorization_code` 일 때만) |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/json
```

```json
{
    "access_token": "{MASKED}",
    "token_type": "Bearer",
    "expires_in": 3600,
    "refresh_token": "{MASKED}",
    "id_token": "{MASKED}"
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `{"error":"invalid_grant"}` — 없거나 쓴 코드, `redirect_uri` 불일치, `code_verifier` 불일치, `token_error` 변종, 무효 갱신 토큰(`refresh_invalid` 변종 포함) |
| 401 | Unauthorized | `{"error":"invalid_client"}` — `client_id` 가 인가 때와 다름 |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

SP 의 OIDC 드라이버가 서버 간으로 부른다. 갱신 토큰 교환은 주기 점검(`deprovision.periodic`)을 시험할 때 쓰인다.


### POST /plugins/sirsoft-sso/mock/saml/approve
<!-- @generated:start:web.plugins.sirsoft-sso.mock.saml.approve -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.saml.approve`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController@approve`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만, CSRF 면제
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| acs | body | string | 예 | 이 사이트의 `/plugins/sirsoft-sso/saml/mock_…/acs` 절대 주소 | 응답을 보낼 ACS. 모의 SAML IdP 의 ACS 가 아니면 400 |
| decision | body | string | 예 | `approve`, 그 밖 | `approve` 가 아니면 RequestDenied 응답 |
| variant | body | string | 아니오 | 아래 변종(기본 `ok`) | 응답 변종 |
| request_id | body | string | 아니오 | — | AuthnRequest ID(InResponseTo). 비면 IdP-initiated |
| audience | body | string | 아니오 | — | Audience(기본 SP Entity ID) |
| relay_state | body | string | 아니오 | — | 돌려보낼 RelayState |
| email | body | string | 아니오 | — | 이메일 속성 |
| name_id | body | string | 아니오 | 191자 | NameID. 비우면 이메일에서 만든 고정 값 |
| name | body | string | 아니오 | 100자 | 이름 속성(기본 「모의 직원」) |
| groups | body | string | 아니오 | 쉼표 구분 | 그룹 속성 |

변종: `ok`, `mfa_context`, `groups_multi`, `no_attributes`, `unsigned`, `wrong_key`, `expired`, `not_yet_valid`, `audience_other`, `destination_other`, `in_response_to_mismatch`, `unsolicited`, `replay`(마지막 정상 응답을 다시 보냄), `xsw_wrap`, `xsw_sibling`, `comment_injection`, `encrypted_assertion`, `status_responder`, `repeat_attribute`(속성 값마다 같은 이름 속성을 따로 보내고 `Role` 을 두 번 보냄 — Keycloak 기본 `role_list` 범위의 모양). 정상 응답 한 가지와 SP 가 걸러야 하는 어긋남(서명, 기간, 대상, 재사용, 서명 래핑 공격 등)을 하나씩 만든다.

**요청 예시**

```http
POST /plugins/sirsoft-sso/mock/saml/approve HTTP/1.1
Host: api.example.com
Content-Type: application/x-www-form-urlencoded

acs=https%3A%2F%2Fwww.example.com%2Fplugins%2Fsirsoft-sso%2Fsaml%2Fmock_saml_a%2Facs&request_id={REQUEST_ID}&relay_state={STATE}&variant=ok&email=hong%40example.com&decision=approve
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. SP ACS 로 `SAMLResponse`(와 `RelayState`)를 자동 제출하는 HTML 폼(200, `Cache-Control: no-store`)을 돌려준다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: text/html; charset=utf-8
Cache-Control: no-store
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `acs` 가 이 사이트의 모의 SAML IdP ACS 가 아님 |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

승인 화면(`sso`, `initiate`)의 [Approve], [Deny] 단추가 이 주소로 보낸다. 정상 응답은 10분 동안 보관해 `replay` 변종이 다시 보낼 수 있게 한다.


### GET /plugins/sirsoft-sso/mock/saml/initiate
<!-- @generated:start:web.plugins.sirsoft-sso.mock.saml.initiate -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.saml.initiate`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController@initiate`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | query | string | 예 | `mock_` 으로 시작하는 SAML IdP 키 | SP 쪽 모의 SAML IdP |
| RelayState | query | string | 아니오 | 같은 출처 경로 | 로그인 뒤 돌아갈 경로 |

**요청 예시**

```http
GET /plugins/sirsoft-sso/mock/saml/initiate?idp=mock_saml_a&RelayState=%2Fmypage HTTP/1.1
Host: api.example.com
Accept: text/html
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. IdP 가 시작한 로그인(IdP-initiated)의 승인 화면 HTML(`Cache-Control: no-store`)이다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: text/html; charset=utf-8
Cache-Control: no-store
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 모의 게이트가 꺼짐, 또는 `idp` 가 모의 SAML IdP 가 아님 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

SP 의 IdP 설정 `idp_initiated` 가 꺼져 있으면 승인 뒤 ACS 가 `saml_unsolicited` 로 거절한다. 그 거절을 확인할 때도 쓴다.


### GET /plugins/sirsoft-sso/mock/saml/metadata
<!-- @generated:start:web.plugins.sirsoft-sso.mock.saml.metadata -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.saml.metadata`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController@metadata`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| variant | query | string | 아니오 | `ok`, `cert_expiring_7d`, `cert_rotated` | `cert_expiring_7d` 는 7일 뒤 만료 인증서를, `cert_rotated` 는 교체 예정 인증서를 주 인증서와 함께 싣는다 |

**요청 예시**

```http
GET /plugins/sirsoft-sso/mock/saml/metadata?variant=cert_expiring_7d HTTP/1.1
Host: api.example.com
Accept: application/samlmetadata+xml
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. IdP 메타데이터 XML(`Content-Type: application/samlmetadata+xml; charset=utf-8`, `Cache-Control: no-store`). Entity ID `{사이트 주소}/plugins/sirsoft-sso/mock/saml`, SSO `…/sso`, SLO `…/slo`, 서명 인증서.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/samlmetadata+xml; charset=utf-8
Cache-Control: no-store
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

모의 SAML IdP 행의 IdP 메타데이터 주소다. 응답은 늘 주 키로 서명하므로 변종을 바꿔도 로그인은 이어진다. 인증서 만료 알림과 메타데이터 갱신을 시험할 때 변종을 쓴다.


### GET /plugins/sirsoft-sso/mock/saml/slo
<!-- @generated:start:web.plugins.sirsoft-sso.mock.saml.slo -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.saml.slo`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController@slo`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| SAMLRequest | query | string | 아니오 | deflate + base64 | SP 가 보낸 LogoutRequest |
| RelayState | query | string | 아니오 | — | 그대로 돌려준다 |

**요청 예시**

```http
GET /plugins/sirsoft-sso/mock/saml/slo?SAMLRequest={DEFLATED_BASE64}&RelayState=https%3A%2F%2Fwww.example.com%2Fplugins%2Fsirsoft-sso%2Flogout%2Freturn HTTP/1.1
Host: api.example.com
Accept: text/html
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. LogoutRequest 를 읽으면 그 SP(Issuer 로 찾은 모의 SAML IdP)의 SLS 로 LogoutResponse 를 실어 302. LogoutRequest 가 아니면 「signed out」 HTML(200).

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 302 Found
Location: https://www.example.com/plugins/sirsoft-sso/saml/mock_saml_a/sls?SAMLResponse=…&RelayState=…
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | LogoutRequest 의 Issuer 가 모의 SAML IdP 의 SP Entity ID 가 아님 |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

SP 가 시작한 SAML 로그아웃(`logout_mode` 가 `rp`, `both`)을 시험할 때 SP 가 이 주소로 보낸다. SLS 는 받은 답을 확인하고 `logout/return` 으로 보낸다.


### GET /plugins/sirsoft-sso/mock/saml/sso
<!-- @generated:start:web.plugins.sirsoft-sso.mock.saml.sso::get -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.saml.sso`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController@sso`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| SAMLRequest | query | string | 예 | deflate + base64(Redirect 바인딩) | SP 의 AuthnRequest |
| RelayState | query | string | 아니오 | — | 그대로 돌려줄 state |

**요청 예시**

```http
GET /plugins/sirsoft-sso/mock/saml/sso?SAMLRequest={DEFLATED_BASE64}&RelayState={STATE} HTTP/1.1
Host: api.example.com
Accept: text/html
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. 승인 화면 HTML(`Cache-Control: no-store`)이다. 화면에서 변종, 이메일, NameID, 이름, 그룹을 고르고 [Approve] 또는 [Deny] 를 누르면 `approve` 로 POST 한다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: text/html; charset=utf-8
Cache-Control: no-store
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `SAMLRequest` 가 비었거나 읽을 수 없음, AuthnRequest 가 아님, ACS 가 이 사이트의 모의 SAML IdP ACS 가 아님 |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

SP 의 AuthnRequest 바인딩이 `redirect`(기본)일 때 오는 주소다.


### POST /plugins/sirsoft-sso/mock/saml/sso
<!-- @generated:start:web.plugins.sirsoft-sso.mock.saml.sso::post -->
- **라우트명**: `web.plugins.sirsoft-sso.mock.saml.sso`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController@sso`
- **인증/권한**: 공개 (인증 불필요) — 모의 게이트가 켜진 동안만, CSRF 면제
- **요청 제한**: `throttle:120,1,sirsoft-sso.mock`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| SAMLRequest | body | string | 예 | base64(POST 바인딩, 압축 없음) | SP 의 AuthnRequest |
| RelayState | body | string | 아니오 | — | 그대로 돌려줄 state |

**요청 예시**

```http
POST /plugins/sirsoft-sso/mock/saml/sso HTTP/1.1
Host: api.example.com
Content-Type: application/x-www-form-urlencoded

SAMLRequest={BASE64_AUTHN_REQUEST}&RelayState={STATE}
```

**응답 필드** (`data` 내부)

GET 과 같다. 승인 화면 HTML 이다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: text/html; charset=utf-8
Cache-Control: no-store
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | GET 과 같은 조건 |
| 404 | Not Found | 모의 게이트가 꺼짐 |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

SP 의 AuthnRequest 바인딩이 `post` 일 때 SP 가 자동 제출 폼으로 보내는 주소다.
