# Templates API 레퍼런스

> **소유**: plugin `sirsoft-message_bizppurio` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Templates 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-message_bizppurio/admin/templates-readiness
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.readiness -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.readiness`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/templates-readiness HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| api_key_set | boolean | `false` | <!-- TODO: 설명 --> |
| sender_key_set | boolean | `false` | <!-- TODO: 설명 --> |
| ready | boolean | `false` | <!-- TODO: 설명 --> |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "data": {
        "api_key_set": "{MASKED}",
        "sender_key_set": false,
        "ready": false
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->

