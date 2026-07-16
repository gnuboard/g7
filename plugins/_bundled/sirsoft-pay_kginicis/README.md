# KG Inicis Plugin for G7

KG 이니시스 표준결제를 G7 `sirsoft-ecommerce` 모듈에 연결하는 결제 플러그인입니다.

PC 결제는 KG 이니시스 `INIStdPay.js` 표준결제창을 사용하고, 모바일 결제는 KG 이니시스 모바일 표준결제창으로 이동한 뒤 서버 승인 API로 최종 승인합니다. 일본 엔(JPY) 결제는 KG 이니시스 CBT(JPPG) 흐름을 사용합니다.

## 주요 기능

- 신용카드, 계좌이체, 가상계좌, 휴대폰결제 지원
- PC 표준결제창 연동
- 모바일 표준결제창 연동 및 `P_CHKFAKE` 위변조 방지 해시 생성
- 삼성페이, L.pay, 카카오페이 간편결제 버튼 주입
- 가상계좌 발급, PC/모바일 입금통보 처리
- 에스크로 결제, 배송 등록, 구매결정, 구매거절확인 연동
- 결제 취소 및 부분취소 연동
- PG 측 결제 취소 확인 시점의 활동 로그 별도 기록 (PG 응답 시각·취소 TID 사후 추적)
- 주문 완료/마이페이지 영수증 버튼 주입
- 관리자 주문 상세의 거래 조회, 현금영수증 발행, 에스크로 처리 UI 확장
- 일본 결제 CBT(JPPG) 인증/승인, 테스트 상품 생성, 연결 진단
- 승인 URL 화이트리스트, 콜백 재처리 방지, 타임스탬프 신선도 검증

## 요구 사항

| 항목 | 내용 |
|------|------|
| G7 | `>= 7.0.0-beta.2` |
| 의존 모듈 | `sirsoft-ecommerce >= 1.0.0-beta.4` |
| PHP | `^8.2` |
| 운영 환경 | HTTPS 도메인, 올바른 `APP_URL`, KG 이니시스 가맹점 계약 정보 |
| PC 결제 | MID, signKey |
| 모바일 결제 | MID, 모바일 hash key |
| 취소/거래조회/현금영수증 | INIAPI key, INIAPI IV |
| 일본 CBT | 별도 일본 결제 MID, CBT hash key |

서버에서 KG 이니시스 결제/INIAPI/CBT 호스트로 HTTPS outbound 요청이 가능해야 합니다. CBT 테스트 환경 `devcbt.inicis.com`은 KG 이니시스 측에 서버 egress IP 등록이 필요할 수 있습니다.

## 설치

플러그인을 G7 프로젝트의 플러그인 디렉토리에 배치합니다.

```text
plugins/sirsoft-pay_kginicis
```

프론트엔드 에셋을 수정한 경우 플러그인 디렉토리에서 빌드합니다.

```bash
npm install
npm run build
```

그다음 G7 관리자에서 플러그인을 활성화하고, 이커머스 결제 설정에서 PG 제공자를 `KG 이니시스`로 선택합니다.

## 관리자 설정

관리자 플러그인 설정 화면에서 KG 이니시스 계약 정보를 입력합니다.

