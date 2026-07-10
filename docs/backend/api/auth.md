# Auth API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Auth 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/admin/auth/logout
<!-- @generated:start:api.admin.auth.logout -->
- **라우트명**: `api.admin.auth.logout`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AuthController@logout`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/auth/logout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "로그아웃이 성공했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

현재 관리자의 Sanctum 토큰을 폐기해 로그아웃한다. `AuthService::logout()` 이 3단계(토큰 삭제 → 세션 무효화 → `Auth::logout()`)를 수행하며, `data` 는 없고 `message` 만 `auth.logout_success` 로 내려온다. 프론트는 응답 후 저장된 Bearer 토큰을 폐기하고 로그인 화면으로 전환한다.


### POST /api/admin/auth/refresh
<!-- @generated:start:api.admin.auth.refresh -->
- **라우트명**: `api.admin.auth.refresh`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AuthController@refresh`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/auth/refresh HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| user | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 대상 사용자 정보 객체 (uuid/name/email 등 — user 관계 파생) |
| token | string | `75\|WgPUplvLGTv8YIj4507uIR6dEOHTXyNUed…` | 발급된 API 접근 토큰 평문 (Bearer 토큰으로 사용, 발급 시 1회만 노출) |
| token_type | string | `Bearer` | 토큰 타입 (일반적으로 Bearer) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "nickname": "song.hyunji",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "language": "ko",
            "language_label": "한국어",
            "country": "KR",
            "status": "active",
            "status_label": "활성",
            "status_variant": "success",
            "is_admin": true,
            "homepage": "https://example.com",
            "mobile": "010-9595-2897",
            "phone": "02-637-5618",
            "zipcode": "16505",
            "address": "경기도 안양시 봉은사로 2918",
            "address_detail": "48동 718호",
            "signature": "Fugit consequuntur repellendus sed.",
            "bio": "Ut magni et sunt ducimus error adipisci. Pariatur corporis voluptatem ratione quo non saepe. Illo atque praesentium possimus dolores qui est fugit. Sint fugiat numquam voluptates.",
            "last_login_at": "2026-07-07 10:41:24",
            "email_verified_at": "2026-07-08 10:41:24",
            "timezone": "Asia/Seoul",
            "created_at": "2026-07-08 10:41:24",
            "updated_at": "2026-07-08 10:41:24",
            "is_owner": true,
            "abilities": {
                "can_read": true,
                "can_create": true,
                "can_update": true,
                "can_delete": true,
                "can_assign_roles": true
            }
        },
        "token": "{MASKED}",
        "token_type": "Bearer"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

현재 관리자 토큰을 새 Sanctum 토큰으로 교체한다. `AuthService::refreshToken()` 이 기존 토큰을 폐기하고 새 토큰을 발급하며, `data` 에는 새 `token` 과 `user`(UserResource) 가 담긴다. 만료 임박 토큰을 재발급하는 용도로, 세션 만료로 재인증이 필요한 경우(토큰 무효)에는 `401 auth.unauthenticated` 를 반환한다.


