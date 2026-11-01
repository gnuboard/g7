# Providers API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Providers 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-social_login/admin/providers
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.providers.index -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.index`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/admin/providers HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| providers | array | `[{"key":"kakao","driver":"kakao","type":"builtin","read_o…` | 설정 화면의 제공자 목록. 제공자 표의 행(내장 `builtin`, 일반 OIDC `custom`)을 표시 순서대로 싣고, 다른 확장이 등록한 제공자는 `type: "extension"`, `read_only: true` 로 뒤에 붙인다(키 일부만). 항목 모양은 아래 「제공자 항목」 참조 |
| limits | object | `{"custom_count":0,"custom_limit":10}` | 일반 OIDC 제공자 수(`custom_count`)와 추가 상한(`custom_limit`, 플러그인 설정 `custom_provider_limit`, 기본 10) |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "소셜 로그인 제공자 설정입니다.",
    "data": {
        "providers": [
            {
                "key": "kakao",
                "driver": "kakao",
                "type": "builtin",
                "read_only": false,
                "enabled": true,
                "...": "(30개 키 생략, 총 35개)"
            },
            {
                "key": "naver",
                "driver": "naver",
                "type": "builtin",
                "read_only": false,
                "enabled": false,
                "...": "(30개 키 생략, 총 35개)"
            },
            {
                "key": "google",
                "driver": "google",
                "type": "builtin",
                "read_only": false,
                "enabled": false,
                "...": "(30개 키 생략, 총 35개)"
            },
            {
                "key": "facebook",
                "driver": "facebook",
                "type": "builtin",
                "read_only": false,
                "enabled": false,
                "...": "(30개 키 생략, 총 35개)"
            }
        ],
        "limits": {
            "custom_count": 0,
            "custom_limit": 10
        },
        "abilities": {
            "can_update": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.view`)이 없는 경우 |

<!-- @generated:end -->

**설명**

관리자 소셜 로그인 설정 화면이 제공자 카드를 그리는 조회다. 제공자 표의 행과 다른 확장이 등록한 제공자를 함께 돌려준다.

비밀값(`client_secret`, `admin_key`)은 값 대신 저장 여부만 마스크로 알린다. 마스크 판정은 암호문이 있는지만 보고 복호화하지 않으므로, 앱 키가 바뀌어 복호화가 안 되는 사이트에서도 목록이 열리고 `credentials_unreadable` 로 재입력이 필요함을 알린다.

`abilities.can_update` 는 호출자에게 `sirsoft-social_login.settings.update` 권한이 있는지다. 화면은 이 값으로 저장, 추가, 테스트 버튼을 잠근다.

**제공자 항목** (`providers[]`, 추가와 수정 응답의 `data` 와 같은 모양)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| key | string | 제공자 키. 콜백 주소와 시작 주소 경로에 쓰인다 |
| driver | string | 드라이버 이름(`kakao`, `naver`, `google`, `facebook`, `oidc`) |
| type | string | `builtin`(내장), `custom`(일반 OIDC), `extension`(다른 확장이 등록, 읽기 전용) |
| read_only | boolean | 확장 등록 제공자면 true. 이 화면에서 바꿀 수 없다 |
| enabled | boolean | 로그인 화면에 노출하는지 |
| sort_order | integer\|null | 표시 순서(확장 등록 제공자는 null) |
| label | object | 저장된 언어별 표시 이름(`{ko, en, …}`). 비어 있으면 드라이버 기본 이름을 쓴다 |
| display_label | string | 지금 언어로 고른 표시 이름 |
| brand | string | 버튼 모양 계열. 내장 4종은 드라이버 이름, 그 밖은 `custom` |
| client_id | string\|null | 클라이언트 ID |
| client_secret | string\|null | 저장돼 있으면 마스크 `••••••••`, 없으면 null. 값은 싣지 않는다 |
| admin_key | string\|null | 카카오 어드민 키. 규칙은 `client_secret` 과 같다 |
| credentials_unreadable | boolean | 저장된 비밀값을 지금 앱 키로 풀 수 없으면 true(앱 키가 바뀐 사이트). 비밀값을 다시 입력하면 풀린다 |
| scopes | array | 저장된 요청 범위(비어 있으면 드라이버 기본) |
| discovery_url, authorize_url, token_url, userinfo_url, jwks_url, issuer | string\|null | 일반 OIDC 주소 칸과 발급자 |
| graph_api_version | string\|null | 페이스북 Graph API 버전 |
| button_style | object\|null | 일반 OIDC 버튼 색 `{bg, fg, border}` |
| signup_allowed | boolean | 새 회원 가입 허용 |
| email_missing_policy | string | `ask`, `deny`, `placeholder` |
| email_trust | string | `flag`, `trust`, `verify` |
| webhook_ip_allowlist_enabled | boolean | 웹훅 발신 IP 확인 켬/끔(기본 true) |
| webhook_app_id | string\|null | 카카오 웹훅 앱 ID. 넣으면 다른 앱의 알림을 거른다 |
| credential_schema | array | 설정 화면이 그릴 자격증명 칸 정의(`key`, `type`, `required`, `label`) |
| supports | object | 드라이버 기능(`pkce`, `oidc`, `refresh`, `revoke`, `webhook`, `callback_methods`) |
| notes | array | 콜백 주소 등록 주의사항 다국어 키(`sirsoft-social_login.admin.providers.notes.{driver}.{note}`). 화면이 번역해 그린다 |
| redirect_uri | string | 제공자 콘솔에 등록할 콜백 주소. 사이트 주소(`APP_URL`) 기준 절대 주소다 |
| webhook_urls | object | 내장 제공자 중 웹훅을 받는 것(카카오, 네이버, 페이스북)의 웹훅 종류별 절대 주소. 그 밖은 빈 값 |
| last_success_at, last_error_at | string\|null | 마지막 성공, 오류 시각(보는 사람 시간대 `YYYY-MM-DD HH:mm:ss`) |
| last_error_code | string\|null | 마지막 오류 코드 |


### POST /api/plugins/sirsoft-social_login/admin/providers
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.providers.store -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.store`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| enabled | body | boolean | 아니오 | — | 사용 여부 |
| label | body | array | 예 | — | 표시용 라벨 |
| client_id | body | string | 예 | max 255 | client 식별자 |
| client_secret | body | string | 예 | max 2000 | 제공자 콘솔에서 받은 클라이언트 비밀값. 암호화해 저장하고 응답에는 마스크(`••••••••`)로만 싣는다. 마스크를 그대로 보내거나 키를 빼면 유지, 빈 문자열이면 삭제 |
| admin_key | body | string | 아니오 | max 2000 | 카카오 어드민 키. 카카오 쪽 연결 끊기와 「연결 끊기」 웹훅의 `Authorization: KakaoAK …` 대조에 쓴다. 카카오가 아닌 제공자에는 저장하지 않는다. 마스크 규칙은 `client_secret` 과 같다 |
| scopes | body | array | 아니오 | max 20 | 인가 요청에 실을 범위 목록(항목마다 공백 없는 문자열, 100자 이하). 비우면 드라이버 기본 범위를 쓴다. 설정 화면은 한 줄 문자열 `scopes_text` 로 보내고 서버가 공백과 쉼표로 나눠 이 배열로 바꾼다 |
| signup_allowed | body | boolean | 아니오 | — | 이 제공자로 새 회원 가입을 받을지. 끄면 연결이 없는 제공자 계정의 로그인은 가입 대신 로그인 화면 오류(`signup_disabled`)로 돌아간다 |
| email_missing_policy | body | string | 아니오 | — | 제공자가 이메일을 주지 않을 때의 가입 정책. `ask`(가입 화면에서 입력받는다), `deny`(가입을 막는다), `placeholder`(받을 수 없는 대체 주소로 가입시킨다) |
| email_trust | body | string | 아니오 | — | 제공자가 준 이메일을 얼마나 믿는지. `flag`(제공자의 검증 표시를 따른다), `trust`(검증된 것으로 본다), `verify`(늘 메일 인증번호로 다시 확인한다) |
| discovery_url | body | string | 아니오 | max 500 | 일반 OIDC 의 discovery 문서 주소(https). 있으면 인가, 토큰, 서명 키 주소와 발급자를 이 문서에서 읽는다. 내장 제공자에 보내면 저장하지 않는다 |
| authorize_url | body | string | 아니오 | max 500 | 일반 OIDC 인가 주소(https). 추가 요청에서는 `discovery_url` 이 없을 때 필수. 내장 제공자에 보내면 저장하지 않는다 |
| token_url | body | string | 아니오 | max 500 | 일반 OIDC 토큰 주소(https). 추가 요청에서는 `discovery_url` 이 없을 때 필수. 내장 제공자에 보내면 저장하지 않는다 |
| userinfo_url | body | string | 아니오 | max 500 | 일반 OIDC 사용자 정보 주소(https, 선택). 내장 제공자에 보내면 저장하지 않는다 |
| jwks_url | body | string | 아니오 | max 500 | 일반 OIDC ID 토큰 서명 키 목록(JWKS) 주소(https). 추가 요청에서는 `discovery_url` 이 없을 때 필수. 내장 제공자에 보내면 저장하지 않는다 |
| issuer | body | string | 아니오 | max 255 | 일반 OIDC 발급자 값. ID 토큰 검증의 기대 발급자로 쓴다. 추가 요청에서는 `discovery_url` 이 없을 때 필수. 내장 제공자에 보내면 저장하지 않는다 |
| graph_api_version | body | string | 아니오 | — | 페이스북 Graph API 버전(`v{주}.{부}` 형식, 예 `v26.0`). 페이스북이 아닌 제공자에 보내면 저장하지 않는다. 비우면 드라이버 기본 버전 |
| button_style | body | array | 아니오 | — | 일반 OIDC 로그인 버튼 색 `{bg, fg, border}`. 내장 제공자에 보내면 저장하지 않는다(브랜드 모양 고정) |
| button_style.bg | body | string | 아니오 | — | 버튼 배경색. `#` 뒤 16진수 3~8자(`/^#[0-9A-Fa-f]{3,8}$/`) |
| button_style.fg | body | string | 아니오 | — | 버튼 글자색. 형식은 `button_style.bg` 와 같다 |
| button_style.border | body | string | 아니오 | — | 버튼 테두리색. 형식은 `button_style.bg` 와 같다 |
| webhook_ip_allowlist_enabled | body | boolean | 아니오 | — | 웹훅 발신 IP 확인 켬/끔(저장 안 했으면 켜짐). 지금은 카카오 「연결 끊기」 웹훅 검증만 이 값을 보며, 켜져 있으면 카카오 발신 대역에서 온 알림만 받는다 |
| webhook_app_id | body | string | 아니오 | — | webhook app 식별자 |
| key | body | string | 예 | — | 새 일반 OIDC 제공자 키. 영소문자로 시작하는 영소문자, 숫자, 밑줄 2~50자(`/^[a-z][a-z0-9_]{1,49}$/`). 내장 제공자 이름, `mock_` 로 시작하는 키, 이미 있는 키는 422 `key_taken` |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/admin/providers HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "enabled": true,
    "label": [
        "예시값"
    ],
    "client_id": "예시값",
    "client_secret": "예시값",
    "admin_key": "예시값",
    "scopes": [
        "예시값"
    ],
    "signup_allowed": true,
    "email_missing_policy": "user@example.com",
    "email_trust": "user@example.com",
    "discovery_url": "https://example.com",
    "authorize_url": "https://example.com",
    "token_url": "https://example.com",
    "userinfo_url": "https://example.com",
    "jwks_url": "https://example.com",
    "issuer": "예시값",
    "graph_api_version": "예시값",
    "button_style": [
        "예시값"
    ],
    "button_style.bg": "예시값",
    "button_style.fg": "예시값",
    "button_style.border": "예시값",
    "webhook_ip_allowlist_enabled": true,
    "webhook_app_id": "예시값",
    "key": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._ 만든 제공자 항목 1건이다. 항목 모양은 목록 조회(`GET /admin/providers`)의 「제공자 항목」과 같다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| key | string | `acme` | 만든 제공자 키 |
| driver | string | `oidc` | 늘 `oidc` |
| type | string | `custom` | 늘 `custom` |
| enabled | boolean | `false` | 늘 꺼진 상태로 만든다(요청의 `enabled` 는 쓰지 않는다) |
| sort_order | integer | `5` | 지금 가장 큰 표시 순서 + 1 |
| client_secret | string | `••••••••` | 저장 여부 마스크 |
| signup_allowed | boolean | `true` | 보내지 않으면 true |
| email_missing_policy | string | `ask` | 보내지 않으면 `ask` |
| email_trust | string | `flag` | 보내지 않으면 `flag` |
| redirect_uri | string | `https://example.com/plugins/sirsoft-social_login/oauth/acme/callback` | 제공자 콘솔에 등록할 콜백 주소 |
| webhook_urls | array | `[]` | 일반 OIDC 는 웹훅 주소가 없다 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "일반 OIDC 제공자를 추가했습니다. 연결 테스트를 마친 뒤 켜 주세요.",
    "data": {
        "key": "acme",
        "driver": "oidc",
        "type": "custom",
        "read_only": false,
        "enabled": false,
        "sort_order": 5,
        "label": { "ko": "에이크미" },
        "display_label": "에이크미",
        "brand": "custom",
        "client_id": "acme-client",
        "client_secret": "••••••••",
        "admin_key": null,
        "credentials_unreadable": false,
        "scopes": [],
        "discovery_url": "https://id.example.com/.well-known/openid-configuration",
        "authorize_url": null,
        "token_url": null,
        "userinfo_url": null,
        "jwks_url": null,
        "issuer": null,
        "graph_api_version": null,
        "button_style": null,
        "signup_allowed": true,
        "email_missing_policy": "ask",
        "email_trust": "flag",
        "webhook_ip_allowlist_enabled": true,
        "webhook_app_id": null,
        "credential_schema": [
            { "key": "client_id", "type": "text", "required": true, "label": { "ko": "클라이언트 ID", "en": "Client ID" } },
            { "key": "client_secret", "type": "secret", "required": true, "label": { "ko": "클라이언트 비밀값", "en": "Client Secret" } },
            { "key": "discovery_url", "type": "url", "required": false, "label": { "ko": "Discovery 주소", "en": "Discovery URL" } }
        ],
        "supports": { "oidc": true, "revoke": false, "pkce": true, "refresh": true, "webhook": false, "callback_methods": ["GET"] },
        "notes": [
            "sirsoft-social_login.admin.providers.notes.oidc.redirect_exact",
            "sirsoft-social_login.admin.providers.notes.oidc.discovery_https"
        ],
        "redirect_uri": "https://example.com/plugins/sirsoft-social_login/oauth/acme/callback",
        "webhook_urls": [],
        "last_success_at": null,
        "last_error_at": null,
        "last_error_code": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | `key_taken` | 예약 키(내장 제공자 이름, `mock_` 접두, 키 규칙 위반)이거나 이미 있는 키(확장 등록 제공자 포함)인 경우 |
| 422 | `custom_limit_reached` | 일반 OIDC 제공자 수가 플러그인 설정 `custom_provider_limit`(기본 10)에 닿은 경우 |

<!-- @generated:end -->

**설명**

일반 OIDC 제공자를 추가한다. 키, 사이트 기본 언어 표시 이름(`label`), 클라이언트 ID, 비밀값이 필수이고, 주소는 `discovery_url` 하나 또는 `authorize_url`, `token_url`, `jwks_url`, `issuer` 넷을 모두 직접 넣는다.

새 제공자는 늘 꺼진 상태로 만들어진다. 연결 테스트로 자격증명과 콜백 주소를 확인한 뒤 수정 API 로 켠다. 응답은 201 이다.

한도 확인과 추가는 한 트랜잭션에서 하고, 추가 직후 훅 `sirsoft-social_login.provider.after_create`(인자: 비밀값 없는 요약 `{key, driver, type, enabled, label}`)는 커밋 뒤에 발행한다.


### POST /api/plugins/sirsoft-social_login/admin/providers/discover
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.providers.discover -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.discover`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderController@discover`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| discovery_url | body | string | 예 | max 500 | 읽을 discovery 문서 주소(https 만). 저장하지 않고 주소 칸에 채울 값만 돌려준다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/admin/providers/discover HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "discovery_url": "https://example.com"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| issuer | string | `https://id.example.com` | discovery 문서의 `issuer` |
| authorize_url | string | `https://id.example.com/authorize` | discovery 문서의 `authorization_endpoint` |
| token_url | string | `https://id.example.com/token` | discovery 문서의 `token_endpoint` |
| userinfo_url | string\|null | `https://id.example.com/userinfo` | discovery 문서의 `userinfo_endpoint`(없으면 null) |
| jwks_url | string | `https://id.example.com/jwks` | discovery 문서의 `jwks_uri` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "discovery 문서에서 주소를 읽었습니다. 확인 뒤 저장해 주세요.",
    "data": {
        "issuer": "https://id.example.com",
        "authorize_url": "https://id.example.com/authorize",
        "token_url": "https://id.example.com/token",
        "userinfo_url": "https://id.example.com/userinfo",
        "jwks_url": "https://id.example.com/jwks"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | `discovery_failed` | discovery 문서를 받지 못했거나(연결 실패, 2xx 아님, JSON 아님) 응답이 객체가 아닌 경우. 문구는 「discovery 문서를 받지 못했습니다. 주소와 제공자 상태를 확인해 주세요.」 |
| 422 | `discovery_incomplete` | 문서에 `issuer`, `authorization_endpoint`, `token_endpoint`, `jwks_uri` 중 하나가 없거나 빈 경우 |
| 429 | Too Many Requests | 요청 제한(`throttle:20,1`, 버킷 `sirsoft-social_login.discover`)을 넘은 경우 |

<!-- @generated:end -->

**설명**

일반 OIDC 추가, 수정 화면의 「discovery 불러오기」가 부른다. 문서를 캐시 없이 새로 읽어(운영자가 방금 고친 문서를 확인하는 용도) 주소 칸에 채울 값을 돌려줄 뿐 아무것도 저장하지 않는다. 화면은 받은 값을 칸에 넣고, 운영자가 확인한 뒤 저장한다.

실패 코드는 회원 로그인 흐름과 같지만 문구는 관리자용(`exceptions.admin.*`)이다.


### POST /api/plugins/sirsoft-social_login/admin/providers/reorder
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.providers.reorder -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.reorder`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderController@reorder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| keys | body | array | 예 | min 1, max 50 | 새 표시 순서의 제공자 키 목록(1~50개, 서로 달라야 하고 키 규칙 `/^[a-z][a-z0-9_]{1,49}$/`). 목록에 없는 제공자는 원래 순서대로 뒤에 붙고, 제공자 표에 없는 키(확장 등록 제공자)는 무시한다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/admin/providers/reorder HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "keys": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| keys | array | `["google","kakao","naver","facebook"]` | 저장된 표시 순서. 요청에 없던 제공자 표 행이 원래 순서대로 뒤에 붙은 최종 순서다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "표시 순서를 저장했습니다.",
    "data": {
        "keys": ["google", "kakao", "naver", "facebook"]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

로그인, 가입 화면 버튼 순서를 바꾼다. 받은 키 순서대로 `sort_order` 를 1부터 다시 매긴다(한 트랜잭션). 중복 키는 검증에서 422 로 막고, 제공자 표에 없는 키는 조용히 건너뛰며, 목록에서 빠진 행은 원래 순서를 지킨 채 뒤로 간다. 확장 등록 제공자는 순서를 갖지 않고 늘 표 행 뒤에 나온다.


### DELETE /api/plugins/sirsoft-social_login/admin/providers/{key}
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.providers.destroy -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.destroy`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | path | string | 예 | — | 대상 설정/항목의 키 |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-social_login/admin/providers/{key} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| key | string | `acme` | 지운 제공자 키(경로 값 그대로) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "제공자를 삭제했습니다.",
    "data": {
        "key": "acme"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.update`)이 없는 경우 |
| 404 | `provider_unknown` | 제공자 표에 없는 키인 경우(확장 등록 제공자 포함). 문구는 「제공자를 찾을 수 없습니다.」 |
| 409 | `provider_has_links` | 이 제공자에 연결된 회원이 남은 경우 |
| 422 | `builtin_not_deletable` | 내장 제공자(카카오, 네이버, 구글, 페이스북)인 경우. 내장 제공자는 끄기만 한다 |

<!-- @generated:end -->

**설명**

일반 OIDC 제공자를 지운다. 연결된 회원이 있으면 지우지 않는다. 연결 행이 가리킬 제공자가 사라지면 그 회원들은 이 사이트에서 연결을 볼 수도 끊을 수도 없게 되기 때문이다. 먼저 끄고 회원이 다른 로그인 수단을 갖게 한 뒤 연결을 정리하고 지운다. 영향 수치는 `GET /admin/providers/{key}/impact` 로 본다.

삭제 직후 훅 `sirsoft-social_login.provider.after_delete`(인자: 비밀값 없는 요약)를 발행한다.


### PUT /api/plugins/sirsoft-social_login/admin/providers/{key}
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.providers.update -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.update`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | path | string | 예 | — | 대상 설정/항목의 키 |
| enabled | body | boolean | 아니오 | — | 사용 여부 |
| label | body | array | 아니오 | — | 표시용 라벨 |
| client_id | body | string | 아니오 | max 255 | client 식별자 |
| client_secret | body | string | 아니오 | max 2000 | 제공자 콘솔에서 받은 클라이언트 비밀값. 암호화해 저장하고 응답에는 마스크(`••••••••`)로만 싣는다. 마스크를 그대로 보내거나 키를 빼면 유지, 빈 문자열이면 삭제 |
| admin_key | body | string | 아니오 | max 2000 | 카카오 어드민 키. 카카오 쪽 연결 끊기와 「연결 끊기」 웹훅의 `Authorization: KakaoAK …` 대조에 쓴다. 카카오가 아닌 제공자에는 저장하지 않는다. 마스크 규칙은 `client_secret` 과 같다 |
| scopes | body | array | 아니오 | max 20 | 인가 요청에 실을 범위 목록(항목마다 공백 없는 문자열, 100자 이하). 비우면 드라이버 기본 범위를 쓴다. 설정 화면은 한 줄 문자열 `scopes_text` 로 보내고 서버가 공백과 쉼표로 나눠 이 배열로 바꾼다 |
| signup_allowed | body | boolean | 아니오 | — | 이 제공자로 새 회원 가입을 받을지. 끄면 연결이 없는 제공자 계정의 로그인은 가입 대신 로그인 화면 오류(`signup_disabled`)로 돌아간다 |
| email_missing_policy | body | string | 아니오 | — | 제공자가 이메일을 주지 않을 때의 가입 정책. `ask`(가입 화면에서 입력받는다), `deny`(가입을 막는다), `placeholder`(받을 수 없는 대체 주소로 가입시킨다) |
| email_trust | body | string | 아니오 | — | 제공자가 준 이메일을 얼마나 믿는지. `flag`(제공자의 검증 표시를 따른다), `trust`(검증된 것으로 본다), `verify`(늘 메일 인증번호로 다시 확인한다) |
| discovery_url | body | string | 아니오 | max 500 | 일반 OIDC 의 discovery 문서 주소(https). 있으면 인가, 토큰, 서명 키 주소와 발급자를 이 문서에서 읽는다. 내장 제공자에 보내면 저장하지 않는다 |
| authorize_url | body | string | 아니오 | max 500 | 일반 OIDC 인가 주소(https). 추가 요청에서는 `discovery_url` 이 없을 때 필수. 내장 제공자에 보내면 저장하지 않는다 |
| token_url | body | string | 아니오 | max 500 | 일반 OIDC 토큰 주소(https). 추가 요청에서는 `discovery_url` 이 없을 때 필수. 내장 제공자에 보내면 저장하지 않는다 |
| userinfo_url | body | string | 아니오 | max 500 | 일반 OIDC 사용자 정보 주소(https, 선택). 내장 제공자에 보내면 저장하지 않는다 |
| jwks_url | body | string | 아니오 | max 500 | 일반 OIDC ID 토큰 서명 키 목록(JWKS) 주소(https). 추가 요청에서는 `discovery_url` 이 없을 때 필수. 내장 제공자에 보내면 저장하지 않는다 |
| issuer | body | string | 아니오 | max 255 | 일반 OIDC 발급자 값. ID 토큰 검증의 기대 발급자로 쓴다. 추가 요청에서는 `discovery_url` 이 없을 때 필수. 내장 제공자에 보내면 저장하지 않는다 |
| graph_api_version | body | string | 아니오 | — | 페이스북 Graph API 버전(`v{주}.{부}` 형식, 예 `v26.0`). 페이스북이 아닌 제공자에 보내면 저장하지 않는다. 비우면 드라이버 기본 버전 |
| button_style | body | array | 아니오 | — | 일반 OIDC 로그인 버튼 색 `{bg, fg, border}`. 내장 제공자에 보내면 저장하지 않는다(브랜드 모양 고정) |
| button_style.bg | body | string | 아니오 | — | 버튼 배경색. `#` 뒤 16진수 3~8자(`/^#[0-9A-Fa-f]{3,8}$/`) |
| button_style.fg | body | string | 아니오 | — | 버튼 글자색. 형식은 `button_style.bg` 와 같다 |
| button_style.border | body | string | 아니오 | — | 버튼 테두리색. 형식은 `button_style.bg` 와 같다 |
| webhook_ip_allowlist_enabled | body | boolean | 아니오 | — | 웹훅 발신 IP 확인 켬/끔(저장 안 했으면 켜짐). 지금은 카카오 「연결 끊기」 웹훅 검증만 이 값을 보며, 켜져 있으면 카카오 발신 대역에서 온 알림만 받는다 |
| webhook_app_id | body | string | 아니오 | — | webhook app 식별자 |

**요청 예시**

```http
PUT /api/plugins/sirsoft-social_login/admin/providers/{key} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "enabled": true,
    "label": [
        "예시값"
    ],
    "client_id": "예시값",
    "client_secret": "예시값",
    "admin_key": "예시값",
    "scopes": [
        "예시값"
    ],
    "signup_allowed": true,
    "email_missing_policy": "user@example.com",
    "email_trust": "user@example.com",
    "discovery_url": "https://example.com",
    "authorize_url": "https://example.com",
    "token_url": "https://example.com",
    "userinfo_url": "https://example.com",
    "jwks_url": "https://example.com",
    "issuer": "예시값",
    "graph_api_version": "예시값",
    "button_style": [
        "예시값"
    ],
    "button_style.bg": "예시값",
    "button_style.fg": "예시값",
    "button_style.border": "예시값",
    "webhook_ip_allowlist_enabled": true,
    "webhook_app_id": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._ 저장 뒤의 제공자 항목 1건이다. 모양은 목록 조회(`GET /admin/providers`)의 「제공자 항목」과 같고, 비밀값은 마스크(`••••••••`) 또는 null 로만 싣는다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "제공자 설정을 저장했습니다.",
    "data": {
        "key": "kakao",
        "driver": "kakao",
        "type": "builtin",
        "read_only": false,
        "enabled": true,
        "sort_order": 1,
        "label": [],
        "display_label": "카카오",
        "brand": "kakao",
        "client_id": "kakao-rest-api-key",
        "client_secret": "••••••••",
        "admin_key": "••••••••",
        "credentials_unreadable": false,
        "scopes": [],
        "...": "(목록 조회의 제공자 항목과 같은 키)",
        "redirect_uri": "https://example.com/plugins/sirsoft-social_login/oauth/kakao/callback",
        "webhook_urls": {
            "unlink": "https://example.com/plugins/sirsoft-social_login/webhooks/kakao"
        },
        "last_success_at": null,
        "last_error_at": null,
        "last_error_code": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | `credentials_required` | 켜려는데(`enabled: true` 이거나 이미 켜져 있음) 클라이언트 ID 나 비밀값이 비는 경우. 일반 OIDC 는 `discovery_url` 도, 주소 넷(`authorize_url`, `token_url`, `jwks_url`, `issuer`)도 다 갖추지 못한 경우 포함 |
| 404 | `provider_unknown` | 제공자 표에 없는 키인 경우(확장 등록 제공자는 이 화면에서 바꿀 수 없다) |

<!-- @generated:end -->

**설명**

제공자 카드의 [저장]이 부른다. 모든 칸이 선택이고 보낸 칸만 바뀐다. 키, 드라이버, 종류는 바꾸지 않는다.

- 내장 제공자는 주소 칸과 버튼 색을 받지 않는다(드라이버가 정한다). `graph_api_version` 은 페이스북, `admin_key` 와 `webhook_app_id` 는 카카오에만 저장한다. 해당하지 않는 칸은 조용히 버린다.
- `label` 은 저장된 언어별 이름에 합쳐진다(보낸 언어만 바뀌고 빈 문자열인 언어는 빠진다).
- 문자열 칸은 앞뒤 공백을 걷고, 빈 문자열은 null 로 저장한다.
- 켜려면 자격증명이 있어야 한다. 판정은 저장된 값에 이번 요청 값을 겹쳐서 하고, 비밀값은 암호문이 있는지만 본다.

실제로 바뀐 칸이 있으면 훅 `sirsoft-social_login.provider.after_update`(인자: 요약, 바뀐 칸 이름 목록. 값은 싣지 않는다)를 발행한다.


### GET /api/plugins/sirsoft-social_login/admin/providers/{key}/impact
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.providers.impact -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.impact`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderController@impact`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | path | string | 예 | — | 대상 설정/항목의 키 |

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/admin/providers/{key}/impact HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| social_only_users | integer | `3` | 이 제공자 연결만 있고 비밀번호가 없는 회원 수. 이 제공자를 끄거나 자격증명을 바꾸면 로그인할 수 없게 되는 회원이다 |
| linked_users | integer | `12` | 이 제공자에 연결된 소셜 계정 수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "제공자 변경 영향입니다.",
    "data": {
        "social_only_users": 3,
        "linked_users": 12
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.view`)이 없는 경우 |
| 404 | `provider_unknown` | 제공자 표에도 확장 등록 제공자에도 없는 키인 경우 |

<!-- @generated:end -->

**설명**

설정 화면이 제공자를 끄거나 클라이언트 ID 를 바꾸기 전에 띄우는 확인 창의 수치다. 확장 등록 제공자도 조회할 수 있다. 수치는 지금 값이며 저장하지 않는다.


### POST /api/plugins/sirsoft-social_login/admin/providers/{provider}/test
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.providers.test -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.test`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderTestController@test`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | path | string | 예 | — | 대상 provider의 식별자 |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/admin/providers/{provider}/test HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| result | string | `credentials_ok` | 분류. `credentials_ok`(클라이언트 인증 통과), `invalid_client`(클라이언트 ID 나 비밀값이 틀림), `redirect_mismatch`(콜백 주소 불일치), `unreachable`(연결 실패, 제공자 5xx), `inconclusive`(제공자가 틀린 자격증명과 없는 코드를 같은 본문으로 거절해 판별할 수 없음, 네이버 `invalid_request`), `credentials_missing`(필수 자격증명 빔), `credentials_unreadable`(저장된 비밀값을 지금 앱 키로 풀 수 없음), `unexpected`(그 밖). OIDC 드라이버는 discovery, 서명 키 확인 실패 코드(`discovery_failed`, `discovery_incomplete`, `jwks_unreachable`)가 그대로 온다 |
| ok | boolean | `true` | `result` 가 `credentials_ok` 일 때만 true |
| steps | array | `[{"name":"credentials","ok":true,"code":null,"fields":[]},…]` | 단계별 결과 `{name, ok, code}`. `name` 은 `credentials`(자격증명 확인, 빈 칸 이름 `fields` 동반), `discovery`(OIDC 드라이버만, 서명 키 목록 수신), `token`(없는 인가 코드로 토큰 주소 호출, `code` 는 제공자 오류 코드) |
| provider_code | string\|null | `KOE320` | 제공자가 돌려준 오류 코드(분류의 근거). 자격증명 단계에서 멈추면 null |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "연결 테스트를 마쳤습니다.",
    "data": {
        "result": "credentials_ok",
        "ok": true,
        "steps": [
            { "name": "credentials", "ok": true, "code": null, "fields": [] },
            { "name": "token", "ok": true, "code": "KOE320" }
        ],
        "provider_code": "KOE320"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.update`)이 없는 경우 |
| 404 | `provider_unknown` | 등록되지 않은 제공자 키인 경우 |
| 429 | Too Many Requests | 요청 제한(`throttle:20,1`, 버킷 `sirsoft-social_login.test`)을 넘은 경우 |

<!-- @generated:end -->

**설명**

제공자 카드의 [연결 테스트]가 부른다. 회원 동의 없이 자격증명과 콜백 주소가 맞는지 두 단계로 본다. 켜져 있지 않은 제공자도 테스트할 수 있다.

1. 설정: 필수 자격증명이 있는지, OIDC 드라이버(일반 OIDC, 구글)는 서명 키 목록(JWKS)을 받을 수 있는지 본다.
2. 토큰 요청: 존재하지 않는 인가 코드로 토큰 주소를 한 번 불러 제공자가 무엇을 거절하는지 읽는다. 「코드가 틀렸다」는 거절(`invalid_grant`, 카카오 `KOE320`, 페이스북 `facebook_100`)은 클라이언트 인증이 통과했다는 뜻이라 정상(`credentials_ok`)이다.

테스트 실패도 HTTP 200 이다. 판정은 `data.result` 와 `data.ok` 로 한다. 실제 로그인 왕복 확인은 `POST /admin/providers/{provider}/test-login` 이 맡는다.


### POST /api/plugins/sirsoft-social_login/admin/providers/{provider}/test-login
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.providers.test-login -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.providers.test-login`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderTestController@testLogin`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | path | string | 예 | — | 대상 provider의 식별자 |

**요청 예시**

```http
POST /api/plugins/sirsoft-social_login/admin/providers/{provider}/test-login HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| authorize_url | string | `https://kauth.kakao.com/oauth/authorize?response_type=code&client_id=…&redirect_uri=…&state=…` | 관리자 브라우저가 새 창으로 열 제공자 인가 주소. 함께 실린 `state` 는 서버에 테스트 왕복(`intent=test`)으로 저장된다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "연결 테스트를 시작합니다. 열린 창에서 제공자 동의를 마쳐 주세요.",
    "data": {
        "authorize_url": "https://kauth.kakao.com/oauth/authorize?response_type=code&client_id=kakao-rest-api-key&redirect_uri=https%3A%2F%2Fexample.com%2Fplugins%2Fsirsoft-social_login%2Foauth%2Fkakao%2Fcallback&state=…&code_challenge=…&code_challenge_method=S256"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.update`)이 없는 경우 |
| 404 | Not Found | 등록되지 않은 제공자이거나 인가 주소를 만들지 못한 경우(OIDC discovery 실패 등). 문구는 「제공자를 찾을 수 없거나 사용할 수 없습니다.」, `errors.code` 에 제공자 오류 코드 |
| 429 | Too Many Requests | 요청 제한(`throttle:20,1`, 버킷 `sirsoft-social_login.test-login`)을 넘은 경우 |

<!-- @generated:end -->

**설명**

제공자 카드의 [브라우저로 로그인 테스트]가 부른다. 켜지 않은 제공자도 시작할 수 있다. 관리자가 반환된 주소를 새 창에서 열어 제공자 동의를 마치면, 콜백은 로그인하지 않고 일회용 결과 화면(`GET /plugins/sirsoft-social_login/test-result/{code}`, [test-result.md](test-result.md))으로 보낸다. 결과 화면에는 제공자가 보낸 프로필 요약(가린 사용자 ID, 가린 이메일, 받은 항목, 가입에 빠진 항목)이 나온다.

테스트 왕복의 시도 기록은 `intent=test` 로 남고, 통계(`GET /admin/stats`) 집계에서는 빠진다.


### GET /api/plugins/sirsoft-social_login/providers
<!-- @generated:start:api.plugins.sirsoft-social_login.providers.index -->
- **라우트명**: `api.plugins.sirsoft-social_login.providers.index`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\ProviderListController@index`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/providers HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| key | string | `kakao` | 제공자 키. `start_url` 경로와 콜백 주소에 쓰인다 |
| label | string | `카카오` | 표시용 라벨 |
| brand | string | `kakao` | 버튼 모양 계열. 내장 4종은 `kakao`, `naver`, `google`, `facebook`, 그 밖(일반 OIDC, 확장 등록)은 `custom`. 모의 제공자는 원 제공자 계열을 따른다 |
| style | null | `null` | 일반 OIDC 버튼 색 `{bg, fg, border}`(형식이 맞는 색만). 저장된 색이 없으면 null |
| start_url | string | `/plugins/sirsoft-social_login/oauth/k…` | start URL |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "소셜 로그인 제공자 목록입니다.",
    "data": [
        {
            "key": "kakao",
            "label": "카카오",
            "brand": "kakao",
            "style": null,
            "start_url": "/plugins/sirsoft-social_login/oauth/kakao/start"
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 429 | Too Many Requests | 요청 제한(`throttle:120,1`, 버킷 `sirsoft-social_login.providers`)을 넘은 경우 |

<!-- @generated:end -->

**설명**

로그인, 가입 화면이 소셜 로그인 버튼을 그리는 공개 조회다. 켜진 제공자만 표시 순서대로 돌려주고(제공자 표 행은 `sort_order` 순, 다른 확장이 등록한 제공자는 그 뒤), 자격증명(클라이언트 ID 포함)은 싣지 않는다. 버튼은 이 사이트의 시작 주소(`start_url`)로만 간다.

모의 제공자는 개발용으로 켰을 때만 덧붙는다. 켜진 제공자가 없으면 `data` 는 빈 배열이고 화면은 버튼 영역을 그리지 않는다.

요청 제한 버킷을 따로 두어, 화면마다 부르는 이 목록이 가입 인증번호 요청 한도를 깎지 않는다.

