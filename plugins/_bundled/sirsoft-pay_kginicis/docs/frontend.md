# KG 이니시스 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
이 플러그인이 소유한 화면 레이아웃은 관리자 설정 화면 하나뿐입니다 — 체크아웃·주문상세의
결제 UI는 이 플러그인 소유가 아니라 §레이아웃 확장(다른 확장/템플릿 레이아웃에 주입되는
조각)으로 존재합니다. "화면"과 "레이아웃 확장 조각"을 헷갈리면 체크아웃 결제 버튼을 찾으러
`resources/layouts/`를 뒤지게 되는데, 실제로는 `resources/extensions/`에 있습니다.

설정 화면의 비밀값 칸(운영 서명 키·INIAPI 키·IV·모바일 해시 키) 옆 보기/숨기기 단추는 아이콘만 있으므로, 현재 상태에 따라
`aria-label` 을 코어 공통 키 `common.show_value`/`common.hide_value` 로 바꾸고 `aria-pressed` 로 펼침 여부를 알립니다.
상태 변수(`_local._show_live_*`)와 두 속성의 조건식은 같아야 합니다 — 하나만 고치면 보이는 값과 읽히는 상태가 어긋납니다.
<!-- @intent END -->

### 주문 화면의 결제 블록은 조각이 그린다

