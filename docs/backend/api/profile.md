# Profile API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Profile 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/user/profile
<!-- @generated:start:api.user.profile.show -->
- **라우트명**: `api.user.profile.show`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.profile.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/user/profile HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`UserResource::toProfileArray()` 산물)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a231747f-e82e-4cf2-9ae1-a261849dce40` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `홍길동` | 사용자 이름 |
| nickname | string\|null | `gildong` | 닉네임 (미설정 시 null) |
| email | string | `user@example.com` | 이메일 주소 |
| avatar | string\|null | `null` | 아바타 이미지 URL (`User::getAvatarUrl()` 산물, 미등록 시 null) |
| language | string | `ko` | 사용자 언어 설정 (`config('app.supported_locales')` 값 — 기본 `ko`, `en`) |
| timezone | string\|null | `Asia/Seoul` | 사용자 시간대 식별자 |
| country | string\|null | `KR` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (`active`, `inactive`, `blocked`, `withdrawn`, `pending_verification`) |
| status_label | string\|null | `활성` | 상태의 다국어 라벨 (`UserStatus::label()` 산물) |
| status_variant | string\|null | `success` | 상태 배지 색상 키 (`success`/`secondary`/`danger`/`warning`/`info`) |
| homepage | string\|null | `https://example.com` | 홈페이지 URL |
| mobile | string\|null | `010-1234-5678` | 휴대전화 번호 |
| phone | string\|null | `02-123-4567` | 전화번호 |
| zipcode | string\|null | `06234` | 우편번호 |
| address | string\|null | `서울특별시 강남구 테헤란로 1` | 기본 주소 |
| address_detail | string\|null | `101동 202호` | 상세 주소 |
| signature | string\|null | `null` | 게시글 서명 |
| bio | string\|null | `null` | 자기소개 |
| is_super | boolean | `false` | 슈퍼관리자 여부 (`User::isSuperAdmin()` 파생) |
| is_admin | boolean | `false` | 관리자 역할 보유 여부 (`User::isAdmin()` 파생) |
| withdrawn_at | string\|null | `null` | 탈퇴 처리 일시 (사용자 시간대 기준) |
| last_login_at | string\|null | `2026-07-05 19:15:16` | 마지막 로그인 일시 (사용자 시간대 기준) |
| last_login_human | string\|null | `1일 전` | 마지막 로그인 시각의 상대 표현 (`diffForHumans()` 산물) |
| created_at | string\|null | `2026-07-06 19:15:16` | 가입 일시 (사용자 시간대 기준) |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 |

> 위 필드에 더해 `core.user.filter_resource_data` 훅으로 확장 소유 필드가 병합된다 (게시판 모듈 `notify_*`, 마케팅 플러그인 `marketing_consent*`/`email_subscription*`/`third_party_consent*`/`info_disclosure*`/`channels`/`consent_histories`, 이커머스 모듈 `ecommerce_*`). 전체 목록은 `GET /api/me` 문서 참조.

**응답 예시**

```json
{
    "success": true,
    "message": "프로필 정보를 성공적으로 가져왔습니다.",
    "data": {
        "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "name": "홍길동",
        "nickname": "gildong",
        "email": "user@example.com",
        "avatar": null,
        "language": "ko",
        "timezone": "Asia/Seoul",
        "country": "KR",
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "homepage": null,
        "mobile": "010-1234-5678",
        "phone": null,
        "zipcode": "06234",
        "address": "서울특별시 강남구 테헤란로 1",
        "address_detail": "101동 202호",
        "signature": null,
        "bio": null,
        "is_super": false,
        "is_admin": false,
        "withdrawn_at": null,
        "last_login_at": "2026-07-05 19:15:16",
        "last_login_human": "1일 전",
        "created_at": "2026-07-06 19:15:16",
        "is_owner": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`core.profile.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

