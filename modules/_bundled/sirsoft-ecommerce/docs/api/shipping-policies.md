# Shipping Policies API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Shipping Policies 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
| with_country_settings | query | boolean | 아니오 | 기본 `false` | 국가별 설정을 **전체 컬럼**으로 포함할지. 기본값에서도 `country_settings` 는 내려가지만 목록 표시용 필드만 담깁니다(구간 설정·도서산간 설정·계산 API 설정 제외). 편집 폼처럼 전체 값이 필요한 호출자만 켜세요 |
| sort_by | query | string | 아니오 | `id`, `name`, `is_active`, `sort_order`, `created_at`, `updated_at` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| per_page | query | integer | 아니오 | min 10, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.shipping_policy.list_validation_rules`, `sirsoft-ecommerce.shipping_policy.list_validation_messages`).

**목록은 경량 표현입니다.**

국가별 설정(`country_settings[]`)은 목록 화면이 그리는 필드만 담습니다 — 국가 코드, 배송방법, 부과정책, 배송비, 무료배송 기준액, 도서산간 사용 여부, 활성 여부. 구간 설정(`ranges`), 도서산간 상세(`extra_fee_settings`), 계산 API 설정(`api_*`)은 정책 하나에 국가 수만큼 곱해지는 중첩 데이터라 목록에서 제외합니다. 전체 값이 필요하면 `with_country_settings=1` 을 쓰거나 단건 조회(`GET .../admin/shipping-policies/{id}`)를 이용하세요.

비활성 국가 설정도 함께 내려갑니다(`is_active: false`) — 목록이 비활성 배지를 그리기 때문입니다. 다만 배송비 요약(`fee_summary`)과 국가 표시(`countries_display`)는 활성 설정만 세므로, 조회 경로와 무관하게 같은 값이 나옵니다.

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/shipping-policies?search=%EC%98%88%EC%8B%9C%EA%B0%92&shipping_methods=%EC%98%88%EC%8B%9C%EA%B0%92&charge_policies=%EC%98%88%EC%8B%9C%EA%B0%92&countries=KR&is_active=%2C%20&with_country_settings=1&sort_by=id&sort_order=asc&per_page=1&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `15` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `444` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"국내 무료배송","en":"Domestic Free Shipping"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `국내 무료배송` | `name` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| sort_order | integer | `1` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-30 23:35:47` | 생성 일시 |
| updated_at | string | `2026-07-30 23:35:47` | 최종 수정 일시 |
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
                "number": 15,
                "id": 444,
                "name": {
                    "ko": "국내 무료배송",
                    "en": "Domestic Free Shipping"
                },
                "name_localized": "국내 무료배송",
                "is_active": true,
                "is_default": true,
                "sort_order": 1,
                "created_at": "2026-07-30 23:35:47",
                "updated_at": "2026-07-30 23:35:47",
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            {
                "number": 14,
                "id": 445,
                "name": {
                    "ko": "국내 택배 (고정)",
                    "en": "Domestic Parcel (Fixed)"
                },
                "name_localized": "국내 택배 (고정)",
                "is_active": true,
                "is_default": false,
                "sort_order": 2,
                "created_at": "2026-07-30 23:35:47",
                "updated_at": "2026-07-30 23:35:47",
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            "... (총 15건 중 2건 표시)"
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        },
        "statistics": {
            "total": 15,
            "active": 14,
            "inactive": 1,
            "shipping_method": {
                "direct": 2,
                "parcel": 12,
                "quick": 1
            },
            "charge_policy": {
                "api": 1,
                "conditional_free": 1,
                "fixed": 2,
                "free": 1,
                "per_amount": 1,
                "per_quantity": 1,
                "per_volume": 1,
                "per_volume_weight": 1,
                "per_weight": 1,
                "range_amount": 1,
                "range_quantity": 1,
                "range_volume": 1,
                "range_volume_weight": 1,
                "range_weight": 2
            }
        },
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 15,
            "from": 1,
            "to": 15,
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

