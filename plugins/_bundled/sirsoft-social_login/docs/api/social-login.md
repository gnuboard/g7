# 소셜 로그인 API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: 손 작성(1.0.0). 실측 값은 플러그인 시험(`CallbackFlowTest`, `SignupFlowTest`, `LinkExistingTest`, `AccountLinkServiceTest`, `SocialReauthenticationTest`, `PublicProvidersTest`, `ProviderControllerTest`, `ProviderTestServiceTest`, `DiagnosticsControllerTest`, `StatsControllerTest`, `UserAccountsControllerTest`, 웹훅 시험 4종)과 같다.

---

## TL;DR (5초 요약)

```text
1. 로그인 왕복과 제공자 웹훅은 웹 주소다(아래 「웹 엔드포인트」). JSON API 는 그 앞뒤 화면(버튼 목록, 가입 모달, 마이페이지 연결 관리, 관리자 설정 진단 통계, 관리자 회원 탭)이 부른다
6. 관리자 응답은 제공자 비밀값을 마스크(`••••••••`)로만 싣는다 — 마스크를 그대로 돌려보내면 유지, `null`(빈 문자열)은 삭제
2. 가입 모달 API 는 게스트가 부른다 — 콜백이 발급한 48자 가입 대기 토큰(pending_token)이 곧 권한이고, 유효 시간이 지나면 404 `expired`
3. 로그인 토큰은 이 플러그인이 만들지 않는다 — 가입·연결이 끝나면 코어 로그인 인계 주소(handoff_url)를 돌려주고, 화면이 그 주소로 가서 코어가 로그인시킨다
4. 실패 응답은 `errors.code` 에 플러그인 코드를 싣는다(아래 「실패 코드」). 화면은 이 코드로 분기한다
5. 요청 제한은 라우트마다 따로 센다(throttle 세 번째 인자 = 버킷 이름) — 화면마다 부르는 제공자 목록이 인증번호 요청 한도를 깎지 않는다
```

---

## 공통 사항

**URL prefix**: `/api/plugins/sirsoft-social_login/` · **라우트명 prefix**: `api.plugins.sirsoft-social_login.`

**응답 봉투**는 코어 `ResponseHelper` 규격입니다.

```json
{ "success": true,  "message": "...", "data": { } }
{ "success": false, "message": "...", "errors": { "code": "expired" } }
```

| 상태 | 조건 |
| --- | --- |
| 401 | 비로그인 요청 (`auth:sanctum` 라우트) |
| 403 | 권한 없음, 관리자 계정 소셜 로그인 차단(`admin_blocked`), 재확인 대상 아님(`reauth_not_available`) |
| 404 | 가입 대기 토큰 만료(`expired`), 꺼진 제공자(`provider_disabled`), 연결 없음(`not_linked`), 수령 코드 무효(`reauth_claim_invalid`), 실패 횟수 초과(`too_many_attempts`) |
| 409 | 다른 회원에 연결된 계정(`already_linked_other_user`), 이미 연결된 제공자(`provider_already_linked`), 마지막 로그인 수단(`last_method`) 등 |
| 422 | 입력 검증 실패(`errors` 는 필드별 메시지), 흐름 실패(`invalid_code`, `email_taken` 등) |
| 428 | 코어 본인인증 정책 `core.auth.signup_before_submit` 이 켜졌는데 확인 토큰이 없음(가입 완료만) |
| 503 | 이메일 인증번호를 보내지 못함(`mail_failed` — 사이트 메일 설정 문제, 시도 기록에 남는다) |
| 429 | 라우트별 요청 제한 초과 |

### 실패 코드