### GET /api/admin/auth/user
<!-- @generated:start:api.admin.auth.user -->
- **라우트명**: `api.admin.auth.user`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AuthController@user`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/auth/user HTTP/1.1
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
| avatar | null | `null` | 아바타 이미지 URL (User::getAvatarUrl() — 아바타 미설정 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (user.language.{code} 번역) |
| country | string | `KR` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생) |
| homepage | string | `https://example.com` | 홈페이지 URL |
| mobile | string | `010-9070-5662` | 휴대폰 번호 |
| phone | string | `02-805-4759` | 전화번호 |
| zipcode | string | `93153` | 우편번호 |
| address | string | `대구광역시 북구 백제고분로 720` | 기본 주소 |
| address_detail | string | `40동 835호` | 상세 주소 |
| signature | string | `Ipsam rem amet expedita est.` | 서명 |
| bio | string | `Tenetur omnis et amet omnis veniam to…` | 자기소개 |
| last_login_at | string | `2026-07-05 19:15:16` | last login 일시 |
| email_verified_at | string | `2026-07-06 19:15:16` | email verified 일시 |
| timezone | string | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| roles | array | `[{"id":1,"identifier":"admin","name":"관리자"}]` | 사용자에게 부여된 역할 목록 (원소 id/identifier/name — roles 관계 파생, name 은 현지화 라벨) |
| permissions | array | `[{"id":3,"identifier":"core.users.read","name":"사용자 조회"},…` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| created_at | string | `2026-07-06 19:15:16` | 생성 일시 |
| updated_at | string | `2026-07-06 19:15:16` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "name": "API 문서 샘플 사용자",
        "nickname": "song.hyunji",
        "email": "apidoc-sample-user@example.com",
        "avatar": null,
        "language": "ko",
        "language_label": "한국어",
        "country": "KR",
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "is_admin": true,
        "homepage": "https://example.com",
        "mobile": "010-9595-2897",
        "phone": "02-637-5618",
        "zipcode": "16505",
        "address": "경기도 안양시 봉은사로 2918",
        "address_detail": "48동 718호",
        "signature": "Fugit consequuntur repellendus sed.",
        "bio": "Ut magni et sunt ducimus error adipisci. Pariatur corporis voluptatem ratione quo non saepe. Illo atque praesentium possimus dolores qui est fugit. Sint fugiat numquam voluptates.",
        "last_login_at": "2026-07-07 10:41:24",
        "email_verified_at": "2026-07-08 10:41:24",
        "timezone": "Asia/Seoul",
        "roles": [
            {
                "id": 1,
                "identifier": "admin",
                "name": "관리자"
            },
            {
                "id": 4,
                "identifier": "apidoc-sample-role",
                "name": "API 문서 샘플 역할"
            }
        ],
        "permissions": [
            {
                "id": 5,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.posts.read",
                "name": "게시글 조회 (관리자)"
            },
            {
                "id": 6,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.posts.write",
                "name": "게시글 작성/수정/삭제 (관리자)"
            },
            {
                "id": 7,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.posts.read-secret",
                "name": "비밀글 조회 (관리자)"
            },
            {
                "id": 8,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.comments.read",
                "name": "댓글 조회 (관리자)"
            },
            {
                "id": 9,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.comments.write",
                "name": "댓글 작성/수정/삭제 (관리자)"
            },
            {
                "id": 10,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.attachments.upload",
                "name": "파일 업로드/삭제 (관리자)"
            },
            {
                "id": 11,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.attachments.download",
                "name": "파일 다운로드 (관리자)"
            },
            {
                "id": 12,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.manage",
                "name": "게시판 관리 (관리자)"
            },
            {
                "id": 13,
                "identifier": "sirsoft-board.apidoc-sample-board.posts.read",
                "name": "게시글 조회"
            },
            {
                "id": 14,
                "identifier": "sirsoft-board.apidoc-sample-board.posts.write",
                "name": "게시글 작성/수정/삭제"
            },
            {
                "id": 15,
                "identifier": "sirsoft-board.apidoc-sample-board.posts.read-secret",
                "name": "비밀글 조회"
            },
            {
                "id": 16,
                "identifier": "sirsoft-board.apidoc-sample-board.comments.read",
                "name": "댓글 조회"
            },
            {
                "id": 17,
                "identifier": "sirsoft-board.apidoc-sample-board.comments.write",
                "name": "댓글 작성/수정/삭제"
            },
            {
                "id": 18,
                "identifier": "sirsoft-board.apidoc-sample-board.attachments.upload",
                "name": "파일 업로드/삭제"
            },
            {
                "id": 19,
                "identifier": "sirsoft-board.apidoc-sample-board.attachments.download",
                "name": "파일 다운로드"
            },
            {
                "id": 20,
                "identifier": "sirsoft-board.apidoc-sample-board.manager",
                "name": "게시판 관리 (사용자)"
            },
            {
                "id": 81,
                "identifier": "sirsoft-ecommerce.user-products.read",
                "name": "상품 조회"
            },
            {
                "id": 83,
                "identifier": "sirsoft-ecommerce.user-orders.create",
                "name": "주문하기"
            },
            {
                "id": 84,
                "identifier": "sirsoft-ecommerce.user-orders.cancel",
                "name": "주문 취소"
            },
            {
                "id": 85,
                "identifier": "sirsoft-ecommerce.user-orders.confirm",
                "name": "구매확정"
            },
            {
                "id": 87,
                "identifier": "sirsoft-ecommerce.user-reviews.write",
                "name": "리뷰 작성"
            },
            {
                "id": 23,
                "identifier": "sirsoft-ecommerce.products.read",
                "name": "상품 조회"
            },
            {
                "id": 24,
                "identifier": "sirsoft-ecommerce.products.create",
                "name": "상품 생성"
            },
            {
                "id": 25,
                "identifier": "sirsoft-ecommerce.products.update",
                "name": "상품 수정"
            },
            {
                "id": 26,
                "identifier": "sirsoft-ecommerce.products.delete",
                "name": "상품 삭제"
            },
            {
                "id": 28,
                "identifier": "sirsoft-ecommerce.orders.read",
                "name": "주문 조회"
            },
            {
                "id": 29,
                "identifier": "sirsoft-ecommerce.orders.update",
                "name": "주문 수정"
            },
            {
                "id": 31,
                "identifier": "sirsoft-ecommerce.categories.read",
                "name": "카테고리 조회"
            },
            {
                "id": 32,
                "identifier": "sirsoft-ecommerce.categories.create",
                "name": "카테고리 생성"
            },
            {
                "id": 33,
                "identifier": "sirsoft-ecommerce.categories.update",
                "name": "카테고리 수정"
            },
            {
                "id": 34,
                "identifier": "sirsoft-ecommerce.categories.delete",
                "name": "카테고리 삭제"
            },
            {
                "id": 36,
                "identifier": "sirsoft-ecommerce.brands.read",
                "name": "브랜드 조회"
            },
            {
                "id": 37,
                "identifier": "sirsoft-ecommerce.brands.create",
                "name": "브랜드 생성"
            },
            {
                "id": 38,
                "identifier": "sirsoft-ecommerce.brands.update",
                "name": "브랜드 수정"
            },
            {
                "id": 39,
                "identifier": "sirsoft-ecommerce.brands.delete",
                "name": "브랜드 삭제"
            },
            {
                "id": 41,
                "identifier": "sirsoft-ecommerce.product-notice-templates.read",
                "name": "조회"
            },
            {
                "id": 42,
                "identifier": "sirsoft-ecommerce.product-notice-templates.create",
                "name": "생성"
            },
            {
                "id": 43,
                "identifier": "sirsoft-ecommerce.product-notice-templates.update",
                "name": "수정"
            },
            {
                "id": 44,
                "identifier": "sirsoft-ecommerce.product-notice-templates.delete",
                "name": "삭제"
            },
            {
                "id": 46,
                "identifier": "sirsoft-ecommerce.product-common-infos.read",
                "name": "조회"
            },
            {
                "id": 47,
                "identifier": "sirsoft-ecommerce.product-common-infos.create",
                "name": "생성"
            },
            {
                "id": 48,
                "identifier": "sirsoft-ecommerce.product-common-infos.update",
                "name": "수정"
            },
            {
                "id": 49,
                "identifier": "sirsoft-ecommerce.product-common-infos.delete",
                "name": "삭제"
            },
            {
                "id": 51,
                "identifier": "sirsoft-ecommerce.settings.read",
                "name": "환경설정 조회"
            },
            {
                "id": 52,
                "identifier": "sirsoft-ecommerce.settings.update",
                "name": "환경설정 수정"
            },
            {
                "id": 54,
                "identifier": "sirsoft-ecommerce.promotion-coupon.read",
                "name": "쿠폰 조회"
            },
            {
                "id": 55,
                "identifier": "sirsoft-ecommerce.promotion-coupon.create",
                "name": "쿠폰 생성"
            },
            {
                "id": 56,
                "identifier": "sirsoft-ecommerce.promotion-coupon.update",
                "name": "쿠폰 수정"
            },
            {
                "id": 57,
                "identifier": "sirsoft-ecommerce.promotion-coupon.delete",
                "name": "쿠폰 삭제"
            },
            {
                "id": 59,
                "identifier": "sirsoft-ecommerce.shipping-policies.read",
                "name": "배송정책 조회"
            },
            {
                "id": 60,
                "identifier": "sirsoft-ecommerce.shipping-policies.create",
                "name": "배송정책 생성"
            },
            {
                "id": 61,
                "identifier": "sirsoft-ecommerce.shipping-policies.update",
                "name": "배송정책 수정"
            },
            {
                "id": 62,
                "identifier": "sirsoft-ecommerce.shipping-policies.delete",
                "name": "배송정책 삭제"
            },
            {
                "id": 64,
                "identifier": "sirsoft-ecommerce.product-labels.read",
                "name": "상품 라벨 조회"
            },
            {
                "id": 65,
                "identifier": "sirsoft-ecommerce.product-labels.create",
                "name": "상품 라벨 생성"
            },
            {
                "id": 66,
                "identifier": "sirsoft-ecommerce.product-labels.update",
                "name": "상품 라벨 수정"
            },
            {
                "id": 67,
                "identifier": "sirsoft-ecommerce.product-labels.delete",
                "name": "상품 라벨 삭제"
            },
            {
                "id": 69,
                "identifier": "sirsoft-ecommerce.identity.policies.read",
                "name": "본인인증 정책 조회"
            },
            {
                "id": 70,
                "identifier": "sirsoft-ecommerce.identity.policies.update",
                "name": "본인인증 정책 수정"
            },
            {
                "id": 72,
                "identifier": "sirsoft-ecommerce.reviews.read",
                "name": "리뷰 조회"
            },
            {
                "id": 73,
                "identifier": "sirsoft-ecommerce.reviews.update",
                "name": "리뷰 처리"
            },
            {
                "id": 74,
                "identifier": "sirsoft-ecommerce.reviews.delete",
                "name": "리뷰 삭제"
            },
            {
                "id": 76,
                "identifier": "sirsoft-ecommerce.inquiries.update",
                "name": "문의 처리"
            },
            {
                "id": 77,
                "identifier": "sirsoft-ecommerce.inquiries.delete",
                "name": "문의 삭제"
            },
            {
                "id": 79,
                "identifier": "sirsoft-ecommerce.dashboard.view",
                "name": "대시보드 조회"
            },
            {
                "id": 89,
                "identifier": "sirsoft-ecommerce.mileage.read",
                "name": "마일리지 내역 조회"
            },
            {
                "id": 90,
                "identifier": "sirsoft-ecommerce.mileage.manage",
                "name": "마일리지 수동 처리"
            },
            {
                "id": 92,
                "identifier": "sirsoft-ecommerce.user-currency.manage",
                "name": "회원 결제 통화 변경"
            },
            {
                "id": 94,
                "identifier": "sirsoft-ecommerce.user-shipping-country.manage",
                "name": "회원 배송국가 변경"
            },
            {
                "id": 96,
                "identifier": "sirsoft-board.boards.read",
                "name": "게시판 조회"
            },
            {
                "id": 97,
                "identifier": "sirsoft-board.boards.create",
                "name": "게시판 생성"
            },
            {
                "id": 98,
                "identifier": "sirsoft-board.boards.update",
                "name": "게시판 수정"
            },
            {
                "id": 99,
                "identifier": "sirsoft-board.boards.delete",
                "name": "게시판 삭제"
            },
            {
                "id": 101,
                "identifier": "sirsoft-board.settings.read",
                "name": "환경설정 조회"
            },
            {
                "id": 102,
                "identifier": "sirsoft-board.settings.update",
                "name": "환경설정 수정"
            },
            {
                "id": 104,
                "identifier": "sirsoft-board.identity.policies.read",
                "name": "본인인증 정책 조회"
            },
            {
                "id": 105,
                "identifier": "sirsoft-board.identity.policies.update",
                "name": "본인인증 정책 수정"
            },
            {
                "id": 107,
                "identifier": "sirsoft-board.reports.view",
                "name": "신고 조회"
            },
            {
                "id": 108,
                "identifier": "sirsoft-board.reports.manage",
                "name": "신고 처리"
            },
            {
                "id": 111,
                "identifier": "sirsoft-page.pages.read",
                "name": "페이지 조회"
            },
            {
                "id": 112,
                "identifier": "sirsoft-page.pages.create",
                "name": "페이지 생성"
            },
            {
                "id": 113,
                "identifier": "sirsoft-page.pages.update",
                "name": "페이지 수정"
            },
            {
                "id": 114,
                "identifier": "sirsoft-page.pages.delete",
                "name": "페이지 삭제"
            },
            {
                "id": 117,
                "identifier": "core.users.read",
                "name": "사용자 조회"
            },
            {
                "id": 118,
                "identifier": "core.users.create",
                "name": "사용자 생성"
            },
            {
                "id": 119,
                "identifier": "core.users.update",
                "name": "사용자 수정"
            },
            {
                "id": 120,
                "identifier": "core.users.delete",
                "name": "사용자 삭제"
            },
            {
                "id": 122,
                "identifier": "core.menus.read",
                "name": "메뉴 조회"
            },
            {
                "id": 123,
                "identifier": "core.menus.create",
                "name": "메뉴 생성"
            },
            {
                "id": 124,
                "identifier": "core.menus.update",
                "name": "메뉴 수정"
            },
            {
                "id": 125,
                "identifier": "core.menus.delete",
                "name": "메뉴 삭제"
            },
            {
                "id": 127,
                "identifier": "core.modules.read",
                "name": "모듈 조회"
            },
            {
                "id": 128,
                "identifier": "core.modules.install",
                "name": "모듈 설치"
            },
            {
                "id": 129,
                "identifier": "core.modules.activate",
                "name": "모듈 활성화"
            },
            {
                "id": 130,
                "identifier": "core.modules.uninstall",
                "name": "모듈 삭제"
            },
            {
                "id": 132,
                "identifier": "core.plugins.read",
                "name": "플러그인 조회"
            },
            {
                "id": 133,
                "identifier": "core.plugins.install",
                "name": "플러그인 설치"
            },
            {
                "id": 134,
                "identifier": "core.plugins.activate",
                "name": "플러그인 활성화"
            },
            {
                "id": 135,
                "identifier": "core.plugins.update",
                "name": "플러그인 설정"
            },
            {
                "id": 136,
                "identifier": "core.plugins.uninstall",
                "name": "플러그인 삭제"
            },
            {
                "id": 138,
                "identifier": "core.templates.read",
                "name": "템플릿 조회"
            },
            {
                "id": 139,
                "identifier": "core.templates.install",
                "name": "템플릿 설치"
            },
            {
                "id": 140,
                "identifier": "core.templates.activate",
                "name": "템플릿 활성화"
            },
            {
                "id": 141,
                "identifier": "core.templates.uninstall",
                "name": "템플릿 삭제"
            },
            {
                "id": 142,
                "identifier": "core.templates.layouts.edit",
                "name": "레이아웃 편집"
            },
            {
                "id": 144,
                "identifier": "core.permissions.read",
                "name": "권한 조회"
            },
            {
                "id": 145,
                "identifier": "core.permissions.create",
                "name": "역할 생성"
            },
            {
                "id": 146,
                "identifier": "core.permissions.update",
                "name": "역할 수정"
            },
            {
                "id": 147,
                "identifier": "core.permissions.delete",
                "name": "역할 삭제"
            },
            {
                "id": 149,
                "identifier": "core.notification-logs.read",
                "name": "발송 이력 조회"
            },
            {
                "id": 150,
                "identifier": "core.notification-logs.delete",
                "name": "발송 이력 삭제"
            },
            {
                "id": 152,
                "identifier": "core.notifications.read",
                "name": "알림 조회"
            },
            {
                "id": 153,
                "identifier": "core.notifications.update",
                "name": "알림 읽음 처리"
            },
            {
                "id": 154,
                "identifier": "core.notifications.delete",
                "name": "알림 삭제"
            },
            {
                "id": 156,
                "identifier": "core.user-notifications.read",
                "name": "알림 조회"
            },
            {
                "id": 157,
                "identifier": "core.user-notifications.update",
                "name": "알림 읽음 처리"
            },
            {
                "id": 158,
                "identifier": "core.user-notifications.delete",
                "name": "알림 삭제"
            },
            {
                "id": 160,
                "identifier": "core.identity.request",
                "name": "IDV 요청"
            },
            {
                "id": 161,
                "identifier": "core.identity.verify",
                "name": "IDV 검증"
            },
            {
                "id": 162,
                "identifier": "core.identity.cancel",
                "name": "IDV 취소"
            },
            {
                "id": 164,
                "identifier": "core.admin.identity.providers.read",
                "name": "프로바이더 설정 조회"
            },
            {
                "id": 165,
                "identifier": "core.admin.identity.providers.update",
                "name": "프로바이더 설정 수정"
            },
            {
                "id": 166,
                "identifier": "core.admin.identity.policies.read",
                "name": "정책 조회"
            },
            {
                "id": 167,
                "identifier": "core.admin.identity.policies.update",
                "name": "정책 수정"
            },
            {
                "id": 168,
                "identifier": "core.admin.identity.logs.read",
                "name": "로그 열람"
            },
            {
                "id": 169,
                "identifier": "core.admin.identity.logs.purge",
                "name": "로그 파기"
            },
            {
                "id": 170,
                "identifier": "core.admin.identity.messages.read",
                "name": "메시지 템플릿 조회"
            },
            {
                "id": 171,
                "identifier": "core.admin.identity.messages.update",
                "name": "메시지 템플릿 수정"
            },
            {
                "id": 173,
                "identifier": "core.settings.read",
                "name": "설정 조회"
            },
            {
                "id": 174,
                "identifier": "core.settings.update",
                "name": "설정 수정"
            },
            {
                "id": 176,
                "identifier": "core.dashboard.read",
                "name": "대시보드 조회"
            },
            {
                "id": 177,
                "identifier": "core.dashboard.system-status",
                "name": "시스템 상태"
            },
            {
                "id": 178,
                "identifier": "core.dashboard.resources",
                "name": "시스템 리소스"
            },
            {
                "id": 179,
                "identifier": "core.dashboard.activities",
                "name": "최근 활동"
            },
            {
                "id": 180,
                "identifier": "core.dashboard.alerts",
                "name": "시스템 알림"
            },
            {
                "id": 182,
                "identifier": "core.activities.read",
                "name": "활동 로그 조회"
            },
            {
                "id": 183,
                "identifier": "core.activities.delete",
                "name": "활동 로그 삭제"
            },
            {
                "id": 185,
                "identifier": "core.attachments.create",
                "name": "첨부파일 업로드"
            },
            {
                "id": 186,
                "identifier": "core.attachments.update",
                "name": "첨부파일 수정"
            },
            {
                "id": 187,
                "identifier": "core.attachments.delete",
                "name": "첨부파일 삭제"
            },
            {
                "id": 189,
                "identifier": "core.schedules.read",
                "name": "스케줄 조회"
            },
            {
                "id": 190,
                "identifier": "core.schedules.create",
                "name": "스케줄 생성"
            },
            {
                "id": 191,
                "identifier": "core.schedules.update",
                "name": "스케줄 수정"
            },
            {
                "id": 192,
                "identifier": "core.schedules.delete",
                "name": "스케줄 삭제"
            },
            {
                "id": 193,
                "identifier": "core.schedules.run",
                "name": "스케줄 실행"
            },
            {
                "id": 195,
                "identifier": "core.language_packs.read",
                "name": "언어팩 조회"
            },
            {
                "id": 196,
                "identifier": "core.language_packs.install",
                "name": "언어팩 설치"
            },
            {
                "id": 197,
                "identifier": "core.language_packs.manage",
                "name": "언어팩 관리"
            },
            {
                "id": 198,
                "identifier": "core.language_packs.update",
                "name": "언어팩 업데이트"
            },
            {
                "id": 1,
                "identifier": "apidoc-sample.parent",
                "name": "API 문서 샘플 권한"
            },
            {
                "id": 2,
                "identifier": "apidoc-sample.child",
                "name": "하위 권한"
            },
            {
                "id": 3,
                "identifier": "sirsoft-board",
                "name": "게시판"
            },
            {
                "id": 21,
                "identifier": "sirsoft-ecommerce",
                "name": "이커머스"
            },
            {
                "id": 109,
                "identifier": "sirsoft-page",
                "name": "페이지"
            },
            {
                "id": 4,
                "identifier": "sirsoft-board.apidoc-sample-board",
                "name": "API 문서 샘플 게시판 게시판"
            },
            {
                "id": 95,
                "identifier": "sirsoft-board.boards",
                "name": "게시판 관리"
            },
            {
                "id": 100,
                "identifier": "sirsoft-board.settings",
                "name": "환경설정"
            },
            {
                "id": 103,
                "identifier": "sirsoft-board.identity.policies",
                "name": "게시판 본인인증 정책"
            },
            {
                "id": 106,
                "identifier": "sirsoft-board.reports",
                "name": "게시판 신고 관리"
            },
            {
                "id": 22,
                "identifier": "sirsoft-ecommerce.products",
                "name": "상품 관리"
            },
            {
                "id": 27,
                "identifier": "sirsoft-ecommerce.orders",
                "name": "주문 관리"
            },
            {
                "id": 30,
                "identifier": "sirsoft-ecommerce.categories",
                "name": "카테고리 관리"
            },
            {
                "id": 35,
                "identifier": "sirsoft-ecommerce.brands",
                "name": "브랜드 관리"
            },
            {
                "id": 40,
                "identifier": "sirsoft-ecommerce.product-notice-templates",
                "name": "상품정보제공고시 관리"
            },
            {
                "id": 45,
                "identifier": "sirsoft-ecommerce.product-common-infos",
                "name": "공통정보 관리"
            },
            {
                "id": 50,
                "identifier": "sirsoft-ecommerce.settings",
                "name": "환경설정"
            },
            {
                "id": 53,
                "identifier": "sirsoft-ecommerce.promotion-coupon",
                "name": "쿠폰 관리"
            },
            {
                "id": 58,
                "identifier": "sirsoft-ecommerce.shipping-policies",
                "name": "배송정책 관리"
            },
            {
                "id": 63,
                "identifier": "sirsoft-ecommerce.product-labels",
                "name": "상품 라벨 관리"
            },
            {
                "id": 68,
                "identifier": "sirsoft-ecommerce.identity.policies",
                "name": "이커머스 본인인증 정책"
            },
            {
                "id": 71,
                "identifier": "sirsoft-ecommerce.reviews",
                "name": "리뷰 관리"
            },
            {
                "id": 75,
                "identifier": "sirsoft-ecommerce.inquiries",
                "name": "문의 관리"
            },
            {
                "id": 78,
                "identifier": "sirsoft-ecommerce.dashboard",
                "name": "대시보드"
            },
            {
                "id": 80,
                "identifier": "sirsoft-ecommerce.user-products",
                "name": "사용자 상품"
            },
            {
                "id": 82,
                "identifier": "sirsoft-ecommerce.user-orders",
                "name": "사용자 주문"
            },
            {
                "id": 86,
                "identifier": "sirsoft-ecommerce.user-reviews",
                "name": "사용자 리뷰"
            },
            {
                "id": 88,
                "identifier": "sirsoft-ecommerce.mileage",
                "name": "마일리지 관리"
            },
            {
                "id": 91,
                "identifier": "sirsoft-ecommerce.user-currency",
                "name": "회원 결제 통화 관리"
            },
            {
                "id": 93,
                "identifier": "sirsoft-ecommerce.user-shipping-country",
                "name": "회원 배송국가 관리"
            },
            {
                "id": 110,
                "identifier": "sirsoft-page.pages",
                "name": "페이지 관리"
            },
            {
                "id": 115,
                "identifier": "core",
                "name": "코어"
            },
            {
                "id": 116,
                "identifier": "core.users",
                "name": "사용자 관리"
            },
            {
                "id": 121,
                "identifier": "core.menus",
                "name": "메뉴 관리"
            },
            {
                "id": 126,
                "identifier": "core.modules",
                "name": "모듈 관리"
            },
            {
                "id": 131,
                "identifier": "core.plugins",
                "name": "플러그인 관리"
            },
            {
                "id": 137,
                "identifier": "core.templates",
                "name": "템플릿 관리"
            },
            {
                "id": 143,
                "identifier": "core.permissions",
                "name": "권한 관리"
            },
            {
                "id": 148,
                "identifier": "core.notification-logs",
                "name": "알림 발송 이력"
            },
            {
                "id": 151,
                "identifier": "core.notifications",
                "name": "알림 (관리자)"
            },
            {
                "id": 155,
                "identifier": "core.user-notifications",
                "name": "알림 (사용자)"
            },
            {
                "id": 159,
                "identifier": "core.identity",
                "name": "본인인증 (사용자)"
            },
            {
                "id": 163,
                "identifier": "core.admin.identity",
                "name": "본인인증 관리 (관리자)"
            },
            {
                "id": 172,
                "identifier": "core.settings",
                "name": "환경설정"
            },
            {
                "id": 175,
                "identifier": "core.dashboard",
                "name": "대시보드"
            },
            {
                "id": 181,
                "identifier": "core.activities",
                "name": "활동 로그"
            },
            {
                "id": 184,
                "identifier": "core.attachments",
                "name": "첨부파일 관리"
            },
            {
                "id": 188,
                "identifier": "core.schedules",
                "name": "스케줄 관리"
            },
            {
                "id": 194,
                "identifier": "core.language_packs",
                "name": "언어팩 관리"
            }
        ],
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 10:41:24",
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_assign_roles": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

