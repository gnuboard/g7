# Shipping Policies API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Shipping Policies 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/shipping-policies
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search | query | string | 아니오 | max 200 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| shipping_methods | query | array | 아니오 | — | 배송방법 코드로 필터 (ShippingType 코드 배열, 국가별 설정 중 하나라도 매치되는 정책만) |
| charge_policies | query | array | 아니오 | — | 배송비 부과정책으로 필터 (free/fixed/conditional_free/range_*/api/per_* 등, 국가별 설정 매치) |
| countries | query | array | 아니오 | — | 배송 국가 코드로 필터 (ISO 코드 배열, 해당 국가 설정을 가진 정책만) |
| is_active | query | string | 아니오 | ``, `true`, `false` | 활성 여부 (true 활성 / false 비활성) |
| sort_by | query | string | 아니오 | `id`, `name`, `is_active`, `sort_order`, `created_at`, `updated_at` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| per_page | query | integer | 아니오 | min 10, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.shipping_policy.list_validation_rules`, `sirsoft-ecommerce.shipping_policy.list_validation_messages`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/shipping-policies?search=%EC%98%88%EC%8B%9C%EA%B0%92&shipping_methods=%EC%98%88%EC%8B%9C%EA%B0%92&charge_policies=%EC%98%88%EC%8B%9C%EA%B0%92&countries=KR&is_active=%2C%20&sort_by=id&sort_order=asc&per_page=1&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `17` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `47` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 배송정책","en":"API Doc Sample Shipping Poli…` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `API 문서 샘플 배송정책` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| country_settings | array | `[]` | 국가별 배송 설정 목록 (countrySettings 관계 로드 시 각 국가의 배송방식·부과정책·배송비 상세) |
| fee_summary | string | `` | 활성 국가별 설정을 종합한 배송비 요약 텍스트 (예: `KR: 3000원 \| US: $20`, 활성 설정 없으면 빈 문자열) |
| countries_display | string | `` | 활성 배송 국가를 국기 이모지로 표시한 문자열 (최대 3개 노출, 초과분은 `+N` 축약) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `false` | default 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-07 14:47:31` | 생성 일시 |
| updated_at | string | `2026-07-07 14:47:31` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배송정책 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 1,
                "id": 1,
                "name": {
                    "ko": "API 문서 샘플 배송정책",
                    "en": "API Doc Sample Shipping Policy"
                },
                "name_localized": "API 문서 샘플 배송정책",
                "country_settings": [],
                "fee_summary": "",
                "countries_display": "",
                "is_active": true,
                "is_default": false,
                "sort_order": 0,
                "created_at": "2026-07-08 10:44:49",
                "updated_at": "2026-07-08 10:44:49",
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            }
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        },
        "statistics": {
            "total": 1,
            "active": 1,
            "inactive": 0,
            "shipping_method": [],
            "charge_policy": []
        },
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 1,
            "from": 1,
            "to": 1,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 배송정책 목록을 페이지네이션으로 조회합니다. `sirsoft-ecommerce.shipping-policies.read` 권한이 필요하며, `ShippingPolicyService::getList()` 가 검색어·배송방식·부과정책·국가·활성여부 필터와 정렬을 적용하고, 함께 `getStatistics()` 로 집계 통계를 계산해 `ShippingPolicyCollection` 에 담아 반환합니다. 각 항목의 `abilities` 로 생성/수정/삭제 가능 여부가 내려옵니다. 배송정책 관리 목록 화면을 채우는 데 사용합니다.


### POST /api/modules/sirsoft-ecommerce/admin/shipping-policies
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| is_active | body | boolean | 예 | — | 활성 여부 (true 활성 / false 비활성) |
| is_default | body | boolean | 아니오 | — | 기본값 지정 여부 |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |
| country_settings | body | array | 예 | min 1 | 국가별 배송 설정 배열 (최소 1개). 각 항목에 국가코드·배송방식·부과정책(charge_policy)·배송비·구간/API/도서산간 설정을 담음 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.shipping_policy.store_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/shipping-policies HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "is_active": true,
    "is_default": true,
    "sort_order": 1,
    "country_settings": [
        "KR"
    ]
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 새 배송정책을 생성합니다. `sirsoft-ecommerce.shipping-policies.create` 권한이 필요하며, `ShippingPolicyService::create()` 가 다국어 정책명(`name`), 활성여부, 기본여부, 정렬순서, 국가별 설정(`country_settings`, 최소 1개)을 저장하고 201 로 생성된 정책 리소스를 반환합니다. 국가별로 배송방식·배송비 부과정책을 담은 배송정책을 새로 등록할 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/admin/shipping-policies/active
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.active -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.active`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@activeList`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/shipping-policies/active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| value | integer | `46` | 배송정책 ID (Select 옵션의 value) |
| label | string | `sdfsf` | 표시용 라벨 |
| countries_display | string | `🇰🇷` | 활성 배송 국가를 국기 이모지로 표시한 문자열 (최대 3개, 초과분 `+N`) |
| fee_summary | string | `KR: 외부 API 연동 (실시간 계산)` | 국가별 배송비 요약 텍스트 (`country_code: fee` 형태를 ` \| ` 로 결합) |
| is_default | boolean | `false` | default 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.shipping_policy.active_list_retrieved",
    "data": [
        {
            "value": 1,
            "label": "API 문서 샘플 배송정책",
            "countries_display": "",
            "fee_summary": "",
            "is_default": false
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 활성화된 배송정책만 Select 옵션 형태로 조회합니다. `sirsoft-ecommerce.shipping-policies.read` 권한이 필요하며, `ShippingPolicyService::getActiveList()` 결과를 `{value, label, countries_display, fee_summary, is_default}` 로 매핑해 반환합니다. 상품 등록/수정 폼 등에서 배송정책을 선택하는 드롭다운을 채우는 데 사용하며, 비활성 정책은 노출되지 않습니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.bulk-destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.bulk-destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.shipping_policy.bulk_delete_validation_rules`).

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 선택한 여러 배송정책을 한 번에 삭제합니다. `sirsoft-ecommerce.shipping-policies.delete` 권한이 필요하며, `ShippingPolicyService::bulkDelete()` 가 `ids`(최소 1개)에 해당하는 정책들을 삭제하고 `deleted_count` 를 반환합니다. 목록 화면에서 체크박스로 다건 선택 후 일괄 삭제할 때 사용합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk-toggle-active
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.bulk-toggle-active -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.bulk-toggle-active`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@bulkToggleActive`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| is_active | body | boolean | 예 | — | 활성 여부 (true 활성 / false 비활성) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.shipping_policy.bulk_toggle_active_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk-toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "is_active": true
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 선택한 여러 배송정책의 활성 상태를 한 번에 변경합니다. `sirsoft-ecommerce.shipping-policies.update` 권한이 필요하며, `ShippingPolicyService::bulkToggleActive()` 가 `ids`(최소 1개)에 해당하는 정책들을 `is_active` 값으로 일괄 활성/비활성 처리하고 `updated_count` 를 반환합니다. 목록 화면에서 다건 선택 후 사용여부를 한꺼번에 켜거나 끌 때 사용합니다.


