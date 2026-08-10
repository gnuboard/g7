# Options API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Options 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### PATCH /api/modules/sirsoft-ecommerce/admin/options/bulk-price
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.options.bulk-price -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.options.bulk-price`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductOptionController@bulkUpdatePrice`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product_ids | body | array | 아니오 | — | product 식별자 배열 |
| option_ids | body | array | 아니오 | — | option 식별자 배열 |
| method | body | string | 예 | `increase`, `decrease`, `fixed` | 가격 변경 방식 (increase 인상 / decrease 인하 / fixed 고정가로 설정) |
| value | body | number | 예 | min 0 | 값 |
| unit | body | string | 예 | `won`, `percent` | 변경 단위 (won 금액 기준 / percent 비율 기준) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product_option.bulk_price_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/options/bulk-price HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "product_ids": [
        "예시값"
    ],
    "option_ids": [
        "예시값"
    ],
    "method": "increase",
    "value": 1,
    "unit": "won"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductOptionService::bulkUpdatePriceByMixedIds()` 반환 배열)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `3` | 실제로 판매가가 변경된 옵션 건수 (`product_ids` 로 확장된 옵션 + `option_ids` 로 개별 선택된 옵션을 중복 제거한 대상 수) |
| requested_product_count | integer | `1` | 요청에 담긴 `product_ids` 배열의 원소 수 (옵션만 선택한 경우 `0`) |

**응답 예시**

```json
{
    "success": true,
    "message": ":count개 옵션의 가격이 수정되었습니다.",
    "data": {
        "updated_count": 3,
        "requested_product_count": 1
    }
}
```

> `message` 는 `sirsoft-ecommerce::messages.options.bulk_price_updated` 를 그대로 사용합니다. 컨트롤러가 `messageParams` 를 전달하지 않으므로 `:count` 플레이스홀더는 치환되지 않은 채 응답됩니다 (변경 건수는 `data.updated_count` 로 확인).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) / Service 단계에서 `ValidationException` 발생 시 `옵션 가격 일괄 변경에 실패했습니다.` |
| 500 | Internal Server Error | 그 외 예외 발생 시 (`옵션 가격 일괄 변경에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 선택한 상품/옵션의 판매가를 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, `ProductOptionService::bulkUpdatePriceByMixedIds()`가 처리합니다. `product_ids`는 해당 상품의 모든 옵션을, `option_ids`는 "productId-optionId" 형식으로 개별 선택된 옵션을 대상으로 합니다. `method`(increase/decrease/fixed)와 `unit`(won/percent) 조합으로 인상·인하·고정가를 적용하며, 검증 실패 시 422, 그 외 오류 시 500을 반환합니다. `sirsoft-ecommerce.product_option.bulk_price_validation_rules` 필터로 확장이 검증 규칙을 추가할 수 있습니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/options/bulk-stock
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.options.bulk-stock -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.options.bulk-stock`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductOptionController@bulkUpdateStock`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product_ids | body | array | 아니오 | — | product 식별자 배열 |
| option_ids | body | array | 아니오 | — | option 식별자 배열 |
| method | body | string | 예 | `increase`, `decrease`, `set` | 재고 변경 방식 (increase 증가 / decrease 감소 / set 특정 수량으로 설정) |
| value | body | integer | 예 | min 0 | 값 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.product_option.bulk_stock_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/options/bulk-stock HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "product_ids": [
        "예시값"
    ],
    "option_ids": [
        "예시값"
    ],
    "method": "increase",
    "value": 1
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductOptionService::bulkUpdateStockByMixedIds()` 반환 배열)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `3` | 실제로 재고가 변경된 옵션 건수 (`product_ids` 로 확장된 옵션 + `option_ids` 로 개별 선택된 옵션을 중복 제거한 대상 수) |
| requested_product_count | integer | `1` | 요청에 담긴 `product_ids` 배열의 원소 수 (옵션만 선택한 경우 `0`) |

**응답 예시**

```json
{
    "success": true,
    "message": ":count개 옵션의 재고가 수정되었습니다.",
    "data": {
        "updated_count": 3,
        "requested_product_count": 1
    }
}
```

> `message` 는 `sirsoft-ecommerce::messages.options.bulk_stock_updated` 를 그대로 사용합니다. 컨트롤러가 `messageParams` 를 전달하지 않으므로 `:count` 플레이스홀더는 치환되지 않은 채 응답됩니다 (변경 건수는 `data.updated_count` 로 확인).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) / Service 단계에서 `ValidationException` 발생 시 `옵션 재고 일괄 변경에 실패했습니다.` |
| 500 | Internal Server Error | 그 외 예외 발생 시 (`옵션 재고 일괄 변경에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 선택한 상품/옵션의 재고를 일괄 변경합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, `ProductOptionService::bulkUpdateStockByMixedIds()`가 처리합니다. `product_ids`는 해당 상품의 모든 옵션을, `option_ids`는 "productId-optionId" 형식으로 개별 선택된 옵션을 대상으로 합니다. `method`(increase/decrease/set)와 정수 `value`로 재고를 가감하거나 특정 수량으로 설정하며, 검증 실패 시 422, 그 외 오류 시 500을 반환합니다. `sirsoft-ecommerce.product_option.bulk_stock_validation_rules` 필터로 확장이 검증 규칙을 추가할 수 있습니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/options/bulk-update
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.options.bulk-update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.options.bulk-update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductOptionController@bulkUpdate`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 옵션 식별자 배열. 각 원소는 `"productId-optionId"` 형식 문자열 (`regex:/^\d+-\d+$/`) |
| bulk_changes | body | array | 아니오 | — | 옵션 일괄 변경 조건 (`price_adjustment`/`stock_quantity` 각각 method+value, 설정된 필드가 개별 수정보다 우선 적용) |
| bulk_changes.price_adjustment | body | array | 아니오 | — | 옵션 추가금액(`price_adjustment`) 일괄 변경 조건 객체. 존재 시 하위 `method`/`value` 필수 |
| bulk_changes.price_adjustment.method | body | string | 아니오 | `set`, `add`, `percent` | 추가금액 변경 방식 (set 지정값으로 설정 / add 기존값에 가산 / percent 기존값에 비율 적용) |
| bulk_changes.price_adjustment.value | body | number | 아니오 | — | 추가금액 변경 값 (`method` 에 따라 설정값·가감액·비율로 해석. 소수 통화 대응) |
| bulk_changes.stock_quantity | body | array | 아니오 | — | 옵션 재고수량(`stock_quantity`) 일괄 변경 조건 객체. 존재 시 하위 `method`/`value` 필수 |
| bulk_changes.stock_quantity.method | body | string | 아니오 | `set`, `add`, `subtract` | 재고 변경 방식 (set 특정 수량으로 설정 / add 증가 / subtract 감소) |
| bulk_changes.stock_quantity.value | body | integer | 아니오 | min 0 | 재고 변경 값 (`method` 에 따라 설정 수량·증감 수량으로 해석) |
| items | body | array | 아니오 | — | 개별 인라인 수정 항목 배열. 항목별 키: `product_id`(필수, 존재하는 상품), `option_id`(필수, 존재하는 옵션), `option_name`(로케일별 문자열 배열, 각 max 255), `sku`(max 100), `list_price`(min 0), `price_adjustment`, `stock_quantity`(min 0), `safe_stock_quantity`(min 0), `is_default`, `is_active`. `bulk_changes` 에 설정된 필드는 개별값보다 우선 적용되어 무시됨 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.option.bulk_update_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/options/bulk-update HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "bulk_changes": [
        "예시값"
    ],
    "bulk_changes.price_adjustment": [
        "예시값"
    ],
    "bulk_changes.price_adjustment.method": "set",
    "bulk_changes.price_adjustment.value": 1,
    "bulk_changes.stock_quantity": [
        "예시값"
    ],
    "bulk_changes.stock_quantity.method": "set",
    "bulk_changes.stock_quantity.value": 1,
    "items": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ProductOptionService::bulkUpdate()` 반환 배열)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| options_updated | integer | `5` | 적용된 변경 횟수. 일괄 변경(`bulk_changes.price_adjustment`, `bulk_changes.stock_quantity`)은 각 조건마다 대상 옵션 수만큼 누적되고, 개별 인라인 수정(`items`)은 실제로 변경 필드가 남은 항목마다 1씩 누적됩니다 (대상 옵션이 없으면 `0`) |

