# NicePayments Plugin for G7

나이스페이먼츠(NicePayments) PG 연동 플러그인입니다. G7 플랫폼의 sirsoft-ecommerce 모듈과 함께 동작합니다.

## 지원 결제 수단

| 결제 수단 | PayMethod |
|-----------|-----------|
| 신용카드 | CARD |
| 가상계좌 | VBANK |
| 계좌이체 | BANK |
| 휴대폰결제 | CELLPHONE |

## 설치

```bash
# 플러그인 디렉토리에 배치 후
composer install
npm install && npm run build
```

## 설정

관리자 → 플러그인 → NicePayments 설정에서 구성합니다.

| 항목 | 설명 |
|------|------|
| 테스트 모드 | 활성화 시 나이스페이먼츠 공용 테스트 MID를 사용합니다. 실제 카드 승인/출금 알림이 발생할 수 있으며, 테스트 계정 결제는 당일 23:30경 일괄 자동 취소됩니다. |
| 테스트 MID | 테스트 가맹점 ID (`nicepay00m` 기본값) |
| 테스트 가맹점 키 | 나이스페이 공용 테스트 키 |
| 라이브 MID | 실서비스 가맹점 ID |
| 라이브 가맹점 키 | 실서비스 가맹점 키 (외부 노출 금지) |
| 결제 성공 URL | 결제 완료 후 리다이렉트 경로 (`{orderId}` 치환 지원) |
| 결제 실패 URL | 결제 실패 후 리다이렉트 경로 |

테스트 모드 주문은 실제 배송하지 마세요. 실제 카드 승인/출금 알림이 발생할 수 있으며, 테스트 계정 결제는 당일 23:30경 일괄 자동 취소됩니다.

## 웹훅 (가상계좌 입금 통보)

나이스페이먼츠 관리자에서 가상계좌 입금 통보 URL을 아래로 설정하세요:

```
https://your-domain.com/plugins/sirsoft-pay_nicepayments/payment/vbank-notify
```

### IP 화이트리스트

나이스페이먼츠 서버 IP만 허용됩니다. 로컬/테스트 환경에서는 자동으로 우회됩니다.

| IP |
|----|
| 121.133.126.10 |
| 121.133.126.11 |
| 211.33.136.39 |

## 결제 흐름

### PC / 모바일 결제 (인증 + 승인 2단계)

```
브라우저  →  goPay(form) / 모바일 결제창 form POST
결제창    →  POST /payment/callback  →  authCallback() (1단계 인증)
서버      →  POST NextAppURL  →  승인 API 호출 (2단계)
승인 완료 →  completePayment()  →  성공 페이지 리다이렉트
```

### 결제창 취소 / 인증 실패

모바일 결제창에서 사용자가 취소버튼을 누르거나 PG 가 인증을 거부하면 (AuthResultCode != '0000') 결제 승인 (NextAppURL 호출) 이전이므로 사용자에게 generic 오류 메시지를 띄우지 않고 체크아웃으로 silent redirect 합니다. 운영 가시성은 로그(`auth_result_code` / `auth_result_msg`) 로 보존됩니다. 2단계 이후 hard failure (signature / mid / amount / authorize) 는 종전대로 `?error=` 쿼리 부착하여 안내합니다.

### 결제 취소 / 부분취소

```text
관리자 주문 취소 요청 (cancel_pg=true)
→ 코어가 sirsoft-ecommerce.payment.refund 필터 훅 발화
→ PaymentRefundListener 가 NicePayments cancelPayment API 호출
   · 전액취소: isPartial=0
   · 부분취소: isPartial=1
→ 코어가 환불 레코드 생성 + 쿠폰 / 마일리지 / 재고 복원
→ CancelActivityLogListener 가 PG 응답 시각·취소 TID를 활동 로그에 기록
```

배송비가 포함된 주문은 전체취소 시 배송비도 함께 환불 레코드에 반영되고, 쿠폰이 적용된 주문은 실결제금액(쿠폰 차감 후) 이 PG cancelAmt 로 전달됩니다. 부분취소 시 쿠폰 최소 주문금액 조건을 더 이상 충족하지 못하면 코어가 취소 자체를 거부 (422) 하여 PG 호출이 발생하지 않습니다. 가상계좌 입금 완료 건은 환불 계좌 정보가 필요해 일반 취소 API 가 아닌 별도 어드민 환불 계좌 API 경로로 처리됩니다.

## 가용 훅 (Hook)

다른 플러그인이나 리스너에서 아래 훅에 연결할 수 있습니다.

### 액션 훅

| 훅 이름 | 시점 | 인수 |
|---------|------|------|
| `sirsoft-pay_nicepayments.payment.before_authorize` | 서버 승인 API 호출 직전 | `Order $order, array $pgParams` |
| `sirsoft-pay_nicepayments.payment.after_authorize` | 서버 승인 API 응답 직후 | `Order $order, array $pgResponse` |
| `sirsoft-pay_nicepayments.payment.before_cancel` | NicePayments 취소 API 호출 직전 | `Order $order, OrderPayment $payment, float $refundAmount` |
| `sirsoft-pay_nicepayments.payment.after_cancel` | NicePayments 취소 API 호출 직후 | `Order $order, OrderPayment $payment, array $pgResponse` |
| `sirsoft-pay_nicepayments.payment.refund_failed` | 환불 API 호출 실패 시 | `Order $order, OrderPayment $payment, array $context` |

#### `refund_failed` context 구조

```php
[
    'tid'        => string,  // 나이스페이 거래번호
    'cancel_amt' => int,     // 환불 시도 금액 (원)
    'error'      => string,  // 오류 메시지
]
```

### 훅 등록 예시

```php
use App\Extension\HookManager;

HookManager::addAction(
    'sirsoft-pay_nicepayments.payment.refund_failed',
    function (Order $order, OrderPayment $payment, array $context) {
        // 예: Slack 알림 발송
        SlackNotifier::send("환불 실패: 주문 #{$order->order_number}, 오류: {$context['error']}");
    },
    priority: 10
);
```

## API 단건 조회

`NicePaymentsApiService::queryTransaction(string $tid): array` 메서드로 거래 상태를 조회할 수 있습니다.

```php
$apiService = app(\Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService::class);
$result = $apiService->queryTransaction('NICE_TID_12345');
// $result['ResultCode'], $result['Amt'], ...
```

## 과세 처리

결제 요청 시 주문의 `total_tax_amount`, `total_vat_amount`, `total_tax_free_amount` 값을 자동으로 나이스페이 폼에 포함합니다. 세 값이 모두 0이면 과세 필드를 생략합니다.

## 테스트 실행

```bash
cd c:/g7
php artisan test --filter=Nicepayments
```

## 라이선스

MIT
