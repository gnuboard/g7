# Shipping Carriers API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Shipping Carriers 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/shipping-carriers
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-carriers.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-carriers.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingCarrierController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/shipping-carriers HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `13` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `1` | 기본 키 (내부 식별자) |
| code | string | `cj` | 배송사 고유 코드 (소문자 시작 영숫자·하이픈/언더스코어, 시스템 식별용) |
| name | object | `{"ko":"CJ대한통운","en":"CJ Logistics","ja":"CJ大韓通運"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `CJ대한통운` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| type | string | `domestic` | 배송사 유형 (`domestic` 국내 / `international` 해외) |
| type_label | string | `국내` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| tracking_url | string | `https://trace.cjlogistics.com/next/tr…` | tracking URL |
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
    "message": "배송사 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 13,
                "id": 13,
                "code": "apidoc",
                "name": {
                    "ko": "API 문서 샘플 배송사",
                    "en": "API Doc Sample Carrier"
                },
                "localized_name": "API 문서 샘플 배송사",
                "type": "domestic",
                "type_label": "국내",
                "tracking_url": null,
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
            {
                "number": 12,
                "id": 1,
                "code": "cj",
                "name": {
                    "ko": "CJ대한통운",
                    "en": "CJ Logistics"
                },
                "localized_name": "CJ대한통운",
                "type": "domestic",
                "type_label": "국내",
                "tracking_url": "https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}",
                "is_active": true,
                "sort_order": 1,
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
            "... (총 13건 중 2건 표시)"
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

**설명** 관리자가 등록된 배송사 목록을 조회합니다. 배송 설정 영역이므로 `sirsoft-ecommerce.settings.read` 권한이 필요하며, `ShippingCarrierService::getAllCarriers()` 가 요청 파라미터로 목록을 조회해 `ShippingCarrierCollection` 으로 반환합니다. 각 항목에는 코드·다국어 배송사명·유형(국내/해외)·추적 URL·활성여부·정렬순서가 포함됩니다. 배송사 관리 화면의 목록을 채우는 데 사용합니다.


### POST /api/modules/sirsoft-ecommerce/admin/shipping-carriers
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-carriers.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-carriers.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingCarrierController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| code | body | string | 예 | max 50 | 배송사 고유 코드 (소문자 시작 영숫자·하이픈/언더스코어, 중복 불가) |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| type | body | string | 예 | — | 배송사 유형 (`domestic` 국내 / `international` 해외) |
| tracking_url | body | string | 아니오 | max 500 | 배송 추적 URL 템플릿 (`{tracking_number}` 치환자를 운송장 번호로 대체) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.shipping_carrier.create_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/shipping-carriers HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "code": "예시값",
    "name": [
        "예시 이름"
    ],
    "type": "예시값",
    "tracking_url": "https://example.com",
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

**설명** 관리자가 새 배송사를 등록합니다. `sirsoft-ecommerce.settings.update` 권한이 필요하며, `ShippingCarrierService::createCarrier()` 가 고유 코드(`code`), 다국어 배송사명(`name`), 유형(`type`, 국내/해외), 배송 추적 URL 템플릿, 활성여부, 정렬순서를 저장하고 201 로 생성된 배송사 리소스를 반환합니다. `tracking_url` 에는 `{tracking_number}` 치환자를 넣어 추적 링크를 구성합니다. 새 택배사/특송사를 시스템에 추가할 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/admin/shipping-carriers/active
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-carriers.active -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-carriers.active`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingCarrierController@active`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/shipping-carriers/active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| value | integer | `1` | 배송사 ID (Select 옵션의 value) |
| label | string | `CJ대한통운` | 표시용 라벨 |
| code | string | `cj` | 배송사 고유 코드 (시스템 식별용) |
| type | string | `domestic` | 배송사 유형 (`domestic` 국내 / `international` 해외) |
| tracking_url | string | `https://trace.cjlogistics.com/next/tr…` | tracking URL |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배송사 목록을 조회했습니다.",
    "data": [
        {
            "value": 13,
            "label": "API 문서 샘플 배송사",
            "code": "apidoc",
            "type": "domestic",
            "tracking_url": null
        },
        {
            "value": 1,
            "label": "CJ대한통운",
            "code": "cj",
            "type": "domestic",
            "tracking_url": "https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}"
        },
        {
            "value": 2,
            "label": "한진택배",
            "code": "hanjin",
            "type": "domestic",
            "tracking_url": "https://www.hanjin.com/kor/CMS/DeliveryMgr/WaybillResult.do?wblnb={tracking_number}"
        },
        {
            "value": 3,
            "label": "롯데택배",
            "code": "lotte",
            "type": "domestic",
            "tracking_url": "https://www.lotteglogis.com/home/reservation/tracking/link498?InvNo={tracking_number}"
        },
        {
            "value": 4,
            "label": "로젠택배",
            "code": "logen",
            "type": "domestic",
            "tracking_url": "https://www.ilogen.com/web/personal/trace/{tracking_number}"
        },
        {
            "value": 5,
            "label": "UPS",
            "code": "ups",
            "type": "international",
            "tracking_url": "https://www.ups.com/track?tracknum={tracking_number}"
        },
        {
            "value": 6,
            "label": "EMS",
            "code": "ems",
            "type": "international",
            "tracking_url": "https://service.epost.go.kr/trace.RetrieveEmsRi498.postal?POST_CODE={tracking_number}"
        },
        {
            "value": 7,
            "label": "DHL",
            "code": "dhl",
            "type": "international",
            "tracking_url": "https://www.dhl.com/kr-ko/home/tracking/tracking-express.html?submit=1&tracking-id={tracking_number}"
        },
        {
            "value": 8,
            "label": "FedEx",
            "code": "fedex",
            "type": "international",
            "tracking_url": "https://www.fedex.com/fedextrack/?tracknumbers={tracking_number}"
        },
        {
            "value": 9,
            "label": "SF Express",
            "code": "sf",
            "type": "international",
            "tracking_url": null
        },
        {
            "value": 10,
            "label": "야마토운수",
            "code": "yamato",
            "type": "international",
            "tracking_url": null
        },
        {
            "value": 11,
            "label": "사가와익스프레스",
            "code": "sagawa",
            "type": "international",
            "tracking_url": null
        },
        {
            "value": 12,
            "label": "기타",
            "code": "other",
            "type": "domestic",
            "tracking_url": null
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 활성화된 배송사만 Select 옵션 형태로 조회합니다. `sirsoft-ecommerce.settings.read` 권한이 필요하며, `ShippingCarrierService::getActiveCarriers()` 결과를 `{value, label, code, type, tracking_url}` 로 매핑해 반환합니다. `type` 쿼리(domestic/international)로 국내/해외 배송사를 필터링할 수 있습니다. 송장 등록 등에서 배송사를 선택하는 드롭다운을 채우는 데 사용하며, 비활성 배송사는 노출되지 않습니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/shipping-carriers/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-carriers.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-carriers.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingCarrierController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/shipping-carriers/13 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| carrier_id | integer | `13` | carrier 식별자 (연관 리소스 참조) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배송사가 삭제되었습니다.",
    "data": {
        "carrier_id": 13
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

**설명** 관리자가 배송사 1건을 삭제합니다. `sirsoft-ecommerce.settings.update` 권한이 필요하며, 컨트롤러가 `getCarrier()` 로 배송사를 조회해 없으면 404 를 반환한 뒤 `ShippingCarrierService::deleteCarrier()` 로 제거합니다. 주문/송장에서 사용 중이어서 삭제할 수 없는 경우 서비스 예외 메시지와 함께 400 을 반환합니다. 더 이상 쓰지 않는 배송사를 정리할 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/admin/shipping-carriers/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-carriers.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-carriers.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingCarrierController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/shipping-carriers/13 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `13` | 기본 키 (내부 식별자) |
| code | string | `apidoc` | 고유 코드 (cj, fedex 등) |
| name | object | `{"ko":"API 문서 샘플 배송사","en":"API Doc Sample Carrier"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 배송사` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| type | string | `domestic` | 배송사 유형: domestic(국내), international(해외) |
| type_label | string | `국내` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| tracking_url | null | `null` | tracking URL |
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
    "message": "배송사 정보를 조회했습니다.",
    "data": {
        "id": 13,
        "code": "apidoc",
        "name": {
            "ko": "API 문서 샘플 배송사",
            "en": "API Doc Sample Carrier"
        },
        "localized_name": "API 문서 샘플 배송사",
        "type": "domestic",
        "type_label": "국내",
        "tracking_url": null,
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

**설명** 관리자가 배송사 1건의 상세 정보를 조회합니다. `sirsoft-ecommerce.settings.read` 권한이 필요하며, `ShippingCarrierService::getCarrier()` 가 배송사를 조회해 없으면 404 를 반환하고, 있으면 코드·다국어명·유형·추적 URL·활성여부 등을 담은 단건 리소스를 반환합니다. 배송사 수정 화면 진입 시 기존 값을 불러오는 데 사용합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/shipping-carriers/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-carriers.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-carriers.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingCarrierController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| code | body | string | 아니오 | max 50 | 배송사 고유 코드 (부분 수정 시 전달, 중복 불가) |
| name | body | array | 아니오 | — | 대상의 이름/명칭 |
| type | body | string | 아니오 | — | 배송사 유형 (`domestic` 국내 / `international` 해외) |
| tracking_url | body | string | 아니오 | max 500 | 배송 추적 URL 템플릿 (`{tracking_number}` 치환자를 운송장 번호로 대체) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.shipping_carrier.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/shipping-carriers/13 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "code": "예시값",
    "name": [
        "예시 이름"
    ],
    "type": "예시값",
    "tracking_url": "https://example.com",
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

**설명** 관리자가 기존 배송사 정보를 수정합니다. `sirsoft-ecommerce.settings.update` 권한이 필요하며, `ShippingCarrierService::updateCarrier()` 가 코드·다국어명·유형·추적 URL·활성여부·정렬순서 중 전달된 값을 갱신하고 수정된 리소스를 반환합니다(모든 body 필드는 선택적, 부분 수정 가능). 대상 배송사가 없거나 갱신에 실패하면 각각 404/400 을 반환합니다. 배송사의 추적 URL이나 표시명을 변경할 때 사용합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/shipping-carriers/{id}/toggle-status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-carriers.toggle-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-carriers.toggle-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingCarrierController@toggleStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/shipping-carriers/13/toggle-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `13` | 기본 키 (내부 식별자) |
| code | string | `apidoc` | 고유 코드 (cj, fedex 등) |
| name | object | `{"ko":"API 문서 샘플 배송사","en":"API Doc Sample Carrier"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| localized_name | string | `API 문서 샘플 배송사` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| type | string | `domestic` | 배송사 유형: domestic(국내), international(해외) |
| type_label | string | `국내` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| tracking_url | null | `null` | tracking URL |
| is_active | boolean | `false` | active 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:34` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.shipping_carriers.status_changed",
    "data": {
        "id": 13,
        "code": "apidoc",
        "name": {
            "ko": "API 문서 샘플 배송사",
            "en": "API Doc Sample Carrier"
        },
        "localized_name": "API 문서 샘플 배송사",
        "type": "domestic",
        "type_label": "국내",
        "tracking_url": null,
        "is_active": false,
        "sort_order": 0,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:00:34",
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

**설명** 관리자가 배송사 1건의 활성 상태를 토글합니다. `sirsoft-ecommerce.settings.update` 권한이 필요하며, `ShippingCarrierService::toggleStatus()` 가 현재 활성 여부를 반전시키고 갱신된 배송사 리소스를 반환합니다. 대상 배송사가 없거나 처리에 실패하면 각각 404/400 을 반환합니다. 목록 화면에서 배송사 사용여부 스위치를 켜고 끌 때 사용합니다.


