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
<!-- @intent END -->

### 영수증 링크는 API 주소로 그린다

주문 상세 결제 정보 카드의 영수증 링크 조각(`plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/user_order_show.g7.html`)은 결제사 주소를
글자 그대로 적지 않는다. 적으면 레이아웃 편집기의 저장 게이트(외부 URL 금지)가 이 조각을 거절한다. 조각은 마운트 때
`GET /api/plugins/sirsoft-pay_kginicis/user/orders/{orderNumber}/receipt` 를 불러 공통 응답 형식의 `data.receipt_url` 을 로컬 상태
(`_kgReceiptUrl`)에 담고 그 값으로 링크를 그린다. 회원·비회원 주문 상세가 같은 조각을 쓰므로 회원은 토큰
(`auth_mode=optional`), 비회원은 `X-Guest-Order-Token` 헤더를 실어 같은 API 를 부른다. 주소를 받지 못하면 링크를 그리지 않는다.
주문번호는 주소가 아니라 불러온 주문(`order.data.order_number`)에서 읽는다 — 이 결제 정보 카드(영수증 링크·에스크로 구매확정 링크)는 주소에 주문번호가 없는
교환·반품·환불 신청서 상세(회원·비회원)에서도 같은 조각으로 쓰인다.

### 영수증 글자는 요청 언어로 오고, 스크립트는 항목을 키로 찾는다

영수증 조회 응답의 버튼 이름·확인서 제목·안내·항목 이름·결제수단 이름은 서버가 요청 언어로 번역해 보낸다.
그래서 확인서 항목(`receipt_fields`)을 이름(`label`)으로 찾으면 영어·일본어 화면에서 못 찾는다 — 일본 편의점
입금 안내 패널(`plugins/_bundled/sirsoft-pay_kginicis/resources/js/vbankInfoInjector.ts`)은 항목의 고정 키(`key`: `deposit_status`·`amount`·`cvs_code` 등)로
값을 찾고, 편의점 결제 여부도 결제수단 이름이 아니라 응답의 결제수단 코드(`cbt_pay_method`)로 가린다. 패널 제목·행 이름,
확인서 팝업의 인쇄 버튼, 서버가 이름을 주지 않았을 때의 버튼 이름은 `resources/lang` 의 `user.*` 키로 그린다
(`receiptPopup.ts` 의 `translateKginicis`).

입금 안내의 입금 기한은 쇼핑몰이 사용자 언어·시간대로 서식을 입혀 주는 `payment.vbank_due_at_formatted` 를 그대로 쓴다.
플러그인은 날짜에 서식을 입히지 않는다 — 원시 값(`vbank_due_at`)을 한국 서식·한국 시간으로 바꿔 쓰면 영어·일본어 화면에도
한국식 날짜가 나온다. 주문 완료 화면의 날짜도 쇼핑몰의 서식 값을 템플릿이 그대로 표시하므로 플러그인이 건드리지 않는다.

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 1개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `requestPayment` | `sirsoft-pay_kginicis.requestPayment` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`requestPayment` 하나로 PC/모바일/CBT 3가지 프로토콜을 전부 처리합니다 — 체크아웃 버튼은
결제수단·통화가 무엇이든 이 핸들러 하나만 호출하고, PC 결제창을 열지 모바일 폼을 제출할지
CBT 인증 URL로 이동할지는 핸들러 내부에서 서버 응답(§API `/payment/*/signature`,
`/payment/cbt/hash-data`)에 따라 분기합니다. 레이아웃 JSON 작성자가 결제수단별로 다른
핸들러를 호출할 필요가 없다는 뜻입니다.
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
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
KG 이니시스가 제공하는 `INIStdPay.js`/모바일 결제창 스크립트는 이 목록에 없습니다 — 그
스크립트들은 KG 이니시스 CDN 에서 결제 시도 시점에 동적으로 로드되는 제3자 자산이라, 이
플러그인이 빌드 시 번들링하는 `dist/` 산출물과는 다른 층입니다. CSS 산출물이 없는 것은
결제창 자체는 KG 이니시스가 그리고, 이 플러그인은 결제 버튼 같은 최소한의 UI만 코어
컴포넌트로 구성하기 때문입니다.
<!-- @intent END -->

### 엔화 결제수단 제한의 화면 보정은 id·표식으로만 찾는다

`checkoutJpyPaymentMethodRestrictor` 는 두 겹이다 — 결제 설정 응답에서 허용 밖 결제수단을 거르는 앞단(결제수단 id 기준)과,
이미 그려진 버튼을 숨기는 화면 보정. 화면 보정은 템플릿이 단 칸 `id="checkout_payment_method_section"` 과 버튼의
`data-payment-method`(결제수단 id)로만 대상을 찾는다. 종전에는 칸 제목 글자와 버튼 이름 글자(한국어·영어·일본어 이름표)로
찾아 번역이 다르면 빠졌다. 표식이 없는 버튼은 건드리지 않는다.

### 결제 정보 카드는 id 로만 찾는다

주문 상세·신청서 상세에 영수증 버튼·입금 안내를 붙이는 주입 스크립트는 결제 정보 카드를 템플릿이 약속한 `id="order_payment_info_panel"` 로만
찾는다. 화면 제목 글자(「결제 정보」 등)로 찾지 않는다 — 제목은 언어마다 다르다. id 가 없는 템플릿에서는 붙이지 않는다
(약속 원문: 이커머스 `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` 「PG 플러그인이 결제 정보 카드에 붙일 때의 약속」).

간편결제 이름을 결제 방법 행에 덧붙일 때도 행을 id 로만 찾는다 — 사용자 주문 상세·주문 완료는 `order_payment_method_row`, 관리자 주문 상세는
`payment_method_block_{결제 순번}`. 행 이름 글자(「결제 방법」「결제수단」)로 찾지 않는다.

### 관리자 결제수단 설정의 브랜드 표시는 결제수단 id 표식으로만 찾는다

관리자 쇼핑몰 설정의 결제수단 목록에서 브랜드 배지를 붙일 항목은 이커머스 관리자 화면이 항목마다 단
`data-payment-method`(결제수단 id)로만 찾는다. 이름 글자(한국어·영어 이름표)와 클래스 모양으로 찾지 않는다 — 관리자 언어·화면 폭마다
다르다. 약속 원문: 이커머스 `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` 「PG 플러그인이 관리자 결제수단 설정 항목을 볼 때의 약속」.

### 스크립트가 그리는 글자는 번역 키로 낸다

영수증 버튼을 누른 뒤의 조회 중 글자는 `user.loading` 번역 키(`translateKginicis`)로 낸다. 플러그인은 ko·en 을 갖고 일본어는 언어팩이 채운다.
