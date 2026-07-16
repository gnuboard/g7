# Currency API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Currency 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/user/currency
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.currency.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.currency.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserCurrencyController@show`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/currency HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| preferred_currency | null | `null` | 회원이 저장한 선호 결제 통화 코드 (미설정 시 `null`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "결제 통화를 조회했습니다.",
    "data": {
        "preferred_currency": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 로그인한 회원 본인의 선호 결제 통화를 조회합니다. `auth:sanctum` 인증이 필요하며, `UserCurrencyService::getPreferredCurrency()`가 현재 사용자의 저장된 통화 코드를 반환합니다. 아직 통화를 설정하지 않은 회원은 `preferred_currency`가 `null`로 반환됩니다. 마이페이지 통화 설정 화면이나 회원정보 수정 화면에서 현재 값을 표시하는 용도입니다.


### PUT /api/modules/sirsoft-ecommerce/user/currency
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.currency.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.currency.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserCurrencyController@update`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| currency | body | string | 예 | — | 저장할 선호 결제 통화 코드 (등록 통화: is_default 또는 exchange_rate>0 인 통화만 허용) |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/user/currency HTTP/1.1
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 로그인한 회원 본인의 선호 결제 통화를 저장합니다. `auth:sanctum` 인증이 필요하며, `UserCurrencyService::setPreferredCurrency()`가 검증된 `currency`(등록된 통화만 허용)를 현재 사용자에 영속화하고 저장된 통화 코드를 반환합니다. 마이페이지 통화 설정이나 회원정보 수정에서 회원이 직접 결제 통화를 변경할 때 호출하는 용도입니다.


