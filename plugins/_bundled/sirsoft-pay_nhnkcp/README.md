# NHN KCP Plugin for G7

NHN KCP Standard Pay 결제를 G7 `sirsoft-ecommerce` 모듈에 연결하는 결제 플러그인입니다.

PC 결제는 KCP `payplus_web.jsp` 결제창과 KCP CLI 승인 모듈을 사용하고, 모바일 결제는 SmartPhone Pay SOAP 승인키를 받은 뒤 KCP 모바일 결제창으로 이동합니다.

## 주요 기능

- 신용카드, 계좌이체, 가상계좌, 휴대폰결제 지원
- PC Standard Pay 결제창 연동
- 모바일 SmartPhone Pay 승인키 발급 및 모바일 결제창 연동
- PAYCO, 네이버페이, 네이버페이 포인트, 카카오페이, Apple Pay 간편결제 버튼 주입
- 가상계좌 발급, 입금통보, 테스트 모드 모의입금 처리
- 에스크로 결제, 에스크로 배송 등록, 공통통보 처리
- 결제 취소 및 부분취소 연동
- PG 측 결제 취소 확인 시점의 활동 로그 별도 기록 (PG 응답 시각·취소 거래번호 사후 추적)
- 주문 완료/마이페이지 영수증, 현금영수증 조회 버튼 주입
- 관리자 주문 상세의 KCP 거래 정보 표시
- 관리자 설정 화면의 KCP 실행 환경 점검

## 요구 사항

| 항목 | 내용 |
|------|------|
| G7 | `>= 7.0.0-beta.2` |
| 의존 모듈 | `sirsoft-ecommerce >= 1.0.0-beta.5` |
| PHP | `^8.2` |
| PC 결제 | PHP `exec()` 사용 가능, KCP CLI 바이너리, `pub.key` |
| 모바일 결제 | PHP SOAP 확장, KCP WSDL 파일 |
| 운영 환경 | HTTPS 도메인, 올바른 `APP_URL`, KCP 가맹점 계약 정보 |

`bin/` 디렉토리에는 아래 파일이 필요합니다.

```text
bin/pp_cli
bin/pp_cli_x64
bin/pp_cli_exe.exe
bin/pub.key
bin/KCPPaymentService.wsdl
bin/real_KCPPaymentService.wsdl
```

Linux 서버에서는 현재 OS 아키텍처에 맞는 CLI 파일에 실행 권한이 필요합니다.

```bash
chmod 755 plugins/sirsoft-pay_nhnkcp/bin/pp_cli
chmod 755 plugins/sirsoft-pay_nhnkcp/bin/pp_cli_x64
```

관리자 설정 화면의 시스템 점검 API가 실행 권한을 자동 복구할 수 있지만, 서버 권한 정책에 따라 직접 조치가 필요할 수 있습니다.

## 설치

플러그인을 G7 프로젝트의 플러그인 디렉토리에 배치합니다.

```text
plugins/sirsoft-pay_nhnkcp
```

프론트엔드 에셋을 수정한 경우 플러그인 디렉토리에서 빌드합니다.

```bash
npm install
npm run build
```

그다음 G7 관리자에서 플러그인을 활성화하고, 이커머스 결제 설정에서 PG 제공자를 `NHN KCP`로 선택합니다.

## 관리자 설정

관리자 플러그인 설정 화면에서 KCP 계약 정보를 입력합니다.

| 설정 | 설명 |
|------|------|
| 테스트 모드 | 활성화 시 KCP 테스트 환경을 사용합니다. |
| 테스트 사이트 코드 | 기본값은 `T0000`입니다. |
| 테스트 사이트 키 | KCP 테스트 site key입니다. |
| 라이브 사이트 코드 | 운영 site code입니다. `SR` prefix 없이 입력해도 플러그인이 자동 보정합니다. |
| 라이브 사이트 키 | 운영 site key입니다. 외부에 노출하지 마세요. |
| 결제 성공 URL | 기본값은 `/shop/orders/{orderId}/complete`입니다. |
| 결제 실패 URL | 기본값은 `/shop/checkout`입니다. |
| 가상계좌 입금 만료일 | 가상계좌 발급 후 입금 가능 기간입니다. |
| 에스크로 결제 사용 | 활성화 시 KCP 에스크로 결제 파라미터를 함께 전달합니다. |
| 에스크로 테스트 사이트 코드 | 테스트 에스크로 site code입니다. 기본 fallback은 `T0007`입니다. |
| 간편결제 | KCP 계약이 완료된 간편결제만 활성화하세요. |
| 타 PG와 사용가능함 | 다른 PG가 기본값이어도 KCP 간편결제 버튼을 체크아웃 화면에 표시합니다. |

