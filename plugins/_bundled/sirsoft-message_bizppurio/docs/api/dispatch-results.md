# Dispatch Results API 레퍼런스

> **소유**: plugin `sirsoft-message_bizppurio` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Dispatch Results 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/sirsoft-message_bizppurio/admin/dispatch-results/lookup
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.dispatch-results.lookup -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.dispatch-results.lookup`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\DispatchResultController@lookup`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| notification_log_ids | body | array | 아니오 | max 100 | notification log 식별자 배열 |

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/dispatch-results/lookup HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "notification_log_ids": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| results | array | `[]` | <!-- TODO: 설명 --> |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "results": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

코어 "알림 발송 이력" 화면에 얹은 결과 컬럼(layout_extensions overlay)이 소비하는 조회 API 다.
현재 페이지에 표시된 코어 알림 로그 id 배열(`notification_log_ids`, 최대 100)을 넘기면, 그 로그에
연결된 비즈뿌리오 발송 결과를 **로그 id 를 키로 하는 맵**으로 한 번에 돌려준다(행마다 개별 호출하지
않아 N+1 을 피한다). 코어 알림 로그 테이블은 수정하지 않으며, 연결 표식은 비즈뿌리오 쪽
(`bizppurio_dispatches.notification_log_id`)에만 둔다.

매칭되지 않는 로그 id(메일·사이트내알림 등 비-비즈뿌리오 발송)는 결과 맵에서 제외된다 — 화면에서는
그 행의 결과 컬럼이 빈 셀이 된다. 결과에는 전화번호 등 민감정보를 포함하지 않는다(결과 컬럼은 상태·
사유만 표시).

**응답 필드** (`data.results` — 키는 `notification_log_id`, 값은 결과 객체)

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| status | string\|null | 발송 상태: `sent` / `success` / `failed` (DispatchStatus) |
| status_label | string\|null | 로케일 상태 라벨 (예: "성공", "실패", "발송중") |
| result_code | string\|null | 결과 코드 (발송응답 또는 webhook 리포트). 리포트 미수신 시 null |
| result_label | string\|null | `사유 (코드)` 표시 라벨 (예: "음영 지역 (4400)"). 코드 없으면 null |
| is_low_balance | boolean | 잔액 부족(9070 문자 / 7436 알림톡) 여부 |
| fallback_status | string\|null | SMS 대체발송 결과 (webhook TELRES). 없으면 null |
| channel | string\|null | 발송 채널: `sms` / `lms` / `alimtalk` |
| content | string\|null | 실제 비즈뿌리오에 발송한 본문. 알림톡은 코어 `notification_logs.body`(대체발송용 코어 템플릿 값)와 달리 카카오 승인 템플릿의 실제 내용이므로, 화면은 이 값을 채널별로 구분해 별도 노출한다 |

**응답 예시**

```json
{
    "success": true,
    "message": "성공",
    "data": {
        "results": {
            "128": {
                "status": "failed",
                "status_label": "실패",
                "result_code": "4400",
                "result_label": "음영 지역 (4400)",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "sms"
            }
        }
    }
}
```


### GET /api/plugins/sirsoft-message_bizppurio/admin/dispatch-results/recent
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.dispatch-results.recent -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.dispatch-results.recent`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\DispatchResultController@recent`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/dispatch-results/recent HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| results | object | `{"21":{"status":"pending","status_label":"대기","result_cod…` | <!-- TODO: 설명 --> |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "results": {
            "21": {
                "status": "pending",
                "status_label": "대기",
                "result_code": null,
                "result_label": null,
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "alimtalk"
            },
            "20": {
                "status": "success",
                "status_label": "성공",
                "result_code": "7000",
                "result_label": "성공 (7000)",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "alimtalk"
            },
            "19": {
                "status": "failed",
                "status_label": "실패",
                "result_code": "7206",
                "result_label": "검수되지 않은 템플릿 (7206)",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "alimtalk"
            },
            "18": {
                "status": "failed",
                "status_label": "실패",
                "result_code": "7436",
                "result_label": "지갑 잔액 부족(알림톡) (7436)",
                "is_low_balance": true,
                "fallback_status": "실패",
                "channel": "alimtalk"
            },
            "17": {
                "status": "success",
                "status_label": "성공",
                "result_code": "7000",
                "result_label": "성공 (7000)",
                "is_low_balance": false,
                "fallback_status": "성공",
                "channel": "alimtalk"
            },
            "16": {
                "status": "success",
                "status_label": "성공",
                "result_code": "7000",
                "result_label": "성공 (7000)",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "alimtalk"
            },
            "15": {
                "status": "failed",
                "status_label": "실패",
                "result_code": "6603",
                "result_label": "음영 지역 (6603)",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "lms"
            },
            "14": {
                "status": "success",
                "status_label": "성공",
                "result_code": "6600",
                "result_label": "성공 (6600)",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "lms"
            },
            "13": {
                "status": "failed",
                "status_label": "실패",
                "result_code": "9999",
                "result_label": "9999",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "sms"
            },
            "12": {
                "status": "failed",
                "status_label": "실패",
                "result_code": "9070",
                "result_label": "잔액 부족(문자) (9070)",
                "is_low_balance": true,
                "fallback_status": null,
                "channel": "sms"
            },
            "11": {
                "status": "success",
                "status_label": "성공",
                "result_code": "4100",
                "result_label": "성공 (4100)",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "sms"
            },
            "10": {
                "status": "pending",
                "status_label": "대기",
                "result_code": null,
                "result_label": null,
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "sms"
            },
            "9": {
                "status": "sent",
                "status_label": "발송중",
                "result_code": null,
                "result_label": null,
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "sms"
            },
            "8": {
                "status": "failed",
                "status_label": "실패",
                "result_code": "4410",
                "result_label": "잘못된 번호 (4410)",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "sms"
            },
            "7": {
                "status": "failed",
                "status_label": "실패",
                "result_code": "4400",
                "result_label": "음영 지역 (4400)",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "sms"
            },
            "6": {
                "status": "success",
                "status_label": "성공",
                "result_code": "4100",
                "result_label": "성공 (4100)",
                "is_low_balance": false,
                "fallback_status": null,
                "channel": "sms"
            }
        }
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


