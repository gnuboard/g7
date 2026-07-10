# Shipping Country API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Shipping Country 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/user/shipping-country
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.shipping-country.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.shipping-country.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserShippingCountryController@show`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/shipping-country HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| preferred_shipping_country | null | `null` | 회원이 저장한 선호 배송국가 코드 (2자리 대문자, 미설정 시 `null`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배송국가를 조회했습니다.",
    "data": {
        "preferred_shipping_country": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 로그인한 회원이 마이페이지에 저장해 둔 선호 배송국가 코드를 조회합니다. `auth:sanctum` 인증이 필요하며, `UserShippingCountryService::getPreferredShippingCountry()`가 인증 사용자 ID로 영속된 값을 반환하고 미설정 시 `preferred_shipping_country: null`을 내려줍니다. 마이페이지 배송국가 설정·회원정보 수정 화면이 초기 선택값을 채우는 데 사용합니다.


### PUT /api/modules/sirsoft-ecommerce/user/shipping-country
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.shipping-country.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.shipping-country.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserShippingCountryController@update`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| shipping_country | body | string | 예 | — | 저장할 선호 배송국가 2자리 코드 (활성 배송가능 국가만 허용, 대문자로 정규화되어 저장) |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/user/shipping-country HTTP/1.1
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 로그인한 회원이 선호 배송국가를 저장합니다. `auth:sanctum` 인증이 필요하며, `UpdateUserShippingCountryRequest`가 활성 국가 코드만 허용하도록 검증한 뒤 `UserShippingCountryService::setPreferredShippingCountry()`가 인증 사용자에게 영속합니다. 저장된 값은 대문자로 정규화되어 응답되며, 이후 장바구니·주문 계산의 기본 배송국가로 사용됩니다.


