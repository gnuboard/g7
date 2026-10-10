# 나이스페이먼츠 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 5종 / 호출 지점 5곳. 이 중 1종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-pay_nicepayments.payment.after_authorize` | action | 나이스페이먼츠 서버 승인 완료 후 | `src/Controllers/PaymentCallbackController.php:325` |
| `sirsoft-pay_nicepayments.payment.after_cancel` | action | 나이스페이먼츠 결제 취소 완료 후 | `src/Services/NicePaymentsApiService.php:347` |
| `sirsoft-pay_nicepayments.payment.before_authorize` | action | 나이스페이먼츠 서버 승인 API 호출 전 | `src/Controllers/PaymentCallbackController.php:320` |
| `sirsoft-pay_nicepayments.payment.before_cancel` | action | 나이스페이먼츠 결제 취소 API 호출 전 (본인인증 등 확장 지점) | `src/Services/NicePaymentsApiService.php:324` |
| `sirsoft-pay_nicepayments.payment.refund_failed` | action | — | `src/Listeners/PaymentRefundListener.php:187` |
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
| `core.plugin_settings.update_validation_rules` | filter | `ValidateRedirectUrlSettingsListener` | `rules` | 10 |
| `core.plugins.after_update` | action | `RestoreLayoutExtensionsAfterUpdateListener` | `restoreCurrentExtensionsAfterUpdate` | 20 |
| `sirsoft-ecommerce.claim_payment.approve` | filter | `ClaimPaymentApproveListener` | `processApprove` | 10 |
| `sirsoft-ecommerce.claim_payment.refund` | filter | `ClaimPaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.claim_payment.void_approval` | filter | `ClaimPaymentApproveListener` | `voidApproval` | 10 |
| `sirsoft-ecommerce.order.filter_payment_method_display_label` | filter | `ProvidePaymentMethodDisplayLabelListener` | `provideDisplayLabel` | 10 |
| `sirsoft-ecommerce.payment.close_vbank` | filter | `VbankCloseListener` | `closeVbank` | 10 |
| `sirsoft-ecommerce.payment.get_client_config` | filter | `RegisterPgProviderListener` | `getClientConfig` | 10 |
| `sirsoft-ecommerce.payment.refund` | filter | `CancelActivityLogListener` | `logCancelConfirmed` | 20 |
| `sirsoft-ecommerce.payment.refund` | filter | `PaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | `RegisterPgProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.payment.supports_partial_cancel` | filter | `PaymentRefundListener` | `supportsPartialCancel` | 10 |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | `RegisterEasyPayMethodsListener` | `injectEasyPayMethods` | 40 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`PaymentRefundListener`(10)가 `CancelActivityLogListener`(20)보다 먼저 실행되도록 우선순위를
명시한 것은 실제 취소가 성공한 뒤에야 활동 로그를 남기기 위함입니다 — 순서가 뒤바뀌면
"로그는 있는데 취소는 실패"가 생깁니다. `RegisterEasyPayMethodsListener`가 8종의 간편결제
방식을 하나의 필터 훅에서 한 번에 주입하는 이유는 §settings.md 의 설정 스키마와 대칭을
맞추기 위함입니다 — 설정 키가 8개인데 훅 등록이 여러 곳으로 흩어지면 한쪽만 갱신되는
사각이 생깁니다.

`sirsoft-ecommerce.claim_payment.approve` / `.refund` 두 필터는 클레임 추가결제(`CP` 채번)의
승인·환급을 이 플러그인이 맡는 접합면입니다. 콜백 컨트롤러는 결제번호가 `CP` 로 시작하면 주문
경로를 타지 않고 모듈의 `approvePgPayment()` 에 위임하며, 그 안에서 이 필터가 발화합니다 —
서버 콜백 경로와 API 승인 경로가 같은 구현을 탑니다. 인자·반환 키·멱등 규칙은 쇼핑몰 모듈
`modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` 가 단일 출처입니다.

승인 리스너는 서명 금액(`Amt`)을 승인 호출 **전에** 청구액과 대조하고(다르면 PG 호출 없이
`AMOUNT_MISMATCH`), 콜백·응답의 주문번호(`Moid`)가 이 결제 건이 아니면 거절하며, 응답 금액이 서명
금액과 다르거나 응답에 승인 금액이 없으면(숫자가 아닌 값 포함) 망취소한 뒤 실패로 돌려줍니다. 모듈에 넘기는
`paid_amount` 는 브라우저 콜백 금액이 아니라 승인 응답의 금액입니다. 모듈도 승인 뒤 금액을 한 번 더 대조하며, 모듈이 따로 거절한 건은
`sirsoft-ecommerce.claim_payment.void_approval` 필터(같은 리스너의 `voidApproval()`)로 되돌립니다 —
콜백 컨트롤러는 보상 클로저를 따로 넘기지 않으므로 서버 콜백 경로와 API 승인 경로의 판정·보상이
한 구현입니다.

콜백 컨트롤러의 `CP` 분기는 결제번호 단위 캐시 락(`sirsoft-pay_nicepayments:callback:{문맥}:{sha1(결제번호)}`)으로
직렬화됩니다. 락을 기다리다 시간을 넘기면 오류(500)가 아니라 결제 건 상태를 읽어 신청서 상세로
돌려보냅니다 — 이미 결제완료면 `?claim_payment=success`, 아니면 `?claim_payment=failed&error=callback_locked`.

주입하는 결제수단 항목은 이커머스의 결제수단 **능력 키**를 함께 선언합니다. 그중
`auto_approve_refund` 는 "사람의 개입 없이 환불이 집행되는가" 이고, 이 플러그인이 등록하는 것은
모두 간편결제라 나이스페이먼츠 결제취소 API 가 그 자리에서 환불을 끝냅니다 — 전부 `true` 입니다.
선언을 빠뜨리면(코어 기본값 `false`) 그 수단의 환불이 전부 운영자 승인 대기로 쌓입니다. 계약은
이커머스 `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` "결제수단 능력 선언" 절이 갖습니다.
<!-- @intent END -->

## 활동 로그 훅

> 이 확장이 코어 활동 로그(`activity_logs`)에 기록을 남기기 위해 구독하는 훅 1개입니다.
> 위 「구독 훅」 절이 이 확장의 구독 전량을 싣고, 이 절은 그중 **기록을 남기는 것**만 추립니다.
> 코어 `docs/backend/activity-log-hooks.md` 에는 총계와 이 문서로의 링크만 남습니다(#601).

> 새 항목을 추가하면 코어 `lang/{ko,en}/activity_log.php` 의 action 라벨과 description 본문,
> 그리고 번들 일본어 팩까지 함께 정의해야 합니다 — **플러그인 lang 파일에 넣으면 action 라벨이
> 해석되지 않습니다.** (description 본문은 이 확장의 `lang/{ko,en}/activity_log.php` 소유입니다.)

### 결제 취소 훅 (CancelActivityLogListener)

**파일**: `plugins/_bundled/sirsoft-pay_nicepayments/src/Listeners/CancelActivityLogListener.php`
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
| `ClaimPaymentApproveListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/ClaimPaymentApproveListener.php` |
| `ClaimPaymentRefundListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ClaimPaymentRefundListener.php` |
| `EnsureAdminOrderDetailPaymentQueryLayoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/EnsureAdminOrderDetailPaymentQueryLayoutListener.php` |
| `PaymentRefundListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/PaymentRefundListener.php` |
| `ProvidePaymentMethodDisplayLabelListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ProvidePaymentMethodDisplayLabelListener.php` |
| `RegisterEasyPayMethodsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterEasyPayMethodsListener.php` |
| `RegisterPgProviderListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/RegisterPgProviderListener.php` |
| `RestoreLayoutExtensionsAfterUpdateListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RestoreLayoutExtensionsAfterUpdateListener.php` |
| `ValidateRedirectUrlSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateRedirectUrlSettingsListener.php` |
| `VbankCloseListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/VbankCloseListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`RestoreLayoutExtensionsAfterUpdateListener`는 `plugin:update`가 레이아웃 확장 조각(§레이아웃
확장)의 활성/비활성 상태를 초기화할 수 있어서 존재합니다 — 운영자가 특정 화면을 꺼둔 상태로
업데이트해도 그 선택이 사라지지 않도록 복원합니다. 대상 화면에 덧붙이는 조각(overlay)뿐 아니라
확장점에 붙는 조각(주문 목록 테스트 결제 배지)도 되살리며, 확장점 조각은 그 확장점을 가진 레이아웃이
있는 템플릿에만 등록합니다. 리스너 전부가 `HookListenerInterface`를 구현하는 것은 이 저장소의 전
리스너 공통 계약입니다.