_단건 응답: `data` 객체의 필드 (`ShippingPolicyResource`). 생성 성공 시 HTTP 201._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"기본 배송정책","en":"Default Shipping Policy"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `기본 배송정책` | `name` 의 현재 로케일 해석 값 |
| country_settings | array | `[]` | 국가별 배송 설정 목록 (`countrySettings` 관계가 로드된 경우에만 포함) |
| fee_summary | string | `KR: 배송비: 3,000원` | 활성 국가별 설정을 종합한 배송비 요약 텍스트 (활성 설정 없으면 빈 문자열) |
| countries_display | string | `🇰🇷` | 활성 배송 국가를 국기 이모지로 표시한 문자열 (최대 3개, 초과분은 `+N` 축약) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `false` | default 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 10:44:49` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "배송정책이 등록되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "기본 배송정책",
            "en": "Default Shipping Policy"
        },
        "name_localized": "기본 배송정책",
        "fee_summary": "KR: 배송비: 3,000원",
        "countries_display": "🇰🇷",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 생성 처리 중 예외 발생 (`exceptions.operation_failed`) |

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
| value | integer | `444` | 배송정책 ID (Select 옵션의 value) |
| label | string | `국내 무료배송` | 표시용 라벨 |
| countries_display | string | `🇰🇷` | 활성 배송 국가를 국기 이모지로 표시한 문자열 (최대 3개, 초과분 `+N`) |
| fee_summary | string | `KR: 무료배송` | 국가별 배송비 요약 텍스트 (`country_code: fee` 형태를 ` \| ` 로 결합) |
| is_default | boolean | `true` | default 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "사용 중인 배송정책 목록을 조회했습니다.",
    "data": [
        {
            "value": 444,
            "label": "국내 무료배송",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: 무료배송",
            "is_default": true
        },
        {
            "value": 445,
            "label": "국내 택배 (고정)",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: 배송비: ¥3,000",
            "is_default": false
        },
        {
            "value": 446,
            "label": "조건부 무료배송 (5만원 이상)",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: ¥50,000 미만 ¥2,500 / ¥50,000 이상 무료",
            "is_default": false
        },
        {
            "value": 447,
            "label": "금액별 구간 배송비",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: ~10000원: ¥5,000 / 10000~30000원: ¥3,000 / 30000~50000원: ¥2,000 / 50000~100000원: ¥1,000 / 100000원~: ¥0",
            "is_default": false
        },
        {
            "value": 448,
            "label": "수량별 구간 배송비",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: 1~5개: ¥3,000 / 6개~: ¥5,000",
            "is_default": false
        },
        {
            "value": 449,
            "label": "무게별 구간 배송비",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: ~2kg: ¥3,000 / 2~5kg: ¥4,000 / 5~10kg: ¥6,000 / 10kg~: ¥8,000",
            "is_default": false
        },
        {
            "value": 450,
            "label": "부피별 구간 배송비",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: ~50L: ¥5,000 / 50~100L: ¥10,000 / 100L~: ¥20,000",
            "is_default": false
        },
        {
            "value": 451,
            "label": "부피무게 구간 배송비",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: ~5kg: ¥3,500 / 5~10kg: ¥5,000 / 10~20kg: ¥8,000 / 20kg~: ¥12,000",
            "is_default": false
        },
        {
            "value": 452,
            "label": "해외배송 (DHL)",
            "countries_display": "🇨🇳🇯🇵🇺🇸",
            "fee_summary": "CN: 외부 API 연동 (실시간 계산) | JP: 외부 API 연동 (실시간 계산) | US: 외부 API 연동 (실시간 계산)",
            "is_default": false
        },
        {
            "value": 454,
            "label": "수량당 배송비 (3개당)",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: 3개당 ¥3,000",
            "is_default": false
        },
        {
            "value": 455,
            "label": "무게당 배송비 (1kg당)",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: 1kg당 ¥1,000",
            "is_default": false
        },
        {
            "value": 456,
            "label": "부피당 배송비 (10L당)",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: 10L당 ¥2,000",
            "is_default": false
        },
        {
            "value": 457,
            "label": "국내외 복합 배송 (부피무게당)",
            "countries_display": "🇰🇷🇺🇸",
            "fee_summary": "KR: 5kg당 ¥3,000 | US: ~2kg: ¥25 / 2~5kg: ¥40 / 5kg~: ¥60",
            "is_default": false
        },
        {
            "value": 458,
            "label": "금액당 배송비 (1만원당)",
            "countries_display": "🇰🇷",
            "fee_summary": "KR: 10,000당 ¥500",
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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `2` | 실제로 삭제된 배송정책 건수 (`ShippingPolicyService::bulkDelete()` 반환값) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": ":count개 배송정책이 삭제되었습니다.",
    "data": {
        "deleted_count": 2
    }
}
```

