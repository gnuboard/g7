# Options API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Options 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| bulk_changes | body | array | 아니오 | — | 옵션 일괄 변경 조건 (`price_adjustment`/`stock_quantity` 각각 method+value, 설정된 필드가 개별 수정보다 우선 적용) |
| items | body | array | 아니오 | — | 처리 대상 항목 배열 |

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
    "items": [
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 옵션들을 통합 일괄 업데이트합니다. `auth:sanctum` + `sirsoft-ecommerce.products.update` 권한이 필요하며, 상품은 미선택하고 옵션만 선택된 경우에 사용됩니다. `ids`(대상 옵션, 최소 1개)와 함께 `bulk_changes`(일괄 변경 조건)와 `items`(개별 인라인 수정)를 받아 `ProductOptionService::bulkUpdate()`가 처리하며, 일괄 변경 조건이 설정된 필드가 우선 적용되고 나머지는 개별 수정이 반영됩니다. 검증 실패 시 422, 그 외 오류 시 500을 반환하고, `sirsoft-ecommerce.option.bulk_update_validation_rules` 필터로 확장이 검증 규칙을 추가할 수 있습니다.


