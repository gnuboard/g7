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

## 코어 API 레퍼런스

<!-- @generated:start:api-readme-index -->
- **문서 수**: 35 · **엔드포인트 수**: 291

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
| [templates.md](templates.md) | `templates` | 57 |
| [users.md](users.md) | `users` | 12 |
| [verify-password.md](verify-password.md) | `verify-password` | 1 |

<!-- @generated:end -->

## 확장 API 레퍼런스

> 각 확장이 자신의 API 문서를 소유합니다. 아래 표는 자동 생성됩니다.

<!-- @generated:start:api-readme-extensions -->
- **확장 수**: 9 · **엔드포인트 수**: 372

| 확장 | 유형 | API 문서 목차 | 문서/엔드포인트 |
| --- | --- | --- | --- |
| `gnuboard7-hello_module` | 모듈 | [docs/api/](../../../modules/_bundled/gnuboard7-hello_module/docs/api/README.md) | 1 / 2 |
| `sirsoft-board` | 모듈 | [docs/api/](../../../modules/_bundled/sirsoft-board/docs/api/README.md) | 10 / 80 |
| `sirsoft-ecommerce` | 모듈 | [docs/api/](../../../modules/_bundled/sirsoft-ecommerce/docs/api/README.md) | 33 / 231 |
| `sirsoft-page` | 모듈 | [docs/api/](../../../modules/_bundled/sirsoft-page/docs/api/README.md) | 2 / 17 |
| `sirsoft-ckeditor5` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-ckeditor5/docs/api/README.md) | 2 / 2 |
| `sirsoft-gdpr` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-gdpr/docs/api/README.md) | 4 / 15 |
| `sirsoft-marketing` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-marketing/docs/api/README.md) | 2 / 2 |
| `sirsoft-pay_kginicis` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-pay_kginicis/docs/api/README.md) | 5 / 22 |
| `sirsoft-verification_kginicis` | 플러그인 | [docs/api/](../../../plugins/_bundled/sirsoft-verification_kginicis/docs/api/README.md) | 1 / 1 |

<!-- @generated:end -->
