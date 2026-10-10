# 토스페이먼츠 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 4종 / 호출 지점 4곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-tosspayments.payment.after_cancel` | action | 토스페이먼츠 결제 취소 완료 후 | `src/Services/TossPaymentsApiService.php:119` |
| `sirsoft-tosspayments.payment.after_confirm` | action | 토스페이먼츠 결제 승인 완료 후 | `src/Controllers/PaymentCallbackController.php:175` |
| `sirsoft-tosspayments.payment.before_cancel` | action | 토스페이먼츠 결제 취소 API 호출 전 | `src/Services/TossPaymentsApiService.php:114` |
| `sirsoft-tosspayments.payment.before_confirm` | action | 토스페이먼츠 결제 승인 API 호출 전 | `src/Controllers/PaymentCallbackController.php:168` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`before_confirm`/`before_cancel`은 API 호출 **전** 개입 지점이라 예외를 던지면 실제 토스
호출이 일어나지 않습니다. `after_confirm`은 승인 확인 응답을 받은 뒤(§AGENTS.md "핵심 흐름")
발화하므로, 이 시점에는 아직 승인 금액 대조가 끝나지 않았습니다 — 대조에 실패하면 이 훅이
발화한 뒤 토스 승인이 취소될 수 있으므로, 구독하는 확장은 이커머스가 최종 완료 처리를 마쳤는지
별도로 확인해야 합니다. `before_confirm` 의 금액 인자는 브라우저 콜백 금액이 아니라 대조를 마친
주문 청구액입니다. `after_confirm`·`after_cancel` 이 넘기는 토스 응답에는 가상계좌 입금통보 검증값
(`secret`)이 빠져 있습니다 — 큐로 실행되는 구독자의 작업 기록에 남지 않게 하기 위해서이고, 그 밖의
응답 필드는 그대로입니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.plugin_settings.before_save` | action (미선언) | `ValidateTossSettingsListener` | `validateBeforeSave` | 10 |
| `core.plugin_settings.update_validation_rules` | filter | `ValidateRedirectUrlSettingsListener` | `rules` | 10 |
| `core.plugins.after_update` | action | `RestoreLayoutExtensionsAfterUpdateListener` | `restoreCurrentExtensionsAfterUpdate` | 20 |
| `sirsoft-ecommerce.cash_receipt.cancel` | filter | `RegisterCashReceiptProviderListener` | `cancel` | 10 |
| `sirsoft-ecommerce.cash_receipt.issue` | filter | `RegisterCashReceiptProviderListener` | `issue` | 10 |
| `sirsoft-ecommerce.cash_receipt.registered_providers` | filter | `RegisterCashReceiptProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.claim_payment.approve` | filter | `ClaimPaymentApproveListener` | `processApprove` | 10 |
| `sirsoft-ecommerce.claim_payment.refund` | filter | `ClaimPaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.claim_payment.void_approval` | filter | `ClaimPaymentApproveListener` | `voidApproval` | 10 |
| `sirsoft-ecommerce.payment.close_vbank` | filter | `VbankCloseListener` | `closeVbank` | 10 |
| `sirsoft-ecommerce.payment.get_client_config` | filter | `RegisterPgProviderListener` | `getClientConfig` | 10 |
| `sirsoft-ecommerce.payment.refund` | filter | `PaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | `RegisterPgProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.payment.supports_partial_cancel` | filter | `PaymentRefundListener` | `supportsPartialCancel` | 10 |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | `RegisterTossPaymentMethodsListener` | `injectTossMethods` | 20 |
| `sirsoft-gdpr.necessary_storage.filter_provided_keys` | filter | `ProvideNecessaryStorageKeysListener` | `provide` | 10 |
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

주입하는 `toss_*` 항목은 이커머스의 결제수단 **능력 키**를 함께 선언합니다. 그중
`auto_approve_refund` 는 "사람의 개입 없이 환불이 집행되는가" 이고, **카드 계열
(`toss_card` + 간편결제 5종)만 `true`** 입니다 — 토스 결제취소 API 가 그 자리에서 환불을
끝냅니다. `toss_virtual_account`·`toss_transfer`·`toss_mobile_phone` 은 고객 환불계좌를 받아
사람이 확인해야 끝나므로 `false` 입니다. `true` 로 잘못 선언하면 코어가 환불신청서를 완료까지
보내 고객에게 "환불 완료" 알림이 가는데 돈은 아직 나가지 않은 상태가 되고, 반대로 카드 계열에서
선언을 빠뜨리면 그 수단의 환불이 전부 운영자 승인 대기로 쌓입니다. 계약은 이커머스
`modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` "결제수단 능력 선언" 절이 갖습니다.

`sirsoft-ecommerce.claim_payment.approve` / `.refund` 두 필터는 클레임 추가결제(`CP` 채번)의
승인·환급을 이 플러그인이 맡는 접합면입니다. 콜백 컨트롤러는 결제번호가 `CP` 로 시작하면 주문
경로를 타지 않고 모듈의 `approvePgPayment()` 에 위임하며, 그 안에서 이 필터가 발화합니다 —
서버 콜백 경로와 API 승인 경로가 같은 구현을 탑니다. 인자·반환 키·멱등 규칙은 쇼핑몰 모듈
`modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` 가 단일 출처입니다.

