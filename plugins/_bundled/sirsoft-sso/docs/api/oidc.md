# Oidc API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 웹 라우트다(JSON API 아님). IdP 콘솔에 넣는 OIDC Redirect URI(콜백)와 back-channel 로그아웃 주소다
2. 콜백은 GET(response_mode=query)과 POST 를 모두 받는다. CSRF 면제 — 위조 방어는 1회용 state 와 브라우저 묶음 쿠키 g7_sso_bind 대조
3. 콜백 결과는 언제나 302 다. 로그인이면 /login?login_handoff={코드}, 동의 대기 ?sso_pending=, 연결 확인 대기 ?sso_link=, 실패 ?sso_error={코드}
4. 재확인(reauth) 성공은 출발 화면 경로에 ?verification_token=… 을 붙여 돌려보낸다(화면 쿼리 보존)
5. back-channel 은 IdP 서버가 직접 부른다. logout_token 서명과 jti 1회 사용을 검증하고 성공은 빈 본문 200, 실패는 400 {"error":"invalid_request"}
```

---


### POST /plugins/sirsoft-sso/oidc/{idp}/backchannel-logout
<!-- @generated:start:web.plugins.sirsoft-sso.oidc.backchannel -->
- **라우트명**: `web.plugins.sirsoft-sso.oidc.backchannel`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\BackchannelController@logout`
- **인증/권한**: 공개 (인증 불필요) — `logout_token` 서명 검증, CSRF 면제
- **요청 제한**: `throttle:120,1,sirsoft-sso.oidc-backchannel`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| logout_token | body | string | 예 | JWT | IdP 가 서명한 로그아웃 토큰(`application/x-www-form-urlencoded`) |

**요청 예시**

```http
POST /plugins/sirsoft-sso/oidc/{idp}/backchannel-logout HTTP/1.1
Host: api.example.com
Content-Type: application/x-www-form-urlencoded

logout_token={LOGOUT_TOKEN_JWT}
```

**응답 필드** (`data` 내부)

이 엔드포인트는 G7 봉투를 쓰지 않는다. 성공은 빈 본문 200, 실패는 OIDC Back-Channel Logout 규격의 `{"error": "invalid_request"}` 400 이다. 둘 다 `Cache-Control: no-store`.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Cache-Control: no-store
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 본문 `{"error":"invalid_request"}` — 없는 IdP, 꺼진 IdP, IdP 의 `logout_mode` 가 `both` 가 아님, `logout_token` 이 없음, 서명이나 발급자, 대상, 이벤트 클레임 검증 실패, `nonce` 가 있음, `sid` 와 `sub` 가 모두 없음, 이미 처리한 `jti` |
| 404 | Not Found | `idp` 가 키 형식이 아닌 경우(라우트 제약) |
| 429 | Too Many Requests | 분당 120회를 넘은 경우 |

<!-- @generated:end -->

**설명**

IdP 에서 로그아웃하면 G7 도 로그아웃시키는 양방향 로그아웃(`logout_mode = both`)의 수신 주소다. IdP 콘솔의 Back-channel logout URI 에 넣는다(IdP 편집 화면 `sp_info` 의 `backchannel_logout_uri`). `sid` 가 있으면 그 IdP 세션으로, 없으면 `sub` 로 열린 SSO 세션을 고르고, 그 세션들의 G7 토큰을 폐기한다. 고른 세션이 없어도 200 이다.


### GET /plugins/sirsoft-sso/oidc/{idp}/callback
<!-- @generated:start:web.plugins.sirsoft-sso.oidc.callback::get -->
- **라우트명**: `web.plugins.sirsoft-sso.oidc.callback`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\OidcController@callback`
- **인증/권한**: 공개 (인증 불필요) — 1회용 state + 묶음 쿠키 `g7_sso_bind`, CSRF 면제
- **요청 제한**: `throttle:60,1,sirsoft-sso.oidc-callback`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| state | query | string | 예 | 128자 | 시작 때 만든 1회용 state(꺼내자마자 지운다) |
| code | query | string | 조건부 | 2048자 | 인가 코드. IdP 가 오류를 보내지 않았으면 필수 |
| error | query | string | 아니오 | 100자 | IdP 오류 코드(`access_denied` 등) |
| error_description | query | string | 아니오 | 1000자 | IdP 오류 설명(읽지 않는다) |
| g7_sso_bind | cookie | string | 예 | — | 로그인을 시작한 브라우저의 묶음 쿠키 |

값이 문자열이 아니거나 길이를 넘으면 IdP 와 주고받지 않고 `state_expired` 로 보낸다.

**요청 예시**

```http
GET /plugins/sirsoft-sso/oidc/{idp}/callback?code={CODE}&state={STATE} HTTP/1.1
Host: api.example.com
Accept: text/html
Cookie: g7_sso_bind={BIND}
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. 언제나 **302 리다이렉트**다. `{화면}` 은 사용자 흐름이면 `/login`, 관리자 흐름이면 `/admin/login` 이고, 시작 때 받은 `redirect` 가 `/` 가 아니면 `&redirect=…` 가 붙는다.