**응답 예시**

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.options.bulk_updated",
    "data": {
        "options_updated": 5
    }
}
```

> 컨트롤러가 사용하는 메시지 키 `messages.options.bulk_updated` 는 모듈 lang(ko/en)에 정의되어 있지 않습니다(정의된 키는 `messages.options.bulk_update_success`). 따라서 `message` 에는 번역문 대신 키 문자열이 그대로 반환됩니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) / Service 단계에서 `ValidationException` 발생 시 `옵션 일괄 업데이트에 실패했습니다.` |
| 500 | Internal Server Error | 그 외 예외 발생 시 (`옵션 일괄 업데이트에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 옵션들을 통합 일괄 업데이트합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, 상품은 미선택하고 옵션만 선택된 경우에 사용됩니다. `ids`(대상 옵션, 최소 1개)와 함께 `bulk_changes`(일괄 변경 조건)와 `items`(개별 인라인 수정)를 받아 `ProductOptionService::bulkUpdate()`가 처리하며, 일괄 변경 조건이 설정된 필드가 우선 적용되고 나머지는 개별 수정이 반영됩니다. 검증 실패 시 422, 그 외 오류 시 500을 반환하고, `sirsoft-ecommerce.option.bulk_update_validation_rules` 필터로 확장이 검증 규칙을 추가할 수 있습니다.


