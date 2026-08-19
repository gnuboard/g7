# Me API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Me 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### DELETE /api/me
<!-- @generated:start:api.me.destroy -->
- **라우트명**: `api.me.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@destroy`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
DELETE /api/me HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```json
{
    "success": true,
    "message": "회원 탈퇴가 완료되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

현재 로그인한 사용자가 자신의 계정을 탈퇴한다. 프론트의 회원 탈퇴 모달(`_modal_withdraw.json`)이 호출한다. 별도 요청 파라미터 없이 인증 토큰의 사용자를 대상으로 하며, `UserService::withdrawUser()` 가 아바타·토큰 삭제와 개인정보 익명화를 수행한다. 되돌릴 수 없는 작업이므로 호출 전 사용자 확인 절차를 두는 것을 권장한다.

탈퇴는 원자적으로 처리된다 — 약관 동의 이력 삭제·토큰 삭제·익명화가 하나의 트랜잭션이며, 어느 단계에서 실패해도 전부 취소된다. 아바타 파일 삭제는 커밋이 확정된 뒤에 수행되고, 파일 삭제가 실패해도 탈퇴는 되돌리지 않는다(참조되지 않는 파일만 남는다).

익명화된 이메일에는 사용자 ID 가 포함되어 구조적으로 유일하다 — 같은 이메일로 재가입한 회원이 같은 날 다시 탈퇴해도 충돌하지 않는다.

탈퇴 처리 중 예기치 못한 오류(예: 데이터베이스 예외)가 발생하면 500 과 함께 고정된 안내 메시지(`user.withdraw_failed`)만 반환한다 — 예외 원문(SQL 상태코드·쿼리 등)은 응답에 싣지 않고 서버 로그에만 기록한다(회원에게 노출되는 경로이므로 내부 정보 유출을 차단한다).

**추가 오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Validation Error | 관리자 역할 보유 계정 또는 수퍼관리자 계정의 탈퇴 시도 (`errors.general` 에 사유) |

```json
{
    "success": false,
    "message": "회원 탈퇴에 실패했습니다.",
    "errors": {
        "general": [
            "관리자 계정은 탈퇴할 수 없습니다."
        ]
    }
}
```

> 차단 응답은 종전에 500 이었으나, 잘못된 요청이지 서버 오류가 아니므로 422 로 바뀌었다. 활동 로그도 탈퇴가 성공한 경우에만 기록된다.


### GET /api/me
<!-- @generated:start:api.me.show -->
- **라우트명**: `api.me.show`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@show`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/me HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a26219fc-94a0-4f63-9404-04c2a6ac99e4` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `최고관리자` | 사용자 이름 |
| nickname | string | `최고관리자` | 닉네임 |
| email | string | `heuristing@gmail.com` | 이메일 주소 |
| avatar | null | `null` | 아바타 이미지 URL (User::getAvatarUrl() 산물, 미등록 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| timezone | string | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| country | null | `null` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| homepage | null | `null` | 홈페이지 URL |
| mobile | null | `null` | 휴대폰 번호 |
| phone | null | `null` | 전화번호 |
| zipcode | null | `null` | 우편번호 |
| address | null | `null` | 기본 주소 |
| address_detail | null | `null` | 상세 주소 |
| signature | null | `null` | 서명 |
| bio | null | `null` | 자기소개 |
| is_super | boolean | `true` | super 여부 |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생) |
| withdrawn_at | null | `null` | withdrawn 일시 |
| last_login_at | string | `2026-08-04 19:00:10` | last login 일시 |
| last_login_human | string | `2시간 전` | 마지막 로그인 시각의 상대 표현 (diffForHumans() 산물, 사용자 시간대 기준) |
| created_at | string | `2026-07-30 23:37:44` | 생성 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| notify_post_complete | boolean | `false` | 게시글 작성 완료 알림 수신 설정 (게시판 모듈 주입) |
| notify_post_reply | boolean | `false` | 내 게시글에 대한 답글 알림 수신 설정 (게시판 모듈 주입) |
| notify_comment | boolean | `false` | 내 게시글에 대한 댓글 알림 수신 설정 (게시판 모듈 주입) |
| notify_reply_comment | boolean | `false` | 내 댓글에 대한 대댓글 알림 수신 설정 (게시판 모듈 주입) |
| email_subscription | boolean | `false` | 광고성 이메일 수신 동의 여부 (마케팅 플러그인 주입, 채널) |
| email_subscription_at | null | `null` | email subscription 일시 |
| marketing_consent | boolean | `false` | 마케팅 정보 수신 전체 동의 마스터 키 (마케팅 플러그인 주입) |
| marketing_consent_at | null | `null` | marketing consent 일시 |
| third_party_consent | boolean | `false` | 제3자 정보 제공 동의 여부 (법적 항목, 마케팅 플러그인 주입) |
| third_party_consent_at | null | `null` | third party consent 일시 |
| info_disclosure | boolean | `false` | 개인정보 이용 안내 동의 여부 (법적 항목, 마케팅 플러그인 주입) |
| info_disclosure_at | null | `null` | info disclosure 일시 |
| marketing_consent_enabled | boolean | `true` | 마케팅 동의 항목 UI 노출 여부 (활성화 플래그) |
| marketing_consent_terms_slug | string | `marketing-terms` | 마케팅 동의에 연결된 약관 slug (미설정 시 null) |
| marketing_consent_terms_slug_set | boolean | `true` | 마케팅 동의 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| third_party_consent_enabled | boolean | `true` | 제3자 제공 동의 항목 UI 노출 여부 (활성화 플래그) |
| third_party_consent_terms_slug | null | `null` | 제3자 제공 동의에 연결된 약관 slug (미설정 시 null) |
| third_party_consent_terms_slug_set | boolean | `false` | 제3자 제공 동의 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| info_disclosure_enabled | boolean | `true` | 개인정보 이용 안내 동의 항목 UI 노출 여부 (활성화 플래그) |
| info_disclosure_terms_slug | null | `null` | 개인정보 이용 안내 동의에 연결된 약관 slug (미설정 시 null) |
| info_disclosure_terms_slug_set | boolean | `false` | 개인정보 이용 안내 동의 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| email_subscription_enabled | boolean | `true` | 이메일 수신 동의 항목 UI 노출 여부 (활성화 플래그) |
| email_subscription_terms_slug | null | `null` | 이메일 수신 동의에 연결된 약관 slug (미설정 시 null) |
| email_subscription_terms_slug_set | boolean | `false` | 이메일 수신 동의 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| channels | array | `[{"key":"email_subscription","label":"광고성 이메일 수신","enable…` | 관리자 정의 전체 마케팅 채널 목록 (원소 key/label/enabled/terms_slug, 마케팅 플러그인 주입) |
| consent_histories | array | `[]` | 동의 변경 이력 (원소 channel_key/action/source/created_at, 마케팅 플러그인 주입) |
| ecommerce_mileage | object | `{"enabled":false}` | 마일리지 정보 (enabled: 기능 활성 여부, 잔액, 이커머스 모듈 주입) |
| ecommerce_preferred_currency | string | `KRW` | 선호 결제 통화 (이커머스 모듈 주입, 미설정 시 null) |
| ecommerce_preferred_shipping_country | null | `null` | 선호 배송 국가 코드 (이커머스 모듈 주입, 미설정 시 null) |
| ecommerce_preferred_shipping_country_name | null | `null` | 선호 배송 국가 이름 (코드 파생, 이커머스 모듈 주입, 미설정 시 null) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "프로필 정보를 성공적으로 가져왔습니다.",
    "data": {
        "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
        "name": "최고관리자",
        "nickname": "최고관리자",
        "email": "heuristing@gmail.com",
        "avatar": null,
        "language": "ko",
        "timezone": "Asia/Seoul",
        "country": null,
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "homepage": null,
        "mobile": null,
        "phone": null,
        "zipcode": null,
        "address": null,
        "address_detail": null,
        "signature": null,
        "bio": null,
        "is_super": true,
        "is_admin": true,
        "withdrawn_at": null,
        "last_login_at": "2026-08-04 19:00:10",
        "last_login_human": "2시간 전",
        "created_at": "2026-07-30 23:37:44",
        "is_owner": true,
        "notify_post_complete": false,
        "notify_post_reply": false,
        "notify_comment": false,
        "notify_reply_comment": false,
        "email_subscription": false,
        "email_subscription_at": null,
        "marketing_consent": false,
        "marketing_consent_at": null,
        "third_party_consent": false,
        "third_party_consent_at": null,
        "info_disclosure": false,
        "info_disclosure_at": null,
        "marketing_consent_enabled": true,
        "marketing_consent_terms_slug": "marketing-terms",
        "marketing_consent_terms_slug_set": true,
        "third_party_consent_enabled": true,
        "third_party_consent_terms_slug": null,
        "third_party_consent_terms_slug_set": false,
        "info_disclosure_enabled": true,
        "info_disclosure_terms_slug": null,
        "info_disclosure_terms_slug_set": false,
        "email_subscription_enabled": true,
        "email_subscription_terms_slug": null,
        "email_subscription_terms_slug_set": false,
        "channels": [
            {
                "key": "email_subscription",
                "label": "광고성 이메일 수신",
                "enabled": true,
                "terms_slug": null,
                "terms_slug_set": false
            }
        ],
        "consent_histories": [],
        "ecommerce_mileage": {
            "enabled": false
        },
        "ecommerce_preferred_currency": null,
        "ecommerce_preferred_shipping_country": null,
        "ecommerce_preferred_shipping_country_name": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

현재 로그인한 사용자의 프로필 정보를 조회한다. 프론트의 마이페이지(`mypage/profile.json`)와 프로필 수정 화면(`mypage/profile-edit.json`)이 소비한다. 응답은 `UserResource::toProfileArray()` 산물로, 비밀번호 등 민감 필드는 제외된다. 표의 `notify_*`(게시판 모듈)·`marketing_consent*`/`email_subscription*`/`third_party_consent*`/`info_disclosure*`/`channels`/`consent_histories`(마케팅 플러그인)·`ecommerce_*`(이커머스 모듈) 필드는 코어가 아니라 각 확장이 `core.user.filter_resource_data` 훅으로 병합하는 확장 소유 필드이며, 해당 확장이 비활성인 환경에서는 응답에 나타나지 않는다.


### PUT /api/me
<!-- @generated:start:api.me.update -->
- **라우트명**: `api.me.update`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@update`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | string | 예 | max 255 | 대상의 이름/명칭 |
| nickname | body | string | 아니오 | max 50 | 닉네임 |
| email | body | email | 예 | max 255 | 이메일 주소 |
| password | body | string | 아니오 | — | 비밀번호 |
| current_password | body | string | 아니오 | — | 현재 비밀번호 (변경 전 확인용) |
| language | body | string | 아니오 | — | 언어 코드 |
| country | body | string | 아니오 | — | 국가 코드 (ISO 3166-1 alpha-2) |
| timezone | body | string | 아니오 | — | 타임존 식별자 |
| homepage | body | string | 아니오 | max 255 | 홈페이지 URL |
| mobile | body | string | 아니오 | max 20 | 휴대전화 번호 |
| phone | body | string | 아니오 | max 20 | 전화번호 |
| zipcode | body | string | 아니오 | max 10 | 우편번호 |
| address | body | string | 아니오 | max 255 | 기본 주소 |
| address_detail | body | string | 아니오 | max 255 | 상세 주소 |
| signature | body | string | 아니오 | max 1000 | 서명 |
| bio | body | string | 아니오 | max 5000 | 자기소개 |
| notify_post_complete | body | boolean | 아니오 | — | 게시글 작성 완료 알림 수신 설정 (게시판 모듈 추가) |
| notify_post_reply | body | boolean | 아니오 | — | 내 게시글에 대한 답글 알림 수신 설정 (게시판 모듈 추가) |
| notify_comment | body | boolean | 아니오 | — | 내 게시글에 대한 댓글 알림 수신 설정 (게시판 모듈 추가) |
| notify_reply_comment | body | boolean | 아니오 | — | 내 댓글에 대한 대댓글 알림 수신 설정 (게시판 모듈 추가) |
| email_subscription | body | boolean | 아니오 | — | 광고성 이메일 수신 동의 (마케팅 플러그인 추가, 채널) |
| marketing_consent | body | boolean | 아니오 | — | 마케팅 정보 수신 전체 동의 마스터 키 (마케팅 플러그인 추가) |
| third_party_consent | body | boolean | 아니오 | — | 제3자 정보 제공 동의 (법적 항목, 마케팅 플러그인 추가) |
| info_disclosure | body | boolean | 아니오 | — | 개인정보 이용 안내 동의 (법적 항목, 마케팅 플러그인 추가) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.update_profile_validation_rules`).

**요청 예시**

```http
PUT /api/me HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": "예시 이름",
    "nickname": "예시 이름",
    "email": "user@example.com",
    "password": "Password123!",
    "current_password": "Password123!",
    "language": "ko",
    "country": "KR",
    "timezone": "Asia/Seoul",
    "homepage": "https://example.com",
    "mobile": "010-1234-5678",
    "phone": "010-1234-5678",
    "zipcode": "06234",
    "address": "서울특별시 강남구 테헤란로 1",
    "address_detail": "서울특별시 강남구 테헤란로 1",
    "signature": "예시값",
    "bio": "예시 내용입니다.",
    "notify_post_complete": true,
    "notify_post_reply": true,
    "notify_comment": true,
    "notify_reply_comment": true,
    "email_subscription": true,
    "marketing_consent": true,
    "third_party_consent": true,
    "info_disclosure": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`UserResource::toArray()` 산물 — GET /api/me 의 `toProfileArray()` 와 필드 구성이 다르다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `홍길동` | 사용자 이름 |
| nickname | string\|null | `gildong` | 닉네임 |
| email | string | `user@example.com` | 이메일 주소 |
| avatar | string\|null | `null` | 아바타 이미지 URL (`User::getAvatarUrl()` 산물, 미등록 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| language_label | string\|null | `한국어` | 언어 코드의 다국어 라벨 (`user.language.{code}` 번역문) |
| country | string\|null | `KR` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (active, inactive, blocked, withdrawn, pending_verification) |
| status_label | string\|null | `활성` | 상태의 사람이 읽는 라벨 (UserStatus::label() 산물) |
| status_variant | string\|null | `success` | 상태 표시 색상/스타일 변형 키 (UserStatus::variant() 산물 — UI 배지용) |
| is_admin | boolean | `false` | 관리자 역할 보유 여부 (`User::isAdmin()` — 역할 관계 기반 파생) |
| homepage | string\|null | `https://example.com` | 홈페이지 URL |
| mobile | string\|null | `010-1234-5678` | 휴대폰 번호 |
| phone | string\|null | `02-123-4567` | 전화번호 |
| zipcode | string\|null | `06234` | 우편번호 |
| address | string\|null | `서울특별시 강남구 테헤란로 1` | 기본 주소 |
| address_detail | string\|null | `101동 202호` | 상세 주소 |
| signature | string\|null | `null` | 서명 |
| bio | string\|null | `null` | 자기소개 |
| last_login_at | string\|null | `2026-07-07 10:41:24` | 마지막 로그인 일시 (사용자 시간대 기준 문자열) |
| email_verified_at | string\|null | `null` | 이메일 인증 일시 (미인증 시 null) |
| timezone | string\|null | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 (사용자 시간대 기준 문자열) |
| updated_at | string | `2026-07-08 11:02:10` | 수정 일시 (사용자 시간대 기준 문자열) |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":false,"can_create":false,"can_update":false,"can_delete":false,"can_assign_roles":false}` | 현재 사용자의 이 리소스에 대한 권한 맵 (core.users.read/create/update/delete 기준. `can_assign_roles` 는 `core.users.update` — 역할 부여는 사용자 관리의 일부. 슈퍼관리자 계정은 `can_delete` 가 항상 false) |

관계형 필드(`modules`, `plugins`, `menus`, `roles`, `permissions`, `consents`, `terms_consent`, `privacy_consent`)와 카운트 필드(`modules_count`, `plugins_count`, `menus_count`)는 해당 관계가 로드된 경우에만 응답에 포함된다 (프로필 수정 응답에서는 로드하지 않으므로 나타나지 않는다).

**응답 예시**

```json
{
    "success": true,
    "message": "사용자 정보가 성공적으로 업데이트되었습니다.",
    "data": {
        "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "name": "예시 이름",
        "nickname": "예시 이름",
        "email": "user@example.com",
        "avatar": null,
        "language": "ko",
        "language_label": "한국어",
        "country": "KR",
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "is_admin": false,
        "homepage": "https://example.com",
        "mobile": "010-1234-5678",
        "phone": "010-1234-5678",
        "zipcode": "06234",
        "address": "서울특별시 강남구 테헤란로 1",
        "address_detail": "서울특별시 강남구 테헤란로 1",
        "signature": "예시값",
        "bio": "예시 내용입니다.",
        "last_login_at": "2026-07-07 10:41:24",
        "email_verified_at": null,
        "timezone": "Asia/Seoul",
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 11:02:10",
        "is_owner": true,
        "abilities": {
            "can_read": false,
            "can_create": false,
            "can_update": false,
            "can_delete": false,
            "can_assign_roles": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

현재 로그인한 사용자가 자신의 프로필을 수정한다. 프론트의 프로필 수정 화면(`partials/mypage/profile/_edit.json`)이 사용한다. `name`·`email` 은 필수이며 `email` 은 본인을 제외한 중복이 허용되지 않는다. 비밀번호를 함께 변경하려면 `password`(+ `password_confirmation`)와 현재 비밀번호(`current_password`)를 함께 보내야 하며, `password` 가 빈 문자열이면 비밀번호 미변경으로 처리된다. `notify_*`·`marketing_consent`·`email_subscription` 등 확장 소유 파라미터는 게시판 모듈·마케팅 플러그인이 `core.user.update_profile_validation_rules` 훅으로 추가하며, 해당 확장이 비활성인 환경에서는 수용되지 않는다. 성공 시 갱신된 프로필이 `UserResource` 형태로 반환된다.