| 결과 | Location |
| --- | --- |
| 로그인(연결된 회원, 자동 연결, 자동 생성) | `{화면}?login_handoff={코드}` — 코어 인계 묶음 쿠키 `g7_login_bind` 가 같은 응답에 실린다. 화면이 코어 인계 API 로 교환한다 |
| 처음 로그인 약관 동의 대기 | `{화면}?sso_pending={48자}` — pending.md |
| 기존 회원 연결 확인 대기 | `{화면}?sso_link={48자}` — link.md |
| 마이페이지 연결(`link`) 성공 | `{출발 경로}?sso_linked={IdP 키}` |
| 재확인(`reauth`) 성공 | `{출발 경로}?verification_token={토큰}` — 출발 경로에 있던 화면 쿼리는 그대로 둔다 |
| 관리자 테스트(`test`) | `/plugins/sirsoft-sso/test-result/{40자}` — 성공과 실패 모두 |
| 로그인 실패 | `{화면}?sso_error={코드}` |
| 연결, 재확인 실패 | `{출발 경로}?sso_error={코드}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 302 Found
Location: https://www.example.com/login?login_handoff={MASKED}&redirect=%2Fmypage
Set-Cookie: g7_login_bind={MASKED}; path=/api/auth; httponly; samesite=lax
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 302 | Found (실패 이동) | `sso_error` 코드 — `state_expired`(state 가 없거나 만료, 다른 IdP 의 state, 값 형식 비정상), `bind_mismatch`(다른 브라우저), `access_denied`(사용자 취소, IdP 거절), `token_error`(IdP 오류, 코드 교환 실패, 그 밖의 예외), `id_token_invalid`(서명, 발급자, 대상, nonce, 만료, 알고리즘 검증 실패), `not_registered`, `email_in_use`, `already_linked`, `deprovisioned`, `admin_target_manual_only`, `ticket_invalid`(연결이나 재확인 대상 회원이 사라짐, 재확인 반영 실패) |
| 404 | Not Found | `idp` 가 키 형식이 아닌 경우(라우트 제약) |
| 429 | Too Many Requests | 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

IdP 콘솔의 Redirect URI 다(IdP 편집 화면 `sp_info` 의 `redirect_uri`). 성공하면 세션 ID 를 새로 만들고(세션 고정 방어), IdP 의 마지막 성공 시각을 갱신한다. 결과는 시도 기록에 남고 실패와 거절은 훅 `sirsoft-sso.auth.login_failed` 로 나간다.

재확인 흐름은 코어 본인인증(외부 수단 `sirsoft-sso`)이 시작한다. 같은 회사 계정으로 다시 로그인하면 이 콜백이 코어 본인인증 요청을 확인 처리하고 발급된 `verification_token` 을 출발 경로에 붙인다. 예: 출발 경로가 `/mypage/profile?reauth=app_key` 면 `/mypage/profile?reauth=app_key&verification_token=…`. 화면은 다른 외부 수단과 같이 `query.verification_token` 으로 원래 저장을 다시 보낸다. 토큰은 1회용이고 그 회원만 쓸 수 있다.


### POST /plugins/sirsoft-sso/oidc/{idp}/callback
<!-- @generated:start:web.plugins.sirsoft-sso.oidc.callback::post -->
- **라우트명**: `web.plugins.sirsoft-sso.oidc.callback`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\OidcController@callback`
- **인증/권한**: 공개 (인증 불필요) — 1회용 state + 묶음 쿠키 `g7_sso_bind`, CSRF 면제
- **요청 제한**: `throttle:60,1,sirsoft-sso.oidc-callback`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| state | body | string | 예 | 128자 | 1회용 state |
| code | body | string | 조건부 | 2048자 | 인가 코드 |
| error | body | string | 아니오 | 100자 | IdP 오류 코드 |
| error_description | body | string | 아니오 | 1000자 | IdP 오류 설명(읽지 않는다) |
| g7_sso_bind | cookie | string | 예 | — | 로그인을 시작한 브라우저의 묶음 쿠키 |

**요청 예시**

```http
POST /plugins/sirsoft-sso/oidc/{idp}/callback HTTP/1.1
Host: api.example.com
Content-Type: application/x-www-form-urlencoded
Cookie: g7_sso_bind={BIND}

code={CODE}&state={STATE}
```

**응답 필드** (`data` 내부)

GET 콜백과 같다. 언제나 302 리다이렉트다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 302 Found
Location: https://www.example.com/login?sso_pending={TOKEN}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 302 | Found (실패 이동) | GET 콜백과 같은 `sso_error` 코드 |
| 404 | Not Found | `idp` 가 키 형식이 아닌 경우(라우트 제약) |
| 429 | Too Many Requests | 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

이 플러그인은 인가 요청에 `response_mode=query` 를 보내므로 보통 GET 으로 돌아온다. `form_post` 로 응답하는 IdP 를 위해 POST 도 같은 처리로 받는다. 교차 사이트 POST 라 묶음 쿠키는 HTTPS 사이트(SameSite=None; Secure)에서만 실린다.
