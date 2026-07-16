# Me API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Me 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-500 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

현재 로그인한 사용자가 자신의 계정을 탈퇴한다. 프론트의 회원 탈퇴 모달(`_modal_withdraw.json`)이 호출한다. 별도 요청 파라미터 없이 인증 토큰의 사용자를 대상으로 하며, `UserService::withdrawUser()` 가 아바타·토큰 삭제와 개인정보 익명화를 수행한다. 되돌릴 수 없는 작업이므로 호출 전 사용자 확인 절차를 두는 것을 권장한다.


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
| uuid | string | `a231747f-e82e-4cf2-9ae1-a261849dce40` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `API 문서 샘플 사용자` | 사용자 이름 |
| nickname | string | `gunwoo.oh` | 닉네임 |
| email | string | `apidoc-sample-user@example.com` | 이메일 주소 |
| avatar | null | `null` | 아바타 이미지 URL (User::getAvatarUrl() 산물, 미등록 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| timezone | string | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| country | string | `KR` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| homepage | string | `https://example.com` | 홈페이지 URL |
| mobile | string | `010-9070-5662` | 휴대폰 번호 |
| phone | string | `02-805-4759` | 전화번호 |
| zipcode | string | `93153` | 우편번호 |
| address | string | `대구광역시 북구 백제고분로 720` | 기본 주소 |
| address_detail | string | `40동 835호` | 상세 주소 |
| signature | string | `Ipsam rem amet expedita est.` | 서명 |
| bio | string | `Tenetur omnis et amet omnis veniam to…` | 자기소개 |
| is_super | boolean | `false` | super 여부 |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생) |
| withdrawn_at | null | `null` | withdrawn 일시 |
| last_login_at | string | `2026-07-05 19:15:16` | last login 일시 |
| last_login_human | string | `1일 전` | 마지막 로그인 시각의 상대 표현 (diffForHumans() 산물, 사용자 시간대 기준) |
| created_at | string | `2026-07-06 19:15:16` | 생성 일시 |
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
| ecommerce_preferred_currency | null | `null` | 선호 결제 통화 (이커머스 모듈 주입, 미설정 시 null) |
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
        "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "name": "API 문서 샘플 사용자",
        "nickname": "song.hyunji",
        "email": "apidoc-sample-user@example.com",
        "avatar": null,
        "language": "ko",
        "timezone": "Asia/Seoul",
        "country": "KR",
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "homepage": "https://example.com",
        "mobile": "010-9595-2897",
        "phone": "02-637-5618",
        "zipcode": "16505",
        "address": "경기도 안양시 봉은사로 2918",
        "address_detail": "48동 718호",
        "signature": "Fugit consequuntur repellendus sed.",
        "bio": "Ut magni et sunt ducimus error adipisci. Pariatur corporis voluptatem ratione quo non saepe. Illo atque praesentium possimus dolores qui est fugit. Sint fugiat numquam voluptates.",
        "is_super": false,
        "is_admin": true,
        "withdrawn_at": null,
        "last_login_at": "2026-07-07 10:41:24",
        "last_login_human": "1일 전",
        "created_at": "2026-07-08 10:41:24",
        "is_owner": true,
        "notify_post_complete": false,
        "notify_post_reply": false,
        "notify_comment": false,
        "notify_reply_comment": false,
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
    "notify_reply_comment": true
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

현재 로그인한 사용자가 자신의 프로필을 수정한다. 프론트의 프로필 수정 화면(`partials/mypage/profile/_edit.json`)이 사용한다. `name`·`email` 은 필수이며 `email` 은 본인을 제외한 중복이 허용되지 않는다. 비밀번호를 함께 변경하려면 `password`(+ `password_confirmation`)와 현재 비밀번호(`current_password`)를 함께 보내야 하며, `password` 가 빈 문자열이면 비밀번호 미변경으로 처리된다. `notify_*`·`marketing_consent`·`email_subscription` 등 확장 소유 파라미터는 게시판 모듈·마케팅 플러그인이 `core.user.update_profile_validation_rules` 훅으로 추가하며, 해당 확장이 비활성인 환경에서는 수용되지 않는다. 성공 시 갱신된 프로필이 `UserResource` 형태로 반환된다.