| 코드 | 상태 | 뜻 |
| --- | --- | --- |
| `expired` | 404 | 가입 대기 토큰이 없거나 만료됐다 — 처음부터 다시 로그인한다 |
| `provider_disabled` | 404 | 제공자가 꺼졌거나 없다 |
| `admin_blocked` | 403 | 관리자 계정은 소셜 로그인이 꺼져 있다(`allow_admin_social_login`) |
| `email_taken` | 422 | 입력한 이메일을 다른 회원이 쓴다 |
| `email_locked` | 422 | 제공자가 확인한 이메일로 고정된 가입에서 다른 이메일을 보냈다 |
| `email_not_verified` | 422 | 이메일 인증번호 확인 전에 가입을 완료하려 했다 |
| `challenge_mismatch` | 422 | 이 가입(또는 이 기존 계정)에 발급한 인증 요청이 아니다 |
| `invalid_code` | 422 | 인증번호가 틀렸다 |
| `name_required` | 422 | 제공자가 이름을 주지 않았는데 입력도 비었다 |
| `existing_account_pending` | 409 | 같은 이메일의 기존 계정이 있다 — 연결 단계를 거친다 |
| `not_existing_step` | 409 | 기존 계정 연결 단계가 아닌데 연결 API 를 불렀다 |
| `password_mismatch` | 422 | 기존 계정 비밀번호가 틀렸다 |
| `password_not_available` | 422 | 비밀번호가 없는 기존 계정에 비밀번호 방법을 썼다 |
| `too_many_attempts` | 404 | 기존 계정 연결 확인을 5번 틀렸다 — 가입 대기 토큰이 폐기된다 |
| `already_linked_other_user` | 409 | 이 제공자 계정은 다른 회원에 연결돼 있다 |
| `provider_already_linked` | 409 | 이 회원은 이 제공자에 이미 다른 계정을 연결했다 |
| `not_linked` | 404 | 해제하거나 재확인하려는 제공자가 이 회원에 연결돼 있지 않다 |
| `last_method` | 409 | 비밀번호가 없고 연결이 하나뿐이라 해제하면 로그인할 수 없다(설정 `allow_unlink_last_method` 로 허용) |
| `reauth_not_available` | 403 | 소셜 재확인 대상이 아니다(비밀번호가 있거나, 메일을 받을 수 있거나, 연결이 없음) |
| `reauth_claim_invalid` | 404 | 재확인 수령 코드가 없거나, 이미 썼거나, 다른 회원의 것이다 |
| `mail_failed` | 503 | 인증번호 메일 발송이 실패했다(가입 이메일·기존 계정 이메일 모두) — 「관리자에게 문의」 안내 |
| `provider_unknown` | 404 | 관리자 API 에서 제공자 표에 없는 키(확장 등록 제공자 포함) |
| `credentials_required` | 422 | 클라이언트 ID 와 비밀값 없이 제공자를 켜려 했다 |
| `key_taken` | 422 | 일반 OIDC 추가 키가 예약 키이거나 이미 있다 |
| `custom_limit_reached` | 422 | 일반 OIDC 제공자 수가 설정 `custom_provider_limit` 에 닿았다 |
| `builtin_not_deletable` | 422 | 내장 제공자는 지울 수 없다(끄기만) |
| `provider_has_links` | 409 | 연결된 회원이 남은 제공자는 지울 수 없다 |
| `discovery_failed` · `discovery_incomplete` | 422 | discovery 문서를 받지 못했거나 필수 항목이 없다 |

제공자·드라이버가 정한 코드(일반 OIDC 의 `discovery_failed` 등)는 `errors.code` 에 그대로 담고, `message` 는 「제공자에 연결하지 못했습니다」 공통 문구입니다.

---

## 공개

### GET /api/plugins/sirsoft-social_login/providers

로그인·가입 화면 버튼용으로 켜진 제공자를 표시 순서대로 돌려줍니다. 자격증명(client id·secret)은 싣지 않습니다. 모의 제공자는 CLI 로 켰을 때만 원 제공자 모양으로 덧붙습니다.

- **라우트명**: `api.plugins.sirsoft-social_login.providers.index`
- **미들웨어**: `throttle:120,1,sirsoft-social_login.providers`

**응답 200**

```json
{
  "success": true,
  "message": "...",
  "data": [
    { "key": "kakao", "label": "카카오", "brand": "kakao", "style": null, "start_url": "/plugins/sirsoft-social_login/oauth/kakao/start" }
  ]
}
```

켜진 제공자가 없으면 `data` 는 빈 배열이고, 화면은 버튼 영역을 그리지 않습니다.

---

## 가입 모달 (게스트)

모든 요청은 `pending_token`(48자 영숫자, 콜백이 `/register?social_pending=…&social_step=…` 으로 넘긴 값)을 받습니다. GET 은 쿼리, POST 는 본문입니다.

### GET /api/plugins/sirsoft-social_login/signup/pending

가입 모달이 그릴 내용을 돌려줍니다.

- **라우트명**: `api.plugins.sirsoft-social_login.signup.pending`
- **미들웨어**: `throttle:60,1,sirsoft-social_login.signup-pending`

**응답 200**

