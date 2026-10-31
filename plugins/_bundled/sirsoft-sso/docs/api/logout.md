# Logout API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 웹 라우트다(JSON API 아님). IdP 로그아웃으로 보내는 주소와 IdP 에서 돌아오는 주소 둘이다
2. 코어 로그아웃 응답의 redirect_url 이 /plugins/sirsoft-sso/logout/{idp}?ticket={40자} 이면 화면이 그리로 간다(IdP 의 logout_mode 가 rp, both 일 때)
3. 주소에 세션 ID 를 싣지 않는다. 1회용 티켓(60초)만 받고, 없거나 쓴 티켓이면 로그인 화면으로 보낸다
4. IdP 콘솔의 Post logout redirect URI 는 /plugins/sirsoft-sso/logout/return 이다
```

---


### GET /plugins/sirsoft-sso/logout/return
<!-- @generated:start:web.plugins.sirsoft-sso.logout.return -->
- **라우트명**: `web.plugins.sirsoft-sso.logout.return`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\LogoutController@return`
- **인증/권한**: 공개 (인증 불필요)
- **요청 제한**: `throttle:60,1,sirsoft-sso.logout-return`

**요청 파라미터**

_요청 파라미터 없음._ IdP 가 붙여 보내는 쿼리(`state` 등)는 읽지 않는다.

**요청 예시**

```http
GET /plugins/sirsoft-sso/logout/return HTTP/1.1
Host: api.example.com
Accept: text/html
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. 브라우저를 로그인 화면으로 **302 리다이렉트**한다. 로그아웃을 시작할 때 세션에 남긴 화면 구분이 관리자면 `/admin/login`, 아니면 `/login` 이다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 302 Found
Location: https://www.example.com/login
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 429 | Too Many Requests | 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

IdP 로그아웃을 마친 브라우저가 돌아오는 주소다. OIDC 는 `post_logout_redirect_uri` 로 이 주소를 보내고, SAML 로그아웃 응답(SLS)도 RelayState 가 이 경로면 여기로 보낸다. 로그아웃은 이미 끝난 상태라 이 주소는 이동만 한다. 라우트 순서상 `logout/{idp}` 보다 먼저 맞춰지므로 `return` 은 IdP 키로 쓸 수 없다.


### GET /plugins/sirsoft-sso/logout/{idp}
<!-- @generated:start:web.plugins.sirsoft-sso.logout -->
- **라우트명**: `web.plugins.sirsoft-sso.logout`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\LogoutController@start`
- **인증/권한**: 공개 (인증 불필요) — 1회용 로그아웃 티켓
- **요청 제한**: `throttle:30,1,sirsoft-sso.logout-start`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키(티켓의 IdP 와 같아야 한다) |
| ticket | query | string | 예 | 영숫자 40자 | 코어 로그아웃 때 발급한 1회용 티켓(60초) |

**요청 예시**

```http
GET /plugins/sirsoft-sso/logout/{idp}?ticket={TICKET} HTTP/1.1
Host: api.example.com
Accept: text/html
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. 브라우저를 IdP 로그아웃 주소로 **302 리다이렉트**한다.

| 경우 | 응답 |
| --- | --- |
| OIDC | 302 → discovery 의 `end_session_endpoint?client_id=…&post_logout_redirect_uri={사이트 주소}/plugins/sirsoft-sso/logout/return` |
| SAML | 302 → IdP 단일 로그아웃 주소(Redirect 바인딩 LogoutRequest, NameID 와 SessionIndex, RelayState 는 `logout/return` 주소) |
| 티켓이 없거나 썼거나 IdP 가 다르거나, IdP 가 로그아웃 주소를 주지 않음 | 302 → `/login`(관리자 세션이면 `/admin/login`) |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 302 Found
Location: https://idp.example.com/logout?client_id=…&post_logout_redirect_uri=https%3A%2F%2Fwww.example.com%2Fplugins%2Fsirsoft-sso%2Flogout%2Freturn
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 302 | Found (실패 이동) | 티켓이 맞지 않거나 IdP 로그아웃 주소를 만들지 못한 경우 — 오류 코드 없이 로그인 화면으로 |
| 404 | Not Found | `idp` 가 키 형식이 아닌 경우(라우트 제약) |
| 429 | Too Many Requests | 분당 30회를 넘은 경우 |

<!-- @generated:end -->

**설명**

G7 로그아웃(토큰 폐기)은 코어 로그아웃 API 가 먼저 끝낸다. 코어는 필터 `core.auth.logout_redirect_url` 로 이 플러그인에 갈 곳을 묻고, 이 플러그인은 지금 토큰이 SSO 로 받은 세션이고, IdP 의 `logout_mode` 가 `rp` 또는 `both` 이고, 드라이버가 IdP 로그아웃을 지원하면(LDAP 은 아니다) 1회용 티켓(세션 ID, IdP ID, 화면 구분)을 만들어 이 주소를 돌려준다. 브라우저는 그 주소로 와서 IdP 로그아웃을 마치고 `logout/return` 으로 돌아온다. IdP 로그아웃 주소를 만들지 못하면 조용히 로그인 화면으로 보낸다(G7 로그아웃은 이미 끝났다).