관리자 레이아웃 전역 부트스트랩 엔드포인트. `_admin_base.json` 의 `data_source`(`current_user`)가 모든 관리자 페이지 진입 시 자동 호출해, 헤더/권한 게이트/is_admin 분기의 기준 사용자 정보를 채운다. 응답에는 `roles.permissions` 가 eager load 되어 `permissions` 배열이 함께 내려온다.

**인증 계약**: `auth:sanctum` 필요 — Bearer 토큰이 없거나 만료되면 `401` 을 반환한다(프론트 `data_source` 의 `auth_required: true` 에 대응). 이 계약이 프론트 소비의 SSoT 이므로 미들웨어 체인 변경 시 반드시 프론트 `auth_required`/`auth_mode` 와 함께 검토한다(이슈 #64).


### POST /api/auth/admin/login
<!-- @generated:start:api.auth.admin.login -->
- **라우트명**: `api.auth.admin.login`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AuthController@login`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| email | body | email | 예 | — | 이메일 주소 |
| password | body | string | 예 | min 6 | 비밀번호 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.login_validation_rules`).

**요청 예시**

```http
POST /api/auth/admin/login HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-500 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

관리자 로그인. `email`/`password` 검증 후 `AuthService::login()` 이 인증하고, 인증 사용자가 `isAdmin()` 이 아니면 `403 auth.admin_required` 로 거부한다. 성공 시 `data.token`(Sanctum Bearer) 과 `data.user`(UserResource) 를 반환한다. 계정 잠금 시 `AccountLockedException`, 자격 불일치 시 `422` 검증 오류를 반환한다. 이후 모든 관리자 API 호출은 이 토큰을 `Authorization: Bearer` 헤더로 실어야 한다.


### POST /api/auth/forgot-password
<!-- @generated:start:api.auth.forgot-password -->
- **라우트명**: `api.auth.forgot-password`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@forgotPassword`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| email | body | email | 예 | — | 이메일 주소 |
| redirect_prefix | body | string | 아니오 | `admin` | 재설정 링크가 향할 화면 구분값 — `admin` 전달 시 관리자 재설정 화면, 미지정 시 사용자 화면 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.forgot_password_validation_rules`).

**요청 예시**

```http
POST /api/auth/forgot-password HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "email": "user@example.com",
    "redirect_prefix": "admin"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

