# Payments API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Payments 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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

_단건 응답: `data` 객체의 필드._ `data` 는 Resource 가 아니라 `sirsoft-ecommerce.payment.get_client_config` **필터 훅의 반환 배열을 그대로** 담습니다. 따라서 키 구성은 설치된 PG 플러그인이 결정하며, 코어(모듈)는 어떤 키도 강제하지 않습니다. 아래는 번들 PG 플러그인 두 종의 실제 반환 키입니다.

공통 (모든 PG 플러그인이 공통으로 내려주는 키)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| sdk_url | string | `https://js.tosspayments.com/v2/standard` | 프론트가 로드해야 할 PG 결제 SDK 스크립트 URL (테스트/운영 모드에 따라 달라질 수 있음) |
| callback_urls | object | `{"success":"/plugins/sirsoft-tosspayments/payment/success","fail":"/plugins/sirsoft-tosspayments/payment/fail"}` | 결제창 성공/실패/콜백 등에서 사용할 플러그인 콜백 경로 모음 (키 구성은 PG마다 다름) |

`tosspayments` (플러그인 `sirsoft-tosspayments`)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| client_key | string | `test_ck_...` | 토스페이먼츠 SDK 초기화용 클라이언트 키 (테스트 모드면 test 키, 운영 모드면 live 키) |
| sdk_url | string | `https://js.tosspayments.com/v2/standard` | 토스페이먼츠 v2 표준 SDK URL |
| order_sheet_mode | boolean | `false` | 결제수단을 주문서(오더시트)에서 직접 선택하는 모드 여부. `false` 면 통합결제창 방식 |
| enabled_methods | array | `[]` | 오더시트 모드에서 활성화된 결제수단 목록. 결제창형(`order_sheet_mode: false`)이면 빈 배열 |
| enabled_methods[].id | string | `toss_card` | 플러그인 설정상의 결제수단 식별자 |
| enabled_methods[].method | string | `CARD` | 토스 SDK 에 전달할 결제수단 코드 |
| enabled_methods[].easy_pay_provider | string\|null | `null` | 간편결제일 때 토스 SDK 의 easyPay provider 값 (일반 결제수단이면 null) |
| enabled_methods[].core_payment_method | string | `card` | 이 결제수단에 대응하는 코어 결제수단 값 (프론트 하드코딩 제거용) |
| vbank | object | `{"valid_hours":24,"cash_receipt_type":""}` | 가상계좌 관련 설정 묶음 |
| vbank.valid_hours | integer | `24` | 가상계좌 입금 기한(시간) |
| vbank.cash_receipt_type | string | `""` | 가상계좌 현금영수증 발급 유형 (미설정이면 빈 문자열) |
| use_escrow | string | `off` | 에스크로 사용 설정값 (플러그인 설정 문자열 그대로) |
| callback_urls.success | string | `/plugins/sirsoft-tosspayments/payment/success` | 결제 성공 리다이렉트 경로 |
| callback_urls.fail | string | `/plugins/sirsoft-tosspayments/payment/fail` | 결제 실패 리다이렉트 경로 |