`ProvidePaymentMethodDisplayLabelListener` 는 관리자 주문 목록의 결제수단 이름을 채웁니다. 이커머스는
PG 를 모르므로 간편결제(PAYCO·네이버페이 등)로 결제한 주문도 기본 결제수단 이름만 압니다. 이 리스너가
필터 훅 `sirsoft-ecommerce.order.filter_payment_method_display_label` 로 **자기 결제(`pg_provider`)의
간편결제 주문에만** 「원 수단 (기본 수단)」 이름을 채우고, 자기 결제가 아니거나 앞선 구독자가 채운 값은
그대로 돌려줍니다 — 한 목록에 여러 PG 의 주문이 섞이기 때문입니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin_order_list_payment_method_badge.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `admin_order_list_payment_method_badges` |
| `resources/extensions/admin_order_payment_query.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_ecommerce_order_detail` |
| `resources/extensions/user_mypage_order_receipt.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `mypage_order_payment_provider_slot` |
| `resources/extensions/user_order_complete_receipt.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `shop/order_complete` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
5개 조각은 각각 독립적인 화면 관심사입니다 — 관리자 주문 목록의 테스트 결제 배지, 관리자 주문
상세의 거래조회 UI, 체크아웃의 간편결제 버튼, 마이페이지·주문완료의 영수증 버튼이 서로
다른 화면에 주입되므로 하나로 합치지 않았습니다. `user_mypage_order_receipt.g7.html`과
`user_order_complete_receipt.g7.html`이 별도 파일인 것은 두 화면의 컴포넌트 트리와 데이터
소스가 다르기 때문입니다(§AGENTS.md "설계 원칙" — 상태는 이커머스 소유이므로 화면마다
필요한 조회 방식이 다를 수 있습니다).