비밀번호 재설정 메일 발송을 요청한다(공개). `email` 로 계정을 찾아 재설정 링크 메일을 보내고 `message: auth.password_reset_email_sent` 를 반환한다. `redirect_prefix` 는 재설정 링크가 향할 화면을 구분하는 값으로 관리자 흐름(`admin_forgot_password.json`)에서는 `admin` 을 전달해 링크가 관리자 재설정 화면을 가리키게 한다(미지정 시 사용자 화면). 계정 열거 방지를 위해 이메일 존재 여부와 무관하게 동일 응답을 주는 것이 원칙이다.


### POST /api/auth/login
<!-- @generated:start:api.auth.login -->
- **라우트명**: `api.auth.login`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@login`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| email | body | email | 예 | — | 이메일 주소 |
| password | body | string | 예 | min 6 | 비밀번호 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.login_validation_rules`).

**요청 예시**

```http
POST /api/auth/login HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-500 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

일반 사용자 로그인(공개). 관리자 로그인과 달리 `isAdmin()` 검사가 없다. 성공 시 `data.token`(Sanctum Bearer) 과 `data.user` 를 반환하며 `message: auth.login_success`. 계정 잠금 시 `423 auth.account_locked` 를 잠금 해제까지 남은 정보와 함께 반환한다. 프론트 로그인 폼(`partials/auth/_register_form.json` 인접)에서 소비한다.


### POST /api/auth/logout
<!-- @generated:start:api.auth.logout -->
- **라우트명**: `api.auth.logout`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@logout`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/auth/logout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "로그아웃이 성공했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

현재 사용자 토큰을 폐기해 로그아웃한다(`message: auth.logout_success`). 현재 요청에 사용된 토큰만 폐기하며, 모든 기기에서 로그아웃하려면 `/api/user/auth/logout-all-devices` 를 사용한다.


### POST /api/auth/register
<!-- @generated:start:api.auth.register -->
- **라우트명**: `api.auth.register`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@register`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | string | 예 | max 255 | 대상의 이름/명칭 |
| nickname | body | string | 아니오 | max 50 | 닉네임 |
| email | body | string | 예 | max 255 | 이메일 주소 |
| password | body | string | 예 | min 8 | 비밀번호 |
| language | body | string | 아니오 | `ko`, `en`, `fr`, `ja` | 언어 코드 |
| agree_terms | body | string | 아니오 | — | 이용약관 동의 (코어 필수 동의 — accepted 규칙, 미동의 시 가입 거부) |
| agree_privacy | body | string | 아니오 | — | 개인정보 처리방침 동의 (코어 필수 동의 — accepted 규칙, 미동의 시 가입 거부) |
| agree_email_subscription | body | boolean | 아니오 | — | 광고성 이메일 수신 동의 (marketing 플러그인 주입, 선택 항목) |
| agree_marketing_consent | body | boolean | 아니오 | — | 마케팅 정보 수신 전체 동의 (marketing 플러그인 주입, 선택 항목) |
| agree_third_party_consent | body | boolean | 아니오 | — | 제3자 정보 제공 동의 (marketing 플러그인 주입, 선택 항목) |
| agree_info_disclosure | body | boolean | 아니오 | — | 개인정보 이용 안내 동의 (marketing 플러그인 주입, 선택 항목) |
| preferred_currency | body | string | 아니오 | — | 선호 결제 통화 (ecommerce 모듈 주입, 가입 시 계정 기본 통화로 저장) |
| preferred_shipping_country | body | string | 아니오 | — | 선호 배송 국가 코드 (ecommerce 모듈 주입, 가입 시 계정 기본 배송 국가로 저장) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.register_validation_rules`).

**요청 예시**

```http
POST /api/auth/register HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "name": "예시 이름",
    "nickname": "예시 이름",
    "email": "user@example.com",
    "password": "Password123!",
    "language": "ko",
    "agree_terms": "예시값",
    "agree_privacy": "예시값",
    "preferred_currency": "예시값",
    "preferred_shipping_country": "KR"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

회원가입(공개). 성공 시 `201 auth.register_success` 와 `data.token`/`data.user` 를 반환해 가입 직후 로그인 상태로 이어진다. `agree_*` 동의 파라미터(약관/개인정보/이메일수신/마케팅/제3자제공/정보공개)는 가입 시점의 동의 이력으로 기록된다 — 그중 `agree_email_subscription`/`agree_marketing_consent`/`agree_third_party_consent`/`agree_info_disclosure` 및 `preferred_currency`/`preferred_shipping_country` 는 marketing·ecommerce 확장이 훅(`core.auth.register_validation_rules`)으로 주입하는 파라미터로, 해당 확장 비활성 시 무시된다. 검증 실패 시 `422 auth.register_failed`.


### POST /api/auth/reset-password
<!-- @generated:start:api.auth.reset-password -->
- **라우트명**: `api.auth.reset-password`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@resetPassword`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | body | string | 예 | — | 인증/검증 토큰 |
| email | body | email | 예 | — | 이메일 주소 |
| password | body | string | 예 | min 8 | 비밀번호 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.reset_password_validation_rules`).

**요청 예시**

```http
POST /api/auth/reset-password HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "token": "{YOUR_TOKEN}",
    "email": "user@example.com",
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

비밀번호 재설정을 실제 반영한다(공개). 재설정 메일의 `token` 과 `email`, 새 `password` 를 받아 비밀번호를 갱신하고 `message: auth.password_reset_success`. 토큰 만료/불일치 등 검증 실패 시 `422 auth.password_reset_failed`. 반영 전 토큰 유효성만 먼저 확인하려면 `/api/auth/validate-reset-token` 을 사용한다.


