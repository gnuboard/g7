# Notification Bindings API 레퍼런스

> **소유**: plugin `sirsoft-message_bizppurio` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Notification Bindings 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-message_bizppurio/admin/notification-bindings
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.notification-bindings.index -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.notification-bindings.index`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\NotificationBindingController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/notification-bindings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| bindings | array | `[]` | <!-- TODO: 설명 --> |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "bindings": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |

<!-- @generated:end -->

**설명**

알림 설정 화면의 알림톡 탭이 소비하는 목록이다. 코어 알림 정의 중 채널에 `alimtalk` 을 포함하는 활성 알림 전체를, 연결된 알림톡 템플릿(binding)과 조인해 반환한다. 미연결 알림은 `is_bound=false` 이고 `template_code`/`template_name` 은 null, `fallback_sms_enabled` 는 false 다. `variables` 는 코어 알림 정의의 변수 목록으로, 편집 모달의 "제공 변수" 안내에 쓰인다.


### POST /api/plugins/sirsoft-message_bizppurio/admin/notification-bindings
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.notification-bindings.store -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.notification-bindings.store`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\NotificationBindingController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| notification_type | body | string | 예 | max 100 | <!-- TODO: 용도 --> |
| template_code | body | string | 아니오 | max 50 | <!-- TODO: 용도 --> |
| template_name | body | string | 아니오 | max 255 | template 이름 (식별자) |
| fallback_sms_enabled | body | boolean | 아니오 | — | <!-- TODO: 용도 --> |

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/notification-bindings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "notification_type": "예시값",
    "template_code": "예시값",
    "template_name": "예시 이름",
    "fallback_sms_enabled": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| bindings | object | `{"실측 예시값":{"notification_type":"실측 예시값","template_code":"…` | <!-- TODO: 설명 --> |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.binding.saved",
    "data": {
        "bindings": {
            "실측 예시값": {
                "notification_type": "실측 예시값",
                "template_code": "실측 예시값",
                "template_name": "실측 예시값",
                "fallback_sms_enabled": true
            }
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | `template_code` 가 카카오 승인 상태(RDY/ACT)가 아니거나, 카카오 승인 목록 조회 자체가 실패(자격증명 미설정·장애)한 경우 (`error.kakao_message`/`error.result_code`) |

<!-- @generated:end -->

**설명**

알림에 알림톡 템플릿을 연결(생성/갱신)한다. 알림톡 탭 편집 모달의 [저장] 이 호출한다. `(notification_type, alimtalk)` 당 1개 연결이므로, 이미 연결이 있으면 갱신(upsert)된다. 저장은 코어 알림 설정 저장 버튼과 무관하게 이 API 로 직접 수행되어 코어 우회가 없다(§6-2). 저장 전 카카오 승인 상태(RDY/ACT)를 서버측에서 재검증하며, 미승인 코드거나 카카오 조회 자체가 실패하면 422 로 거부한다(편집 모달 드롭다운의 승인 템플릿 필터는 화면 단계일 뿐이라, 이를 우회한 직접 API 호출로 미승인 템플릿이 저장되는 것을 막기 위함). 연결 해제(`template_code` 빈 값)는 이 검증을 거치지 않는다.


### GET /api/plugins/sirsoft-message_bizppurio/admin/notification-bindings/approved-templates
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.notification-bindings.approved-templates -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.notification-bindings.approved-templates`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\NotificationBindingController@approvedTemplates`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/notification-bindings/approved-templates HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-422 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |

<!-- @generated:end -->

**설명**

연동 편집 모달의 "연결 템플릿" 드롭다운을 채우는 소스다. 카카오 템플릿 목록 중 serviceStatus 가 RDY(발송전)·ACT(정상)인 승인 템플릿만 반환한다. 자격증명(bizId·apiKey·senderKey) 미설정이거나 kapi 조회 실패 시 422 로 카카오 사유를 그대로 전달한다.