PAYCO 테스트 결제는 내부 기본값으로 간편결제 테스트 site code `S6729`를 사용합니다.

## 콜백 및 통보 URL

KCP 가맹점 관리자에 아래 URL을 등록합니다. 도메인은 실제 운영 도메인으로 바꿔 입력하세요.

| 용도 | URL |
|------|-----|
| 결제 결과 Return URL | `https://your-domain.com/plugins/sirsoft-pay_nhnkcp/payment/callback` |
| 가상계좌 입금통보 URL | `https://your-domain.com/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify` |
| 에스크로 공통통보 URL | `https://your-domain.com/plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify` |

결제 결과 Return URL은 브라우저가 POST하는 경로이므로 IP 제한을 적용하지 않습니다. 가상계좌 입금통보와 에스크로 공통통보는 KCP 서버가 직접 호출하므로 운영 모드에서 IP 화이트리스트를 적용합니다.

## IP 화이트리스트

운영 모드에서는 아래 IP에서 들어온 KCP 서버 통보만 허용합니다. 테스트 모드에서는 개발과 KCP testadmin 모의입금을 위해 IP 제한을 우회합니다.

| IP |
|----|
| `203.238.36.58` |
| `203.238.36.160` |
| `203.238.36.161` |
| `203.238.36.173` |
| `203.238.36.178` |
| `103.215.144.173` |
| `103.215.144.174` |
| `103.215.145.30` |
| `210.122.72.173` |

운영 전 KCP 가맹점 관리자와 최신 연동 가이드의 통보 서버 IP를 다시 확인하세요.

## 결제 흐름

### PC 결제

```text
체크아웃 주문 생성
→ 프론트엔드 핸들러가 payplus_web.jsp 결제창 실행
→ KCP가 /payment/callback 으로 enc_data, enc_info POST
→ 서버가 KCP CLI로 승인 확인
→ 주문 결제 완료 처리
→ 성공 URL로 리다이렉트
```

### 모바일 결제

```text
체크아웃 주문 생성
→ /api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key 호출
→ 서버가 KCP SOAP approve 로 approval_key, pay_url 획득
→ 브라우저가 pay_url 로 form POST
→ KCP가 /payment/callback 으로 결과 POST
→ 주문 결제 완료 처리
→ 성공 URL로 리다이렉트
```

### 가상계좌

```text
결제창에서 가상계좌 발급
→ 주문 결제 정보에 은행, 계좌번호, 예금주, 만료일 저장
→ 주문은 입금대기 상태 유지
→ KCP가 /payment/vbank-notify 로 입금통보 POST
→ 입금 금액 검증 후 주문 결제 완료 처리
```

테스트 모드에서는 마이페이지 주문 상세에 KCP testadmin 모의입금 폼이 표시될 수 있습니다.

### 에스크로

에스크로를 활성화하면 결제 요청에 `escw_used=Y`, `pay_mod=O`를 전달합니다. 에스크로 결제 완료 후 관리자 주문 상세에서 운송장번호와 택배사를 입력해 KCP 배송 등록을 호출할 수 있습니다.

KCP 공통통보는 아래 이벤트를 처리합니다.

| tx_cd | 조건 | 처리 |
|-------|------|------|
| `TX02` | `cl_status=2` | 구매확인 훅 실행 |
| `TX02` | `cl_status=8` | 구매취소 훅 실행 |
| `TX02` | `cl_status=3` | 구매취소 확인 훅 실행 |
| `TX03` | - | 배송시작 훅 실행 |

### 결제 취소 / 부분취소

```text
관리자 주문 취소 요청 (cancel_pg=true)
→ 코어가 sirsoft-ecommerce.payment.refund 필터 훅 발화
→ PaymentRefundListener 가 KCP cancelPayment API 호출
   · 전액취소: isPartial=false
   · 부분취소: isPartial=true, totalAmt=원래 결제금액
→ 코어가 환불 레코드 생성 + 쿠폰 / 마일리지 / 재고 복원
→ CancelActivityLogListener 가 PG 응답 시각·취소 거래번호를 활동 로그에 기록
```

