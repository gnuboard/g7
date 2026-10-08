# KG 이니시스 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 6종 / 호출 지점 6곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-pay_kginicis.payment.after_authorize` | action | KG 이니시스 서버 승인 완료 후 | `src/Controllers/PaymentCallbackController.php:294` |
| `sirsoft-pay_kginicis.payment.after_cancel` | action | KG 이니시스 결제 취소 완료 후 | `src/Services/KgInicisApiService.php:863` |
| `sirsoft-pay_kginicis.payment.after_cbt_refund` | action | KG 이니시스 일본 CBT 결제 취소 완료 후 | `src/Services/KgInicisApiService.php:627` |
| `sirsoft-pay_kginicis.payment.before_authorize` | action | KG 이니시스 서버 승인 API 호출 전 | `src/Controllers/PaymentCallbackController.php:290` |
| `sirsoft-pay_kginicis.payment.before_cancel` | action | KG 이니시스 결제 취소 API 호출 전 (본인인증 등 확장 지점) | `src/Services/KgInicisApiService.php:841` |
| `sirsoft-pay_kginicis.payment.before_cbt_refund` | action | KG 이니시스 일본 CBT 결제 취소 API 호출 전 | `src/Services/KgInicisApiService.php:599` |
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
| `core.plugin_settings.before_save` | action (미선언) | `ValidateCbtSettingsListener` | `validateBeforeSave` | 10 |
| `core.plugin_settings.update_validation_rules` | filter | `ValidateRedirectUrlSettingsListener` | `rules` | 10 |
| `core.plugins.after_update` | action | `RestoreLayoutExtensionsAfterUpdateListener` | `restoreCurrentExtensionsAfterUpdate` | 20 |
| `sirsoft-ecommerce.cash_receipt.cancel` | filter | `RegisterCashReceiptProviderListener` | `cancel` | 10 |
| `sirsoft-ecommerce.cash_receipt.issue` | filter | `RegisterCashReceiptProviderListener` | `issue` | 10 |
| `sirsoft-ecommerce.cash_receipt.registered_providers` | filter | `RegisterCashReceiptProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.checkout.filter_payment_methods_for_currency` | filter | `RestrictPaymentMethodsForCurrencyListener` | `filter` | 20 |
| `sirsoft-ecommerce.claim_payment.approve` | filter | `ClaimPaymentApproveListener` | `processApprove` | 10 |
| `sirsoft-ecommerce.claim_payment.refund` | filter | `ClaimPaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.claim_payment.void_approval` | filter | `ClaimPaymentApproveListener` | `voidApproval` | 10 |
| `sirsoft-ecommerce.order.after_anonymize` | action | `ScrubEscrowRecipientOnOrderAnonymizeListener` | `scrubEscrowRecipient` | 10 |
| `sirsoft-ecommerce.payment.close_vbank` | filter | `PaymentRefundListener` | `closeVbank` | 10 |
| `sirsoft-ecommerce.payment.get_client_config` | filter | `RegisterPgProviderListener` | `getClientConfig` | 10 |
| `sirsoft-ecommerce.payment.refund` | filter | `CancelActivityLogListener` | `logCancelConfirmed` | 20 |
| `sirsoft-ecommerce.payment.refund` | filter | `PaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | `RegisterPgProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.payment.supports_partial_cancel` | filter | `PaymentRefundListener` | `supportsPartialCancel` | 10 |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | `RegisterEasyPayMethodsListener` | `injectEasyPayMethods` | 20 |
| `sirsoft-gdpr.necessary_storage.filter_provided_keys` | filter | `ProvideNecessaryStorageKeysListener` | `provide` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`core.layout_extension.after_apply`를 구독하는 상세 화면 리스너 2개(`Ensure*LayoutListener`)가 서로
다른 우선순위(65/66)를 갖는 것은 우연이 아닙니다 — 관리자 주문 상세 레이아웃에 여러 확장이 조각을
주입할 수 있어, 이 플러그인의 조각들이 서로 겹치지 않는 순서로 배치되도록 번호를 나눠 씁니다.
관리자 주문 **목록**의 테스트 결제 배지는 리스너로 덧대지 않습니다 — 이커머스가 결제수단 칸에 연
확장점(`admin_order_list_payment_method_badges`)에 조각으로 붙습니다. `sirsoft-ecommerce.payment.refund`를 구독하는 두 리스너의 우선순위
(`PaymentRefundListener`=10, `CancelActivityLogListener`=20)는 §AGENTS.md 핵심 흐름에서
설명한 대로 "취소 성공 후에만 로그 기록"을 강제하기 위한 순서입니다 — 뒤바뀌면 실패한 취소도
로그에 성공처럼 남을 수 있습니다.

`sirsoft-ecommerce.order.after_anonymize`를 구독하는 `ScrubEscrowRecipientOnOrderAnonymizeListener`는
쇼핑몰의 개인정보 보유기간 익명화가 주문 한 건을 처리할 때 에스크로 배송 등록 기록
(`payment_meta.escrow_delivery`)의 수령인 이름·연락처·주소를 지웁니다. 이 훅은 익명화 트랜잭션
안에서 발행되므로 `sync: true` 로 구독합니다 — 큐로 미루면 익명화 표지만 커밋되고 사본이 남을 수
있고, 동기로 돌다 실패하면 그 주문의 익명화 전체가 롤백되어 다음 실행이 다시 잡습니다.

`sirsoft-ecommerce.claim_payment.approve` / `.refund` 두 필터는 클레임 추가결제(`CP` 채번)의
승인·환급을 이 플러그인이 맡는 접합면입니다. 콜백 컨트롤러는 결제번호가 `CP` 로 시작하면 주문
경로를 타지 않고 모듈의 `approvePgPayment()` 에 위임하며, 그 안에서 이 필터가 발화합니다 —
서버 콜백 경로와 API 승인 경로가 같은 구현을 탑니다. 인자·반환 키·멱등 규칙은 쇼핑몰 모듈
`modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` 가 단일 출처입니다.

승인 응답이 이 결제 건·청구액의 것인지는 승인 리스너가 판정합니다 — PC 는 응답 `TotPrice`(콜백 값으로
채우지 않음)·`MOID`, 모바일은 `P_AMT`·`P_OID`, CBT 는 응답에 금액이 실려 오면 그 금액을 청구액과
대조하고, 어긋나면 리스너가 직접 되돌린 뒤(PC 망취소 · 모바일 취소 · CBT 환불) 실패(`AMOUNT_MISMATCH`
/ `ORDER_ID_MISMATCH`)로 돌려줍니다. 모듈도 승인 뒤 금액을 한 번 더 대조하며, 모듈이 따로 거절한 건은
`sirsoft-ecommerce.claim_payment.void_approval` 필터(같은 리스너의 `voidApproval()`)로 되돌립니다 —
콜백 컨트롤러는 보상 클로저를 따로 넘기지 않으므로 서버 콜백 경로와 API 승인 경로의 판정·보상이
한 구현입니다. CBT 편의점(CVS)은 응답의
결제수단 또는 CVS 전용 응답 필드(`confNo`·`receiptNo`·`convenience`·`paymentTerm`)로 판정하고,
콜백 `paymethod` 는 거절 쪽으로만 씁니다. 주문 결제 콜백도 같은 규칙입니다 — PC 는 승인 응답 `TotPrice`,
모바일은 응답 `P_AMT` 만 승인액으로 쓰고(응답에 없으면 취소 + 실패), 주문 CBT 의 CVS 판정은 같은
`Support/CbtCvsResponse` 를 씁니다.

콜백 컨트롤러의 `CP` 분기는 결제번호 단위 캐시 락(`sirsoft-pay_kginicis:callback:{문맥}:{sha1(결제번호)}`)으로
직렬화됩니다. 락을 기다리다 시간을 넘기면 오류(500)가 아니라 결제 건 상태를 읽어 신청서 상세로
돌려보냅니다 — 이미 결제완료면 `?claim_payment=success`, 아니면 `?claim_payment=failed&error=callback_locked`.

주입하는 결제수단 항목은 이커머스의 결제수단 **능력 키**를 함께 선언합니다. 그중
`auto_approve_refund` 는 "사람의 개입 없이 환불이 집행되는가" 이고, 간편결제 수단은 KG 결제취소
API 가 그 자리에서 환불하므로 `true` 입니다. 예외는 `kginicis_japan_cvs`(편의점 결제)로, 고객이
현금으로 낸 돈이라 환불도 사람이 계좌로 송금해야 끝나므로 `false` 입니다 — `true` 로 잘못
선언하면 코어가 환불신청서를 완료까지 보내 고객에게 "환불 완료" 알림이 가는데 돈은 아직 나가지
않은 상태가 됩니다. 계약은 이커머스 `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` "결제수단 능력 선언" 절이 갖습니다.
<!-- @intent END -->

## 활동 로그 훅

> 이 확장이 코어 활동 로그(`activity_logs`)에 기록을 남기기 위해 구독하는 훅 1개입니다.
> 위 「구독 훅」 절이 이 확장의 구독 전량을 싣고, 이 절은 그중 **기록을 남기는 것**만 추립니다.
> 코어 `docs/backend/activity-log-hooks.md` 에는 총계와 이 문서로의 링크만 남습니다(#601).

> 새 항목을 추가하면 코어 `lang/{ko,en}/activity_log.php` 의 action 라벨과 description 본문,
> 그리고 번들 일본어 팩까지 함께 정의해야 합니다 — **플러그인 lang 파일에 넣으면 action 라벨이
> 해석되지 않습니다.** (description 본문은 이 확장의 `lang/{ko,en}/activity_log.php` 소유입니다.)

### 결제 취소 훅 (CancelActivityLogListener)

**파일**: `plugins/_bundled/sirsoft-pay_kginicis/src/Listeners/CancelActivityLogListener.php`
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
| `EnsureAdminOrderDetailTestModeLayoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/EnsureAdminOrderDetailTestModeLayoutListener.php` |
| `PaymentRefundListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PaymentRefundListener.php` |
| `ProvideNecessaryStorageKeysListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ProvideNecessaryStorageKeysListener.php` |
| `RegisterCashReceiptProviderListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/RegisterCashReceiptProviderListener.php` |
| `RegisterEasyPayMethodsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterEasyPayMethodsListener.php` |
| `RegisterPgProviderListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/RegisterPgProviderListener.php` |
| `RestoreLayoutExtensionsAfterUpdateListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RestoreLayoutExtensionsAfterUpdateListener.php` |
| `RestrictPaymentMethodsForCurrencyListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RestrictPaymentMethodsForCurrencyListener.php` |
| `ScrubEscrowRecipientOnOrderAnonymizeListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ScrubEscrowRecipientOnOrderAnonymizeListener.php` |
| `ValidateCbtSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateCbtSettingsListener.php` |
| `ValidateRedirectUrlSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateRedirectUrlSettingsListener.php` |
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
| `resources/extensions/admin_order_list_payment_method_badge.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `admin_order_list_payment_method_badges` |
| `resources/extensions/admin_order_payment_query.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_ecommerce_order_detail` |
| `resources/extensions/checkout_payment_error.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `shop/checkout` |
| `resources/extensions/user_order_complete_receipt.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `shop/order_complete` |
| `resources/extensions/user_order_show.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `mypage_order_payment_provider_slot` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
`checkout_payment_error.g7.html`은 체크아웃 화면에 KG 이니시스 특유의 오류 메시지(예: 계약되지
않은 결제수단 선택 시 안내)를 끼워 넣고, `user_order_show.g7.html`은 주문 상세·신청서 상세의 결제 정보
카드에 간편결제 이름·입금 안내·영수증·결제확인서를, `user_order_complete_receipt.g7.html`은 주문 완료 화면에
간편결제 이름·영수증 버튼을 붙입니다. 관리자 쪽 두 조각(`admin_order_list_payment_method_badge`/`admin_order_payment_query`)은
"이 주문이 테스트 모드로 결제됐는가"와 "PG 거래 상태를 다시 조회"를 관리자 화면에서 바로
확인하게 하는 운영 편의 기능이고, `admin_order_payment_query` 는 결제수단 칸에 간편결제 이름도 덧붙입니다 —
실제 결제 로직과는 분리돼 있어 이 조각만 비활성화해도 결제 자체는 영향받지 않습니다.

예전에는 이 일들 중 상당수를 화면 주입 스크립트가 했습니다(주소 글자 매칭, DOM 폴링, `window.fetch`·`history.pushState`
덮어쓰기, 로그인 토큰 직접 읽기). #639 에서 모두 조각과 서버 훅으로 옮겼습니다. 조각이 붙는 자리는 이커머스·템플릿이 약속한
id·확장점만 씁니다 — `mypage_order_payment_provider_slot`(템플릿), `order_complete_payment_summary`(템플릿 `shop/order_complete`),
`payment_method_block_{{payIdx}}`(이커머스 관리자 주문 상세). 대상 쪽이 그 자리를 없애면 조각은 오류 없이 사라지므로, 대상
확장을 올린 뒤에는 화면에 여전히 나타나는지 확인합니다.

목록 배지 조각은 **자기 배지와 자기 테스트 결제 맵(`kginicis_test_map`)만** 갖습니다. 주문 목록의
컬럼은 이커머스 레이아웃이 단일 출처이므로 컬럼 배열을 복사해 덮지 않고, 다른 PG 의 테스트 결제
여부도 읽지 않습니다(한 목록에 여러 PG 주문이 섞여도 PG 마다 자기 배지를 자기 주문에만 띄운다).

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
