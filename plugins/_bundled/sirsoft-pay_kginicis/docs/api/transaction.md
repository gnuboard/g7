# Transaction API 레퍼런스

> **소유**: plugin `sirsoft-pay_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Transaction 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/sirsoft-pay_kginicis/admin/transaction/query
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.transaction.query -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.transaction.query`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminTransactionController@query`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/admin/transaction/query HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

거래번호(TID)를 직접 입력받아 KG 이니시스 거래 상태를 조회하고 화면 표시용으로 보강해 반환하는 관리자 엔드포인트입니다. 로컬 결제 레코드(`ecommerce_order_payments`)에서 결제 시점 MID·테스트 모드·에스크로 여부를 해석해 해당 자격증명으로 `KgInicisApiService::queryTransaction()` 을 호출하며, 응답을 카드/가상계좌/간편결제/취소 이력 등 상세 필드로 정규화하고 은행 코드→은행명·할부 개월·날짜 포맷을 변환합니다. 일본 CBT 거래(TID `INIJPG` prefix 또는 통화 JPY)는 한국 INIAPI 조회 대상이 아니므로 저장된 로컬 승인/입금 확인 정보로 결과를 구성합니다. 관리자 인증(`auth:sanctum`)과 `sirsoft-ecommerce.orders.read` 권한이 필요하며, `tid` 미입력은 422, 토큰 누락·만료 401, 권한 부족 403, KG 이니시스 조회 실패 시 502 로 응답합니다.


