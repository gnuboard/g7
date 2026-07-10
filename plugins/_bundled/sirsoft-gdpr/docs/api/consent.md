# Consent API 레퍼런스

> **소유**: plugin `sirsoft-gdpr` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Consent 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/sirsoft-gdpr/consent/cookie
<!-- @generated:start:api.plugins.sirsoft-gdpr.consent.cookie -->
- **라우트명**: `api.plugins.sirsoft-gdpr.consent.cookie`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Public\GdprCookieConsentController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-gdpr/consent/cookie HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** 공개 쿠키 동의 배너에서 방문자가 선택한 카테고리별 동의를 저장합니다. `optional.sanctum` 라우트로 게스트와 회원 모두 호출할 수 있으며, 회원(sanctum 토큰 보유)이면 user_id 기준으로 status를 upsert하고 history를 남기고, 게스트면 session_id 기준으로 history를 기록합니다. 게스트가 처음 호출해 세션 식별자가 없으면 UUID 기반 `gdpr_session` 쿠키(1년, SameSite=Lax)를 응답에 발급해 첨부합니다. 동의 철회 시 실제 쿠키 파기는 이 엔드포인트가 아니라 클라이언트 정리기와 후속 응답의 CookieConsentMiddleware가 담당합니다.


### GET /api/plugins/sirsoft-gdpr/consent/cookie/status
<!-- @generated:start:api.plugins.sirsoft-gdpr.consent.cookie.status -->
- **라우트명**: `api.plugins.sirsoft-gdpr.consent.cookie.status`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Public\GdprCookieConsentController@status`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/consent/cookie/status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| has_consented | boolean | `false` | consented 여부 |
| consents | array | `[]` | 현재 방문자의 활성 쿠키 동의 목록(카테고리별 현재 선택 상태). 배너가 기존 동의를 반영해 토글 초기값을 그릴 때 사용합니다 |
| needs_renewal | boolean | `false` | 과거 동의 이력은 있으나 현재 정책 버전으로는 미동의인 상태(정책 갱신 후 재확인 필요). 동의 이력이 전혀 없는 신규 게스트는 `false`입니다 |
| current_policy_version | string | `10` | 현재 발행된 최신 정책 버전 문자열(정책 버전 서비스 기준). 방문자 동의 버전과 비교해 배너 재노출 여부를 판단합니다 |
| is_member | boolean | `true` | member 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "has_consented": false,
        "consents": [],
        "needs_renewal": false,
        "current_policy_version": "1",
        "is_member": true
    }
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** 현재 방문자의 쿠키 동의 상태를 반환해 배너 재노출 여부를 판단하게 합니다. `optional.sanctum` 라우트로 회원이면 user_id 기준, 미인증이면 session_id 기준으로 조회합니다. `has_consented`는 현재 정책 버전으로 동의를 완료했는지, `needs_renewal`은 과거 동의는 있으나 현재 정책 버전으로는 미동의인 상태(정책 갱신 후 재확인 필요)를 나타내며 동의 이력이 전혀 없는 신규 게스트는 `false`입니다. `is_member`는 배너가 회원 전용 분기(기존 동의 유지 버튼 등)를 노출할지 결정하는 데 사용합니다.


### POST /api/plugins/sirsoft-gdpr/consent/grant
<!-- @generated:start:api.plugins.sirsoft-gdpr.consent.grant -->
- **라우트명**: `api.plugins.sirsoft-gdpr.consent.grant`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\User\GdprConsentController@grant`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-gdpr/consent/grant HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 마이페이지 「내 동의 현황」에서 회원이 특정 동의 항목을 부여하거나 다시 동의할 때 호출합니다. `auth:sanctum` 인증이 필요하며, `consent_key` 하나를 대상으로 동의를 `true`로 갱신하고 source를 `mypage`로 기록합니다. GDPR Art.7(3)의 자유 변경권을 구현한 것으로, 철회했던 항목의 재동의와 신규 동의를 동일하게 처리하며 history 행이 남습니다. `consent_key`의 화이트리스트 검사는 FormRequest에서 수행합니다.


