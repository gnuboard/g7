# Verify Password API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Verify Password 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/me/verify-password
<!-- @generated:start:api.me.verify-password -->
- **라우트명**: `api.me.verify-password`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@verifyPassword`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| password | body | string | 예 | — | 비밀번호 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.auth.verify_password_rules`).

**요청 예시**

```http
POST /api/me/verify-password HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-401 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

현재 로그인한 사용자의 비밀번호를 재확인한다. 민감한 작업 직전 본인 확인 게이트로 사용되며, 프론트의 `_password_verify_section.json` 이 호출한다. 요청의 `password` 를 `Hash::check` 로 저장된 해시와 대조해, 일치하면 성공 응답(`user.password_verified`)을, 틀리면 401(`user.password_incorrect`)을 반환한다. 비밀번호를 변경하지 않고 신원만 확인하므로 사용자 데이터는 바뀌지 않는다.


