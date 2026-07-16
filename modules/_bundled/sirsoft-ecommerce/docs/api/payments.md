# Payments API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Payments 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/payments/client-config/{provider}
<!-- @generated:start:api.modules.sirsoft-ecommerce.payments.client-config -->
- **라우트명**: `api.modules.sirsoft-ecommerce.payments.client-config`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Shop\PaymentConfigController@clientConfig`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | path | string | 예 | — | 대상 provider의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/payments/client-config/{provider} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** PG 제공자(`provider`, 예: `tosspayments`)의 프론트엔드 결제 SDK 초기화에 필요한 클라이언트 설정을 반환하는 공개 엔드포인트입니다. 인증이 필요 없으며, 결제 페이지가 결제창을 띄우기 직전에 호출합니다. 실제 설정값은 `PaymentConfigController@clientConfig`가 `sirsoft-ecommerce.payment.get_client_config` 필터 훅을 실행해 각 PG 플러그인이 등록한 `client_key`·`sdk_url` 등을 수집한 결과이며, 코어는 어떤 PG도 하드코딩하지 않습니다. 해당 provider에 등록된 설정이 없으면(플러그인 미설치·미활성) 404를 반환합니다.


