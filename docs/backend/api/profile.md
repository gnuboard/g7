# Profile API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Profile 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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

<!-- 실측 제외: http-403 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

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
    "notify_reply_comment": true
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

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

<!-- 실측 제외: http-403 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `language` 값이 `config('app.supported_locales')`(기본 `['ko','en']`)에 포함되지 않는 미지원 로케일인 경우 |

<!-- @generated:end -->

**설명**

현재 사용자의 언어 설정만 변경한다. 요청 본문의 `language` 값을 받아 `config('app.supported_locales')`(기본 `['ko','en']`)에 포함되는지 검사하며, 허용되지 않는 값이면 400 을 반환한다. 프로필 전체 수정 없이 언어만 즉시 전환할 때 사용하며, 성공 시 갱신된 사용자 정보가 `UserResource` 형태로 반환된다. `language` 는 FormRequest 가 아닌 컨트롤러에서 직접 읽어 검증하므로 문서 상단 파라미터 표에는 자동 수집되지 않는다.


