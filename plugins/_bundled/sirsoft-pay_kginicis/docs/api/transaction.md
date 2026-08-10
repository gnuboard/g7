# Transaction API 레퍼런스

> **소유**: plugin `sirsoft-pay_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Transaction 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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

_단건 응답: `data` 객체의 필드._

`data` 는 KG 이니시스 INIAPI v2 `inquiry` 응답(원본 키 그대로)에 화면 표시용 보강 필드(`_` 접두 키)를 덧붙인 객체입니다. 원본 키는 결제수단·거래 상태에 따라 존재 여부가 달라지므로, 화면은 항상 `_` 접두 필드를 기준으로 읽습니다. 보강 필드는 조회 응답 → 로컬 결제 레코드(`ecommerce_order_payments`) fallback → `null` 순으로 채워집니다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| resultCode | string | `0000` | KG 이니시스 조회 결과 코드 (원본 응답). CBT 로컬 조회 시에는 `LOCAL_CBT` |
| resultMsg | string | `정상처리` | KG 이니시스 조회 결과 메시지 (원본 응답) |
| tid | string | `StdpayCARDSIRSOFT001202606191358...` | 거래번호(TID) |
| \_is_test_mode | boolean | `true` | 이 거래가 테스트 모드 자격증명으로 결제/조회된 거래인지 여부 |
| \_is_cbt | boolean | `true` | 일본 CBT(국가간 거래) 결제 여부. CBT 응답에만 포함 |
| \_is_local_confirmation | boolean | `true` | KG 이니시스 조회 API 가 아닌 로컬 저장 승인/입금 정보로 구성된 결과인지 여부. CBT 응답에만 포함 |
| \_local_notice | string | `CBT 거래는 한국 INIAPI 거래조회 대상이 아니므로 저장된 승인/입금 확인 정보로 표시됩니다.` | 로컬 확인 결과임을 알리는 안내 문구. CBT 응답에만 포함 |
| \_local_is_escrow | boolean | `false` | 로컬 결제 레코드(`ecommerce_order_payments.is_escrow`) 기준 에스크로 결제 여부 |
| \_pay_method | string | `Card` | KG 이니시스 결제수단 원본 코드 (`Card`/`VBank`/`DirectBank`/`HPP`/`EasyPay`/`CVS` 등) |
| \_base_pay_method_label | string | `신용카드` | 결제수단 코드의 한국어 라벨 (미매핑 시 코드 원문, 없으면 `-`) |
| \_embedded_pg_provider | string \| null | `kakaopay` | 간편결제 등에서 내부 결제사업자 코드 (없으면 `null`) |
| \_embedded_pg_provider_label | string \| null | `카카오페이` | 내부 결제사업자의 한국어 라벨 (없으면 `null`) |
| \_pay_method_label | string | `카카오페이 (신용카드)` | 화면 표시용 최종 결제수단 라벨. 내부 결제사업자가 있으면 `사업자 (기본수단)` 형태 |
| \_auth_code | string \| null | `30001234` | 승인번호 (카드 `applNum` 등) |
| \_auth_date | string \| null | `2026-06-19 13:58:55` | 승인 일시 (`YYYY-MM-DD HH:MM:SS`) |
| \_total_price | string \| null | `39000` | 결제(승인) 금액 |
| \_currency | string | `WON` | 결제 통화. KG 국내 거래는 `WON`, CBT 거래는 `JPY` 등 |
| \_moid | string \| null | `20260619-1358556131` | 주문번호(MOID) |
| \_buyer_name | string \| null | `홍길동` | 구매자 이름 |
| \_buyer_email | string \| null | `buyer@example.com` | 구매자 이메일 |
| \_buyer_tel | string \| null | `01012345678` | 구매자 연락처 |
| \_status | string \| null | `0` | KG 이니시스 거래 상태 값 (원본 `status`) |
| \_cancel_price | string \| null | `39000` | 취소 금액 (취소 이력이 있는 경우) |
| \_cancel_date | string \| null | `2026-06-20 10:12:00` | 취소 일시 |
| \_part_cancel_list | array | `[]` | 부분취소 이력 배열. 각 항목은 `price` / `date` / `msg` / `tid` |
| \_card_name | string \| null | `신한카드` | 카드사명 |
| \_card_num | string \| null | `123456******7890` | 마스킹된 카드번호 |
| \_card_code | string \| null | `03` | 카드사 코드 |
| \_card_quota | string \| null | `일시불` | 할부 개월 표시값 (`0` → `일시불`, 그 외 `N개월`) |
| \_card_interest | string \| null | `0` | 무이자 여부 원본 값 (CBT 응답에서는 항상 `null`) |
| \_vbank_num | string \| null | `56212345678901` | 가상계좌 번호 (CBT 편의점결제는 접수번호) |
| \_vbank_bank_code | string \| null | `88` | 가상계좌 은행 코드 |
| \_vbank_bank_name | string \| null | `신한(통합)은행` | 가상계좌 은행명. 미제공 시 은행 코드로 매핑 |
| \_vbank_holder | string \| null | `홍길동` | 가상계좌 예금주명 |
| \_vbank_expire_date | string \| null | `2026-06-20 08:59:59` | 가상계좌 입금 기한(KST). 로컬 `vbank_due_at` 이 있으면 그 값을 KST 로 변환해 사용 |
| \_vbank_status | string \| null | `1` | 가상계좌 입금 상태 값 |
| \_vbank_paid_at | string \| null | `2026-06-19 17:03:11` | 가상계좌 입금 완료 일시 |
| \_bank_code | string \| null | `04` | 계좌이체 은행 코드 |
| \_bank_name | string \| null | `국민은행` | 계좌이체 은행명. 미제공 시 은행 코드로 매핑 |
| \_bank_acnt_num | string \| null | `12345678901234` | 계좌이체 출금 계좌번호 |
| \_hpp_num | string \| null | `01012345678` | 휴대폰 결제 번호 |
| \_hpp_corp | string \| null | `SKT` | 휴대폰 결제 통신사 |
| \_escrow_status | string \| null | `1` | 에스크로 상태 값 |
| \_escrow_confirm | string \| null | `2026-06-21 09:00:00` | 에스크로 구매확정 일시 |
| \_inquiry_at | string | `2026-06-19 14:02:31` | 이 조회를 수행한 서버 시각 |
| \_cbt_cvs | object \| null | `null` | 일본 편의점결제(CVS) 거래일 때의 상세 상태. `status` / `last_notify_at` / `last_notify_result` / `last_notify_reason` / `last_recheck_at` / `last_recheck_result` / `expired_at` / `expiry_reason` / `notify_history`(최대 10건). CBT 응답에만 포함 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "resultCode": "0000",
        "resultMsg": "정상처리",
        "tid": "StdpayCARDSIRSOFT001202606191358556131",
        "_is_test_mode": true,
        "_local_is_escrow": false,
        "_pay_method": "Card",
        "_base_pay_method_label": "신용카드",
        "_embedded_pg_provider": null,
        "_embedded_pg_provider_label": null,
        "_pay_method_label": "신용카드",
        "_auth_code": "30001234",
        "_auth_date": "2026-06-19 13:58:55",
        "_total_price": "39000",
        "_currency": "WON",
        "_moid": "20260619-1358556131",
        "_buyer_name": "홍길동",
        "_buyer_email": "buyer@example.com",
        "_buyer_tel": "01012345678",
        "_status": "0",
        "_cancel_price": null,
        "_cancel_date": null,
        "_part_cancel_list": [],
        "_card_name": "신한카드",
        "_card_num": "123456******7890",
        "_card_code": "03",
        "_card_quota": "일시불",
        "_card_interest": null,
        "_vbank_num": null,
        "_vbank_bank_code": null,
        "_vbank_bank_name": null,
        "_vbank_holder": null,
        "_vbank_expire_date": null,
        "_vbank_status": null,
        "_vbank_paid_at": null,
        "_bank_code": null,
        "_bank_name": null,
        "_bank_acnt_num": null,
        "_hpp_num": null,
        "_hpp_corp": null,
        "_escrow_status": null,
        "_escrow_confirm": null,
        "_inquiry_at": "2026-06-19 14:02:31"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |
| 422 | Unprocessable Entity | `tid` 가 비어 있는 경우 (`errors.tid` = `TID를 입력하세요.`) |
| 502 | Bad Gateway | KG 이니시스 조회 API 호출 실패(HTTP 오류·타임아웃 등) 시 |

<!-- @generated:end -->

**설명**

거래번호(TID)를 직접 입력받아 KG 이니시스 거래 상태를 조회하고 화면 표시용으로 보강해 반환하는 관리자 엔드포인트입니다. 로컬 결제 레코드(`ecommerce_order_payments`)에서 결제 시점 MID·테스트 모드·에스크로 여부를 해석해 해당 자격증명으로 `KgInicisApiService::queryTransaction()` 을 호출하며, 응답을 카드/가상계좌/간편결제/취소 이력 등 상세 필드로 정규화하고 은행 코드→은행명·할부 개월·날짜 포맷을 변환합니다. 일본 CBT 거래(TID `INIJPG` prefix 또는 통화 JPY)는 한국 INIAPI 조회 대상이 아니므로 저장된 로컬 승인/입금 확인 정보로 결과를 구성합니다. 관리자 인증(`auth:sanctum`)과 `sirsoft-ecommerce.orders.read` 권한이 필요하며, `tid` 미입력은 422, 토큰 누락·만료 401, 권한 부족 403, KG 이니시스 조회 실패 시 502 로 응답합니다.


