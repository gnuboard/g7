# Oauth API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Oauth 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /plugins/sirsoft-social_login/oauth/{provider}/callback
<!-- @generated:start:web.plugins.sirsoft-social_login.oauth.callback::get -->
- **라우트명**: `web.plugins.sirsoft-social_login.oauth.callback`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\OAuthController@callback`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | path | string | 예 | — | 제공자 키(`^[a-z][a-z0-9_]{1,49}$`). 형식이 맞지 않으면 라우트가 맞지 않아 404 |
| state | query | string | 아니오 | max 128 | 시작할 때 만든 1회용 state. 서버가 보관한 값과 대조하고 꺼내자마자 지운다(유효 시간 설정 `state_ttl_minutes`, 기본 10분) |
| code | query | string | 아니오 | max 4096 | 제공자가 발급한 인가 코드. 토큰 주소에서 토큰으로 바꾼다. 비어 있으면 `code_missing` 실패 |
| error | query | string | 아니오 | max 100 | 제공자가 보낸 오류 코드(회원이 동의를 거부하면 `access_denied` 등). 값이 있으면 코드 교환 없이 그 코드로 실패 처리한다 |

**요청 예시**

```http
GET /plugins/sirsoft-social_login/oauth/{provider}/callback?state=%EC%98%88%EC%8B%9C%EA%B0%92&code=%EC%98%88%EC%8B%9C%EA%B0%92&error=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_JSON 응답이 아니다. 언제나 302 이동이며 본문 필드가 없다. 이동할 곳은 시작한 의도(로그인, 연결, 재확인, 관리자 테스트)와 결과로 정해진다._

**응답 예시**

로그인, 연결된 회원(코어 로그인 인계):

```http
HTTP/1.1 302 Found
Location: https://example.com/login?login_handoff=…&redirect=%2Fmypage
```

로그인, 연결 없는 제공자 계정(가입 모달):

```http
HTTP/1.1 302 Found
Location: https://example.com/register?social_pending=…&social_step=complete
```

state 가 없거나 이미 썼을 때:

```http
HTTP/1.1 302 Found
Location: https://example.com/login?social_error=state_invalid
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | — | 이 경로는 422 를 돌려주지 않는다. 값 길이가 규칙을 넘으면 제공자와 주고받지 않고 `/login?social_error=state_invalid` 로 302 이동한다 |
| 404 | Not Found | 제공자 키가 형식(`^[a-z][a-z0-9_]{1,49}$`)에 맞지 않아 라우트가 맞지 않는다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:60,1,sirsoft-social_login.oauth-callback`) |

<!-- @generated:end -->

**설명**

제공자 인가 화면이 브라우저를 돌려보내는 콜백 주소다(GET, 쿼리 문자열). 제공자 콘솔에 등록하는 콜백 주소는 이 주소와 글자 하나까지 같아야 한다. 제공자는 CSRF 토큰을 보내지 않으므로 CSRF 검사에서 빠지고, 위조 방어는 1회용 state 가 맡는다.

- state 가 없거나 이미 썼으면 `/login?social_error=state_invalid`. 다른 제공자의 콜백 주소로 돌아온 state 도 같은 실패로 처리한다(연결과 재확인은 시작한 화면으로).
- 성공하면 의도에 따라 갈린다. 로그인은 연결된 회원이면 코어 로그인 인계 주소(`/login?login_handoff=…`), 연결이 없으면 가입 모달(`/register?social_pending=…&social_step=complete|existing`) 또는 정책에 따른 `/login?social_error=…`(`signup_disabled`, `registration_closed`, `email_required`, `admin_blocked` 등). 마이페이지 연결은 `/mypage/profile?social=linked&provider={키}`, 재확인은 돌아갈 주소에 `social_reauth={수령 코드}`, 관리자 연결 테스트는 일회용 결과 화면(`/plugins/sirsoft-social_login/test-result/{코드}`).
- 실패하면 제공자 오류 코드(`access_denied` 등)나 플러그인 코드(`code_missing`, `token_exchange_failed`, `profile_fetch_failed`, `profile_missing_id`, `unexpected_error` 등)를 `social_error` 로 싣고, 의도에 맞는 화면(로그인은 로그인 화면, 연결과 재확인은 시작한 화면)으로 보낸다. 시도는 모두 시도 기록에 남는다.


