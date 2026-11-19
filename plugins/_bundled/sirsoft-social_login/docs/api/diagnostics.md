# Diagnostics API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Diagnostics 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-social_login/admin/diagnostics
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.diagnostics.index -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.diagnostics.index`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\DiagnosticsController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/admin/diagnostics HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| providers | array | `[{"key":"kakao","label":"카카오","driver":"kakao","type":"bu…` | 제공자 표 행별 상태. `configured`(클라이언트 ID 와 비밀값이 저장돼 있음), `credentials_unreadable`(저장된 비밀값을 지금 앱 키로 풀 수 없음), `last_success_at`, `last_error_at`, `last_error_code`(마지막 로그인 왕복 성공, 실패), `last_webhook_at`(마지막 웹훅 수신). 시각은 보는 사람 시간대 `YYYY-MM-DD HH:mm:ss` |
| queue | object | `{"connection":"sync","pending":0,"failed_jobs":0}` | 큐 상태. `connection`(기본 큐 연결 이름), `pending`(기본 큐 대기 잡 수, 동기 큐는 0, 셀 수 없으면 null), `failed_jobs`(실패한 웹훅 처리 잡 수) |
| mail | object | `{"mailer":"mailgun","delivering":true,"recent_code_failur…` | 인증번호 메일 상태. `mailer`(기본 메일 수단), `delivering`(실제로 보내는 수단인지, `log`와 `array` 면 false), `recent_code_failures`(최근 7일 인증번호 메일 발송 실패 수, 시도 기록 `mail_failed`) |
| mock | object | `{"enabled":false,"expires_at":null}` | 모의 제공자 상태. `enabled`(지금 켜져 있는지), `expires_at`(저절로 꺼지는 시각, 보는 사람 시간대. 꺼져 있으면 null) |
| members | object | `{"linked_users":4,"passwordless_users":"{MASKED}"}` | 소셜 연결 회원 수. `linked_users`(연결이 하나라도 있는 회원), `passwordless_users`(그중 비밀번호가 없는 회원). 둘 다 정수이며, 응답 예시의 `{MASKED}` 는 문서 생성기가 키 이름 때문에 가린 값이다 |
| webhooks | object | `{"days":7,"received":12,"ignored":12,"failed":0,"trusted_…` | 최근 `days`(7)일 웹훅 수신 요약. `received`(받은 수), `ignored`(무시한 수), `failed`(처리 실패 수), `trusted_proxy_configured`(신뢰 프록시 설정 여부). 무시한 웹훅이 있는데 신뢰 프록시가 없으면 화면이 「프록시 뒤라면 발신 IP 확인이 모두 실패할 수 있다」고 안내한다 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "진단 정보입니다.",
    "data": {
        "providers": [
            {
                "key": "kakao",
                "label": "카카오",
                "driver": "kakao",
                "type": "builtin",
                "enabled": true,
                "configured": true,
                "credentials_unreadable": false,
                "last_success_at": "2026-10-22 12:56:56",
                "last_error_at": null,
                "last_error_code": null,
                "last_webhook_at": "2026-11-01 19:10:25"
            },
            {
                "key": "naver",
                "label": "네이버",
                "driver": "naver",
                "type": "builtin",
                "enabled": false,
                "configured": false,
                "credentials_unreadable": false,
                "last_success_at": null,
                "last_error_at": null,
                "last_error_code": null,
                "last_webhook_at": "2026-10-31 14:55:18"
            },
            {
                "key": "google",
                "label": "구글",
                "driver": "google",
                "type": "builtin",
                "enabled": false,
                "configured": false,
                "credentials_unreadable": false,
                "last_success_at": null,
                "last_error_at": null,
                "last_error_code": null,
                "last_webhook_at": null
            },
            {
                "key": "facebook",
                "label": "페이스북",
                "driver": "facebook",
                "type": "builtin",
                "enabled": false,
                "configured": false,
                "credentials_unreadable": false,
                "last_success_at": null,
                "last_error_at": null,
                "last_error_code": null,
                "last_webhook_at": "2026-10-31 14:55:18"
            }
        ],
        "queue": {
            "connection": "sync",
            "pending": 0,
            "failed_jobs": 0
        },
        "mail": {
            "mailer": "mailgun",
            "delivering": true,
            "recent_code_failures": 0
        },
        "mock": {
            "enabled": false,
            "expires_at": null
        },
        "members": {
            "linked_users": 4,
            "passwordless_users": "{MASKED}"
        },
        "webhooks": {
            "days": 7,
            "received": 12,
            "ignored": 12,
            "failed": 0,
            "trusted_proxy_configured": false
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

관리자 진단 화면의 요약 카드가 부른다. 제공자 설정, 큐, 메일, 모의 제공자, 연결 회원, 웹훅 수신을 한 번에 보여 소셜 로그인이 멈춘 원인을 좁히는 용도다. 모든 값은 호출 시점의 계산값이며 저장하지 않는다.

- `providers[].credentials_unreadable` 이 true 면 앱 키가 바뀐 것이다. 설정 화면에서 비밀값을 다시 입력하면 풀린다.
- `mail.delivering` 이 false 면 가입, 기존 계정 연결 인증번호가 실제로 가지 않는다.
- 최근 시도 기록과 웹훅 수신 이력의 행 목록은 `GET /admin/attempts`, `GET /admin/webhook-events` 가 따로 준다.