`kginicis` (플러그인 `sirsoft-pay_kginicis`)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| mid | string | `INIpayTest` | 이니시스 상점 아이디(MID). 테스트/운영 및 에스크로 사용 여부에 따라 선택됨 |
| sdk_url | string | `https://stgstdpay.inicis.com/stdjs/INIStdPay.js` | 이니시스 표준결제 SDK URL (테스트: stgstdpay, 운영: stdpay) |
| callback_urls | object | — | 서명/종료보고/콜백/CBT/모바일 등 이니시스 연동 경로 모음 |
| callback_urls.signature | string | `/plugins/sirsoft-pay_kginicis/payment/signature` | PC 표준결제 서명 발급 경로 |
| callback_urls.close_report | string | `/plugins/sirsoft-pay_kginicis/payment/close-report` | 결제창 종료 보고 경로 |
| callback_urls.callback | string | `/plugins/sirsoft-pay_kginicis/payment/callback` | PC 표준결제 인증 결과 콜백 경로 |
| callback_urls.close | string | `/plugins/sirsoft-pay_kginicis/payment/close` | 결제창 닫기 처리 경로 |
| callback_urls.cbt_checkout_token | string | `/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token` | 해외결제(CBT) 체크아웃 토큰 발급 경로 |
| callback_urls.cbt_hash_data | string | `/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data` | CBT 해시 데이터 생성 경로 |
| callback_urls.cbt_callback | string | `/plugins/sirsoft-pay_kginicis/payment/cbt/callback` | CBT 결제 결과 콜백 경로 |
| callback_urls.cbt_cvs_notify | string | `/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify` | CBT 편의점 결제 입금 통보 경로 |
| callback_urls.cbt_auth_url | string | — | CBT 인증 요청 URL (테스트/운영 상수값) |
| callback_urls.mobile_signature | string | `/plugins/sirsoft-pay_kginicis/payment/mobile/signature` | 모바일 결제 서명 발급 경로 |
| callback_urls.mobile_callback | string | `/plugins/sirsoft-pay_kginicis/payment/mobile/callback` | 모바일 결제 결과 콜백 경로 |
| callback_urls.mobile_vbank_notify | string | `/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify` | 모바일 가상계좌 입금 통보 경로 |
| japan_enabled | boolean | `false` | 일본 결제(해외결제) 사용 여부 설정값 |
| japan_restrict_jpy_payment_methods | boolean | `false` | JPY 결제 시 결제수단을 일본 전용으로 제한할지 여부 |
| japan_configured | boolean | `false` | 일본 결제에 필요한 설정(MID/키 등)이 모두 채워졌는지 여부 |
| standard_configured | boolean | `true` | PC 표준결제 설정이 모두 채워졌는지 여부 |
| mobile_configured | boolean | `true` | 모바일 결제 설정이 모두 채워졌는지 여부 |
| use_escrow | boolean | `false` | 에스크로 사용 여부 설정값 |
| japan_mid | string | — | 일본 결제용 상점 아이디 (테스트면 고정 테스트 MID, 운영이면 설정된 live MID) |
| cbt_extra_data | object | — | CBT 결제 요청에 부가로 실어보낼 데이터 (플러그인 설정 기반으로 구성) |
| use_credit_point | boolean | `false` | 신용카드 포인트 결제 사용 여부 |
| easy_pay_show_brand_button | boolean | `false` | 간편결제 브랜드 버튼 노출 여부 |
| easy_pay_enabled_methods | array | `[]` | 활성화된 국내 간편결제 수단 목록 |

**응답 예시**

`tosspayments` (결제창형, 테스트 모드) 기준:

```json
{
    "success": true,
    "message": "결제 클라이언트 설정을 조회했습니다.",
    "data": {
        "client_key": "test_ck_xxxxxxxxxxxxxxxxxxxxxxxx",
        "sdk_url": "https://js.tosspayments.com/v2/standard",
        "order_sheet_mode": false,
        "enabled_methods": [],
        "vbank": {
            "valid_hours": 24,
            "cash_receipt_type": ""
        },
        "use_escrow": "off",
        "callback_urls": {
            "success": "/plugins/sirsoft-tosspayments/payment/success",
            "fail": "/plugins/sirsoft-tosspayments/payment/fail"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 해당 `provider` 에 대해 `sirsoft-ecommerce.payment.get_client_config` 필터가 빈 설정을 반환한 경우 (PG 플러그인 미설치·미활성·미지원 provider). 메시지: `결제 제공자를 찾을 수 없습니다.` |

<!-- @generated:end -->

**설명** PG 제공자(`provider`, 예: `tosspayments`)의 프론트엔드 결제 SDK 초기화에 필요한 클라이언트 설정을 반환하는 공개 엔드포인트입니다. 인증이 필요 없으며, 결제 페이지가 결제창을 띄우기 직전에 호출합니다. 실제 설정값은 `PaymentConfigController@clientConfig`가 `sirsoft-ecommerce.payment.get_client_config` 필터 훅을 실행해 각 PG 플러그인이 등록한 `client_key`·`sdk_url` 등을 수집한 결과이며, 코어는 어떤 PG도 하드코딩하지 않습니다. 해당 provider에 등록된 설정이 없으면(플러그인 미설치·미활성) 404를 반환합니다.