### GET /api/auth/user
<!-- @generated:start:api.auth.user -->
- **라우트명**: `api.auth.user`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@user`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/auth/user HTTP/1.1
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
| avatar | null | `null` | 아바타 이미지 URL (User::getAvatarUrl() — 아바타 미설정 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (user.language.{code} 번역) |
| country | string | `KR` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생) |
| homepage | string | `https://example.com` | 홈페이지 URL |
| mobile | string | `010-9070-5662` | 휴대폰 번호 |
| phone | string | `02-805-4759` | 전화번호 |
| zipcode | string | `93153` | 우편번호 |
| address | string | `대구광역시 북구 백제고분로 720` | 기본 주소 |
| address_detail | string | `40동 835호` | 상세 주소 |
| signature | string | `Ipsam rem amet expedita est.` | 서명 |
| bio | string | `Tenetur omnis et amet omnis veniam to…` | 자기소개 |
| last_login_at | string | `2026-07-05 19:15:16` | last login 일시 |
| email_verified_at | string | `2026-07-06 19:15:16` | email verified 일시 |
| timezone | string | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| modules_count | array | `[]` | 접근 가능 모듈 수 (modules_count 속성이 로드된 경우에만 포함 — whenLoaded 성격의 조건부 필드) |
| plugins_count | array | `[]` | 접근 가능 플러그인 수 (plugins_count 속성이 로드된 경우에만 포함) |
| menus_count | array | `[]` | 접근 가능 메뉴 수 (menus_count 속성이 로드된 경우에만 포함) |
| modules | array | `[]` | 접근 가능 모듈 목록 (원소 id/name/slug/is_active — modules 관계 로드 시에만 포함) |
| plugins | array | `[]` | 접근 가능 플러그인 목록 (원소 id/name/slug/is_active — plugins 관계 로드 시에만 포함) |
| menus | array | `[]` | 접근 가능 메뉴 목록 (원소 id/title/url/is_active — menus 관계 로드 시에만 포함) |
| roles | array | `[{"id":1,"identifier":"admin","name":"관리자"}]` | 사용자에게 부여된 역할 목록 (원소 id/identifier/name — roles 관계 파생, name 은 현지화 라벨) |
| permissions | array | `[{"id":3,"identifier":"core.users.read","name":"사용자 조회"},…` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| consents | array | `[]` | 전체 약관 동의 이력 (원소 consent_type/agreed_at/revoked_at — consents 관계 로드 시 포함, 플러그인 참조용) |
| terms_consent | array | `[]` | 이용약관 동의 정보 (agreed_at — ConsentType::Terms 동의 이력에서 파생, 미동의 시 null) |
| privacy_consent | array | `[]` | 개인정보 처리방침 동의 정보 (agreed_at — ConsentType::Privacy 동의 이력에서 파생, 미동의 시 null) |
| created_at | string | `2026-07-06 19:15:16` | 생성 일시 |
| updated_at | string | `2026-07-06 19:15:16` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |
| notify_post_complete | boolean | `false` | 게시판 새 글 작성 완료 알림 수신 설정 (marketing 플러그인 주입) |
| notify_post_reply | boolean | `false` | 내 게시글에 답글 달림 알림 수신 설정 (marketing 플러그인 주입) |
| notify_comment | boolean | `false` | 내 게시글에 댓글 달림 알림 수신 설정 (marketing 플러그인 주입) |
| notify_reply_comment | boolean | `false` | 내 댓글에 답글 달림 알림 수신 설정 (marketing 플러그인 주입) |
| email_subscription | boolean | `false` | 광고성 이메일 수신 동의 여부 (marketing 플러그인 주입) |
| email_subscription_at | null | `null` | email subscription 일시 (광고성 이메일 수신 동의 시각, 미동의 시 null) |
| marketing_consent | boolean | `false` | 마케팅 정보 수신 전체 동의 마스터 키 (marketing 플러그인 주입) |
| marketing_consent_at | null | `null` | marketing consent 일시 (마케팅 정보 수신 동의 시각, 미동의 시 null) |
| third_party_consent | boolean | `false` | 제3자 정보 제공 동의 여부 (법적 항목 — marketing 플러그인 주입) |
| third_party_consent_at | null | `null` | third party consent 일시 (제3자 정보 제공 동의 시각, 미동의 시 null) |
| info_disclosure | boolean | `false` | 개인정보 이용 안내 동의 여부 (법적 항목 — marketing 플러그인 주입) |
| info_disclosure_at | null | `null` | info disclosure 일시 (개인정보 이용 안내 동의 시각, 미동의 시 null) |
| marketing_consent_enabled | boolean | `true` | 마케팅 정보 수신 동의 항목 UI 노출 여부 (관리자 활성화 플래그) |
| marketing_consent_terms_slug | string | `marketing-terms` | 마케팅 정보 수신 동의에 연결된 약관 slug (미설정 시 null) |
| marketing_consent_terms_slug_set | boolean | `true` | 마케팅 정보 수신 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| third_party_consent_enabled | boolean | `true` | 제3자 정보 제공 동의 항목 UI 노출 여부 (관리자 활성화 플래그) |
| third_party_consent_terms_slug | null | `null` | 제3자 정보 제공 동의에 연결된 약관 slug (미설정 시 null) |
| third_party_consent_terms_slug_set | boolean | `false` | 제3자 정보 제공 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| info_disclosure_enabled | boolean | `true` | 개인정보 이용 안내 동의 항목 UI 노출 여부 (관리자 활성화 플래그) |
| info_disclosure_terms_slug | null | `null` | 개인정보 이용 안내 동의에 연결된 약관 slug (미설정 시 null) |
| info_disclosure_terms_slug_set | boolean | `false` | 개인정보 이용 안내 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| email_subscription_enabled | boolean | `true` | 광고성 이메일 수신 동의 항목 UI 노출 여부 (관리자 활성화 플래그) |
| email_subscription_terms_slug | null | `null` | 광고성 이메일 수신 동의에 연결된 약관 slug (미설정 시 null) |
| email_subscription_terms_slug_set | boolean | `false` | 광고성 이메일 수신 약관 연결 존재 여부 (프론트 링크 표시 판정용) |
| channels | array | `[{"key":"email_subscription","label":"광고성 이메일 수신","enable…` | 관리자 정의 전체 마케팅 채널 목록 (원소 key/label/enabled/terms_slug — marketing 플러그인 주입) |
| consent_histories | array | `[]` | 동의 변경 이력 (원소 channel_key/action/source/created_at — marketing 플러그인 주입) |
| ecommerce_mileage | object | `{"enabled":false}` | 마일리지 정보 (enabled/잔액 — ecommerce 모듈 주입, 모듈 비활성 시 enabled=false) |
| ecommerce_preferred_currency | null | `null` | 선호 결제 통화 (ecommerce 모듈 주입, 미설정 시 null) |
| ecommerce_preferred_shipping_country | null | `null` | 선호 배송 국가 코드 (ecommerce 모듈 주입, 미설정 시 null) |
| ecommerce_preferred_shipping_country_name | null | `null` | 선호 배송 국가 이름 (국가 코드에서 현지화 파생 — ecommerce 모듈 주입, 미설정 시 null) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "name": "API 문서 샘플 사용자",
        "nickname": "song.hyunji",
        "email": "apidoc-sample-user@example.com",
        "avatar": null,
        "language": "ko",
        "language_label": "한국어",
        "country": "KR",
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "is_admin": true,
        "homepage": "https://example.com",
        "mobile": "010-9595-2897",
        "phone": "02-637-5618",
        "zipcode": "16505",
        "address": "경기도 안양시 봉은사로 2918",
        "address_detail": "48동 718호",
        "signature": "Fugit consequuntur repellendus sed.",
        "bio": "Ut magni et sunt ducimus error adipisci. Pariatur corporis voluptatem ratione quo non saepe. Illo atque praesentium possimus dolores qui est fugit. Sint fugiat numquam voluptates.",
        "last_login_at": "2026-07-07 10:41:24",
        "email_verified_at": "2026-07-08 10:41:24",
        "timezone": "Asia/Seoul",
        "modules_count": [],
        "plugins_count": [],
        "menus_count": [],
        "modules": [],
        "plugins": [],
        "menus": [],
        "roles": [
            {
                "id": 1,
                "identifier": "admin",
                "name": "관리자"
            },
            {
                "id": 4,
                "identifier": "apidoc-sample-role",
                "name": "API 문서 샘플 역할"
            }
        ],
        "permissions": [
            {
                "id": 5,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.posts.read",
                "name": "게시글 조회 (관리자)"
            },
            {
                "id": 6,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.posts.write",
                "name": "게시글 작성/수정/삭제 (관리자)"
            },
            {
                "id": 7,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.posts.read-secret",
                "name": "비밀글 조회 (관리자)"
            },
            {
                "id": 8,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.comments.read",
                "name": "댓글 조회 (관리자)"
            },
            {
                "id": 9,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.comments.write",
                "name": "댓글 작성/수정/삭제 (관리자)"
            },
            {
                "id": 10,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.attachments.upload",
                "name": "파일 업로드/삭제 (관리자)"
            },
            {
                "id": 11,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.attachments.download",
                "name": "파일 다운로드 (관리자)"
            },
            {
                "id": 12,
                "identifier": "sirsoft-board.apidoc-sample-board.admin.manage",
                "name": "게시판 관리 (관리자)"
            },
            {
                "id": 13,
                "identifier": "sirsoft-board.apidoc-sample-board.posts.read",
                "name": "게시글 조회"
            },
            {
                "id": 14,
                "identifier": "sirsoft-board.apidoc-sample-board.posts.write",
                "name": "게시글 작성/수정/삭제"
            },
            {
                "id": 15,
                "identifier": "sirsoft-board.apidoc-sample-board.posts.read-secret",
                "name": "비밀글 조회"
            },
            {
                "id": 16,
                "identifier": "sirsoft-board.apidoc-sample-board.comments.read",
                "name": "댓글 조회"
            },
            {
                "id": 17,
                "identifier": "sirsoft-board.apidoc-sample-board.comments.write",
                "name": "댓글 작성/수정/삭제"
            },
            {
                "id": 18,
                "identifier": "sirsoft-board.apidoc-sample-board.attachments.upload",
                "name": "파일 업로드/삭제"
            },
            {
                "id": 19,
                "identifier": "sirsoft-board.apidoc-sample-board.attachments.download",
                "name": "파일 다운로드"
            },
            {
                "id": 20,
                "identifier": "sirsoft-board.apidoc-sample-board.manager",
                "name": "게시판 관리 (사용자)"
            },
            {
                "id": 81,
                "identifier": "sirsoft-ecommerce.user-products.read",
                "name": "상품 조회"
            },
            {
                "id": 83,
                "identifier": "sirsoft-ecommerce.user-orders.create",
                "name": "주문하기"
            },
            {
                "id": 84,
                "identifier": "sirsoft-ecommerce.user-orders.cancel",
                "name": "주문 취소"
            },
            {
                "id": 85,
                "identifier": "sirsoft-ecommerce.user-orders.confirm",
                "name": "구매확정"
            },
            {
                "id": 87,
                "identifier": "sirsoft-ecommerce.user-reviews.write",
                "name": "리뷰 작성"
            },
            {
                "id": 23,
                "identifier": "sirsoft-ecommerce.products.read",
                "name": "상품 조회"
            },
            {
                "id": 24,
                "identifier": "sirsoft-ecommerce.products.create",
                "name": "상품 생성"
            },
            {
                "id": 25,
                "identifier": "sirsoft-ecommerce.products.update",
                "name": "상품 수정"
            },
            {
                "id": 26,
                "identifier": "sirsoft-ecommerce.products.delete",
                "name": "상품 삭제"
            },
            {
                "id": 28,
                "identifier": "sirsoft-ecommerce.orders.read",
                "name": "주문 조회"
            },
            {
                "id": 29,
                "identifier": "sirsoft-ecommerce.orders.update",
                "name": "주문 수정"
            },
            {
                "id": 31,
                "identifier": "sirsoft-ecommerce.categories.read",
                "name": "카테고리 조회"
            },
            {
                "id": 32,
                "identifier": "sirsoft-ecommerce.categories.create",
                "name": "카테고리 생성"
            },
            {
                "id": 33,
                "identifier": "sirsoft-ecommerce.categories.update",
                "name": "카테고리 수정"
            },
            {
                "id": 34,
                "identifier": "sirsoft-ecommerce.categories.delete",
                "name": "카테고리 삭제"
            },
            {
                "id": 36,
                "identifier": "sirsoft-ecommerce.brands.read",
                "name": "브랜드 조회"
            },
            {
                "id": 37,
                "identifier": "sirsoft-ecommerce.brands.create",
                "name": "브랜드 생성"
            },
            {
                "id": 38,
                "identifier": "sirsoft-ecommerce.brands.update",
                "name": "브랜드 수정"
            },
            {
                "id": 39,
                "identifier": "sirsoft-ecommerce.brands.delete",
                "name": "브랜드 삭제"
            },
            {
                "id": 41,
                "identifier": "sirsoft-ecommerce.product-notice-templates.read",
                "name": "조회"
            },
            {
                "id": 42,
                "identifier": "sirsoft-ecommerce.product-notice-templates.create",
                "name": "생성"
            },
            {
                "id": 43,
                "identifier": "sirsoft-ecommerce.product-notice-templates.update",
                "name": "수정"
            },
            {
                "id": 44,
                "identifier": "sirsoft-ecommerce.product-notice-templates.delete",
                "name": "삭제"
            },
            {
                "id": 46,
                "identifier": "sirsoft-ecommerce.product-common-infos.read",
                "name": "조회"
            },
            {
                "id": 47,
                "identifier": "sirsoft-ecommerce.product-common-infos.create",
                "name": "생성"
            },
            {
                "id": 48,
                "identifier": "sirsoft-ecommerce.product-common-infos.update",
                "name": "수정"
            },
            {
                "id": 49,
                "identifier": "sirsoft-ecommerce.product-common-infos.delete",
                "name": "삭제"
            },
            {
                "id": 51,
                "identifier": "sirsoft-ecommerce.settings.read",
                "name": "환경설정 조회"
            },
            {
                "id": 52,
                "identifier": "sirsoft-ecommerce.settings.update",
                "name": "환경설정 수정"
            },
            {
                "id": 54,
                "identifier": "sirsoft-ecommerce.promotion-coupon.read",
                "name": "쿠폰 조회"
            },
            {
                "id": 55,
                "identifier": "sirsoft-ecommerce.promotion-coupon.create",
                "name": "쿠폰 생성"
            },
            {
                "id": 56,
                "identifier": "sirsoft-ecommerce.promotion-coupon.update",
                "name": "쿠폰 수정"
            },
            {
                "id": 57,
                "identifier": "sirsoft-ecommerce.promotion-coupon.delete",
                "name": "쿠폰 삭제"
            },
            {
                "id": 59,
                "identifier": "sirsoft-ecommerce.shipping-policies.read",
                "name": "배송정책 조회"
            },
            {
                "id": 60,
                "identifier": "sirsoft-ecommerce.shipping-policies.create",
                "name": "배송정책 생성"
            },
            {
                "id": 61,
                "identifier": "sirsoft-ecommerce.shipping-policies.update",
                "name": "배송정책 수정"
            },
            {
                "id": 62,
                "identifier": "sirsoft-ecommerce.shipping-policies.delete",
                "name": "배송정책 삭제"
            },
            {
                "id": 64,
                "identifier": "sirsoft-ecommerce.product-labels.read",
                "name": "상품 라벨 조회"
            },
            {
                "id": 65,
                "identifier": "sirsoft-ecommerce.product-labels.create",
                "name": "상품 라벨 생성"
            },
            {
                "id": 66,
                "identifier": "sirsoft-ecommerce.product-labels.update",
                "name": "상품 라벨 수정"
            },
            {
                "id": 67,
                "identifier": "sirsoft-ecommerce.product-labels.delete",
                "name": "상품 라벨 삭제"
            },
            {
                "id": 69,
                "identifier": "sirsoft-ecommerce.identity.policies.read",
                "name": "본인인증 정책 조회"
            },
            {
                "id": 70,
                "identifier": "sirsoft-ecommerce.identity.policies.update",
                "name": "본인인증 정책 수정"
            },
            {
                "id": 72,
                "identifier": "sirsoft-ecommerce.reviews.read",
                "name": "리뷰 조회"
            },
            {
                "id": 73,
                "identifier": "sirsoft-ecommerce.reviews.update",
                "name": "리뷰 처리"
            },
            {
                "id": 74,
                "identifier": "sirsoft-ecommerce.reviews.delete",
                "name": "리뷰 삭제"
            },
            {
                "id": 76,
                "identifier": "sirsoft-ecommerce.inquiries.update",
                "name": "문의 처리"
            },
            {
                "id": 77,
                "identifier": "sirsoft-ecommerce.inquiries.delete",
                "name": "문의 삭제"
            },
            {
                "id": 79,
                "identifier": "sirsoft-ecommerce.dashboard.view",
                "name": "대시보드 조회"
            },
            {
                "id": 89,
                "identifier": "sirsoft-ecommerce.mileage.read",
                "name": "마일리지 내역 조회"
            },
            {
                "id": 90,
                "identifier": "sirsoft-ecommerce.mileage.manage",
                "name": "마일리지 수동 처리"
            },
            {
                "id": 92,
                "identifier": "sirsoft-ecommerce.user-currency.manage",
                "name": "회원 결제 통화 변경"
            },
            {
                "id": 94,
                "identifier": "sirsoft-ecommerce.user-shipping-country.manage",
                "name": "회원 배송국가 변경"
            },
            {
                "id": 96,
                "identifier": "sirsoft-board.boards.read",
                "name": "게시판 조회"
            },
            {
                "id": 97,
                "identifier": "sirsoft-board.boards.create",
                "name": "게시판 생성"
            },
            {
                "id": 98,
                "identifier": "sirsoft-board.boards.update",
                "name": "게시판 수정"
            },
            {
                "id": 99,
                "identifier": "sirsoft-board.boards.delete",
                "name": "게시판 삭제"
            },
            {
                "id": 101,
                "identifier": "sirsoft-board.settings.read",
                "name": "환경설정 조회"
            },
            {
                "id": 102,
                "identifier": "sirsoft-board.settings.update",
                "name": "환경설정 수정"
            },
            {
                "id": 104,
                "identifier": "sirsoft-board.identity.policies.read",
                "name": "본인인증 정책 조회"
            },
            {
                "id": 105,
                "identifier": "sirsoft-board.identity.policies.update",
                "name": "본인인증 정책 수정"
            },
            {
                "id": 107,
                "identifier": "sirsoft-board.reports.view",
                "name": "신고 조회"
            },
            {
                "id": 108,
                "identifier": "sirsoft-board.reports.manage",
                "name": "신고 처리"
            },
            {
                "id": 111,
                "identifier": "sirsoft-page.pages.read",
                "name": "페이지 조회"
            },
            {
                "id": 112,
                "identifier": "sirsoft-page.pages.create",
                "name": "페이지 생성"
            },
            {
                "id": 113,
                "identifier": "sirsoft-page.pages.update",
                "name": "페이지 수정"
            },
            {
                "id": 114,
                "identifier": "sirsoft-page.pages.delete",
                "name": "페이지 삭제"
            },
            {
                "id": 117,
                "identifier": "core.users.read",
                "name": "사용자 조회"
            },
            {
                "id": 118,
                "identifier": "core.users.create",
                "name": "사용자 생성"
            },
            {
                "id": 119,
                "identifier": "core.users.update",
                "name": "사용자 수정"
            },
            {
                "id": 120,
                "identifier": "core.users.delete",
                "name": "사용자 삭제"
            },
            {
                "id": 122,
                "identifier": "core.menus.read",
                "name": "메뉴 조회"
            },
            {
                "id": 123,
                "identifier": "core.menus.create",
                "name": "메뉴 생성"
            },
            {
                "id": 124,
                "identifier": "core.menus.update",
                "name": "메뉴 수정"
            },
            {
                "id": 125,
                "identifier": "core.menus.delete",
                "name": "메뉴 삭제"
            },
            {
                "id": 127,
                "identifier": "core.modules.read",
                "name": "모듈 조회"
            },
            {
                "id": 128,
                "identifier": "core.modules.install",
                "name": "모듈 설치"
            },
            {
                "id": 129,
                "identifier": "core.modules.activate",
                "name": "모듈 활성화"
            },
            {
                "id": 130,
                "identifier": "core.modules.uninstall",
                "name": "모듈 삭제"
            },
            {
                "id": 132,
                "identifier": "core.plugins.read",
                "name": "플러그인 조회"
            },
            {
                "id": 133,
                "identifier": "core.plugins.install",
                "name": "플러그인 설치"
            },
            {
                "id": 134,
                "identifier": "core.plugins.activate",
                "name": "플러그인 활성화"
            },
            {
                "id": 135,
                "identifier": "core.plugins.update",
                "name": "플러그인 설정"
            },
            {
                "id": 136,
                "identifier": "core.plugins.uninstall",
                "name": "플러그인 삭제"
            },
            {
                "id": 138,
                "identifier": "core.templates.read",
                "name": "템플릿 조회"
            },
            {
                "id": 139,
                "identifier": "core.templates.install",
                "name": "템플릿 설치"
            },
            {
                "id": 140,
                "identifier": "core.templates.activate",
                "name": "템플릿 활성화"
            },
            {
                "id": 141,
                "identifier": "core.templates.uninstall",
                "name": "템플릿 삭제"
            },
            {
                "id": 142,
                "identifier": "core.templates.layouts.edit",
                "name": "레이아웃 편집"
            },
            {
                "id": 144,
                "identifier": "core.permissions.read",
                "name": "권한 조회"
            },
            {
                "id": 145,
                "identifier": "core.permissions.create",
                "name": "역할 생성"
            },
            {
                "id": 146,
                "identifier": "core.permissions.update",
                "name": "역할 수정"
            },
            {
                "id": 147,
                "identifier": "core.permissions.delete",
                "name": "역할 삭제"
            },
            {
                "id": 149,
                "identifier": "core.notification-logs.read",
                "name": "발송 이력 조회"
            },
            {
                "id": 150,
                "identifier": "core.notification-logs.delete",
                "name": "발송 이력 삭제"
            },
            {
                "id": 152,
                "identifier": "core.notifications.read",
                "name": "알림 조회"
            },
            {
                "id": 153,
                "identifier": "core.notifications.update",
                "name": "알림 읽음 처리"
            },
            {
                "id": 154,
                "identifier": "core.notifications.delete",
                "name": "알림 삭제"
            },
            {
                "id": 156,
                "identifier": "core.user-notifications.read",
                "name": "알림 조회"
            },
            {
                "id": 157,
                "identifier": "core.user-notifications.update",
                "name": "알림 읽음 처리"
            },
            {
                "id": 158,
                "identifier": "core.user-notifications.delete",
                "name": "알림 삭제"
            },
            {
                "id": 160,
                "identifier": "core.identity.request",
                "name": "IDV 요청"
            },
            {
                "id": 161,
                "identifier": "core.identity.verify",
                "name": "IDV 검증"
            },
            {
                "id": 162,
                "identifier": "core.identity.cancel",
                "name": "IDV 취소"
            },
            {
                "id": 164,
                "identifier": "core.admin.identity.providers.read",
                "name": "프로바이더 설정 조회"
            },
            {
                "id": 165,
                "identifier": "core.admin.identity.providers.update",
                "name": "프로바이더 설정 수정"
            },
            {
                "id": 166,
                "identifier": "core.admin.identity.policies.read",
                "name": "정책 조회"
            },
            {
                "id": 167,
                "identifier": "core.admin.identity.policies.update",
                "name": "정책 수정"
            },
            {
                "id": 168,
                "identifier": "core.admin.identity.logs.read",
                "name": "로그 열람"
            },
            {
                "id": 169,
                "identifier": "core.admin.identity.logs.purge",
                "name": "로그 파기"
            },
            {
                "id": 170,
                "identifier": "core.admin.identity.messages.read",
                "name": "메시지 템플릿 조회"
            },
            {
                "id": 171,
                "identifier": "core.admin.identity.messages.update",
                "name": "메시지 템플릿 수정"
            },
            {
                "id": 173,
                "identifier": "core.settings.read",
                "name": "설정 조회"
            },
            {
                "id": 174,
                "identifier": "core.settings.update",
                "name": "설정 수정"
            },
            {
                "id": 176,
                "identifier": "core.dashboard.read",
                "name": "대시보드 조회"
            },
            {
                "id": 177,
                "identifier": "core.dashboard.system-status",
                "name": "시스템 상태"
            },
            {
                "id": 178,
                "identifier": "core.dashboard.resources",
                "name": "시스템 리소스"
            },
            {
                "id": 179,
                "identifier": "core.dashboard.activities",
                "name": "최근 활동"
            },
            {
                "id": 180,
                "identifier": "core.dashboard.alerts",
                "name": "시스템 알림"
            },
            {
                "id": 182,
                "identifier": "core.activities.read",
                "name": "활동 로그 조회"
            },
            {
                "id": 183,
                "identifier": "core.activities.delete",
                "name": "활동 로그 삭제"
            },
            {
                "id": 185,
                "identifier": "core.attachments.create",
                "name": "첨부파일 업로드"
            },
            {
                "id": 186,
                "identifier": "core.attachments.update",
                "name": "첨부파일 수정"
            },
            {
                "id": 187,
                "identifier": "core.attachments.delete",
                "name": "첨부파일 삭제"
            },
            {
                "id": 189,
                "identifier": "core.schedules.read",
                "name": "스케줄 조회"
            },
            {
                "id": 190,
                "identifier": "core.schedules.create",
                "name": "스케줄 생성"
            },
            {
                "id": 191,
                "identifier": "core.schedules.update",
                "name": "스케줄 수정"
            },
            {
                "id": 192,
                "identifier": "core.schedules.delete",
                "name": "스케줄 삭제"
            },
            {
                "id": 193,
                "identifier": "core.schedules.run",
                "name": "스케줄 실행"
            },
            {
                "id": 195,
                "identifier": "core.language_packs.read",
                "name": "언어팩 조회"
            },
            {
                "id": 196,
                "identifier": "core.language_packs.install",
                "name": "언어팩 설치"
            },
            {
                "id": 197,
                "identifier": "core.language_packs.manage",
                "name": "언어팩 관리"
            },
            {
                "id": 198,
                "identifier": "core.language_packs.update",
                "name": "언어팩 업데이트"
            },
            {
                "id": 1,
                "identifier": "apidoc-sample.parent",
                "name": "API 문서 샘플 권한"
            },
            {
                "id": 2,
                "identifier": "apidoc-sample.child",
                "name": "하위 권한"
            },
            {
                "id": 3,
                "identifier": "sirsoft-board",
                "name": "게시판"
            },
            {
                "id": 21,
                "identifier": "sirsoft-ecommerce",
                "name": "이커머스"
            },
            {
                "id": 109,
                "identifier": "sirsoft-page",
                "name": "페이지"
            },
            {
                "id": 4,
                "identifier": "sirsoft-board.apidoc-sample-board",
                "name": "API 문서 샘플 게시판 게시판"
            },
            {
                "id": 95,
                "identifier": "sirsoft-board.boards",
                "name": "게시판 관리"
            },
            {
                "id": 100,
                "identifier": "sirsoft-board.settings",
                "name": "환경설정"
            },
            {
                "id": 103,
                "identifier": "sirsoft-board.identity.policies",
                "name": "게시판 본인인증 정책"
            },
            {
                "id": 106,
                "identifier": "sirsoft-board.reports",
                "name": "게시판 신고 관리"
            },
            {
                "id": 22,
                "identifier": "sirsoft-ecommerce.products",
                "name": "상품 관리"
            },
            {
                "id": 27,
                "identifier": "sirsoft-ecommerce.orders",
                "name": "주문 관리"
            },
            {
                "id": 30,
                "identifier": "sirsoft-ecommerce.categories",
                "name": "카테고리 관리"
            },
            {
                "id": 35,
                "identifier": "sirsoft-ecommerce.brands",
                "name": "브랜드 관리"
            },
            {
                "id": 40,
                "identifier": "sirsoft-ecommerce.product-notice-templates",
                "name": "상품정보제공고시 관리"
            },
            {
                "id": 45,
                "identifier": "sirsoft-ecommerce.product-common-infos",
                "name": "공통정보 관리"
            },
            {
                "id": 50,
                "identifier": "sirsoft-ecommerce.settings",
                "name": "환경설정"
            },
            {
                "id": 53,
                "identifier": "sirsoft-ecommerce.promotion-coupon",
                "name": "쿠폰 관리"
            },
            {
                "id": 58,
                "identifier": "sirsoft-ecommerce.shipping-policies",
                "name": "배송정책 관리"
            },
            {
                "id": 63,
                "identifier": "sirsoft-ecommerce.product-labels",
                "name": "상품 라벨 관리"
            },
            {
                "id": 68,
                "identifier": "sirsoft-ecommerce.identity.policies",
                "name": "이커머스 본인인증 정책"
            },
            {
                "id": 71,
                "identifier": "sirsoft-ecommerce.reviews",
                "name": "리뷰 관리"
            },
            {
                "id": 75,
                "identifier": "sirsoft-ecommerce.inquiries",
                "name": "문의 관리"
            },
            {
                "id": 78,
                "identifier": "sirsoft-ecommerce.dashboard",
                "name": "대시보드"
            },
            {
                "id": 80,
                "identifier": "sirsoft-ecommerce.user-products",
                "name": "사용자 상품"
            },
            {
                "id": 82,
                "identifier": "sirsoft-ecommerce.user-orders",
                "name": "사용자 주문"
            },
            {
                "id": 86,
                "identifier": "sirsoft-ecommerce.user-reviews",
                "name": "사용자 리뷰"
            },
            {
                "id": 88,
                "identifier": "sirsoft-ecommerce.mileage",
                "name": "마일리지 관리"
            },
            {
                "id": 91,
                "identifier": "sirsoft-ecommerce.user-currency",
                "name": "회원 결제 통화 관리"
            },
            {
                "id": 93,
                "identifier": "sirsoft-ecommerce.user-shipping-country",
                "name": "회원 배송국가 관리"
            },
            {
                "id": 110,
                "identifier": "sirsoft-page.pages",
                "name": "페이지 관리"
            },
            {
                "id": 115,
                "identifier": "core",
                "name": "코어"
            },
            {
                "id": 116,
                "identifier": "core.users",
                "name": "사용자 관리"
            },
            {
                "id": 121,
                "identifier": "core.menus",
                "name": "메뉴 관리"
            },
            {
                "id": 126,
                "identifier": "core.modules",
                "name": "모듈 관리"
            },
            {
                "id": 131,
                "identifier": "core.plugins",
                "name": "플러그인 관리"
            },
            {
                "id": 137,
                "identifier": "core.templates",
                "name": "템플릿 관리"
            },
            {
                "id": 143,
                "identifier": "core.permissions",
                "name": "권한 관리"
            },
            {
                "id": 148,
                "identifier": "core.notification-logs",
                "name": "알림 발송 이력"
            },
            {
                "id": 151,
                "identifier": "core.notifications",
                "name": "알림 (관리자)"
            },
            {
                "id": 155,
                "identifier": "core.user-notifications",
                "name": "알림 (사용자)"
            },
            {
                "id": 159,
                "identifier": "core.identity",
                "name": "본인인증 (사용자)"
            },
            {
                "id": 163,
                "identifier": "core.admin.identity",
                "name": "본인인증 관리 (관리자)"
            },
            {
                "id": 172,
                "identifier": "core.settings",
                "name": "환경설정"
            },
            {
                "id": 175,
                "identifier": "core.dashboard",
                "name": "대시보드"
            },
            {
                "id": 181,
                "identifier": "core.activities",
                "name": "활동 로그"
            },
            {
                "id": 184,
                "identifier": "core.attachments",
                "name": "첨부파일 관리"
            },
            {
                "id": 188,
                "identifier": "core.schedules",
                "name": "스케줄 관리"
            },
            {
                "id": 194,
                "identifier": "core.language_packs",
                "name": "언어팩 관리"
            }
        ],
        "consents": [],
        "terms_consent": [],
        "privacy_consent": [],
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 10:41:24",
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_assign_roles": true
        },
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