### POST /api/modules/sirsoft-ecommerce/admin/shipping-policies/test-api-call
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.test-api-call -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.test-api-call`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@testApiCall`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| endpoint | body | string | 예 | max 500 | 테스트로 호출할 외부 배송비 계산 API 엔드포인트 URL |
| request_fields | body | array | 아니오 | — | 요청에 실어 보낼 필드명 목록 (후보 SSoT ShippingApiRequestField 5종) |
| config | body | array | 아니오 | — | API 호출 고급 설정 (HTTP 메서드·인증방식·필드 매핑·응답 형식/경로 등) |
| sample | body | array | 아니오 | — | 테스트 계산에 사용할 샘플 주문 데이터 (무게/금액/수량 등) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/shipping-policies/test-api-call HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "endpoint": "예시값",
    "request_fields": [
        "예시값"
    ],
    "config": [
        "예시값"
    ],
    "sample": [
        "예시값"
    ]
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 배송정책 편집 폼에서 입력 중인 설정으로 외부 배송비 계산 API 를 1회 실호출해 미리 테스트합니다. `sirsoft-ecommerce.shipping-policies.update` 권한이 필요하며, `OrderCalculationService::testApiCall()` 이 `endpoint`·`config`·`request_fields`·`sample` 을 사용해 실제 요청을 보내고 요청 미리보기, 응답, 추출된 배송비를 반환합니다. 타임아웃과 응답 크기 제한이 적용됩니다. 실시간 계산형 배송정책을 저장하기 전에 API 연동이 올바른지 검증할 때 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/shipping-policies/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배송정책이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 배송정책 1건을 삭제합니다. `sirsoft-ecommerce.shipping-policies.delete` 권한이 필요하며, 컨트롤러가 `getDetail()` 로 정책을 조회해 없으면 404 를 반환한 뒤 `ShippingPolicyService::delete()` 로 제거합니다. 사용 중인 정책이라 삭제할 수 없는 등 실패 시 400 을 반환합니다. 더 이상 쓰지 않는 배송정책을 개별 정리할 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/shipping-policies/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 배송정책","en":"API Doc Sample Shipping Poli…` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `API 문서 샘플 배송정책` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| country_settings | array | `[]` | 국가별 배송 설정 목록 (countrySettings 관계 로드 시 각 국가의 배송방식·부과정책·배송비 상세) |
| fee_summary | string | `` | 활성 국가별 설정을 종합한 배송비 요약 텍스트 (예: `KR: 3000원 \| US: $20`, 활성 설정 없으면 빈 문자열) |
| countries_display | string | `` | 활성 배송 국가를 국기 이모지로 표시한 문자열 (최대 3개 노출, 초과분은 `+N` 축약) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `false` | default 여부 |
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
    "message": "배송정책 정보를 조회했습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 배송정책",
            "en": "API Doc Sample Shipping Policy"
        },
        "name_localized": "API 문서 샘플 배송정책",
        "country_settings": [],
        "fee_summary": "",
        "countries_display": "",
        "is_active": true,
        "is_default": false,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 배송정책 1건의 상세 정보를 조회합니다. `sirsoft-ecommerce.shipping-policies.read` 권한이 필요하며, `ShippingPolicyService::getDetail()` 이 정책을 조회해 없으면 404 를 반환하고, 있으면 다국어 정책명·국가별 설정·배송비 요약 등을 담은 단건 리소스를 반환합니다. 배송정책 수정 화면 진입 시 기존 값을 불러오는 데 사용합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| is_active | body | boolean | 예 | — | 활성 여부 (true 활성 / false 비활성) |
| is_default | body | boolean | 아니오 | — | 기본값 지정 여부 |
| sort_order | body | integer | 아니오 | min 0 | 표시 정렬 순서 값 (작을수록 우선) |
| country_settings | body | array | 예 | min 1 | 국가별 배송 설정 배열 (최소 1개). 각 항목에 국가코드·배송방식·부과정책(charge_policy)·배송비·구간/API/도서산간 설정을 담음 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.shipping_policy.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/shipping-policies/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "is_active": true,
    "is_default": true,
    "sort_order": 1,
    "country_settings": [
        "KR"
    ]
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 기존 배송정책을 수정합니다. `sirsoft-ecommerce.shipping-policies.update` 권한이 필요하며, 컨트롤러가 `getDetail()` 로 정책을 조회해 없으면 404 를 반환한 뒤 `ShippingPolicyService::update()` 가 다국어 정책명·활성여부·기본여부·정렬순서·국가별 설정(`country_settings`, 최소 1개)을 갱신하고 수정된 리소스를 반환합니다. 배송정책의 국가별 배송비/방식을 변경할 때 사용합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id}/set-default
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.set-default -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.set-default`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@setDefault`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/shipping-policies/1/set-default HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 배송정책","en":"API Doc Sample Shipping Poli…` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `API 문서 샘플 배송정책` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| fee_summary | string | `` | 활성 국가별 설정을 종합한 배송비 요약 텍스트 (예: `KR: 3000원 \| US: $20`, 활성 설정 없으면 빈 문자열) |
| countries_display | string | `` | 활성 배송 국가를 국기 이모지로 표시한 문자열 (최대 3개 노출, 초과분은 `+N` 축약) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:35` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "기본 배송정책이 설정되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 배송정책",
            "en": "API Doc Sample Shipping Policy"
        },
        "name_localized": "API 문서 샘플 배송정책",
        "fee_summary": "",
        "countries_display": "",
        "is_active": true,
        "is_default": true,
        "sort_order": 0,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:00:35",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 지정한 배송정책을 기본 배송정책으로 설정합니다. `sirsoft-ecommerce.shipping-policies.update` 권한이 필요하며, 컨트롤러가 `getDetail()` 로 정책을 조회해 없으면 404 를 반환한 뒤 `ShippingPolicyService::setDefault()` 가 해당 정책을 기본값으로 지정합니다(기존 기본 정책은 해제). 별도 정책이 매칭되지 않을 때 적용되는 기본 배송정책을 바꿀 때 사용합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id}/toggle-active
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.shipping-policies.toggle-active -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.shipping-policies.toggle-active`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController@toggleActive`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/shipping-policies/1/toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"API 문서 샘플 배송정책","en":"API Doc Sample Shipping Poli…` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `API 문서 샘플 배송정책` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| fee_summary | string | `` | 활성 국가별 설정을 종합한 배송비 요약 텍스트 (예: `KR: 3000원 \| US: $20`, 활성 설정 없으면 빈 문자열) |
| countries_display | string | `` | 활성 배송 국가를 국기 이모지로 표시한 문자열 (최대 3개 노출, 초과분은 `+N` 축약) |
| is_active | boolean | `false` | active 여부 |
| is_default | boolean | `false` | default 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:35` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배송정책 사용여부가 변경되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "API 문서 샘플 배송정책",
            "en": "API Doc Sample Shipping Policy"
        },
        "name_localized": "API 문서 샘플 배송정책",
        "fee_summary": "",
        "countries_display": "",
        "is_active": false,
        "is_default": false,
        "sort_order": 0,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:00:35",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 배송정책 1건의 활성 상태를 토글합니다. `sirsoft-ecommerce.shipping-policies.update` 권한이 필요하며, 컨트롤러가 `getDetail()` 로 정책을 조회해 없으면 404 를 반환한 뒤 `ShippingPolicyService::toggleActive()` 가 현재 활성 여부를 반전시키고 갱신된 리소스를 반환합니다. 목록 화면에서 개별 정책의 사용여부 스위치를 켜고 끌 때 사용합니다.


