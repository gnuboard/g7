# Verify Password API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Verify Password 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
| password | body | string | verification_token 이 없으면 예(비밀번호 없는 회원은 아니오) | — | 비밀번호 — 비밀번호가 없는 회원(외부 인증 전용)이 보내면 값은 쓰지 않고 **428** 본인인증 요구(목적 `sensitive_action`, `return_request` = 이 요청)로 답한다. 화면이 확인 뒤 `?verification_token=` 을 붙여 다시 보내면 통과(7.2.0) |
| verification_token | body | string | 아니오 | 최대 128자 | 비밀번호가 없는 회원의 대체 확인 — 코어 본인인증(`purpose: sensitive_action`)으로 확인해 받은 토큰. 본인 것·미사용·미만료여야 하고 통과하면 소비된다(무효면 422 `user.reauthentication_invalid`) (7.2.0) |

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

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — 컨트롤러가 `ResponseHelper::success('user.password_verified')` 를 인자 없이 호출하므로 `data` 는 `null` 입니다)._

**응답 예시**

```json
{
    "success": true,
    "message": "비밀번호가 확인되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | 비밀번호 불일치 | 전달한 `password` 가 저장된 해시와 일치하지 않는 경우 (`message`: 비밀번호가 일치하지 않습니다.) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 428 | 본인인증 필요 | 비밀번호가 없는 회원이 `password` 로 확인하려 하거나 아무것도 없이(빈 본문) 온 경우 (`error_code`: `identity_verification_required`, `verification.purpose`: `sensitive_action`) — 확인 뒤 `?verification_token=` 을 붙여 같은 요청을 다시 보낸다 (7.2.0) |
| 429 | Too Many Requests | 비밀번호 확인을 너무 여러 번 틀린 경우 (`auth.password_reconfirm_throttled`, `:seconds` 치환, `Retry-After` 헤더, 7.1.6). 회원 단위로 세며(`PasswordReconfirmationService`, 키 `password-reconfirm:{userId}`) 상한은 환경설정 보안의 「로그인 시도 횟수」, 대기는 「잠금 시간」(분)이다. 맞히면 횟수가 초기화된다. 인증 앱 등록 시작(`sirsoft-totp`)의 현재 비밀번호 확인과 같은 횟수를 공유한다 |
| 500 | 비밀번호 확인 실패 | 확인 처리 중 예기치 못한 예외가 발생한 경우 (`message`: 비밀번호 확인에 실패했습니다.) |

<!-- @generated:end -->


**설명**

현재 로그인한 사용자의 비밀번호를 재확인한다. 민감한 작업 직전 본인 확인 게이트로 사용되며, 프론트의 `_password_verify_section.g7.html` 이 호출한다. 요청의 `password` 를 `Hash::check` 로 저장된 해시와 대조해, 일치하면 성공 응답(`user.password_verified`)을, 틀리면 401(`user.password_incorrect`)을 반환한다. 비밀번호를 변경하지 않고 신원만 확인하므로 사용자 데이터는 바뀌지 않는다.