```json
{
  "success": true,
  "message": "...",
  "data": {
    "provider": { "key": "kakao", "label": "카카오", "brand": "kakao" },
    "step": "complete",
    "name": "홍길동",
    "nickname": "길동",
    "email": { "mode": "input", "value": null, "verified": false, "requires_code": true, "challenge_id": null },
    "missing": ["email"],
    "show_profile_fields": false,
    "existing_account": null,
    "expires_at": "2026-10-20T12:30:00+09:00"
  }
}
```

- `step`: `complete`(새 계정 가입) 또는 `existing`(같은 이메일의 기존 계정 — `existing_account` 에 `email_masked`, `has_password`).
- `email.mode`: `locked`(제공자가 확인한 이메일 고정), `input`(입력하고 인증번호 확인), `placeholder`(메일을 받을 수 없는 대체 주소 `{제공자}_{해시}@social.invalid`).

### POST /api/plugins/sirsoft-social_login/signup/email/request

입력 모드에서 이메일 인증번호를 보냅니다(코어 본인인증 `signup` 목적, 메일 수단).

- **라우트명**: `api.plugins.sirsoft-social_login.signup.email.request`
- **미들웨어**: `throttle:10,1,sirsoft-social_login.signup-email-request`
- **본문**: `pending_token`, `email`

**응답 200** — `data: { "challenge_id": "…", "expires_at": "…" }`. 실패는 `email_taken`(422), 메일 발송 실패 `mail_failed`(503) 등.

### POST /api/plugins/sirsoft-social_login/signup/email/verify

- **라우트명**: `api.plugins.sirsoft-social_login.signup.email.verify`
- **미들웨어**: `throttle:10,1,sirsoft-social_login.signup-email-verify`
- **본문**: `pending_token`, `challenge_id`, `code`

**응답 200** — `data: { "verified_email": "…" }`. 실패는 `challenge_mismatch`, `invalid_code`(422).

### POST /api/plugins/sirsoft-social_login/signup/complete

비밀번호 없이 가입하고 제공자 계정을 연결합니다. 회원 생성은 코어 `AuthService::register(…, ['issue_token' => false])` 로 하므로 코어 가입 규칙(약관 동의, 가입 허용, 이메일 인증 정책)을 그대로 따릅니다.

- **라우트명**: `api.plugins.sirsoft-social_login.signup.complete`
- **미들웨어**: `identity.policy:core.auth.signup_before_submit`, `throttle:20,1,sirsoft-social_login.signup-complete`
- **본문**: `pending_token`, `name`?, `nickname`?, `email`?(입력 모드에서 확인한 값), `agree_terms`(accepted), `agree_privacy`(accepted), `verification_token`?(본인인증 정책이 켜졌을 때)

**응답 200**

```json
{ "success": true, "message": "...", "data": { "handoff_url": "/login?login_handoff=…&redirect=%2F" } }
```

코어 가입 이메일 인증이 켜진 사이트는 `data.redirect_url` 이 `/login?social_notice=pending_verification` 이고 로그인하지 않습니다.

### POST /api/plugins/sirsoft-social_login/signup/link-existing/request

같은 이메일의 기존 계정에 연결합니다. 비밀번호 방법은 바로 연결하고, 이메일 방법은 기존 계정 주소로 인증번호를 보냅니다(코어 본인인증 `sensitive_action` 목적).

- **라우트명**: `api.plugins.sirsoft-social_login.signup.link-existing.request`
- **미들웨어**: `throttle:10,1,sirsoft-social_login.link-existing-request`
- **본문**: `pending_token`, `method`(`password`|`email`), `password`(method=password 일 때)

**응답 200** — 비밀번호: `data: { "handoff_url": "…" }` · 이메일: `data: { "challenge_id": "…", "expires_at": "…" }`. 비밀번호가 틀리면 422 `password_mismatch`, 비밀번호와 인증번호를 합쳐 5번 틀리면 404 `too_many_attempts`, 기존 계정 주소로 메일을 보내지 못하면 503 `mail_failed`.

### POST /api/plugins/sirsoft-social_login/signup/link-existing/verify

- **라우트명**: `api.plugins.sirsoft-social_login.signup.link-existing.verify`
- **미들웨어**: `throttle:10,1,sirsoft-social_login.link-existing-verify`
- **본문**: `pending_token`, `challenge_id`, `code`

**응답 200** — `data: { "handoff_url": "…" }`.

### POST /api/plugins/sirsoft-social_login/signup/discard-existing

