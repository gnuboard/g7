# Mileage Transactions API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Mileage Transactions 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/mileage-transactions
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.mileage-transactions.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.mileage-transactions.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\MileageTransactionController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.mileage.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort | query | string | 아니오 | `created_at_desc`, `created_at_asc`, `amount_desc`, `amount_asc` | 정렬 기준 (필드명, `-` 접두 시 내림차순) |
| search_field | query | string | 아니오 | `member`, `member_id`, `email`, `order` | 검색 대상 필드명 (검색어를 적용할 컬럼) |
| search_keyword | query | string | 아니오 | max 100 | 검색 키워드 (부분 일치) |
| type | query | string | 아니오 | `earn`, `use`, `expire`, `adjust` | 유형 필터 (해당 유형의 항목만 조회) |
| currency | query | string | 아니오 | max 10 | 통화 코드 필터 (해당 통화로 기록된 거래만 조회) |
| start_date | query | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| end_date | query | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/mileage-transactions?page=1&per_page=1&sort=created_at_desc&search_field=member&search_keyword=%EC%98%88%EC%8B%9C%EA%B0%92&type=earn&currency=%EC%98%88%EC%8B%9C%EA%B0%92&start_date=2026-01-01&end_date=2026-01-01 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `68` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `528` | 기본 키 (내부 식별자) |
| user_id | integer | `166` | user 식별자 (연관 리소스 참조) |
| currency | string | `KRW` | 거래 기록 통화 코드 (주문 기준통화 스냅샷, 금액 표기·잔액 집계 단위) |
| type | string | `admin_earn` | 거래 유형 (MileageTransactionTypeEnum 8종: purchase_earn·admin_earn·order_use·admin_deduct·expired·refund_restore·order_cancel_restore·earn_cancel) |
| type_label | string | `관리자 지급` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| admin_badge_group | string | `amber` | 관리자 내역 화면 배지 색상 그룹 (적립계=green·사용계=blue·소멸=gray·복원계=teal·수동/회수계=amber) |
| user_display_category | string | `adjust` | 회원 마이페이지 표시용 4분류 (earn·use·expire·adjust — 복원·수동·회수는 adjust 로 통합) |
| amount | integer | `1000` | 거래 금액 (양수=적립, 음수=차감) |
| amount_formatted | string | `1,000원` | `amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| remaining_amount | integer | `0` | 잔여 금액 (적립건만 양수, FIFO 차감으로 소진 — 미만료 잔여 합이 잔액 SSoT) |
| remaining_amount_formatted | string | `0원` | `remaining_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| balance_after | integer | `1000` | 거래 직후 잔액 (감사용 스냅샷, 베스트에포트) |
| order_id | integer | `436` | order 식별자 (연관 리소스 참조) |
| order_option_id | integer | `824` | order option 식별자 (연관 리소스 참조) |
| order_cancel_id | integer | `18` | order cancel 식별자 (연관 리소스 참조) |
| source_transaction_id | integer | `523` | source transaction 식별자 (연관 리소스 참조) |
| granted_by | integer | `1` | 부여 주체 식별자 (NULL=시스템 자동, user ID=관리자 수동 부여) |
| granted_by_name | string | `관리자` | 부여 관리자 이름 (grantedByUser 관계 eager load 시에만 노출) |
| granted_by_uuid | string | `a1e0a91a-fba6-491c-a53e-7285a5686857` | 부여 관리자 UUID (grantedByUser 관계 eager load 시에만 노출) |
| user_name | string | `API 문서 샘플 사용자` | 거래 대상 회원 이름 (user 관계 eager load 시에만 노출) |
| user_uuid | string | `a231747f-e82e-4cf2-9ae1-a261849dce40` | 거래 대상 회원 UUID (user 관계 eager load 시에만 노출) |
| order_number | string | `20260619-1425382147` | 연관 주문번호 (order 관계 eager load 시에만 노출) |
| description | string | `마일리지 적립 (660원)` | 설명 (다국어 필드는 로케일별 값 객체) |
| memo | string | `111` | 관리자 메모 (수동 지급/차감·적립건 편집 시 입력) |
| expires_at | string | `2027-07-02T15:29:30+00:00` | expires 일시 |
| expires_at_formatted | string | `2027-07-03 00:29:30` | `expires_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| expires_at_date | string | `2027-07-03` | `expires_at` 의 사이트 타임존 기준 날짜 부분 (시각 제외) |
| expired_at | null | `null` | expired 일시 |
| expired_at_formatted | null | `null` | `expired_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| created_at | string | `2026-07-07T05:47:31+00:00` | 생성 일시 |
| created_at_formatted | string | `2026-07-07 14:47:31` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| created_at_date | string | `2026-07-07` | `created_at` 의 사이트 타임존 기준 날짜 부분 (시각 제외) |
| is_earning | boolean | `true` | earning 여부 |
| can_edit_expiry | boolean | `false` | edit expiry 수행 가능 여부 (권한 기반) |
| expired_amount | integer | `0` | 이 적립 lot 을 source 로 소멸(expired)된 금액 합계 (목록 조회 시 eager 집계, 단건 조회 시 0 폴백) |
| expired_amount_formatted | string | `0원` | `expired_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| expiry_state | string | `active` | 적립건 소멸 상태 (active=미소멸, partial_expired=일부 소멸, fully_expired=전액 소멸 — 적립계만 의미) |
| abilities | object | `{"can_manage":true,"can_edit":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "마일리지 내역을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 1,
                "id": 1,
                "user_id": 6,
                "currency": "KRW",
                "type": "admin_earn",
                "type_label": "관리자 지급",
                "admin_badge_group": "amber",
                "user_display_category": "adjust",
                "amount": 1000,
                "amount_formatted": "1,000원",
                "remaining_amount": 0,
                "remaining_amount_formatted": "0원",
                "balance_after": 1000,
                "order_id": null,
                "order_option_id": null,
                "order_cancel_id": null,
                "source_transaction_id": null,
                "granted_by": null,
                "granted_by_name": null,
                "granted_by_uuid": null,
                "user_name": "API 문서 샘플 사용자",
                "user_uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                "order_number": null,
                "description": null,
                "memo": null,
                "expires_at": null,
                "expires_at_formatted": null,
                "expires_at_date": null,
                "expired_at": null,
                "expired_at_formatted": null,
                "created_at": "2026-07-08T01:44:49+00:00",
                "created_at_formatted": "2026-07-08 10:44:49",
                "created_at_date": "2026-07-08",
                "is_earning": true,
                "can_edit_expiry": false,
                "expired_amount": 0,
                "expired_amount_formatted": "0원",
                "expiry_state": "active",
                "abilities": {
                    "can_manage": true,
                    "can_edit": true
                }
            }
        ],
        "abilities": {
            "can_manage": true
        },
        "currencies": [
            "KRW"
        ],
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.mileage.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 전체 회원의 마일리지 거래 원장을 검색·필터·페이지네이션으로 조회합니다. `permission:sirsoft-ecommerce.mileage.read` 권한이 필요하며, 회원(member·member_id·email)·주문번호 검색, type(earn·use·expire·adjust)·통화·기간 필터, 금액/생성일 정렬을 지원합니다. `UserMileageService::paginateAdminHistory()`가 조회하고 `MileageTransactionCollection`이 통화 필터 후보(`withCurrencies`)와 함께 직렬화합니다. 각 행은 원장 스냅샷(balance_after, remaining_amount 등)을 그대로 노출하며 원장은 불변입니다.


