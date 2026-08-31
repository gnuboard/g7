# KG 이니시스 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 6종 / 호출 지점 6곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-pay_kginicis.payment.after_authorize` | action | KG 이니시스 서버 승인 완료 후 | `src/Controllers/PaymentCallbackController.php:247` |
| `sirsoft-pay_kginicis.payment.after_cancel` | action | KG 이니시스 결제 취소 완료 후 | `src/Services/KgInicisApiService.php:780` |
| `sirsoft-pay_kginicis.payment.after_cbt_refund` | action | KG 이니시스 일본 CBT 결제 취소 완료 후 | `src/Services/KgInicisApiService.php:592` |
| `sirsoft-pay_kginicis.payment.before_authorize` | action | KG 이니시스 서버 승인 API 호출 전 | `src/Controllers/PaymentCallbackController.php:243` |
| `sirsoft-pay_kginicis.payment.before_cancel` | action | KG 이니시스 결제 취소 API 호출 전 (본인인증 등 확장 지점) | `src/Services/KgInicisApiService.php:758` |
| `sirsoft-pay_kginicis.payment.before_cbt_refund` | action | KG 이니시스 일본 CBT 결제 취소 API 호출 전 | `src/Services/KgInicisApiService.php:564` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
일반 결제(승인/취소)와 CBT(일본)가 각각 별도 `before/after_cbt_refund` 훅 쌍을 갖는 이유는
두 흐름이 서로 다른 API·통화·해시 체계를 쓰기 때문입니다 — 하나로 합치면 구독자가 매번
"이게 CBT 인지 일반인지"를 페이로드로 분기해야 합니다. `before_cancel`은 발행 위치 설명에
"본인인증 등 확장 지점"이라고 명시돼 있습니다 — 고액 취소에 관리자 재인증을 강제하고 싶은
확장은 이 훅에서 예외를 던져 PG 호출 자체를 막을 수 있습니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.layout_extension.after_apply` | filter | `AdjustEcommercePaymentMethodsLayoutListener` | `adjustPaymentMethodsLayout` | 20 |
| `core.layout_extension.after_apply` | filter | `EnsureAdminOrderDetailPaymentQueryLayoutListener` | `ensurePaymentQueryLayout` | 66 |
| `core.layout_extension.after_apply` | filter | `EnsureAdminOrderDetailTestModeLayoutListener` | `ensureTestModeLayout` | 65 |
| `core.layout_extension.after_apply` | filter | `EnsureAdminOrderListTestBadgeLayoutListener` | `ensureTestBadgeLayout` | 60 |
| `core.plugin_settings.before_save` | action (미선언) | `ValidateCbtSettingsListener` | `validateBeforeSave` | 10 |
| `core.plugins.updated` | action | `RestoreLayoutExtensionsAfterUpdateListener` | `restoreCurrentExtensionsAfterUpdate` | 20 |
| `sirsoft-ecommerce.cash_receipt.cancel` | filter | `RegisterCashReceiptProviderListener` | `cancel` | 10 |
| `sirsoft-ecommerce.cash_receipt.issue` | filter | `RegisterCashReceiptProviderListener` | `issue` | 10 |
| `sirsoft-ecommerce.cash_receipt.registered_providers` | filter | `RegisterCashReceiptProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.payment.get_client_config` | filter | `RegisterPgProviderListener` | `getClientConfig` | 10 |
| `sirsoft-ecommerce.payment.refund` | filter | `CancelActivityLogListener` | `logCancelConfirmed` | 20 |
| `sirsoft-ecommerce.payment.refund` | filter | `PaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | `RegisterPgProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | `RegisterEasyPayMethodsListener` | `injectEasyPayMethods` | 20 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`core.layout_extension.after_apply`를 구독하는 3개 리스너(`Ensure*LayoutListener`)가 서로
다른 우선순위(60/65/66)를 갖는 것은 우연이 아닙니다 — 관리자 주문 목록/상세 레이아웃에 여러
확장이 조각을 주입할 수 있어, 이 플러그인의 조각들이 서로 겹치지 않는 순서로 배치되도록
번호를 나눠 씁니다. `sirsoft-ecommerce.payment.refund`를 구독하는 두 리스너의 우선순위
(`PaymentRefundListener`=10, `CancelActivityLogListener`=20)는 §AGENTS.md 핵심 흐름에서
설명한 대로 "취소 성공 후에만 로그 기록"을 강제하기 위한 순서입니다 — 뒤바뀌면 실패한 취소도
로그에 성공처럼 남을 수 있습니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `AdjustEcommercePaymentMethodsLayoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/AdjustEcommercePaymentMethodsLayoutListener.php` |
| `CancelActivityLogListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/CancelActivityLogListener.php` |
| `EnsureAdminOrderDetailPaymentQueryLayoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/EnsureAdminOrderDetailPaymentQueryLayoutListener.php` |
| `EnsureAdminOrderDetailTestModeLayoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/EnsureAdminOrderDetailTestModeLayoutListener.php` |
| `EnsureAdminOrderListTestBadgeLayoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/EnsureAdminOrderListTestBadgeLayoutListener.php` |
| `PaymentRefundListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PaymentRefundListener.php` |
| `RegisterCashReceiptProviderListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/RegisterCashReceiptProviderListener.php` |
| `RegisterEasyPayMethodsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterEasyPayMethodsListener.php` |
| `RegisterPgProviderListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/RegisterPgProviderListener.php` |
| `RestoreLayoutExtensionsAfterUpdateListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RestoreLayoutExtensionsAfterUpdateListener.php` |
| `ValidateCbtSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateCbtSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`RegisterPgProviderListener`·`RegisterCashReceiptProviderListener`·`RegisterEasyPayMethodsListener`
가 이 플러그인의 "등록" 축입니다 — 이 셋이 없으면 플러그인을 활성화해도 이커머스 화면에서
KG 이니시스가 보이지 않습니다. 나머지는 전부 부가 UI(`Ensure*LayoutListener`)나 정합성
검증(`ValidateCbtSettingsListener`)입니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin_order_list_test_badge.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/admin_order_payment_query.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/checkout_payment_error.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user_order_show.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
`checkout_payment_error.json`은 체크아웃 화면에 KG 이니시스 특유의 오류 메시지(예: 계약되지
않은 결제수단 선택 시 안내)를 끼워 넣고, `user_order_show.json`은 주문 상세에 영수증 버튼을
추가합니다. 관리자 쪽 두 조각(`admin_order_list_test_badge`/`admin_order_payment_query`)은
"이 주문이 테스트 모드로 결제됐는가"와 "PG 거래 상태를 다시 조회"를 관리자 화면에서 바로
확인하게 하는 운영 편의 기능입니다 — 실제 결제 로직과는 분리돼 있어 이 조각만 비활성화해도
결제 자체는 영향받지 않습니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `InicisNotifyIpWhitelist` | `web.plugins.sirsoft-pay_kginicis.payment.cbt.cvs-notify`, `web.plugins.sirsoft-pay_kginicis.payment.vbank-notify`, `web.plugins.sirsoft-pay_kginicis.payment.mobile.vbank-notify` | - |
<!-- @generated:middleware END -->

