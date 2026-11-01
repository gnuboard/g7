# Login API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 웹 라우트다(JSON API 아님). 브라우저가 이 주소로 오면 IdP 로그인 화면으로 보낸다(302, SAML POST 바인딩은 자동 제출 폼)
2. 로그인(intent=login)만 쿼리로 시작할 수 있다. 연결, 재확인, 관리자 테스트는 회원을 아는 API 가 발급한 1회용 ticket 만 받는다
3. 이 응답이 브라우저 묶음 쿠키 g7_sso_bind 를 굽는다. 콜백은 같은 브라우저에서 돌아와야 한다
4. 실패는 로그인 화면 /login?sso_error={코드}(관리자는 /admin/login), 연결이나 재확인은 출발 화면 ?sso_error={코드} 로 보낸다
```

---


### GET /plugins/sirsoft-sso/login/{idp}
<!-- @generated:start:web.plugins.sirsoft-sso.login -->
- **라우트명**: `web.plugins.sirsoft-sso.login`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\LoginController@start`
- **인증/권한**: 공개 (인증 불필요) — 로그인 외 의도는 1회용 티켓
- **요청 제한**: `throttle:30,1,sirsoft-sso.login-start`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| intent | query | string | 아니오 | max 10, 쿼리로는 `login` | 쿼리로는 `login` 만 받는다. 다른 값을 티켓 없이 보내면 `ticket_required` |
| guard | query | string | 아니오 | `user`, `admin` | 로그인 화면 구분(기본 `user`) |
| redirect | query | string | 아니오 | 최대 2000자 | 로그인 뒤 돌아갈 같은 출처 경로. 사이트 주소로 시작하는 절대 주소는 경로로 바꾸고, 외부 주소나 `//` 로 시작하거나 `\` 가 든 주소는 `/` 로 본다 |
| ticket | query | string | 아니오 | max 64 (발급값은 영숫자 48자) | 1회용 시작 티켓(60초). `POST me/links/start`(연결), `POST admin/idps/{id}/test` 의 `browser: true`(관리자 테스트), 코어 본인인증 요청(재확인)이 발급한다 |

형식이 틀린 값(검증 실패)은 422 로 답하지 않고 그 값만 버린 채 진행한다.

**요청 예시**

```http
GET /plugins/sirsoft-sso/login/{idp}?guard=user&redirect=%2Fmypage HTTP/1.1
Host: api.example.com
Accept: text/html
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. 브라우저를 IdP 인가 주소로 **302 리다이렉트**한다. SAML 의 AuthnRequest 바인딩이 `post` 면 IdP SSO 주소로 자동 제출하는 HTML 폼(200)을 돌려준다. 응답에 묶음 쿠키 `Set-Cookie: g7_sso_bind=…`(HttpOnly, 30분, HTTPS 사이트는 SameSite=None; Secure, HTTP 개발 사이트는 Lax)가 실린다.

| 의도 | 성공 | 실패 |
| --- | --- | --- |
| 로그인(`login`) | 302 → IdP 인가 주소(OIDC `authorize`, SAML SSO) | 302 → `/login?sso_error={코드}&redirect=…`(관리자 `guard=admin` 은 `/admin/login?…`) |
| 연결(`link`), 재확인(`reauth`) | 302 → IdP | 302 → 출발 화면 경로 `?sso_error={코드}` |
| 관리자 테스트(`test`) | 302 → IdP(꺼진 IdP 도 시작한다) | 302 → `/plugins/sirsoft-sso/test-result/{40자}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 302 Found
Location: https://idp.example.com/authorize?response_type=code&client_id=…&redirect_uri=https%3A%2F%2Fwww.example.com%2Fplugins%2Fsirsoft-sso%2Foidc%2F…%2Fcallback&scope=…&state=…&nonce=…&response_mode=query&code_challenge=…&code_challenge_method=S256
Set-Cookie: g7_sso_bind={MASKED}; path=/; httponly; samesite=lax
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 302 | Found (실패 이동) | `sso_error` 코드 — `ticket_invalid`(티켓이 없거나 썼거나 다른 브라우저 것이거나 IdP 가 다름), `ticket_required`(로그인 외 의도를 티켓 없이), `idp_disabled`(꺼졌거나 없는 IdP, 모의 게이트가 꺼진 모의 IdP), `driver_unavailable`(드라이버를 쓸 수 없거나 리디렉션 로그인이 없는 LDAP), `token_error`(OIDC discovery 실패), `saml_invalid`(SAML 요청을 만들지 못함) |
| 404 | Not Found | `idp` 가 키 형식이 아닌 경우(라우트 제약) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 429 | Too Many Requests | 분당 30회를 넘은 경우 |

<!-- @generated:end -->

**설명**

브라우저 이동에는 Bearer 토큰이 실리지 않아 이 주소는 회원을 모른다. 그래서 로그인 외 의도는 회원을 아는 API 가 회원 ID, 의도, 돌아갈 경로를 담아 발급한 1회용 티켓으로만 시작한다. 티켓도 발급한 브라우저의 `g7_sso_bind` 쿠키와 대조한다.

OIDC 인가 주소는 discovery 의 `authorization_endpoint` 에 `response_type=code`, `scope`, `state`, `nonce`, `response_mode=query`, PKCE(`use_pkce` 가 켜지면 `code_challenge`, S256)를 붙인 것이고, 재확인은 `prompt=login`, `max_age=0` 을 더해 IdP 가 다시 비밀번호를 받게 한다. state 는 정책 `state_ttl_minutes`(기본 10분) 동안 살아 있고 한 번만 쓴다. IdP 에서 돌아오는 처리는 oidc.md(OIDC 콜백), saml.md(SAML ACS)에 있다. 시작 실패는 시도 기록에 남고 IdP 의 마지막 오류(`last_error_code`)에 반영된다.

로그인 화면 버튼은 공개 IdP 목록(`GET /api/plugins/sirsoft-sso/idps`)의 `start_url` 로 이 주소에 온다. 사이트 전체 강제가 켜졌고 정책 `auto_redirect_idp` 가 있으면 로그인 화면이 이 주소로 바로 보낸다.