### POST /plugins/sirsoft-social_login/oauth/{provider}/callback
<!-- @generated:start:web.plugins.sirsoft-social_login.oauth.callback::post -->
- **라우트명**: `web.plugins.sirsoft-social_login.oauth.callback`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\OAuthController@callback`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | path | string | 예 | — | 제공자 키(`^[a-z][a-z0-9_]{1,49}$`). 형식이 맞지 않으면 라우트가 맞지 않아 404 |
| state | body | string | 아니오 | max 128 | 시작할 때 만든 1회용 state. 서버가 보관한 값과 대조하고 꺼내자마자 지운다(유효 시간 설정 `state_ttl_minutes`, 기본 10분) |
| code | body | string | 아니오 | max 4096 | 제공자가 발급한 인가 코드. 토큰 주소에서 토큰으로 바꾼다. 비어 있으면 `code_missing` 실패 |
| error | body | string | 아니오 | max 100 | 제공자가 보낸 오류 코드(회원이 동의를 거부하면 `access_denied` 등). 값이 있으면 코드 교환 없이 그 코드로 실패 처리한다 |

**요청 예시**

```http
POST /plugins/sirsoft-social_login/oauth/{provider}/callback HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "state": "예시값",
    "code": "예시값",
    "error": "예시값"
}
```

**응답 필드** (`data` 내부)

_JSON 응답이 아니다. 언제나 302 이동이며 본문 필드가 없다. 이동할 곳은 시작한 의도(로그인, 연결, 재확인, 관리자 테스트)와 결과로 정해진다._

**응답 예시**

로그인, 연결된 회원(코어 로그인 인계):

```http
HTTP/1.1 302 Found
Location: https://example.com/login?login_handoff=…&redirect=%2Fmypage
```

로그인, 연결 없는 제공자 계정(가입 모달):

```http
HTTP/1.1 302 Found
Location: https://example.com/register?social_pending=…&social_step=complete
```

state 가 없거나 이미 썼을 때:

```http
HTTP/1.1 302 Found
Location: https://example.com/login?social_error=state_invalid
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | — | 이 경로는 422 를 돌려주지 않는다. 값 길이가 규칙을 넘으면 제공자와 주고받지 않고 `/login?social_error=state_invalid` 로 302 이동한다 |
| 404 | Not Found | 제공자 키가 형식(`^[a-z][a-z0-9_]{1,49}$`)에 맞지 않아 라우트가 맞지 않는다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:60,1,sirsoft-social_login.oauth-callback`) |

<!-- @generated:end -->

**설명**

제공자 인가 화면이 브라우저를 돌려보내는 콜백 주소다(POST, `form_post` 응답 방식 제공자 대비. 받는 값과 처리는 GET 과 같다). 제공자 콘솔에 등록하는 콜백 주소는 이 주소와 글자 하나까지 같아야 한다. 제공자는 CSRF 토큰을 보내지 않으므로 CSRF 검사에서 빠지고, 위조 방어는 1회용 state 가 맡는다.

- state 가 없거나 이미 썼으면 `/login?social_error=state_invalid`. 다른 제공자의 콜백 주소로 돌아온 state 도 같은 실패로 처리한다(연결과 재확인은 시작한 화면으로).
- 성공하면 의도에 따라 갈린다. 로그인은 연결된 회원이면 코어 로그인 인계 주소(`/login?login_handoff=…`), 연결이 없으면 가입 모달(`/register?social_pending=…&social_step=complete|existing`) 또는 정책에 따른 `/login?social_error=…`(`signup_disabled`, `registration_closed`, `email_required`, `admin_blocked` 등). 마이페이지 연결은 `/mypage/profile?social=linked&provider={키}`, 재확인은 돌아갈 주소에 `social_reauth={수령 코드}`, 관리자 연결 테스트는 일회용 결과 화면(`/plugins/sirsoft-social_login/test-result/{코드}`).
- 실패하면 제공자 오류 코드(`access_denied` 등)나 플러그인 코드(`code_missing`, `token_exchange_failed`, `profile_fetch_failed`, `profile_missing_id`, `unexpected_error` 등)를 `social_error` 로 싣고, 의도에 맞는 화면(로그인은 로그인 화면, 연결과 재확인은 시작한 화면)으로 보낸다. 시도는 모두 시도 기록에 남는다.


### GET /plugins/sirsoft-social_login/oauth/{provider}/start
<!-- @generated:start:web.plugins.sirsoft-social_login.oauth.start -->
- **라우트명**: `web.plugins.sirsoft-social_login.oauth.start`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\OAuthController@start`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | path | string | 예 | — | 제공자 키(`^[a-z][a-z0-9_]{1,49}$`). 형식이 맞지 않으면 라우트가 맞지 않아 404 |
| redirect | query | string | 아니오 | max 2000 | 로그인 뒤 돌아갈 같은 출처 경로. `/` 로 시작하지 않거나 `//`, 역슬래시, 스킴, 제어문자가 든 값과 2000자를 넘는 값은 `/` 로 바뀐다 |
| reconsent | query | string | 아니오 | `1` | `1` 이면 제공자에 동의 화면을 다시 띄워 달라고 요청한다(로그인 화면의 [이메일 제공 다시 동의하기] 단추). 다른 값은 무시한다 |