> 컨트롤러가 `messageParams` 를 넘기지 않으므로 `message` 의 `:count` 자리표시자는 치환되지 않은 채로 내려옵니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 일괄 삭제 처리 중 예외 발생 (`exceptions.operation_failed`) |
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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `2` | 활성 상태가 실제로 변경된 배송정책 건수 (`ShippingPolicyService::bulkToggleActive()` 반환값) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": ":count개 배송정책의 사용여부가 변경되었습니다.",
    "data": {
        "updated_count": 2
    }
}
```

> 컨트롤러가 `messageParams` 를 넘기지 않으므로 `message` 의 `:count` 자리표시자는 치환되지 않은 채로 내려옵니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 일괄 변경 처리 중 예외 발생 (`exceptions.operation_failed`) |
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
| endpoint | body | string | 예 | max 500 | 테스트로 호출할 외부 배송비 계산 API 엔드포인트 URL. 내부 네트워크 주소(사설 IP·루프백·`localhost`·`*.internal` 등)와 userinfo(`https://a@b/`) 위장 주소는 422 로 거부됩니다 — 이 주소는 쇼핑몰 서버가 대신 호출하므로 내부망 접근을 막기 위함(SSRF). 사내 배송비 계산 서버를 쓰려면 코어 환경설정의 `security.allow_internal_outbound_urls` 를 켜세요 |
| request_fields | body | array | 아니오 | — | 요청에 실어 보낼 필드명 목록 (후보 SSoT ShippingApiRequestField 5종) |
| config | body | array | 아니오 | — | API 호출 고급 설정 (HTTP 메서드·인증방식·필드 매핑·응답 형식/경로 등) |
| config.http_method | body | string | 아니오 | — | 외부 API 호출에 사용할 HTTP 메서드 (GET=query string bracket 표기, POST=JSON body. 미지정 시 POST) |
| config.auth_type | body | string | 아니오 | — | 인증 헤더 부착 방식 (none=인증 없음, bearer=`Authorization: Bearer {token}`, custom_header=커스텀 헤더명 + 값) |
| config.auth_token | body | string | 아니오 | max 1000 | 인증에 사용할 토큰/키 값 (`auth_type` 이 bearer/custom_header 일 때 헤더 값으로 전송) |
| config.auth_header_name | body | string | 아니오 | max 100 | `auth_type` 이 `custom_header` 일 때 토큰을 실어 보낼 헤더 이름 (예: `X-API-Key`. HTTP 헤더 토큰 문자만 허용) |
| config.response_type | body | string | 아니오 | — | 외부 API 응답에서 배송비를 추출하는 방식 (json=`response_path` 점표기 경로로 중첩 값 추출, text=본문에서 숫자/소수점만 추출) |
| config.response_path | body | string | 아니오 | max 200 | JSON 응답에서 배송비 값이 위치한 점표기 경로 (예: `data.shipping_fee`) |
| config.field_map | body | array | 아니오 | — | 요청 필드명 재매핑 표 (내부 필드명 → 외부 API 가 요구하는 파라미터명. 값은 영숫자/`_`/`.`/`-` 만 허용) |
| sample | body | array | 아니오 | — | 테스트 계산에 사용할 샘플 주문 데이터 (무게/금액/수량 등) |
| sample.group_total | body | number | 아니오 | min 0 | 테스트용 배송 그룹 합계 금액 (미지정 시 `10000`) |
| sample.total_quantity | body | integer | 아니오 | min 0 | 테스트용 배송 그룹 총 수량 (미지정 시 `1`) |
| sample.country_code | body | string | 아니오 | max 10 | 국가 코드 (ISO 3166-1 alpha-2. 미지정 시 `KR`) |

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
    "config.http_method": "예시값",
    "config.auth_type": "예시값",
    "config.auth_token": "{YOUR_TOKEN}",
    "config.auth_header_name": "예시 이름",
    "config.response_type": "예시값",
    "config.response_path": "예시값",
    "config.field_map": [
        "예시값"
    ],
    "sample": [
        "예시값"
    ],
    "sample.group_total": 1,
    "sample.total_quantity": 1,
    "sample.country_code": "KR"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`OrderCalculationService::testApiCall()` 반환 배열). 외부 API 호출 실패도 HTTP 200 으로 내려오며 `ok=false` 로 구분합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| ok | boolean | `true` | 테스트 호출 성공 여부 (외부 API 가 2xx 응답을 준 경우 true. 무응답/요청 실패 시 false) |