### POST /api/modules/sirsoft-ecommerce/admin/mileage-transactions
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.mileage-transactions.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.mileage-transactions.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\MileageTransactionController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.mileage.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user_id | body | uuid | 예 | — | user 식별자 |
| action | body | string | 예 | `earn`, `deduct` | 처리 동작 (earn=수동 적립 → adminEarn, deduct=수동 차감 → adminDeduct FIFO 소진) |
| amount | body | integer | 예 | min 1 | 지급/차감할 마일리지 금액 (양수) |
| currency | body | string | 예 | max 10 | 대상 통화 코드 (해당 통화 잔액에 적용) |
| memo | body | string | 아니오 | max 1000 | 관리자 메모 (거래에 기록) |
| description | body | string | 아니오 | max 500 | 설명 |
| expires_at | body | date | 아니오 | — | 만료일 직접 지정 (지급 시 `use_default_expiry`=false 인 경우 적용) |
| use_default_expiry | body | boolean | 아니오 | — | 지급 시 정책 기본 만료일 적용 여부 (기본 true, false 면 `expires_at` 사용) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/mileage-transactions HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "user_id": "9f8b2c1a-4d3e-4a2b-8c1d-0e1f2a3b4c5d",
    "action": "earn",
    "amount": 1,
    "currency": "예시값",
    "memo": "예시값",
    "description": "예시 내용입니다.",
    "expires_at": "2026-01-01",
    "use_default_expiry": true
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.mileage.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 특정 회원에게 마일리지를 수동으로 지급하거나 차감합니다. `permission:sirsoft-ecommerce.mileage.manage` 권한이 필요하고 회원은 `user_id`(uuid)로 지정하며, `action`(earn/deduct)에 따라 `UserMileageService::adminEarn()` 또는 `adminDeduct()`를 호출합니다. 지급 시 `use_default_expiry`(기본 true)면 정책 기본 만료일을, 아니면 `expires_at`을 적용하고, 차감은 FIFO로 적립건에서 소진합니다. 잔액 부족 등 도메인 규칙 위반은 `MileageValidationException`으로 422를 반환합니다.


