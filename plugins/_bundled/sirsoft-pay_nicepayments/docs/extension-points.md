# 나이스페이먼츠 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 5종 / 호출 지점 5곳. 이 중 1종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-pay_nicepayments.payment.after_authorize` | action | 나이스페이먼츠 서버 승인 완료 후 | `src/Controllers/PaymentCallbackController.php:257` |
| `sirsoft-pay_nicepayments.payment.after_cancel` | action | 나이스페이먼츠 결제 취소 완료 후 | `src/Services/NicePaymentsApiService.php:308` |
| `sirsoft-pay_nicepayments.payment.before_authorize` | action | 나이스페이먼츠 서버 승인 API 호출 전 | `src/Controllers/PaymentCallbackController.php:252` |
| `sirsoft-pay_nicepayments.payment.before_cancel` | action | 나이스페이먼츠 결제 취소 API 호출 전 (본인인증 등 확장 지점) | `src/Services/NicePaymentsApiService.php:285` |
| `sirsoft-pay_nicepayments.payment.refund_failed` | action | — | `src/Listeners/PaymentRefundListener.php:131` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`before_authorize`/`before_cancel`은 API 호출 **전** 개입 지점입니다 — 예외를 던지면 실제
나이스페이먼츠 호출 자체가 일어나지 않습니다. `refund_failed`는 `getHooks()` 선언 없이
소스에서 자동 감지된 훅으로, `after_cancel`(성공 응답)과 달리 취소 API 호출이 **실패**했을
때만 발화합니다 — 운영 알림을 붙이려는 확장은 이 훅을 구독해야 합니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.layout_extension.after_apply` | filter | `AdjustEcommercePaymentMethodsLayoutListener` | `adjustPaymentMethodsLayout` | 40 |
| `core.layout_extension.after_apply` | filter | `EnsureAdminOrderDetailPaymentQueryLayoutListener` | `ensurePaymentQueryLayout` | 66 |
| `core.layout_extension.after_apply` | filter | `EnsureAdminOrderListTestBadgeLayoutListener` | `ensureTestBadgeLayout` | 60 |
| `core.plugins.updated` | action | `RestoreLayoutExtensionsAfterUpdateListener` | `restoreCurrentExtensionsAfterUpdate` | 20 |
| `sirsoft-ecommerce.payment.get_client_config` | filter | `RegisterPgProviderListener` | `getClientConfig` | 10 |
| `sirsoft-ecommerce.payment.refund` | filter | `CancelActivityLogListener` | `logCancelConfirmed` | 20 |
| `sirsoft-ecommerce.payment.refund` | filter | `PaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | `RegisterPgProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | `RegisterEasyPayMethodsListener` | `injectEasyPayMethods` | 40 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`PaymentRefundListener`(10)가 `CancelActivityLogListener`(20)보다 먼저 실행되도록 우선순위를
명시한 것은 실제 취소가 성공한 뒤에야 활동 로그를 남기기 위함입니다 — 순서가 뒤바뀌면
"로그는 있는데 취소는 실패"가 생깁니다. `RegisterEasyPayMethodsListener`가 8종의 간편결제
방식을 하나의 필터 훅에서 한 번에 주입하는 이유는 §settings.md 의 설정 스키마와 대칭을
맞추기 위함입니다 — 설정 키가 8개인데 훅 등록이 여러 곳으로 흩어지면 한쪽만 갱신되는
사각이 생깁니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `AdjustEcommercePaymentMethodsLayoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/AdjustEcommercePaymentMethodsLayoutListener.php` |
| `CancelActivityLogListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/CancelActivityLogListener.php` |
| `EnsureAdminOrderDetailPaymentQueryLayoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/EnsureAdminOrderDetailPaymentQueryLayoutListener.php` |
| `EnsureAdminOrderListTestBadgeLayoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/EnsureAdminOrderListTestBadgeLayoutListener.php` |
| `PaymentRefundListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PaymentRefundListener.php` |
| `RegisterEasyPayMethodsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterEasyPayMethodsListener.php` |
| `RegisterPgProviderListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/RegisterPgProviderListener.php` |
| `RestoreLayoutExtensionsAfterUpdateListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RestoreLayoutExtensionsAfterUpdateListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`RestoreLayoutExtensionsAfterUpdateListener`는 `plugin:update`가 레이아웃 확장 조각(§레이아웃
확장)의 활성/비활성 상태를 초기화할 수 있어서 존재합니다 — 운영자가 특정 화면을 꺼둔 상태로
업데이트해도 그 선택이 사라지지 않도록 복원합니다. 8개 리스너 전부가
`HookListenerInterface`를 구현하는 것은 이 저장소의 전 리스너 공통 계약입니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin_order_list_test_badge.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/admin_order_payment_query.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/checkout_easy_pay.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user_mypage_order_receipt.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user_order_complete_receipt.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
5개 조각은 각각 독립적인 화면 관심사입니다 — 관리자 주문 목록의 테스트배지, 관리자 주문
상세의 거래조회 UI, 체크아웃의 간편결제 버튼, 마이페이지·주문완료의 영수증 버튼이 서로
다른 화면에 주입되므로 하나로 합치지 않았습니다. `user_mypage_order_receipt.json`과
`user_order_complete_receipt.json`이 별도 파일인 것은 두 화면의 컴포넌트 트리와 데이터
소스가 다르기 때문입니다(§AGENTS.md "설계 원칙" — 상태는 이커머스 소유이므로 화면마다
필요한 조회 방식이 다를 수 있습니다).
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `VbankNotifyIpWhitelist` | `web.plugins.sirsoft-pay_nicepayments.payment.vbank-notify` | - |
<!-- @generated:middleware END -->

<!-- @intent START -->
결제 결과 콜백(`/payment/callback`)에는 이 미들웨어가 붙지 않습니다 — 그 경로는 브라우저가
POST 하는 경로라 발신 IP 가 사용자마다 다르기 때문입니다. IP 화이트리스트가 의미 있는 것은
나이스페이먼츠 서버가 직접 호출하는 가상계좌 입금통보뿐입니다. 로컬/테스트 환경에서는
이 제한을 자동 우회합니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
결제 승인·통보는 전부 동기 HTTP 요청/응답 안에서 끝나는 흐름이라 실시간 브로드캐스트가
필요한 지점이 없습니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
가상계좌 만료는 이 플러그인이 크론으로 스캔하지 않고, 만료 이후 도착하는 나이스페이먼츠
입금통보를 거부하는 방식으로 처리됩니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
결제 완료/실패 알림은 이커머스 모듈이 주문 상태 변화를 기준으로 발송하는 공용 알림에 이미
포함됩니다 — PG 마다 별도 알림 정의를 만들면 같은 이벤트에 대해 PG 수만큼 중복 정의가
생깁니다. 운영자에게 실패를 알리고 싶은 확장은 §발행 훅의 `refund_failed` 를 구독합니다.
<!-- @intent END -->
