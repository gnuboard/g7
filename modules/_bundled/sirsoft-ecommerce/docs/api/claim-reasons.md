# Claim Reasons API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Claim Reasons 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/claim-reasons
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claim-reasons.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claim-reasons.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimReasonController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/claim-reasons HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `8` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `1` | 기본 키 (내부 식별자) |
| type | string | `refund` | 클래임 사유 유형 (ClaimReasonTypeEnum — `refund`(환불/취소)) |
| code | string | `order_mistake` | 사유 식별 코드 (같은 type 내 고유, 영문 소문자/숫자/`_`) |
| name | object | `{"ko":"주문 실수","en":"Order Mistake","ja":"注文ミス"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `주문 실수` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| fault_type | string | `customer` | 귀책 구분 (ClaimReasonFaultTypeEnum — `customer`(고객)/`seller`(판매자)/`carrier`(배송사)) |
| fault_type_label | string | `고객 귀책` | `fault_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_user_selectable | boolean | `true` | user selectable 여부 |
| is_active | boolean | `true` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-05-27 15:20:43` | 생성 일시 |
| updated_at | string | `2026-06-27 00:49:51` | 최종 수정 일시 |
| creator | array | `[]` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| updater | array | `[]` | 최종 수정자 정보 객체 (id/name — updater 관계 로드 시) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "클래임 사유 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 8,
                "id": 1,
                "type": "refund",
                "code": "order_mistake",
                "name": {
                    "ko": "주문 실수",
                    "en": "Order Mistake"
                },
                "localized_name": "주문 실수",
                "fault_type": "customer",
                "fault_type_label": "고객 귀책",
                "is_user_selectable": true,
                "is_active": true,
                "sort_order": 0,
                "created_at": "2026-07-08 10:43:32",
                "updated_at": "2026-07-08 10:43:32",
                "creator": [],
                "updater": [],
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            {
                "number": 7,
                "id": 8,
                "type": "refund",
                "code": "apidoc_sample",
                "name": {
                    "ko": "API 문서 샘플 사유",
                    "en": "API Doc Sample Reason"
                },
                "localized_name": "API 문서 샘플 사유",
                "fault_type": "customer",
                "fault_type_label": "고객 귀책",
                "is_user_selectable": true,
                "is_active": true,
                "sort_order": 0,
                "created_at": "2026-07-08 10:44:49",
                "updated_at": "2026-07-08 10:44:49",
                "creator": [],
                "updater": [],
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            "... (총 8건 중 2건 표시)"
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 클래임(반품/교환/환불) 사유 마스터 목록을 조회합니다. `permission:sirsoft-ecommerce.settings.read` 권한이 필요하며, `type`(기본 refund)·`is_active`·`fault_type`·`search` 쿼리로 필터링할 수 있습니다. `ClaimReasonService::getAllReasons()`가 조회하고 `ClaimReasonCollection`으로 반환하며, 각 항목은 다국어 사유명(`name`)과 귀책 구분(`fault_type`: customer·seller·carrier), 사용자 노출 여부(`is_user_selectable`)를 포함합니다. 환경설정의 클래임 사유 관리 화면에서 사용됩니다.


### POST /api/modules/sirsoft-ecommerce/admin/claim-reasons
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claim-reasons.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claim-reasons.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimReasonController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | body | string | 예 | — | 클래임 사유 유형 (ClaimReasonTypeEnum — 현재 `refund`(환불/취소)) |
| code | body | string | 예 | max 50 | 사유 식별 코드 (영문 소문자/숫자/`_`, 같은 type 내에서 고유) |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| fault_type | body | string | 예 | — | 귀책 구분 (ClaimReasonFaultTypeEnum — `customer`(고객)/`seller`(판매자)/`carrier`(배송사)) |
| is_user_selectable | body | boolean | 아니오 | — | user selectable 여부 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/claim-reasons HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "type": "예시값",
    "code": "예시값",
    "name": [
        "예시 이름"
    ],
    "fault_type": "예시값",
    "is_user_selectable": true,
    "is_active": true,
    "sort_order": 1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 새 클래임 사유를 생성합니다. `permission:sirsoft-ecommerce.settings.update` 권한이 필요하며, 유형(`type`)·고유 코드(`code`)·다국어 사유명(`name`)·귀책 구분(`fault_type`)을 필수로 받고 사용자 노출 여부·활성 여부·정렬 순서를 선택 입력합니다. `ClaimReasonService::createReason()`이 저장하고 생성된 사유를 201로 반환합니다. `code` 는 유형 내에서 고유해야 하며 회원 취소/반품 화면의 사유 선택지로 활용됩니다.


### GET /api/modules/sirsoft-ecommerce/admin/claim-reasons/active
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claim-reasons.active -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claim-reasons.active`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimReasonController@active`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/claim-reasons/active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `8` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `1` | 기본 키 (내부 식별자) |
| type | string | `refund` | 클래임 사유 유형 (ClaimReasonTypeEnum — `refund`(환불/취소)) |
| code | string | `order_mistake` | 사유 식별 코드 (같은 type 내 고유, 영문 소문자/숫자/`_`) |
| name | object | `{"ko":"주문 실수","en":"Order Mistake","ja":"注文ミス"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `주문 실수` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| fault_type | string | `customer` | 귀책 구분 (ClaimReasonFaultTypeEnum — `customer`(고객)/`seller`(판매자)/`carrier`(배송사)) |
| fault_type_label | string | `고객 귀책` | `fault_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_user_selectable | boolean | `true` | user selectable 여부 |
| is_active | boolean | `true` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-05-27 15:20:43` | 생성 일시 |
| updated_at | string | `2026-06-27 00:49:51` | 최종 수정 일시 |
| creator | array | `[]` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| updater | array | `[]` | 최종 수정자 정보 객체 (id/name — updater 관계 로드 시) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "클래임 사유 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 8,
                "id": 1,
                "type": "refund",
                "code": "order_mistake",
                "name": {
                    "ko": "주문 실수",
                    "en": "Order Mistake"
                },
                "localized_name": "주문 실수",
                "fault_type": "customer",
                "fault_type_label": "고객 귀책",
                "is_user_selectable": true,
                "is_active": true,
                "sort_order": 0,
                "created_at": "2026-07-08 10:43:32",
                "updated_at": "2026-07-08 10:43:32",
                "creator": [],
                "updater": [],
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            {
                "number": 7,
                "id": 8,
                "type": "refund",
                "code": "apidoc_sample",
                "name": {
                    "ko": "API 문서 샘플 사유",
                    "en": "API Doc Sample Reason"
                },
                "localized_name": "API 문서 샘플 사유",
                "fault_type": "customer",
                "fault_type_label": "고객 귀책",
                "is_user_selectable": true,
                "is_active": true,
                "sort_order": 0,
                "created_at": "2026-07-08 10:44:49",
                "updated_at": "2026-07-08 10:44:49",
                "creator": [],
                "updater": [],
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            "... (총 8건 중 2건 표시)"
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 활성화된 클래임 사유만 추려 Select 옵션용으로 조회합니다. `permission:sirsoft-ecommerce.settings.read` 권한이 필요하며, `type` 쿼리(기본 refund)로 유형을 지정하면 `ClaimReasonService::getActiveReasons()`가 `is_active=true` 인 사유만 반환합니다. 관리자 화면에서 환불/취소 처리 시 사유 드롭다운을 채우는 용도로, 목록(index)과 달리 필터 없이 활성 사유만 내려주는 점이 다릅니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/claim-reasons/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claim-reasons.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claim-reasons.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimReasonController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/claim-reasons/8 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| reason_id | integer | `8` | reason 식별자 (연관 리소스 참조) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "클래임 사유가 삭제되었습니다.",
    "data": {
        "reason_id": 8
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 클래임 사유 1건을 삭제합니다. `permission:sirsoft-ecommerce.settings.update` 권한이 필요하며, 대상이 없으면 404 를 반환하고 존재하면 `ClaimReasonService::deleteReason()`이 삭제합니다. 이미 사용 중인 사유 등 삭제 불가 상황에서는 서비스가 던진 예외 메시지를 그대로 사용해 400 으로 응답하므로, 관리자에게 삭제 실패 사유가 노출됩니다.


### GET /api/modules/sirsoft-ecommerce/admin/claim-reasons/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claim-reasons.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claim-reasons.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimReasonController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/claim-reasons/8 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `8` | 기본 키 (내부 식별자) |
| type | string | `refund` | 사유 유형 (refund, exchange, return 등) |
| code | string | `apidoc_sample` | 고유 코드 (order_mistake 등) |
| name | object | `{"ko":"API 문서 샘플 사유","en":"API Doc Sample Reason"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 사유` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| fault_type | string | `customer` | 귀책 구분 (customer, seller, carrier) |
| fault_type_label | string | `고객 귀책` | `fault_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_user_selectable | boolean | `true` | user selectable 여부 |
| is_active | boolean | `true` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 10:44:49` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "클래임 사유 정보를 조회했습니다.",
    "data": {
        "id": 8,
        "type": "refund",
        "code": "apidoc_sample",
        "name": {
            "ko": "API 문서 샘플 사유",
            "en": "API Doc Sample Reason"
        },
        "localized_name": "API 문서 샘플 사유",
        "fault_type": "customer",
        "fault_type_label": "고객 귀책",
        "is_user_selectable": true,
        "is_active": true,
        "sort_order": 0,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 10:44:49",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 클래임 사유 1건의 상세 정보를 조회합니다. `permission:sirsoft-ecommerce.settings.read` 권한이 필요하며, `ClaimReasonService::getReason()`이 대상을 조회해 `ClaimReasonResource`로 반환합니다. 사유 편집 폼을 열 때 기존 값(다국어 사유명·코드·귀책 구분·활성/노출 설정 등)을 채우는 용도로 사용되며, 해당 사유가 없으면 404 를 반환합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/claim-reasons/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claim-reasons.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claim-reasons.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimReasonController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| type | body | string | 예 | — | 클래임 사유 유형 (ClaimReasonTypeEnum — 현재 `refund`(환불/취소)) |
| code | body | string | 예 | max 50 | 사유 식별 코드 (영문 소문자/숫자/`_`, 같은 type 내에서 고유) |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| fault_type | body | string | 예 | — | 귀책 구분 (ClaimReasonFaultTypeEnum — `customer`(고객)/`seller`(판매자)/`carrier`(배송사)) |
| is_user_selectable | body | boolean | 아니오 | — | user selectable 여부 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/claim-reasons/8 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "type": "예시값",
    "code": "예시값",
    "name": [
        "예시 이름"
    ],
    "fault_type": "예시값",
    "is_user_selectable": true,
    "is_active": true,
    "sort_order": 1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 기존 클래임 사유를 수정합니다. `permission:sirsoft-ecommerce.settings.update` 권한이 필요하고 대상은 경로 `id` 로 지정하며, 생성과 동일한 필드(유형·코드·다국어 사유명·귀책 구분·노출/활성/정렬)를 받아 `ClaimReasonService::updateReason()`이 갱신하고 갱신된 사유를 반환합니다. 대상이 없거나 갱신 실패 시 400 오류로 응답합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/claim-reasons/{id}/toggle-status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claim-reasons.toggle-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claim-reasons.toggle-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimReasonController@toggleStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/claim-reasons/8/toggle-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `8` | 기본 키 (내부 식별자) |
| type | string | `refund` | 사유 유형 (refund, exchange, return 등) |
| code | string | `apidoc_sample` | 고유 코드 (order_mistake 등) |
| name | object | `{"ko":"API 문서 샘플 사유","en":"API Doc Sample Reason"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 사유` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| fault_type | string | `customer` | 귀책 구분 (customer, seller, carrier) |
| fault_type_label | string | `고객 귀책` | `fault_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_user_selectable | boolean | `true` | user selectable 여부 |
| is_active | boolean | `false` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:18` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "클래임 사유 상태가 변경되었습니다.",
    "data": {
        "id": 8,
        "type": "refund",
        "code": "apidoc_sample",
        "name": {
            "ko": "API 문서 샘플 사유",
            "en": "API Doc Sample Reason"
        },
        "localized_name": "API 문서 샘플 사유",
        "fault_type": "customer",
        "fault_type_label": "고객 귀책",
        "is_user_selectable": true,
        "is_active": false,
        "sort_order": 0,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:00:18",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 클래임 사유의 활성 상태를 켜고 끄는 토글을 수행합니다. `permission:sirsoft-ecommerce.settings.update` 권한이 필요하며, `ClaimReasonService::toggleStatus()`가 대상 사유의 `is_active` 값을 반전시켜 저장하고 갱신된 사유를 반환합니다. 사유를 삭제하지 않고 일시적으로 회원 선택지에서 감추거나 다시 노출할 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/user/claim-reasons
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.claim-reasons.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.claim-reasons.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimReasonController@userSelectableReasons`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-ecommerce.user-orders.cancel`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/claim-reasons HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `7` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `1` | 기본 키 (내부 식별자) |
| type | string | `refund` | 클래임 사유 유형 (ClaimReasonTypeEnum — `refund`(환불/취소)) |
| code | string | `order_mistake` | 사유 식별 코드 (같은 type 내 고유, 영문 소문자/숫자/`_`) |
| name | object | `{"ko":"주문 실수","en":"Order Mistake","ja":"注文ミス"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `주문 실수` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| fault_type | string | `customer` | 귀책 구분 (ClaimReasonFaultTypeEnum — `customer`(고객)/`seller`(판매자)/`carrier`(배송사)) |
| fault_type_label | string | `고객 귀책` | `fault_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_user_selectable | boolean | `true` | user selectable 여부 |
| is_active | boolean | `true` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-05-27 15:20:43` | 생성 일시 |
| updated_at | string | `2026-06-27 00:49:51` | 최종 수정 일시 |
| creator | array | `[]` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| updater | array | `[]` | 최종 수정자 정보 객체 (id/name — updater 관계 로드 시) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "클래임 사유 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 7,
                "id": 1,
                "type": "refund",
                "code": "order_mistake",
                "name": {
                    "ko": "주문 실수",
                    "en": "Order Mistake"
                },
                "localized_name": "주문 실수",
                "fault_type": "customer",
                "fault_type_label": "고객 귀책",
                "is_user_selectable": true,
                "is_active": true,
                "sort_order": 0,
                "created_at": "2026-07-08 10:43:32",
                "updated_at": "2026-07-08 10:43:32",
                "creator": [],
                "updater": [],
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            {
                "number": 6,
                "id": 8,
                "type": "refund",
                "code": "apidoc_sample",
                "name": {
                    "ko": "API 문서 샘플 사유",
                    "en": "API Doc Sample Reason"
                },
                "localized_name": "API 문서 샘플 사유",
                "fault_type": "customer",
                "fault_type_label": "고객 귀책",
                "is_user_selectable": true,
                "is_active": true,
                "sort_order": 0,
                "created_at": "2026-07-08 10:44:49",
                "updated_at": "2026-07-08 10:44:49",
                "creator": [],
                "updater": [],
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            "... (총 7건 중 2건 표시)"
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-orders.cancel`)이 없는 경우 |

<!-- @generated:end -->

**설명** 회원(및 선택적으로 비회원)이 주문 취소/반품 신청 화면에서 선택할 수 있는 클래임 사유 목록을 조회합니다. `optional.sanctum`(회원/비회원 모두 접근)과 `permission:sirsoft-ecommerce.user-orders.cancel` 권한이 적용되며, `ClaimReasonService::getUserSelectableReasons()`가 활성이면서 `is_user_selectable=true` 인 사유만 반환합니다. 관리 전용 목록과 달리 사용자에게 공개 가능한 사유만 내려주는 사용자향 엔드포인트입니다.


