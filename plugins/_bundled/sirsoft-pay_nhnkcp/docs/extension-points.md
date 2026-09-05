# NHN KCP — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 8종 / 호출 지점 8곳. 이 중 4종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-pay_nhnkcp.escrow.delivery_started` | action | — | `src/Controllers/EscrowCommonNotifyController.php:101` |
| `sirsoft-pay_nhnkcp.escrow.denial_confirmed` | action | — | `src/Controllers/EscrowCommonNotifyController.php:98` |
| `sirsoft-pay_nhnkcp.escrow.purchase_cancelled` | action | — | `src/Controllers/EscrowCommonNotifyController.php:97` |
| `sirsoft-pay_nhnkcp.escrow.purchase_confirmed` | action | — | `src/Controllers/EscrowCommonNotifyController.php:96` |
| `sirsoft-pay_nhnkcp.payment.after_cancel` | action | KCP 결제 취소 완료 후 | `src/Services/NhnKcpApiService.php:250` |
| `sirsoft-pay_nhnkcp.payment.after_confirm` | action | KCP 결제 승인 확인 완료 후 | `src/Controllers/PaymentCallbackController.php:280` |
| `sirsoft-pay_nhnkcp.payment.before_cancel` | action | KCP 결제 취소 API 호출 전 (본인인증 등 확장 지점) | `src/Services/NhnKcpApiService.php:229` |
| `sirsoft-pay_nhnkcp.payment.before_confirm` | action | KCP 결제 승인 확인 전 | `src/Controllers/PaymentCallbackController.php:275` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`escrow.*` 4종에 `유형`/`설명`이 비어 있는 것은 실수가 아니라 선언 누락입니다 — 소스에서
자동 감지된 훅이라 `getHooks()`에 등록하면 이름 그대로도 의미가 분명해 설명을 생략했습니다.
`before_confirm`/`before_cancel`은 KCP API 호출 **전** 개입 지점이라 여기서 예외를 던지면
실제 KCP 호출 자체가 일어나지 않습니다(예: 고액 결제에 추가 인증을 요구하고 싶은 확장이
`before_cancel`에서 조건 미충족 시 예외). `after_*`는 응답을 받은 뒤 부가효과(로그, 알림)를
붙이는 자리입니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.layout_extension.after_apply` | filter | `AdjustEcommercePaymentMethodsLayoutListener` | `adjustPaymentMethodsLayout` | 30 |
| `core.layout_extension.after_apply` | filter | `EnsureAdminOrderDetailPaymentQueryLayoutListener` | `ensurePaymentQueryLayout` | 66 |
| `core.layout_extension.after_apply` | filter | `EnsureAdminOrderListTestBadgeLayoutListener` | `ensureTestBadgeLayout` | 60 |
| `core.plugins.updated` | action | `RestoreLayoutExtensionsAfterUpdateListener` | `restoreCurrentExtensionsAfterUpdate` | 20 |
| `sirsoft-ecommerce.payment.get_client_config` | filter | `RegisterPgProviderListener` | `getClientConfig` | 10 |
| `sirsoft-ecommerce.payment.refund` | filter | `CancelActivityLogListener` | `logCancelConfirmed` | 20 |
| `sirsoft-ecommerce.payment.refund` | filter | `PaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | `RegisterPgProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | `RegisterEasyPayMethodsListener` | `injectEasyPayMethods` | 30 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`RegisterPgProviderListener` 가 우선순위 10 으로 두 훅(`get_client_config`/
`registered_pg_providers`)을 모두 구독하는 이유는 "이 PG 가 존재한다는 사실"과 "체크아웃
화면이 필요로 하는 클라이언트 설정값"이 같은 리스너의 책임이기 때문입니다 — 등록과 설정
노출이 다른 리스너로 갈라지면 한쪽만 갱신되는 사각이 생깁니다. `PaymentRefundListener`(10)
가 `CancelActivityLogListener`(20)보다 먼저 실행되도록 우선순위를 명시한 것은 실제 취소가
성공한 뒤에야 활동 로그를 남기기 위함입니다 — 순서가 뒤바뀌면 "로그는 있는데 취소는 실패"가
생깁니다.
<!-- @intent END -->

## 활동 로그 훅

> 이 확장이 코어 활동 로그(`activity_logs`)에 기록을 남기기 위해 구독하는 훅 1개입니다.
> 위 「구독 훅」 절이 이 확장의 구독 전량을 싣고, 이 절은 그중 **기록을 남기는 것**만 추립니다.
> 코어 `docs/backend/activity-log-hooks.md` 에는 총계와 이 문서로의 링크만 남습니다(#601).

> 새 항목을 추가하면 코어 `lang/{ko,en}/activity_log.php` 의 action 라벨과 description 본문,
> 그리고 번들 일본어 팩까지 함께 정의해야 합니다 — **플러그인 lang 파일에 넣으면 action 라벨이
> 해석되지 않습니다.** (description 본문은 이 확장의 `lang/{ko,en}/activity_log.php` 소유입니다.)

### 결제 취소 훅 (CancelActivityLogListener)

**파일**: `plugins/_bundled/sirsoft-pay_nhnkcp/src/Listeners/CancelActivityLogListener.php`
**총 1훅**

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.payment.refund` | `logCancelConfirmed` | `payment.cancel` | `ResolvesActivityLogType` 로 해석 | Order |