배송비가 포함된 주문은 전체취소 시 배송비도 함께 환불 레코드에 반영되고, 쿠폰이 적용된 주문은 실결제금액(쿠폰 차감 후) 이 PG cancelAmt 로 전달됩니다. 부분취소 시 쿠폰 최소 주문금액 조건을 더 이상 충족하지 못하면 코어가 취소 자체를 거부 (422) 하여 PG 호출이 발생하지 않습니다. KCP API 호출이 실패하면 주문 상태 변경이 롤백됩니다.

## API

### 사용자 API

| Method | Path | 설명 |
|--------|------|------|
| `GET` | `/api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/receipt` | KCP 영수증, 현금영수증 URL 조회 |
| `GET` | `/api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/vbank-mock-deposit-info` | 테스트 모드 가상계좌 모의입금 정보 조회 |
| `POST` | `/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key` | 모바일 결제 승인키 발급 |

### 관리자 API

| Method | Path | 설명 |
|--------|------|------|
| `GET` | `/api/plugins/sirsoft-pay_nhnkcp/admin/vbank-notify-url` | 가상계좌/에스크로 통보 URL 조회 |
| `GET` | `/api/plugins/sirsoft-pay_nhnkcp/admin/orders/test-mode-map` | 주문목록 테스트 모드 배지용 맵 조회 |
| `GET` | `/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/transaction-status` | 저장된 KCP 거래 정보 조회 |
| `GET` | `/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/escrow-delivery` | 에스크로 배송 등록 폼 데이터 조회 |
| `POST` | `/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/escrow-delivery` | KCP 에스크로 배송 등록 |
| `GET` | `/api/plugins/sirsoft-pay_nhnkcp/admin/health` | KCP 실행 환경 점검 |

## 훅

다른 모듈이나 플러그인에서 아래 훅에 연결해 결제 흐름을 확장할 수 있습니다.

| 훅 | 타입 | 시점 |
|----|------|------|
| `sirsoft-pay_nhnkcp.payment.before_confirm` | action | KCP CLI 승인 확인 전 |
| `sirsoft-pay_nhnkcp.payment.after_confirm` | action | KCP CLI 승인 확인 후 |
| `sirsoft-pay_nhnkcp.payment.before_cancel` | action | KCP 취소 API 호출 전 |
| `sirsoft-pay_nhnkcp.payment.after_cancel` | action | KCP 취소 API 호출 후 |
| `sirsoft-pay_nhnkcp.escrow.purchase_confirmed` | action | 에스크로 구매확인 통보 수신 |
| `sirsoft-pay_nhnkcp.escrow.purchase_cancelled` | action | 에스크로 구매취소 통보 수신 |
| `sirsoft-pay_nhnkcp.escrow.denial_confirmed` | action | 에스크로 구매취소 확인 통보 수신 |
| `sirsoft-pay_nhnkcp.escrow.delivery_started` | action | 에스크로 배송시작 통보 수신 |

## 보안 및 운영 참고

- 운영 도메인의 `APP_URL`을 HTTPS 절대 URL로 정확히 설정하세요.
- 운영 site key는 외부에 노출하지 마세요.
- 운영 모드에서는 KCP 서버 통보 IP 화이트리스트가 적용됩니다.
- 결제 승인 후 서버 후속 처리에 실패하면 PG 잔존 승인을 자동 취소합니다.
- 동일 거래번호 콜백은 중복 처리하지 않도록 방어합니다.
- KCP CLI 호출 인자는 위험 문자와 제어문자를 사전에 거부합니다.
- 가상계좌와 에스크로 통보 URL은 KCP 가맹점 관리자에 반드시 등록해야 합니다.
- KCP 계약이 없는 결제수단이나 간편결제를 활성화하면 KCP 오류가 발생할 수 있습니다.

## 테스트

플러그인을 G7 프로젝트에 배치한 뒤 G7 루트에서 PHP 테스트를 실행합니다.

```bash
php artisan test plugins/sirsoft-pay_nhnkcp/tests
```

프론트엔드 테스트와 빌드는 플러그인 디렉토리에서 실행합니다.

```bash
npm install
npm run test:run
npm run build
```

## 라이선스

MIT