| http_ok | boolean | `true` | 외부 API 응답이 2xx 인지 여부 (응답을 받은 경우에만 포함) |
| reason | string | `no_response`, `request_failed` | 실패 사유 코드 (`ok=false` 인 경우에만 포함. no_response=응답 없음, request_failed=연결 실패/타임아웃) |
| error | string | `cURL error 28: Operation timed out` | 예외 메시지 (`reason=request_failed` 인 경우에만 포함) |
| request | object | `{"method":"POST","endpoint":"https://…","data":{…},"body":"{…}"}` | 실제로 보낸 요청 미리보기 (성공/실패 무관하게 항상 포함) |
| request.method | string | `POST` | 실제 사용된 HTTP 메서드 (`config.http_method` 대문자, 미지정 시 `POST`) |
| request.endpoint | string | `https://api.example.com/shipping/quote` | 호출한 외부 API URL |
| request.data | object | `{"country_code":"KR","group_total":10000,"total_quantity":1}` | `request_fields` + `field_map` 을 적용해 구성된 요청 파라미터 |
| request.body | string | `{\n    "country_code": "KR"\n}` | `request.data` 를 pretty-print JSON 문자열로 직렬화한 요청 본문 미리보기 |
| response | object | `{"status":200,"body":"{\"fee\":3000}"}` | 외부 API 응답 (응답을 받은 경우에만 포함) |
| response.status | integer | `200` | 외부 API 응답 HTTP 상태코드 |
| response.body | string | `{"fee":3000}` | 외부 API 응답 본문 (과대 응답 방어를 위해 앞 4096자로 잘림) |
| extracted_fee | integer\|null | `3000` | 응답에서 추출된 배송비 (`response_type`/`response_path` 로 추출 실패 시 `null`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "계산 API 테스트 호출을 완료했습니다.",
    "data": {
        "ok": true,
        "http_ok": true,
        "request": {
            "method": "POST",
            "endpoint": "https://api.example.com/shipping/quote",
            "data": {
                "country_code": "KR",
                "group_total": 10000,
                "total_quantity": 1
            },
            "body": "{\n    \"country_code\": \"KR\",\n    \"group_total\": 10000,\n    \"total_quantity\": 1\n}"
        },
        "response": {
            "status": 200,
            "body": "{\"fee\":3000}"
        },
        "extracted_fee": 3000
    }
}
```

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
DELETE /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — 컨트롤러가 `ResponseHelper::moduleSuccess()` 에 데이터를 넘기지 않아 `data` 는 `null`)._

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 삭제 처리 중 예외 발생 (사용 중인 정책 등 — `exceptions.operation_failed`) |
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
GET /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

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
PUT /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id} HTTP/1.1
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

_단건 응답: `data` 객체의 필드 (`ShippingPolicyResource` — show 와 동일 구조)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | object | `{"ko":"기본 배송정책","en":"Default Shipping Policy"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_localized | string | `기본 배송정책` | `name` 의 현재 로케일 해석 값 |
| country_settings | array | `[]` | 국가별 배송 설정 목록 (`countrySettings` 관계가 로드된 경우에만 포함) |
| fee_summary | string | `KR: 배송비: 3,000원` | 활성 국가별 설정을 종합한 배송비 요약 텍스트 (활성 설정 없으면 빈 문자열) |
| countries_display | string | `🇰🇷` | 활성 배송 국가를 국기 이모지로 표시한 문자열 (최대 3개, 초과분은 `+N` 축약) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `false` | default 여부 |
| sort_order | integer | `0` | 표시 정렬 순서 값 (작을수록 우선) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:35` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배송정책이 수정되었습니다.",
    "data": {
        "id": 1,
        "name": {
            "ko": "기본 배송정책",
            "en": "Default Shipping Policy"
        },
        "name_localized": "기본 배송정책",
        "fee_summary": "KR: 배송비: 3,000원",
        "countries_display": "🇰🇷",
        "is_active": true,
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 수정 처리 중 예외 발생 (`exceptions.operation_failed`) |

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
PATCH /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id}/set-default HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

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
PATCH /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id}/toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 배송정책 1건의 활성 상태를 토글합니다. `sirsoft-ecommerce.shipping-policies.update` 권한이 필요하며, 컨트롤러가 `getDetail()` 로 정책을 조회해 없으면 404 를 반환한 뒤 `ShippingPolicyService::toggleActive()` 가 현재 활성 여부를 반전시키고 갱신된 리소스를 반환합니다. 목록 화면에서 개별 정책의 사용여부 스위치를 켜고 끌 때 사용합니다.