> 이 훅은 `filter` 이고 우선순위 **20** 입니다. 같은 훅을 구독하는 `PaymentRefundListener`(10)가
> 먼저 실행돼 실제 취소가 성공한 뒤에야 기록이 남습니다 — 순서가 뒤바뀌면 실패한 취소도
> 성공처럼 로그에 남습니다.

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
`RestoreLayoutExtensionsAfterUpdateListener`가 존재하는 이유는 `plugin:update`가 레이아웃
확장 조각(§레이아웃 확장)의 활성/비활성 상태를 초기화할 수 있어서입니다 — 운영자가 특정
화면(예: 테스트배지)을 꺼둔 상태로 플러그인을 업데이트해도 그 선택이 사라지지 않도록
업데이트 직후 복원합니다. 8개 리스너 전부가 `HookListenerInterface`를 구현하는 것은
auto-discovery 대상이라는 뜻이 아니라 이 저장소의 전 리스너 공통 계약입니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin_order_list_test_badge.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/admin_order_payment_query.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/checkout_easy_pay.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user_order_complete_receipt.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user_order_show.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
5개 조각은 각각 독립적인 화면 관심사입니다 — 관리자 주문 목록의 테스트배지, 관리자 주문
상세의 거래조회 UI, 체크아웃의 간편결제 버튼, 주문완료/마이페이지의 영수증 버튼이 서로
다른 화면·다른 컴포넌트 트리에 주입되므로 하나의 조각으로 합치지 않았습니다. 새 KCP 기능이
필요로 하는 화면이 이 5개 중 하나에 해당하면 새 조각을 만들지 말고 기존 조각을 확장합니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `RestrictKcpIp` | `web.plugins.sirsoft-pay_nhnkcp.payment.vbank-notify`, `web.plugins.sirsoft-pay_nhnkcp.payment.escrow-common-notify` | - |
<!-- @generated:middleware END -->

<!-- @intent START -->
결제 결과 Return URL(`/payment/callback`)에는 이 미들웨어가 붙지 않습니다 — 그 경로는
브라우저가 POST 하는 경로라 발신 IP 가 사용자마다 다르기 때문입니다. IP 화이트리스트가
의미 있는 것은 KCP 서버가 직접 호출하는 두 통보 경로(가상계좌 입금통보·에스크로 공통통보)
뿐입니다. 테스트 모드에서는 개발 편의와 KCP testadmin 모의입금을 위해 이 제한을 우회합니다
— 운영 모드로 전환할 때 이 우회가 함께 꺼지는지 확인해야 합니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
결제 승인·통보는 전부 동기 HTTP 요청/응답 안에서 끝나는 흐름이라 실시간 브로드캐스트가
필요한 지점이 없습니다 — 가상계좌 입금통보조차 KCP 서버의 POST 요청 하나로 완결됩니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
가상계좌 만료 처리(§settings.md `vbank_expire_days`)는 이 플러그인이 크론으로 직접 만료
스캔을 하지 않고, 만료 이후 도착하는 KCP 입금통보를 거부하는 방식으로 처리됩니다 — 별도
스케줄 작업이 필요 없습니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
결제 완료/실패 알림은 이커머스 모듈이 주문 상태 변화를 기준으로 발송하는 공용 알림에 이미
포함됩니다 — PG 마다 별도 알림 정의를 만들면 같은 이벤트(결제완료)에 대해 PG 수만큼 중복
알림 정의가 생깁니다.
<!-- @intent END -->