**요청 예시**

```http
GET /plugins/sirsoft-social_login/oauth/{provider}/start?redirect=%EC%98%88%EC%8B%9C%EA%B0%92&reconsent=1 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_JSON 응답이 아니다. 언제나 302 이동이며 본문 필드가 없다._

**응답 예시**

켜진 제공자(제공자 인가 화면으로 이동):

```http
HTTP/1.1 302 Found
Location: https://kauth.kakao.com/oauth/authorize?response_type=code&client_id=…&redirect_uri=…&state=…
```

꺼졌거나 없는 제공자:

```http
HTTP/1.1 302 Found
Location: https://example.com/login?social_error=provider_disabled
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | — | 이 경로는 422 를 돌려주지 않는다. 검증에 맞지 않는 `redirect` 는 버리고(복귀 주소 `/`) 왕복을 계속한다 |
| 404 | Not Found | 제공자 키가 형식(`^[a-z][a-z0-9_]{1,49}$`)에 맞지 않아 라우트가 맞지 않는다. 형식은 맞지만 없거나 꺼진 제공자는 `/login?social_error=provider_unknown` 또는 `provider_disabled` 로 302 이동한다 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:30,1,sirsoft-social_login.oauth-start`) |

<!-- @generated:end -->

**설명**

로그인 화면과 가입 화면의 소셜 버튼이 여는 주소다(공개 제공자 목록의 `start_url`). 1회용 state 를 만들어 서버에 보관하고, 제공자 인가 화면으로 302 이동한다.

- 이미 로그인한 세션이면 제공자로 보내지 않고 복귀 주소(`redirect`)로 보낸다.
- 없거나 꺼진 제공자는 `/login?social_error={코드}` 로 보낸다. 복귀 주소가 `/` 가 아니면 `redirect` 쿼리가 함께 붙는다.
- 마이페이지 연결과 소셜 재확인은 이 주소가 아니라 각각 `POST /api/plugins/sirsoft-social_login/me/accounts/{provider}/link-start`, `POST /api/plugins/sirsoft-social_login/me/reauth/{provider}/start` 로 시작한다.

