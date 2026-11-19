# Users API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Users 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-social_login/admin/users/{uuid}/accounts
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.users.accounts.index -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.users.accounts.index`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\UserAccountsController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.accounts.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| uuid | path | string | 예 | — | 회원 UUID(`[0-9a-fA-F-]{36}`). 형식이 맞지 않으면 라우트가 맞지 않아 404 |

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/admin/users/{uuid}/accounts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| accounts | array | `[{"provider_key":"kakao","label":"카카오","brand":"kakao","provider_user_id_prefix":"381294",…}]` | 회원의 소셜 계정 연결 목록. 항목은 `provider_key`, `label`, `brand`, `provider_user_id_prefix`(제공자 사용자 ID 앞 6자), `email`(제공자가 준 이메일 원문), `email_verified`(제공자가 검증했다고 한 값, 모르면 `null`), `linked_at`, `last_login_at`(보는 사람 시간대의 `YYYY-MM-DD HH:mm:ss`) |
| has_password | boolean | `false` | 회원에게 비밀번호가 있는지. 없으면 강제 해제 뒤 로그인 수단이 남지 않을 수 있다 |
| abilities | object | `{"can_unlink":true}` | `can_unlink`: 이 관리자가 강제 해제 권한(`sirsoft-social_login.accounts.unlink`)을 가졌는지. 화면은 이 값으로 [연결 해제] 단추를 보인다 |

**응답 예시**

```json
{
    "success": true,
    "message": "회원의 소셜 계정 연결 목록입니다.",
    "data": {
        "accounts": [
            {
                "provider_key": "kakao",
                "label": "카카오",
                "brand": "kakao",
                "provider_user_id_prefix": "381294",
                "email": "user@example.com",
                "email_verified": true,
                "linked_at": "2026-10-20 12:00:00",
                "last_login_at": "2026-10-21 09:30:00"
            }
        ],
        "has_password": false,
        "abilities": {
            "can_unlink": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.accounts.view`)이 없는 경우 |
| 404 | Not Found | 그 UUID 의 회원이 없다(`message` 「사용자를 찾을 수 없습니다.」, `errors` 없음). UUID 형식이 맞지 않아도 404 |

<!-- @generated:end -->

**설명**

관리자 회원 상세의 「소셜 로그인」 탭이 부른다. 제공자 사용자 ID 는 앞 6자만 보인다. 제공자 콘솔에서 회원을 찾는 단서로는 충분하고, 전체 값은 다른 사이트와 대조하는 식별자가 될 수 있기 때문이다.

열람 권한(`accounts.view`)과 해제 권한(`accounts.unlink`)은 따로다. 해제 권한이 없는 관리자도 목록은 보고, `abilities.can_unlink` 가 `false` 면 화면이 해제 단추를 숨긴다.


### DELETE /api/plugins/sirsoft-social_login/admin/users/{uuid}/accounts/{key}
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.users.accounts.destroy -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.users.accounts.destroy`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\UserAccountsController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.accounts.unlink`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| uuid | path | string | 예 | — | 회원 UUID(`[0-9a-fA-F-]{36}`). 형식이 맞지 않으면 라우트가 맞지 않아 404 |
| key | path | string | 예 | — | 해제할 제공자 키(`^[a-z][a-z0-9_]{1,49}$`) |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-social_login/admin/users/{uuid}/accounts/{key} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| provider_key | string | `kakao` | 연결을 해제한 제공자 키 |

**응답 예시**

```json
{
    "success": true,
    "message": "회원의 소셜 계정 연결을 해제했습니다.",
    "data": {
        "provider_key": "kakao"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.accounts.unlink`)이 없는 경우 |
| 404 | Not Found | 그 UUID 의 회원이 없다(`message` 「사용자를 찾을 수 없습니다.」, `errors` 없음). UUID 형식이 맞지 않아도 404 |
| 404 | Not Found | `errors.code` = `not_linked`: 그 제공자가 이 회원에 연결돼 있지 않다 |

<!-- @generated:end -->

**설명**

관리자가 회원의 소셜 계정 연결을 강제로 끊는다. 회원 본인 해제와 달리 마지막 로그인 수단 가드(`last_method`)를 보지 않는다(관리자 판단).

- 설정 `revoke_on_unlink`(기본 켜짐)이면 제공자 쪽 권한 회수를 시도한다. 실패해도 연결은 끊는다.
- 해제 뒤 액션 훅 `sirsoft-social_login.account.unlinked_by_admin`(인자 `User $user, string $providerKey`)이 발행되어 회원에게 알림(사이트 알림, 메일)이 가고 활동 로그에 남는다.

