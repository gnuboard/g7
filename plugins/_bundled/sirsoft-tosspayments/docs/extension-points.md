# 토스페이먼츠 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 4종 / 호출 지점 4곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-tosspayments.payment.after_cancel` | action | 토스페이먼츠 결제 취소 완료 후 | `src/Services/TossPaymentsApiService.php:102` |
| `sirsoft-tosspayments.payment.after_confirm` | action | 토스페이먼츠 결제 승인 완료 후 | `src/Controllers/PaymentCallbackController.php:98` |
| `sirsoft-tosspayments.payment.before_cancel` | action | 토스페이먼츠 결제 취소 API 호출 전 | `src/Services/TossPaymentsApiService.php:98` |
| `sirsoft-tosspayments.payment.before_confirm` | action | 토스페이먼츠 결제 승인 API 호출 전 | `src/Controllers/PaymentCallbackController.php:94` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`before_confirm`/`before_cancel`은 API 호출 **전** 개입 지점이라 예외를 던지면 실제 토스
호출이 일어나지 않습니다. `after_confirm`은 승인 확인 응답을 받은 뒤(§AGENTS.md "핵심 흐름")
발화하므로, 이 시점에는 아직 금액 대조가 끝나지 않았을 수 있습니다 — 구독하는 확장은
이커머스가 최종 완료 처리를 마쳤는지 별도로 확인해야 합니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.plugin_settings.before_save` | action (미선언) | `ValidateTossSettingsListener` | `validateBeforeSave` | 10 |
| `core.plugins.updated` | action | `RestoreLayoutExtensionsAfterUpdateListener` | `restoreCurrentExtensionsAfterUpdate` | 20 |
| `sirsoft-ecommerce.cash_receipt.cancel` | filter | `RegisterCashReceiptProviderListener` | `cancel` | 10 |
| `sirsoft-ecommerce.cash_receipt.issue` | filter | `RegisterCashReceiptProviderListener` | `issue` | 10 |
| `sirsoft-ecommerce.cash_receipt.registered_providers` | filter | `RegisterCashReceiptProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.payment.get_client_config` | filter | `RegisterPgProviderListener` | `getClientConfig` | 10 |
| `sirsoft-ecommerce.payment.refund` | filter | `PaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | `RegisterPgProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | `RegisterTossPaymentMethodsListener` | `injectTossMethods` | 20 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`core.plugin_settings.before_save`는 다른 PG 플러그인에는 없는 구독입니다 — 이 플러그인만
설정 저장 시점에 서버측 범위 검증(`vbank_valid_hours`, `use_escrow`)을 강제합니다
(§AGENTS.md "핵심 흐름"). `RegisterTossPaymentMethodsListener`가 `order_sheet_mode`가
꺼져 있으면 아무것도 주입하지 않는 것은 결제창형에서는 토스 결제수단 전부가 통합결제창
카드 하나로 처리되기 때문입니다 — 개별 버튼을 만들 필요가 없습니다. 3종의 현금영수증 훅
(`cash_receipt.*`)은 카드/계좌이체 발급, 취소, 프로바이더 등록을 각각 담당하며 모두
`RegisterCashReceiptProviderListener` 하나가 처리합니다 — PG 선택과 현금영수증 발급사
선택이 이커머스에서 독립적인 개념이라 별도 등록입니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `PaymentRefundListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PaymentRefundListener.php` |
| `RegisterCashReceiptProviderListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/RegisterCashReceiptProviderListener.php` |
| `RegisterPgProviderListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/RegisterPgProviderListener.php` |
| `RegisterTossPaymentMethodsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterTossPaymentMethodsListener.php` |
| `RestoreLayoutExtensionsAfterUpdateListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RestoreLayoutExtensionsAfterUpdateListener.php` |
| `ValidateTossSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateTossSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`ValidateTossSettingsListener`는 구독 훅이 `sirsoft-ecommerce.*`가 아니라
`core.plugin_settings.before_save`라는 점에서 이 표의 다른 5개 리스너와 성격이 다릅니다 —
결제 도메인이 아니라 코어 설정 저장 파이프라인에 개입합니다. `getSubscribedHooks()`에서
`sync: true`를 선언하는 것도 이 리스너뿐입니다(§AGENTS.md "금지 패턴").
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin_order_payment.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/checkout-payment-error.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user_order_show.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
`checkout-payment-error.json`은 다른 PG 플러그인에는 없는 조각입니다 — 승인 확인 실패
(`amount_mismatch`, 서명 오류 등)가 `?error=` 쿼리로 체크아웃에 되돌아왔을 때 그 오류를
사용자에게 보여주는 전용 UI입니다. 다른 PG는 오류 안내를 체크아웃 레이아웃이 이미 가진
공용 오류 처리에 맡기지만, 이 플러그인은 리다이렉트 기반 승인이라 오류 사유가 다양해
전용 조각을 둡니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
다른 PG 플러그인은 가상계좌 입금통보에 IP 화이트리스트 미들웨어를 부착하지만, 이
플러그인은 미들웨어가 없습니다 — 토스가 notify IP 목록을 제공하지 않아 IP 기반 검증
자체가 불가능하기 때문입니다. 대신 §핵심 흐름의 secret 대조(`WebhookController` 안의
애플리케이션 레벨 검증)가 그 역할을 대신합니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
결제 승인·웹훅은 전부 동기 HTTP 요청/응답 안에서 끝나는 흐름이라 실시간 브로드캐스트가
필요한 지점이 없습니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
가상계좌 만료는 이 플러그인이 크론으로 스캔하지 않고, 만료 이후 도착하는 토스 입금 웹훅을
거부하는 방식으로 처리됩니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
결제 완료/실패 알림은 이커머스 모듈이 주문 상태 변화를 기준으로 발송하는 공용 알림에 이미
포함됩니다 — PG 마다 별도 알림 정의를 만들면 같은 이벤트에 대해 PG 수만큼 중복 정의가
생깁니다.
<!-- @intent END -->
