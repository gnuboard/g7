# Linked Providers API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Linked Providers 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-social_login/admin/linked-providers
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.linked-providers.index -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.linked-providers.index`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\UserAccountsController@providerOptions`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.accounts.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/admin/linked-providers HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| key | string | `kakao` | 연결이 하나라도 있는 제공자 키. 꺼진 제공자, 모의 제공자도 연결이 남아 있으면 나온다 |
| label | string | `카카오` | 표시용 라벨 |
| count | integer | `1` | 이 제공자에 연결된 소셜 계정 수 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "연결이 있는 제공자 목록입니다.",
    "data": [
        {
            "key": "kakao",
            "label": "카카오",
            "count": 1
        },
        {
            "key": "mock_google",
            "label": "mock_google",
            "count": 1
        },
        {
            "key": "mock_naver",
            "label": "mock_naver",
            "count": 2
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.accounts.view`)이 없는 경우 |

<!-- @generated:end -->

**설명**

관리자 회원 목록의 「가입경로」 필터 선택지다. 켜진 제공자 목록이 아니라 연결이 있는 제공자를 고르게 하는 것은, 운영자가 제공자를 끈 뒤에도 그 제공자로 가입한 회원을 찾을 수 있어야 하기 때문이다.

`label` 은 등록된 제공자면 표시 이름, 더는 등록되지 않은 키(지운 제공자, 꺼진 모의 제공자 등)면 키 그대로다. 연결이 하나도 없으면 `data` 는 빈 배열이다.

