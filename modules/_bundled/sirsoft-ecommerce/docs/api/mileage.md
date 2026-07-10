# Mileage API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Mileage 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/user/mileage
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.mileage.balance -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.mileage.balance`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserMileageController@balance`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/mileage HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| mileage | object | `{"enabled":false,"available":12910,"pending":14000,"expir…` | 마일리지 잔액 요약 객체 (enabled 기능 활성화 여부, available 사용 가능, pending 적립 대기, expiring_soon/expiring_date 소멸 예정, total_earned/total_used 누적 적립·사용, by_currency 통화별 잔액) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "마일리지 잔액을 조회했습니다.",
    "data": {
        "mileage": {
            "enabled": false,
            "available": 0,
            "pending": 0,
            "expiring_soon": 0,
            "expiring_date": null,
            "total_earned": 0,
            "total_used": 0,
            "by_currency": []
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 로그인한 회원이 마이페이지에서 자신의 마일리지 잔액 요약을 조회합니다. `auth:sanctum` 인증만 필요하며, `UserMileageService::getBalance()`가 마일리지 기능 활성화 여부, 사용 가능(available)·적립 대기(pending)·소멸 예정 금액을 계산해 `mileage` 객체로 반환합니다. 마일리지 기능이 꺼져 있으면 `enabled: false` 와 0값이 내려오므로 화면에서 잔액 위젯 노출 여부를 이 플래그로 판단할 수 있습니다.


### GET /api/modules/sirsoft-ecommerce/user/mileage/history
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.mileage.history -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.mileage.history`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserMileageController@history`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| category | query | string | 아니오 | `earn`, `use`, `expire`, `adjust` | 분류 필터 (해당 분류의 항목만 조회) |
| currency | query | string | 아니오 | max 10 | 통화 코드 필터 (해당 통화의 마일리지 거래만 조회) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/mileage/history?category=earn&currency=%EC%98%88%EC%8B%9C%EA%B0%92&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| transactions | object | `{"data":[{"number":6,"id":527,"user_id":1,"currency":"KRW…` | 마일리지 거래 내역 페이지네이션 객체 (`data` 거래 항목 배열 + 페이지 메타, `MileageTransactionCollection` 으로 직렬화) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "마일리지 내역을 조회했습니다.",
    "data": {
        "transactions": {
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
                    "granted_by_name": [],
                    "granted_by_uuid": [],
                    "user_name": [],
                    "user_uuid": [],
                    "order_number": [],
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
            "currencies": [],
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
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 로그인한 회원이 마이페이지에서 자신의 마일리지 적립/사용 내역을 페이지네이션으로 조회합니다. `category`(earn·use·expire·adjust) 4분류 필터와 `currency`·`per_page`(최대 100)를 지원하며, `UserMileageService::paginateUserHistory()`가 필터를 적용해 조회한 뒤 `MileageTransactionCollection`으로 직렬화해 `transactions` 에 담습니다. `category` 는 원장의 원시 type 이 아니라 사용자 표시용 4분류로 매핑된 값입니다.


### GET /api/modules/sirsoft-ecommerce/user/mileage/max-usable
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.mileage.max-usable -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.mileage.max-usable`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserMileageController@maxUsable`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order_amount | query | integer | 예 | min 0 | 사용 가능 상한 계산 기준 주문금액 (마일리지 사용액은 이 금액을 넘을 수 없음) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.mileage.max_usable_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/mileage/max-usable?order_amount=1 HTTP/1.1
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 체크아웃 화면에서 특정 주문금액(`order_amount`)에 실제로 사용 가능한 최대 마일리지를 계산해 반환합니다. `auth:sanctum` 인증이 필요하고 `order_amount` 는 필수이며, `UserMileageService::getMaxUsable()`가 보유 잔액·최소 사용 정책·주문금액 상한을 종합해 사용 가능 상한을 산출하고 현재 잔액(available)도 함께 내려줍니다. 확장은 `sirsoft-ecommerce.mileage.max_usable_validation_rules` 필터로 검증 파라미터를 추가할 수 있습니다.


