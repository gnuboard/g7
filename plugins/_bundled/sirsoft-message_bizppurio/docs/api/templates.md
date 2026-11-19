# Templates API 레퍼런스

> **소유**: plugin `sirsoft-message_bizppurio` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 Templates 엔드포인트 레퍼런스입니다 (readiness 1건과 라이프사이클 13건 모두 @generated 블록. 라이프사이클 항목의 설명과 예시는 코드 사양 기반으로 사람이 채움)
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시 + 응답 필드 표 + 응답 예시(envelope)
3. 알림 템플릿 라이프사이클: 등록(draft) → 검수 신청(requested) → 승인(approved) 후 발송 활성화 — 발송 판정은 DB 가 유일한 근거
4. kapi(카카오 관리 API) 실패는 422 + errors.bizppurio_message(사유 원문)·errors.result_code / 서버 연결 실패는 연결 실패 안내 422 / 상태 전이 위반도 422
5. 갱신: 코드 변경 후 php artisan api:docgen 재실행. 사람이 채운 칸은 보존되며, 라이프사이클 항목은 kapi 자격증명 없이 실측 불가라 사실이 바뀌면 그 칸을 직접 고친다
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
| api_key_set | boolean | `false` | 카카오 관리 API 키(`api_key`) 저장 여부 — 값 자체는 sensitive 라 노출하지 않고 저장 여부만 내려준다 |
| sender_key_set | boolean | `false` | 알림톡 발신프로필 키(`sender_key`) 저장 여부 |
| ready | boolean | `false` | 두 값이 모두 저장되어 템플릿 작성·검수 신청이 가능한 상태인지 (알림 설정 탭 readiness 배너가 소비) |

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

**설명**

카카오 관리에 필요한 자격증명(`api_key`·`sender_key`)은 sensitive 설정이라 코어 설정 조회 응답에서 제거된다.
값 자체는 노출하지 않고 저장 여부(boolean)만 내려주며, 알림톡 탭 readiness 배너가 소비한다.

---

## 알림 템플릿 라이프사이클

> 아래 13개 엔드포인트도 `api:docgen` 생성 블록 안에 있다. kapi(카카오 관리 API) 자격증명 없이는 실측할 수 없어 표의 설명과
> 요청 예시, 응답 예시를 코드 사양(컨트롤러 직렬화, FormRequest 검증, 서비스 상태 전이) 기반으로 사람이 채웠고, 재생성은 그 값을 보존한다. 응답 예시값은 합리적 예시다.

시스템 등록(**draft**) → 검수 신청(**requested**) → 승인(**approved**) 후 알림톡 발송이 활성화된다.
발송 판정은 DB(`bizppurio_templates`)가 유일한 근거이며(실시간 조회 폐지), 카카오와의 상태 정합은
스케줄러(`bizppurio:sync-template-status`)와 수동 sync 가 유지한다.

- **인증**: 전 엔드포인트 `auth:sanctum` + 관리자
- **권한**: 조회(목록/맵/상세) = `permission:sirsoft-message_bizppurio.messaging.view` / 그 외 변경 = `permission:sirsoft-message_bizppurio.messaging.manage`
- **status 어휘**: `draft`(작성중) / `requested`(검수중) / `approved`(승인됨) / `rejected`(반려됨) / `stopped`(발송중지) / `blocked`(차단됨) / `dormant`(휴면)

**공통 실패 규약**

| 상태코드 | 발생 조건 | 응답 형태 |
| --- | --- | --- |
| 422 (kapi 실패) | 카카오 관리 API 호출이 실패한 경우 | `errors.bizppurio_message` 에 카카오가 준 실패 사유 원문, `errors.result_code` 에 결과 코드 (관리자 전용 면 — 조치 근거 제공) |
| 422 (연결 실패) | 비즈뿌리오 서버에 닿지 못한 경우(타임아웃·DNS·프록시) | `messages.error.connection_failed` 안내, errors 없음. 행 상태는 바뀌지 않는다 |
| 422 (상태 전이 위반) | 현재 상태에서 허용되지 않는 액션인 경우 | 위반 사유 메시지 (예: "현재 상태(requested)에서는 알림톡 내용을 수정할 수 없습니다. …") |
| 422 (검증 실패) | 요청 파라미터가 검증 규칙을 위반한 경우 | `error.errors` 에 필드별 메시지 |
| 404 | `{id}` 에 해당하는 템플릿이 없는 경우 | `messages.template.not_found`(「알림 템플릿을 찾을 수 없습니다.」) |
| 401 / 403 | 토큰 없음·만료 / 요구 권한 없음 | 표준 |

kapi 실패 422 응답 예시:

```json
{
    "success": false,
    "message": "카카오 요청이 실패했습니다.",
    "error": {
        "errors": {
            "bizppurio_message": "이미 사용중인 템플릿 코드입니다.",
            "result_code": "504"
        }
    }
}
```

인프라 장애·코드 결함은 4xx 로 위장하지 않는다 — 도메인 예외(kapi 실패·상태 전이 위반) 외의 예외는 500 으로 드러난다.