`GET /api/me` 와 동일하게 `ProfileController@show` 를 호출해 현재 사용자의 프로필을 조회하지만, `permission:core.profile.read` 권한 미들웨어가 추가된 경로다. 응답 형태는 `UserResource::toProfileArray()` 산물로 `GET /api/me` 와 같으며, 필드별 소유(확장 병합) 규칙도 동일하다. 실측 예시가 비어 있는 것은 문서 생성 시 샘플 사용자가 해당 권한을 갖지 못해 403 이 반환되었기 때문이며, 응답 필드는 `GET /api/me` 문서를 참조한다.


### PUT /api/user/profile
<!-- @generated:start:api.user.profile.update -->
- **라우트명**: `api.user.profile.update`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@update`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.profile.update`

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
| notify_post_complete | body | boolean | 아니오 | — | 내 글에 답변/처리 완료 시 알림 수신 여부 (게시판 모듈 알림 설정) |
| notify_post_reply | body | boolean | 아니오 | — | 내 글에 답글이 달렸을 때 알림 수신 여부 (게시판 모듈 알림 설정) |
| notify_comment | body | boolean | 아니오 | — | 내 글에 댓글이 달렸을 때 알림 수신 여부 (게시판 모듈 알림 설정) |
| notify_reply_comment | body | boolean | 아니오 | — | 내 댓글에 대댓글이 달렸을 때 알림 수신 여부 (게시판 모듈 알림 설정) |
| email_subscription | body | boolean | 아니오 | — | 광고성 이메일 수신 동의 여부 (sirsoft-marketing 채널, 훅 주입 파라미터) |
| marketing_consent | body | boolean | 아니오 | — | 마케팅 정보 수신 전체 동의 마스터 키 — 마케팅 채널 전체 동의/철회 제어 (sirsoft-marketing 훅 주입 파라미터) |
| third_party_consent | body | boolean | 아니오 | — | 제3자 정보 제공 동의 여부 (법적 필수 항목, sirsoft-marketing 훅 주입 파라미터) |
| info_disclosure | body | boolean | 아니오 | — | 개인정보 이용 안내 동의 여부 (법적 필수 항목, sirsoft-marketing 훅 주입 파라미터) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.update_profile_validation_rules`).

**요청 예시**

```http
PUT /api/user/profile HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
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

