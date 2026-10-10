# 관리자 주문 연동 API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nhnkcp`. 관리자 주문 목록·상세 화면이 호출하는 NHN KCP 연동 경로와 각 경로가 요구하는 세부 권한을 서술한다.

---

## TL;DR (5초 요약)

```text
1. admin 그룹의 모든 경로는 Bearer 토큰(관리자) + 이커머스 세부 권한을 함께 요구한다
2. 조회 경로는 sirsoft-ecommerce.orders.read, 쓰기 경로는 sirsoft-ecommerce.orders.update
3. 설정성 경로(입금통보 주소·시스템 점검)는 sirsoft-ecommerce.settings.read
4. 관리자(type=admin)라도 해당 권한이 없으면 403 이며, 요청은 아무 부작용도 남기지 않는다
5. 다른 결제대행사 플러그인(kginicis·nicepayments)과 동일한 권한 기준이다
```

---

## 권한 매트릭스

`admin` 미들웨어는 관리자 여부(type=admin 권한 보유)만 판정하고 업무 권한은 판정하지 않는다. 따라서 각 라우트가 요구 권한을 직접 선언한다.

| 메서드/URI | 라우트명 | 요구 권한 |
| --- | --- | --- |
| `GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/test-mode-map` | `...admin.orders.test-mode-map` | `sirsoft-ecommerce.orders.read` |
| `GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/transaction-status` | `...admin.orders.transaction-status` | `sirsoft-ecommerce.orders.read` |
| `GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/escrow-delivery` | `...admin.orders.escrow-delivery.form` | `sirsoft-ecommerce.orders.read` |
| `POST /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/escrow-delivery` | `...admin.orders.escrow-delivery.register` | `sirsoft-ecommerce.orders.update` |
| `GET /api/plugins/sirsoft-pay_nhnkcp/admin/settings/test-mode-status` | `...admin.settings.test-mode-status` | `sirsoft-ecommerce.settings.read` |
| `GET /api/plugins/sirsoft-pay_nhnkcp/admin/vbank-notify-url` | `...admin.vbank.notify.url` | `sirsoft-ecommerce.settings.read` |
| `GET /api/plugins/sirsoft-pay_nhnkcp/admin/health` | `...admin.health` | `sirsoft-ecommerce.settings.read` 또는 `core.plugins.update` (OR) |

`admin/vbank-notify-url` 이 돌려주는 `url`(가상계좌 입금통보)·`escrow_common_notify_url`(에스크로 공통통보)은 운영자가 KCP 상점관리자에 등록할 주소라, 관리자가 접속한 주소(요청 Host)가 아니라 사이트 주소(`APP_URL`) 기준 절대 URL 로 만든다.

### 에러 응답

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나, 위 표의 권한을 보유하지 않은 경우 |
| 422 | Validation | 배송등록 요청의 운송장번호·택배사 코드가 형식에 맞지 않는 경우 |

권한 검사는 컨트롤러 진입 **전에** 수행되므로, 403 으로 거부된 배송등록 요청은 결제 정보(`payment_meta`)를 포함해 어떤 상태도 변경하지 않는다.

---

## 조회 경로

### 테스트 모드 주문 맵

`GET .../admin/orders/test-mode-map`

관리자 주문 목록에서 어떤 주문이 테스트 결제인지 배지로 표시하기 위한 맵을 반환한다. 응답 `data` 는 주문번호를 키로 하는 객체다.

### 간편결제 표시 라벨 (별도 경로 없음)

간편결제(PAYCO 등)로 결제된 주문의 원 결제수단 표시 라벨은 이커머스 주문 목록 응답의 `payment.payment_method_display_label` 로 전달된다 — 이 플러그인은 필터 훅 `sirsoft-ecommerce.order.filter_payment_method_display_label` 을 구독해 라벨을 채운다. 종전의 `GET .../admin/orders/easy-pay-display-map` 은 1.1.0 에서 제거됐다.

### 거래 상태 조회

`GET .../admin/orders/{orderNumber}/transaction-status`

상세 필드는 [transaction-status.md](transaction-status.md) 참조.

### 에스크로 배송등록 폼 데이터

`GET .../admin/orders/{orderNumber}/escrow-delivery`

배송등록 화면의 초기값(주문 정보·기본 배송지·기등록 배송 이력)을 반환한다. 이 주문에 에스크로 결제가 없으면 `data` 는 `null` 이다(오류 아님).

### 시스템 점검

`GET .../admin/health`

설정 화면의 시스템 점검 카드가 부르는 경로다(7.1.0 부터 플러그인 설정 수정 권한으로도 읽는다). CLI 실행 권한 자동 복구(chmod)는 플러그인 설정 수정 권한(`core.plugins.update`)이 있을 때만 하고, 그 밖에는 진단과 조치 안내만 돌려준다. CLI 바이너리·`pub.key`·WSDL 등 결제에 필요한 파일이 플러그인 `bin` 디렉토리에 있고 실행 가능한지 확인한다. 응답은 공통 성공 형식이며 `message` 에 점검 완료 안내(`sirsoft-pay_nhnkcp::messages.health.checked`, 요청 언어로 번역)가 실린다.

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| `data.summary` | object | PC·모바일 결제 가능 여부와 상태별 건수(`error`·`warning`·`fixed`) |
| `data.checks` | array | 점검 항목별 결과 목록 |

---

## 쓰기 경로

### 에스크로 배송등록

`POST .../admin/orders/{orderNumber}/escrow-delivery`

| 이름 | 위치 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- | --- |
| `deli_numb` | body | string | 예 | 운송장번호 |
| `deli_corp` | body | string | 예 | 택배사 코드 (KCP 공식 코드표) |

NHN KCP 에 운송장 정보를 등록하고, 응답 중 허용된 필드만 정제해 결제 정보에 기록한다. 주문 조회 권한만 보유한 관리자는 이 경로에서 403 을 받는다.