프론트(사용자) 레이아웃 전역 부트스트랩 엔드포인트. `_user_base.json` 의 `current_user` data_source 가 모든 페이지 진입 시 호출한다. 관리자 `user` 와 달리 응답을 `UserResource::toAuthArray()` 로 만들어 `core.user.filter_resource_data` 필터를 적용하므로, marketing 플러그인·ecommerce 모듈이 훅으로 병합한 필드(`notify_*`, `marketing_consent*`, `channels`, `ecommerce_*` 등)가 함께 내려온다. 이 필드들은 확장 소유이므로 상세 설명은 각 확장 문서를 따른다. 로그인 시 이 응답이 계정 영속 통화를 덮어쓰는 계약(D-LOGIN-CUR)의 출처다.

**인증 계약**: 이 경로(`api.auth.user`)는 `auth:sanctum` 으로 인증이 필수다. 인증 여부와 무관하게 게스트 컨텍스트가 필요한 화면은 `optional.sanctum` 이 걸린 `/api/user/auth/user`(`api.user.auth.user`)를 사용해야 한다 — 프론트 `data_source` 의 `auth_mode: "optional"` 이 이 경로에 대응한다. 두 경로의 미들웨어 차이가 곧 `auth_required`/`auth_mode` 계약이므로 변경 시 프론트와 함께 검토한다(이슈 #64).


### POST /api/auth/validate-reset-token
<!-- @generated:start:api.auth.validate-reset-token -->
- **라우트명**: `api.auth.validate-reset-token`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@validateResetToken`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | body | string | 예 | — | 인증/검증 토큰 |
| email | body | email | 예 | — | 이메일 주소 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.validate_reset_token_rules`, `core.auth.validate_reset_token_messages`).