<!-- @intent START -->
가상계좌/CBT 편의점 입금통보 3개 라우트에만 부착되고 결제 승인/취소 콜백 라우트에는
부착되지 않습니다 — 입금통보는 KG 이니시스 서버가 발신자 인증 수단 없이 단순 POST 로
호출하므로 IP 로 걸러야 하지만, 승인/취소 콜백은 `authUrl`/`authToken` 자체가 위조 방지
수단(§AGENTS.md 금지 패턴)이라 별도 IP 제한이 없어도 안전합니다. `local`/`testing` 환경에서는
이 제한이 우회됩니다 — 개발 중에는 실제 KG 이니시스 IP 대역에서 요청이 오지 않기 때문입니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
결제 진행 상황(승인 대기 등)을 실시간으로 밀어줄 필요가 없습니다 — PC/모바일 결제는 결제창이
닫히고 콜백이 오는 시점에 화면이 이미 그 페이지에 있고, 가상계좌 입금통보는 방문자가 화면을
보고 있지 않은 시점에 도착하므로 알림(§알림 정의)이나 다음 방문 시 조회가 더 적절합니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
가상계좌 만료 처리나 CBT 정산 대사(reconciliation) 같은 주기적 점검이 있을 법하지만
(`CbtReconciliationRepository` 참고), 현재는 관리자가 필요할 때 수동으로 조회·확인하는
구조입니다 — 자동 스케줄로 상태를 바꾸면 결제 상태 변경 시점을 운영자가 놓칠 수 있습니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
결제 완료/실패 알림은 이 플러그인이 아니라 `sirsoft-ecommerce`의 주문 상태 알림 정의가
담당합니다 — PG 가 여러 개일 수 있는데 PG 마다 "결제 완료 알림"을 각자 만들면 같은 이벤트에
대해 서로 다른 알림 정의가 난립합니다. 이 플러그인은 "그 결제가 KG 이니시스를 통했다"는
사실만 이커머스에 전달하고, 알림 발송은 이커머스가 단일하게 책임집니다.
<!-- @intent END -->
