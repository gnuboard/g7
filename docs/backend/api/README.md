# API 레퍼런스 문서 목차

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반).
> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.

G7 의 REST API 레퍼런스입니다. 도메인별 문서에는 엔드포인트마다 메서드·URI·인증/권한, 요청 파라미터 표,
응답 필드 표, 실제 호출로 관측한 요청·응답 예시가 실려 있습니다. 아래 공통 규약은 모든 엔드포인트에
동일하게 적용되므로 개별 문서에서 반복하지 않습니다.

문서 작성·갱신 규정은 [api-documentation.md](../api-documentation.md) 를 참고하세요.

## 공통 규약

### 인증

Laravel Sanctum 의 **Bearer 토큰 전용**입니다(세션 쿠키 인증 미사용).
`POST /api/auth/login` 또는 `POST /api/auth/admin/login` 으로 토큰을 발급받아 모든 후속 요청에 실어 보냅니다.

```http
GET /api/me HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

각 문서의 **인증/권한** 줄은 다음 네 가지 중 하나입니다.

| 표기 | 의미 |
| --- | --- |
| `공개 (인증 불필요)` | 토큰 없이 호출 가능 |
| `optional.sanctum` | 토큰이 있으면 회원, 없으면 비회원으로 처리 (둘 다 접근 가능) |
| `auth:sanctum` | 유효한 토큰 필수 (없거나 만료 시 401) |
| `auth:sanctum + admin + permission:{키}` | 토큰 + 관리자 + 해당 권한 필요 (권한 부족 시 403) |

### 로케일

응답 메시지의 언어는 ① 로그인 사용자의 `users.language` → ② `Accept-Language` 헤더 →
③ 시스템 기본값(`config('app.locale')`) 순으로 결정됩니다. 지원 언어는 `config('app.supported_locales')` 를 따릅니다.

### 응답 봉투

성공·실패 모두 동일한 최상위 구조를 씁니다. 실제 페이로드는 항상 `data` 안에 들어갑니다.

```json
{
    "success": true,
    "message": "요청이 성공했습니다.",
    "data": { }
}
```

실패 시 `success` 는 `false` 이고, 검증 오류는 `errors` 에 필드별 메시지 배열로 담깁니다.

```json
{
    "success": false,
    "message": "입력값이 올바르지 않습니다.",
    "errors": {
        "email": ["이메일 형식이 올바르지 않습니다."]
    }
}
```

### 페이지네이션

목록 엔드포인트는 `data.data[]` 에 항목 배열을, `data.pagination` 에 페이지 정보를 담습니다.
요청은 `page` 와 `per_page` 쿼리 파라미터로 제어합니다.

```json
{
    "pagination": {
        "current_page": 1,
        "last_page": 4,
        "per_page": 25,
        "total": 87,
        "from": 1,
        "to": 25,
        "has_more_pages": true
    }
}
```

#### 총 건수 정확도 (대용량 목록)

매칭이 아주 많을 수 있는 목록(검색 등)은 총 건수를 상한까지만 셉니다. 그런 목록은 위 필드에
더해 정확도를 함께 내보내며, 세지 않은 값을 정확한 것처럼 말하지 않습니다.

| 필드 | 타입 | 의미 |
| --- | --- | --- |
| `total_relation` | string | `exact`(정확) 또는 `at_least`(그 이상) |
| `total_is_exact` | boolean | 총 건수가 정확한지 여부 |
| `result_cap` | integer\|null | 집계에 적용된 상한 (무제한이면 `null`) |

상한을 넘긴 경우 동작은 이렇습니다.

- `total` 은 상한값이며 **그 이상**이라는 뜻입니다 (화면은 "10,000건 이상" 으로 표기)
- `last_page` 는 **`null`** 입니다 — 총 건수를 알아야 계산되는 유일한 값이라 계산할 수 없습니다
- `has_more_pages` 는 그대로 정확합니다. 다음 페이지 이동은 끝까지 열려 있습니다

즉 상한에 걸려도 막히는 것은 마지막 페이지 점프 하나뿐입니다.

#### 단순형·커서형 응답

총 건수를 아예 세지 않는 목록(`simplePaginate`)은 `total` 과 `last_page` 를 **내보내지 않습니다**.
커서 방식 목록은 대신 `next_cursor` / `prev_cursor` 를 실어 보냅니다. 없는 필드를 0 이나 1 로
채우지 않으므로, 화면은 필드 존재 여부로 목록의 종류를 구분할 수 있습니다.

> 상한·커서 규약 상세: [pagination.md](../pagination.md)

일부 목록은 `data.abilities` 에 컬렉션 레벨 권한(`can_create`, `can_delete` 등)을 함께 반환합니다.
화면의 버튼 노출 여부를 이 값으로 판정하세요.

### 공통 에러 상태코드

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 엔드포인트가 요구하는 권한이 없는 경우 |
| 404 | Not Found | 대상 리소스가 없거나 접근 범위를 벗어난 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 428 | Precondition Required | 본인인증(IDV)이 선행되어야 하는 경우 |

확장(모듈·플러그인)이 제공하는 엔드포인트(`/api/modules/{id}/…`, `/api/plugins/{id}/…`)는 그 확장이
**활성 상태일 때만** 존재합니다. 비활성화·제거된 확장의 엔드포인트는 404 를 반환하며, 이는 권한
문제가 아니라 라우트가 등록되지 않은 상태입니다. 확장을 업데이트하는 동안에도 잠시 같은 상태가 됩니다.

428 응답은 `error_code: "identity_verification_required"` 와 함께 `verification` 객체를 반환합니다.
클라이언트는 이 값으로 본인인증 화면을 띄운 뒤 원래 요청을 재시도합니다.

```json
{
    "success": false,
    "error_code": "identity_verification_required",
    "message": "본인인증이 필요합니다.",
    "verification": { }
}
```

### 자산 URL 이중 모드

정적 파일 확장자(`.js` / `.css` / `.json`)로 끝나는 동적 엔드포인트는 **확장자 없는 형태를 함께 제공**합니다.
아래 문서에 실린 URI 는 확장자 형태를 기준으로 표기하지만, 각 엔드포인트는 대응하는 확장자 없는 형태로도
동일한 응답·동일한 권한 가드로 호출할 수 있습니다.

이유는 서버 설정입니다. nginx/Apache 의 표준적 정적 최적화 블록은 URL 마지막 확장자로 분기하며,
nginx 에서 정규식 location 은 프리픽스 location 보다 먼저 매칭되므로 `try_files ... /index.php` 폴백이
실행될 기회가 없습니다. 그런 환경에서는 확장자 붙은 동적 응답이 PHP 에 도달하지 못하고 404 가 됩니다.

```nginx
location ~* \.(js|css|json)$ { expires max; access_log off; }
```

| 확장자 형태 | 확장자 없는 형태 | 변환 규칙 |
| --- | --- | --- |
| `/api/templates/{id}/routes.json` | `/api/templates/{id}/routes` | 접미사 제거 |
| `/api/layouts/{tpl}/{layout}.json` | `/api/layouts/{tpl}/{layout}` | 접미사 제거 |
| `/api/modules/bundle.js` | `/api/modules/bundle/js` | 접미사를 경로 세그먼트로 (js/css 구분이 필요) |
| `/api/templates/assets/{id}/js/a.js` | `/api/templates/assets/{id}?file=js/a.js` | 파일 경로를 `file` 쿼리로 (경로가 곧 파일명이라 제거 불가) |

`file` 쿼리 형태가 안전한 이유는 nginx 의 location 정규식이 쿼리스트링을 제외한 경로에만 매칭되기 때문입니다.
확장자 없는 형태에도 경로 탈출 방어와 확장자 화이트리스트가 동일하게 적용됩니다.

두 형태는 **모두 영구 유지**됩니다. 확장자 형태를 제거하면 URL 을 하드코딩한 서드파티 확장이 깨집니다.

어느 형태를 쓸지는 서버 환경에 따라 결정되며, 다음 프로브 엔드포인트로 판정합니다.

| 메서드 | URI | 인증/권한 | 설명 |
| --- | --- | --- | --- |
| GET | `/api/system/asset-probe.js` | 공개 (인증 불필요) | 확장자 형태 프로브 |
| GET | `/api/system/asset-probe` | 공개 (인증 불필요) | 대조군 |

두 URL 을 **브라우저에서** 쌍으로 요청합니다(서버측 loopback curl 은 vhost·프록시 체인을 우회해 오판합니다).
응답은 `application/javascript` 이며 본문에 매직 토큰 `G7_ASSET_PROBE_OK` 를 담습니다. DB 에 접근하지 않고
`Cache-Control: no-store` 로 캐시되지 않습니다.

| `asset-probe.js` | `asset-probe` | 판정 |
| --- | --- | --- |
| 성공 | 성공 | 확장자 형태 사용 가능 |
| 실패 | 성공 | 정적 블록 가로채기 확정 — 확장자 없는 형태 사용 |
| 실패 | 실패 | 모드 문제가 아님 (PHP/라우팅 장애) |

성공 판정은 상태코드가 아니라 **본문의 매직 토큰과 Content-Type** 으로 합니다. 상태코드만 보면
"404 대신 200 + 에러 HTML" 이나 catch-all 200 페이지를 반환하는 설정에서 영원히 오판합니다.

### 보안 게이트 (KVE-2026 대응)

일부 관리 엔드포인트에는 표준 응답 외에 다음 보안 게이트가 적용됩니다(각 엔드포인트 표에는 별도 표기가 없어도 공통 적용).

- **등급 상한 (KVE-2026-1919)** — 사용자·역할 쓰기 경로:
  - `PUT /api/admin/users/{user}`, `POST /api/admin/users/{user}/unlock`: 비-슈퍼관리자 액터가 슈퍼 관리자 계정을 수정·잠금해제하려 하면 `403` (`exceptions.cannot_modify_super_admin`).
  - `PATCH /api/admin/users/bulk-status`: 비-슈퍼관리자 액터가 포함시킨 슈퍼 관리자 대상은 일괄 처리에서 제외(요청은 `200`, 슈퍼 관리자 상태 불변).
  - `POST /api/admin/roles`, `PUT /api/admin/roles/{role}`: 비-슈퍼관리자 액터가 자신이 보유하지 않았거나 자신의 범위(scope)보다 넓은 권한을 부여하려 하면 `403` (`exceptions.cannot_grant_unheld_permission`).
  - `PUT /api/admin/roles/{role}`, `PATCH /api/admin/roles/{role}/toggle-status`: 비-슈퍼관리자 액터가 코어/확장 소유 역할(예: `admin`)을 수정·토글하려 하면 `403` (`exceptions.cannot_modify_protected_role`).
  - 슈퍼 관리자 액터의 동일 작업은 정상 수행됩니다.
- **레이아웃 저장 표현식/URL 검증 (KVE-2026-1915)** — 레이아웃 생성·수정(`POST/PUT /api/admin/layouts*`)의 `content` 검증:
  - `{{...}}`·`computed`·`init_actions`/`actions` 문자열 값에 위험 토큰이 있으면 `422` (`validation.layout.dangerous_expression`). 차단 토큰은 프로토타입 체인 접근(`.constructor`/`.__proto__`/`.prototype`, `['constructor']`, 원시 `__proto__`)·`Function(`·`eval(`·`import(` 입니다.
  - `scripts[].src`·`data_sources[].endpoint` 가 same-origin path-only(`/` 시작)가 아니면 `422` (`validation.layout.external_resource_url`). 단, 활성 확장(모듈·플러그인·템플릿)이 자기 manifest 의 `trusted_script_hosts` 로 선언한 호스트는 예외로 허용됩니다 — 이 목록은 확장 배포물이 정하며 요청으로 바꿀 수 없습니다.
  - 정상 표현식(조건·계산·목록 가공·화살표 함수·템플릿 리터럴·경로 조립)은 통과합니다.

## 코어 API 레퍼런스

<!-- @generated:start:api-readme-index -->
- **문서 수**: 36 · **엔드포인트 수**: 319

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [activity-logs.md](activity-logs.md) | `activity-logs` | 3 |
| [attachment.md](attachment.md) | `attachment` | 1 |
| [attachments.md](attachments.md) | `attachments` | 4 |
| [auth.md](auth.md) | `auth` | 15 |
| [avatar.md](avatar.md) | `avatar` | 2 |
| [broadcasting.md](broadcasting.md) | `broadcasting` | 1 |
| [changelog.md](changelog.md) | `changelog` | 1 |
| [core-update.md](core-update.md) | `core-update` | 2 |
| [dashboard.md](dashboard.md) | `dashboard` | 5 |
| [extensions.md](extensions.md) | `extensions` | 3 |
| [identity.md](identity.md) | `identity` | 27 |
| [language-packs.md](language-packs.md) | `language-packs` | 15 |
| [layouts.md](layouts.md) | `layouts` | 2 |
| [license.md](license.md) | `license` | 1 |
| [locales.md](locales.md) | `locales` | 1 |
| [me.md](me.md) | `me` | 3 |
| [menus.md](menus.md) | `menus` | 10 |
| [modules.md](modules.md) | `modules` | 25 |
| [notification-channels.md](notification-channels.md) | `notification-channels` | 1 |
| [notification-definitions.md](notification-definitions.md) | `notification-definitions` | 5 |
| [notification-logs.md](notification-logs.md) | `notification-logs` | 3 |
| [notification-templates.md](notification-templates.md) | `notification-templates` | 4 |
| [notifications.md](notifications.md) | `notifications` | 14 |
| [password.md](password.md) | `password` | 1 |
| [permissions.md](permissions.md) | `permissions` | 1 |
| [plugins.md](plugins.md) | `plugins` | 27 |
| [profile.md](profile.md) | `profile` | 4 |
| [roles.md](roles.md) | `roles` | 7 |
| [schedules.md](schedules.md) | `schedules` | 12 |
| [search.md](search.md) | `search` | 1 |
| [seo.md](seo.md) | `seo` | 5 |
| [settings.md](settings.md) | `settings` | 15 |
| [system.md](system.md) | `system` | 2 |
| [templates.md](templates.md) | `templates` | 57 |
| [users.md](users.md) | `users` | 12 |
| [verify-password.md](verify-password.md) | `verify-password` | 1 |

<!-- @generated:end -->

## 확장 API 레퍼런스

> 각 확장이 자신의 API 문서를 소유합니다. 아래 표는 자동 생성됩니다.

<!-- @generated:start:api-readme-extensions -->
- **확장 수**: 14 · **엔드포인트 수**: 416

| 확장 | 유형 | API 문서 목차 | 문서/엔드포인트 |
| --- | --- | --- | --- |
| `gnuboard7-hello_module` | 모듈 | [docs/api/](../../../modules/_bundled/gnuboard7-hello_module/docs/api/README.md) | 1 / 7 |
| `sirsoft-board` | 모듈 | [docs/api/](../../../modules/_bundled/sirsoft-board/docs/api/README.md) | 10 / 80 |
| `sirsoft-ecommerce` | 모듈 | [docs/api/](../../../modules/_bundled/sirsoft-ecommerce/docs/api/README.md) | 33 / 239 |
| `sirsoft-page` | 모듈 | [docs/api/](../../../modules/_bundled/sirsoft-page/docs/api/README.md) | 2 / 17 |
| `sirsoft-ckeditor5` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-ckeditor5/docs/api/README.md) | 2 / 2 |
| `sirsoft-gdpr` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-gdpr/docs/api/README.md) | 4 / 15 |
| `sirsoft-marketing` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-marketing/docs/api/README.md) | 2 / 2 |
| `sirsoft-pay_kginicis` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-pay_kginicis/docs/api/README.md) | 5 / 34 |
| `sirsoft-pay_nhnkcp` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-pay_nhnkcp/docs/api/README.md) | 0 / 0 |
| `sirsoft-pay_nicepayments` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-pay_nicepayments/docs/api/README.md) | 0 / 0 |
| `sirsoft-tosspayments` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-tosspayments/docs/api/README.md) | 2 / 4 |
| `sirsoft-verification_kginicis` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-verification_kginicis/docs/api/README.md) | 2 / 3 |
| `sirsoft-verification_nhnkcp` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-verification_nhnkcp/docs/api/README.md) | 1 / 1 |

<!-- @generated:end -->