| 설정 | 설명 |
|------|------|
| 테스트 모드 | 활성화 시 KG 이니시스 테스트 환경을 사용합니다. 실제 카드 승인/출금 알림이 발생할 수 있으며, 테스트 거래는 매일 23:00~23:50 사이 자동 취소될 수 있습니다. |
| 테스트 MID | 기본값은 `INIpayTest`입니다. 에스크로 테스트 사용 시 내부적으로 `iniescrow0`을 사용합니다. |
| 테스트 사인키 | PC 결제창 서명 생성에 사용합니다. |
| 테스트 INIAPI 키/IV | 취소, 거래조회, 현금영수증, 에스크로 API 인증에 사용합니다. |
| 테스트 모바일 해시키 | 모바일 `P_CHKFAKE` 생성에 사용합니다. |
| 라이브 MID | 운영 MID입니다. `SIR` prefix 없이 입력해도 플러그인이 자동 보정합니다. |
| 라이브 사인키 | 운영 결제창 서명 생성에 사용합니다. 외부에 노출하지 마세요. |
| 라이브 INIAPI 키/IV | 운영 취소, 거래조회, 현금영수증, 에스크로 API 인증에 사용합니다. |
| 라이브 모바일 해시키 | 운영 모바일 `P_CHKFAKE` 생성에 사용합니다. |
| 에스크로 결제 활성화 | PC는 `acceptmethod`에 `useescrow`, 모바일은 `P_RESERVED`에 `useescrow=Y`를 추가합니다. |
| 일본 결제 활성화 | JPY 주문에서 KG 이니시스 CBT(JPPG) 결제 흐름을 사용합니다. |
| 테스트 일본 CBT 해시키 | CBT 테스트 해시 생성에 사용합니다. 테스트 MID는 `CBTTEST001` 고정값을 사용합니다. |
| 라이브 일본 MID/해시키 | 운영 CBT 결제에 사용합니다. |
| JPPG 결제창 표시 정보 | 일본 결제창 `extraData`에 포함되는 가맹점명, 가나명, 영문명, 문의처 정보를 설정합니다. 운영 전 실제 계약 정보로 교체하세요. |
| 결제 성공 URL | 기본값은 `/shop/orders/{orderId}/complete`입니다. |
| 결제 실패 URL | 기본값은 `/shop/checkout`입니다. |
| 간편결제 | KG 이니시스 계약이 완료된 간편결제만 활성화하세요. |
| 타 PG와 사용가능함 | 다른 PG가 기본값이어도 KG 이니시스 간편결제 버튼을 체크아웃 화면에 표시합니다. |
| 신용카드 포인트 사용 | PC 카드 결제 `acceptmethod`에 신용카드 포인트 사용 옵션을 추가합니다. |

테스트 모드 주문은 실제 배송하지 마세요. 실제 카드 승인/출금 알림이 발생할 수 있으며, 테스트 거래는 매일 23:00~23:50 사이 자동 취소될 수 있습니다.

운영 키와 해시키는 외부에 노출하지 말고, 배포 전 테스트 모드가 의도한 값인지 확인하세요.

## 콜백 및 통보 URL

KG 이니시스 가맹점 관리자에 아래 URL을 등록합니다. 도메인은 실제 운영 도메인으로 바꿔 입력하세요.

| 용도 | URL |
|------|-----|
| PC 결제 결과 Return URL | `https://your-domain.com/plugins/sirsoft-pay_kginicis/payment/callback` |
| PC 가상계좌 입금통보 URL | `https://your-domain.com/plugins/sirsoft-pay_kginicis/payment/vbank-notify` |
| 모바일 결제 결과 `P_NEXT_URL` | `https://your-domain.com/plugins/sirsoft-pay_kginicis/payment/mobile/callback` |
| 모바일 가상계좌 입금통보 URL | `https://your-domain.com/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify` |
| CBT 콜백 URL | `https://your-domain.com/plugins/sirsoft-pay_kginicis/payment/cbt/callback` |
| CBT 편의점 입금 NOTI URL | `https://your-domain.com/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify` |
| 에스크로 구매결정 화면 | `https://your-domain.com/plugins/sirsoft-pay_kginicis/payment/escrow-confirm/{orderNumber}` |

PC 결제 결과 Return URL과 모바일 `P_NEXT_URL`은 사용자 브라우저를 통해 호출됩니다. 가상계좌 입금통보 URL은 KG 이니시스 서버가 직접 호출하므로 운영 환경에서 IP 화이트리스트가 적용됩니다.

KG 이니시스 PC 에스크로 매뉴얼 기준 별도 webhook 통보 채널은 사용하지 않습니다. 에스크로 배송등록, 구매결정, 구매거절확인은 플러그인이 제공하는 화면과 API를 통해 처리합니다.

## IP 화이트리스트

운영 환경에서는 아래 IP에서 들어온 KG 이니시스 가상계좌 입금통보만 허용합니다. `local`, `testing` 환경에서는 개발과 테스트를 위해 제한을 우회합니다.

| IP |
|----|
| `203.238.37.15` |
| `39.115.212.9` |
| `118.129.210.25` |
| `183.109.71.153` |

운영 전 KG 이니시스 가맹점 관리자와 최신 연동 가이드의 통보 서버 IP를 다시 확인하세요.

## 결제 흐름

### PC 결제

```text
체크아웃 주문 생성
→ 프론트엔드 핸들러가 /payment/signature 호출
→ INIStdPay.js 결제창 실행
→ KG 이니시스가 /payment/callback 으로 authToken, authUrl POST
→ 서버가 authUrl 화이트리스트 검증 후 승인 API 호출
→ 주문 결제 완료 처리
→ 성공 URL로 리다이렉트
```

