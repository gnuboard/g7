# Token API 레퍼런스

> **소유**: plugin `sirsoft-message_bizppurio` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 Token(연결 확인) 엔드포인트 레퍼런스입니다
2. POST /admin/token/check — 저장된 계정/비밀번호로 즉시 재인증(캐시 우회)
3. 성공 시 200 + 새 토큰을 캐시에 반영, 실패 시 422 + errors 에 비즈뿌리오 원문 사유(bizppurio_message) + result_code
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---

### POST /api/plugins/sirsoft-message_bizppurio/admin/token/check
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.token.check -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.token.check`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

_요청 파라미터 없음. 저장된 플러그인 설정(`bizppurio_id`, `password`)을 사용합니다._

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/token/check HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_성공 응답은 `data` 없이 메시지만 반환합니다._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "인증이 정상적으로 확인되었습니다. 아이디와 비밀번호가 올바릅니다."
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 자격증명 미설정(계정/비밀번호 공란) 또는 비즈뿌리오 인증 실패 — `errors.bizppurio_message` 에 비즈뿌리오 실패 사유 원문, `errors.result_code` 에 비즈뿌리오 응답 결과코드(있으면) 동반. 비즈뿌리오 서버 연결 자체가 실패(타임아웃·DNS 등)한 경우도 422(연결 실패 안내 메시지, `result_code` 없음) |

<!-- @generated:end -->

**설명**

관리자가 설정 화면에서 저장한 비즈뿌리오 아이디·비밀번호가 실제로 유효한지 그 자리에서 확인하는 "연결 확인" 버튼이 호출하는 엔드포인트입니다. `BizppurioTokenService::verifyCredentials()` 가 캐시를 거치지 않고 매번 `/v1/token` 을 새로 호출해 재검증하며, 성공 시 새로 발급된 토큰을 캐시(TTL 23시간)에 반영해 확인 직후의 발송이 이 토큰을 그대로 재사용하게 합니다(불필요한 재발급 방지).

실패 시 `BizppurioApiException` 을 422 로 변환합니다 — 응답 `message` 는 고정 안내(`token_check.failed`)이고, 비즈뿌리오가 준 실패 사유 원문(응답 `description` 이 있으면 `token_issue_failed_with_reason` 형태로 조립된 문장)은 관리자 전용 진단 정보로 `errors.bizppurio_message` 에 담습니다(예외 원문을 메시지 키 자리에 전달하지 않는 예외→응답 매핑 규정). 응답 `errors.result_code` 는 비즈뿌리오 결과코드(예: `3007`)이며, HTTP 전송 자체가 실패한 경우 `null` 입니다.

비즈뿌리오 서버 자체에 연결할 수 없는 경우(타임아웃·DNS 실패 등)는 `BizppurioApiException` 이 아닌 `ConnectionException` 으로 던져지므로 별도 catch 하여 `error.connection_failed` 메시지와 함께 422 로 응답합니다(`result_code` 없이 500 대신 매끄러운 실패 안내).

프론트 화면(`admin/plugin_settings.json` "연결 확인" 필드)은 저장하지 않은 변경사항(`_local.hasChanges`)이 있으면 이 API 를 호출하지 않고 "변경사항을 먼저 저장해주세요" toast 만 표시합니다 — 저장 전 값으로 확인하면 실제 저장된 자격증명과 다른 결과가 나올 수 있기 때문입니다.

조회가 아닌 재인증(쓰기 성격의 외부 API 호출)이므로 `messaging.view` 가 아닌 `messaging.manage` 권한을 요구합니다.