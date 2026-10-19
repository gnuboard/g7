# 소셜 로그인 API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: 손 작성(1.0.0). 실측 값은 플러그인 시험 `CallbackFlowTest` 와 같다.

---

## TL;DR (5초 요약)

```text
1. 1.0.0 의 JSON API 는 관리자 연결 테스트 시작 1개다 — 로그인 왕복은 브라우저가 오가는 웹 주소다(아래 「웹 엔드포인트」)
2. 연결 테스트는 제공자를 켜기 전에도 돌릴 수 있고, 로그인하지 않는다 — 결과는 일회용 결과 화면으로만 본다
3. 결과 화면에는 토큰이 실리지 않고, 제공자 사용자 ID 와 이메일은 가려서 보인다
4. 콜백 주소는 요청 Host 가 아니라 사이트 주소(app.url) 기준이다 — 제공자 콘솔에 이 값을 등록한다
```

---

## 공통 사항

**URL prefix**: `/api/plugins/sirsoft-social_login/` · **라우트명 prefix**: `api.plugins.sirsoft-social_login.`

**응답 봉투**는 코어 `ResponseHelper` 규격입니다.

```json
{ "success": true,  "message": "...", "data": { } }
{ "success": false, "message": "..." }
```

| 상태 | 조건 |
| --- | --- |
| 401 | 비로그인 요청 (`auth:sanctum`) |
| 403 | `sirsoft-social_login.settings.update` 권한 없음 |
| 404 | 레지스트리에 없는 제공자 키 |
| 429 | 분당 20회 초과 |

---

## 관리자

### POST /api/plugins/sirsoft-social_login/admin/providers/{provider}/test-login

제공자 연결 테스트 왕복을 시작하고 제공자 인가 주소를 돌려줍니다. 관리자 화면은 이 주소를 새 창으로 엽니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.test-login`
- **미들웨어**: `auth:sanctum`, `permission:admin,sirsoft-social_login.settings.update`, `throttle:20,1`
- **경로 파라미터**: `provider` — 제공자 키(`^[a-z][a-z0-9_]{1,49}$`). 꺼진 제공자도 된다(켜기 전 확인용).

**응답 200**

```json
{
  "success": true,
  "message": "연결 테스트를 시작합니다. 열린 창에서 제공자 동의를 마쳐 주세요.",
  "data": { "authorize_url": "https://nid.naver.com/oauth2.0/authorize?response_type=code&client_id=…&redirect_uri=…&state=…" }
}
```

**응답 404** — 레지스트리에 없는 키. `errors.code` 는 `provider_unknown`.

---

## 웹 엔드포인트 (참고 — JSON 이 아니다)

접두 `/plugins/sirsoft-social_login`, 라우트명 접두 `web.plugins.sirsoft-social_login.`.

| 경로 | 동작 |
| --- | --- |
| `GET oauth/{provider}/start?redirect=/경로` | 로그인 왕복 시작. 켜진 제공자면 인가 화면으로 302, 아니면 `/login?social_error={코드}` (`provider_disabled`, `provider_unknown`). 이미 로그인한 세션이면 복귀 주소로. `throttle:30,1` |
| `GET\|POST oauth/{provider}/callback` | 제공자 콜백. state 는 꺼내자마자 지운다(재사용 시 `state_invalid`). 관리자 테스트는 결과 화면으로, 로그인 의도는 1.0.0 에서 `/login?social_error=flow_not_available` 로 돌아간다. CSRF 면제, `throttle:60,1` |
| `GET test-result/{code}` | 연결 테스트 결과(일회용, `Cache-Control: no-store`). 두 번째 요청은 404 |
| `GET\|POST mock/authorize` · `GET\|POST mock/token` · `GET mock/userinfo` · `GET mock/.well-known/openid-configuration` · `GET mock/jwks` | 모의 제공자. `php artisan sirsoft-social_login:mock --enable` 로 켰을 때만 응답하고, 꺼져 있으면 404 |

로그인 실패 코드는 제공자 오류 코드(`access_denied`, `KOE320`, `024`, `facebook_190` 등) 또는 플러그인 코드(`state_invalid`, `code_missing`, `token_exchange_failed`, `profile_fetch_failed`, `profile_missing_id`, `id_token_*`, `discovery_*`, `jwks_fetch_failed`, 제공자 오류가 아닌 예외를 감싼 `unexpected_error`)다. `unexpected_error` 의 원인은 서버 로그에만 남고 시도 기록에는 예외 종류만 적힌다.