_단건 응답: `data` 객체의 필드 (`UserResource::toArray()` 산물 — `show` 의 `toProfileArray()` 와 필드 구성이 다르다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 외부 노출용 UUID |
| name | string | `홍길동` | 사용자 이름 |
| nickname | string\|null | `gildong` | 닉네임 |
| email | string | `user@example.com` | 이메일 주소 |
| avatar | string\|null | `null` | 아바타 이미지 URL (미등록 시 null) |
| language | string | `ko` | 사용자 언어 설정 |
| language_label | string\|null | `한국어` | 언어의 다국어 라벨 (`user.language.{code}` 번역) |
| country | string\|null | `KR` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (`active`, `inactive`, `blocked`, `withdrawn`, `pending_verification`) |
| status_label | string\|null | `활성` | 상태의 다국어 라벨 |
| status_variant | string\|null | `success` | 상태 배지 색상 키 (`success`/`secondary`/`danger`/`warning`/`info`) |
| is_admin | boolean | `false` | 관리자 역할 보유 여부 |
| homepage | string\|null | `https://example.com` | 홈페이지 URL |
| mobile | string\|null | `010-1234-5678` | 휴대전화 번호 |
| phone | string\|null | `02-123-4567` | 전화번호 |
| zipcode | string\|null | `06234` | 우편번호 |
| address | string\|null | `서울특별시 강남구 테헤란로 1` | 기본 주소 |
| address_detail | string\|null | `101동 202호` | 상세 주소 |
| signature | string\|null | `null` | 게시글 서명 |
| bio | string\|null | `null` | 자기소개 |
| last_login_at | string\|null | `2026-07-05 19:15:16` | 마지막 로그인 일시 (사용자 시간대 기준) |
| email_verified_at | string\|null | `null` | 이메일 인증 완료 일시 (미인증 시 null) |
| timezone | string\|null | `Asia/Seoul` | 사용자 시간대 식별자 |
| created_at | string\|null | `2026-07-06 19:15:16` | 가입 일시 |
| updated_at | string\|null | `2026-07-08 10:41:24` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 |
| abilities | object | `{"can_read":true,"can_update":true,...}` | 현재 사용자의 리소스 권한 맵 (`can_read`/`can_create`/`can_update`/`can_delete`/`can_assign_roles`) |

> `modules_count`/`plugins_count`/`menus_count`·`modules`/`plugins`/`menus`/`roles`/`permissions`/`consents`/`terms_consent`/`privacy_consent` 는 해당 관계가 로드된 경우에만 포함된다 (프로필 수정 응답에서는 로드되지 않아 생략). 확장 소유 필드는 `core.user.filter_resource_data` 훅으로 병합된다.

**응답 예시**

```json
{
    "success": true,
    "message": "사용자 정보가 성공적으로 업데이트되었습니다.",
    "data": {
        "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "name": "홍길동",
        "nickname": "gildong",
        "email": "user@example.com",
        "avatar": null,
        "language": "ko",
        "language_label": "한국어",
        "country": "KR",
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "is_admin": false,
        "homepage": null,
        "mobile": "010-1234-5678",
        "phone": null,
        "zipcode": "06234",
        "address": "서울특별시 강남구 테헤란로 1",
        "address_detail": "101동 202호",
        "signature": null,
        "bio": null,
        "last_login_at": "2026-07-05 19:15:16",
        "email_verified_at": null,
        "timezone": "Asia/Seoul",
        "created_at": "2026-07-06 19:15:16",
        "updated_at": "2026-07-08 10:41:24",
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
| 403 | Forbidden | 요구 권한(`core.profile.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

`PUT /api/me` 와 동일하게 `ProfileController@update` 를 호출해 프로필을 수정하되, `permission:core.profile.update` 권한 미들웨어가 추가된 경로다. 요청 파라미터와 검증 규칙(`UpdateProfileRequest`), 확장 소유 파라미터 병합(`core.user.update_profile_validation_rules`)은 `PUT /api/me` 와 동일하다. 성공 시 갱신된 프로필이 `UserResource` 형태로 반환된다.


### GET /api/user/profile/activity-log
<!-- @generated:start:api.user.profile.activity-log -->
- **라우트명**: `api.user.profile.activity-log`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@activityLog`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.profile.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/user/profile/activity-log HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체에 `activities` 배열이 담긴다 (페이지네이션 없음, 최신순 최대 50건)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| activities | array | `[{...}]` | 최근 활동 로그 목록 (최신순, 최대 50건) |
| activities[].id | integer | `1` | 활동 로그 기본 키 |
| activities[].action | string | `profile.update` | 활동 액션 키 (점 표기, 예: `profile.show`, `profile.update`, `auth.login`) |
| activities[].action_label | string | `프로필 수정` | 액션의 다국어 라벨 (`activity_log.action.*` 번역, 확장 액션은 확장 lang 우선) |
| activities[].description | string | `프로필 정보를 수정했습니다.` | 활동 설명 (현재 로케일 기준 렌더 결과, `description_key` 미설정 시 빈 문자열) |
| activities[].ip_address | string\|null | `127.0.0.1` | 활동을 수행한 클라이언트 IP |
| activities[].created_at | string\|null | `2026-07-06T19:15:16+09:00` | 활동 발생 일시 (ISO 8601) |

**응답 예시**

```json
{
    "success": true,
    "message": "활동 기록을 성공적으로 가져왔습니다.",
    "data": {
        "activities": [
            {
                "id": 1024,
                "action": "profile.update",
                "action_label": "프로필 수정",
                "description": "프로필 정보를 수정했습니다.",
                "ip_address": "127.0.0.1",
                "created_at": "2026-07-06T19:15:16+09:00"
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`core.profile.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

현재 사용자의 최근 활동 로그를 조회한다(`permission:core.profile.read` 필요). `data.activities` 에 최대 50건의 로그가 최신순으로 담기며, 각 항목은 `id`·`action`·`action_label`·`description`(로케일 반영)·`ip_address`·`created_at`(ISO 8601) 필드를 가진다. 실측 예시가 비어 있는 것은 문서 생성 시 샘플 사용자가 권한을 갖지 못해 403 이 반환되었기 때문이다.


### POST /api/user/profile/update-language
<!-- @generated:start:api.user.profile.update-language -->
- **라우트명**: `api.user.profile.update-language`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@updateLanguage`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/user/profile/update-language HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`UserResource::toArray()` 산물 — `PUT /api/user/profile` 응답과 동일 구조)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 외부 노출용 UUID |
| name | string | `홍길동` | 사용자 이름 |
| nickname | string\|null | `gildong` | 닉네임 |
| email | string | `user@example.com` | 이메일 주소 |
| avatar | string\|null | `null` | 아바타 이미지 URL |
| language | string | `en` | 변경된 사용자 언어 설정 |
| language_label | string\|null | `English` | 언어의 다국어 라벨 (`user.language.{code}` 번역) |
| country | string\|null | `KR` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 |
| status_label | string\|null | `활성` | 상태의 다국어 라벨 |
| status_variant | string\|null | `success` | 상태 배지 색상 키 |
| is_admin | boolean | `false` | 관리자 역할 보유 여부 |
| homepage | string\|null | `null` | 홈페이지 URL |
| mobile | string\|null | `010-1234-5678` | 휴대전화 번호 |
| phone | string\|null | `null` | 전화번호 |
| zipcode | string\|null | `06234` | 우편번호 |
| address | string\|null | `서울특별시 강남구 테헤란로 1` | 기본 주소 |
| address_detail | string\|null | `101동 202호` | 상세 주소 |
| signature | string\|null | `null` | 게시글 서명 |
| bio | string\|null | `null` | 자기소개 |
| last_login_at | string\|null | `2026-07-05 19:15:16` | 마지막 로그인 일시 |
| email_verified_at | string\|null | `null` | 이메일 인증 완료 일시 |
| timezone | string\|null | `Asia/Seoul` | 사용자 시간대 식별자 |
| created_at | string\|null | `2026-07-06 19:15:16` | 가입 일시 |
| updated_at | string\|null | `2026-07-08 10:41:24` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 |
| abilities | object | `{"can_read":false,...}` | 현재 사용자의 리소스 권한 맵 |

**응답 예시**

```json
{
    "success": true,
    "message": "언어 설정이 성공적으로 변경되었습니다.",
    "data": {
        "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "name": "홍길동",
        "nickname": "gildong",
        "email": "user@example.com",
        "avatar": null,
        "language": "en",
        "language_label": "English",
        "country": "KR",
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "is_admin": false,
        "homepage": null,
        "mobile": "010-1234-5678",
        "phone": null,
        "zipcode": "06234",
        "address": "서울특별시 강남구 테헤란로 1",
        "address_detail": "101동 202호",
        "signature": null,
        "bio": null,
        "last_login_at": "2026-07-05 19:15:16",
        "email_verified_at": null,
        "timezone": "Asia/Seoul",
        "created_at": "2026-07-06 19:15:16",
        "updated_at": "2026-07-08 10:41:24",
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
| 403 | Forbidden | 요구 권한(`core.profile.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

현재 사용자의 언어 설정만 변경한다. 요청 본문의 `language` 값을 받아 `config('app.supported_locales')`(기본 `['ko','en']`)에 포함되는지 검사하며, 허용되지 않는 값이면 400 을 반환한다. 프로필 전체 수정 없이 언어만 즉시 전환할 때 사용하며, 성공 시 갱신된 사용자 정보가 `UserResource` 형태로 반환된다. `language` 는 FormRequest 가 아닌 컨트롤러에서 직접 읽어 검증하므로 문서 상단 파라미터 표에는 자동 수집되지 않는다.