기존 계정에 연결하지 않고 새 계정으로 가입하는 단계로 바꿉니다. 응답은 `signup/pending` 과 같은 모양이며 `step` 은 `complete` 입니다.

- **라우트명**: `api.plugins.sirsoft-social_login.signup.discard-existing`
- **미들웨어**: `throttle:10,1,sirsoft-social_login.discard-existing`
- **본문**: `pending_token`

---

## 마이페이지 연결 관리 (로그인 회원 본인)

공통 미들웨어: `auth:sanctum`, `check.user_status`, `throttle:60,1,sirsoft-social_login.me`. 경로의 `provider` 는 `^[a-z][a-z0-9_]{1,49}$`.

### GET /api/plugins/sirsoft-social_login/me/accounts

- **라우트명**: `api.plugins.sirsoft-social_login.me.accounts.index`

**응답 200**

```json
{
  "success": true,
  "message": "...",
  "data": {
    "accounts": [
      { "provider_key": "kakao", "label": "카카오", "brand": "kakao", "email": "ho***@example.com", "linked_at": "…", "last_login_at": "…" }
    ],
    "available": [ { "provider_key": "naver", "label": "네이버", "brand": "naver", "style": null } ],
    "has_password": false,
    "can_unlink": { "kakao": { "allowed": false, "reason": "last_method" } },
    "reauth_via_social": true
  }
}
```

`style` 은 커스텀(일반 OIDC) 제공자의 버튼 색 `{ "bg", "fg", "border" }`(16진 색만, 없으면 `null`)입니다 — 내장 제공자의 모양은 `brand` 가 정합니다. `reauth_via_social` 은 비밀번호도 없고 메일도 받을 수 없는 회원(대체 이메일)이 연결된 제공자가 하나 이상 있어 그 제공자로 본인 재확인을 해야 할 때 `true` 입니다.

### POST /api/plugins/sirsoft-social_login/me/accounts/{provider}/link-start

연결 왕복을 시작하고 제공자 인가 주소를 돌려줍니다. 화면은 이 주소로 이동하고, 콜백은 연결을 만든 뒤 `/mypage/profile?social=linked` 로 돌아옵니다(실패는 `?social_error={코드}&provider={키}`).

- **라우트명**: `api.plugins.sirsoft-social_login.me.accounts.link-start`

**응답 200** — `data: { "authorize_url": "https://…" }`. 이미 연결된 제공자면 409 `provider_already_linked`, 꺼졌거나 없는 제공자면 404 `provider_disabled`, 제공자 설정을 읽지 못하면(일반 OIDC discovery 장애 등) 422 — `errors.code` 에 제공자 코드, `message` 는 공통 문구.

### DELETE /api/plugins/sirsoft-social_login/me/accounts/{provider}

연결을 해제합니다. 제공자가 권한 회수를 지원하면 시도하고, 회수에 실패해도 해제는 진행합니다.

- **라우트명**: `api.plugins.sirsoft-social_login.me.accounts.destroy`

**응답 200** — `data: { "provider_key": "kakao" }`. 실패는 404 `not_linked`, 409 `last_method`.

### POST /api/plugins/sirsoft-social_login/me/reauth/{provider}/start

대체 이메일 회원의 본인 재확인을 시작합니다. 코어 본인인증 수단(`sirsoft-social_login`, `sensitive_action` 목적)으로 인증 요청을 만들고, 연결된 제공자로 다시 로그인하게 합니다.

- **라우트명**: `api.plugins.sirsoft-social_login.me.reauth.start`
- **본문**: `return`?(돌아올 같은 출처 경로, 기본 `/`)

**응답 200** — `data: { "authorize_url": "https://…" }`. 대상이 아니면 403 `reauth_not_available`, 그 제공자가 이 회원에 연결돼 있지 않으면 404 `not_linked`, 꺼졌거나 없는 제공자면 404 `provider_disabled`, 제공자 설정을 읽지 못하면 422(`errors.code` 에 제공자 코드, `message` 는 공통 문구).

콜백은 같은 제공자 계정이면 `{return}?social_reauth={수령 코드 40자}` 로, 다른 계정이면 `?social_error=reauth_mismatch` 로 돌아옵니다. 확인 토큰은 주소에 싣지 않습니다.

### POST /api/plugins/sirsoft-social_login/me/reauth/claim

