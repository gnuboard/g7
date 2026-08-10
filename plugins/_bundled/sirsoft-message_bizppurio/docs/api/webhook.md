# Webhook API 레퍼런스

> **소유**: plugin `sirsoft-message_bizppurio` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Webhook 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/sirsoft-message_bizppurio/webhook
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.webhook -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.webhook`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\BizppurioWebhookController@handle`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| DEVICE | body | string | 아니오 | max 20 | <!-- TODO: 용도 --> |
| CMSGID | body | string | 아니오 | max 64 | <!-- TODO: 용도 --> |
| MSGID | body | string | 아니오 | max 64 | <!-- TODO: 용도 --> |
| PHONE | body | string | 아니오 | max 20 | <!-- TODO: 용도 --> |
| MEDIA | body | string | 아니오 | max 10 | <!-- TODO: 용도 --> |
| RESULT | body | string | 예 | max 10 | <!-- TODO: 용도 --> |
| REFKEY | body | string | 예 | max 32 | <!-- TODO: 용도 --> |
| TELRES | body | string | 아니오 | max 10 | <!-- TODO: 용도 --> |
| KAORES | body | string | 아니오 | max 10 | <!-- TODO: 용도 --> |

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/webhook HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "DEVICE": "예시값",
    "CMSGID": "예시값",
    "MSGID": "예시값",
    "PHONE": "010-1234-5678",
    "MEDIA": "예시값",
    "RESULT": "예시값",
    "REFKEY": "예시값",
    "TELRES": "예시값",
    "KAORES": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-403 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

비즈뿌리오가 문자·알림톡 발송 결과를 URL PUSH 로 통보하는 리포트 수신 엔드포인트다. 운영자가 이 주소를 비즈뿌리오에 등록하면(환경설정 화면의 리포트 수신 주소), 발송 후 결과가 이 엔드포인트로 전송된다.

- **인증**: 코어 토큰/IDV 미들웨어를 라우트 레벨에서 제외하고, 인증을 IP 화이트리스트로 대체한다. 화이트리스트 밖 IP 는 403. IP 화이트리스트는 `plugin.php::getMiddleware()` 에서 이 라우트명(`api.plugins.sirsoft-message_bizppurio.webhook`)으로 self-gate 선언하며, 코어 게이트가 요청 시점에 부착한다(라우트 파일 직접 부착 아님).
- **처리**: `REFKEY` 로 발송 이력을 조회한다. 없으면(위조/미매칭) 200 으로 흡수한다. 이미 리포트가 반영된 이력(`reported_at` 존재)이면 replay 로 판정해 멱등 처리한다. 그 외에는 `RESULT` 코드를 분류(성공/실패/잔액부족)해 상태를 전이하고 `media`·`fallback_status`·`raw_payload`·`reported_at` 을 기록한다.
- **잔액부족**: `RESULT` 가 9070(문자)/7436(알림톡)이면 이력을 실패로 뒤집고 관리자에게 자체 알림을 1회 발송한다.
- 응답은 항상 200 이다(비즈뿌리오가 실패 응답을 재전송하지 않도록). replay 멱등이 중복 처리를 막는다.