승인 후 주문 처리에 실패하면 KG 이니시스 netCancel URL로 망취소를 시도합니다.

### 모바일 결제

```text
체크아웃 주문 생성
→ /payment/mobile/signature 호출
→ 모바일 표준결제창으로 form POST
→ KG 이니시스가 /payment/mobile/callback 으로 P_TID, P_REQ_URL 전달
→ 서버가 P_REQ_URL 화이트리스트 검증 후 모바일 승인 API 호출
→ 주문 결제 완료 처리
→ 성공 URL로 리다이렉트
```

모바일 승인 후 주문 처리에 실패하면 취소 API로 자동 취소를 시도합니다.

### 가상계좌

```text
결제창에서 가상계좌 발급
→ 주문 결제 정보에 은행, 계좌번호, 예금주, 만료일 저장
→ 주문은 입금대기 상태 유지
→ KG 이니시스가 입금통보 URL로 입금 결과 POST
→ 거래번호 재처리와 금액 검증 후 주문 결제 완료 처리
```

PC와 모바일 입금통보는 서로 다른 URL을 사용하지만, 모두 같은 IP 화이트리스트 미들웨어를 통과해야 합니다.

### 일본 CBT

```text
JPY 주문 생성
→ /payment/cbt/hash-data 호출
→ CBT 인증 URL로 form POST
→ KG 이니시스가 /payment/cbt/callback 으로 sid 전달
→ 서버가 cbtapprove API 호출
→ 카드/PayPay는 주문 결제 완료 처리
→ 편의점(CVS)은 입금대기 저장 후 /payment/cbt/cvs-notify 입금 NOTI 수신 시 결제 완료 처리
```

CBT 승인 이후 로컬 후속 처리에 실패하면 CBT 전용 취소 API로 자동 취소를 시도합니다. 자동 취소까지 실패한 경우에는 운영자 수동 취소가 필요하다는 오류 로그를 남깁니다.

일본 엔(JPY) 주문은 일본 결제 설정이 완료된 경우에만 CBT 결제창으로 진입합니다. 설정이 부족하면 한국 표준결제 흐름으로 대체하지 않고 결제를 중단합니다.

현재 CBT 결제창은 선택한 결제수단에 맞춰 지불수단을 제한합니다. 신용카드는 `CARD`만, PayPay는 `PAYpay`만, 일본 편의점결제는 `CVS`만 열립니다.

운영 모드에서 일본 결제를 활성화하려면 라이브 일본 MID/CBT 해시키와 실제 JPPG 가맹점 표시 정보가 필요합니다. 기본 샘플값이 남아 있으면 설정 저장 단계에서 차단됩니다.

### 에스크로

에스크로를 활성화하면 결제 요청에 에스크로 옵션을 전달합니다. 에스크로 결제 완료 후 관리자 주문 상세에서 배송 등록을 호출할 수 있고, 사용자는 에스크로 구매결정 화면에서 구매확인을 진행할 수 있습니다.

구매거절이 발생한 주문은 관리자 주문 상세에서 구매거절확인을 호출할 수 있습니다.

### 결제 취소 / 부분취소

```text
관리자 주문 취소 요청 (cancel_pg=true)
→ 코어가 sirsoft-ecommerce.payment.refund 필터 훅 발화
→ PaymentRefundListener 가 KG 이니시스 취소/부분취소 API 호출
   · 전액취소: cancelPrice=null, totalAmount=null
   · 부분취소: cancelPrice=취소 금액, totalAmount=원래 결제금액
→ 코어가 환불 레코드 생성 + 쿠폰 / 마일리지 / 재고 복원
→ CancelActivityLogListener 가 PG 응답 시각·취소 TID를 활동 로그에 기록
```

배송비가 포함된 주문은 전체취소 시 배송비도 함께 환불 레코드에 반영되고, 쿠폰이 적용된 주문은 실결제금액(쿠폰 차감 후) 이 PG 취소 금액으로 전달됩니다. 부분취소 시 쿠폰 최소 주문금액 조건을 더 이상 충족하지 못하면 코어가 취소 자체를 거부 (422) 하여 PG 호출이 발생하지 않습니다. KG 이니시스 API 호출이 실패하면 주문 상태 변경이 롤백됩니다.

## API

### 사용자 API