수령 코드를 확인 토큰으로 바꿉니다. 코드는 한 번만, 그 회원만 쓸 수 있습니다. 화면은 받은 토큰을 코어 `POST /api/me/verify-password` 의 `verification_token` 으로 넘겨 정보 수정·비밀번호 첫 설정 관문을 통과합니다.

- **라우트명**: `api.plugins.sirsoft-social_login.me.reauth.claim`
- **본문**: `code`(40자 영숫자)

**응답 200** — `data: { "verification_token": "…" }`. 실패는 404 `reauth_claim_invalid`.

---

## 관리자

공통 미들웨어: `auth:sanctum`, `admin`, 그리고 라우트별 권한. 조회는 `sirsoft-social_login.settings.view`, 변경과 연결 테스트는 `sirsoft-social_login.settings.update`, 회원 연결 열람은 `sirsoft-social_login.accounts.view`, 강제 해제는 `sirsoft-social_login.accounts.unlink`. 경로의 제공자 키는 `^[a-z][a-z0-9_]{1,49}$`.

### GET /api/plugins/sirsoft-social_login/admin/providers

설정 화면의 제공자 카드. 비밀값(`client_secret`, `admin_key`)은 값이 있으면 마스크 `••••••••`, 없으면 `null` 입니다. 확장이 필터로 더한 제공자는 `read_only: true` 행으로 덧붙습니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.index` (`settings.view`)

**응답 200**

```json
{
  "success": true,
  "message": "...",
  "data": {
    "providers": [
      {
        "key": "kakao", "driver": "kakao", "type": "builtin", "read_only": false, "enabled": false, "sort_order": 10,
        "label": { "ko": "카카오", "en": "Kakao" }, "display_label": "카카오", "brand": "kakao",
        "client_id": "…", "client_secret": "••••••••", "admin_key": null, "credentials_unreadable": false,
        "scopes": ["profile_nickname", "account_email"], "discovery_url": null, "authorize_url": null, "token_url": null,
        "userinfo_url": null, "jwks_url": null, "issuer": null, "graph_api_version": null, "button_style": null,
        "signup_allowed": true, "email_missing_policy": "ask", "email_trust": "flag", "webhook_ip_allowlist_enabled": true, "webhook_app_id": null,
        "credential_schema": [], "supports": { "pkce": true, "oidc": false, "refresh": true, "revoke": true, "webhook": true, "callback_methods": ["GET"] },
        "notes": ["sirsoft-social_login.admin.providers.notes.kakao.client_secret_default_on"],
        "redirect_uri": "https://example.test/plugins/sirsoft-social_login/oauth/kakao/callback",
        "webhook_urls": { "unlink": "https://example.test/plugins/sirsoft-social_login/webhooks/kakao" },
        "last_success_at": null, "last_error_at": null, "last_error_code": null
      }
    ],
    "limits": { "custom_count": 0, "custom_limit": 10 },
    "abilities": { "can_update": true }
  }
}
```

- `credentials_unreadable`: 앱 키가 바뀌어 저장된 비밀값을 풀 수 없다. 화면은 다시 입력하라고 안내한다.
- `webhook_urls`: 카카오 `unlink`, 네이버 `unlink`, 페이스북 `deauthorize` `data_deletion`. 일반 OIDC 는 빈 객체.
- `abilities.can_update`: 이 관리자가 `settings.update` 를 가졌는지. 화면은 이 값으로 편집 단추를 끈다.

### PUT /api/plugins/sirsoft-social_login/admin/providers/{key}

제공자 1건을 고칩니다. 보낸 칸만 바뀝니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.update` (`settings.update`)
- **본문**: `enabled`, `label`(로케일별, 100자), `client_id`, `client_secret`, `admin_key`, `scopes`(배열, 20개) 또는 `scopes_text`(공백·쉼표로 나눈 글자, 보내면 `scopes` 보다 우선), `signup_allowed`, `email_missing_policy`(`ask`|`deny`|`placeholder`), `email_trust`(`flag`|`trust`|`verify`), `discovery_url` `authorize_url` `token_url` `userinfo_url` `jwks_url`(https), `issuer`, `graph_api_version`(`v26.0` 꼴), `button_style`(`bg` `fg` `border`, 16진 색), `webhook_ip_allowlist_enabled`, `webhook_app_id`(카카오만, 숫자 20자 이하 — 넣으면 다른 앱의 연결 끊기 알림을 무시, 빈 값이면 대조하지 않음)
- **비밀값 규칙**: 칸을 보내지 않거나 마스크를 그대로 보내면 유지, `null` 또는 빈 문자열이면 삭제, 그 밖의 값이면 교체.