### POST /api/modules/sirsoft-ecommerce/admin/mileage-transactions/extend-expiry
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.mileage-transactions.extend-expiry -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.mileage-transactions.extend-expiry`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\MileageTransactionController@extendExpiry`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.mileage.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user_id | body | uuid | 예 | — | user 식별자 |
| lot_ids | body | array | 예 | min 1 | lot 식별자 배열 |
| days | body | integer | 예 | min 1, max 3650 | 각 lot 만료일을 연장할 일수 (최대 3650일) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/mileage-transactions/extend-expiry HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "user_id": "9f8b2c1a-4d3e-4a2b-8c1d-0e1f2a3b4c5d",
    "lot_ids": [
        "예시값"
    ],
    "days": 1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.mileage.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 특정 회원의 여러 적립 lot 의 유효기간을 한 번에 연장합니다. `permission:sirsoft-ecommerce.mileage.manage` 권한이 필요하며, 회원(`user_id` uuid)과 대상 적립건 배열(`lot_ids`), 연장 일수(`days`, 최대 3650)를 받아 `UserMileageService::extendLotExpiry()`가 각 lot 의 만료일을 연장하고 실제로 연장된 건수(`extended_count`)를 반환합니다. 이미 소멸/사용된 lot 등 대상 외 건은 서비스가 걸러내므로 반환 건수가 요청한 `lot_ids` 수보다 작을 수 있습니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/mileage-transactions/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.mileage-transactions.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.mileage-transactions.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\MileageTransactionController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.mileage.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| memo | body | string | 아니오 | max 1000 | 관리자 메모 보정값 (요청에 포함된 경우만 갱신, 빈 값으로 비우기 허용) |
| expires_at | body | date | 아니오 | — | expires 일시 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/mileage-transactions/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "memo": "예시값",
    "expires_at": "2026-01-01"
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.mileage.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 기존 적립 거래의 부가 필드(관리자 메모 `memo`, 만료일 `expires_at`)만 보정합니다. 마일리지 원장은 불변이므로 금액·유형 등 핵심 값은 수정할 수 없고, 요청에 실제로 포함된 키만 갱신합니다(`memo` 만 보내면 만료일은 유지). `UserMileageService::updateAdminTransaction()`가 적립계 거래인지·소멸/사용된 lot 이 아닌지 검증하며, 적립계 외 거래 등 규칙 위반은 `MileageValidationException`(422)으로 거부합니다.


### GET /api/modules/sirsoft-ecommerce/admin/mileage-transactions/{id}/linked
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.mileage-transactions.linked -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.mileage-transactions.linked`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\MileageTransactionController@linked`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.mileage.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/mileage-transactions/1/linked HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "연결 거래를 조회했습니다.",
    "data": {
        "data": [],
        "abilities": {
            "can_manage": true
        },
        "currencies": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.mileage.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 마일리지 내역 한 행을 펼쳤을 때 그와 연결된 거래들을 조회합니다. `permission:sirsoft-ecommerce.mileage.read` 권한이 필요하며, `UserMileageService::getLinkedTransactions()`가 적립건이면 그 적립을 FIFO 로 소비한 차감 거래들을, 차감/복원건이면 원본 적립·취소 연결 거래를 찾아 `MileageTransactionCollection`으로 반환합니다. 해당 거래가 없으면 404 를 반환하며, 연결 거래가 없으면 빈 목록이 내려옵니다.