| Method | Path | 설명 |
|--------|------|------|
| `POST` | `/api/plugins/sirsoft-pay_kginicis/payment/signature` | PC 결제창 서명 생성 |
| `POST` | `/api/plugins/sirsoft-pay_kginicis/payment/mobile/signature` | 모바일 `P_CHKFAKE` 생성 |
| `POST` | `/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data` | CBT hashData 생성 |
| `GET` | `/api/plugins/sirsoft-pay_kginicis/user/orders/{orderNumber}/receipt` | KG 이니시스 영수증 URL 조회 |

### 관리자 API

| Method | Path | 설명 |
|--------|------|------|
| `GET` | `/api/plugins/sirsoft-pay_kginicis/admin/vbank-notify-url` | PC/모바일 가상계좌 입금통보 URL 조회 |
| `GET` | `/api/plugins/sirsoft-pay_kginicis/admin/orders/test-mode-map` | 주문목록 테스트 모드 배지용 맵 조회 |
| `POST` | `/api/plugins/sirsoft-pay_kginicis/admin/transaction/query` | TID로 KG 이니시스 거래 조회 |
| `GET` | `/api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/transaction-status` | 주문번호로 거래 상태 조회 |
| `POST` | `/api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/cash-receipt` | 현금영수증 별도 발행 |
| `GET` | `/api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/escrow-delivery` | 에스크로 배송 등록 폼 데이터 조회 |
| `POST` | `/api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/escrow-delivery` | KG 이니시스 에스크로 배송 등록 |
| `POST` | `/api/plugins/sirsoft-pay_kginicis/admin/orders/{orderNumber}/escrow-deny-confirm` | 에스크로 구매거절확인 |
| `POST` | `/api/plugins/sirsoft-pay_kginicis/admin/cbt-test-product` | CBT 테스트용 JPY 상품 생성 |
| `GET` | `/api/plugins/sirsoft-pay_kginicis/admin/cbt-connectivity-check` | CBT 테스트 호스트 연결 진단 |

## 훅

다른 모듈이나 플러그인에서 아래 훅에 연결해 결제 흐름을 확장할 수 있습니다.

| 훅 | 타입 | 시점 |
|----|------|------|
| `sirsoft-pay_kginicis.payment.before_authorize` | action | KG 이니시스 서버 승인 API 호출 전 |
| `sirsoft-pay_kginicis.payment.after_authorize` | action | KG 이니시스 서버 승인 완료 후 |
| `sirsoft-pay_kginicis.payment.before_cancel` | action | KG 이니시스 결제 취소 API 호출 전 |
| `sirsoft-pay_kginicis.payment.after_cancel` | action | KG 이니시스 결제 취소 완료 후 |

`sirsoft-ecommerce.payment.refund` 필터를 통해 이커머스 환불 요청을 KG 이니시스 취소/부분취소 API로 연결합니다.

## 보안 및 운영 참고

- 운영 도메인의 `APP_URL`을 HTTPS 절대 URL로 정확히 설정하세요.
- 운영 signKey, INIAPI key, INIAPI IV, 모바일 hash key, CBT hash key는 외부에 노출하지 마세요.
- 결제창 서명, 모바일 해시, CBT 해시 생성 요청은 타임스탬프 신선도를 검증합니다.
- CBT 해시 생성 요청은 주문자 이메일/연락처와 서버 주문 정보를 대조하고, IP/주문번호 단위 요청 횟수를 제한합니다.
- CBT 환불은 결제 당시 저장된 테스트/운영 모드와 일본 MID 기준으로 처리합니다.
- PC `authUrl`, 모바일 `P_REQ_URL`, PC `netCancelUrl`은 KG 이니시스 허용 URL만 사용합니다.
- 동일 거래번호 콜백은 중복 처리하지 않도록 방어합니다.
- 운영 환경에서는 가상계좌 입금통보 IP 화이트리스트가 적용됩니다.
- 가상계좌 입금통보 URL은 KG 이니시스 가맹점 관리자에 반드시 등록해야 합니다.
- KG 이니시스 계약이 없는 결제수단이나 간편결제를 활성화하면 결제창 오류가 발생할 수 있습니다.
- CBT 테스트가 실패하면 관리자 CBT 연결 진단에서 서버 egress IP와 `devcbt.inicis.com` 443 연결 상태를 먼저 확인하세요.

## 테스트

플러그인을 G7 프로젝트에 배치한 뒤 G7 루트에서 PHP 테스트를 실행합니다.

```bash
php artisan test plugins/sirsoft-pay_kginicis/tests
```

프론트엔드 테스트와 빌드는 플러그인 디렉토리에서 실행합니다.

```bash
npm install
npm run test:run
npm run build
```

## 라이선스

MIT