**응답 200** — `data` 는 목록의 제공자 1건과 같은 모양. 자격증명 없이 `enabled: true` 면 422 `credentials_required`, 없는 키는 404 `provider_unknown`. 확장 등록 제공자(테이블에 없는 키)도 404 입니다.

### POST /api/plugins/sirsoft-social_login/admin/providers

일반 OIDC 제공자를 꺼진 상태로 추가합니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.store` (`settings.update`)
- **본문**: 수정 본문에 더해 `key`(필수, 예약 키 kakao naver google facebook oidc 와 `mock_` 접두 불가), `label`(필수, 기본 로케일 포함), `client_id` `client_secret`(필수), `discovery_url` 또는 `authorize_url` `token_url` `jwks_url` `issuer` 넷

**응답 201** — 추가한 제공자. 키가 겹치면 422 `key_taken`, 설정 `custom_provider_limit` 에 닿으면 422 `custom_limit_reached`.

### DELETE /api/plugins/sirsoft-social_login/admin/providers/{key}

일반 OIDC 제공자를 지웁니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.destroy` (`settings.update`)

**응답 200** — `data: { "key": "corp_account" }`. 내장 제공자는 422 `builtin_not_deletable`, 연결된 회원이 남아 있으면 409 `provider_has_links`.

### POST /api/plugins/sirsoft-social_login/admin/providers/reorder

- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.reorder` (`settings.update`)
- **본문**: `keys`(키 배열, 1~50개, 중복 불가) — 이 순서대로 `sort_order` 를 다시 매긴다

**응답 200** — `data: { "keys": ["kakao", "google", "naver", "facebook"] }`.

### POST /api/plugins/sirsoft-social_login/admin/providers/discover

discovery 문서를 캐시 없이 읽어 주소 칸 값을 돌려줍니다. 저장하지 않습니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.discover` (`settings.update`, `throttle:20,1,sirsoft-social_login.discover`)
- **본문**: `discovery_url`(https, 500자)

**응답 200** — `data: { "issuer", "authorize_url", "token_url", "userinfo_url", "jwks_url" }`. 문서를 받지 못하면 422 `discovery_failed`, 필수 항목이 없으면 422 `discovery_incomplete`.

### GET /api/plugins/sirsoft-social_login/admin/providers/{key}/impact

제공자를 끄거나 자격증명을 바꾸기 전에 볼 영향 수치입니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.impact` (`settings.view`)

**응답 200** — `data: { "social_only_users": 1, "linked_users": 2 }`. `social_only_users` 는 비밀번호가 없고 이 제공자 연결만 있는 회원(끄면 로그인할 수 없다).

### POST /api/plugins/sirsoft-social_login/admin/providers/{provider}/test

회원 동의 없이 자격증명과 콜백 주소를 확인합니다. 없는 인가 코드로 토큰 주소를 불러 제공자의 거절 사유를 나눕니다. 일반 OIDC 는 서명 키 목록(JWKS)을 먼저 받아 봅니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.test` (`settings.update`, `throttle:20,1,sirsoft-social_login.test`)

**응답 200**

```json
{ "success": true, "message": "...", "data": { "result": "invalid_client", "ok": false, "provider_code": "KOE101",
  "steps": [ { "name": "credentials", "ok": true, "code": null, "fields": [] }, { "name": "token", "ok": false, "code": "KOE101" } ] } }
```

`result`: `credentials_ok`(제공자가 클라이언트를 인정하고 코드만 거절), `invalid_client`, `redirect_mismatch`, `unreachable`(연결 실패 또는 5xx), `credentials_missing`, `credentials_unreadable`(저장된 비밀값을 풀 수 없음 — 앱 키 교체 뒤 다시 입력), `discovery_failed`, `discovery_incomplete`, `jwks_unreachable`, `unexpected`.

### POST /api/plugins/sirsoft-social_login/admin/providers/{provider}/test-login

제공자 연결 테스트 왕복을 시작하고 제공자 인가 주소를 돌려줍니다. 관리자 화면은 이 주소를 새 창으로 엽니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.test-login`
- **미들웨어**: `auth:sanctum`, `permission:admin,sirsoft-social_login.settings.update`, `throttle:20,1,sirsoft-social_login.test-login`
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

### GET /api/plugins/sirsoft-social_login/admin/diagnostics

진단 화면 카드 4개와 제공자 표.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.diagnostics.index` (`settings.view`)