주문 상세·신청서 상세(회원·비회원), 주문 완료, 관리자 주문 상세에 붙는 KG이니시스 결제 정보는 모두 레이아웃 조각이 그린다.
예전에는 주입 스크립트 넷(`mypageOrderShowInjector`·`vbankInfoInjector`·`orderCompleteReceiptInjector`·`adminOrderPaymentDisplayInjector`)이
화면 주소 글자를 맞추고 DOM 을 폴링해 끼웠고, 로그인 토큰을 저장소에서 직접 읽어 Bearer 를 붙였으며 `history.pushState` 를 덮어썼다.
쇼핑몰 경로를 바꾼 설치본에서는 조용히 빠졌다(#639 에서 조각으로 옮김).

| 화면 | 조각 | 붙는 자리 | 데이터 |
|---|---|---|---|
| 주문 상세·신청서 상세 결제 정보 카드 | `resources/extensions/user_order_show.g7.html` | 확장점 `mypage_order_payment_provider_slot` | 마운트 때 영수증 API 1회 → `_local._kgReceipt` |
| 주문 완료 결제 금액 요약 | `resources/extensions/user_order_complete_receipt.g7.html` | `shop/order_complete` 의 id `order_complete_payment_summary` | 마운트 때 영수증 API 1회 → `_local._kgOcReceipt` |
| 관리자 주문 상세 결제수단 칸 | `resources/extensions/admin_order_payment_query.g7.html` | `admin_ecommerce_order_detail` 의 id `payment_method_block_{{payIdx}}` | 같은 조각의 데이터 소스 `kginicis_status` |

- 영수증 API(`GET /api/plugins/sirsoft-pay_kginicis/user/orders/{orderNumber}/receipt`)는 회원이면 토큰(`auth_mode=optional`),
  비회원이면 상태 계약 `_global.guestOrderToken` 을 `X-Guest-Order-Token` 헤더로 실어 부른다. 주문번호는 주소가 아니라 불러온 주문
  (`order.data.order_number`)에서 읽는다 — 같은 카드가 주소에 클레임 번호가 실린 신청서 상세에서도 쓰인다.
- **마운트 조회는 조건을 단 노드의 안쪽 노드에 둔다.** 엔진은 `data-if` 가 거짓이어도 그 노드의 `<on event="mount">` 를 실행한다.
  바깥 노드에 두면 다른 결제대행사 주문에서도(또는 주문을 불러오기 전 빈 주문번호로) 조회가 나간다.
- 응답 하나로 간편결제 이름 행, 가상계좌 입금 안내(관리자에게만 은행코드 — 응답의 `vbank_bank_code`), 일본 편의점 입금 안내,
  영수증 링크, CBT 결제확인서 버튼을 그린다. 결제 방법 행 값과 관리자 결제수단 칸 값·상단 배지는 템플릿·이커머스 소유라 바꾸지 않고
  따로 한 줄을 덧붙인다.
- 영수증 링크는 결제사 주소를 글자 그대로 적지 않는다. 적으면 레이아웃 편집기의 저장 게이트(외부 URL 금지)가 거절한다 — API 가 준
  `receipt_url` 로 그린다.
- 주문 완료 조각이 붙는 id `order_complete_payment_summary` 는 템플릿 소유다. 템플릿이 그 id 를 달지 않으면 조각은 오류 없이 나타나지 않는다.
- 주문 상세(회원·비회원)의 영수증 줄은 조각이 id `kginicis-mp-receipt-row` 로 그린다.
- [구매결정](에스크로) 링크는 영수증 조회 응답의 `escrow_confirm_url`(본인 에스크로 결제에만 내려 주는 10분 서명 주소)로 열고, 그 주소가
  없으면 그리지 않는다. 새 창에는 화면의 토큰 로그인이 실리지 않으므로, 구매결정 창(`UserEscrowConfirmController::show`)은 세션 로그인
  또는 그 서명(`uid`)으로 본인을 확인한다.

### 영수증 글자는 요청 언어로 오고, 조각은 항목을 키로 찾는다

영수증 조회 응답의 버튼 이름·확인서 제목·안내·항목 이름·결제수단 이름·간편결제 이름은 서버가 요청 언어로 번역해 보낸다.
그래서 확인서 항목(`receipt_fields`)을 이름(`label`)으로 찾으면 영어·일본어 화면에서 못 찾는다 — 일본 편의점 입금 안내는 항목의
고정 키(`key`: `deposit_status`·`amount`·`cvs_code` 등)로 값을 찾고, 편의점 결제 여부도 응답의 결제수단 코드(`cbt_pay_method`)로 가린다.
조각의 제목·행 이름, 서버가 이름을 주지 않았을 때의 버튼 이름, 확인서 팝업의 인쇄 버튼은 `resources/lang` 의 `user.*` 키로 그린다.

입금 안내의 입금 기한은 쇼핑몰이 사용자 언어·시간대로 서식을 입혀 주는 `payment.vbank_due_at_formatted` 를 그대로 쓴다.
플러그인은 날짜에 서식을 입히지 않는다 — 원시 값(`vbank_due_at`)을 한국 서식·한국 시간으로 바꿔 쓰면 영어·일본어 화면에도
한국식 날짜가 나온다.

입금 안내에는 은행·계좌번호·예금주 다음에 **입금 금액**(`user.vbank_amount`)과 끝에 **자동 취소 안내**(`user.vbank_notice`)를 붙인다 — 나이스·KCP 와 같은 구성이다. 입금 금액은 실제로 입금할 결제예정금액(`total_due_amount_formatted`, 마일리지 차감 뒤)이고, 그 값이 없는 비회원 응답에서는 주문금액(`total_amount_formatted`)으로 대신한다(비회원은 마일리지를 쓰지 못한다). 이 영역은 입금 대기 주문에만 붙으므로 경고도 늘 함께 붙는다.

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 3개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `requestPayment` | `sirsoft-pay_kginicis.requestPayment` |
| `copyToClipboard` | `sirsoft-pay_kginicis.copyToClipboard` |
| `openReceipt` | `sirsoft-pay_kginicis.openReceipt` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`requestPayment` 하나로 PC/모바일/CBT 3가지 프로토콜을 전부 처리합니다 — 체크아웃 버튼은
결제수단·통화가 무엇이든 이 핸들러 하나만 호출하고, PC 결제창을 열지 모바일 폼을 제출할지
CBT 인증 URL로 이동할지는 핸들러 내부에서 서버 응답(§API `/payment/*/signature`,
`/payment/cbt/hash-data`)에 따라 분기합니다. 레이아웃 JSON 작성자가 결제수단별로 다른
핸들러를 호출할 필요가 없다는 뜻입니다.

`copyToClipboard` 는 플러그인 설정의 가상계좌 입금통보 주소를 복사합니다(상점관리자에 그대로 붙여 넣는 값).
설정 화면이 어느 관리자 템플릿으로 그려지든 같게 동작해야 하므로 특정 템플릿의 핸들러에 기대지 않고 플러그인이 직접 둡니다(코어에는 클립보드 핸들러가 없습니다). `navigator.clipboard` 가 막힌 비보안(HTTP) 접속에서는 숨긴 입력칸 복사로 내려갑니다.

`openReceipt` 는 주문 화면 조각의 영수증·결제확인서 버튼이 부릅니다. 조각이 마운트 때 받아 둔 영수증 조회 응답을 `receipt` 로 넘기면
외부 영수증 주소(https 만)는 opener 를 끊은 크기 지정 창으로, CBT 결제확인서는 응답 항목을 이스케이프한 인쇄용 문서로 엽니다.
다시 조회하지 않습니다 — 클릭과 같은 틱에서 창을 열어야 팝업 차단에 걸리지 않습니다.

`requestPayment` 는 결제수단을 액션 인자(`pgPaymentData.payment_method`, 서버가 주문에 저장한 값) 하나에서만, 결제 통화도 인자
(`pgPaymentData.currency`, 주문 스냅샷 환율로 환산한 청구 통화)에서만 읽습니다. 화면 상태(엔진 내부 `_local`)를 뒤져 채우지 않고,
결제수단이 없으면 결제창을 열지 않고 오류 창으로 끝냅니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftKginicis` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftKginicis` 로 노출되는 이유는 코어가 로케일 전환 시 이 이름으로 재등록
진입점을 찾기 때문입니다(§코어 AGENTS.md "재등록 진입점"). `initPlugin()`이 KG 이니시스 결제창
스크립트(`INIStdPay.js`) 자체를 미리 로드하지 않는 것도 의도입니다 — 그 스크립트는
`requestPayment` 핸들러가 실제 결제 시도 시점에만 동적으로 로드합니다(모든 방문자가 결제
페이지에 오는 것은 아니므로 전역 부팅에서 미리 불러올 필요가 없습니다).
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
KG 이니시스가 제공하는 `INIStdPay.js`/모바일 결제창 스크립트는 이 목록에 없습니다 — 그
스크립트들은 KG 이니시스 CDN 에서 결제 시도 시점에 동적으로 로드되는 제3자 자산이라, 이
플러그인이 빌드 시 번들링하는 `dist/` 산출물과는 다른 층입니다. 결제창 자체는 KG 이니시스가 그리므로
이 플러그인의 CSS 산출물(`dist/css/plugin.css`, 원본 `resources/css/main.css`)은 작습니다 — 환경설정 화면의
안내 글자색(경고·성공·위험·안내, 다크 모드 짝 포함)을 접두 `pay-kginicis-tone-*` 클래스로 직접 제공하는 것이
전부입니다. 관리자 템플릿의 의미 글자색 클래스(`text-warning-soft` 등)에 기대면 템플릿이 그 클래스를 바꿀 때
이 화면의 색이 함께 바뀌기 때문입니다(7.1.0 때 경고 본문이 초록으로 바뀐 사례). 다른 결제 플러그인도 같은
이름 체계를 쓰지만 접두가 달라 서로를 참조하지 않습니다.
<!-- @intent END -->

### 엔화 결제수단 제한은 서버가 한다

결제 통화별 결제수단 제한(엔화면 이니시스 수단 중 카드·PayPay·편의점만, 엔화 외면 일본 전용 수단 제외)은 브라우저가 아니라
서버 리스너 `RestrictPaymentMethodsForCurrencyListener` 가 이커머스 훅 `sirsoft-ecommerce.checkout.filter_payment_methods_for_currency`
에 답해 정한다. 주문서 응답의 `allowed_payment_methods` 로 템플릿이 버튼을 거르고, 주문 생성 검증도 같은 판정을 쓴다.
예전 브라우저 제한기(`checkoutJpyPaymentMethodRestrictor`)는 주문서 응답을 `window.fetch` 덮어쓰기로 고치고 그려진 버튼을 숨겼는데,
직접 POST 로 우회됐고 쇼핑몰 경로가 `/shop` 이 아니면 동작하지 않았다(#639 에서 삭제).

### 관리자 결제수단 설정의 브랜드 마크는 카탈로그가 내려 준다

간편결제 6종(네이버페이·삼성페이·L.pay·카카오페이·PayPay·일본 편의점)의 브랜드 마크(SVG)는 결제수단 카탈로그 리스너
(`RegisterEasyPayMethodsListener`)가 `brand_mark` 로 실어 보내고, 이커머스가 저장 항목에 `_cached_brand_mark` 로 옮겨 둔다.
화면은 그 값을 그린다 — 주문서는 이미 `brand-mark` 컴포넌트로 그린다. 관리자 쇼핑몰 설정의 결제수단 목록(이커머스 소유)도
같은 값으로 그려야 하며, 예전처럼 화면 스크립트가 그려진 아이콘을 바꿔 끼우지 않는다(`adminPaymentMethodBrandInjector` 는 #639 에서 삭제).

## 결제창 닫힘 보고의 소유자 증명

<!-- @intent START -->
결제창 닫힘 보고(`POST /api/plugins/sirsoft-pay_kginicis/payment/close-report`)는 결제대기 주문을
실패·취소로 바꾸므로 서버가 **주문 소유자만** 받아 준다(KVE-2026-2434). 주문자 이메일·전화번호는
제3자도 알 수 있어 소유 증명이 되지 못하므로 보내지 않는다.

- 회원: `G7Core.api` 가 `Authorization: Bearer …` 를 스스로 싣는다 — 호출부는 아무것도 붙이지 않는다.
- 비회원: 쇼핑몰 상태 계약 `_global.guestOrderToken`(공개 API `G7Core.state.get()`)의 조회 토큰을 `X-Guest-Order-Token` 헤더로 싣는다.
  저장소 키를 직접 읽지 않는다 — 쇼핑몰이 저장 방식을 바꾸면 조용히 깨진다. 로그인하면 쇼핑몰이 이 토큰을 비운다.

헤더는 `resources/js/guestOrderToken.ts` 의 `buildGuestOwnershipHeaders()` 한 곳에서 만든다. 보고는 `G7Core.api` 로만 보낸다 —
직접 `fetch` 로 보내는 폴백은 두지 않는다(Bearer 부착과 401 처리가 코어 몫이다). 토큰이 없으면 서버가 403 으로 거부하고 화면 흐름은
그대로 진행한다 — 결제대기 주문은 이커머스 만료 정리 잡이 정리한다.

결제창을 닫았을 때 체크아웃 제출 상태를 푸는 일은 체크아웃 화면에서만 한다. 체크아웃 화면은 템플릿 상태 계약 `_global.shopBase`
(쇼핑몰 경로 앞머리, 루트면 빈 문자열)로 `{shopBase}/checkout` 인지 판정한다 — 경로 글자 `/shop/checkout` 을 박지 않는다.
상태 계약이 아직 채워지지 않았으면 체크아웃으로 단정하지 않고 잠시 다시 본다.
<!-- @intent END -->

## 클레임 추가결제 창을 닫을 때

<!-- @intent START -->
교환·반품·환불 신청서의 추가결제는 주문 결제가 아니다. 이커머스가 결제 데이터에 `pg_payment_data.claim_cancel_return_url`
(회원은 신청서 상세 `?claim_payment=cancelled`, 비회원은 주문 조회 화면 — `ClaimPaymentRedirect::cancelled`)을 실어 보내면,
결제창을 닫을 때 닫힘 보고를 보내지 않고 그 주소로 이동한다. 닫힘 보고는 결제대기 **주문**을 실패로 바꾸는 요청이라
클레임 결제에는 대상이 없고, 이동하지 않으면 신청서 상세가 「결제 진행 중…」 버튼에 멈춘 채 안내도 뜨지 않는다.
신청은 그대로 남고, 신청서 상세가 「결제를 취소하셨습니다」 안내와 함께 [결제하기]를 다시 연다.

처리 위치: 결제 요청이 `markStandardPaymentCloseReportContext()` 에 `claimCancelReturnUrl` 을 함께 기억하고,
`resources/js/paymentCloseMessageListener.ts` 가 닫힘 메시지를 받으면 `returnToClaimDetailIfClaimPayment()` 로 먼저 이동한다.
<!-- @intent END -->