승인 리스너는 콜백 금액을 Confirm **전에** 청구액과 대조하고, Confirm 응답의 `totalAmount`(콜백 값으로
채우지 않음)·`orderId` 가 이 결제 건·청구액과 어긋나면 승인을 직접 취소한 뒤 실패로 돌려줍니다.
모듈도 승인 뒤 금액을 한 번 더 대조하며, 모듈이 따로 거절한 건은
`sirsoft-ecommerce.claim_payment.void_approval` 필터(같은 리스너의 `voidApproval()`)로 되돌립니다 —
콜백 컨트롤러는 보상 클로저를 따로 넘기지 않으므로 서버 콜백 경로와 API 승인 경로의 판정·보상이
한 구현입니다.

콜백 컨트롤러의 `CP` 분기는 결제번호 단위 캐시 락(`sirsoft-tosspayments:callback:{문맥}:{sha1(결제번호)}`)으로
직렬화됩니다. 락을 기다리다 시간을 넘기면 오류(500)가 아니라 결제 건 상태를 읽어 신청서 상세로
돌려보냅니다 — 이미 결제완료면 `?claim_payment=success`, 아니면 `?claim_payment=failed&error=callback_locked`.

주문 환불(`sirsoft-ecommerce.payment.refund`)에서 토스 취소가 성립한 뒤의 취소 장부 동기화(승인 스냅샷
`payment_meta.pg_raw_response.cancels` 갱신)는 환불 성패와 분리됩니다 — 저장이 실패해도 환불 결과는
성공이고 실패는 로그로만 남습니다. 다음 취소 응답이 그 결제의 전체 취소 이력을 싣고 오므로 장부는
그때 다시 맞춰집니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ClaimPaymentApproveListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/ClaimPaymentApproveListener.php` |
| `ClaimPaymentRefundListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ClaimPaymentRefundListener.php` |
| `PaymentRefundListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/PaymentRefundListener.php` |
| `ProvideNecessaryStorageKeysListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ProvideNecessaryStorageKeysListener.php` |
| `RegisterCashReceiptProviderListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/RegisterCashReceiptProviderListener.php` |
| `RegisterPgProviderListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/RegisterPgProviderListener.php` |
| `RegisterTossPaymentMethodsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterTossPaymentMethodsListener.php` |
| `RestoreLayoutExtensionsAfterUpdateListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RestoreLayoutExtensionsAfterUpdateListener.php` |
| `ValidateRedirectUrlSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateRedirectUrlSettingsListener.php` |
| `ValidateTossSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateTossSettingsListener.php` |
| `VbankCloseListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/VbankCloseListener.php` |
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
| `resources/extensions/admin_order_payment.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_ecommerce_order_detail` |
| `resources/extensions/checkout-payment-error.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `shop_checkout_extensions` |
| `resources/extensions/user_order_show.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `mypage_order_payment_provider_slot` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
`checkout-payment-error.g7.html`은 다른 PG 플러그인에는 없는 조각입니다 — 승인 확인 실패
(`amount_mismatch`, 서명 오류 등)가 `?error=` 쿼리로 체크아웃에 되돌아왔을 때 그 오류를
사용자에게 보여주는 전용 UI입니다. 다른 PG는 오류 안내를 체크아웃 레이아웃이 이미 가진
공용 오류 처리에 맡기지만, 이 플러그인은 리다이렉트 기반 승인이라 오류 사유가 다양해
전용 조각을 둡니다.

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

이 조각은 가상계좌 입금 안내(은행·계좌번호·예금주·입금 금액·입금 기한, 입금 전 자동 취소 경고)·에스크로 표시·영수증·
현금영수증 링크를 그립니다. 같은 슬롯이 비회원 주문 상세에도 붙으므로 주문 데이터(`order.data`)만 읽고 인증이 필요한
데이터 소스를 두지 않습니다 — 처리기 없는 인증 필수 소스는 비회원에게 401 로 떨어져 로그인 화면 이동을 부릅니다.
입금 금액은 결제예정금액(`total_due_amount_formatted`)이고, 비회원 응답에는 그 값이 없어 주문금액으로 대신합니다.
글자·구분선 색은 이 플러그인 CSS 의 접두 클래스(`pay-tosspayments-*`)가 라이트·다크 짝으로 싣습니다.

**템플릿 요구 사항 — `mypage_order_payment_provider_slot` 슬롯이 필수입니다.** 이 조각은 예전처럼 대상
화면 이름(`target_layout: mypage/orders/show`)으로 붙지 않고 이 슬롯에만 붙습니다. 그래서 사용자 템플릿의
결제 정보 카드에 이 확장점이 없으면(자체 제작 템플릿, 슬롯 도입 전에 복사한 템플릿) 주문 상세·클레임
신청서 상세 어디에도 이 블록이 뜨지 않으며, 오류도 경고도 남지 않습니다. 사용자 템플릿을 만들거나 고칠 때는
결제 정보 카드 partial 에 `<extension-point name="mypage_order_payment_provider_slot">` 을 둡니다 — 기본
사용자 템플릿(sirsoft-basic)은 `partials/mypage/orders/_payment` 에 두고 있습니다.
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
