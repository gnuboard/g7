# Password API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Password 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### PUT /api/me/password
<!-- @generated:start:api.me.password -->
- **라우트명**: `api.me.password`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@changePassword`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| current_password | body | string | 예 | — | 현재 비밀번호 (변경 전 확인용) |
| password | body | string | 예 | — | 비밀번호 |
| password_confirmation | body | string | 예 | — | 비밀번호 확인 (password 와 일치해야 함) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.change_password_validation_rules`).

**요청 예시**

```http
PUT /api/me/password HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "current_password": "Password123!",
    "password": "Password123!",
    "password_confirmation": "Password123!"
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

현재 로그인한 사용자가 자신의 비밀번호를 변경한다. 프론트의 비밀번호 변경 화면(`mypage/change-password.json`)이 사용한다. `current_password` 는 `current_password:sanctum` 규칙으로 검증되어 현재 비밀번호가 틀리면 실패하며, 새 비밀번호는 8자 이상이면서 `password_confirmation` 과 일치해야 한다. 해싱은 `UserService::updateUser()` 가 담당한다. 비밀번호 변경이 본인인증(IDV) 대상으로 설정된 경우 확장이 428 을 유발할 수 있으며, 이는 글로벌 예외 핸들러가 처리한다.