### GET /api/plugins/sirsoft-gdpr/consent/history
<!-- @generated:start:api.plugins.sirsoft-gdpr.consent.history -->
- **라우트명**: `api.plugins.sirsoft-gdpr.consent.history`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\User\GdprConsentController@history`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/consent/history HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| histories | array | `[]` | 회원 본인의 동의 변경 이력 배열. 각 항목은 `consent_key`, `action`(granted/revoked), `source`, `policy_version`, `categories`, `created_at`로 구성되며 부여/철회 기록을 시간순으로 제공합니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "histories": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 회원 본인의 동의 이력을 반환해 마이페이지 감사 목적으로 표시합니다. `auth:sanctum` 인증이 필요하며, `histories` 배열로 동의 부여/철회 기록을 시간순으로 제공합니다. 조회 전용이므로 부수 효과는 없습니다.


### GET /api/plugins/sirsoft-gdpr/consent/me
<!-- @generated:start:api.plugins.sirsoft-gdpr.consent.me -->
- **라우트명**: `api.plugins.sirsoft-gdpr.consent.me`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\User\GdprConsentController@me`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/consent/me HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| user_id | integer | `166` | user 식별자 (연관 리소스 참조) |
| needs_renewal | boolean | `false` | 회원의 활성 동의 중 옛 정책 버전인 항목이 있어 재동의가 필요한지 여부. 마이페이지가 「전체 다시 동의」 안내를 노출할지 판단합니다 |
| current_policy_version | string | `10` | 현재 발행된 최신 정책 버전 문자열. 각 동의 항목의 `policy_version`과 비교해 항목별 갱신 필요 여부를 계산하는 기준입니다 |
| consents | array | `[{"id":null,"consent_key":"cookie_necessary","consent_lab…` | 카탈로그의 모든 쿠키 카테고리와 회원 status를 합친 동의 매트릭스. 항목마다 다국어 라벨(`consent_label`), 필수 여부(`is_required`), 현재 동의 상태(`is_consented`), 철회/재동의 가능 여부(`can_revoke`/`can_grant`), 항목별 갱신 필요(`needs_renewal_this_item`)를 담아 한 화면에서 철회·재동의·신규 동의를 처리하게 합니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "user_id": 6,
        "needs_renewal": false,
        "current_policy_version": "1",
        "consents": [
            {
                "id": null,
                "consent_key": "cookie_necessary",
                "consent_label": "필수 쿠키",
                "consent_description": "세션·CSRF·로그인 토큰, 장바구니 식별자, 사용자가 가입 시 선택한 언어 설정, 쿠키 동의 기록 등 사이트 운영에 반드시 필요한 항목입니다. 비활성화할 수 없습니다.",
                "consent_category": "necessary",
                "is_required": true,
                "is_consented": false,
                "can_revoke": false,
                "can_grant": false,
                "needs_renewal_this_item": false,
                "consented_at": null,
                "revoked_at": null,
                "consent_count": 0,
                "policy_version": null,
                "last_source": null
            },
            {
                "id": null,
                "consent_key": "cookie_functional",
                "consent_label": "기능 쿠키",
                "consent_description": "사용자 선호도(다크모드, 표시 통화 등)를 기억하는 쿠키입니다. 거부 시 매 방문마다 기본값으로 표시됩니다.",
                "consent_category": "functional",
                "is_required": false,
                "is_consented": false,
                "can_revoke": false,
                "can_grant": true,
                "needs_renewal_this_item": false,
                "consented_at": null,
                "revoked_at": null,
                "consent_count": 0,
                "policy_version": null,
                "last_source": null
            },
            {
                "id": null,
                "consent_key": "cookie_analytics",
                "consent_label": "분석 쿠키",
                "consent_description": "방문자가 사이트를 어떻게 이용하는지 익명으로 측정해 더 나은 서비스를 만드는 데 사용됩니다. (예: Google Analytics, Hotjar)",
                "consent_category": "analytics",
                "is_required": false,
                "is_consented": false,
                "can_revoke": false,
                "can_grant": true,
                "needs_renewal_this_item": false,
                "consented_at": null,
                "revoked_at": null,
                "consent_count": 0,
                "policy_version": null,
                "last_source": null
            },
            {
                "id": null,
                "consent_key": "cookie_marketing",
                "consent_label": "마케팅 쿠키",
                "consent_description": "관심사에 맞는 광고를 보여주거나, 광고가 얼마나 효과적이었는지 측정하는 데 사용됩니다. SNS 영상 임베드 등도 포함됩니다. (예: Facebook 픽셀, Google 광고, YouTube 영상)",
                "consent_category": "marketing",
                "is_required": false,
                "is_consented": false,
                "can_revoke": false,
                "can_grant": true,
                "needs_renewal_this_item": false,
                "consented_at": null,
                "revoked_at": null,
                "consent_count": 0,
                "policy_version": null,
                "last_source": null
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 마이페이지 「내 동의 현황」 화면의 데이터 소스로, 회원 본인의 전체 동의 매트릭스를 반환합니다. `auth:sanctum` 인증이 필요하며, 활성 동의만이 아니라 카탈로그의 모든 카테고리와 회원 상태를 합쳐 노출해 철회·재동의·신규 동의를 한 화면에서 처리하도록 합니다(Art.7(3) 대칭성). 함께 반환되는 `needs_renewal`과 `current_policy_version`으로 정책 버전 갱신 이후 재동의가 필요한지 판단합니다. 조회 전용입니다.


### POST /api/plugins/sirsoft-gdpr/consent/renew-all
<!-- @generated:start:api.plugins.sirsoft-gdpr.consent.renew_all -->
- **라우트명**: `api.plugins.sirsoft-gdpr.consent.renew_all`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\User\GdprConsentController@renewAll`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-gdpr/consent/renew-all HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| renewed | integer | `0` | 이번 호출로 현재 정책 버전으로 재동의 처리된 활성 선택형 동의 항목 수. 필수 쿠키와 이미 철회한 항목·이미 현재 버전인 항목은 제외되며, 프론트 toast 메시지의 `{renewed}` 보간값으로 사용됩니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "활성 항목 {renewed} 개를 새 정책 버전으로 갱신했습니다.",
    "data": {
        "renewed": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 정책 버전이 갱신된 뒤 회원의 활성 선택형 동의를 현재 정책 버전으로 일괄 재동의 처리합니다. `auth:sanctum` 인증이 필요하며, 필수 쿠키와 이미 철회한 항목은 대상에서 제외됩니다. 의사 변경 없이 재동의 의사 표명으로 처리되어 갱신된 각 항목마다 `action=granted` history 행이 누적됩니다(Art.7(1) 입증 트레일). 응답에는 갱신 건수가 포함되어 프론트 toast 메시지에 사용됩니다.


### POST /api/plugins/sirsoft-gdpr/consent/revoke
<!-- @generated:start:api.plugins.sirsoft-gdpr.consent.revoke -->
- **라우트명**: `api.plugins.sirsoft-gdpr.consent.revoke`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\User\GdprConsentController@revoke`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-gdpr/consent/revoke HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 마이페이지 「내 동의 현황」에서 회원이 특정 동의 항목을 철회할 때 호출합니다. `auth:sanctum` 인증이 필요하며, `consent_key` 하나를 대상으로 동의를 `false`로 갱신하고 source를 `mypage`로 기록하며 history 행을 남깁니다. `consent_key`의 화이트리스트 검사는 FormRequest에서 수행합니다.