**요청 예시**

```http
POST /api/auth/validate-reset-token HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "token": "{YOUR_TOKEN}",
    "email": "user@example.com"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

비밀번호 재설정 토큰의 유효성만 사전 확인한다(공개, 비밀번호 미변경). 재설정 화면(`admin_reset_password.json`/`auth/reset_password.json`) 진입 시 토큰/이메일이 유효한지 먼저 검사해, 만료·위조 링크면 즉시 오류 화면을 보이고 유효하면 새 비밀번호 입력 폼을 노출하는 용도다. 실제 반영은 `/api/auth/reset-password` 가 담당한다.


### POST /api/user/auth/logout
<!-- @generated:start:api.user.auth.logout -->
- **라우트명**: `api.user.auth.logout`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@logout`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.auth.logout`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/user/auth/logout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`core.auth.logout`)이 없는 경우 |

<!-- @generated:end -->

**설명**

`/user` prefix 그룹의 사용자 로그아웃. 공용 경로 `/api/auth/logout` 과 동일하게 현재 토큰을 폐기하되, `permission:core.auth.logout` 권한 게이트를 추가로 통과해야 한다. 세션 시작(`start.api.session`)이 걸린 공용 경로와 달리 권한 기반 접근 제어가 필요한 흐름에서 사용한다.


### POST /api/user/auth/logout-all-devices
<!-- @generated:start:api.user.auth.logout-all-devices -->
- **라우트명**: `api.user.auth.logout-all-devices`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@logoutFromAllDevices`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.auth.logout`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/user/auth/logout-all-devices HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`core.auth.logout`)이 없는 경우 |

<!-- @generated:end -->

**설명**

현재 사용자의 **모든** Sanctum 토큰을 폐기해 전 기기에서 로그아웃한다(`message: auth.logout_all_devices_success`). 비밀번호 변경 후 기존 세션 무효화, 계정 도용 대응 등에 사용한다.


### POST /api/user/auth/refresh
<!-- @generated:start:api.user.auth.refresh -->
- **라우트명**: `api.user.auth.refresh`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@refresh`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.auth.refresh`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/user/auth/refresh HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`core.auth.refresh`)이 없는 경우 |

<!-- @generated:end -->

**설명**

`/user` prefix 그룹의 토큰 갱신. 공용 `refresh` 와 동작은 같으나 `permission:core.auth.refresh` 권한 게이트를 추가로 통과해야 한다. 이 그룹(`routes/api.php:271`)은 `optional.sanctum` + `RefreshTokenExpiration` 미들웨어 아래 있어 토큰 만료 정책 갱신과 함께 동작한다.


### GET /api/user/auth/user
<!-- @generated:start:api.user.auth.user -->
- **라우트명**: `api.user.auth.user`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\AuthController@user`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.auth.user`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/user/auth/user HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.auth.user`)이 없는 경우 |

