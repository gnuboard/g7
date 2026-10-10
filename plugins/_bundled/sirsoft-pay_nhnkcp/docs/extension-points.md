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
| `sirsoft-pay_nhnkcp.payment.after_cancel` | action | KCP 결제 취소 완료 후 | `src/Services/NhnKcpApiService.php:289` |
| `sirsoft-pay_nhnkcp.payment.after_confirm` | action | KCP 결제 승인 확인 완료 후 | `src/Controllers/PaymentCallbackController.php:330` |
| `sirsoft-pay_nhnkcp.payment.before_cancel` | action | KCP 결제 취소 API 호출 전 (본인인증 등 확장 지점) | `src/Services/NhnKcpApiService.php:268` |
| `sirsoft-pay_nhnkcp.payment.before_confirm` | action | KCP 결제 승인 확인 전 | `src/Controllers/PaymentCallbackController.php:324` |
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
| `core.plugin_settings.update_validation_rules` | filter | `ValidateRedirectUrlSettingsListener` | `rules` | 10 |
| `core.plugins.after_update` | action | `RestoreLayoutExtensionsAfterUpdateListener` | `restoreCurrentExtensionsAfterUpdate` | 20 |
| `sirsoft-ecommerce.claim_payment.approve` | filter | `ClaimPaymentApproveListener` | `processApprove` | 10 |
| `sirsoft-ecommerce.claim_payment.refund` | filter | `ClaimPaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.claim_payment.void_approval` | filter | `ClaimPaymentApproveListener` | `voidApproval` | 10 |
| `sirsoft-ecommerce.order.filter_payment_method_display_label` | filter | `ProvidePaymentMethodDisplayLabelListener` | `provideDisplayLabel` | 10 |
| `sirsoft-ecommerce.payment.close_vbank` | filter | `PaymentRefundListener` | `closeVbank` | 10 |
| `sirsoft-ecommerce.payment.get_client_config` | filter | `RegisterPgProviderListener` | `getClientConfig` | 10 |
| `sirsoft-ecommerce.payment.refund` | filter | `CancelActivityLogListener` | `logCancelConfirmed` | 20 |
| `sirsoft-ecommerce.payment.refund` | filter | `PaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | `RegisterPgProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.payment.supports_partial_cancel` | filter | `PaymentRefundListener` | `supportsPartialCancel` | 10 |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | `RegisterEasyPayMethodsListener` | `injectEasyPayMethods` | 30 |
| `sirsoft-gdpr.necessary_storage.filter_provided_keys` | filter | `ProvideNecessaryStorageKeysListener` | `provide` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`RegisterPgProviderListener` 가 우선순위 10 으로 두 훅(`get_client_config`/
`registered_pg_providers`)을 모두 구독하는 이유는 "이 PG 가 존재한다는 사실"과 "체크아웃
화면이 필요로 하는 클라이언트 설정값"이 같은 리스너의 책임이기 때문입니다 — 등록과 설정
노출이 다른 리스너로 갈라지면 한쪽만 갱신되는 사각이 생깁니다.

`ProvidePaymentMethodDisplayLabelListener` 는 관리자 주문 목록의 결제수단 이름을 채웁니다. 이커머스는
PG 를 모르므로 간편결제(PAYCO·네이버페이 등)로 결제한 주문도 기본 결제수단 이름만 압니다. 이 리스너가
필터 훅 `sirsoft-ecommerce.order.filter_payment_method_display_label` 로 **자기 결제(`pg_provider`)의
간편결제 주문에만** 「원 수단 (기본 수단)」 이름을 채우고, 자기 결제가 아니거나 앞선 구독자가 채운 값은
그대로 돌려줍니다 — 한 목록에 여러 PG 의 주문이 섞이기 때문입니다. `PaymentRefundListener`(10)
가 `CancelActivityLogListener`(20)보다 먼저 실행되도록 우선순위를 명시한 것은 실제 취소가
성공한 뒤에야 활동 로그를 남기기 위함입니다 — 순서가 뒤바뀌면 "로그는 있는데 취소는 실패"가
생깁니다.

`sirsoft-ecommerce.claim_payment.approve` / `.refund` 두 필터는 클레임 추가결제(`CP` 채번)의
승인·환급을 이 플러그인이 맡는 접합면입니다. 콜백 컨트롤러는 결제번호가 `CP` 로 시작하면 주문
경로를 타지 않고 모듈의 `approvePgPayment()` 에 위임하며, 그 안에서 이 필터가 발화합니다 —
서버 콜백 경로와 API 승인 경로가 같은 구현을 탑니다. 인자·반환 키·멱등 규칙은 쇼핑몰 모듈
`modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` 가 단일 출처입니다.

승인 리스너는 결제창 금액(`good_mny`)을 승인 호출 **전에** 청구액과 대조하고(다르면 PG 호출 없이
`AMOUNT_MISMATCH`), 승인 요청에 청구액을 `ordr_mony` 로 실어 KCP 가 결제창 금액과 대조하게 합니다.
승인 뒤에는 KCP 응답 금액(`amount`)만 근거로 삼아, 청구액과 다르거나 응답에 금액이 없으면 승인분을
직접 취소한 뒤 실패로 돌려줍니다 — 브라우저가 보낸 `good_mny` 는 승인액의 대체값으로 쓰지 않습니다.
거래번호도 응답의 `tno` 만 씁니다(없으면 `MISSING_TNO` 로 거절). 모듈도 승인 뒤 금액을 한 번 더 대조하며, 모듈이 따로 거절한 건은
`sirsoft-ecommerce.claim_payment.void_approval` 필터(같은 리스너의 `voidApproval()`)로 되돌립니다 —
콜백 컨트롤러는 보상 클로저를 따로 넘기지 않으므로 서버 콜백 경로와 API 승인 경로의 판정·보상이
한 구현입니다.

콜백 컨트롤러의 `CP` 분기는 결제번호 단위 캐시 락(`sirsoft-pay_nhnkcp:callback:{문맥}:{sha1(결제번호)}`)으로
직렬화됩니다. 락을 기다리다 시간을 넘기면 오류(500)가 아니라 결제 건 상태를 읽어 신청서 상세로
돌려보냅니다 — 이미 결제완료면 `?claim_payment=success`, 아니면 `?claim_payment=failed&error=callback_locked`.

주입하는 결제수단 항목은 이커머스의 결제수단 **능력 키**를 함께 선언합니다. 그중
`auto_approve_refund` 는 "사람의 개입 없이 환불이 집행되는가" 이고, 이 플러그인이 등록하는 것은
모두 간편결제라 NHN KCP 결제취소 API 가 그 자리에서 환불을 끝냅니다 — 전부 `true` 입니다.
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
| `ClaimPaymentApproveListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/ClaimPaymentApproveListener.php` |
| `ClaimPaymentRefundListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ClaimPaymentRefundListener.php` |
| `EnsureAdminOrderDetailPaymentQueryLayoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/EnsureAdminOrderDetailPaymentQueryLayoutListener.php` |
| `PaymentRefundListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PaymentRefundListener.php` |
| `ProvideNecessaryStorageKeysListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ProvideNecessaryStorageKeysListener.php` |
| `ProvidePaymentMethodDisplayLabelListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ProvidePaymentMethodDisplayLabelListener.php` |
| `RegisterEasyPayMethodsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterEasyPayMethodsListener.php` |
| `RegisterPgProviderListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/RegisterPgProviderListener.php` |
| `RestoreLayoutExtensionsAfterUpdateListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RestoreLayoutExtensionsAfterUpdateListener.php` |
| `ValidateRedirectUrlSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateRedirectUrlSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`RestoreLayoutExtensionsAfterUpdateListener`가 존재하는 이유는 `plugin:update`가 레이아웃
확장 조각(§레이아웃 확장)의 활성/비활성 상태를 초기화할 수 있어서입니다 — 운영자가 특정
화면(예: 주문 목록 테스트 결제 배지)을 꺼둔 상태로 플러그인을 업데이트해도 그 선택이 사라지지 않도록
업데이트 직후 복원합니다. 대상 화면에 덧붙이는 조각(overlay)뿐 아니라 확장점에 붙는 조각도 되살리며,
확장점 조각은 그 확장점을 가진 레이아웃이 있는 템플릿에만 등록합니다. 리스너 전부가
`HookListenerInterface`를 구현하는 것은 auto-discovery 대상이라는 뜻이 아니라 이 저장소의 전 리스너
공통 계약입니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin_order_list_payment_method_badge.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `admin_order_list_payment_method_badges` |
| `resources/extensions/admin_order_payment_query.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_ecommerce_order_detail` |
| `resources/extensions/checkout_easy_pay.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `shop/checkout` |
| `resources/extensions/user_order_complete_receipt.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `shop/order_complete` |
| `resources/extensions/user_order_show.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `mypage_order_payment_provider_slot` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
5개 조각은 각각 독립적인 화면 관심사입니다 — 관리자 주문 목록의 테스트 결제 배지, 관리자 주문
상세의 거래조회 UI, 체크아웃의 간편결제 버튼, 주문완료/마이페이지의 영수증 버튼이 서로
다른 화면·다른 컴포넌트 트리에 주입되므로 하나의 조각으로 합치지 않았습니다. 새 KCP 기능이
필요로 하는 화면이 이 5개 중 하나에 해당하면 새 조각을 만들지 말고 기존 조각을 확장합니다.

조각의 **고유 키는 파일 이름**입니다(`fragment_key` — 확장자를 뗀 기본 이름). 같은 확장이 같은 대상
화면에 조각을 둘 보낼 수 있어 DB 행이 대상만으로 갈리지 않기 때문입니다. 설치 경로와
`RestoreLayoutExtensionsAfterUpdateListener` 가 **같은 키**를 써야 하므로, 리스너는
`LayoutFragmentSourceHelper::readDirectoryKeyed()` 로 파일에서 키를 얻어 그대로 넘깁니다. 키 없이
등록하면 대상 화면 이름이 키가 되어 두 경로가 갈리고, 그 뒤로는 같은 대상에 행이 둘 생겨 복원이
어느 행인지 가리지 못한 채 조용히 멈춥니다.
`user_order_show.g7.html` 은 대상 레이아웃 이름이 아니라 **공용 확장 슬롯**
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

**화면에 붙는 표시는 전부 이 조각들이 맡습니다 (#639).** 예전에는 주입 스크립트가 주소 정규식으로 화면을
판정하고 DOM 을 폴링해 남의 화면 글자를 고치거나 버튼을 손으로 만들어 붙였습니다(관리자 주문 상세 결제수단 칸,
관리자 결제수단 설정의 브랜드 배지·애플페이 안내, 내 주문 상세·주문 완료의 영수증·모의입금). 그 스크립트는
`window.fetch`·`history.pushState` 를 덮어쓰고 로그인 토큰을 저장소에서 직접 읽었으므로 모두 지웠습니다.
대신 관리자 주문 상세의 간편결제 원 수단은 `admin_order_payment_query` 의 KCP 패널이, 테스트 모드 모의입금 도구는
`user_order_show` 가 자기 데이터 소스로 그립니다. 관리자 결제수단 설정의 브랜드 배지는 결제수단 카탈로그의
`brand_mark`(→ `_cached_brand_mark`, 그리는 일은 이커머스 관리자 결제수단 목록 몫), 애플페이 iOS 전용 안내는 카탈로그 설명(`payment_methods.applepay.description`),
비 iOS 기기에서 애플페이를 감추는 일은 카탈로그의 `requires_ios` 가 맡습니다.

**템플릿 요구 사항 — 주문 완료 화면의 `order_complete_payment_summary`.** `user_order_complete_receipt` 는
`shop/order_complete` 의 이 id 아래에 붙습니다. 대상 id 가 없는 템플릿에서는 조각이 오류 없이 빠집니다 —
그 화면에서는 매출전표 보기가 뜨지 않습니다.
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
가상계좌 계좌 기한은 주문 입금 기한(이커머스 「주문 자동취소」 기한)을 결제창에 넘겨 정한다(§settings.md
`vbank_expire_days` 는 기한이 없는 주문의 예비값). 만료 처리는 이 플러그인이 크론으로 직접 만료
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