**응답 200**

```json
{
  "success": true,
  "message": "...",
  "data": {
    "providers": [ { "key": "kakao", "label": "카카오", "driver": "kakao", "type": "builtin", "enabled": true, "configured": true,
      "credentials_unreadable": false, "last_success_at": null, "last_error_at": null, "last_error_code": null, "last_webhook_at": null } ],
    "queue": { "connection": "database", "pending": 0, "failed_jobs": 0 },
    "mail": { "mailer": "smtp", "delivering": true, "recent_code_failures": 1 },
    "mock": { "enabled": false, "expires_at": null },
    "members": { "linked_users": 2, "passwordless_users": 1 },
    "webhooks": { "days": 7, "received": 1, "ignored": 1, "failed": 0, "trusted_proxy_configured": false }
  }
}
```

- `queue.failed_jobs`: 실패한 웹훅 처리 작업 수(`ProcessWebhookEventJob`).
- `mail.delivering`: 메일러가 실제로 보내는 종류인지(`log` `array` 는 false). `recent_code_failures` 는 최근 7일 인증번호 메일 발송 실패(`mail_failed`) 시도 수.
- `webhooks.trusted_proxy_configured`: 카카오 허용 IP 대조가 실제 클라이언트 IP 를 보려면 신뢰 프록시 설정이 필요하다.

### GET /api/plugins/sirsoft-social_login/admin/attempts

로그인·가입·연결·테스트 시도 기록을 최신순으로 돌려줍니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.attempts.index` (`settings.view`)
- **쿼리**: `provider`, `outcome`, `intent`, `error_code`, `page`, `per_page`(1~100)

**응답 200** — `data: { "data": [ { "id", "provider_key", "user_id", "intent", "outcome", "error_code", "error_message", "ip", "created_at" } ], "pagination": { "current_page", "last_page", "per_page", "total" } }`.

### GET /api/plugins/sirsoft-social_login/admin/webhook-events

제공자 알림(웹훅) 수신 이력을 최신순으로 돌려줍니다. 제공자 사용자 ID 는 앞 6자만 싣습니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.webhook-events.index` (`settings.view`)
- **쿼리**: `provider`, `status`(`received`|`processed`|`ignored`|`failed`), `event_type`, `page`, `per_page`

**응답 200** — `data: { "data": [ { "id", "provider_key", "event_type", "provider_user_id_prefix", "payload", "status", "attempts", "error", "received_at", "processed_at" } ], "pagination": { … } }`. 검증에 실패한 수신은 `status: ignored`, `error` 에 사유(`webhook_auth_invalid` 등).

### GET /api/plugins/sirsoft-social_login/admin/stats

- **라우트명**: `api.plugins.sirsoft-social_login.admin.stats.index` (`settings.view`)
- **쿼리**: `days`(7|30|90, 기본 30)

**응답 200** — `data: { "days": 30, "since": "…", "rows": [ { "provider_key", "label", "signups", "logins", "links", "failures", "linked_accounts", "social_only_users" } ], "totals": { … } }`. 시도 수는 기간 안의 것이고 관리자 연결 테스트는 세지 않는다. `logins` 는 로그인 인계와 로그인, `failures` 는 실패와 거부를 합친 값. `linked_accounts` `social_only_users` 는 지금 시점의 수.

### GET /api/plugins/sirsoft-social_login/admin/linked-providers

관리자 회원 목록 「가입경로(소셜)」 필터의 선택지. 꺼진 제공자도 연결된 회원이 있으면 나옵니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.linked-providers.index` (`accounts.view`)

**응답 200** — `data: [ { "key": "naver", "label": "네이버", "count": 1 } ]`.

### GET /api/plugins/sirsoft-social_login/admin/users/{uuid}/accounts

관리자 회원 상세 「소셜 로그인」 탭.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.users.accounts.index` (`accounts.view`)

**응답 200** — `data: { "accounts": [ { "provider_key", "label", "brand", "provider_user_id_prefix", "email", "email_verified", "linked_at", "last_login_at" } ], "has_password": false, "abilities": { "can_unlink": true } }`. `provider_user_id_prefix` 는 앞 6자. 없는 회원은 404.

### DELETE /api/plugins/sirsoft-social_login/admin/users/{uuid}/accounts/{key}