### GET /api/plugins/sirsoft-message_bizppurio/admin/templates
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.index -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.index`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| status | query | string | 아니오 | `draft`/`requested`/`approved`/`rejected`/`stopped`/`blocked`/`dormant` | 우리 상태 enum 필터 |
| search | query | string | 아니오 | max 100 | 알림 유형·알림 정의 이름 검색어 |
| page | query | integer | 아니오 | min 1 | 페이지 번호 (기본 1) |
| per_page | query | integer | 아니오 | min 1 · max 100 | 페이지 크기 (기본 20) |

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/templates?status=approved&page=1&per_page=20 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| templates | array | `[{"id":12,"notification_type":"welcome","definition_name"…` | 템플릿 목록 행 배열. 대형 JSON(`content` 등)은 제외되고 존재 여부(`has_content`)만 실린다 |
| pagination | object | `{"total":8,"last_page":1,"has_more_pages":false,"current_…` | 페이지 메타 |
| templates[].id | integer | `3` | 템플릿 PK |
| templates[].notification_type | string | `order_completed` | 코어 알림 정의 type (`notification_definitions.type`) |
| templates[].definition_name | object\|null | `{"ko":"주문 완료","en":"Order completed"}` | 조인된 알림 정의 다국어 이름 (예: `{"ko": "...", "en": "..."}`) |
| templates[].definition_variables | array | `["user_name","order_id"]` | 알림 정의가 제공하는 치환 변수 목록 |
| templates[].definition_extension_type | string\|null | `module` | 알림 정의 소유 확장 유형 (`core`/`module`/`plugin`) |
| templates[].definition_extension_identifier | string\|null | `sirsoft-ecommerce` | 알림 정의 소유 확장 식별자 |
| templates[].alimtalk_enabled | boolean | `true` | 알림톡 사용 여부 |
| templates[].template_code | string\|null | `g7_1a2b3c4d_1` | 자체 채번된 카카오 템플릿 코드 (검수 신청 전이면 null) |
| templates[].status | string | `approved` | 상태 (status 어휘 참조) |
| templates[].has_content | boolean | `true` | 알림톡 content(카카오 등록 페이로드) 작성 여부 |
| templates[].requested_at | string\|null | `2026-08-10T09:12:00+09:00` | 검수 신청 시각 (ISO 8601) |
| templates[].approved_at | string\|null | `2026-08-12T14:30:00+09:00` | 승인 시각 (ISO 8601) |
| templates[].last_synced_at | string\|null | `2026-08-19T03:00:00+09:00` | 마지막 카카오 동기화 시각 (ISO 8601) |
| templates[].requested_at_date_formatted | string\|null | `2026-08-10` | 검수 신청 날짜 — 보는 사람 시간대 (`Y-m-d`, 화면 표시용) |
| templates[].approved_at_date_formatted | string\|null | `2026-08-12` | 승인 날짜 — 보는 사람 시간대 (`Y-m-d`, 화면 표시용) |
| templates[].last_synced_at_date_formatted | string\|null | `2026-08-19` | 마지막 동기화 날짜 — 보는 사람 시간대 (`Y-m-d`, 화면 표시용) |
| templates[].fallback_sms_enabled | boolean | `true` | 알림톡 실패 시 SMS 대체발송 여부 |
| templates[].sms_only | boolean | `false` | SMS 단독 발송 여부 (알림톡 미사용) |
| templates[].is_active | boolean | `true` | 발송 활성 여부 |
| pagination.total | integer | `1` | 총 건수 |
| pagination.last_page | integer | `1` | 마지막 페이지 번호 |
| pagination.has_more_pages | boolean | `false` | 다음 페이지가 있는지 |
| pagination.current_page | integer | `1` | 현재 페이지 번호 |
| pagination.per_page | integer | `20` | 페이지 크기 |

**응답 예시**

```json
{
    "success": true,
    "message": "알림 템플릿을 불러왔습니다.",
    "data": {
        "templates": [
            {
                "id": 3,
                "notification_type": "order_completed",
                "definition_name": { "ko": "주문 완료", "en": "Order completed" },
                "definition_variables": ["user_name", "order_id"],
                "definition_extension_type": "module",
                "definition_extension_identifier": "sirsoft-ecommerce",
                "alimtalk_enabled": true,
                "template_code": "g7_1a2b3c4d_1",
                "status": "approved",
                "has_content": true,
                "requested_at": "2026-08-10T09:12:00+09:00",
                "approved_at": "2026-08-12T14:30:00+09:00",
                "last_synced_at": "2026-08-19T03:00:00+09:00",
                "fallback_sms_enabled": true,
                "sms_only": false,
                "is_active": true
            }
        ],
        "pagination": {
            "total": 1,
            "last_page": 1,
            "has_more_pages": false,
            "current_page": 1,
            "per_page": 20
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 |

<!-- @generated:end -->

**설명**

템플릿 관리 화면의 DB 목록 조회다. 알림 정의(`notification_definitions`)를 조인해 라벨(definition_name)과
소속 확장을 함께 내려주며, 대형 JSON(content·approved_content·inspection_detail)은 목록에서 제외된다 —
상세는 `GET /admin/templates/{id}` 로 조회한다.


### GET /api/plugins/sirsoft-message_bizppurio/admin/templates/map
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.map -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.map`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@map`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/templates/map HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| templates | object | `{"annual_privacy_notice":{"id":30,"notification_type":"an…` | `notification_type` 을 키로, 요약 객체를 값으로 하는 맵 |
| templates.{notification_type}.id | integer | `3` | 템플릿 PK |
| templates.{notification_type}.notification_type | string | `order_completed` | 코어 알림 정의 type |
| templates.{notification_type}.alimtalk_enabled | boolean | `true` | 알림톡 사용 여부 |
| templates.{notification_type}.template_code | string\|null | `g7_1a2b3c4d_1` | 카카오 템플릿 코드 (검수 신청 전이면 null) |
| templates.{notification_type}.status | string | `approved` | 상태 (status 어휘 참조) |
| templates.{notification_type}.has_content | boolean | `true` | 알림톡 content 작성 여부 |
| templates.{notification_type}.requested_at | string\|null | `2026-08-10T09:12:00+09:00` | 검수 신청 시각 (ISO 8601) |
| templates.{notification_type}.approved_at | string\|null | `2026-08-12T14:30:00+09:00` | 승인 시각 (ISO 8601) |
| templates.{notification_type}.last_synced_at | string\|null | `2026-08-19T03:00:00+09:00` | 마지막 동기화 시각 (ISO 8601) |
| templates.{notification_type}.requested_at_date_formatted | string\|null | `2026-08-10` | 검수 신청 날짜 — 보는 사람 시간대 (`Y-m-d`, 화면 표시용) |
| templates.{notification_type}.approved_at_date_formatted | string\|null | `2026-08-12` | 승인 날짜 — 보는 사람 시간대 (`Y-m-d`, 화면 표시용) |
| templates.{notification_type}.last_synced_at_date_formatted | string\|null | `2026-08-19` | 마지막 동기화 날짜 — 보는 사람 시간대 (`Y-m-d`, 화면 표시용) |
| templates.{notification_type}.inspection_detail | array\|null | `null` | 반려 사유(comments) 등 검수 상세 |
| templates.{notification_type}.fallback_sms_enabled | boolean | `true` | SMS 대체발송 여부 |
| templates.{notification_type}.sms_body | object\|null | `{"ko":"#{user_name}님, 주문이 완료되었습니다.","en":"#{user_name}, your…` | 대체/단독 SMS 본문의 로케일 맵 (`{"ko": "...", "en": "..."}`) |
| templates.{notification_type}.has_sms_body | boolean | `true` | 어느 로케일이든 SMS 본문이 채워져 있는지 (공백만 있으면 없음). 발송 게이트와 같은 판정이라 화면은 이 값을 쓴다 |
| templates.{notification_type}.sms_body_preview | string | `#{user_name}님, 주문이 완료되었습니다.` | 요청 언어의 SMS 본문. 비어 있으면 사이트 기본 언어, `en` 순으로 고르고, 모두 없으면 빈 문자열 |
| templates.{notification_type}.sms_only | boolean | `false` | SMS 단독 발송 여부 |
| templates.{notification_type}.is_active | boolean | `true` | 발송 활성 여부 |
| abilities | object | `{"can_manage_messaging":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |
| abilities.can_manage_messaging | boolean | `true` | `sirsoft-message_bizppurio.messaging.manage` 권한 보유 여부. 알림 템플릿 편집 창의 비즈뿌리오 섹션 잠금 근거입니다(7.1.0) |

**응답 예시**

```json
{
    "success": true,
    "message": "알림 템플릿을 불러왔습니다.",
    "data": {
        "templates": {
            "order_completed": {
                "id": 3,
                "notification_type": "order_completed",
                "alimtalk_enabled": true,
                "template_code": "g7_1a2b3c4d_1",
                "status": "approved",
                "has_content": true,
                "requested_at": "2026-08-10T09:12:00+09:00",
                "approved_at": "2026-08-12T14:30:00+09:00",
                "last_synced_at": "2026-08-19T03:00:00+09:00",
                "inspection_detail": null,
                "fallback_sms_enabled": true,
                "sms_body": {"ko": "#{user_name}님, 주문이 완료되었습니다.", "en": "#{user_name}, your order is complete."},
                "has_sms_body": true,
                "sms_body_preview": "#{user_name}님, 주문이 완료되었습니다.",
                "sms_only": false,
                "is_active": true
            }
        },
        "abilities": {
            "can_manage_messaging": true
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

**설명**

알림 설정 3면(코어/게시판/이커머스)의 행 하단 UI 가 소비하는 `notification_type` 키 요약 맵이다.
행 수는 알림 정의 수에 묶인 설정성 데이터라 페이지네이션 없이 전량 내려준다.


### GET /api/plugins/sirsoft-message_bizppurio/admin/templates/{id}
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.show -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.show`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자만 | 템플릿 PK |

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/templates/3 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| template | object | `{"id":3,"notification_type":"order_completed","alimtalk_enab…` | 상세 객체. 변경 계열 엔드포인트들도 같은 형태를 반환한다 |
| template.id | integer | `3` | 템플릿 PK |
| template.notification_type | string | `order_completed` | 코어 알림 정의 type |
| template.alimtalk_enabled | boolean | `true` | 알림톡 사용 여부 |
| template.template_code | string\|null | `g7_1a2b3c4d_1` | 자체 채번된 카카오 템플릿 코드 |
| template.sender_key | string\|null | `b4c9...{MASKED}` | 검수 신청 시점의 발신프로필 키 스냅샷 |
| template.content | object\|null | `{"templateName":"주문 완료 안내","templateMessageType":"BA","templ…` | 카카오 등록 페이로드 (작성중 내용 — kapi add/update 에 그대로 사용) |
| template.approved_content | object\|null | `{"templateName":"주문 완료 안내","templateMessageType":"BA","templ…` | 승인 시점에 동결된 content 스냅샷 (발송 SSoT) |
| template.status | string | `approved` | 상태 (status 어휘 참조) |
| template.is_approved | boolean | `true` | `status === approved` 편의 플래그 |
| template.inspection_detail | array\|null | `null` | 반려 사유(comments) 등 검수 상세 |
| template.requested_at | string\|null | `2026-08-10T09:12:00+09:00` | 검수 신청 시각 (ISO 8601) |
| template.approved_at | string\|null | `2026-08-12T14:30:00+09:00` | 승인 시각 (ISO 8601) |
| template.last_synced_at | string\|null | `2026-08-19T03:00:00+09:00` | 마지막 동기화 시각 (ISO 8601) |
| template.requested_at_date_formatted | string\|null | `2026-08-10` | 검수 신청 날짜 — 보는 사람 시간대 (`Y-m-d`, 화면 표시용) |
| template.approved_at_date_formatted | string\|null | `2026-08-12` | 승인 날짜 — 보는 사람 시간대 (`Y-m-d`, 화면 표시용) |
| template.last_synced_at_date_formatted | string\|null | `2026-08-19` | 마지막 동기화 날짜 — 보는 사람 시간대 (`Y-m-d`, 화면 표시용) |
| template.fallback_sms_enabled | boolean | `true` | SMS 대체발송 여부 |
| template.sms_body | object\|null | `{"ko":"#{user_name}님, 주문이 완료되었습니다.","en":"#{user_name}, your…` | 대체/단독 SMS 본문의 로케일 맵 (`{"ko": "...", "en": "..."}`) |
| template.sms_only | boolean | `false` | SMS 단독 발송 여부 |
| template.is_active | boolean | `true` | 발송 활성 여부 |

**응답 예시**

```json
{
    "success": true,
    "message": "알림 템플릿을 불러왔습니다.",
    "data": {
        "template": {
            "id": 3,
            "notification_type": "order_completed",
            "alimtalk_enabled": true,
            "template_code": "g7_1a2b3c4d_1",
            "sender_key": "b4c9...{MASKED}",
            "content": {
                "templateName": "주문 완료 안내",
                "templateMessageType": "BA",
                "templateEmphasizeType": "NONE",
                "templateContent": "#{user_name}님, 주문 #{order_id} 이(가) 완료되었습니다.",
                "categoryCode": "004001",
                "buttons": [
                    { "name": "주문 확인", "linkType": "WL", "linkMo": "https://example.com/orders" }
                ]
            },
            "approved_content": {
                "templateName": "주문 완료 안내",
                "templateMessageType": "BA",
                "templateEmphasizeType": "NONE",
                "templateContent": "#{user_name}님, 주문 #{order_id} 이(가) 완료되었습니다.",
                "categoryCode": "004001",
                "buttons": [
                    { "name": "주문 확인", "linkType": "WL", "linkMo": "https://example.com/orders" }
                ]
            },
            "status": "approved",
            "is_approved": true,
            "inspection_detail": null,
            "requested_at": "2026-08-10T09:12:00+09:00",
            "approved_at": "2026-08-12T14:30:00+09:00",
            "last_synced_at": "2026-08-19T03:00:00+09:00",
            "fallback_sms_enabled": true,
            "sms_body": {"ko": "#{user_name}님, 주문이 완료되었습니다.", "en": "#{user_name}, your order is complete."},
            "sms_only": false,
            "is_active": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |
| 404 | Not Found | `{id}` 에 해당하는 템플릿이 없는 경우 |

<!-- @generated:end -->

**설명**

작성 모달·상세 패널이 소비하는 단건 상세다. `content`(작성중)와 `approved_content`(승인 동결 스냅샷)가
분리되어 있어, 승인 후 content 를 다시 고쳐도 발송은 `approved_content` 기준으로 유지된다.


### POST /api/plugins/sirsoft-message_bizppurio/admin/templates
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.store -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.store`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| notification_type | body | string | 예 | max 100 · 알림 정의 존재(exists) · 미등록(unique) | 대상 코어 알림 정의 type (알림 1건당 1행) |
| alimtalk_enabled | body | boolean | 아니오 | — | 알림톡 사용 여부 |
| fallback_sms_enabled | body | boolean | 아니오 | — | SMS 대체발송 여부 |
| sms_only | body | boolean | 아니오 | — | SMS 단독 발송 여부 |
| is_active | body | boolean | 아니오 | — | 발송 활성 여부 |
| sms_body | body | array | 아니오 | nullable · 로케일별 max 2000 | 대체/단독 SMS 본문의 로케일 맵. 키는 시스템 지원 로케일(`app.translatable_locales`), 값은 각 언어 본문 |
| content | body | array | 아니오 | nullable | 카카오 등록 페이로드 (아래 `content.*` 행). SMS 단독 알림은 content 없이 생성 가능 — 검수 신청 시점에 존재를 재검한다 |
| draft | body | boolean | 아니오 | — | `true` 면 임시 저장 — 아래 `content.*` 행의 **필수 조건(필수·조건부 필수·아이템 최소 개수·링크 종류별 필수 칸)만 걷어 내고** 형식·길이 제약은 그대로 건다. 걷어 낸 필수값은 검수 신청(`POST /{id}/request`)이 저장된 content 에 전체 규칙을 걸어 422 로 알린다(본문에 draft 를 실어도 완화되지 않는다) |
| content.templateName | body | string | `content` 있으면 필수 | max 200 | 템플릿명 |
| content.templateMessageType | body | string | `content` 있으면 필수 | `BA`(기본형), `EX`(부가정보형), `AD`(채널추가형), `MI`(복합형) | 메시지 유형 |
| content.templateEmphasizeType | body | string | `content` 있으면 필수 | `NONE`, `TEXT`(강조표기), `IMAGE`(이미지형), `ITEM_LIST`(아이템리스트) | 강조 유형 |
| content.templateContent | body | string | `content` 있으면 필수 | max 1000 | 알림톡 본문 |
| content.templatePreviewMessage | body | string | 아니오 | max 40 | 미리보기 문구 |
| content.categoryCode | body | string | `content` 있으면 필수 | max 20 | 카테고리 코드 (`GET /admin/alimtalk-templates/categories` 의 코드) |
| content.securityFlag | body | boolean | 아니오 | — | 보안 템플릿 여부 |
| content.templateExtra | body | string | messageType `EX`/`MI` 이면 필수 | — | 부가정보. 메시지 유형이 `EX`, `MI` 가 아니면 서버가 검증 전에 제거한다 |
| content.templateTitle | body | string | emphasizeType `TEXT` 이면 필수 | — | 강조 타이틀. `templateSubtitle` 과 필수쌍이며, 길이 수치는 미기재 항목이라 kapi 가 최종 게이트다 |
| content.templateSubtitle | body | string | emphasizeType `TEXT` 이면 필수 | — | 강조 서브타이틀. `templateTitle` 과 필수쌍 |
| content.templateHeader | body | string | 아니오 | max 16 | 헤더 |
| content.templateImageName | body | string | emphasizeType `IMAGE` 이면 필수 | — | 이미지 파일명. `templateImageUrl` 과 필수쌍 |
| content.templateImageUrl | body | string | emphasizeType `IMAGE` 이면 필수 | max 500 | 이미지 URL. 업로드 프록시(`POST /admin/templates/image`) 응답값만 기입한다 |
| content.templateItem | body | array | emphasizeType `ITEM_LIST` 이면 필수 | — | 아이템 리스트 (`list`, `summary`) |
| content.templateItem.list | body | array | `templateItem` 있으면 필수 | min 2, max 10 | 아이템 목록. 각 항목의 `title`(필수, max 6), `description`(필수, max 23) |
| content.templateItem.summary | body | array | 아니오 | — | 요약 (`title`, `description`) |
| content.templateItem.summary.title | body | string | 아니오 | max 6 | 요약 제목 |
| content.templateItem.summary.description | body | string | 아니오 | max 14 | 요약 설명 |
| content.templateItemHighlight | body | array | 아니오 | — | 하이라이트 (`title`, `description`, `imageUrl`). 길이 상한은 썸네일 유무로 갈린다 |
| content.templateItemHighlight.title | body | string | 아니오 | 썸네일 없으면 max 30, 있으면 max 21 | 하이라이트 타이틀 |
| content.templateItemHighlight.description | body | string | 아니오 | 썸네일 없으면 max 19, 있으면 max 13 | 하이라이트 설명 |
| content.templateItemHighlight.imageUrl | body | string | 아니오 | max 500 | 하이라이트 썸네일 이미지 URL |
| content.templateRepresentLink | body | array | 아니오 | — | 대표 링크 (`linkMo`, `linkPc`, `linkAnd`, `linkIos`) |
| content.templateRepresentLink.linkMo | body | string | 아니오 | max 500 | 모바일 링크 |
| content.templateRepresentLink.linkPc | body | string | 아니오 | max 500 | PC 링크 |
| content.templateRepresentLink.linkAnd | body | string | 아니오 | max 500 | Android 스킴 |
| content.templateRepresentLink.linkIos | body | string | 아니오 | max 500 | iOS 스킴 |
| content.buttons | body | array | 아니오 | max 5 | 버튼 목록. 각 항목의 `name`(필수, max 14), `linkType`(필수, `WL`, `AL`, `DS`, `BK`, `MD`, `AC`, `BC`, `BT`, `P1`, `P2`, `P3`, `TN`, `MP`), 링크 칸(`linkMo`, `linkPc`, `linkAnd`, `linkIos` 각 max 500), `telNumber`(max 20), `pluginId`(max 100) |
| content.quickReplies | body | array | 아니오 | max 10 | 바로연결 목록. 각 항목의 `name`(필수), `linkType`(필수, `WL`, `AL`, `BK`, `MD`, `BC`, `BT`), 링크 칸(`linkMo`, `linkPc`, `linkAnd`, `linkIos` 각 max 500) |

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/templates HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "notification_type": "order_completed",
    "alimtalk_enabled": true,
    "fallback_sms_enabled": true,
    "sms_body": {"ko": "#{user_name}님, 주문이 완료되었습니다.", "en": "#{user_name}, your order is complete."},
    "content": {
        "templateName": "주문 완료 안내",
        "templateMessageType": "BA",
        "templateEmphasizeType": "NONE",
        "templateContent": "#{user_name}님, 주문 #{order_id} 이(가) 완료되었습니다.",
        "categoryCode": "004001",
        "buttons": [
            { "name": "주문 확인", "linkType": "WL", "linkMo": "https://example.com/orders" }
        ]
    }
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| template | object | `{"id":4,"notification_type":"order_completed","alimtalk_enab…` | 상세 객체. 필드 구성은 `GET /admin/templates/{id}` 응답의 `template.*` 행과 같다 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "알림 템플릿을 저장했습니다.",
    "data": {
        "template": {
            "id": 4,
            "notification_type": "order_completed",
            "alimtalk_enabled": true,
            "template_code": null,
            "sender_key": null,
            "content": { "templateName": "주문 완료 안내", "templateMessageType": "BA", "templateEmphasizeType": "NONE", "templateContent": "#{user_name}님, 주문 #{order_id} 이(가) 완료되었습니다.", "categoryCode": "004001", "buttons": [{ "name": "주문 확인", "linkType": "WL", "linkMo": "https://example.com/orders" }] },
            "approved_content": null,
            "status": "draft",
            "is_approved": false,
            "inspection_detail": null,
            "requested_at": null,
            "approved_at": null,
            "last_synced_at": null,
            "fallback_sms_enabled": true,
            "sms_body": {"ko": "#{user_name}님, 주문이 완료되었습니다.", "en": "#{user_name}, your order is complete."},
            "sms_only": false,
            "is_active": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 검증 실패 (알림 정의 부재·이미 등록된 notification_type·`content.*` 규칙 위반 등) |

<!-- @generated:end -->

**설명**

템플릿 행을 `status=draft` 로 생성한다. 이 시점에는 kapi 를 호출하지 않는다 — 카카오 등록은
검수 신청(`POST /admin/templates/{id}/request`) 시점에 수행된다.

`content.*` 행의 규칙은 `content` 를 보냈을 때만 적용된다. 서버가 선택 유형과 무관한 필드·빈 값을 검증 전에 제거(prune)하므로 화면은 폼 상태 전체를 그대로 보내도 된다.

버튼·바로연결 항목별 조건부 필수: `WL` → `linkMo` / `AL` → `linkAnd`+`linkIos` 둘 다 / `TN` → `telNumber` / `P1`~`P3` → `pluginId`.


### PUT /api/plugins/sirsoft-message_bizppurio/admin/templates/{id}
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.update -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.update`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자만 | 템플릿 PK |
| alimtalk_enabled | body | boolean | 아니오 | — | 알림톡 사용 여부 |
| fallback_sms_enabled | body | boolean | 아니오 | — | SMS 대체발송 여부 |
| sms_only | body | boolean | 아니오 | — | SMS 단독 발송 여부 |
| is_active | body | boolean | 아니오 | — | 발송 활성 여부 |
| sms_body | body | array | 아니오 | nullable · 로케일별 max 2000 | 대체/단독 SMS 본문의 로케일 맵. 키는 시스템 지원 로케일(`app.translatable_locales`), 값은 각 언어 본문 |
| content | body | array | 아니오 | nullable, 등록(`POST /admin/templates`)과 같은 `content.*` 규칙 | 카카오 등록 페이로드 |
| content.templateName | body | string | `content` 있으면 필수 | max 200 | 템플릿명 |
| content.templateMessageType | body | string | `content` 있으면 필수 | `BA`(기본형), `EX`(부가정보형), `AD`(채널추가형), `MI`(복합형) | 메시지 유형 |
| content.templateEmphasizeType | body | string | `content` 있으면 필수 | `NONE`, `TEXT`(강조표기), `IMAGE`(이미지형), `ITEM_LIST`(아이템리스트) | 강조 유형 |
| content.templateContent | body | string | `content` 있으면 필수 | max 1000 | 알림톡 본문 |
| content.templatePreviewMessage | body | string | 아니오 | max 40 | 미리보기 문구 |
| content.categoryCode | body | string | `content` 있으면 필수 | max 20 | 카테고리 코드 (`GET /admin/alimtalk-templates/categories` 의 코드) |
| content.securityFlag | body | boolean | 아니오 | — | 보안 템플릿 여부 |
| content.templateExtra | body | string | messageType `EX`/`MI` 이면 필수 | — | 부가정보. 메시지 유형이 `EX`, `MI` 가 아니면 서버가 검증 전에 제거한다 |
| content.templateTitle | body | string | emphasizeType `TEXT` 이면 필수 | — | 강조 타이틀. `templateSubtitle` 과 필수쌍이며, 길이 수치는 미기재 항목이라 kapi 가 최종 게이트다 |
| content.templateSubtitle | body | string | emphasizeType `TEXT` 이면 필수 | — | 강조 서브타이틀. `templateTitle` 과 필수쌍 |
| content.templateHeader | body | string | 아니오 | max 16 | 헤더 |
| content.templateImageName | body | string | emphasizeType `IMAGE` 이면 필수 | — | 이미지 파일명. `templateImageUrl` 과 필수쌍 |
| content.templateImageUrl | body | string | emphasizeType `IMAGE` 이면 필수 | max 500 | 이미지 URL. 업로드 프록시(`POST /admin/templates/image`) 응답값만 기입한다 |
| content.templateItem | body | array | emphasizeType `ITEM_LIST` 이면 필수 | — | 아이템 리스트 (`list`, `summary`) |
| content.templateItem.list | body | array | `templateItem` 있으면 필수 | min 2, max 10 | 아이템 목록. 각 항목의 `title`(필수, max 6), `description`(필수, max 23) |
| content.templateItem.summary | body | array | 아니오 | — | 요약 (`title`, `description`) |
| content.templateItem.summary.title | body | string | 아니오 | max 6 | 요약 제목 |
| content.templateItem.summary.description | body | string | 아니오 | max 14 | 요약 설명 |
| content.templateItemHighlight | body | array | 아니오 | — | 하이라이트 (`title`, `description`, `imageUrl`). 길이 상한은 썸네일 유무로 갈린다 |
| content.templateItemHighlight.title | body | string | 아니오 | 썸네일 없으면 max 30, 있으면 max 21 | 하이라이트 타이틀 |
| content.templateItemHighlight.description | body | string | 아니오 | 썸네일 없으면 max 19, 있으면 max 13 | 하이라이트 설명 |
| content.templateItemHighlight.imageUrl | body | string | 아니오 | max 500 | 하이라이트 썸네일 이미지 URL |
| content.templateRepresentLink | body | array | 아니오 | — | 대표 링크 (`linkMo`, `linkPc`, `linkAnd`, `linkIos`) |
| content.templateRepresentLink.linkMo | body | string | 아니오 | max 500 | 모바일 링크 |
| content.templateRepresentLink.linkPc | body | string | 아니오 | max 500 | PC 링크 |
| content.templateRepresentLink.linkAnd | body | string | 아니오 | max 500 | Android 스킴 |
| content.templateRepresentLink.linkIos | body | string | 아니오 | max 500 | iOS 스킴 |
| content.buttons | body | array | 아니오 | max 5 | 버튼 목록. 각 항목의 `name`(필수, max 14), `linkType`(필수, `WL`, `AL`, `DS`, `BK`, `MD`, `AC`, `BC`, `BT`, `P1`, `P2`, `P3`, `TN`, `MP`), 링크 칸(`linkMo`, `linkPc`, `linkAnd`, `linkIos` 각 max 500), `telNumber`(max 20), `pluginId`(max 100) |
| content.quickReplies | body | array | 아니오 | max 10 | 바로연결 목록. 각 항목의 `name`(필수), `linkType`(필수, `WL`, `AL`, `BK`, `MD`, `BC`, `BT`), 링크 칸(`linkMo`, `linkPc`, `linkAnd`, `linkIos` 각 max 500) |

**요청 예시**

```http
PUT /api/plugins/sirsoft-message_bizppurio/admin/templates/4 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "content": {
        "templateName": "주문 완료 안내",
        "templateMessageType": "BA",
        "templateEmphasizeType": "NONE",
        "templateContent": "#{user_name}님, 주문 #{order_id} 이(가) 결제 완료되었습니다.",
        "categoryCode": "004001"
    }
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| template | object | `{"id":4,"status":"draft","...":"상세 객체 필드 동일"}` | 상세 객체. 필드 구성은 `GET /admin/templates/{id}` 응답의 `template.*` 행과 같다 |

**응답 예시**

```json
{
    "success": true,
    "message": "알림 템플릿을 수정했습니다.",
    "data": {
        "template": { "id": 4, "status": "draft", "...": "상세 객체 필드 동일" }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 404 | Not Found | `{id}` 에 해당하는 템플릿이 없는 경우 |
| 422 | Unprocessable Entity | 검증 실패, 또는 content 변경 불가 상태 ("현재 상태(:status)에서는 알림톡 내용을 수정할 수 없습니다. …") |

<!-- @generated:end -->

**설명**

`content` 필드를 **포함한 요청**은 `draft`/`rejected` 상태에서만 허용된다 —
`requested` 는 검수 취소를, `approved` 는 승인 취소를 먼저 해야 하며, 위반 시 422 를 반환한다.
판정은 `content` 키의 포함 여부만 보므로, 기존 값과 동일한 `content` 를 다시 보내는 것도 거부된다.
발송 설정 필드(alimtalk_enabled/fallback_sms_enabled/sms_body/sms_only/is_active)는 라이프사이클과
무관하므로 상태와 관계없이 수정할 수 있다 — 단 그 경우 요청 본문에 `content` 를 싣지 않아야 한다.

`notification_type` 은 행 정체성이라 수정 대상이 아니다 — 전달돼도 무시된다.

본문에 `draft: true` 를 함께 실으면 등록(`POST /admin/templates`)과 같이 `content.*` 의 필수 조건만 걷어 내고 검증한다.


### PUT /api/plugins/sirsoft-message_bizppurio/admin/templates/delivery/{notificationType}
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.delivery -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.delivery`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@upsertDelivery`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| notificationType | path | string | 예 | max 100 · 알림 정의 존재(exists) | 코어 알림 정의 type |
| notification_type | body | string | 서버가 채움 | max 100, 알림 정의 존재(exists) | 클라이언트가 보내지 않습니다. 서버가 경로의 `notificationType` 으로 이 값을 채워 검증합니다 |
| alimtalk_enabled | body | boolean | 아니오 | — | 알림톡 사용 여부 |
| fallback_sms_enabled | body | boolean | 아니오 | — | SMS 대체발송 여부 |
| sms_only | body | boolean | 아니오 | — | SMS 단독 발송 여부 |
| is_active | body | boolean | 아니오 | — | 발송 활성 여부 |
| sms_body | body | array | 아니오 | nullable · 로케일별 max 2000 | 대체/단독 SMS 본문의 로케일 맵. 키는 시스템 지원 로케일(`app.translatable_locales`), 값은 각 언어 본문 |

**요청 예시**

```http
PUT /api/plugins/sirsoft-message_bizppurio/admin/templates/delivery/order_completed HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "fallback_sms_enabled": true,
    "sms_body": {"ko": "#{user_name}님, 주문이 완료되었습니다.", "en": "#{user_name}, your order is complete."}
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| template | object | `{"id":4,"notification_type":"order_completed","status":"draf…` | 상세 객체. 필드 구성은 `GET /admin/templates/{id}` 응답의 `template.*` 행과 같다 |

**응답 예시**

```json
{
    "success": true,
    "message": "알림 템플릿을 수정했습니다.",
    "data": {
        "template": { "id": 4, "notification_type": "order_completed", "status": "draft", "...": "상세 객체 필드 동일" }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | `notificationType` 에 해당하는 알림 정의가 없는 경우 등 검증 실패 |
| 404 | Not Found | 이 경로는 404 를 반환하지 않는다. 알림 정의가 없는 `notificationType` 은 422 로 끝나고, 아직 행이 없는 알림 유형은 `draft` 로 새로 만든다 |

<!-- @generated:end -->

**설명**

알림 설정 탭 행 하단 토글(알림톡 사용·대체 SMS·SMS 본문·SMS 단독·활성)의 즉시 저장 경로다.
대상 행이 없으면 `draft` 로 생성한다(upsert). 알림톡 content 는 이 경로로 변경할 수 없다(작성 모달 전용)
— content 를 건드리지 않으므로 상태 가드도 없다.


### POST /api/plugins/sirsoft-message_bizppurio/admin/templates/{id}/request
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.request -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.request`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@requestInspection`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자만 | 템플릿 PK |
| comment | body | string | 아니오 | 500자 이내 (앞뒤 공백 제거, 비어 있으면 미전달) | 검수자 전달 의견 — kapi `template/request` 의 comment 로 그대로 실린다. 행에는 저장하지 않는다 |
| content | body | array | 서버가 채움 | — | 클라이언트가 보내지 않습니다. 서버가 저장된 템플릿의 `content` 로 이 값을 채워(비어 있으면 `null`) 아래 `content.*` 규칙 전체로 검증하며, 임시 저장 완화는 없습니다 |
| content.templateName | body | string | `content` 있으면 필수 | max 200 | 템플릿명 |
| content.templateMessageType | body | string | `content` 있으면 필수 | `BA`(기본형), `EX`(부가정보형), `AD`(채널추가형), `MI`(복합형) | 메시지 유형 |
| content.templateEmphasizeType | body | string | `content` 있으면 필수 | `NONE`, `TEXT`(강조표기), `IMAGE`(이미지형), `ITEM_LIST`(아이템리스트) | 강조 유형 |
| content.templateContent | body | string | `content` 있으면 필수 | max 1000 | 알림톡 본문 |
| content.templatePreviewMessage | body | string | 아니오 | max 40 | 미리보기 문구 |
| content.categoryCode | body | string | `content` 있으면 필수 | max 20 | 카테고리 코드 (`GET /admin/alimtalk-templates/categories` 의 코드) |
| content.securityFlag | body | boolean | 아니오 | — | 보안 템플릿 여부 |
| content.templateExtra | body | string | messageType `EX`/`MI` 이면 필수 | — | 부가정보. 메시지 유형이 `EX`, `MI` 가 아니면 서버가 검증 전에 제거한다 |
| content.templateTitle | body | string | emphasizeType `TEXT` 이면 필수 | — | 강조 타이틀. `templateSubtitle` 과 필수쌍이며, 길이 수치는 미기재 항목이라 kapi 가 최종 게이트다 |
| content.templateSubtitle | body | string | emphasizeType `TEXT` 이면 필수 | — | 강조 서브타이틀. `templateTitle` 과 필수쌍 |
| content.templateHeader | body | string | 아니오 | max 16 | 헤더 |
| content.templateImageName | body | string | emphasizeType `IMAGE` 이면 필수 | — | 이미지 파일명. `templateImageUrl` 과 필수쌍 |
| content.templateImageUrl | body | string | emphasizeType `IMAGE` 이면 필수 | max 500 | 이미지 URL. 업로드 프록시(`POST /admin/templates/image`) 응답값만 기입한다 |
| content.templateItem | body | array | emphasizeType `ITEM_LIST` 이면 필수 | — | 아이템 리스트 (`list`, `summary`) |
| content.templateItem.list | body | array | `templateItem` 있으면 필수 | min 2, max 10 | 아이템 목록. 각 항목의 `title`(필수, max 6), `description`(필수, max 23) |
| content.templateItem.summary | body | array | 아니오 | — | 요약 (`title`, `description`) |
| content.templateItem.summary.title | body | string | 아니오 | max 6 | 요약 제목 |
| content.templateItem.summary.description | body | string | 아니오 | max 14 | 요약 설명 |
| content.templateItemHighlight | body | array | 아니오 | — | 하이라이트 (`title`, `description`, `imageUrl`). 길이 상한은 썸네일 유무로 갈린다 |
| content.templateItemHighlight.title | body | string | 아니오 | 썸네일 없으면 max 30, 있으면 max 21 | 하이라이트 타이틀 |
| content.templateItemHighlight.description | body | string | 아니오 | 썸네일 없으면 max 19, 있으면 max 13 | 하이라이트 설명 |
| content.templateItemHighlight.imageUrl | body | string | 아니오 | max 500 | 하이라이트 썸네일 이미지 URL |
| content.templateRepresentLink | body | array | 아니오 | — | 대표 링크 (`linkMo`, `linkPc`, `linkAnd`, `linkIos`) |
| content.templateRepresentLink.linkMo | body | string | 아니오 | max 500 | 모바일 링크 |
| content.templateRepresentLink.linkPc | body | string | 아니오 | max 500 | PC 링크 |
| content.templateRepresentLink.linkAnd | body | string | 아니오 | max 500 | Android 스킴 |
| content.templateRepresentLink.linkIos | body | string | 아니오 | max 500 | iOS 스킴 |
| content.buttons | body | array | 아니오 | max 5 | 버튼 목록. 각 항목의 `name`(필수, max 14), `linkType`(필수, `WL`, `AL`, `DS`, `BK`, `MD`, `AC`, `BC`, `BT`, `P1`, `P2`, `P3`, `TN`, `MP`), 링크 칸(`linkMo`, `linkPc`, `linkAnd`, `linkIos` 각 max 500), `telNumber`(max 20), `pluginId`(max 100) |
| content.quickReplies | body | array | 아니오 | max 10 | 바로연결 목록. 각 항목의 `name`(필수), `linkType`(필수, `WL`, `AL`, `BK`, `MD`, `BC`, `BT`), 링크 칸(`linkMo`, `linkPc`, `linkAnd`, `linkIos` 각 max 500) |

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/templates/4/request HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json
Authorization: Bearer {YOUR_TOKEN}

{
    "comment": "변수 예시: #{name}=홍길동, #{order_number}=20260823-1043226571"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| template | object | `{"id":4,"template_code":"g7_1a2b3c4d_1","status":"requested"…` | 상세 객체. 필드 구성은 `GET /admin/templates/{id}` 응답의 `template.*` 행과 같다 |

**응답 예시**

```json
{
    "success": true,
    "message": "검수를 신청했습니다. 승인 결과는 자동 동기화되며 [새로고침]으로 즉시 확인할 수 있습니다.",
    "data": {
        "template": {
            "id": 4,
            "template_code": "g7_1a2b3c4d_1",
            "status": "requested",
            "requested_at": "2026-08-19T10:00:00+09:00",
            "last_synced_at": "2026-08-19T10:00:00+09:00",
            "...": "상세 객체 필드 동일"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 404 | Not Found | `{id}` 에 해당하는 템플릿이 없는 경우 |
| 422 | Unprocessable Entity | comment 500자 초과 / content 미작성 / 신청 불가 상태(draft·rejected 외) / kapi 실패(`errors.bizppurio_message`·`errors.result_code`) / 발신프로필 키(sender_key) 미설정 / 템플릿 코드 채번 실패 |

<!-- @generated:end -->

**설명**

검수 신청의 전체 시퀀스: ① `template_code` 가 없으면(최초) 자체 채번 — `g7_{md5(notification_type) 앞 8자}_{세대}`
형식을 우리 DB 와 kapi codeCheck 양쪽에서 검증하고 충돌 시 세대를 올려 최대 3회 재시도 →
② kapi add(최초) 또는 update(재신청) 로 카카오에 등록 → ③ kapi request 로 검수 신청 → ④ `status=requested`.
add 성공 시점에만 template_code 를 행에 확정하므로, add 자체가 실패하면 다음 시도에서 다시 채번부터 진행된다.
`draft`/`rejected` 상태에서만 신청할 수 있으며(requested 는 이미 검수중, approved 는 승인 취소 먼저),
content 미작성이면 422 를 반환한다. 성공 시 `inspection_detail` 은 초기화된다.
요청 본문의 `comment`(선택, ≤500)는 ③ 의 kapi request 에 검수자 전달 의견으로 그대로 실리며 행에는 저장하지 않는다 —
카카오 심사 가이드가 본문 변수마다 요구하는 '예시 텍스트' 를 검수자에게 전할 유일한 통로다(kapi `template/add` 에는
예시 필드가 없다). 500자 초과는 kapi 호출 전에 422 로 끝나므로 행 상태와 선점은 바뀌지 않는다.


### POST /api/plugins/sirsoft-message_bizppurio/admin/templates/{id}/cancel-request
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.cancel-request -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.cancel-request`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@cancelRequest`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자만 | 템플릿 PK |

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/templates/4/cancel-request HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| template | object | `{"id":4,"status":"draft","...":"상세 객체 필드 동일"}` | 상세 객체. 필드 구성은 `GET /admin/templates/{id}` 응답의 `template.*` 행과 같다 |

**응답 예시**

```json
{
    "success": true,
    "message": "검수 신청을 취소했습니다.",
    "data": {
        "template": { "id": 4, "status": "draft", "...": "상세 객체 필드 동일" }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 404 | Not Found | `{id}` 에 해당하는 템플릿이 없는 경우 |
| 422 | Unprocessable Entity | `requested` 상태가 아닌 경우 / kapi 실패 |

<!-- @generated:end -->

**설명**

kapi 검수 취소(카카오측 REQ→REG)를 호출한 뒤 우리 상태를 `draft` 로 되돌린다.
`requested` 상태에서만 호출할 수 있다.


### POST /api/plugins/sirsoft-message_bizppurio/admin/templates/{id}/cancel-approval
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.cancel-approval -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.cancel-approval`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@cancelApproval`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자만 | 템플릿 PK |

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/templates/3/cancel-approval HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| template | object | `{"id":3,"status":"draft","approved_content":{"...":"스냅샷 유지"}…` | 상세 객체. 필드 구성은 `GET /admin/templates/{id}` 응답의 `template.*` 행과 같다 |

**응답 예시**

```json
{
    "success": true,
    "message": "승인을 취소했습니다. 이 알림의 알림톡 발송이 중단되었습니다.",
    "data": {
        "template": { "id": 3, "status": "draft", "approved_content": { "...": "스냅샷 유지" }, "...": "상세 객체 필드 동일" }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 404 | Not Found | `{id}` 에 해당하는 템플릿이 없는 경우 |
| 422 | Unprocessable Entity | `approved` 상태가 아닌 경우 / kapi 실패 |

<!-- @generated:end -->

**설명**

kapi 승인 취소(카카오측 승인→등록 복귀)를 호출한 뒤 우리 상태를 `draft` 로 되돌린다.
`approved_content` 스냅샷은 유지되지만 status 가 `approved` 를 벗어나므로 **알림톡 발송이 즉시 차단**된다
— 운영자 확인 모달이 이 효과를 사전 경고한다. `approved` 상태에서만 호출할 수 있다.


### POST /api/plugins/sirsoft-message_bizppurio/admin/templates/{id}/release
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.release -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.release`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@release`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자만 | 템플릿 PK |

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/templates/3/release HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| template | object | `{"id":3,"status":"approved","last_synced_at":"2026-08-19T10:…` | 상세 객체. 필드 구성은 `GET /admin/templates/{id}` 응답의 `template.*` 행과 같다 |

**응답 예시**

```json
{
    "success": true,
    "message": "휴면 상태를 해제했습니다.",
    "data": {
        "template": { "id": 3, "status": "approved", "last_synced_at": "2026-08-19T10:05:00+09:00", "...": "상세 객체 필드 동일" }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 404 | Not Found | `{id}` 에 해당하는 템플릿이 없는 경우 |
| 422 | Unprocessable Entity | `dormant` 상태가 아닌 경우 / kapi 실패 |

<!-- @generated:end -->

**설명**

kapi 휴면 해제를 호출한 뒤, 카카오측 실제 상태를 되받아 재동기화(sync)해 반영한다 —
응답의 status 는 해제 후 카카오가 알려준 실제 상태다. `dormant` 상태에서만 호출할 수 있다.


### POST /api/plugins/sirsoft-message_bizppurio/admin/templates/{id}/sync
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.sync -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.sync`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@sync`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자만 | 템플릿 PK |

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/templates/4/sync HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| template | object | `{"id":4,"status":"approved","approved_content":{"...":"승인 전이…` | 상세 객체. 필드 구성은 `GET /admin/templates/{id}` 응답의 `template.*` 행과 같다 |

**응답 예시**

```json
{
    "success": true,
    "message": "카카오 검수 상태를 동기화했습니다.",
    "data": {
        "template": {
            "id": 4,
            "status": "approved",
            "approved_content": { "...": "승인 전이 시 content 동결 스냅샷" },
            "approved_at": "2026-08-19T10:10:00+09:00",
            "last_synced_at": "2026-08-19T10:10:00+09:00",
            "...": "상세 객체 필드 동일"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 404 | Not Found | `{id}` 에 해당하는 템플릿이 없는 경우 |
| 422 | Unprocessable Entity | kapi 실패 |

<!-- @generated:end -->

**설명**

관리 화면 [새로고침] 버튼의 수동 상태 동기화다. kapi 상세를 조회해 카카오측 serviceStatus 를 우리
상태로 환원해 반영한다. **승인으로 전이하는 시점에 `content` 를 `approved_content` 로 동결**(발송 SSoT)하고
`approved_at` 을 확정하며, **반려 시 상세의 comments 를 `inspection_detail` 로 저장**한다.
알 수 없는 serviceStatus 는 상태를 덮어쓰지 않고 `last_synced_at` 만 갱신한다.
`template_code` 가 없는(카카오 등록 전) 행은 동기화 대상이 아니므로 행을 그대로 반환한다.
검수중(requested) 행 전체의 자동 동기화는 스케줄 커맨드(`bizppurio:sync-template-status`)가 담당한다.


### POST /api/plugins/sirsoft-message_bizppurio/admin/templates/image
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.image -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.image`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@uploadImage`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| image | body | file | 예 | jpg, jpeg, png, max 500 (KB), 가로 500px 이상, 가로:세로 = 2:1 | 이미지형(IMAGE) 템플릿용 이미지 파일 (multipart/form-data) |

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/templates/image HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----boundary

------boundary
Content-Disposition: form-data; name="image"; filename="banner.png"
Content-Type: image/png

(binary)
------boundary--
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `https://mud-kage.kakao.com/dn/example/img_1234567890.png` | 카카오가 반환한 이미지 URL — `content.templateImageUrl` 에 그대로 기입한다 |

**응답 예시**

```json
{
    "success": true,
    "message": "이미지를 업로드했습니다.",
    "data": {
        "url": "https://mud-kage.kakao.com/dn/example/img_1234567890.png"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 파일 형식/크기/치수/비율 위반 (프록시 단계 사전 검증) / kapi 업로드 실패 |

<!-- @generated:end -->

**설명**

이미지형(`templateEmphasizeType=IMAGE`) 템플릿용 이미지의 kapi 업로드 프록시다. kapi 제약
(jpg/png · ≤500KB · 가로 ≥500px · 가로:세로 2:1)을 프록시 단계에서 사전 검증해 kapi 왕복 없이
즉시 인라인 오류를 돌려준다. 성공 시 `data.url` 이 카카오 이미지 URL 이며, 화면은 이 값만
`content.templateImageUrl` 에 기입한다(임의 URL 기입 금지).

상한 500KB·가로 500px 은 **kapi 고정 제약**이며 운영자 설정으로 바뀌지 않습니다 — 설정 축을
열면 "설정을 올리면 더 큰 파일이 된다" 는 거짓 여지가 생깁니다. 두 값은
`BizppurioTemplateImageRequest::MAX_KILOBYTES` · `MIN_WIDTH` 공개 상수 하나에서 나오고, 화면
두 곳(플러그인 설정 · 알림 템플릿 폼 슬롯)의 안내 문구가 같은 값을 인용합니다. 드리프트
테스트가 세 자리(서버 상수 · 두 화면)를 함께 잠급니다.


### DELETE /api/plugins/sirsoft-message_bizppurio/admin/templates/{id}
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.templates.destroy -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.templates.destroy`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자만 | 템플릿 PK |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-message_bizppurio/admin/templates/4 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| kakao_deleted | boolean | `false` | 카카오측 템플릿도 함께 삭제했는지 여부 |
| kakao_skip_reason | string\|null | `state_not_deletable` | 카카오측 미삭제 사유: `not_registered`(카카오 등록 전) / `state_not_deletable`(삭제 가능 상태 아님) / kapi 삭제 실패 시 카카오 사유 원문 / 서버에 닿지 못했으면 연결 실패 안내 문장(`messages.error.connection_failed` 의 번역). 삭제했으면 null |

**응답 예시**

```json
{
    "success": true,
    "message": "알림 템플릿을 삭제했습니다.",
    "data": {
        "kakao_deleted": false,
        "kakao_skip_reason": "state_not_deletable"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |
| 404 | Not Found | `{id}` 에 해당하는 템플릿이 없는 경우 |

<!-- @generated:end -->

**설명**

DB 행은 항상 삭제한다. 카카오측 템플릿은 삭제 가능 상태일 때만 동반 삭제한다 — kapi delete 는
등록(REG)/반려(REJ) 상태에서만 허용되므로, 우리 상태가 `draft`/`rejected` 면 kapi 삭제를 시도하고,
그 외 상태(또는 kapi 삭제 실패)면 카카오측은 그대로 두고 DB 행만 삭제한다. 어느 쪽이든 응답의
`kakao_deleted`·`kakao_skip_reason` 으로 결과를 명시해 운영자가 알 수 있게 한다(kapi 실패가 있어도
이 엔드포인트는 422 가 아닌 200 으로 완료된다). 비즈뿌리오 서버에 닿지 못한 경우도 같다 — `kakao_skip_reason` 에
연결 실패 안내 문장(`messages.error.connection_failed` 키의 번역)을 적는다.