조각의 **고유 키는 파일 이름**입니다(`fragment_key` — 확장자를 뗀 기본 이름). 같은 확장이 같은 대상
화면에 조각을 둘 보낼 수 있어 DB 행이 대상만으로 갈리지 않기 때문입니다. 설치 경로와
`RestoreLayoutExtensionsAfterUpdateListener` 가 **같은 키**를 써야 하므로, 리스너는
`LayoutFragmentSourceHelper::readDirectoryKeyed()` 로 파일에서 키를 얻어 그대로 넘깁니다. 키 없이
등록하면 대상 화면 이름이 키가 되어 두 경로가 갈리고, 그 뒤로는 같은 대상에 행이 둘 생겨 복원이
어느 행인지 가리지 못한 채 조용히 멈춥니다.
`user_mypage_order_receipt.g7.html` 은 대상 레이아웃 이름이 아니라 **공용 확장 슬롯**
(`mypage_order_payment_provider_slot`)을 겨냥합니다. 이 슬롯이 사는 결제 정보 카드
(`partials/mypage/orders/_payment`)는 주문 상세 말고도 클레임 신청서 상세가 그대로 재사용하기
때문입니다. `target_layout`(`mypage/orders/show`)으로 겨냥하면 그 한 화면에서만 뜨고, 나머지
화면은 카드는 나오는데 이 블록만 빠진 채 열립니다 — 오류도 경고도 남지 않아 알아채기 어렵습니다.
슬롯은 partial 을 따라가므로 그 카드가 새 화면에 마운트돼도 조각이 함께 갑니다.

**템플릿 요구 사항 — `mypage_order_payment_provider_slot` 슬롯이 필수입니다.** 이 조각은 예전처럼 대상
화면 이름(`target_layout: mypage/orders/show`)으로 붙지 않고 이 슬롯에만 붙습니다. 그래서 사용자 템플릿의
결제 정보 카드에 이 확장점이 없으면(자체 제작 템플릿, 슬롯 도입 전에 복사한 템플릿) 주문 상세·클레임
신청서 상세 어디에도 이 블록이 뜨지 않으며, 오류도 경고도 남지 않습니다. 사용자 템플릿을 만들거나 고칠 때는
결제 정보 카드 partial 에 `<extension-point name="mypage_order_payment_provider_slot">` 을 둡니다 — 기본
사용자 템플릿(sirsoft-basic)은 `partials/mypage/orders/_payment` 에 두고 있습니다.
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
