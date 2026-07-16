# Users API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Users 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### PATCH /api/modules/sirsoft-ecommerce/admin/users/{user}/currency
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.users.currency.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.users.currency.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminUserCurrencyController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-currency.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user | path | string | 예 | — | 대상 user의 식별자 |
| currency | body | string | 예 | — | 회원에게 지정할 결제 통화 코드 (등록 통화: is_default 또는 exchange_rate>0 인 통화만 허용) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/users/a234c2b1-cde8-437f-b28b-23323be2b98d/currency HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "currency": "예시값"
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-currency.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 회원의 선호 결제 통화를 변경합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.user-currency.manage` 권한이 필요하며, path의 `{user}`는 UUID 라우트 모델 바인딩(관리자 회원 URL 규약)으로 주입됩니다. `UserCurrencyService::changeUserCurrencyByAdmin()`이 통화 저장과 활동 로그 훅 발화를 한 단위로 처리하며, `currency`는 등록된 통화 코드만 허용됩니다. 관리자 회원 상세 화면에서 회원별 결제 통화를 지정하는 용도입니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/users/{user}/shipping-country
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.users.shipping-country.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.users.shipping-country.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminUserShippingCountryController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-shipping-country.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user | path | string | 예 | — | 대상 user의 식별자 |
| shipping_country | body | string | 예 | — | 회원에게 지정할 배송국가 2자리 코드 (활성 배송가능 국가만 허용, 대문자로 정규화) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/users/a234c2b1-cde8-437f-b28b-23323be2b98d/shipping-country HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "shipping_country": "KR"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| preferred_shipping_country | string | `KR` | 변경 후 저장된 회원 선호 배송국가 코드 (2자리 대문자 ISO 국가코드) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배송국가가 변경되었습니다.",
    "data": {
        "preferred_shipping_country": "KR"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-shipping-country.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 특정 회원의 선호 배송국가를 변경합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.user-shipping-country.manage` 권한이 필요하며, path의 `{user}`는 UUID 라우트 모델 바인딩으로 주입됩니다. `UserShippingCountryService::changeUserShippingCountryByAdmin()`이 배송국가 저장과 활동 로그 훅 발화를 한 단위로 처리하고, `shipping_country`는 활성 국가만 허용되며 대문자로 정규화되어 반환됩니다. 관리자 회원 상세 화면에서 회원별 기본 배송국가를 지정하는 용도입니다.