<!-- @generated:end -->

**설명**

`/user` prefix 그룹(`routes/api.php:271`)의 현재 사용자 정보. 이 그룹은 `optional.sanctum` 미들웨어 아래 있어 **비인증(게스트) 요청도 통과**하며, 게스트 컨텍스트가 필요한 프론트 화면의 `data_source`(`auth_mode: "optional"`)가 이 경로를 소비한다. 응답 필드는 인증된 경우 공용 `/api/auth/user` 와 동일 형태(`toAuthArray` 병합 포함)이며, 이 경로에는 추가로 `permission:core.auth.user` 권한 게이트가 걸린다. 실측이 `403` 으로 제외된 것은 샘플 사용자에 해당 권한이 없었기 때문으로, 응답 shape 은 공용 `user` 경로를 참조한다.

> **인증 계약 요약(이슈 #64)**: `api.auth.user`(`auth:sanctum`, 필수) ↔ `api.user.auth.user`(`optional.sanctum`, 선택). 프론트 `data_source` 의 `auth_required: true` 는 전자에, `auth_mode: "optional"` 은 후자에 대응한다. 어느 한쪽 미들웨어를 바꾸면 프론트 소비 계약이 침묵 속에서 깨지므로 반드시 양쪽 문서를 함께 갱신한다.