연결을 강제로 해제합니다. 마지막 로그인 수단 가드를 보지 않습니다(관리자 판단). 설정 `revoke_on_unlink` 가 켜져 있으면 제공자 권한 회수를 시도하고, 훅 `account.unlinked_by_admin` 으로 회원에게 사이트 알림이 가고 활동 로그에 남습니다.

- **라우트명**: `api.plugins.sirsoft-social_login.admin.users.accounts.destroy` (`accounts.unlink`)

**응답 200** — `data: { "provider_key": "kakao" }`. 연결이 없으면 404 `not_linked`.

---

## 웹 엔드포인트 (참고 — JSON 이 아니다)

접두 `/plugins/sirsoft-social_login`, 라우트명 접두 `web.plugins.sirsoft-social_login.`.

| 경로 | 동작 |
| --- | --- |
| `GET oauth/{provider}/start?redirect=/경로` | 로그인 왕복 시작. 켜진 제공자면 인가 화면으로 302, 아니면 `/login?social_error={코드}&provider={키}` (`provider_disabled`, `provider_unknown`). 이미 로그인한 세션이면 복귀 주소로. `throttle:30,1,sirsoft-social_login.oauth-start` |
| `GET\|POST oauth/{provider}/callback` | 제공자 콜백. state 는 꺼내자마자 지운다(재사용 시 `state_invalid`). 시작한 의도에 따라 갈린다 — 관리자 테스트는 결과 화면, 로그인은 연결된 회원이면 코어 로그인 인계 주소(`/login?login_handoff=…`)·없으면 가입 모달(`/register?social_pending=…`), 연결은 마이페이지, 재확인은 돌아갈 주소. CSRF 면제, `throttle:60,1,sirsoft-social_login.oauth-callback` |
| `GET test-result/{code}` | 연결 테스트 결과(일회용, `Cache-Control: no-store`). 두 번째 요청은 404 |
| `GET\|POST mock/authorize` · `GET\|POST mock/token` · `GET mock/userinfo` · `GET mock/.well-known/openid-configuration` · `GET mock/jwks` | 모의 제공자. `php artisan sirsoft-social_login:mock --enable` 로 켰을 때만 응답하고, 꺼져 있으면 404. `throttle:120,1,sirsoft-social_login.mock` |
| `GET\|POST webhooks/kakao` | 카카오 연결 끊기 알림. `Authorization: KakaoAK {어드민 키}` 대조, 허용 IP 대역(제공자 설정 `webhook_ip_allowlist_enabled`, 기본 켜짐), 앱 ID. 언제나 빈 본문 200 |
| `POST webhooks/naver` | 네이버 연동 해제 알림. `clientId` 대조, `signature`(HMAC-SHA256) 검증, 암호화된 고유 ID 복호화. 언제나 204 |
| `POST webhooks/facebook/deauthorize` | 페이스북 앱 권한 해제. `signed_request` 서명 검증. 200 `{ "success": true }`, 검증 실패 400 `{ "error": "…" }` |
| `POST webhooks/facebook/data-deletion` | 페이스북 데이터 삭제 요청. 200 `{ "url": "…/data-deletion/{확인 코드}", "confirmation_code": "…" }`(제공자 규약이라 응답 봉투를 쓰지 않는다), 검증 실패 400 |
| `GET data-deletion/{code}` | 데이터 삭제 진행 상태 HTML(확인 코드 20자). 없는 코드는 404 |

웹훅 라우트는 CSRF 면제, `throttle:120,1`. 받은 알림은 `event_key` 로 중복을 걸러 기록하고(검증 실패는 `ignored`), 연결 해제와 정책별 탈퇴(`webhook_unlink_policy`)는 큐 작업(`ProcessWebhookEventJob`)이 커밋 뒤에 처리합니다. 웹훅 해제는 제공자 권한 회수를 하지 않습니다(제공자가 이미 끊었다).

로그인 실패 코드는 제공자 오류 코드(`access_denied`, `KOE320`, `024`, `facebook_190` 등) 또는 플러그인 코드(`state_invalid`, `code_missing`, `token_exchange_failed`, `profile_fetch_failed`, `profile_missing_id`, `id_token_*`, `discovery_*`, `jwks_fetch_failed`, `credentials_unreadable`(앱 키가 바뀌어 저장된 비밀값을 풀 수 없음), `admin_blocked`, 제공자 오류가 아닌 예외를 감싼 `unexpected_error`)다. `unexpected_error` 의 원인은 서버 로그에만 남고 시도 기록에는 예외 종류만 적힌다.
